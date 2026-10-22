<?php
/**
 * suppliers.php — الموردون
 * نظام الفجالة ستور المالي
 *
 * - كشوف حسابات الموردين (ملخص وتفصيلي)
 */
require_once __DIR__ . '/auth.php';
require_permission('suppliers');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = 'statement';

require_once __DIR__ . '/recon_lib.php';

// نقرأ رسالة الفلاش من السيشن (بعد التحويل إن وجدت)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== كشوف حسابات الموردين (تاب statement) =====
require_once __DIR__ . '/suppliers_lib.php';

$stStart = suppliers_get_start_date($pdo);
$params  = suppliers_parse_params($_GET, $stStart);
$from    = $params['from'];
$to      = $params['to'];
$selectedSupplierId = $params['selected_supplier_id'];
$currBranchKey      = $params['curr_branch_key'];
$currBranch         = $params['curr_branch'];

if (isset($_GET['supplier']) && !isset($_GET['tab'])) {
    $tab = 'statement';
}

$statementSummary   = [];
$totSumPurchases    = 0.0;
$totSumReturns      = 0.0;
$totSumPaid         = 0.0;
$totSumReceived     = 0.0;
$totSumBalance      = 0.0;
$singleSupplier     = null;
$allMoves           = [];
$priorBalance       = 0.0;
$priorPurchases     = 0.0;
$priorReturns       = 0.0;
$priorPaid          = 0.0;
$priorReceived      = 0.0;
$periodPurchases    = 0.0;
$periodReturns      = 0.0;
$periodPaid         = 0.0;
$periodReceived     = 0.0;
$finalBalance       = 0.0;

// ?party= القديم ← redirect لـ branch_recon.php بنفس الطرفين والتاريخ (بريف ٦٠)
if (isset($_GET['party']) && $_GET['party'] !== '') {
    $pFrom = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])) ? (string)$_GET['from'] : '2026-06-27';
    $pTo   = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])) ? (string)$_GET['to'] : date('Y-m-d');
    $pA    = $currBranchKey ?: 'fagala';
    $pB    = trim((string)$_GET['party']);
    header("Location: branch_recon.php?a=" . urlencode($pA) . "&b=" . urlencode($pB) . "&from=" . urlencode($pFrom) . "&to=" . urlencode($pTo));
    exit;
}

