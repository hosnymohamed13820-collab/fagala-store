<?php
/**
 * branch_recon.php — مطابقة الفروع والإدارة
 * نظام الفجالة ستور المالي
 *
 * موديول مستقل بصلاحية branch_recon لمطابقة حركة البضاعة
 * بين الإدارة وجميع فروع الشركة (الأزواج العشرة).
 */

require_once __DIR__ . '/auth.php';
require_permission('branch_recon');
require_once __DIR__ . '/recon_lib.php';
require_once __DIR__ . '/item_flags_lib.php';
require_once __DIR__ . '/recon_alerts_lib.php';
require_once __DIR__ . '/branch_recon_lib.php';

const RECON_ALERTS_START = '2026-10-01'; // كل الأزواج اتقفلت ٣٠/٩ ما عدا الإدارة↔بونجور

$pdo  = db();
$uid  = (int) current_user()['id'];

// AJAX: حفظ أو مسح علامة التلوين على صف الفاتورة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recon_mark') {
    header('Content-Type: application/json; charset=utf-8');
    require_permission('branch_recon');
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني']);
        exit;
    }

    $sthId  = isset($_POST['sth_id']) ? (int) $_POST['sth_id'] : 0;
    $suffix = isset($_POST['suffix']) ? trim((string) $_POST['suffix']) : '';
    $color  = isset($_POST['color']) ? trim((string) $_POST['color']) : '';

    if ($sthId <= 0 || $suffix === '') {
        echo json_encode(['ok' => false, 'error' => 'بيانات الفاتورة غير صحيحة']);
        exit;
    }

    try {
        if ($color === '') {
            $del = $pdo->prepare('DELETE FROM recon_marks WHERE sth_id = ? AND suffix = ?');
            $del->execute([$sthId, $suffix]);
            echo json_encode(['ok' => true, 'action' => 'deleted']);
            exit;
        }

        if (!in_array($color, IFL_COLORS, true)) {
            echo json_encode(['ok' => false, 'error' => 'اللون غير صالح']);
            exit;
        }

        $ins = $pdo->prepare(
            'INSERT INTO recon_marks (sth_id, suffix, color, marked_by, marked_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE color = VALUES(color), marked_by = VALUES(marked_by), marked_at = NOW()'
        );
        $ins->execute([$sthId, $suffix, $color, $uid]);
        echo json_encode(['ok' => true, 'action' => 'saved', 'color' => $color]);
        exit;
    } catch (PDOException $e) {
        $errMsg = 'شغّل local/recon_marks.sql الأول';
        if ($e->getCode() != '42S02' && strpos($e->getMessage(), "doesn't exist") === false) {
            $errMsg = 'تعذّر الحفظ: ' . $e->getMessage();
        }
        echo json_encode(['ok' => false, 'error' => $errMsg]);
        exit;
    }
}

// قراءة فترة البداية والمدى الزمني
$stStart   = branch_recon_get_start_date($pdo);
$activeTab = trim((string)($_GET['tab'] ?? ''));
$fromParam = isset($_GET['from']) ? (string)$_GET['from'] : null;
$toParam   = isset($_GET['to']) ? (string)$_GET['to'] : null;
$dateRange = branch_recon_get_date_range($activeTab, $fromParam, $toParam, $stStart);
$from      = $dateRange['from'];
$to        = $dateRange['to'];

$allParties = recon_all_parties();
$aKey = trim((string)($_GET['a'] ?? ''));
$bKey = trim((string)($_GET['b'] ?? ''));

$isDetailView = false;
$partyA = null;
$partyB = null;

if ($aKey !== '' && $bKey !== '' && $aKey !== $bKey && isset($allParties[$aKey], $allParties[$bKey])) {
    $isDetailView = true;
    $partyA = $allParties[$aKey];
    $partyB = $allParties[$bKey];
}

