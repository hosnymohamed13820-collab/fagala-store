<?php
/**
 * customers.php — واجهة موديول العملاء (عرض فقط — مفيش SQL)
 * نظام الفجالة ستور المالي
 *
 * أي استدعاء داتا بـ apiFetch من customers_api.php
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_module_access('customers');
if (!can('customers:view') && !can('pos:sell')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

$canEdit = can('customers:edit');
$canStatement = can('customers:statement') || can('customers:view');

$page_title    = 'العملاء';
$active_module = 'customers';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/ui.php';
?>

<main class="page-container" id="customersApp">
    <?= ui_page_head(
        'العملاء',
        'بيانات العملاء وسجل المشتريات وكشوف الحسابات',
        $canEdit ? '<button type="button" class="btn btn-primary" id="btnToggleNewCustomer">' . ui_icon('user-plus') . ' عميل جديد</button>' : ''
    ) ?>

    <div id="custAlertBox"></div>

    <?php if ($canEdit): ?>
    <section class="fcard" id="newCustomerCard" style="display:none; margin-bottom:var(--sp-4);">
        <div class="fcard-head">
            <h2 class="fcard-title">إضافة عميل جديد</h2>
        </div>
        <form id="newCustomerForm" autocomplete="off">
            <div class="ffilters" style="margin-bottom:var(--sp-3);">
                <div class="ffield" style="flex:2; min-width:200px;">
                    <label class="flabel" for="newCustName">اسم العميل *</label>
                    <input class="finput" id="newCustName" name="name" required placeholder="اسم العميل الكامل">
                </div>
                <div class="ffield" style="flex:1; min-width:160px;">
                    <label class="flabel" for="newCustPhone">رقم الموبايل</label>
                    <input class="finput" id="newCustPhone" name="phone" dir="ltr" placeholder="01xxxxxxxxx">
                </div>
                <div class="ffield" style="flex:1; min-width:160px;">
                    <label class="flabel" for="newCustPhone2">موبايل بديل</label>
                    <input class="finput" id="newCustPhone2" name="phone2" dir="ltr" placeholder="01xxxxxxxxx">
                </div>
                <div class="ffield" style="width:130px;">
                    <label class="flabel" for="newCustKind">نوع العميل</label>
                    <select class="fselect" id="newCustKind" name="kind">
                        <option value="walkin" selected>طياري</option>
                        <option value="regular">دايم</option>
                    </select>
                </div>
                <div class="ffield" id="newCustBranchField" style="width:160px; display:none;">
                    <label class="flabel" for="newCustBranch">الفرع</label>
                    <select class="fselect" id="newCustBranch" name="branch_id"></select>
                </div>
            </div>
            <div class="ffield" style="margin-bottom:var(--sp-3);">
                <label class="flabel" for="newCustNotes">ملاحظات</label>
                <input class="finput" id="newCustNotes" name="notes" placeholder="أي ملاحظات عن العميل">
            </div>
            <div style="display:flex; gap:var(--sp-2);">
                <button type="submit" class="btn btn-primary" id="btnSaveNewCustomer">
                    <?= ui_icon('plus') ?> حفظ العميل
                </button>
                <button type="button" class="btn btn-ghost" id="btnCancelNewCustomer">إلغاء</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <!-- شاشة قائمة العملاء -->
    <div id="customersListView">
        <section class="fcard">
            <form class="ffilters" id="searchFiltersForm" onsubmit="return false;">
                <div class="ffield" style="flex:2; min-width:240px;">
                    <input class="finput" id="searchQuery" placeholder="بحث بالاسم، رقم الموبايل، أو كود العميل..." autofocus>
                </div>
                <div class="ffield" id="filterBranchWrap" style="display:none; width:160px;">
                    <select class="fselect" id="filterBranch">
                        <option value="">كل الفروع</option>
                    </select>
                </div>
                <div class="ffield" style="width:130px;">
                    <select class="fselect" id="filterKind">
                        <option value="">كل الأنواع</option>
                        <option value="walkin">طياري</option>
                        <option value="regular">دايم</option>
                    </select>
                </div>
                <div class="ffield" style="width:130px;">
                    <select class="fselect" id="filterActive">
                        <option value="">الكل</option>
                        <option value="1" selected>المفعّلين</option>
                        <option value="0">الموقوفين</option>
                    </select>
                </div>
            </form>

            <div class="ftable-wrap">
                <table class="ftable" id="customersTable">
                    <thead>
                        <tr>
                            <th class="num" style="width:90px;">الكود</th>
                            <th>اسم العميل</th>
                            <th>الموبايل</th>
                            <th>موبايل بديل</th>
                            <th style="width:110px;">النوع</th>
                            <th style="width:120px;">الفرع</th>
                            <th>ملاحظات</th>
                            <th style="width:100px;">الحالة</th>
                            <th style="width:110px; text-align:center;">كشف الحساب</th>
                        </tr>
                    </thead>
                    <tbody id="customersTableBody">
                        <tr><td colspan="9" style="text-align:center; padding:var(--sp-4); color:var(--ink-500);">جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="customersEmpty" style="display:none;"></div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:var(--sp-3);" id="paginationWrap">
                <span id="custCountLabel" style="color:var(--ink-500); font-size:var(--fs-sm);"></span>
                <div style="display:flex; gap:var(--sp-2);" id="paginationBtns">
                    <button type="button" class="btn btn-sm btn-ghost" id="btnPrevPage">السابق</button>
                    <span id="pageIndicator" style="align-self:center; font-size:var(--fs-sm); font-variant-numeric:tabular-nums;"></span>
                    <button type="button" class="btn btn-sm btn-ghost" id="btnNextPage">التالي</button>
                </div>
            </div>
        </section>
    </div>

    <!-- شاشة كشف حساب العميل -->
    <div id="statementView" style="display:none;">
        <div style="margin-bottom:var(--sp-3); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--sp-2);">
            <button type="button" class="btn btn-ghost" id="btnBackToList">
                <?= ui_icon('arrow-right') ?> رجوع لقائمة العملاء
            </button>
            <a href="#" class="btn btn-outline" id="btnExportXlsx" target="_blank">
                <?= ui_icon('file-spreadsheet') ?> تصدير إكسيل
            </a>
        </div>

        <div class="falert falert-info" style="margin-bottom:var(--sp-3);">
            <?= ui_icon('alert-circle') ?>
            <div>ده سجل مشتريات مش مديونية — مفيش آجل في نقطة البيع دلوقتي.</div>
        </div>

        <section class="fcard" style="margin-bottom:var(--sp-3);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--sp-2);">
                <div>
                    <h2 class="fcard-title" id="stmtCustomerName" style="margin:0;"></h2>
                    <span id="stmtCustomerMeta" style="color:var(--ink-500); font-size:var(--fs-sm);"></span>
                </div>
                <div id="stmtCustomerKind"></div>
            </div>
        </section>

        <div class="fkpis" id="stmtKpis" data-xt-kpis="#statementTable" style="margin-bottom:var(--sp-4);">
            <div class="fkpi"><span>عدد الفواتير</span><strong id="kpiCount" data-xt-sum="count" data-xt-dec="0">0</strong></div>
            <div class="fkpi"><span>إجمالي المشتريات</span><strong id="kpiSales" data-xt-sum="sales" style="color:var(--pos);">0.00</strong></div>
            <div class="fkpi"><span>المرتجع</span><strong id="kpiReturns" data-xt-sum="returns" style="color:var(--neg);">0.00</strong></div>
            <div class="fkpi"><span>الصافي</span><strong id="kpiNet" data-xt-sum="net" class="num-ltr">0.00</strong></div>
            <div class="fkpi"><span>آخر زيارة</span><strong id="kpiLastVisit">-</strong></div>
        </div>

        <section class="fcard">
            <div class="fcard-head">
                <h3 class="fcard-title">تفاصيل الحركات</h3>
            </div>
            <div class="ftable-wrap">
                <table class="ftable" id="statementTable">
                    <thead>
                        <tr>
                            <th style="width:160px;">التاريخ</th>
                            <th class="num" style="width:120px;">رقم الإيصال</th>
                            <th style="width:90px;">النوع</th>
                            <th style="width:130px;">الفرع</th>
                            <th>طرق الدفع</th>
                            <th class="num" style="width:110px;">الصافي</th>
                            <th class="num" style="width:120px;">الرصيد التراكمي</th>
                        </tr>
                    </thead>
                    <tbody id="statementTableBody">
                        <tr><td colspan="7" style="text-align:center; padding:var(--sp-4); color:var(--ink-500);">مفيش حركات مسجّلة للعميل ده.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.ui;
    const statementTemplate = document.getElementById('statementTable').outerHTML;
    const canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    const alertBox = document.getElementById('custAlertBox');
    const tableBody = document.getElementById('customersTableBody');
    const emptyBox = document.getElementById('customersEmpty');
    const countLabel = document.getElementById('custCountLabel');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevBtn = document.getElementById('btnPrevPage');
    const nextBtn = document.getElementById('btnNextPage');

    const searchInput = document.getElementById('searchQuery');
    const branchSelect = document.getElementById('filterBranch');
    const branchWrap = document.getElementById('filterBranchWrap');
    const kindSelect = document.getElementById('filterKind');
    const activeSelect = document.getElementById('filterActive');

    const btnToggleNew = document.getElementById('btnToggleNewCustomer');
    const newCard = document.getElementById('newCustomerCard');
    const newForm = document.getElementById('newCustomerForm');
    const btnCancelNew = document.getElementById('btnCancelNewCustomer');
    const newBranchField = document.getElementById('newCustBranchField');
    const newBranchSelect = document.getElementById('newCustBranch');

    const listView = document.getElementById('customersListView');
    const statementView = document.getElementById('statementView');
    const btnBackToList = document.getElementById('btnBackToList');
    const btnExportXlsx = document.getElementById('btnExportXlsx');

    let currentPage = 1;
    let totalPages = 1;
    let branchesLoaded = false;
    let branchesList = [];
    let searchDebounceTimer = null;

    function showAlert(kind, message) {
        if (!message) {
            alertBox.innerHTML = '';
            return;
        }
        alertBox.innerHTML = ui.alert(kind, message);
    }

    function clearAlert() {
        alertBox.innerHTML = '';
    }

    function loadCustomers(page) {
        if (page) currentPage = page;
        clearAlert();

        const params = new URLSearchParams();
        params.set('part', 'search');
        params.set('page', String(currentPage));

        const q = searchInput.value.trim();
        if (q) params.set('q', q);
        if (branchSelect.value) params.set('branch_id', branchSelect.value);
        if (kindSelect.value) params.set('kind', kindSelect.value);
        if (activeSelect.value !== '') params.set('is_active', activeSelect.value);

        window.apiFetch('customers_api.php?' + params.toString())
            .then(function (res) {
                const data = res.data || {};
                currentPage = Number(data.page || 1);
                totalPages = Number(data.pages || 1);

                if (!branchesLoaded && Array.isArray(data.branches)) {
                    branchesList = data.branches;
                    if (branchesList.length > 1) {
                        branchWrap.style.display = 'block';
                        branchSelect.innerHTML = '<option value="">كل الفروع</option>' + branchesList.map(function (b) {
                            return '<option value="' + Number(b.id) + '">' + ui.esc(b.name) + '</option>';
                        }).join('');

                        if (newBranchField && newBranchSelect) {
                            newBranchField.style.display = 'block';
                            newBranchSelect.innerHTML = branchesList.map(function (b) {
                                return '<option value="' + Number(b.id) + '">' + ui.esc(b.name) + '</option>';
                            }).join('');
                        }
                    }
                    branchesLoaded = true;
                }

                renderCustomers(data.rows || []);
                updatePagination(data.total || 0);
            })
            .catch(function (err) {
                if (location.protocol === 'file:') return;
                showAlert('neg', err.message || 'تعذر تحميل بيانات العملاء.');
            });
    }

    function renderCustomers(rows) {
        if (!rows.length) {
            tableBody.innerHTML = '';
            emptyBox.style.display = 'block';
            emptyBox.innerHTML = ui.empty('مفيش عملاء مطابقين للبحث.');
            return;
        }

        emptyBox.style.display = 'none';
        tableBody.innerHTML = rows.map(function (c) {
            const id = Number(c.id);
            const isAct = Number(c.is_active) === 1;

            if (canEdit) {
                return '<tr data-id="' + id + '">' +
                    '<td class="num">' + ui.esc(c.code) + '</td>' +
                    '<td><input class="finput" name="name" value="' + ui.esc(c.name) + '" data-field="name" data-orig="' + ui.esc(c.name) + '"></td>' +
                    '<td><input class="finput" name="phone" dir="ltr" value="' + ui.esc(c.phone || '') + '" data-field="phone" data-orig="' + ui.esc(c.phone || '') + '"></td>' +
                    '<td><input class="finput" name="phone2" dir="ltr" value="' + ui.esc(c.phone2 || '') + '" data-field="phone2" data-orig="' + ui.esc(c.phone2 || '') + '"></td>' +
                    '<td>' +
                        '<select class="fselect" name="kind" data-field="kind" data-orig="' + ui.esc(c.kind) + '">' +
                            '<option value="walkin"' + (c.kind === 'walkin' ? ' selected' : '') + '>طياري</option>' +
                            '<option value="regular"' + (c.kind === 'regular' ? ' selected' : '') + '>دايم</option>' +
                        '</select>' +
                    '</td>' +
                    '<td>' + ui.esc(c.branch_name || '-') + '</td>' +
                    '<td><input class="finput" name="notes" value="' + ui.esc(c.notes || '') + '" data-field="notes" data-orig="' + ui.esc(c.notes || '') + '"></td>' +
                    '<td>' +
                        '<select class="fselect" name="is_active" data-field="is_active" data-orig="' + (isAct ? '1' : '0') + '">' +
                            '<option value="1"' + (isAct ? ' selected' : '') + '>مفعّل</option>' +
                            '<option value="0"' + (!isAct ? ' selected' : '') + '>موقوف</option>' +
                        '</select>' +
                    '</td>' +
                    '<td style="text-align:center;">' +
                        '<button type="button" class="btn btn-sm btn-ghost btnViewStatement" data-id="' + id + '">كشف الحساب</button>' +
                    '</td>' +
                '</tr>';
            }

            const kindChip = c.kind === 'regular'
                ? '<span class="fchip fchip-pos">دايم</span>'
                : '<span class="fchip fchip-mute">طياري</span>';
            const statusChip = isAct
                ? '<span class="fchip fchip-pos">مفعّل</span>'
                : '<span class="fchip fchip-neg">موقوف</span>';

            return '<tr data-id="' + id + '">' +
                '<td class="num">' + ui.esc(c.code) + '</td>' +
                '<td>' + ui.esc(c.name) + '</td>' +
                '<td dir="ltr" style="text-align:right;">' + ui.esc(c.phone || '-') + '</td>' +
                '<td dir="ltr" style="text-align:right;">' + ui.esc(c.phone2 || '-') + '</td>' +
                '<td>' + kindChip + '</td>' +
                '<td>' + ui.esc(c.branch_name || '-') + '</td>' +
                '<td>' + ui.esc(c.notes || '-') + '</td>' +
                '<td>' + statusChip + '</td>' +
                '<td style="text-align:center;">' +
                    '<button type="button" class="btn btn-sm btn-ghost btnViewStatement" data-id="' + id + '">كشف الحساب</button>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function updatePagination(total) {
        countLabel.textContent = 'إجمالي العملاء: ' + total;
        pageIndicator.textContent = 'صفحة ' + currentPage + ' من ' + totalPages;
        prevBtn.disabled = (currentPage <= 1);
        nextBtn.disabled = (currentPage >= totalPages);
    }

    function saveFieldUpdate(el) {
        const row = el.closest('tr[data-id]');
        if (!row) return;
        const id = Number(row.dataset.id);
        const field = el.dataset.field;
        const origVal = el.dataset.orig;
        const newVal = el.value.trim();

        if (newVal === origVal) return;

        clearAlert();
        el.disabled = true;

        window.apiFetch('customers_api.php?part=update_field', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, field: field, value: newVal})
        }).then(function (res) {
            el.dataset.orig = newVal;
            showAlert('pos', 'اتحفظ التعديل.');
        }).catch(function (err) {
            el.value = origVal;
            showAlert('neg', err.message || 'التعديل ماتحفظش.');
        }).finally(function () {
            el.disabled = false;
        });
    }

    tableBody.addEventListener('focusout', function (e) {
        if (e.target.matches('input[data-field]')) {
            saveFieldUpdate(e.target);
        }
    });

    tableBody.addEventListener('change', function (e) {
        if (e.target.matches('select[data-field]')) {
            saveFieldUpdate(e.target);
        }
    });

    tableBody.addEventListener('click', function (e) {
        const btn = e.target.closest('.btnViewStatement');
        if (btn) {
            openStatement(Number(btn.dataset.id));
        }
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(function () {
            loadCustomers(1);
        }, 300);
    });

    branchSelect.addEventListener('change', function () { loadCustomers(1); });
    kindSelect.addEventListener('change', function () { loadCustomers(1); });
    activeSelect.addEventListener('change', function () { loadCustomers(1); });

    prevBtn.addEventListener('click', function () {
        if (currentPage > 1) loadCustomers(currentPage - 1);
    });
    nextBtn.addEventListener('click', function () {
        if (currentPage < totalPages) loadCustomers(currentPage + 1);
    });

    if (btnToggleNew && newCard) {
        btnToggleNew.addEventListener('click', function () {
            const isHidden = newCard.style.display === 'none';
            newCard.style.display = isHidden ? 'block' : 'none';
            if (isHidden) {
                document.getElementById('newCustName').focus();
            }
        });
    }

    if (btnCancelNew && newCard) {
        btnCancelNew.addEventListener('click', function () {
            newCard.style.display = 'none';
            newForm.reset();
        });
    }

    if (newForm) {
        newForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearAlert();

            const payload = {
                name: document.getElementById('newCustName').value.trim(),
                phone: document.getElementById('newCustPhone').value.trim(),
                phone2: document.getElementById('newCustPhone2').value.trim(),
                kind: document.getElementById('newCustKind').value,
                notes: document.getElementById('newCustNotes').value.trim()
            };

            if (newBranchSelect && newBranchSelect.value) {
                payload.branch_id = Number(newBranchSelect.value);
            }

            const btnSave = document.getElementById('btnSaveNewCustomer');
            btnSave.disabled = true;

            window.apiFetch('customers_api.php?part=create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            }).then(function (res) {
                showAlert('pos', 'اتضاف العميل بنجاح.');
                newForm.reset();
                newCard.style.display = 'none';
                loadCustomers(1);
            }).catch(function (err) {
                showAlert('neg', err.message || 'تعذر إضافة العميل.');
            }).finally(function () {
                btnSave.disabled = false;
            });
        });
    }

    function openStatement(customerId) {
        clearAlert();
        window.apiFetch('customers_api.php?part=statement&id=' + encodeURIComponent(customerId))
            .then(function (res) {
                const data = res.data || {};
                renderStatement(data);
                listView.style.display = 'none';
                statementView.style.display = 'block';
                window.scrollTo({top: 0, behavior: 'smooth'});
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر تحميل كشف الحساب.');
            });
    }

    function renderStatement(data) {
        const cust = data.customer || {};
        const kpis = data.kpis || {};
        const statement = data.statement || [];

        document.getElementById('stmtCustomerName').textContent = cust.name + ' (' + (cust.code || cust.id) + ')';
        document.getElementById('stmtCustomerMeta').textContent =
            'الموبايل: ' + (cust.phone || '-') + ' · الفرع: ' + (cust.branch_name || '-');

        const kindWrap = document.getElementById('stmtCustomerKind');
        kindWrap.innerHTML = cust.kind === 'regular'
            ? '<span class="fchip fchip-pos">دايم</span>'
            : '<span class="fchip fchip-mute">طياري</span>';

        btnExportXlsx.href = 'customers_api.php?part=statement&id=' + encodeURIComponent(cust.id) + '&format=xlsx';

        document.querySelectorAll('#stmtKpis [data-xt-orig]').forEach(function (el) { el.removeAttribute('data-xt-orig'); el.removeAttribute('data-xt-filtered'); });
        document.querySelectorAll('#stmtKpis .fkpi-scope').forEach(function (el) { el.remove(); });
        document.getElementById('kpiCount').textContent = kpis.invoices_count || 0;
        document.getElementById('kpiSales').textContent = Number(kpis.total_sales || 0).toFixed(2);
        document.getElementById('kpiReturns').textContent = Number(kpis.total_returns || 0).toFixed(2);
        const netTotal = Number(kpis.net_total || 0);
        const kpiNetEl = document.getElementById('kpiNet');
        kpiNetEl.textContent = netTotal.toFixed(2);
        kpiNetEl.className = 'num-ltr' + (netTotal < 0 ? ' num-neg' : '');
        document.getElementById('kpiLastVisit').textContent = kpis.last_visit || '-';

        const freshStatement = document.createElement('div');
        freshStatement.innerHTML = statementTemplate;
        document.getElementById('statementTable').replaceWith(freshStatement.firstElementChild);
        const stmtBody = document.getElementById('statementTableBody');
        if (!statement.length) {
            stmtBody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:var(--sp-4); color:var(--ink-500);">مفيش حركات مسجّلة للعميل ده.</td></tr>';
            return;
        }

        stmtBody.innerHTML = statement.map(function (row) {
            const isSale = (row.doc_kind === 'sale');
            const kindBadge = isSale
                ? '<span class="fchip fchip-pos">بيع</span>'
                : '<span class="fchip fchip-neg">مرتجع</span>';
            const netDisplay = isSale ? Number(row.net).toFixed(2) : ('−' + Number(row.net).toFixed(2));
            const netClass = isSale ? '' : 'style="color:var(--neg);"';

            return '<tr data-v-count="1" data-v-sales="' + (isSale ? Number(row.net) : 0) + '" data-v-returns="' + (isSale ? 0 : Number(row.net)) + '" data-v-net="' + (isSale ? Number(row.net) : -Number(row.net)) + '">' +
                '<td>' + ui.esc(row.completed_at || row.created_at) + '</td>' +
                '<td class="num">' + ui.esc(row.receipt_no || ('#' + row.id)) + '</td>' +
                '<td>' + kindBadge + '</td>' +
                '<td>' + ui.esc(row.branch_name || '-') + '</td>' +
                '<td>' + ui.esc(row.payments_summary || '-') + '</td>' +
                '<td class="num" ' + netClass + '>' + netDisplay + '</td>' +
                '<td class="num"><strong>' + Number(row.running_balance).toFixed(2) + '</strong></td>' +
            '</tr>';
        }).join('');
    }

    btnBackToList.addEventListener('click', function () {
        statementView.style.display = 'none';
        listView.style.display = 'block';
    });

    loadCustomers(1);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
