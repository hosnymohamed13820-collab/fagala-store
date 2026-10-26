<?php
/**
 * activity.php — صفحة «كل حركة على السيستم» للمدير
 * نظام الفجالة ستور المالي (بريف ١٩٥ · اتقفلت في بريف ٢٠٦)
 *
 * كل نوع في الخط الزمني = استعلام واحد بـLIMIT 200، والدمج في PHP.
 * الصفحة قراية بس — مفيش أي INSERT/UPDATE/DELETE من هنا.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// مخصصة للمالك فقط
if (!is_owner()) {
    http_response_code(403);
    die('هذه الصفحة مخصصة للمالك فقط.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/activity_lib.php';
sens_page();

$pdo = db();

[$branchesList, $branchById, $branchBySto] = act_get_branches();

// =========================================================================
//  ١. كروت «محتاج تصرّف» — كل رقم استعلام مستقل
// =========================================================================

$cardsData             = act_cards_data();
$pendingShiftsCount    = $cardsData['pending_shifts'];
$pendingCustodyCount   = $cardsData['pending_custody'];
$lateInvoicesTotal     = $cardsData['late_invoices_total'];
$lateInvoicesBreakdown = $cardsData['late_invoices_breakdown'];
$smsNoPermitCount      = $cardsData['sms_no_permit'];
$smsCardFrom           = $cardsData['sms_card_from'];
$smsCardTo             = $cardsData['sms_card_to'];

// =========================================================================
//  ٢. الفلاتر
// =========================================================================

$period = (string) ($_GET['period'] ?? 'today');
$view = (string) ($_GET['view'] ?? 'timeline');
$branchFilter = isset($_GET['branch']) && $_GET['branch'] !== 'all' && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;
$typeFilter = (string) ($_GET['type'] ?? 'all');
$customFrom = (string) ($_GET['from'] ?? '');
$customTo = (string) ($_GET['to'] ?? '');

if (!isset($_GET['view'])) {
    if ($typeFilter === 'purchases' || $typeFilter === 'returns') {
        $view = 'purchases';
    } elseif ($typeFilter === 'sales') {
        $view = 'sales';
    }
}
if (!in_array($view, ['timeline', 'sales', 'purchases'], true)) {
    $view = 'timeline';
}
if (!isset($_GET['view']) && $view === 'purchases' && $branchFilter !== null) {
    // اللينك القديم كان بيبعت branches.id؛ تاب المشتريات بيستخدم company_branch.
    $branchFilter = act_company_branch_of($branchFilter, $branchById);
}
$validTypes = ['all', 'shifts', 'custody', 'receiving', 'treasury',
               'shortage', 'attendance', 'stocktake', 'sms', 'newitems',
               'permits', 'transfers', 'salaries', 'advances'];
if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = 'all';
}

$timeStart = microtime(true);
[$dateStart, $dateEnd, $period] = act_date_range($period, $customFrom, $customTo);

// لينكات التابات والفروع بتحافظ على نفس الفترة.
$periodParams = ['period' => $period];
if ($customFrom !== '') {
    $periodParams['from'] = $customFrom;
}
if ($customTo !== '') {
    $periodParams['to'] = $customTo;
}

// =========================================================================
//  ٣. استعلامات الخط الزمني / المبيعات / المشتريات
// =========================================================================

$feed = [];
$newItemsEver = false;
if ($view === 'timeline') {
    $timelineData = act_timeline_data($dateStart, $dateEnd, $typeFilter, $branchFilter, $branchById);
    $feed = $timelineData['feed'];
    $newItemsEver = $timelineData['new_items_ever'];
}

// المبيعات: عدد التاب من كل الفروع، والتفاصيل اليومية من استعلام مجمّع واحد.
$salesBranches = br_inv_map();
$salesCounts = [];
$salesDays = [];
$salesTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
$salesTooLong = false;
$salesInvoices = [];
$salesLinesByInvoice = [];
$salesAgentNames = [];
$totalSalesCount = 0;
$salesTotalPages = 1;
$salesPage = 1;
$salesOffset = 0;
$salesLimit = 200;

if ($view === 'sales') {
    $salesPage = max(1, (int) ($_GET['page'] ?? 1));
    $salesData = act_sales_data($dateStart, $dateEnd, $period, $branchFilter, $salesPage, 200);
    $salesTooLong        = $salesData['too_long'];
    $salesBranches       = $salesData['branches'];
    $salesAgentNames     = $salesData['agent_names'];
    $salesCounts         = $salesData['counts'];
    $salesDays           = $salesData['days'];
    $salesTotals         = $salesData['totals'];
    $totalSalesCount     = $salesData['total_count'];
    $salesTotalPages     = $salesData['total_pages'];
    $salesPage           = $salesData['page'];
    $salesOffset         = $salesData['offset'];
    $salesLimit          = $salesData['limit'];
    $salesInvoices       = $salesData['invoices'];
    $salesLinesByInvoice = $salesData['lines_by_invoice'];
    $branchFilter        = $salesData['branch_filter'];
}

// المشتريات: إجماليات التابات والكروت من كل الفواتير، ثم أول ٥٠٠ صف للعرض.
$purchaseBranches = [1, 2, 3, 5, 6];
$purchaseCounts = [];
$purchaseTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
$purchaseRows = [];

if ($view === 'purchases') {
    $purchasesData    = act_purchases_data($dateStart, $dateEnd, $branchFilter);
    $purchaseBranches = $purchasesData['branches'];
    $purchaseCounts   = $purchasesData['counts'];
    $purchaseTotals   = $purchasesData['totals'];
    $purchaseRows     = $purchasesData['rows'];
    $branchFilter     = $purchasesData['branch_filter'];
}

$timeElapsedMs = round((microtime(true) - $timeStart) * 1000, 2);

$pageTitle = 'حركات النظام';
$active = 'activity';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.rv-pane { display:none; } .rv-pane.active { display:block; }
.activity-stat { min-width:0; }
.activity-stat-label { display:block; color:var(--ink-500); font-size:12px; margin-bottom:6px; }
.activity-stat-value { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; }
.activity-table-wrap { overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; }
.activity-table-wrap .ftable { width:100%; min-width:650px; margin:0; }
.activity-table-wrap th.num, .activity-table-wrap td.num { text-align:left; font-variant-numeric:tabular-nums; white-space:nowrap; }
.activity-table-wrap tfoot { font-weight:700; }
/* كروت محتاج تصرف */
.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.action-tile {
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-decoration: none;
    color: var(--text);
    transition: transform 0.15s;
}
.action-tile:hover {
    transform: translateY(-2px);
}
.action-tile-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.action-tile-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-500);
}
.action-tile-num {
    font-size: 24px;
    font-weight: 700;
    line-height: 1.1;
}
.action-tile-sub {
    font-size: 11px;
    color: var(--ink-400);
    margin-top: 2px;
}
.action-tile-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