// -------------------------------------------------------------
// الشاشة التفصيلية: كشف مقارنة الزوج (party moves)
// -------------------------------------------------------------
if ($isDetailView) {
    $detailData = branch_recon_get_detail_data($pdo, $from, $to, $aKey, $bKey, $allParties);
    $partyMovesOur  = $detailData['movesA'];
    $partyMovesHis  = $detailData['movesB'];
    $partyTotOurBuy = $detailData['totA_buy'];
    $partyTotOurRet = $detailData['totA_ret'];
    $partyTotHisBuy = $detailData['totB_buy'];
    $partyTotHisRet = $detailData['totB_ret'];
    $partyNetOur    = $detailData['netA'];
    $partyNetHis    = $detailData['netB'];
    $partyDiff      = $detailData['diff'];
    $partyIsMatch   = $detailData['is_match'];
    $reconMarks     = $detailData['recon_marks'];

    // تصدير إكسيل لكشف المقارنة
    if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
        require_once __DIR__ . '/xlsx_lib.php';
        $sheetTitle = mb_substr('مطابقة ' . $partyA['label'] . ' و ' . $partyB['label'], 0, 31);
        $x = new SimpleXlsx($sheetTitle);
        $headerTitle = 'كشف مقارنة مطابقة: ' . $partyA['label'] . ' ⟷ ' . $partyB['label'] . ' — من ' . $from . ' حتى ' . $to;
        $x->addRow([$headerTitle]);
        $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
        $x->addBlank();

        // قسم ١: دفاتر الطرف A
        $x->addRow(['أولاً: دفاتر ' . $partyA['label']], true);
        $x->addRow(['التاريخ', 'رقم المستند', 'نوع الحركة', 'المبلغ'], true);
        foreach ($partyMovesOur as $m) {
            $x->addRow([
                $m['tx_date'],
                (string)$m['doc_no'],
                ((int)$m['doc_type'] === 2 ? 'شراء' : 'مردود'),
                round((float)$m['net_amount'], 2),
            ]);
        }
        $x->addRow(['إجمالي الشراء', '', '', round($partyTotOurBuy, 2)], true);
        $x->addRow(['إجمالي المردود', '', '', round($partyTotOurRet, 2)], true);
        $x->addBlank();

        // قسم ٢: دفاتر الطرف B
        $x->addRow(['ثانياً: دفاتر ' . $partyB['label']], true);
        $x->addRow(['التاريخ', 'رقم المستند', 'نوع الحركة', 'المبلغ'], true);
        foreach ($partyMovesHis as $m) {
            $x->addRow([
                $m['tx_date'],
                (string)$m['doc_no'],
                ((int)$m['doc_type'] === 2 ? 'شراء' : 'مردود'),
                round((float)$m['net_amount'], 2),
            ]);
        }
        $x->addRow(['إجمالي الشراء', '', '', round($partyTotHisBuy, 2)], true);
        $x->addRow(['إجمالي المردود', '', '', round($partyTotHisRet, 2)], true);
        $x->addBlank();

        // قسم ٣: صافي المطابقة
        $x->addRow(['ثالثاً: صافي المطابقة'], true);
        $x->addRow(['وصلنا منه — صافي (دفاتر ' . $partyA['label'] . ')', '', '', round($partyNetOur, 2)], true);
        $x->addRow(['بعتهولنا — صافي (دفاتر ' . $partyB['label'] . ')', '', '', round($partyNetHis, 2)], true);
        $x->addRow(['الفرق (دفاترنا − دفاتره)', '', '', round($partyDiff, 2)], true);

        $x->download('BRANCH_RECON_' . strtoupper($aKey) . '_' . strtoupper($bKey) . '_' . $from . '_' . $to . '.xlsx');
        exit;
    }
} else {
    if ($activeTab === 'alerts') {
        // ---------------------------------------------------------
        // تاب التنبيهات: اكتشاف الأخطاء والتكرار والمطابقة
        // ---------------------------------------------------------
        $pFilter  = trim((string)($_GET['p'] ?? ''));
        $showDone = !empty($_GET['show_done']);
        $alertsData = branch_recon_get_alerts_data($pdo, $from, $to, $allParties, $pFilter, $showDone);
        $alerts         = $alertsData['alerts'];
        $doneCount      = $alertsData['done_count'];
        $redCount       = $alertsData['red_count'];
        $redAmt         = $alertsData['red_amt'];
        $yellowCount    = $alertsData['yellow_count'];
        $yellowAmt      = $alertsData['yellow_amt'];
        $grayCount      = $alertsData['gray_count'];
        $grayAmt        = $alertsData['gray_amt'];
        $alertsPerfTime = $alertsData['perf_time'];
        $reconMarks     = $alertsData['recon_marks'];
    } elseif ($activeTab === 'ashraqa') {
        // ---------------------------------------------------------
        // تاب مشتريات إشراقة: قائمة الفواتير وتفاصيل السطور
        // ---------------------------------------------------------
        $docParam = trim((string)($_GET['doc'] ?? ''));
        $ashraqaDetailMode = false;
        $ashDoc = null;
        $ashLines = [];
        $ashCodesMap = [];
        $ashDocNotFound = false;

        if ($docParam !== '') {
            $ashraqaDetailMode = true;
            $ashDetail = branch_recon_get_ashraqa_detail($pdo, $docParam);
            if (!$ashDetail) {
                $ashDocNotFound = true;
            } else {
                $ashDoc      = $ashDetail['doc'];
                $ashLines    = $ashDetail['lines'];
                $ashCodesMap = $ashDetail['codes_map'];
            }
        } else {
            $ashSstt          = isset($_GET['sstt']) ? (int)$_GET['sstt'] : 0;
            $ashSupplier      = isset($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
            $ashSearch        = trim((string)($_GET['q'] ?? ''));
            $ashShowCancelled = !empty($_GET['show_cancelled']);
            $ashListData      = branch_recon_get_ashraqa_list($pdo, $from, $to, $ashSstt, $ashSupplier, $ashSearch, $ashShowCancelled);
            $ashSuppliers     = $ashListData['suppliers'];
            $ashInvoices      = $ashListData['invoices'];
            $totAshBuy        = $ashListData['tot_buy'];
            $totAshRet        = $ashListData['tot_ret'];
        }
    } else {
        // -------------------------------------------------------------
        // الشاشة العامة: جدول الأزواج العشرة (الفروق)
        // -------------------------------------------------------------
        $pairsData     = branch_recon_get_pairs_data($pdo, $from, $to);
        $matrix        = $pairsData['matrix'];
        $pairs         = $pairsData['pairs'];
        $totPairsCount = $pairsData['total_pairs'];
        $matchCount    = $pairsData['match_count'];
        $mismatchCount = $pairsData['mismatch_count'];

        // تصدير إكسيل لجدول الأزواج
        if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
            require_once __DIR__ . '/xlsx_lib.php';
            $x = new SimpleXlsx('مطابقة الفروع');
            $headerTitle = 'مطابقة بضاعة الفروع والإدارة — من ' . $from . ' حتى ' . $to;
            $x->addRow([$headerTitle]);
            $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
            $x->addBlank();
            $x->addRow(['من', 'إلى', 'صافي اللي خرج (دفاتر من)', 'صافي اللي وصل (دفاتر إلى)', 'الفرق', 'الحالة'], true);

            foreach ($pairs as $p) {
                $statusText = $p['is_match'] ? 'متطابق' : ($p['diff'] > 0 ? ('دفاتر ' . $p['from_label'] . ' فيها أكتر') : ('دفاتر ' . $p['to_label'] . ' فيها أكتر'));
                $x->addRow([
                    $p['from_label'],
                    $p['to_label'],
                    round($p['net_sent'], 2),
                    round($p['net_recv'], 2),
                    round($p['diff'], 2),
                    $statusText,
                ]);
            }
            $x->download('BRANCHES_RECONCILIATION_' . $from . '_' . $to . '.xlsx');
            exit;
        }
    }
}

