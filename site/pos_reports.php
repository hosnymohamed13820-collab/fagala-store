<?php
/** pos_reports.php — عرض تقارير حارس العجز، والداتا كلها من الـAPI. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('pos:reports');
$page_title = 'تقارير نقطة البيع';
$active_module = 'pos';
require_once __DIR__ . '/includes/header.php';
?>

<?= ui_page_head('تقارير نقطة البيع', 'حارس العجز للعمليات الحساسة والتقفيل.', '<a class="btn btn-outline" href="pos.php">رجوع لنقطة البيع</a>') ?>

<div class="fcard">
    <form class="ffilters" id="posReportsFilters">
        <div class="ffield"><label class="flabel" for="prFrom">من</label><input class="finput" type="date" id="prFrom" value="<?= e(date('Y-m-01')) ?>"></div>
        <div class="ffield"><label class="flabel" for="prTo">إلى</label><input class="finput" type="date" id="prTo" value="<?= e(date('Y-m-d')) ?>"></div>
        <div class="ffield"><label class="flabel" for="prBranch">الفرع</label><select class="fselect" id="prBranch"><option value="0">كل الفروع المسموحة</option></select></div>
        <div class="ffield"><label class="flabel" for="prCashier">الكاشير</label><select class="fselect" id="prCashier"><option value="0">كل الكاشيرين</option></select></div>
        <button class="btn btn-primary" type="submit">عرض</button>
        <button class="btn btn-outline" type="button" id="prExport">تصدير إكسيل</button>
    </form>
</div>

<div class="ftabs" id="prTabs"></div>
<div id="prAlert"></div>
<div class="fcard">
    <div class="ftable-wrap"><table class="ftable" id="prTable"><thead></thead><tbody></tbody></table></div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.ui;
    const reports = ['removed', 'drawer', 'discounts', 'returns', 'held_cancelled', 'close_variances'];
    let definitions = {};
    let activeReport = 'removed';
    const alertBox = document.getElementById('prAlert');
    const tabs = document.getElementById('prTabs');
    const table = document.getElementById('prTable');

    function params() {
        const q = new URLSearchParams();
        q.set('part', 'report');
        q.set('report', activeReport);
        q.set('from', document.getElementById('prFrom').value);
        q.set('to', document.getElementById('prTo').value);
        q.set('branch_id', document.getElementById('prBranch').value);
        q.set('cashier_id', document.getElementById('prCashier').value);
        return q;
    }

    const statusLabels = {
        approved: 'معتمد',
        rejected: 'مرفوض',
        pending: 'معلّق',
        submitted: 'مستلمة',
        completed: 'مكتملة'
    };
    const eventTypeLabels = {
        line_removed: 'حذف صنف',
        cart_cleared: 'مسح سلة',
        drawer_open: 'فتح درج',
        held_cancelled: 'إلغاء معلّقة',
        session_opened: 'فتح وردية',
        session_closed: 'إغلاق وردية'
    };

    function chipValue(key, value) {
        if (key === 'status') {
            const cls = value === 'approved' ? 'fchip-pos' : (value === 'rejected' ? 'fchip-neg' : 'fchip-warn');
            const lbl = statusLabels[value] || value || '';
            return '<span class="fchip ' + cls + '">' + ui.esc(String(lbl)) + '</span>';
        }
        if (key === 'event_type') {
            const lbl = eventTypeLabels[value] || value || '';
            return '<span class="fchip fchip-mute">' + ui.esc(String(lbl)) + '</span>';
        }
        if (key === 'return_type' || key === 'is_late' || key === 'method_override') {
            return '<span class="fchip fchip-mute">' + ui.esc(String(value || '')) + '</span>';
        }
        return ui.esc(String(value === null || value === undefined ? '' : value));
    }

    function render(data) {
        const def = data.definition || {};
        const columns = def.columns || {};
        const keys = Object.keys(columns);
        table.querySelector('thead').innerHTML = '<tr>' + keys.map(function (key) {
            return '<th' + (columns[key].num ? ' class="num"' : '') + '>' + ui.esc(columns[key].label) + '</th>';
        }).join('') + '</tr>';
        const rows = data.rows || [];
        table.querySelector('tbody').innerHTML = rows.length ? rows.map(function (row) {
            return '<tr>' + keys.map(function (key) {
                return '<td' + (columns[key].num ? ' class="num"' : '') + '>' + chipValue(key, row[key]) + '</td>';
            }).join('') + '</tr>';
        }).join('') : '<tr><td colspan="' + keys.length + '">مفيش بيانات في الفترة دي.</td></tr>';
    }

    function loadReport() {
        alertBox.innerHTML = '';
        window.apiFetch('pos_reports_api.php?' + params().toString())
            .then(function (res) { render(res.data || {}); })
            .catch(function (err) { alertBox.innerHTML = ui.alert('neg', err.message || 'تعذر تحميل التقرير.'); });
    }

    function initParams() {
        const q = new URLSearchParams();
        q.set('part', 'init');
        q.set('report', activeReport);
        q.set('from', document.getElementById('prFrom').value);
        q.set('to', document.getElementById('prTo').value);
        q.set('branch_id', document.getElementById('prBranch').value);
        q.set('cashier_id', document.getElementById('prCashier').value);
        return q;
    }

    function setupMeta(data) {
        definitions = data.definitions || {};
        document.getElementById('prBranch').innerHTML = '<option value="0">كل الفروع المسموحة</option>' +
            (data.branches || []).map(function (row) { return '<option value="' + Number(row.id) + '">' + ui.esc(row.name) + '</option>'; }).join('');
        document.getElementById('prCashier').innerHTML = '<option value="0">كل الكاشيرين</option>' +
            (data.cashiers || []).map(function (row) { return '<option value="' + Number(row.id) + '">' + ui.esc(row.full_name) + '</option>'; }).join('');
        tabs.innerHTML = reports.map(function (key) {
            return '<button type="button" class="ftab' + (key === activeReport ? ' is-active' : '') + '" data-report="' + ui.esc(key) + '">' + ui.esc(definitions[key].title) + '</button>';
        }).join('');
    }

    window.apiFetch('pos_reports_api.php?' + initParams().toString()).then(function (res) {
        const data = res.data || {};
        setupMeta(data.meta || {});
        render(data.report || {});
    }).catch(function (err) {
        alertBox.innerHTML = ui.alert('neg', err.message || 'تعذر تحميل فلاتر التقارير.');
    });

    tabs.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-report]');
        if (!btn) return;
        activeReport = btn.dataset.report;
        tabs.querySelectorAll('.ftab').forEach(function (tab) { tab.classList.toggle('is-active', tab === btn); });
        loadReport();
    });
    document.getElementById('posReportsFilters').addEventListener('submit', function (event) {
        event.preventDefault();
        loadReport();
    });
    document.getElementById('prExport').addEventListener('click', function () {
        const q = params();
        q.set('export', '1');
        window.location.href = 'pos_reports_api.php?' + q.toString();
    });
});
</script>