/* جدول الخط الزمني */
.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.timeline-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.timeline-meta {
    font-size: 12px;
    color: var(--ink-400);
}
.timeline-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.activity-row:hover {
    background: var(--line-soft);
}
.activity-link {
    color: var(--accent-text);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12.5px;
}
.activity-link:hover {
    text-decoration: underline;
}
.empty-box {
    text-align: center;
    padding: 40px 16px;
    color: var(--ink-400);
}

/* أعمدة الجدول — العرض من هنا مش من inline style (عشان الموبايل يلغيها) */
.act-col-time   { width: 115px; }
.act-col-type   { width: 120px; }
.act-col-branch { width: 110px; }
.act-col-actor  { width: 130px; }
.act-col-link   { width: 75px; text-align: center; }

/* موبايل ٣٩٠ — مفيش سكرول بالعرض */
@media (max-width: 600px) {
    .action-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .action-tile { padding: 10px 12px; }
    .action-tile-num { font-size: 20px; }
    .action-tile-icon { width: 34px; height: 34px; font-size: 18px; }
    .ffilters { padding: 10px; gap: 8px; }
    .ffilters > div { width: 100%; }
    .ffilters .fselect, .ffilters .finput { flex: 1 1 auto; min-width: 0; }
    .ffilters .btn { width: 100%; margin-right: 0 !important; }
    .act-table th, .act-table td { padding: 7px 6px; font-size: 11.5px; }
    .act-table .act-col-time { width: 62px; }
    .act-table .act-col-type { width: 82px; }
    .act-table .act-col-branch { width: 66px; }
    .act-table .act-col-actor { display: none; }
    .act-table .act-col-link { width: 38px; }
    .act-table .fchip { white-space: normal; padding: 2px 6px; }
    .act-table .fchip i { display: none; }
    .activity-link { font-size: 11px; }
}
</style>