$page_title    = 'مطابقة الفروع';
$active_module = 'branch_recon';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filter-form label { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-700); font-weight:500; }
    .sub-cnt { font-size:11px; color:var(--ink-500); display:block; margin-top:2px; font-weight:normal; }
    .diff-pill { display:inline-block; font-weight:700; direction:ltr; unicode-bidi:isolate; }
    .diff-match { color:var(--pos); font-weight:700; }
    .diff-mismatch { color:var(--neg); font-weight:700; }
    .recon-row { cursor:pointer; transition:background 0.15s ease; }
    .recon-row:hover { background:var(--line-soft); }
    .dual-tables-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px; }
    @media (max-width: 900px) {
        .dual-tables-grid { grid-template-columns:1fr; }
    }

    /* تمييز وتلوين صفوف المطابقة (بريف ٥٩ و ٦٠) */
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
    .pen-toggle-btn.active { background:var(--accent); color:var(--fill-strong-fg); border-color:var(--accent); }
    .color-dots { display:inline-flex; align-items:center; gap:6px; }
    .color-dot { width:22px; height:22px; border-radius:50%; border:2px solid transparent; cursor:pointer; transition:transform 0.1s ease, border-color 0.1s ease; padding:0; outline:none; }
    .color-dot:hover { transform:scale(1.15); }
    .color-dot.active { border-color:var(--ink-900); transform:scale(1.2); box-shadow:0 0 0 2px var(--surface); }
    .color-dot.dot-red    { background:var(--neg); }
    .color-dot.dot-green  { background:var(--pos); }
    .color-dot.dot-yellow { background:var(--warn); }
    .color-dot.dot-orange { background:var(--warn); }
    .eraser-btn { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:6px; border:1.5px solid var(--line); background:var(--line-soft); color:var(--ink-600); cursor:pointer; font-size:13px; padding:0; transition:all 0.15s ease; }
    .eraser-btn:hover { background:var(--line); color:var(--ink-900); }
    .eraser-btn.active { background:var(--ink-800); color:var(--fill-strong-fg); border-color:var(--ink-800); }

    /* مؤشر القلم العائم */
    .recon-cursor-dot { position:fixed; width:14px; height:14px; border-radius:50%; pointer-events:none; z-index:99999; display:none; transform:translate(-50%, -50%); box-shadow:0 0 6px rgba(0,0,0,0.35); border:2px solid var(--surface); }
    body.pen-active .recon-table tbody tr { cursor:crosshair !important; }
</style>

