<?php
/**
 * item_sales.php — صفحة تحليل المبيعات: حالة الرصيد · حركة البيع · الأرباح
 * نظام الفجالة ستور المالي (بريف ٩٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('item_sales');
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/item_sales_lib.php';

$canStock    = can('item_sales:stock');
$canMovement = can('item_sales:movement');
$canProfit   = can('item_sales:profit');

if ($canProfit) {
    sens_page();
}

$fromParam = isset($_GET['from']) && trim((string)$_GET['from']) !== '' ? trim((string)$_GET['from']) : null;
$toParam   = isset($_GET['to']) && trim((string)$_GET['to']) !== '' ? trim((string)$_GET['to']) : null;
$stoParam  = isset($_GET['sto']) && trim((string)$_GET['sto']) !== '' ? (int)$_GET['sto'] : null;
$catParam  = isset($_GET['cat']) && trim((string)$_GET['cat']) !== '' ? (int)$_GET['cat'] : null;
$byParam   = (($_GET['by'] ?? 'qty') === 'revenue') ? 'revenue' : 'qty';
$tabParam  = trim((string)($_GET['tab'] ?? ''));

// تحديد التبويب الافتراضي بناءً على أول صلاحية متاحة للمستخدم
if ($tabParam === '' || !in_array($tabParam, ['stock', 'movement', 'profit'], true)) {
    if ($canStock) {
        $tabParam = 'stock';
    } elseif ($canMovement) {
        $tabParam = 'movement';
    } elseif ($canProfit) {
        $tabParam = 'profit';
    } else {
        $tabParam = 'stock';
    }
}

$period = isl_period($fromParam, $toParam);
$from   = $period['from'];
$to     = $period['to'];
$days   = $period['days'];

$storesList = an_stores();
$catsData   = isl_categories_map();
$allCats    = $catsData['all_cats'];

// =====================================================================
//  تصدير إكسيل (قبل أي HTML وبنفس الحراسة)
//  نفس SimpleXlsx بتاع التقارير: يمين لشمال، العناوين كحلي، والكود نص
//  (من غير ما إكسيل يحوّله لرقم ويشيل الأصفار اللي على الشمال).
// =====================================================================
$export = trim((string)($_GET['export'] ?? ''));
if ($export !== '') {
    require_once __DIR__ . '/xlsx_lib.php';

    if ($export === 'stock' && $canStock) {
        $coverDays = (int) setting('item_sales_cover_days', '7');
        $stockData = isl_stock_status($from, $to, $coverDays);

        $x = new SimpleXlsx('حالة الرصيد');
        $x->setColumnTypes(['s', 's', 's', 's', 's', 'n', 'n', 'n', 'n']);
        $x->addRow(['الفرع', 'كود الصنف', 'اسم الصنف', 'القسم', 'الحالة', 'الرصيد', 'مبيعات الفترة', 'متوسط اليوم', 'يكفّي كام يوم'], true);

        foreach ($stockData as $sid => $sInfo) {
            if ($stoParam !== null && $stoParam > 0 && $sid !== $stoParam) {
                continue;
            }
            $lists = [
                'سالب'       => $sInfo['negative'],
                'صفر'        => $sInfo['zero'],
                'قرب يخلص'   => $sInfo['near'],
                'ماتباعش'    => $sInfo['idle'],
            ];
            foreach ($lists as $stLabel => $rows) {
                foreach ($rows as $r) {
                    if ($catParam !== null && $catParam > 0 && (int)$r['cat_id'] !== $catParam) {
                        continue;
                    }
                    $x->addRow([
                        $r['store_name'],
                        (string) $r['barcode'],
                        $r['name'],
                        $r['cat_name'],
                        $stLabel,
                        $r['balance'],
                        $r['qty'],
                        $r['daily_avg'],
                        $r['days_left'] !== null ? $r['days_left'] : '',
                    ]);
                }
            }
        }
        $x->download('item_sales_stock_' . $from . '_' . $to . '.xlsx');
        exit;
    }

    if ($export === 'movement' && $canMovement) {
        $movementData = isl_movement($from, $to, $stoParam, $catParam, $byParam, 10000);

        // التكلفة والربح للي معاه جزء الأرباح والأرقام مفتوحة بس
        $withProfit = $canProfit && sens_unlocked();
        $hdr   = ['النوع', 'كود الصنف', 'اسم الصنف', 'القسم', 'الكمية المباعة', 'الإيراد الصافي'];
        $types = ['s', 's', 's', 's', 'n', 'n'];
        if ($withProfit) {
            $hdr   = array_merge($hdr, ['التكلفة', 'الربح', 'الهامش %']);
            $types = array_merge($types, ['n', 'n', 'n']);
        }
        $hdr[] = 'الرصيد';
        $types[] = 'n';

        $x = new SimpleXlsx('حركة البيع');
        $x->setColumnTypes($types);
        $x->addRow($hdr, true);

        foreach (['top' => 'الأكثر مبيعاً', 'bottom' => 'الأقل مبيعاً'] as $k => $lbl) {
            foreach ($movementData[$k] as $r) {
                $line = [$lbl, (string) $r['barcode'], $r['name'], $r['cat_name'], $r['qty'], $r['revenue']];
                if ($withProfit) $line = array_merge($line, [$r['cost'], $r['profit'], $r['margin']]);
                $line[] = $r['balance'];
                $x->addRow($line);
            }
        }
        $x->download('item_sales_movement_' . $from . '_' . $to . '.xlsx');
        exit;
    }

    if ($export === 'profit' && $canProfit) {
        if (!sens_unlocked()) {
            http_response_code(423);
            echo 'الأرقام الحساسة مقفولة';
            exit;
        }

        $profitData = isl_profit($from, $to, $stoParam, $catParam);

        $x = new SimpleXlsx('الأرباح');
        $x->setColumnTypes(['s', 's', 's', 'n', 'n', 'n', 'n', 'n', 'n']);
        $x->addRow(['كود الصنف', 'اسم الصنف', 'القسم', 'الكمية المباعة', 'الإيراد الصافي', 'التكلفة', 'الربح', 'الهامش %', 'سطور بدون تكلفة'], true);

        foreach ($profitData['items'] as $r) {
            $x->addRow([
                (string) $r['barcode'],
                $r['name'],
                $r['cat_name'],
                $r['qty'],
                $r['revenue'],
                $r['cost'],
                $r['profit'],
                $r['margin'],
                $r['zero_cost_lines'],
            ]);
        }
        $x->download('item_sales_profit_' . $from . '_' . $to . '.xlsx');
        exit;
    }
}

// تحميل بيانات التبويب النشط فقط لتقليل المعالجة
$stockData    = null;
$movementData = null;
$profitData   = null;

if ($canStock) {
    $coverDays = (int) setting('item_sales_cover_days', '7');
    $stockData = isl_stock_status($from, $to, $coverDays);
}

if ($canMovement && $tabParam === 'movement') {
    $movementData = isl_movement($from, $to, $stoParam, $catParam, $byParam, 50);
}

if ($canProfit && $tabParam === 'profit' && sens_unlocked()) {
    $profitData = isl_profit($from, $to, $stoParam, $catParam);
}

$page_title    = 'تحليل المبيعات';
$active_module = 'item_sales';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.isl-header-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg, 12px);
    padding: var(--sp-4);
    margin-bottom: var(--sp-4);
}
.isl-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.isl-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.isl-label {
    font-size: var(--fs-xs, 12px);
    font-weight: 600;
    color: var(--ink-500);
}
.isl-input, .isl-select {
    padding: 7px 12px;
    border: 1px solid var(--line);
    border-radius: var(--r-md, 8px);
    background: var(--surface);
    color: var(--ink-800);
    font-family: inherit;
    font-size: var(--fs-sm, 13.5px);
}
.isl-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--line-soft, #e2e8f0);
    margin-bottom: var(--sp-4);
    padding-bottom: 4px;
}
.isl-tab {
    padding: 8px 18px;
    border-radius: var(--r-md, 8px) var(--r-md, 8px) 0 0;
    font-weight: 600;
    font-size: var(--fs-sm, 14px);
    color: var(--ink-500);
    text-decoration: none;
    transition: all .15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.isl-tab:hover {
    color: var(--ink-800);
    background: var(--bg);
}
.isl-tab.active {
    color: var(--fill-strong-fg, #fff);
    background: var(--fill-strong, #1e293b);
}
/* نسخ أزرار الفلترة بدقة من invoices.php */
.status-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.sf-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: var(--surface);
    color: var(--ink-600);
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
}
.sf-btn:hover {
    border-color: var(--ink-300);
    color: var(--ink-800);
}
.sf-btn span {
    display: inline-block;
    min-width: 20px;
    padding: 1px 6px;
    border-radius: 20px;
    background: var(--line-soft, #f1f5f9);
    color: var(--ink-500);
    font-size: 11.5px;
    font-weight: 700;
}
.sf-btn.active {
    background: var(--fill-strong, #1e293b);
    border-color: var(--fill-strong, #1e293b);
    color: var(--fill-strong-fg, #fff);
}
.sf-btn.active span {
    background: var(--fill-strong-veil, rgba(255,255,255,0.25));
    color: var(--fill-strong-fg, #fff);
}
.isl-table-container {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg, 12px);
    overflow-x: auto;
    margin-bottom: var(--sp-6);
}
.isl-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--fs-sm, 13.5px);
}
.isl-table th {
    background: var(--bg);
    color: var(--ink-600);
    font-weight: 600;
    padding: 10px 14px;
    text-align: right;
    border-bottom: 1px solid var(--line);
    white-space: nowrap;
}
.isl-table td {
    padding: 9px 14px;
    border-bottom: 1px solid var(--line-soft, #f1f5f9);
    color: var(--ink-800);
}
.isl-table tr:hover {
    background: var(--line-soft);
}
.isl-table .num,
.isl-table th.num {
    font-variant-numeric: tabular-nums;
    direction: ltr;
    text-align: center;
    white-space: nowrap;
}
.isl-table td.isl-name {
    font-weight: 600;
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.isl-table td.isl-dim { color: var(--ink-500); }
/* شارة الحالة — نفس ألوان الموقع عشان الليلي */
.isl-chip {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: var(--fs-xs, 12px);
    font-weight: 600;
    white-space: nowrap;
}
.isl-chip-negative { background: var(--neg-soft); color: var(--neg); }
.isl-chip-zero     { background: var(--warn-soft); color: var(--warn); }
.isl-chip-near     { background: var(--warn-soft); color: var(--warn); }
.isl-chip-idle     { background: var(--line-soft); color: var(--ink-600); }
.isl-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 900px) {
    .isl-grid-2 { grid-template-columns: 1fr; }
}
.isl-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}
.isl-kpi-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-md, 10px);
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.isl-kpi-title {
    font-size: var(--fs-xs, 12px);
    color: var(--ink-500);
    font-weight: 600;
}
.isl-kpi-val {
    font-size: var(--fs-xl, 22px);
    font-weight: 700;
    color: var(--ink-900);
    font-family: monospace;
}
.isl-tag-negative {
    color: var(--neg);
    font-weight: 600;
}
.isl-tag-zero {
    color: var(--warn);
    font-weight: 600;
}
.isl-tag-near {
    color: var(--warn);
    font-weight: 600;
}
.isl-tag-idle {
    color: var(--ink-500);
}
.alert-warning {
    background: var(--warn-soft);
    border: 1px solid var(--warn);
    color: var(--warn);
    padding: 10px 16px;
    border-radius: var(--r-md, 8px);
    margin-bottom: 16px;
    font-size: var(--fs-sm, 13.5px);
    display: flex;
    align-items: center;
    gap: 8px;
}
.empty-box {
    padding: 32px;
    text-align: center;
    color: var(--ink-400);
    font-size: var(--fs-sm, 14px);
}
.isl-cap-note {
    font-size: var(--fs-sm, 13px);
    color: var(--ink-600);
    margin-bottom: var(--sp-2);
}
</style>

<div class="content-wrapper">
    <!-- كارت الفلاتر الرئيسي -->
    <div class="isl-header-card">
        <form method="get" id="filterForm" class="isl-filters">
            <input type="hidden" name="tab" value="<?= e($tabParam) ?>">
            <?php if ($tabParam === 'movement'): ?>
                <input type="hidden" name="by" value="<?= e($byParam) ?>">
            <?php endif; ?>

            <div class="isl-field">
                <label class="isl-label">من تاريخ:</label>
                <input type="date" name="from" class="isl-input" value="<?= e($from) ?>" onchange="this.form.submit()">
            </div>

            <div class="isl-field">
                <label class="isl-label">إلى تاريخ:</label>
                <input type="date" name="to" class="isl-input" value="<?= e($to) ?>" onchange="this.form.submit()">
            </div>

            <div class="isl-field">
                <label class="isl-label">المخزن / الفرع:</label>
                <select name="sto" class="isl-select" onchange="this.form.submit()">
                    <option value="">كل الفروع والمخازن</option>
                    <?php foreach ($storesList as $sid => $sInfo): ?>
                        <option value="<?= $sid ?>" <?= ($stoParam === $sid) ? 'selected' : '' ?>>
                            <?= e($sInfo['name']) ?><?= $sInfo['is_hq'] ? ' (الإدارة)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="isl-field">
                <label class="isl-label">القسم:</label>
                <select name="cat" class="isl-select" onchange="this.form.submit()">
                    <option value="">كل الأقسام</option>
                    <?php foreach ($allCats as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= ($catParam === $cid) ? 'selected' : '' ?>>
                            <?= e($cname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-right:auto; display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary">تحديث</button>
                <?php
                $csvQs = http_build_query([
                    'tab'    => $tabParam,
                    'export' => $tabParam,
                    'from'   => $from,
                    'to'     => $to,
                    'sto'    => $stoParam,
                    'cat'    => $catParam,
                    'by'     => $byParam,
                ]);
                ?>
                <a href="?<?= $csvQs ?>" class="btn btn-ghost" title="تصدير كل الصفوف إلى إكسيل">
                    <i class="ti ti-file-spreadsheet"></i> تصدير إكسيل
                </a>
            </div>
        </form>
    </div>

    <!-- التبويبات الثلاثة -->
    <div class="isl-tabs">
        <?php if ($canStock): ?>
            <?php $qsStock = http_build_query(array_merge($_GET, ['tab' => 'stock'])); ?>
            <a href="?<?= $qsStock ?>" class="isl-tab <?= $tabParam === 'stock' ? 'active' : '' ?>">
                <i class="ti ti-packages"></i> حالة الرصيد
            </a>
        <?php endif; ?>

        <?php if ($canMovement): ?>
            <?php $qsMove = http_build_query(array_merge($_GET, ['tab' => 'movement'])); ?>
            <a href="?<?= $qsMove ?>" class="isl-tab <?= $tabParam === 'movement' ? 'active' : '' ?>">
                <i class="ti ti-transfer"></i> حركة البيع
            </a>
        <?php endif; ?>

        <?php if ($canProfit): ?>
            <?php $qsProfit = http_build_query(array_merge($_GET, ['tab' => 'profit'])); ?>
            <a href="?<?= $qsProfit ?>" class="isl-tab <?= $tabParam === 'profit' ? 'active' : '' ?>">
                <i class="ti ti-lock"></i> الأرباح
            </a>
        <?php endif; ?>
    </div>

    <!-- =================================================================== -->
    <!--  الجزء الأول: حالة الرصيد                                           -->
    <!-- =================================================================== -->
    <?php if ($tabParam === 'stock' && $canStock): ?>
        <?php
        // أقصى عدد صفوف بيترسم لكل حالة — «ماتباعش» لوحدها حوالي ٥ آلاف صنف على الداتا الحقيقية
        // وده بيتقّل الصفحة على الموبايل. العدد في الزرار هو الكامل، والتصدير فيه كل الصفوف.
        if (!defined('ISL_SHOW_MAX')) define('ISL_SHOW_MAX', 200);
        $countNeg = 0; $countZero = 0; $countNear = 0; $countIdle = 0;
        $combinedRows = [];

        foreach ($stockData as $sid => $sInfo) {
            if ($stoParam !== null && $stoParam > 0 && $sid !== $stoParam) {
                continue;
            }
            foreach ($sInfo['negative'] as $r) {
                if ($catParam !== null && $catParam > 0 && (int)$r['cat_id'] !== $catParam) continue;
                $countNeg++;
                if ($countNeg > ISL_SHOW_MAX) continue; // الباقي في التصدير
                $r['status_type'] = 'negative';
                $r['status_label'] = 'سالب';
                $combinedRows[] = $r;
            }
            foreach ($sInfo['zero'] as $r) {
                if ($catParam !== null && $catParam > 0 && (int)$r['cat_id'] !== $catParam) continue;
                $countZero++;
                if ($countZero > ISL_SHOW_MAX) continue; // الباقي في التصدير
                $r['status_type'] = 'zero';
                $r['status_label'] = 'صفر';
                $combinedRows[] = $r;
            }
            foreach ($sInfo['near'] as $r) {
                if ($catParam !== null && $catParam > 0 && (int)$r['cat_id'] !== $catParam) continue;
                $countNear++;
                if ($countNear > ISL_SHOW_MAX) continue; // الباقي في التصدير
                $r['status_type'] = 'near';
                $r['status_label'] = 'قرب يخلص';
                $combinedRows[] = $r;
            }
            foreach ($sInfo['idle'] as $r) {
                if ($catParam !== null && $catParam > 0 && (int)$r['cat_id'] !== $catParam) continue;
                $countIdle++;
                if ($countIdle > ISL_SHOW_MAX) continue; // الباقي في التصدير
                $r['status_type'] = 'idle';
                $r['status_label'] = 'ماتباعش';
                $combinedRows[] = $r;
            }
        }
        $countTotal = $countNeg + $countZero + $countNear + $countIdle;
        ?>

        <!-- ٤ أزرار فلتر بالعدد بالحرف من invoices.php -->
        <div class="status-filters" id="stockStatusFilters">
            <button type="button" class="sf-btn active" data-st="all" onclick="applyStockFilter('all', this)">
                الكل <span><?= $countTotal ?></span>
            </button>
            <button type="button" class="sf-btn" data-st="negative" id="btnNegativeFilter" onclick="applyStockFilter('negative', this)">
                سالب <span><?= $countNeg ?></span>
            </button>
            <button type="button" class="sf-btn" data-st="zero" onclick="applyStockFilter('zero', this)">
                صفر <span><?= $countZero ?></span>
            </button>
            <button type="button" class="sf-btn" data-st="near" onclick="applyStockFilter('near', this)">
                قرب يخلص <span><?= $countNear ?></span>
            </button>
            <button type="button" class="sf-btn" data-st="idle" onclick="applyStockFilter('idle', this)">
                ماتباعش <span><?= $countIdle ?></span>
            </button>
        </div>

        <?php if (max($countNeg, $countZero, $countNear, $countIdle) > ISL_SHOW_MAX): ?>
            <div class="isl-cap-note">معروض أول <?= ISL_SHOW_MAX ?> صنف من كل حالة بس — العدد الكامل في الزرار، وكل الأصناف في «تصدير».</div>
        <?php endif; ?>

        <div class="isl-table-container">
            <?php if (empty($combinedRows)): ?>
                <div class="empty-box">مفيش أصناف مطابقة للبحث.</div>
            <?php else: ?>
                <table class="isl-table" id="stockTable">
                    <thead>
                        <tr>
                            <th>الحالة</th>
                            <th>الفرع</th>
                            <th>الكود</th>
                            <th>الاسم</th>
                            <th>القسم</th>
                            <th class="num">الرصيد</th>
                            <th class="num">مبيعات الفترة</th>
                            <th class="num">متوسط اليوم</th>
                            <th class="num">يكفّي كام يوم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combinedRows as $r): ?>
                            <tr data-st="<?= $r['status_type'] ?>">
                                <td><span class="isl-chip isl-chip-<?= e($r['status_type']) ?>"><?= e($r['status_label']) ?></span></td>
                                <td><?= e($r['store_name']) ?></td>
                                <td class="num"><?= e($r['barcode']) ?></td>
                                <td class="isl-name" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></td>
                                <td class="<?= (int) $r['cat_id'] === 0 ? 'isl-dim' : '' ?>"><?= e($r['cat_name']) ?></td>
                                <td class="num <?= $r['balance'] < 0 ? 'isl-tag-negative' : '' ?>">
                                    <?= (float)$r['balance'] ?>
                                </td>
                                <td class="num"><?= (float)$r['qty'] ?></td>
                                <td class="num"><?= (float)$r['daily_avg'] ?></td>
                                <td class="num <?= ($r['days_left'] !== null && $r['days_left'] <= 7) ? 'isl-tag-near' : '' ?>">
                                    <?= $r['days_left'] !== null ? $r['days_left'] : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        function applyStockFilter(type, btn) {
            document.querySelectorAll('#stockStatusFilters .sf-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            try {
                localStorage.setItem('isl_stock_filter', type);
            } catch(e) {}

            var rows = document.querySelectorAll('#stockTable tbody tr');
            rows.forEach(function(row) {
                var rowType = row.getAttribute('data-st');
                if (type === 'all' || rowType === type) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // استرجاع الفلتر المحفوظ من localStorage
        document.addEventListener('DOMContentLoaded', function() {
            try {
                var saved = localStorage.getItem('isl_stock_filter');
                if (saved) {
                    var btn = document.querySelector('#stockStatusFilters .sf-btn[data-st="' + saved + '"]');
                    if (btn) applyStockFilter(saved, btn);
                }
            } catch(e) {}
        });
        </script>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!--  الجزء الثاني: حركة البيع                                            -->
    <!-- =================================================================== -->
    <?php if ($tabParam === 'movement' && $canMovement): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
            <div style="font-weight:600; color:var(--ink-700);">
                حركة الأصناف (الأكثر والأقل مبيعاً) — الفترة <?= e($from) ?> إلى <?= e($to) ?> (<?= $days ?> يوم)
            </div>
            <div style="display:flex; gap:6px;">
                <?php
                $qsQty = http_build_query(array_merge($_GET, ['by' => 'qty']));
                $qsRev = http_build_query(array_merge($_GET, ['by' => 'revenue']));
                ?>
                <a href="?<?= $qsQty ?>" class="btn <?= $byParam === 'qty' ? 'btn-primary' : 'btn-ghost' ?>">
                    بالكمية
                </a>
                <a href="?<?= $qsRev ?>" class="btn <?= $byParam === 'revenue' ? 'btn-primary' : 'btn-ghost' ?>">
                    بالقيمة
                </a>
            </div>
        </div>

        <div class="isl-grid-2">
            <!-- جدول الأكثر مبيعاً -->
            <div>
                <h3 style="font-size:15px; margin-bottom:8px; color:var(--ink-800);">الأكثر مبيعاً (Top 50)</h3>
                <div class="isl-table-container">
                    <?php if (empty($movementData['top'])): ?>
                        <div class="empty-box">لا توجد مبيعات في هذه الفترة.</div>
                    <?php else: ?>
                        <table class="isl-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th>القسم</th>
                                    <th class="num"><?= $byParam === 'revenue' ? 'الإيراد الصافي' : 'الكمية' ?></th>
                                    <th class="num">الرصيد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 1; foreach ($movementData['top'] as $r): ?>
                                    <tr>
                                        <td><?= $idx++ ?></td>
                                        <td><?= e($r['barcode']) ?></td>
                                        <td style="font-weight:600;"><?= e($r['name']) ?></td>
                                        <td><?= e($r['cat_name']) ?></td>
                                        <td class="num" style="font-weight:700; color:var(--fill-strong);">
                                            <?= $byParam === 'revenue' ? number_format($r['revenue'], 2) : (float)$r['qty'] ?>
                                        </td>
                                        <td class="num"><?= (float)$r['balance'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- جدول الأقل مبيعاً -->
            <div>
                <h3 style="font-size:15px; margin-bottom:8px; color:var(--ink-800);">الأقل مبيعاً (Bottom 50 - مبيعاتها > 0)</h3>
                <div class="isl-table-container">
                    <?php if (empty($movementData['bottom'])): ?>
                        <div class="empty-box">لا توجد مبيعات في هذه الفترة.</div>
                    <?php else: ?>
                        <table class="isl-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th>القسم</th>
                                    <th class="num"><?= $byParam === 'revenue' ? 'الإيراد الصافي' : 'الكمية' ?></th>
                                    <th class="num">الرصيد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 1; foreach ($movementData['bottom'] as $r): ?>
                                    <tr>
                                        <td><?= $idx++ ?></td>
                                        <td><?= e($r['barcode']) ?></td>
                                        <td style="font-weight:600;"><?= e($r['name']) ?></td>
                                        <td><?= e($r['cat_name']) ?></td>
                                        <td class="num" style="font-weight:700;">
                                            <?= $byParam === 'revenue' ? number_format($r['revenue'], 2) : (float)$r['qty'] ?>
                                        </td>
                                        <td class="num"><?= (float)$r['balance'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!--  الجزء الثالث: الأرباح                                              -->
    <!-- =================================================================== -->
    <?php if ($tabParam === 'profit' && $canProfit): ?>
        <?php if (!sens_unlocked()): ?>
            <?php sens_lock_screen('الأرباح'); ?>
        <?php else: ?>
            <?php if ($profitData['zero_cost_lines'] > 0): ?>
                <div class="alert-warning">
                    <i class="ti ti-alert-triangle"></i>
                    <span>تنبيه: فيه <?= $profitData['zero_cost_lines'] ?> سطر من غير تكلفة — ربحهم طالع أعلى من الحقيقي.</span>
                </div>
            <?php endif; ?>

            <!-- كروت الإجمالي -->
            <?php $tot = $profitData['totals']; ?>
            <div class="isl-kpi-grid">
                <div class="isl-kpi-card">
                    <div class="isl-kpi-title">إجمالي الإيراد الصافي</div>
                    <div class="isl-kpi-val"><?= number_format($tot['revenue'], 2) ?></div>
                </div>
                <div class="isl-kpi-card">
                    <div class="isl-kpi-title">إجمالي التكلفة</div>
                    <div class="isl-kpi-val"><?= number_format($tot['cost'], 2) ?></div>
                </div>
                <div class="isl-kpi-card">
                    <div class="isl-kpi-title">إجمالي الربح</div>
                    <div class="isl-kpi-val" style="color:var(--pos);"><?= number_format($tot['profit'], 2) ?></div>
                </div>
                <div class="isl-kpi-card">
                    <div class="isl-kpi-title">هامش الربح العام</div>
                    <div class="isl-kpi-val" style="color:var(--fill-strong);"><?= $tot['margin'] ?>%</div>
                </div>
            </div>

            <div class="isl-grid-2">
                <!-- أرباح الفروع -->
                <div>
                    <h3 style="font-size:15px; margin-bottom:8px; color:var(--ink-800);">الأرباح حسب الفرع</h3>
                    <div class="isl-table-container">
                        <table class="isl-table">
                            <thead>
                                <tr>
                                    <th>الفرع</th>
                                    <th class="num">الكمية</th>
                                    <th class="num">الإيراد الصافي</th>
                                    <th class="num">التكلفة</th>
                                    <th class="num">الربح</th>
                                    <th class="num">الهامش</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profitData['by_store'] as $st): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?= e($st['name']) ?></td>
                                        <td class="num"><?= (float)$st['qty'] ?></td>
                                        <td class="num"><?= number_format($st['revenue'], 2) ?></td>
                                        <td class="num"><?= number_format($st['cost'], 2) ?></td>
                                        <td class="num" style="font-weight:700; color:var(--pos);"><?= number_format($st['profit'], 2) ?></td>
                                        <td class="num"><?= $st['margin'] ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- أرباح الأقسام -->
                <div>
                    <h3 style="font-size:15px; margin-bottom:8px; color:var(--ink-800);">الأرباح حسب القسم</h3>
                    <div class="isl-table-container">
                        <table class="isl-table">
                            <thead>
                                <tr>
                                    <th>القسم</th>
                                    <th class="num">الكمية</th>
                                    <th class="num">الإيراد الصافي</th>
                                    <th class="num">التكلفة</th>
                                    <th class="num">الربح</th>
                                    <th class="num">الهامش</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profitData['by_cat'] as $ct): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?= e($ct['name']) ?></td>
                                        <td class="num"><?= (float)$ct['qty'] ?></td>
                                        <td class="num"><?= number_format($ct['revenue'], 2) ?></td>
                                        <td class="num"><?= number_format($ct['cost'], 2) ?></td>
                                        <td class="num" style="font-weight:700; color:var(--pos);"><?= number_format($ct['profit'], 2) ?></td>
                                        <td class="num"><?= $ct['margin'] ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- أعلى ١٠٠ صنف ربحاً -->
            <h3 style="font-size:15px; margin-bottom:8px; color:var(--ink-800);">أعلى ١٠٠ صنف ربحاً</h3>
            <div class="isl-table-container">
                <?php if (empty($profitData['items'])): ?>
                    <div class="empty-box">مفيش أصناف مباعة في هذه الفترة.</div>
                <?php else: ?>
                    <table class="isl-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>القسم</th>
                                <th class="num">الكمية</th>
                                <th class="num">الإيراد الصافي</th>
                                <th class="num">التكلفة</th>
                                <th class="num">الربح</th>
                                <th class="num">الهامش</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach (array_slice($profitData['items'], 0, 100) as $it): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= e($it['barcode']) ?></td>
                                    <td style="font-weight:600;"><?= e($it['name']) ?></td>
                                    <td><?= e($it['cat_name']) ?></td>
                                    <td class="num"><?= (float)$it['qty'] ?></td>
                                    <td class="num"><?= number_format($it['revenue'], 2) ?></td>
                                    <td class="num"><?= number_format($it['cost'], 2) ?></td>
                                    <td class="num" style="font-weight:700; color:var(--pos);"><?= number_format($it['profit'], 2) ?></td>
                                    <td class="num"><?= $it['margin'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