if ($tab === 'statement') {
    $allSuppList = suppliers_get_active_list($pdo);

    if ($selectedSupplierId <= 0) {
        $summaryData      = suppliers_get_summary($pdo, $from, $to, $stStart, $currBranch);
        $statementSummary = $summaryData['statement_summary'];
        $totSumPurchases  = $summaryData['totals']['purchases'];
        $totSumReturns    = $summaryData['totals']['returns'];
        $totSumPaid       = $summaryData['totals']['paid'];
        $totSumReceived   = $summaryData['totals']['received'];
        $totSumBalance    = $summaryData['totals']['balance'];

        // تصدير إكسيل للملخص
        if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
            require_once __DIR__ . '/xlsx_lib.php';
            $x = new SimpleXlsx('ملخص الموردين');
            $headerTitle = 'كشوف حسابات الموردين' . ($currBranch ? ' — فرع ' . $currBranch['label'] : '') . ' — من ' . $from . ' حتى ' . $to;
            $x->addRow([$headerTitle]);
            $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
            $x->addBlank();
            $x->addRow(['المورد', 'التليفون', 'المشتريات', 'المردودات', 'المصروف له', 'المستلم منه', 'الرصيد'], true);
            foreach ($statementSummary as $row) {
                $x->addRow([
                    $row['name'],
                    (string)$row['phone'],
                    round($row['purchases'], 2),
                    round($row['returns'], 2),
                    round($row['paid'], 2),
                    round($row['received'], 2),
                    round($row['balance'], 2),
                ]);
            }
            $x->addRow(['الإجمالي', '', round($totSumPurchases, 2), round($totSumReturns, 2), round($totSumPaid, 2), round($totSumReceived, 2), round($totSumBalance, 2)], true);

            $brSuffix = $currBranchKey ? '_' . strtoupper($currBranchKey) : '';
            $x->download('SUPPLIERS_SUMMARY' . $brSuffix . '_' . $from . '_' . $to . '.xlsx');
            exit;
        }
    } else {
        $stData = suppliers_get_statement($pdo, $selectedSupplierId, $from, $to, $stStart, $currBranch);
        if ($stData) {
            $singleSupplier  = $stData['supplier'];
            $priorBalance    = $stData['prior']['balance'];
            $priorPurchases  = $stData['prior']['purchases'];
            $priorReturns    = $stData['prior']['returns'];
            $priorPaid       = $stData['prior']['paid'];
            $priorReceived   = $stData['prior']['received'];
            $allMoves        = $stData['moves'];
            $periodPurchases = $stData['period']['purchases'];
            $periodReturns   = $stData['period']['returns'];
            $periodPaid      = $stData['period']['paid'];
            $periodReceived  = $stData['period']['received'];
            $finalBalance    = $stData['period']['final_balance'];

            // تصدير إكسيل لكشف مورد واحد
            if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
                require_once __DIR__ . '/xlsx_lib.php';
                $branchLabel = $currBranch ? ' (' . $currBranch['label'] . ')' : '';
                $sheetTitle = mb_substr('كشف ' . $singleSupplier['name'] . $branchLabel, 0, 31);
                $x = new SimpleXlsx($sheetTitle);
                $x->addRow(['كشف حساب مورد: ' . $singleSupplier['name'] . ($currBranch ? ' — فرع ' . $currBranch['label'] : '')]);
                $x->addRow(['الفترة', 'من ' . $from . ' إلى ' . $to]);
                $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
                $x->addBlank();
                $x->addRow(['التاريخ', 'البيان', 'الخزينة', 'له', 'عليه', 'الرصيد'], true);
                if (isset($_GET['from']) && $_GET['from'] !== '' && $from > $stStart) {
                    $x->addRow([$from, 'رصيد سابق', '—', 0, 0, round($priorBalance, 2)]);
                }
                foreach ($allMoves as $m) {
                    $x->addRow([
                        $m['tx_date'],
                        $m['description'] . (!empty($m['notes']) ? (' (' . $m['notes'] . ')') : ''),
                        $m['treasury_name'],
                        $m['credit'] > 0 ? round($m['credit'], 2) : '',
                        $m['debit'] > 0 ? round($m['debit'], 2) : '',
                        round($m['balance'], 2),
                    ]);
                }
                $x->addRow(['', 'الإجماليات', '', round($periodPurchases + $periodReceived, 2), round($periodReturns + $periodPaid, 2), round($finalBalance, 2)], true);
                $x->addBlank();
                $x->addRow(['المشتريات', round($periodPurchases, 2)]);
                $x->addRow(['المردودات', round($periodReturns, 2)]);
                $x->addRow(['المصروف له', round($periodPaid, 2)]);
                $x->addRow(['المستلم منه', round($periodReceived, 2)]);
                $x->addRow(['الرصيد الختامي', round($finalBalance, 2)], true);

                $safeName = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $singleSupplier['name']);
                $brSuffix = $currBranchKey ? '_' . strtoupper($currBranchKey) : '';
                $x->download('SUPPLIER_' . ($safeName ?: $selectedSupplierId) . $brSuffix . '_' . $from . '_' . $to . '.xlsx');
                exit;
            }
        }
    }
}