<?php if ($isDetailView): ?>
    <!-- ========================================================= -->
    <!-- كشف مقارنة الطرفين التفصيلي (بريف ٥٩ و ٦٠)               -->
    <!-- ========================================================= -->
    <div class="toolbar" style="align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <a href="branch_recon.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-ghost" style="margin-bottom:8px;">
                <i class="ti ti-arrow-right"></i> رجوع لجدول المطابقة الرئيسي
            </a>
            <div class="section-title" style="margin-bottom:2px;font-size:18px;">
                مطابقة: <?= e($partyA['label']) ?> ⟷ <?= e($partyB['label']) ?>
            </div>
            <div style="font-size:13px;color:var(--ink-500);margin-top:2px;">
                الفترة من <?= e($from) ?> حتى <?= e($to) ?>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <!-- قلم التلوين -->
            <div class="pen-toolbar" id="reconPenToolbar">
                <button type="button" class="pen-toggle-btn" id="reconPenToggle" title="تفعيل/تعطيل وضع التلوين (Esc للخروج)">
                    <i class="ti ti-pencil"></i> قلم التلوين
                </button>
                <div class="color-dots">
                    <button type="button" class="color-dot dot-yellow active" data-color="yellow" title="أصفر"></button>
                    <button type="button" class="color-dot dot-green" data-color="green" title="أخضر"></button>
                    <button type="button" class="color-dot dot-red" data-color="red" title="أحمر"></button>
                    <button type="button" class="color-dot dot-orange" data-color="orange" title="برتقالي"></button>
                </div>
                <button type="button" class="eraser-btn" id="reconEraserBtn" title="ممحاة (مسح التلوين)">
                    <i class="ti ti-x"></i>
                </button>
            </div>

            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="a" value="<?= e($aKey) ?>">
                <input type="hidden" name="b" value="<?= e($bKey) ?>">
                <label>من <input type="date" class="finput" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
                <label>إلى <input type="date" class="finput" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
                <a href="branch_recon.php?a=<?= e($aKey) ?>&b=<?= e($bKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>
    </div>

    <div class="recon-cursor-dot" id="reconCursorDot"></div>

    <div class="dual-tables-grid">
        <!-- جدول الطرف A -->
        <div class="fcard" style="margin-bottom:0;">
            <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--line);">
                دفاتر <?= e($partyA['label']) ?>
            </div>
            <?php if (empty($partyMovesOur)): ?>
                <div class="empty">لا توجد فواتير مسجلة في هذه الفترة.</div>
            <?php else: ?>
            <div class="ftable-wrap">
            <table class="ftable recon-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>رقم المستند</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($partyMovesOur as $m): ?>
                    <?php
                    $mkKey = $m['sth_id'] . '_' . $m['suffix'];
                    $mColor = $reconMarks[$mkKey] ?? '';
                    $rowClass = $mColor !== '' ? 'hl-' . $mColor : '';
                    ?>
                    <tr data-sth-id="<?= (int)$m['sth_id'] ?>" data-suffix="<?= e($m['suffix']) ?>" class="<?= $rowClass ?>">
                        <td><?= e($m['tx_date']) ?></td>
                        <td style="font-weight:600;"><?= e($m['doc_no']) ?></td>
                        <td><?= (int)$m['doc_type'] === 2 ? 'شراء' : 'مردود' ?></td>
                        <td style="font-weight:600;"><?= number_format((float)$m['net_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <div class="fkpis" style="margin-top:16px;">
                <div class="fkpi">
                    <span>إجمالي الشراء</span>
                    <strong><?= number_format($partyTotOurBuy, 2) ?></strong>
                </div>
                <div class="fkpi">
                    <span>إجمالي المردود</span>
                    <strong><?= number_format($partyTotOurRet, 2) ?></strong>
                </div>
            </div>
        </div>

        <!-- جدول الطرف B -->
        <div class="fcard" style="margin-bottom:0;">
            <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--line);">
                دفاتر <?= e($partyB['label']) ?>
            </div>
            <?php if (empty($partyMovesHis)): ?>
                <div class="empty">لا توجد فواتير مسجلة في هذه الفترة.</div>
            <?php else: ?>
            <div class="ftable-wrap">
            <table class="ftable recon-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>رقم المستند</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($partyMovesHis as $m): ?>
                    <?php
                    $mkKey = $m['sth_id'] . '_' . $m['suffix'];
                    $mColor = $reconMarks[$mkKey] ?? '';
                    $rowClass = $mColor !== '' ? 'hl-' . $mColor : '';
                    ?>
                    <tr data-sth-id="<?= (int)$m['sth_id'] ?>" data-suffix="<?= e($m['suffix']) ?>" class="<?= $rowClass ?>">
                        <td><?= e($m['tx_date']) ?></td>
                        <td style="font-weight:600;"><?= e($m['doc_no']) ?></td>
                        <td><?= (int)$m['doc_type'] === 2 ? 'شراء' : 'مردود' ?></td>
                        <td style="font-weight:600;"><?= number_format((float)$m['net_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <div class="fkpis" style="margin-top:16px;">
                <div class="fkpi">
                    <span>إجمالي الشراء</span>
                    <strong><?= number_format($partyTotHisBuy, 2) ?></strong>
                </div>
                <div class="fkpi">
                    <span>إجمالي المردود</span>
                    <strong><?= number_format($partyTotHisRet, 2) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- كروت صافي المطابقة -->
    <div class="fcard" style="margin-top:16px;">
        <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;">
            صافي المطابقة بين الطرفين
        </div>
        <div class="fkpis" style="margin-top:0;">
            <div class="fkpi">
                <span>وصلنا منه — صافي (دفاتر <?= e($partyA['label']) ?>)</span>
                <strong style="direction:ltr;text-align:right;"><?= number_format($partyNetOur, 2) ?></strong>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    شراء <?= number_format($partyTotOurBuy, 2) ?> − مردود <?= number_format($partyTotOurRet, 2) ?>
                </small>
            </div>
            <div class="fkpi">
                <span>بعتهولنا — صافي (دفاتر <?= e($partyB['label']) ?>)</span>
                <strong style="direction:ltr;text-align:right;"><?= number_format($partyNetHis, 2) ?></strong>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    شراء <?= number_format($partyTotHisRet, 2) ?> − مردود <?= number_format($partyTotHisBuy, 2) ?>
                </small>
            </div>
            <div class="fkpi" style="background:<?= $partyIsMatch ? 'var(--pos-soft)' : 'var(--neg-soft)' ?>;border-color:<?= $partyIsMatch ? 'var(--pos)' : 'var(--neg)' ?>;">
                <span>الفرق (دفاتر <?= e($partyA['label']) ?> − دفاتر <?= e($partyB['label']) ?>)</span>
                <strong style="color:<?= $partyIsMatch ? 'var(--pos)' : 'var(--neg)' ?>;direction:ltr;text-align:right;">
                    <?= $partyIsMatch ? '✓ متطابق' : number_format($partyDiff, 2) ?>
                </strong>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?php if ($partyIsMatch): ?>
                        الطرفين مسجّلين نفس الرصيد الصافي
                    <?php else: ?>
                        <?= $partyDiff > 0 ? ('دفاتر ' . e($partyA['label']) . ' فيها أكتر') : ('دفاتر ' . e($partyB['label']) . ' فيها أكتر') ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var colors = ['yellow', 'green', 'red', 'orange'];
        var colorHexes = {
            yellow: 'var(--warn)',
            green: 'var(--pos)',
            red: 'var(--neg)',
            orange: '#f76707'
        };
        var curColorIdx = 0;
        var isEraser = false;
        var penActive = false;

        var penBtn    = document.getElementById('reconPenToggle');
        var eraserBtn = document.getElementById('reconEraserBtn');
        var dotEls    = document.querySelectorAll('.color-dot');
        var cursorDot = document.getElementById('reconCursorDot');
        var csrfToken = '<?= csrf_token() ?>';

        function updateCursorColor() {
            if (!cursorDot) return;
            if (isEraser) {
                cursorDot.style.background = '#333';
                cursorDot.style.borderColor = '#fff';
            } else {
                var c = colors[curColorIdx];
                cursorDot.style.background = colorHexes[c] || '#f59f00';
                cursorDot.style.borderColor = '#fff';
            }
        }

        function setPenActive(active) {
            penActive = Boolean(active);
            if (penBtn) penBtn.classList.toggle('active', penActive);
            document.body.classList.toggle('pen-active', penActive);
            if (cursorDot) cursorDot.style.display = penActive ? 'block' : 'none';
        }

        function selectColorIndex(idx, activatePen) {
            curColorIdx = (idx + colors.length) % colors.length;
            isEraser = false;
            if (eraserBtn) eraserBtn.classList.remove('active');
            dotEls.forEach(function(dot) {
                dot.classList.toggle('active', dot.getAttribute('data-color') === colors[curColorIdx]);
            });
            updateCursorColor();
            if (activatePen) setPenActive(true);
        }

        function selectEraser(activatePen) {
            isEraser = true;
            if (eraserBtn) eraserBtn.classList.add('active');
            dotEls.forEach(function(dot) { dot.classList.remove('active'); });
            updateCursorColor();
            if (activatePen) setPenActive(true);
        }

        if (penBtn) {
            penBtn.addEventListener('click', function() {
                setPenActive(!penActive);
            });
        }

        dotEls.forEach(function(dot) {
            dot.addEventListener('click', function() {
                var c = this.getAttribute('data-color');
                var idx = colors.indexOf(c);
                if (idx !== -1) selectColorIndex(idx, true);
            });
        });

        if (eraserBtn) {
            eraserBtn.addEventListener('click', function() {
                selectEraser(true);
            });
        }

        document.addEventListener('mousemove', function(e) {
            if (!penActive || !cursorDot) return;
            cursorDot.style.left = e.clientX + 'px';
            cursorDot.style.top = e.clientY + 'px';
        });

        // اختصارات لوحة المفاتيح: Ctrl + -> و Ctrl + <- و Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (penActive) {
                    setPenActive(false);
                }
                return;
            }
            if (e.ctrlKey && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
                e.preventDefault();
                if (e.key === 'ArrowRight') {
                    selectColorIndex(curColorIdx + 1, false);
                } else {
                    selectColorIndex(curColorIdx - 1, false);
                }
            }
        });

        // الضغط على أي صف في الجداول
        document.querySelectorAll('.recon-table tbody tr').forEach(function(tr) {
            tr.addEventListener('click', function() {
                if (!penActive) return;

                var sthId = this.getAttribute('data-sth-id');
                var suffix = this.getAttribute('data-suffix');
                if (!sthId || suffix === null) return;

                var oldClasses = this.className;
                var oldColor = '';
                for (var i = 0; i < colors.length; i++) {
                    if (this.classList.contains('hl-' + colors[i])) {
                        oldColor = colors[i];
                        break;
                    }
                }

                var newColor = '';
                if (isEraser) {
                    newColor = '';
                } else {
                    var chosenColor = colors[curColorIdx];
                    if (oldColor === chosenColor) {
                        newColor = '';
                    } else {
                        newColor = chosenColor;
                    }
                }

                // تحديث بصري فوري
                for (var j = 0; j < colors.length; j++) {
                    this.classList.remove('hl-' + colors[j]);
                }
                if (newColor !== '') {
                    this.classList.add('hl-' + newColor);
                }

                var that = this;
                var fd = new FormData();
                fd.append('action', 'recon_mark');
                fd.append('sth_id', sthId);
                fd.append('suffix', suffix);
                fd.append('color', newColor);
                fd.append('csrf', csrfToken);

                fetch('branch_recon.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.ok) {
                        that.className = oldClasses;
                        var msg = (data && data.error) ? data.error : 'فشل حفظ اللون';
                        if (typeof window.fgToast === 'function') {
                            fgToast(msg, 'err');
                        } else {
                            alert(msg);
                        }
                    }
                })
                .catch(function() {
                    that.className = oldClasses;
                    if (typeof window.fgToast === 'function') {
                        fgToast('تعذّر الاتصال بالسيرفر لحفظ اللون', 'err');
                    }
                });
            });
        });

        updateCursorColor();
    })();
    </script>