<div class="content-container">

    <nav class="ftabs" aria-label="أقسام حركات النظام">
        <?php foreach (['timeline' => 'الخط الزمني', 'sales' => 'المبيعات', 'purchases' => 'المشتريات'] as $tabKey => $tabLabel): ?>
            <a class="ftab <?= $view === $tabKey ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => $tabKey]))) ?>"><?= e($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($view === 'timeline'): ?>

    <!-- كروت «محتاج تصرّف» -->
    <div class="action-grid">
        <!-- ورديات مستنية مراجعة -->
        <a href="review.php" class="fcard action-tile">
            <div class="action-tile-info">
                <span class="action-tile-label">ورديات مستنية مراجعة</span>
                <span class="action-tile-num" style="color:<?= $pendingShiftsCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $pendingShiftsCount ?>
                </span>
                <span class="action-tile-sub"><?= $pendingShiftsCount > 0 ? 'تحتاج فحص المحاسب' : 'كل الورديات مراجعة' ?></span>
            </div>
            <div class="action-tile-icon" style="background:var(--warn-soft);color:var(--warn);">
                <i class="ti ti-clock"></i>
            </div>
        </a>

        <!-- عهد مستنية اعتماد -->
        <a href="custody.php" class="fcard action-tile">
            <div class="action-tile-info">
                <span class="action-tile-label">عهد مستنية اعتماد</span>
                <span class="action-tile-num" style="color:<?= $pendingCustodyCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $pendingCustodyCount ?>
                </span>
                <span class="action-tile-sub"><?= $pendingCustodyCount > 0 ? 'بانتظار الاعتماد' : 'لا توجد عهد معلقة' ?></span>
            </div>
            <div class="action-tile-icon" style="background:var(--accent-soft);color:var(--accent-text);">
                <i class="ti ti-cash"></i>
            </div>
        </a>

        <!-- فواتير متأخر استلامها -->
        <a href="receiving.php" class="fcard action-tile">
            <div class="action-tile-info">
                <span class="action-tile-label">فواتير متأخر استلامها</span>
                <span class="action-tile-num" style="color:<?= $lateInvoicesTotal > 0 ? 'var(--neg)' : 'var(--pos)' ?>;">
                    <?= $lateInvoicesTotal ?>
                </span>
                <span class="action-tile-sub">
                    <?php if ($lateInvoicesTotal > 0 && !empty($lateInvoicesBreakdown)): ?>
                        <?= e(implode(' · ', array_slice($lateInvoicesBreakdown, 0, 2))) ?>
                    <?php else: ?>
                        لا توجد فواتير متأخرة
                    <?php endif; ?>
                </span>
            </div>
            <div class="action-tile-icon" style="background:var(--neg-soft);color:var(--neg);">
                <i class="ti ti-truck"></i>
            </div>
        </a>

        <!-- رسايل إنستا الواردة من غير إذن — آخر ٧ أيام -->
        <a href="sms_inbox.php?from=<?= e($smsCardFrom) ?>&to=<?= e($smsCardTo) ?>&kind=in" class="fcard action-tile">
            <div class="action-tile-info">
                <span class="action-tile-label">رسايل إنستا بدون إذن</span>
                <span class="action-tile-num" style="color:<?= $smsNoPermitCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $smsNoPermitCount ?>
                </span>
                <span class="action-tile-sub"><?= $smsNoPermitCount > 0 ? 'واردة آخر ٧ أيام — تحتاج تسوية أو إذن' : 'كل الرسايل الواردة مضبوطة' ?></span>
            </div>
            <div class="action-tile-icon" style="background:var(--accent-soft);color:var(--accent-text);">
                <i class="ti ti-device-mobile"></i>
            </div>
        </a>
    </div>

    <?php endif; ?>

    <!-- شريط الفلاتر -->
    <form method="GET" action="activity.php" class="ffilters">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="periodSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-calendar"></i> الفترة:</label>
            <select class="fselect finput-sm" name="period" id="periodSelect" onchange="toggleCustomDates(this.value)">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>النهارده</option>
                <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>أمس</option>
                <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>آخر ٧ أيام</option>
                <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>آخر ٣٠ يوم</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>الشهر ده</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>فترة مخصصة...</option>
            </select>
        </div>

        <div id="customDatesWrap" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;align-items:center;gap:6px;">
            <input class="finput finput-sm" type="date" name="from" value="<?= e($customFrom) ?>" title="من تاريخ">
            <span style="color:var(--ink-400);">إلى</span>
            <input class="finput finput-sm" type="date" name="to" value="<?= e($customTo) ?>" title="إلى تاريخ">
        </div>

        <?php if ($view === 'timeline'): ?>
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="branchSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-building-store"></i> الفرع:</label>
            <select class="fselect finput-sm" name="branch" id="branchSelect">
                <option value="all">كل الفروع</option>
                <?php foreach ($branchesList as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $branchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;align-items:center;gap:6px;">
            <label for="typeSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-filter"></i> النوع:</label>
            <select class="fselect finput-sm" name="type" id="typeSelect">
                <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>كل الحركات</option>
                <option value="newitems" <?= $typeFilter === 'newitems' ? 'selected' : '' ?>>أصناف جديدة</option>
                <option value="shifts" <?= $typeFilter === 'shifts' ? 'selected' : '' ?>>ورديات</option>
                <option value="custody" <?= $typeFilter === 'custody' ? 'selected' : '' ?>>عهد</option>
                <option value="receiving" <?= $typeFilter === 'receiving' ? 'selected' : '' ?>>استلام فواتير</option>
                <option value="treasury" <?= $typeFilter === 'treasury' ? 'selected' : '' ?>>خزينة</option>
                <option value="transfers" <?= $typeFilter === 'transfers' ? 'selected' : '' ?>>تحويلات خزائن</option>
                <option value="shortage" <?= $typeFilter === 'shortage' ? 'selected' : '' ?>>نواقص وطلبات</option>
                <option value="attendance" <?= $typeFilter === 'attendance' ? 'selected' : '' ?>>حضور وانصراف</option>
                <option value="stocktake" <?= $typeFilter === 'stocktake' ? 'selected' : '' ?>>جرد</option>
                <option value="sms" <?= $typeFilter === 'sms' ? 'selected' : '' ?>>رسايل بنك</option>
                <option value="permits" <?= $typeFilter === 'permits' ? 'selected' : '' ?>>أذون إنستاباي</option>
                <option value="salaries" <?= $typeFilter === 'salaries' ? 'selected' : '' ?>>صرف مرتبات</option>
                <option value="advances" <?= $typeFilter === 'advances' ? 'selected' : '' ?>>سلف</option>
            </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-sm" style="margin-right:auto;">
            <i class="ti ti-filter"></i> تصفية
        </button>
    </form>

    <?php if ($view === 'timeline'): ?>
    <!-- الخط الزمني -->
    <div class="fcard timeline-panel">
        <div class="timeline-header">
            <h3 class="timeline-title">
                <i class="ti ti-list-details" style="color:var(--accent-text);"></i> سجل الحركات بالوقت
                <span class="fchip fchip-mute" style="font-size:11px;"><?= count($feed) ?> حركة</span>
            </h3>
            <span class="timeline-meta">
                الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?> · تم التحميل في <?= e((string)$timeElapsedMs) ?> مللي ثانية
            </span>
        </div>

        <div class="timeline-table-wrap">
            <table class="ftable act-table" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th class="act-col-time">الوقت</th>
                        <th class="act-col-type">النوع</th>
                        <th class="act-col-branch">الفرع</th>
                        <th>الوصف</th>
                        <th class="act-col-actor">مين</th>
                        <th class="act-col-link">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feed)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-box">
                                    <i class="ti ti-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                                    <?php if ($typeFilter === 'newitems'): ?>
                                        <?php if (!$newItemsEver): ?>
                                            مفيش أصناف اتضافت من وقت ما بدأنا نسجّل.
                                        <?php else: ?>
                                            مفيش أصناف جديدة في الفترة المختارة.
                                        <?php endif; ?>
                                    <?php else: ?>
                                        لا توجد حركات مسجلة في هذه الفترة بالفلاتر المحددة.
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feed as $item): ?>
                            <tr class="activity-row">
                                <td class="act-col-time" style="font-size:12px;color:var(--ink-500);white-space:nowrap;" dir="ltr">
                                    <?= e($item['time_label'] ?? date('m/d H:i', strtotime($item['time']))) ?>
                                </td>
                                <td class="act-col-type">
                                    <span class="fchip <?= e($item['badge_class']) ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;">
                                        <i class="ti <?= e($item['icon']) ?>"></i> <?= e($item['type_label']) ?>
                                    </span>
                                </td>
                                <td class="act-col-branch" style="font-weight:600;font-size:12.5px;">
                                    <?= e($item['branch']) ?>
                                </td>
                                <td style="font-size:13px;line-height:1.4;">
                                    <?php /* desc جواه HTML من sens_mask — الأجزاء النصية اتعملها e() وقت البناء */ ?>
                                    <?= $item['desc'] ?>
                                </td>
                                <td class="act-col-actor" style="font-size:12px;color:var(--ink-600);">
                                    <?= e($item['actor']) ?>
                                </td>
                                <td class="act-col-link">
                                    <?php if (!empty($item['link'])): ?>
                                        <a href="<?= e($item['link']) ?>" class="activity-link" title="عرض التفاصيل">
                                            فتح <i class="ti ti-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($view === 'sales'): ?>
        <nav class="ftabs" aria-label="فروع المبيعات">
            <a class="ftab <?= $branchFilter === null ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'sales', 'branch' => 'all']))) ?>">الكل</a>
            <?php foreach ($salesBranches as $b): ?>
                <a class="ftab <?= $branchFilter === (int) $b['id'] ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'sales', 'branch' => (int) $b['id']]))) ?>">
                    <?= e($b['name']) ?> (<?= number_format($salesCounts[(string) $b['agent']] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if ($salesTooLong): ?>
            <div class="fcard timeline-panel">اختار فترة أقل من سنة.</div>
        <?php else: ?>
            <?php $salesNet = $salesTotals['sale_sum'] - $salesTotals['ret_sum']; ?>
            <div class="fkpis">
                <?php foreach ([
                    ['فواتير البيع', number_format($salesTotals['sale_cnt'])],
                    ['إجمالي البيع', sens_mask(number_format($salesTotals['sale_sum'], 2))],
                    ['عدد المرتجعات', number_format($salesTotals['ret_cnt'])],
                    ['إجمالي المرتجعات', sens_mask(number_format($salesTotals['ret_sum'], 2))],
                    ['الصافي', sens_mask(number_format($salesNet, 2))],
                ] as [$label, $value]): ?>
                    <div class="activity-stat"><span class="activity-stat-label"><?= e($label) ?></span><span class="activity-stat-value"><?= $value ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="fcard timeline-panel">
                <div class="timeline-header"><h3 class="timeline-title">فواتير المبيعات</h3><span class="timeline-meta">الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?></span></div>
                <div class="activity-table-wrap">
                    <table class="ftable">
                        <thead><tr><th>التاريخ والوقت</th>
                            <?php if ($branchFilter === null): ?>
                                <th>الفرع</th>
                            <?php endif; ?>
                            <th>رقم الفاتورة</th>
                            <th>النوع</th>
                            <th class="num">عدد الأصناف</th>
                            <th class="num">الإجمالي</th>
                            <th class="num">الصافي</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($salesInvoices as $inv): ?>
                                <?php
                                $invKey = $inv['agent_key'] . '|' . $inv['sth_id'] . '|' . $inv['suffix'];
                                $lines = $salesLinesByInvoice[$invKey] ?? [];
                                $itemCount = count($lines);
                                $isSale = (int) $inv['doc_type'] === 4;
                                $typeChip = $isSale ? 'fchip-pos' : 'fchip-warn';
                                $typeLabel = $isSale ? 'بيع' : 'مرتجع';
                                ?>
                                <tr>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string) $inv['invoice_date']))) ?></td>
                                    <?php if ($branchFilter === null): ?>
                                        <td><?= e($salesAgentNames[(string) $inv['agent_key']] ?? $inv['agent_key']) ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span style="font-weight:600;"><?= e((string) $inv['doc_no']) ?></span>
                                        <button type="button" class="btn-icon" onclick="var d=this.closest('tr').nextElementSibling; d.hidden=!d.hidden; var i=this.querySelector('i'); i.classList.toggle('ti-chevron-down'); i.classList.toggle('ti-arrow-up');" style="background:none;border:none;cursor:pointer;padding:2px 4px;font-size:13px;color:var(--ink-500);vertical-align:middle;" title="عرض أصناف الفاتورة" aria-label="عرض الأصناف">
                                            <i class="ti ti-chevron-down"></i>
                                        </button>
                                    </td>
                                    <td><span class="fchip <?= $typeChip ?>"><?= $typeLabel ?></span></td>
                                    <td class="num"><?= number_format($itemCount) ?></td>
                                    <td class="num"><?= sens_mask(number_format((float) $inv['total_amount'], 2)) ?></td>
                                    <td class="num"><?= sens_mask(number_format((float) $inv['net_amount'], 2)) ?></td>
                                </tr>
                                <tr data-xt-ignore data-xt-nosort hidden>
                                    <td colspan="<?= $branchFilter === null ? 7 : 6 ?>" style="padding:10px 16px;background:var(--surface-soft);">
                                        <?php if (empty($lines)): ?>
                                            <span style="color:var(--ink-500);font-size:13px;">مفيش أصناف مسجلة للفاتورة دي.</span>
                                        <?php else: ?>
                                            <table class="ftable" style="font-size:12px;margin:4px 0;background:var(--surface);">
                                                <thead>
                                                    <tr>
                                                        <th>الكود</th>
                                                        <th>اسم الصنف</th>
                                                        <th class="num">الكمية</th>
                                                        <th class="num">السعر</th>
                                                        <th class="num">الإجمالي</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($lines as $line): ?>
                                                        <?php $lineTotal = (float) $line['quantity'] * (float) $line['unit_price']; ?>
                                                        <tr>
                                                            <td><?= e((string) $line['item_code']) ?></td>
                                                            <td><?= e((string) $line['item_name']) ?></td>
                                                            <td class="num"><?= number_format((float) $line['quantity'], 2) ?></td>
                                                            <td class="num"><?= sens_mask(number_format((float) $line['unit_price'], 2)) ?></td>
                                                            <td class="num"><?= sens_mask(number_format($lineTotal, 2)) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($salesInvoices)): ?>
                                <tr><td colspan="<?= $branchFilter === null ? 7 : 6 ?>" style="text-align:center;padding:24px;color:var(--ink-500);">مفيش مبيعات في الفترة دي.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot><tr>
                            <td>إجمالي الفترة كلها</td>
                            <?php if ($branchFilter === null): ?>
                                <td></td>
                            <?php endif; ?>
                            <td></td>
                            <td><span class="fchip fchip-pos">بيع <?= number_format($salesTotals['sale_cnt']) ?></span> <span class="fchip fchip-warn">مرتجع <?= number_format($salesTotals['ret_cnt']) ?></span></td>
                            <td class="num"></td>
                            <td class="num"><?= sens_mask(number_format($salesTotals['sale_sum'], 2)) ?></td>
                            <td class="num"><?= sens_mask(number_format($salesNet, 2)) ?></td>
                        </tr></tfoot>
                    </table>
                </div>
                <?php if ($totalSalesCount > 0): ?>
                    <?php
                    $startX = $salesOffset + 1;
                    $endY = min($salesOffset + $salesLimit, $totalSalesCount);
                    $pageBaseParams = array_merge($periodParams, ['view' => 'sales']);
                    if ($branchFilter !== null) {
                        $pageBaseParams['branch'] = $branchFilter;
                    } else {
                        $pageBaseParams['branch'] = 'all';
                    }
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:14px;padding:4px 2px;font-size:13px;color:var(--ink-500);">
                        <div>
                            فاتورة <?= number_format($startX) ?>–<?= number_format($endY) ?> من <?= number_format($totalSalesCount) ?>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <?php if ($salesPage > 1): ?>
                                <a class="btn-page" href="<?= e(act_url(array_merge($pageBaseParams, ['page' => $salesPage - 1]))) ?>" style="padding:5px 12px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--ink-900);text-decoration:none;">
                                    الأحدث
                                </a>
                            <?php else: ?>
                                <span class="btn-page disabled" style="padding:5px 12px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--ink-300);cursor:not-allowed;">
                                    الأحدث
                                </span>
                            <?php endif; ?>

                            <?php if ($salesPage < $salesTotalPages): ?>
                                <a class="btn-page" href="<?= e(act_url(array_merge($pageBaseParams, ['page' => $salesPage + 1]))) ?>" style="padding:5px 12px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--ink-900);text-decoration:none;">
                                    الأقدم
                                </a>
                            <?php else: ?>
                                <span class="btn-page disabled" style="padding:5px 12px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--ink-300);cursor:not-allowed;">
                                    الأقدم
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <nav class="ftabs" aria-label="فروع المشتريات">
            <a class="ftab <?= $branchFilter === null ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'purchases', 'branch' => 'all']))) ?>">الكل</a>
            <?php foreach ($purchaseBranches as $cb): ?>
                <a class="ftab <?= $branchFilter === $cb ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'purchases', 'branch' => $cb]))) ?>">
                    <?= e(act_company_branch_name($cb, $branchBySto)) ?> (<?= number_format($purchaseCounts[$cb][2] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </nav>
        <?php $purchaseNet = $purchaseTotals['sale_sum'] - $purchaseTotals['ret_sum']; $purchaseCount = $purchaseTotals['sale_cnt'] + $purchaseTotals['ret_cnt']; ?>
        <div class="fkpis">
            <?php foreach ([
                ['فواتير الشراء', number_format($purchaseTotals['sale_cnt'])],
                ['إجمالي الشراء', sens_mask(number_format($purchaseTotals['sale_sum'], 2))],
                ['عدد المردودات', number_format($purchaseTotals['ret_cnt'])],
                ['إجمالي المردودات', sens_mask(number_format($purchaseTotals['ret_sum'], 2))],
                ['الصافي', sens_mask(number_format($purchaseNet, 2))],
            ] as [$label, $value]): ?>
                <div class="activity-stat"><span class="activity-stat-label"><?= e($label) ?></span><span class="activity-stat-value"><?= $value ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="fcard timeline-panel">
            <div class="timeline-header"><h3 class="timeline-title">فواتير المشتريات والمردودات</h3><span class="timeline-meta">الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?></span></div>
            <div class="activity-table-wrap">
                <table class="ftable">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>رقم الفاتورة</th><th>المورد</th><?php if ($branchFilter === null): ?><th>الفرع</th><?php endif; ?><th class="num">الإجمالي</th><th class="num">الصافي</th></tr></thead>
                    <tbody>
                        <?php foreach ($purchaseRows as $r): ?>
                            <?php $cb = (int) $r['company_branch']; $dt = (int) $r['doc_type']; ?>
                            <tr><td><?= e((string) $r['event_time']) ?></td>
                                <td><span class="fchip <?= $dt === 2 ? 'fchip-pos' : 'fchip-warn' ?>"><?= $dt === 2 ? 'شراء' : 'مردود' ?></span></td>
                                <td><a class="activity-link" href="<?= e('invoices.php?invoice=' . (int) $r['sth_id'] . '&suffix=' . urlencode((string) $r['suffix']) . '&cb=' . $cb . '&dt=' . $dt) ?>"><?= e((string) $r['doc_no']) ?></a></td>
                                <td><?= e((string) ($r['supplier_name'] ?: 'غير محدد')) ?></td>
                                <?php if ($branchFilter === null): ?><td><?= e(act_company_branch_name($cb, $branchBySto)) ?></td><?php endif; ?>
                                <td class="num"><?= sens_mask(number_format((float) $r['total_amount'], 2)) ?></td>
                                <td class="num"><?= sens_mask(number_format((float) $r['net_amount'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$purchaseRows): ?><tr><td colspan="<?= $branchFilter === null ? 7 : 6 ?>">مفيش فواتير في الفترة دي.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><td colspan="<?= $branchFilter === null ? 5 : 4 ?>">الإجمالي: شراء <?= number_format($purchaseTotals['sale_cnt']) ?> (<?= sens_mask(number_format($purchaseTotals['sale_sum'], 2)) ?>) · مردود <?= number_format($purchaseTotals['ret_cnt']) ?> (<?= sens_mask(number_format($purchaseTotals['ret_sum'], 2)) ?>)</td><td class="num">—</td><td class="num"><?= sens_mask(number_format($purchaseNet, 2)) ?></td></tr></tfoot>
                </table>
            </div>
            <?php if ($purchaseCount > 500): ?><p class="timeline-meta">ظاهر أول ٥٠٠ من <?= number_format($purchaseCount) ?> — ضيّق الفترة</p><?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function toggleCustomDates(val) {
    var el = document.getElementById('customDatesWrap');
    if (el) el.style.display = (val === 'custom') ? 'flex' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