$page_title    = 'الموردون';
$active_module = 'suppliers';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab { padding:10px 20px; border-radius:10px; text-decoration:none; color:var(--accent-text); background:var(--surface);
        font-size:14px; font-weight:500; }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:460px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .pay-type-row { display:flex; gap:10px; }
    .pay-opt { flex:1; }
    .pay-opt input { display:none; }
    .pay-opt label { display:block; text-align:center; padding:11px; border:1.5px solid var(--line);
        border-radius:9px; cursor:pointer; font-size:14px; color:var(--ink-700); }
    .pay-opt input:checked + label { border-color:var(--accent-text); background:var(--bg); color:var(--accent-text); font-weight:600; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-top:20px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line); border-radius:12px; padding:14px 16px; }
    .sum-card .label { font-size:12px; color:var(--ink-500); margin-bottom:4px; font-weight:500; }
    .sum-card .val { font-size:18px; font-weight:700; color:var(--ink-900); }
    .filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filter-form label { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-700); font-weight:500; }
    .filter-form input[type="date"] { padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:var(--surface); color:var(--ink-900); }
    .reconcile-card { border:1.5px solid var(--line); border-radius:14px; background:var(--surface); padding:16px 20px; margin-bottom:18px; }
    .reconcile-row { cursor:pointer; transition:background 0.15s ease; }
    .reconcile-row:hover { background:var(--line-soft); }
    .sub-cnt { font-size:11px; color:var(--ink-500); display:block; margin-top:2px; font-weight:normal; }
    .diff-pill { display:inline-block; font-weight:700; direction:ltr; unicode-bidi:isolate; }
    .diff-match { color:var(--pos); font-weight:700; }
    .diff-mismatch { color:var(--neg); font-weight:700; }
    .dual-tables-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px; }
    @media (max-width: 900px) {
        .dual-tables-grid { grid-template-columns:1fr; }
    }

    /* تمييز وتلوين صفوف المطابقة (بريف ٥٩) */
    .recon-table tbody tr.hl-red    { background:var(--line-soft); box-shadow:inset -4px 0 0 var(--neg); }
    .recon-table tbody tr.hl-green  { background:var(--line-soft); box-shadow:inset -4px 0 0 var(--pos); }
    .recon-table tbody tr.hl-yellow { background:var(--warn-soft); box-shadow:inset -4px 0 0 var(--warn); }
    .recon-table tbody tr.hl-orange { background:var(--warn-soft); box-shadow:inset -4px 0 0 var(--warn); }
    .recon-table tbody tr.hl-red:hover    { background:var(--line-soft); }
    .recon-table tbody tr.hl-green:hover  { background:var(--line); }
    .recon-table tbody tr.hl-yellow:hover { background:var(--warn-soft); }
    .recon-table tbody tr.hl-orange:hover { background:var(--warn-soft); }

    /* شريط أدوات قلم التلوين */
    .pen-toolbar { display:inline-flex; align-items:center; gap:8px; background:var(--surface); border:1.5px solid var(--line); border-radius:10px; padding:4px 10px; }
    .pen-toggle-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1.5px solid var(--line); background:var(--line-soft); color:var(--ink-800); font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.15s ease; }
    .pen-toggle-btn:hover { background:var(--line); }
    .pen-toggle-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); }
    .color-dots { display:inline-flex; align-items:center; gap:6px; }
    .color-dot { width:22px; height:22px; border-radius:50%; border:2px solid transparent; cursor:pointer; transition:transform 0.1s ease, border-color 0.1s ease; padding:0; outline:none; }
    .color-dot:hover { transform:scale(1.15); }
    .color-dot.active { border-color:var(--ink-900); transform:scale(1.2); box-shadow:0 0 0 2px var(--surface); }
    .color-dot.dot-red    { background:var(--neg); }
    .color-dot.dot-green  { background:var(--pos); }
    .color-dot.dot-yellow { background:var(--warn); }
    .color-dot.dot-orange { background:#f76707; }
    .eraser-btn { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:6px; border:1.5px solid var(--line); background:var(--line-soft); color:var(--ink-600); cursor:pointer; font-size:13px; padding:0; transition:all 0.15s ease; }
    .eraser-btn:hover { background:var(--line); color:var(--ink-900); }
    .eraser-btn.active { background:var(--ink-800); color:#fff; border-color:var(--ink-800); }

    /* مؤشر القلم العائم */
    .recon-cursor-dot { position:fixed; width:14px; height:14px; border-radius:50%; pointer-events:none; z-index:99999; display:none; transform:translate(-50%, -50%); box-shadow:0 0 6px rgba(0,0,0,0.35); border:2px solid #fff; }
    body.pen-active .recon-table tbody tr { cursor:crosshair !important; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

    <?php if ($selectedSupplierId <= 0 || !$singleSupplier): ?>
        <!-- تابات الفروع -->
        <div class="tabs">
            <a href="?tab=statement&br=&from=<?= e($from) ?>&to=<?= e($to) ?>" class="tab <?= $currBranchKey === '' ? 'active' : '' ?>">الكل</a>
            <?php foreach (SUPP_BRANCHES as $k => $b): ?>
                <a href="?tab=statement&br=<?= e($k) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="tab <?= $currBranchKey === $k ? 'active' : '' ?>"><?= e($b['label']) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- (أ) الملخص: كل الموردين -->
        <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
            <div>
                <span class="section-title" style="margin-bottom:0;">كشوف حسابات الموردين<?= $currBranch ? ' — فرع ' . e($currBranch['label']) : '' ?></span>
                <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                    الفترة من <?= e($from) ?> حتى <?= e($to) ?> (رصيد ما بعد جرد ٢٧ يونيو)
                </div>
            </div>
            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="tab" value="statement">
                <input type="hidden" name="br" value="<?= e($currBranchKey) ?>">
                <label>كشف مورد
                    <select name="supplier" data-searchable="true" data-placeholder="بحث في الموردين..." data-empty="مفيش مورد بالاسم ده" onchange="if(this.value && this.value!='0') this.form.submit()" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);">
                        <option value="0">اختر مورد...</option>
                        <?php foreach ($allSuppList as $sp): ?>
                            <option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
                <a href="?tab=statement&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>

        <div class="sum-cards" style="margin-top:0;margin-bottom:18px;">
            <div class="sum-card">
                <div class="label">عدد الموردين</div>
                <div class="val" id="cardSuppCount"><?= count($statementSummary) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المشتريات</div>
                <div class="val" id="cardTotPurchases"><?= number_format($totSumPurchases, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المردودات</div>
                <div class="val" id="cardTotReturns"><?= number_format($totSumReturns, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المصروف للموردين</div>
                <div class="val" id="cardTotPaid"><?= number_format($totSumPaid, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المستلم من الموردين</div>
                <div class="val" id="cardTotReceived"><?= number_format($totSumReceived, 2) ?></div>
            </div>
            <div class="sum-card" id="cardBalanceBox" style="background:<?= $totSumBalance > 0 ? 'var(--neg-soft)' : ($totSumBalance < 0 ? 'var(--pos-soft)' : 'var(--line-soft)') ?>;border-color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--line)') ?>;">
                <div class="label">إجمالي الرصيد القائم</div>
                <div class="val" id="cardTotBalance" style="color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--ink-900)') ?>;">
                    <?= number_format($totSumBalance, 2) ?>
                </div>
                <small id="cardBalanceHint" style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?= $totSumBalance > 0 ? 'احنا مديونين للموردين' : ($totSumBalance < 0 ? 'الموردين مدينين لنا' : 'الأرصدة متوازنة') ?>
                </small>
            </div>
        </div>

        <?php if ($currBranch !== null && can('branch_recon')): ?>
        <div style="margin-bottom:16px;font-size:13.5px;color:var(--ink-700);background:var(--surface);border:1.5px solid var(--line);border-radius:10px;padding:11px 16px;display:flex;align-items:center;gap:10px;">
            <i class="ti ti-transfer" style="color:var(--accent-text);font-size:16px;"></i>
            <span>مطابقة البضاعة بين الإدارة والفروع ←</span>
            <a href="branch_recon.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="inv-link">صفحة مطابقة الفروع</a>
        </div>
        <?php endif; ?>

        <div class="card">
            <?php if (empty($statementSummary)): ?>
                <div class="empty">لا يوجد موردون بحركات أو أرصدة في هذه الفترة.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table id="suppTable">
                <thead>
                    <tr>
                        <th>المورد</th>
                        <th>المشتريات</th>
                        <th>المردودات</th>
                        <th>المصروف له</th>
                        <th>المستلم منه</th>
                        <th>الرصيد</th>
                    </tr>
                </thead>
                <tbody id="suppTbody">
                <?php foreach ($statementSummary as $s): ?>
                    <tr data-name="<?= e(mb_strtolower($s['name'])) ?>"
                        data-p="<?= $s['purchases'] ?>"
                        data-r="<?= $s['returns'] ?>"
                        data-pd="<?= $s['paid'] ?>"
                        data-rc="<?= $s['received'] ?>"
                        data-b="<?= $s['balance'] ?>">
                        <td style="font-weight:600;">
                            <a href="?tab=statement&supplier=<?= (int)$s['id'] ?>&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="inv-link">
                                <?= e($s['name']) ?>
                            </a>
                        </td>
                        <td><?= $s['purchases'] > 0 ? number_format($s['purchases'], 2) : '—' ?></td>
                        <td><?= $s['returns'] > 0 ? number_format($s['returns'], 2) : '—' ?></td>
                        <td><?= $s['paid'] > 0 ? number_format($s['paid'], 2) : '—' ?></td>
                        <td><?= $s['received'] > 0 ? number_format($s['received'], 2) : '—' ?></td>
                        <td style="font-weight:700;color:<?= $s['balance'] > 0 ? 'var(--neg)' : ($s['balance'] < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($s['balance'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        /* الكروت بتتحسب من الصفوف الظاهرة في فلتر الجدول العام (xtable.js بيبعت
           xtable:filter بالصفوف الظاهرة بعد أي بحث/فلتر/مسح) — نفس اللي الجدول بيعرضه بالظبط. */
        (function () {
            var table = document.getElementById('suppTable');
            if (!table) return;
            var $ = function (id) { return document.getElementById(id); };
            var cardBBox = $('cardBalanceBox'), cardB = $('cardTotBalance'), cardBHint = $('cardBalanceHint');

            function fmt(n) {
                return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            table.addEventListener('xtable:filter', function (ev) {
                var rows = (ev.detail && ev.detail.rows) || [];
                var sP = 0, sR = 0, sPd = 0, sRc = 0, sB = 0, n = 0;
                rows.forEach(function (r) {
                    if (!r.hasAttribute('data-p')) return;
                    n++;
                    sP  += parseFloat(r.getAttribute('data-p'))  || 0;
                    sR  += parseFloat(r.getAttribute('data-r'))  || 0;
                    sPd += parseFloat(r.getAttribute('data-pd')) || 0;
                    sRc += parseFloat(r.getAttribute('data-rc')) || 0;
                    sB  += parseFloat(r.getAttribute('data-b'))  || 0;
                });
                $('cardSuppCount').textContent    = n.toLocaleString('en-US');
                $('cardTotPurchases').textContent = fmt(sP);
                $('cardTotReturns').textContent   = fmt(sR);
                $('cardTotPaid').textContent      = fmt(sPd);
                $('cardTotReceived').textContent  = fmt(sRc);
                cardB.textContent = fmt(sB);
                var tone = sB > 0.005 ? 'neg' : (sB < -0.005 ? 'pos' : '');
                cardBBox.style.background  = tone ? 'var(--' + tone + '-soft)' : 'var(--line-soft)';
                cardBBox.style.borderColor = tone ? 'var(--' + tone + ')' : 'var(--line)';
                cardB.style.color          = tone ? 'var(--' + tone + ')' : 'var(--ink-900)';
                cardBHint.textContent = tone === 'neg' ? 'احنا مديونين للموردين'
                                      : (tone === 'pos' ? 'الموردين مدينين لنا' : 'الأرصدة متوازنة');
            });
        })();
        </script>

    <?php else: ?>
        <!-- (ب) كشف حساب مورد واحد -->
        <div class="toolbar" style="align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div>
                <a href="?tab=statement&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-ghost" style="margin-bottom:8px;">
                    <i class="ti ti-arrow-right"></i> كل الموردين
                </a>
                <div class="section-title" style="margin-bottom:2px;font-size:18px;">
                    <?= e($singleSupplier['name']) ?><?= $currBranch ? ' — <span style="font-size:14px;color:var(--accent-text);font-weight:500;">فرع: ' . e($currBranch['label']) . '</span>' : '' ?>
                </div>
                <div style="font-size:12px;color:var(--ink-500);">
                    <?= !empty($singleSupplier['phone']) ? ('تليفون: ' . e($singleSupplier['phone'])) : '' ?>
                </div>
            </div>

            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="tab" value="statement">
                <input type="hidden" name="br" value="<?= e($currBranchKey) ?>">
                <label>تغيير المورد
                    <select name="supplier" data-searchable="true" data-placeholder="بحث في الموردين..." data-empty="مفيش مورد بالاسم ده" onchange="this.form.submit()" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);">
                        <?php foreach ($allSuppList as $sp): ?>
                            <option value="<?= (int)$sp['id'] ?>" <?= $selectedSupplierId === (int)$sp['id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>"></label>
                <button type="submit" class="btn btn-sm">تطبيق</button>
                <a href="?tab=statement&supplier=<?= $selectedSupplierId ?>&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>البيان</th>
                        <th>الخزينة</th>
                        <th>له</th>
                        <th>عليه</th>
                        <th>الرصيد</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (isset($_GET['from']) && $_GET['from'] !== '' && $from > $stStart): ?>
                    <tr style="background:var(--line-soft);font-weight:600;">
                        <td><?= e($from) ?></td>
                        <td colspan="2">رصيد سابق</td>
                        <td>0.00</td>
                        <td>0.00</td>
                        <td style="color:<?= $priorBalance > 0 ? 'var(--neg)' : ($priorBalance < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($priorBalance, 2) ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php if (empty($allMoves) && $from <= $stStart): ?>
                    <tr><td colspan="6" class="empty">لا توجد حركات لهذا المورد في هذه الفترة.</td></tr>
                <?php else: ?>
                    <?php foreach ($allMoves as $m): ?>
                        <tr>
                            <td><?= e($m['tx_date']) ?></td>
                            <td>
                                <?= e($m['description']) ?>
                                <?php if (!empty($m['notes'])): ?>
                                    <div style="font-size:12px;color:var(--ink-500);margin-top:2px;"><?= e($m['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($m['treasury_name']) ?></td>
                            <td style="font-weight:500;color:<?= $m['credit'] > 0 ? 'var(--pos)' : '' ?>;">
                                <?= $m['credit'] > 0 ? number_format($m['credit'], 2) : '—' ?>
                            </td>
                            <td style="font-weight:500;color:<?= $m['debit'] > 0 ? 'var(--neg)' : '' ?>;">
                                <?= $m['debit'] > 0 ? number_format($m['debit'], 2) : '—' ?>
                            </td>
                            <td style="font-weight:700;color:<?= $m['balance'] > 0 ? 'var(--neg)' : ($m['balance'] < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                                <?= number_format($m['balance'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                    <tr style="background:var(--line-soft);font-weight:700;">
                        <td colspan="3">الإجماليات</td>
                        <td><?= number_format($periodPurchases + $periodReceived, 2) ?></td>
                        <td><?= number_format($periodReturns + $periodPaid, 2) ?></td>
                        <td><?= number_format($finalBalance, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="sum-cards">
            <div class="sum-card">
                <div class="label">المشتريات</div>
                <div class="val"><?= number_format($periodPurchases, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المردودات</div>
                <div class="val"><?= number_format($periodReturns, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المصروف له</div>
                <div class="val"><?= number_format($periodPaid, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المستلم منه</div>
                <div class="val"><?= number_format($periodReceived, 2) ?></div>
            </div>
            <div class="sum-card" style="background:<?= $finalBalance > 0 ? 'var(--neg-soft)' : ($finalBalance < 0 ? 'var(--pos-soft)' : 'var(--line-soft)') ?>;border-color:<?= $finalBalance > 0 ? 'var(--neg)' : ($finalBalance < 0 ? 'var(--pos)' : 'var(--line)') ?>;">
                <div class="label">الرصيد الختامي</div>
                <div class="val" style="color:<?= $finalBalance > 0 ? 'var(--neg)' : ($finalBalance < 0 ? 'var(--pos)' : 'var(--ink-900)') ?>;">
                    <?= number_format($finalBalance, 2) ?>
                </div>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?= $finalBalance > 0 ? 'احنا مديونين له' : ($finalBalance < 0 ? 'هو مدين لنا' : 'خالص') ?>
                </small>
            </div>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