<?php else: ?>
    <!-- ========================================================= -->
    <!-- تابات الشاشة الرئيسية                                     -->
    <!-- ========================================================= -->
    <div class="tabs" style="margin-bottom:18px;">
        <a href="branch_recon.php?from=<?= e($activeTab === '' ? $from : $stStart) ?>&to=<?= e($to) ?>" class="tab <?= $activeTab === '' ? 'active' : '' ?>">الفروق</a>
        <a href="branch_recon.php?tab=alerts&from=<?= e($activeTab === 'alerts' ? $from : RECON_ALERTS_START) ?>&to=<?= e($to) ?>" class="tab <?= $activeTab === 'alerts' ? 'active' : '' ?>">تنبيهات</a>
        <a href="branch_recon.php?tab=ashraqa&from=<?= e($activeTab === 'ashraqa' ? $from : '2026-07-01') ?>&to=<?= e($to) ?>" class="tab <?= $activeTab === 'ashraqa' ? 'active' : '' ?>">مشتريات إشراقة</a>
    </div>

<?php if ($activeTab === 'alerts'): ?>
    <!-- ========================================================= -->
    <!-- تاب التنبيهات                                             -->
    <!-- ========================================================= -->
    <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <span class="section-title" style="margin-bottom:0;">تنبيهات مطابقة الفروع والإدارة</span>
            <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                الفترة من <?= e($from) ?> حتى <?= e($to) ?>
            </div>
        </div>
        <form method="get" class="filter-form" style="margin-bottom:0;">
            <input type="hidden" name="tab" value="alerts">
            <?php if ($showDone): ?>
                <input type="hidden" name="show_done" value="1">
            <?php endif; ?>
            <label>الطرف
                <select name="p" onchange="this.form.submit()" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);">
                    <option value="">كل الأطراف</option>
                    <?php foreach ($allParties as $pk => $p): ?>
                        <option value="<?= e($pk) ?>" <?= $pFilter === $pk ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>من <input type="date" class="finput" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
            <label>إلى <input type="date" class="finput" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
        </form>
    </div>

    <!-- ٣ كروت إحصائية للتنبيهات -->
    <div class="fkpis" style="margin-top:0;margin-bottom:18px;">
        <div class="fkpi" style="background:var(--neg-soft);border-color:var(--neg);">
            <span style="color:var(--neg);">أحمر (حرج)</span>
            <strong style="color:var(--neg);"><?= (int)$redCount ?> <span style="font-size:13px;font-weight:normal;">(<?= number_format($redAmt, 2) ?>)</span></strong>
        </div>
        <div class="fkpi" style="background:var(--warn-soft);border-color:var(--warn);">
            <span style="color:var(--warn);">أصفر (تنبيه)</span>
            <strong style="color:var(--warn);"><?= (int)$yellowCount ?> <span style="font-size:13px;font-weight:normal;">(<?= number_format($yellowAmt, 2) ?>)</span></strong>
        </div>
        <div class="fkpi" style="background:var(--line-soft);border-color:var(--line);">
            <span style="color:var(--ink-500);">رمادي (متابعة)</span>
            <strong style="color:var(--ink-900);"><?= (int)$grayCount ?> <span style="font-size:13px;font-weight:normal;">(<?= number_format($grayAmt, 2) ?>)</span></strong>
        </div>
    </div>

    <?php if (empty($alerts)): ?>
        <div class="fcard empty" style="text-align:center;padding:30px;color:var(--pos);font-size:15px;font-weight:600;">
            <i class="ti ti-circle-check"></i> مفيش حاجة غلط من <?= e($from) ?> لحد <?= e($to) ?>
        </div>
    <?php else: ?>
        <?php foreach ($alerts as $al): ?>
            <?php
            $borderColor = 'var(--line)';
            $iconClass = 'ti ti-alert-circle';
            $iconColor = 'var(--ink-500)';
            if ($al['level'] === 'red') {
                $borderColor = 'var(--neg)';
                $iconClass = 'ti ti-alert-circle';
                $iconColor = 'var(--neg)';
            } elseif ($al['level'] === 'yellow') {
                $borderColor = 'var(--warn)';
                $iconClass = 'ti ti-alert-triangle';
                $iconColor = 'var(--warn)';
            } elseif ($al['level'] === 'gray') {
                $borderColor = 'var(--ink-300)';
                $iconClass = 'ti ti-clock';
                $iconColor = 'var(--ink-500)';
            }
            ?>
            <div class="card" style="border-right:4px solid <?= $borderColor ?>;padding:16px 20px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:280px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                            <i class="<?= $iconClass ?>" style="font-size:18px;color:<?= $iconColor ?>;"></i>
                            <span style="font-size:14px;font-weight:600;color:var(--ink-900);line-height:1.5;"><?= e($al['text']) ?></span>
                        </div>
                        <div style="font-size:13px;color:var(--ink-600);margin-top:4px;padding-right:26px;">
                            <strong>الحل:</strong> <?= e($al['fix']) ?>
                        </div>
                    </div>
                    <div>
                        <a href="branch_recon.php?a=<?= urlencode($al['pair'][0]) ?>&b=<?= urlencode($al['pair'][1]) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-sm btn-ghost" style="white-space:nowrap;">
                            <i class="ti ti-external-link"></i> افتح الزوج
                        </a>
                    </div>
                </div>

                <?php if (!empty($al['lines'])): ?>
                    <details style="margin-top:10px;padding-right:26px;">
                        <summary style="cursor:pointer;color:var(--accent-text);font-size:13px;font-weight:600;">
                            الأصناف (<?= count($al['lines']) ?>)
                        </summary>
                        <div class="ftable-wrap" style="margin-top:8px;">
                            <table class="ftable">
                                <thead>
                                    <tr>
                                        <th>الصنف</th>
                                        <th>كمية/سعر عند <?= e($labels[$al['pair'][0]] ?? $al['pair'][0]) ?></th>
                                        <th>كمية/سعر عند <?= e($labels[$al['pair'][1]] ?? $al['pair'][1]) ?></th>
                                        <th>الفرق</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($al['lines'] as $ln): ?>
                                        <tr>
                                            <td><?= e($ln['name']) ?></td>
                                            <td><?= $ln['q1'] > 0 ? (number_format($ln['q1'], 2) . ' × ' . number_format($ln['p1'], 2)) : '—' ?></td>
                                            <td><?= $ln['q2'] > 0 ? (number_format($ln['q2'], 2) . ' × ' . number_format($ln['p2'], 2)) : '—' ?></td>
                                            <td style="font-weight:600;color:var(--neg);"><?= number_format($ln['diff'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($doneCount > 0): ?>
        <div style="text-align:center;margin:16px 0;">
            <?php if ($showDone): ?>
                <a href="branch_recon.php?tab=alerts&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&p=<?= urlencode($pFilter) ?>&show_done=0" class="btn btn-sm btn-ghost">
                    إخفاء اللي اتقفل (<?= $doneCount ?>)
                </a>
            <?php else: ?>
                <a href="branch_recon.php?tab=alerts&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&p=<?= urlencode($pFilter) ?>&show_done=1" class="btn btn-sm btn-ghost">
                    اعرض اللي اتقفل (<?= $doneCount ?>)
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'ashraqa' && $ashraqaDetailMode): ?>
    <!-- ========================================================= -->
    <!-- تاب مشتريات إشراقة: تفاصيل الفاتورة                        -->
    <!-- ========================================================= -->
    <div style="margin-bottom:14px;">
        <a href="branch_recon.php?tab=ashraqa&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-sm btn-ghost">
            <i class="ti ti-arrow-right"></i> رجوع لقائمة مشتريات إشراقة
        </a>
    </div>

    <?php if ($ashDocNotFound || !$ashDoc): ?>
        <div class="fcard empty" style="text-align:center;padding:30px;color:var(--neg);font-size:15px;font-weight:600;">
            الفاتورة مش موجودة
        </div>
    <?php else: ?>
        <div class="fcard" style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                <div>
                    <span class="section-title" style="margin-bottom:4px;font-size:17px;">
                        فاتورة <?= (int)$ashDoc['sstt_id'] === 113 ? 'شراء' : 'مرتجع شراء' ?> رقم <?= e($ashDoc['add_doc']) ?>
                        <?php if ((int)$ashDoc['cancelled'] === 1): ?>
                            <span style="font-size:12px;background:var(--neg-soft);color:var(--neg);padding:2px 8px;border-radius:6px;font-weight:600;margin-right:8px;">ملغية</span>
                        <?php endif; ?>
                    </span>
                    <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                        التاريخ: <?= e($ashDoc['doc_date']) ?> · المورد: <?= e($ashDoc['supplier_name']) ?> (<?= (int)$ashDoc['supplier_id'] ?>)
                        <?php if (!empty($ashDoc['notes'])): ?>
                            · ملاحظات: <?= e($ashDoc['notes']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:18px;font-weight:700;color:var(--ink-900);direction:ltr;text-align:right;">
                    <?= number_format((float)$ashDoc['net_amount'], 2) ?>
                </div>
            </div>

            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>الصنف عندهم</th>
                            <th>الباركود</th>
                            <th>صنفنا</th>
                            <th>الكمية</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ashLines as $ln): ?>
                            <?php
                            $bcs = !empty($ln['barcodes']) ? explode('|', $ln['barcodes']) : [];
                            $firstBc = trim($bcs[0] ?? '');
                            $ourName = null;
                            foreach ($bcs as $bc) {
                                $bc = trim($bc);
                                if ($bc !== '' && isset($ashCodesMap[$bc])) {
                                    $ourName = $ashCodesMap[$bc];
                                    break;
                                }
                            }
                            $uq = (float)$ln['unit_q'];
                            $q  = (float)$ln['quantity'];
                            ?>
                            <tr>
                                <td><?= (int)$ln['line_no'] ?></td>
                                <td style="font-weight:600;"><?= e($ln['item_name']) ?></td>
                                <td style="direction:ltr;text-align:right;font-variant-numeric:tabular-nums;"><?= e($firstBc) ?></td>
                                <td>
                                    <?php if ($ourName !== null): ?>
                                        <span style="color:var(--ink-900);"><?= e($ourName) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--warn);font-weight:600;">مش متعرّف عندنا</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (abs($uq - 1.0) >= 0.001 && $uq > 0.0001): ?>
                                        <?= number_format($q, 2) ?> × <?= number_format($uq, 2) ?> = <?= number_format($q * $uq, 2) ?> قطعة
                                    <?php else: ?>
                                        <?= number_format($q, 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="direction:ltr;text-align:right;"><?= number_format((float)$ln['unit_price'], 2) ?></td>
                                <td style="direction:ltr;text-align:right;font-weight:600;"><?= number_format((float)$ln['line_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'ashraqa'): ?>
    <!-- ========================================================= -->
    <!-- تاب مشتريات إشراقة: قائمة الفواتير                        -->
    <!-- ========================================================= -->
    <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <span class="section-title" style="margin-bottom:0;">مشتريات إشراقة</span>
            <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                الفترة من <?= e($from) ?> حتى <?= e($to) ?>
            </div>
        </div>
        <form method="get" class="filter-form" style="margin-bottom:0;">
            <input type="hidden" name="tab" value="ashraqa">
            <label>النوع
                <select name="sstt" onchange="this.form.submit()" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);">
                    <option value="0">الكل</option>
                    <option value="113" <?= $ashSstt === 113 ? 'selected' : '' ?>>شراء 113</option>
                    <option value="115" <?= $ashSstt === 115 ? 'selected' : '' ?>>مرتجع شراء 115</option>
                </select>
            </label>
            <label>المورد
                <select name="supplier" data-searchable="true" data-placeholder="بحث في الموردين..." data-empty="مفيش مورد بالاسم ده" onchange="this.form.submit()" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);">
                    <option value="0">كل الموردين</option>
                    <?php foreach ($ashSuppliers as $sp): ?>
                        <option value="<?= (int)$sp['supplier_id'] ?>" <?= $ashSupplier === (int)$sp['supplier_id'] ? 'selected' : '' ?>><?= e($sp['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>بحث <input type="text" name="q" value="<?= e($ashSearch) ?>" placeholder="رقم الفاتورة أو الملاحظات" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);"></label>
            <label>من <input type="date" class="finput" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
            <label>إلى <input type="date" class="finput" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
            <label style="cursor:pointer;">
                <input type="checkbox" name="show_cancelled" value="1" <?= $ashShowCancelled ? 'checked' : '' ?> onchange="this.form.submit()"> اعرض الملغي
            </label>
        </form>
    </div>

    <!-- ٣ كروت إحصائية لإشراقة -->
    <div class="fkpis" style="margin-top:0;margin-bottom:18px;">
        <div class="fkpi">
            <span>عدد الفواتير</span>
            <strong><?= count($ashInvoices) ?></strong>
        </div>
        <div class="fkpi" style="background:var(--pos-soft);border-color:var(--pos);">
            <span style="color:var(--pos);">إجمالي الشراء</span>
            <strong style="color:var(--pos);direction:ltr;text-align:right;"><?= number_format($totAshBuy, 2) ?></strong>
        </div>
        <div class="fkpi" style="background:var(--neg-soft);border-color:var(--neg);">
            <span style="color:var(--neg);">إجمالي المرتجع</span>
            <strong style="color:var(--neg);direction:ltr;text-align:right;"><?= number_format($totAshRet, 2) ?></strong>
        </div>
    </div>

    <div class="fcard" style="margin-bottom:18px;">
        <div class="ftable-wrap">
            <table class="ftable">
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>النوع</th>
                        <th>التاريخ</th>
                        <th>المورد</th>
                        <th>الملاحظات</th>
                        <th>عدد الأصناف</th>
                        <th>إجمالي القطع</th>
                        <th>الصافي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ashInvoices)): ?>
                        <tr><td colspan="8" class="empty">لا توجد فواتير مطابقة للبحث</td></tr>
                    <?php else: ?>
                        <?php foreach ($ashInvoices as $ai): ?>
                            <?php
                            $rowDocKey = $ai['sstt_id'] . '-' . $ai['brch_id'] . '-' . $ai['fy_no'] . '-' . $ai['add_doc'];
                            $rowUrl = 'branch_recon.php?tab=ashraqa&doc=' . urlencode($rowDocKey) . '&from=' . urlencode($from) . '&to=' . urlencode($to);
                            $isCanc = ((int)$ai['cancelled'] === 1);
                            ?>
                            <tr class="recon-row" onclick="window.location='<?= $rowUrl ?>'" style="<?= $isCanc ? 'opacity:0.55;' : '' ?>">
                                <td style="font-weight:700;">
                                    <a href="<?= $rowUrl ?>" class="inv-link" onclick="event.stopPropagation();">
                                        <?= e($ai['add_doc']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= (int)$ai['sstt_id'] === 113 ? 'شراء' : 'مرتجع شراء' ?>
                                    <?php if ($isCanc): ?>
                                        <span style="font-size:11px;background:var(--line-soft);color:var(--ink-500);padding:1px 6px;border-radius:4px;margin-right:4px;">ملغية</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($ai['doc_date']) ?></td>
                                <td><?= e($ai['supplier_name']) ?></td>
                                <td style="color:var(--ink-600);font-size:13px;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= e($ai['notes']) ?>">
                                    <?= e($ai['notes']) ?>
                                </td>
                                <td><?= (int)$ai['items_count'] ?></td>
                                <td style="direction:ltr;text-align:right;"><?= number_format((float)$ai['total_pieces'], 2) ?></td>
                                <td style="font-weight:700;direction:ltr;text-align:right;">
                                    <?= number_format((float)$ai['net_amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- ========================================================= -->
    <!-- الشاشة الرئيسية: جدول الأزواج العشرة                     -->
    <!-- ========================================================= -->
    <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <span class="section-title" style="margin-bottom:0;">مطابقة البضاعة بين الإدارة والفروع</span>
            <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                الفترة من <?= e($from) ?> حتى <?= e($to) ?>
            </div>
        </div>
        <form method="get" class="filter-form" style="margin-bottom:0;">
            <label>من <input type="date" class="finput" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
            <label>إلى <input type="date" class="finput" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
            <a href="branch_recon.php?from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                <i class="ti ti-download"></i> تصدير إكسيل
            </a>
        </form>
    </div>

    <!-- ٣ كروت إحصائية -->
    <div class="fkpis" style="margin-top:0;margin-bottom:18px;">
        <div class="fkpi">
            <span>عدد الأزواج</span>
            <strong><?= (int)$totPairsCount ?></strong>
        </div>
        <div class="fkpi" style="background:var(--pos-soft);border-color:var(--pos);">
            <span style="color:var(--pos);">متطابق</span>
            <strong style="color:var(--pos);"><?= (int)$matchCount ?></strong>
        </div>
        <div class="fkpi" style="background:<?= $mismatchCount > 0 ? 'var(--neg-soft)' : 'var(--line-soft)' ?>;border-color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--line)' ?>;">
            <span style="color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;">فيه فرق</span>
            <strong style="color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--ink-900)' ?>;"><?= (int)$mismatchCount ?></strong>
        </div>
    </div>

    <div class="fcard" style="margin-bottom:18px;">
        <div style="font-size:13px;color:var(--ink-600);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--line-soft);line-height:1.6;">
            صافي البضاعة اللي دخلت الفرع من كل طرف حسب دفاترنا، قصاد صافي اللي خرج لنا من عنده حسب دفاتره. الفرق صفر = الطرفين مسجّلين نفس الحاجة. اضغط على الصف تشوف الفواتير.
        </div>

        <div class="ftable-wrap">
        <table class="ftable">
            <thead>
                <tr>
                    <th>من</th>
                    <th>إلى</th>
                    <th>صافي اللي خرج (دفاتر «من»)</th>
                    <th>صافي اللي وصل (دفاتر «إلى»)</th>
                    <th>الفرق</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pairs as $p): ?>
                <?php
                // كشف ٥٩ مبني على إن a هو اللي استلم — لو a بقى «من» الأرقام بتطلع سالبة
                $rowUrl = 'branch_recon.php?a=' . urlencode($p['to_key']) . '&b=' . urlencode($p['from_key']) . '&from=' . urlencode($from) . '&to=' . urlencode($to);
                ?>
                <tr class="recon-row" onclick="window.location='<?= $rowUrl ?>'">
                    <td style="font-weight:700;">
                        <a href="<?= $rowUrl ?>" class="inv-link" onclick="event.stopPropagation();">
                            <?= e($p['from_label']) ?>
                        </a>
                    </td>
                    <td style="font-weight:700;">
                        <a href="<?= $rowUrl ?>" class="inv-link" onclick="event.stopPropagation();">
                            <?= e($p['to_label']) ?>
                        </a>
                    </td>
                    <td>
                        <div style="font-weight:700;color:var(--ink-900);direction:ltr;text-align:right;">
                            <?= number_format($p['net_sent'], 2) ?>
                        </div>
                        <div class="sub-cnt">
                            إرسال <?= number_format($p['from_sent']['amt'], 2) ?> (<?= $p['from_sent']['cnt'] ?>) − استرجاع <?= number_format($p['from_ret']['amt'], 2) ?> (<?= $p['from_ret']['cnt'] ?>)
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700;color:var(--ink-900);direction:ltr;text-align:right;">
                            <?= number_format($p['net_recv'], 2) ?>
                        </div>
                        <div class="sub-cnt">
                            استلام <?= number_format($p['to_recv']['amt'], 2) ?> (<?= $p['to_recv']['cnt'] ?>) − مردود <?= number_format($p['to_ret']['amt'], 2) ?> (<?= $p['to_ret']['cnt'] ?>)
                        </div>
                    </td>
                    <td>
                        <?php if ($p['is_match']): ?>
                            <span class="diff-match" style="font-size:14px;color:var(--pos);font-weight:700;">✓ متطابق</span>
                        <?php else: ?>
                            <span class="diff-pill diff-mismatch" style="color:var(--neg);"><?= number_format($p['diff'], 2) ?></span>
                            <div style="font-size:11px;color:var(--ink-500);margin-top:2px;">
                                <?= $p['diff'] > 0 ? ('دفاتر ' . e($p['from_label']) . ' فيها أكتر') : ('دفاتر ' . e($p['to_label']) . ' فيها أكتر') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
