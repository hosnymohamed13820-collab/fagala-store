<?php
/**
 * reorder.php — شاشة حد إعادة الطلب واقتراحات طلب النواقص (بريف ٢١٣)
 * نظام الفجالة ستور المالي
 *
 * عرض أصناف النواقص لكل فرع (الرصيد < الحد الأدنى) المحسوبة تلقائياً
 * مع إمكانية تعديل الحدود بالجدول وإرسال طلب نواقص مجمّع للإدارة بضغطة واحدة.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_module_access('reorder');

require_once __DIR__ . '/reorder_lib.php';

$pdo = db();
$user = $_SESSION['user'] ?? [];
$isOwner = !empty($user['is_owner']);
$userBranchId = (int) ($user['branch_id'] ?? 0);

// ١) الحساب التلقائي أول فتح للصفحة لو MAX(calc_at) أقدم من 24 ساعة
if (reorder_should_recalc($pdo)) {
    reorder_recalc_locked($pdo);
}

// ٢) جلب الفروع المتاحة
$allBranches = reorder_branches($pdo);
if (empty($allBranches)) {
    die('لا توجد فروع مهيأة لحد إعادة الطلب.');
}

// غير المالك يشوف فرعه فقط
if (!$isOwner) {
    if ($userBranchId <= 0) {
        die('المستخدم غير معيّن على أي فرع.');
    }
    $visibleBranches = array_values(array_filter($allBranches, fn($b) => (int) $b['id'] === $userBranchId));
    if (empty($visibleBranches)) {
        die('فرعك غير مشمول في نظام حد إعادة الطلب.');
    }
    $selectedBranchId = $userBranchId;
} else {
    $visibleBranches = $allBranches;
    $reqBranch = isset($_GET['branch']) ? (int) $_GET['branch'] : 0;
    $validIds = array_column($visibleBranches, 'id');
    $selectedBranchId = in_array($reqBranch, $validIds, true) ? $reqBranch : (int) $visibleBranches[0]['id'];
}

$selectedBranch = null;
foreach ($visibleBranches as $b) {
    if ((int) $b['id'] === $selectedBranchId) {
        $selectedBranch = $b;
        break;
    }
}
if (!$selectedBranch) {
    $selectedBranch = $visibleBranches[0];
    $selectedBranchId = (int) $selectedBranch['id'];
}

// ٣) جلب الأقسام للفلتر
$catsMap = reorder_categories($pdo);

$catFilter = isset($_GET['cat']) && (int) $_GET['cat'] > 0 ? (int) $_GET['cat'] : null;
$searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

// ٤) تصدير إكسيل بـ SimpleXlsx
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/xlsx_lib.php';

    $exportItems = reorder_branch_items($pdo, $selectedBranchId, $catFilter, $searchQuery);
    $branchName = (string) ($selectedBranch['name'] ?? 'فرع');

    $x = new SimpleXlsx('حد إعادة الطلب');
    $x->setColumnTypes(['s', 's', 's', 's', 'n', 'n', 'n', 'n', 'n', 'n', 'n']);
    $x->addRow([
        'الفرع',
        'كود الصنف',
        'اسم الصنف',
        'القسم',
        'رصيد الفرع',
        'الحد الأدنى',
        'الحد الأقصى',
        'المقترح',
        'رصيد الإدارة',
        'بيع/يوم',
        'تغطية الأيام',
    ], true);

    foreach ($exportItems as $it) {
        $x->addRow([
            $branchName,
            (string) $it['code'],
            (string) $it['name'],
            (string) $it['cat_name'],
            (float) $it['branch_balance'],
            (float) $it['min_qty'],
            (float) $it['max_qty'],
            (float) $it['proposed_qty'],
            (float) $it['hq_balance'],
            (float) $it['avg_daily'],
            (float) $it['coverage_days'],
        ]);
    }

    $fileName = 'reorder_' . $selectedBranchId . '_' . date('Ymd_His') . '.xlsx';
    $x->download($fileName);
    exit;
}

// ٥) جلب الأصناف للشاشة
$items = reorder_branch_items($pdo, $selectedBranchId, $catFilter, $searchQuery);

// وقت آخر حساب
$lastCalcAt = reorder_last_calc_at($pdo) ?: 'لم يُحسب بعد';

$page_title = 'حد إعادة الطلب — ' . e($selectedBranch['name']);
require_once __DIR__ . '/includes/header.php';
?>

<style>
.reorder-container {
    max-width: 1240px;
    margin: 0 auto;
    padding: 12px 16px 40px;
}
.reorder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.reorder-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.reorder-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    background: var(--surface);
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--line);
    margin-bottom: 16px;
}
.reorder-tools-right, .reorder-tools-left {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.reorder-search-box {
    position: relative;
    min-width: 220px;
}
.reorder-search-box .finput { width:100%; padding-inline-start:30px; }
.reorder-search-box .search-icon {
    position: absolute;
    right: 9px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 10px;
    border: 1px solid var(--line);
    background: var(--surface);
}
.reorder-table {
    margin: 0;
    width: 100%;
    border-collapse: collapse;
}
.reorder-table th.num, .reorder-table td.num {
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.reorder-input {
    width: 68px;
}
.reorder-input.saving {
    background: var(--warn-soft);
}
.reorder-input.saved {
    background: var(--pos-soft);
}

.proposed-val {
    font-weight: 700;
    color: var(--pos);
    font-size: 14px;
}
.coverage-danger {
    color: var(--neg);
    font-weight: 600;
}
.coverage-warn {
    color: var(--warn);
    font-weight: 600;
}
.item-name-cell {
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.reset-btn {
    padding: 3px 8px;
    font-size: 12px;
    border-radius: 6px;
    cursor: pointer;
    border: 1px solid var(--line);
    background: var(--surface-soft);
    color: var(--text);
    font-family: inherit;
    transition: .15s;
}
.reset-btn:hover {
    border-color: var(--accent);
    background: var(--accent-soft);
}
.fchip-manual {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--warn-soft);
    color: var(--warn);
    font-weight: 600;
}
.toast-msg {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: var(--z-toast);
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: var(--shadow);
    display: none;
    align-items: center;
    gap: 8px;
}
.toast-success { background: var(--pos-soft); color: var(--pos); border: 1px solid var(--pos); }
.toast-error   { background: var(--neg-soft); color: var(--neg); border: 1px solid var(--neg); }
</style>

<div class="reorder-container">
    <div class="reorder-header">
        <div>
            <h1 class="reorder-title">
                <i class="ti-package"></i>
                حد إعادة الطلب واقتراحات النواقص
            </h1>
            <div style="font-size: 13px; color: var(--muted); margin-top: 4px;">
                آخر حساب تلقائي: <?= e((string) $lastCalcAt) ?>
            </div>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
            <?php if ($isOwner): ?>
            <button type="button" class="btn btn-outline btn-sm" id="btnRecalc" onclick="triggerRecalc()">
                <i class="ti-refresh"></i>
                احسب دلوقتي
            </button>
            <?php endif; ?>
            <a href="reorder.php?export=xlsx&branch=<?= $selectedBranchId ?>&cat=<?= (int)$catFilter ?>&q=<?= urlencode($searchQuery) ?>" class="btn btn-ghost btn-sm">
                <i class="ti-file-spreadsheet"></i>
                تصدير إكسيل
            </a>
        </div>
    </div>

    <!-- تابات الفروع -->
    <?php if ($isOwner || count($visibleBranches) > 1): ?>
    <div class="ftabs">
        <?php foreach ($visibleBranches as $b): ?>
        <a href="reorder.php?branch=<?= (int)$b['id'] ?>&cat=<?= (int)$catFilter ?>&q=<?= urlencode($searchQuery) ?>" 
           class="ftab <?= (int)$b['id'] === $selectedBranchId ? 'is-active' : '' ?>">
            <i class="ti ti-building-store"></i>
            <?= e($b['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- شريط الأدوات والفلترة -->
    <div class="reorder-toolbar">
        <div class="reorder-tools-right">
            <div class="reorder-search-box">
                <i class="ti ti-search search-icon"></i>
                <input class="finput finput-sm" type="text" id="searchInput" placeholder="بحث بالاسم أو الكود..." value="<?= e($searchQuery) ?>" oninput="onSearchInput(this.value)">
            </div>

            <?php if (!empty($catsMap)): ?>
            <select class="fselect finput-sm reorder-select" id="catSelect" onchange="onCatChange(this.value)">
                <option value="">كل الأقسام (<?= count($catsMap) ?>)</option>
                <?php foreach ($catsMap as $cid => $cname): ?>
                <option value="<?= $cid ?>" <?= $catFilter === $cid ? 'selected' : '' ?>><?= e($cname) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>

        <div class="reorder-tools-left">
            <span id="selectedCounter" style="font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums;">
                مختار: <b id="selCount">0</b> صنف
            </span>
            <button type="button" class="btn btn-primary btn-sm" id="btnSendShortage" onclick="sendShortageRequest()" disabled>
                <i class="ti ti-send"></i>
                ابعت طلب نواقص للإدارة
            </button>
        </div>
    </div>

    <!-- جدول الأصناف -->
    <div class="ftable-wrap">
        <table class="ftable reorder-table" id="reorderTable">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">
                        <input type="checkbox" id="chkSelectAll" onclick="toggleSelectAll(this)" title="تحديد الكل">
                    </th>
                    <th style="width: 130px;">الكود</th>
                    <th>الاسم</th>
                    <th style="width: 120px;">القسم</th>
                    <th class="num" style="width: 80px;">الرصيد</th>
                    <th class="num" style="width: 90px;">الحد الأدنى</th>
                    <th class="num" style="width: 90px;">الحد الأقصى</th>
                    <th class="num" style="width: 85px;">المقترح</th>
                    <th class="num" style="width: 85px;">رصيد الإدارة</th>
                    <th class="num" style="width: 80px;">بيع/يوم</th>
                    <th style="width: 110px; text-align: center;">حالة التعديل</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 30px; color: var(--muted);">
                        لا توجد أصناف تحت حد إعادة الطلب لهذا الفرع حالياً.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $it): 
                    $iid = (int) $it['item_id'];
                    $cov = (float) $it['coverage_days'];
                    $covClass = ($it['branch_balance'] <= 0 || $cov <= 2) ? 'coverage-danger' : ($cov <= 5 ? 'coverage-warn' : '');
                ?>
                <tr id="row_<?= $iid ?>" data-item-id="<?= $iid ?>" data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-proposed="<?= (float)$it['proposed_qty'] ?>">
                    <td style="text-align: center;">
                        <input type="checkbox" class="row-chk" value="<?= $iid ?>" onchange="onRowCheckChange()">
                    </td>
                    <td>
                        <span style="font-variant-numeric:tabular-nums;font-size:12px;"><?= e($it['code']) ?></span>
                    </td>
                    <td class="item-name-cell" title="<?= e($it['name']) ?>">
                        <?= e($it['name']) ?>
                    </td>
                    <td style="font-size: 13px; color: var(--muted);">
                        <?= e($it['cat_name']) ?>
                    </td>
                    <td class="num <?= $covClass ?>">
                        <?= (float) $it['branch_balance'] ?>
                    </td>
                    <td class="num">
                        <input type="number" 
                               class="finput finput-sm num reorder-input"
                               id="min_<?= $iid ?>" 
                               value="<?= (float) $it['min_qty'] ?>" 
                               step="1" 
                               min="0"
                               onblur="saveRowLimits(<?= $iid ?>)">
                    </td>
                    <td class="num">
                        <input type="number" 
                               class="finput finput-sm num reorder-input"
                               id="max_<?= $iid ?>" 
                               value="<?= (float) $it['max_qty'] ?>" 
                               step="1" 
                               min="0"
                               onblur="saveRowLimits(<?= $iid ?>)">
                    </td>
                    <td class="num">
                        <span class="proposed-val" id="prop_<?= $iid ?>">
                            <?= (float) $it['proposed_qty'] ?>
                        </span>
                    </td>
                    <td class="num" style="color: var(--muted);">
                        <?= (float) $it['hq_balance'] ?>
                    </td>
                    <td class="num" style="color: var(--muted);">
                        <?= (float) $it['avg_daily'] ?>
                    </td>
                    <td style="text-align: center;">
                        <span id="badge_<?= $iid ?>" class="fchip-manual" style="display: <?= $it['is_manual'] ? 'inline-block' : 'none' ?>;">
                            يدوي
                        </span>
                        <button type="button" 
                                class="reset-btn" 
                                id="btnReset_<?= $iid ?>" 
                                style="display: <?= $it['is_manual'] ? 'inline-block' : 'none' ?>;"
                                onclick="resetRowManual(<?= $iid ?>)"
                                title="استعادة الحساب التلقائي">
                            <i class="ti-rotate"></i>
                            تلقائي
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toastBox" class="toast-msg"></div>

<script>
var CURRENT_BRANCH_ID = <?= $selectedBranchId ?>;
var searchTimer = null;

function showToast(msg, isSuccess, linkUrl, linkText) {
    var b = document.getElementById('toastBox');
    if (!b) return;
    b.className = 'toast-msg ' + (isSuccess ? 'toast-success' : 'toast-error');
    var html = (isSuccess ? '<i class="ti-check"></i> ' : '<i class="ti-alert-triangle"></i> ') + msg;
    if (linkUrl) {
        html += ' <a href="' + linkUrl + '" style="color:inherit;text-decoration:underline;margin-right:8px;font-weight:700;">' + (linkText || 'عرض') + ' ⇦</a>';
    }
    b.innerHTML = html;
    b.style.display = 'inline-flex';
    setTimeout(function() {
        b.style.display = 'none';
    }, 6000);
}

// ===== تحديد الصفوف وإرسال الطلب =====
function toggleSelectAll(master) {
    var chks = document.querySelectorAll('.row-chk');
    for (var i = 0; i < chks.length; i++) {
        chks[i].checked = master.checked;
    }
    onRowCheckChange();
}

function onRowCheckChange() {
    var checked = document.querySelectorAll('.row-chk:checked');
    var total = document.querySelectorAll('.row-chk').length;
    var count = checked.length;
    
    document.getElementById('selCount').textContent = count;
    document.getElementById('btnSendShortage').disabled = (count === 0);
    
    var master = document.getElementById('chkSelectAll');
    if (master) {
        master.checked = (total > 0 && count === total);
    }
}

function sendShortageRequest() {
    var checked = document.querySelectorAll('.row-chk:checked');
    if (!checked.length) return;

    var lines = [];
    checked.forEach(function(chk) {
        var tr = document.getElementById('row_' + chk.value);
        if (!tr) return;
        var code = tr.getAttribute('data-code') || '';
        var name = tr.getAttribute('data-name') || '';
        var qty = parseFloat(tr.getAttribute('data-proposed') || '0');
        if (code && qty > 0) {
            lines.push({
                item_id: parseInt(chk.value, 10),
                code: code,
                name: name,
                qty: qty
            });
        }
    });

    if (!lines.length) {
        showToast('مفيش أصناف بكميات صالحة للإرسال', false);
        return;
    }

    var btn = document.getElementById('btnSendShortage');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti-rotate" style="animation:spin 1s linear infinite;"></i> جاري الإرسال...';

    window.apiFetch('reorder_api.php?action=create_shortage', {
        method: 'POST',
        body: {
            branch_id: CURRENT_BRANCH_ID,
            lines: lines
        }
    }).then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti-send"></i> ابعت طلب نواقص للإدارة';

        if (res && res.ok && res.data) {
            showToast('تم إنشاء طلب النواقص بنجاح (' + res.data.lines_count + ' صنف)', true, res.data.task_url, 'فتح المهمة');
            // إلغاء تحديد الأصناف
            checked.forEach(function(chk) { chk.checked = false; });
            onRowCheckChange();
        } else {
            showToast((res && res.error) ? res.error : 'فشل إرسال الطلب', false);
        }
    }).catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti-send"></i> ابعت طلب نواقص للإدارة';
        showToast('تعذر الاتصال بالسيرفر', false);
    });
}

// ===== تعديل الحدود يدويًا بالجدول وحفظه على blur =====
function saveRowLimits(itemId) {
    var minInp = document.getElementById('min_' + itemId);
    var maxInp = document.getElementById('max_' + itemId);
    if (!minInp || !maxInp) return;

    var minVal = parseFloat(minInp.value) || 0;
    var maxVal = parseFloat(maxInp.value) || 0;

    minInp.classList.add('saving');
    maxInp.classList.add('saving');

    window.apiFetch('reorder_api.php?action=update_limits', {
        method: 'POST',
        body: {
            item_id: itemId,
            branch_id: CURRENT_BRANCH_ID,
            min_qty: minVal,
            max_qty: maxVal
        }
    }).then(function(res) {
        minInp.classList.remove('saving');
        maxInp.classList.remove('saving');

        if (res && res.ok && res.data) {
            minInp.classList.add('saved');
            maxInp.classList.add('saved');
            setTimeout(function() {
                minInp.classList.remove('saved');
                maxInp.classList.remove('saved');
            }, 1200);

            // تحديث المقترح في الـ DOM
            var propSpan = document.getElementById('prop_' + itemId);
            if (propSpan) propSpan.textContent = res.data.proposed_qty;
            var tr = document.getElementById('row_' + itemId);
            if (tr) tr.setAttribute('data-proposed', res.data.proposed_qty);

            // إظهار شارة يدوي وزرار الاستعادة
            var badge = document.getElementById('badge_' + itemId);
            if (badge) badge.style.display = 'inline-block';
            var btnRst = document.getElementById('btnReset_' + itemId);
            if (btnRst) btnRst.style.display = 'inline-block';
        } else {
            showToast((res && res.error) ? res.error : 'فشل حفظ التعديل', false);
        }
    }).catch(function() {
        minInp.classList.remove('saving');
        maxInp.classList.remove('saving');
        showToast('تعذر حفظ الحد الأدنى/الأقصى', false);
    });
}

// ===== استعادة الحساب التلقائي =====
function resetRowManual(itemId) {
    var btn = document.getElementById('btnReset_' + itemId);
    if (btn) btn.disabled = true;

    window.apiFetch('reorder_api.php?action=reset_manual', {
        method: 'POST',
        body: {
            item_id: itemId,
            branch_id: CURRENT_BRANCH_ID
        }
    }).then(function(res) {
        if (btn) btn.disabled = false;
        if (res && res.ok && res.data) {
            var minInp = document.getElementById('min_' + itemId);
            var maxInp = document.getElementById('max_' + itemId);
            if (minInp) minInp.value = res.data.min_qty;
            if (maxInp) maxInp.value = res.data.max_qty;

            var propSpan = document.getElementById('prop_' + itemId);
            if (propSpan) propSpan.textContent = res.data.proposed_qty;
            var tr = document.getElementById('row_' + itemId);
            if (tr) tr.setAttribute('data-proposed', res.data.proposed_qty);

            var badge = document.getElementById('badge_' + itemId);
            if (badge) badge.style.display = 'none';
            if (btn) btn.style.display = 'none';

            showToast('تمت استعادة الحساب التلقائي للصنف', true);
        } else {
            showToast((res && res.error) ? res.error : 'فشل الاستعادة', false);
        }
    }).catch(function() {
        if (btn) btn.disabled = false;
        showToast('تعذر الاتصال بالسيرفر', false);
    });
}

// ===== زرار احسب دلوقتي (المالك) =====
function triggerRecalc() {
    var btn = document.getElementById('btnRecalc');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="ti-rotate" style="animation:spin 1s linear infinite;"></i> جاري الحساب...';

    window.apiFetch('reorder_api.php?action=recalc', {
        method: 'POST'
    }).then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti-refresh"></i> احسب دلوقتي';
        if (res && res.ok && res.data) {
            showToast('تم الحساب بنجاح (' + res.data.items + ' صنف في ' + res.data.ms + 'ms)', true);
            setTimeout(function() {
                location.reload();
            }, 1000);
        } else {
            showToast((res && res.error) ? res.error : 'فشل إعادة الحساب', false);
        }
    }).catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti-refresh"></i> احسب دلوقتي';
        showToast('تعذر الاتصال بالسيرفر', false);
    });
}

// ===== البحث والفلترة =====
function onSearchInput(val) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        applyFilters();
    }, 350);
}

function onCatChange() {
    applyFilters();
}

function applyFilters() {
    var cat = document.getElementById('catSelect') ? document.getElementById('catSelect').value : '';
    var q = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim() : '';
    var url = 'reorder.php?branch=' + CURRENT_BRANCH_ID;
    if (cat) url += '&cat=' + encodeURIComponent(cat);
    if (q) url += '&q=' + encodeURIComponent(q);
    location.href = url;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
