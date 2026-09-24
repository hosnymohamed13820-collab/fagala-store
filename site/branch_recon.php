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

// قراءة فترة البداية الافتراضية
$stStart = '2026-06-27';
try {
    $stSet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'supplier_statement_start' LIMIT 1");
    $stSet->execute();
    $v = $stSet->fetchColumn();
    if ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
        $stStart = (string)$v;
    }
} catch (Throwable) {}

$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $stStart;
$to   = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) ? $_GET['to'] : date('Y-m-d');

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
    $detailData = recon_get_party_moves($pdo, $from, $to, $aKey, $bKey);
    $partyMovesOur = $detailData['movesA'];
    $partyMovesHis = $detailData['movesB'];
    $partyTotOurBuy = $detailData['totA_buy'];
    $partyTotOurRet = $detailData['totA_ret'];
    $partyTotHisBuy = $detailData['totB_buy'];
    $partyTotHisRet = $detailData['totB_ret'];

    $partyNetOur = $partyTotOurBuy - $partyTotOurRet;
    $partyNetHis = $partyTotHisRet - $partyTotHisBuy;
    $partyDiff   = $partyNetOur - $partyNetHis;
    $partyIsMatch = abs($partyDiff) < 0.01;

    // جلب علامات التلوين
    $allSthIds = array_values(array_unique(array_filter(array_merge(
        array_column($partyMovesOur, 'sth_id'),
        array_column($partyMovesHis, 'sth_id')
    ))));
    $reconMarks = recon_get_marks($pdo, $allSthIds);

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
    // -------------------------------------------------------------
    // الشاشة العامة: جدول الأزواج العشرة
    // -------------------------------------------------------------
    $matrix = recon_get_matrix($pdo, $from, $to);
    $pairs  = recon_get_all_pairs($matrix);

    $totPairsCount = count($pairs);
    $matchCount = 0;
    $mismatchCount = 0;
    foreach ($pairs as $p) {
        if ($p['is_match']) {
            $matchCount++;
        } else {
            $mismatchCount++;
        }
    }

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

$page_title    = 'مطابقة الفروع';
$active_module = 'branch_recon';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-top:20px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line); border-radius:12px; padding:14px 16px; }
    .sum-card .label { font-size:12px; color:var(--ink-500); margin-bottom:4px; font-weight:500; }
    .sum-card .val { font-size:18px; font-weight:700; color:var(--ink-900); }
    .filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filter-form label { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-700); font-weight:500; }
    .filter-form input[type="date"] { padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:var(--surface); color:var(--ink-900); }
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
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
                <a href="branch_recon.php?a=<?= e($aKey) ?>&b=<?= e($bKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>
    </div>

    <div class="recon-cursor-dot" id="reconCursorDot"></div>

    <div class="dual-tables-grid">
        <!-- جدول الطرف A -->
        <div class="card" style="margin-bottom:0;">
            <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--line);">
                دفاتر <?= e($partyA['label']) ?>
            </div>
            <?php if (empty($partyMovesOur)): ?>
                <div class="empty">لا توجد فواتير مسجلة في هذه الفترة.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="recon-table">
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

            <div class="sum-cards" style="margin-top:16px;">
                <div class="sum-card">
                    <div class="label">إجمالي الشراء</div>
                    <div class="val"><?= number_format($partyTotOurBuy, 2) ?></div>
                </div>
                <div class="sum-card">
                    <div class="label">إجمالي المردود</div>
                    <div class="val"><?= number_format($partyTotOurRet, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- جدول الطرف B -->
        <div class="card" style="margin-bottom:0;">
            <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--line);">
                دفاتر <?= e($partyB['label']) ?>
            </div>
            <?php if (empty($partyMovesHis)): ?>
                <div class="empty">لا توجد فواتير مسجلة في هذه الفترة.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="recon-table">
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

            <div class="sum-cards" style="margin-top:16px;">
                <div class="sum-card">
                    <div class="label">إجمالي الشراء</div>
                    <div class="val"><?= number_format($partyTotHisBuy, 2) ?></div>
                </div>
                <div class="sum-card">
                    <div class="label">إجمالي المردود</div>
                    <div class="val"><?= number_format($partyTotHisRet, 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- كروت صافي المطابقة -->
    <div class="card" style="margin-top:16px;">
        <div style="font-weight:700;font-size:15px;color:var(--ink-900);margin-bottom:12px;">
            صافي المطابقة بين الطرفين
        </div>
        <div class="sum-cards" style="margin-top:0;">
            <div class="sum-card">
                <div class="label">وصلنا منه — صافي (دفاتر <?= e($partyA['label']) ?>)</div>
                <div class="val" style="direction:ltr;text-align:right;"><?= number_format($partyNetOur, 2) ?></div>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    شراء <?= number_format($partyTotOurBuy, 2) ?> − مردود <?= number_format($partyTotOurRet, 2) ?>
                </small>
            </div>
            <div class="sum-card">
                <div class="label">بعتهولنا — صافي (دفاتر <?= e($partyB['label']) ?>)</div>
                <div class="val" style="direction:ltr;text-align:right;"><?= number_format($partyNetHis, 2) ?></div>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    شراء <?= number_format($partyTotHisRet, 2) ?> − مردود <?= number_format($partyTotHisBuy, 2) ?>
                </small>
            </div>
            <div class="sum-card" style="background:<?= $partyIsMatch ? 'var(--pos-soft)' : 'var(--neg-soft)' ?>;border-color:<?= $partyIsMatch ? 'var(--pos)' : 'var(--neg)' ?>;">
                <div class="label">الفرق (دفاتر <?= e($partyA['label']) ?> − دفاتر <?= e($partyB['label']) ?>)</div>
                <div class="val" style="color:<?= $partyIsMatch ? 'var(--pos)' : 'var(--neg)' ?>;direction:ltr;text-align:right;">
                    <?= $partyIsMatch ? '✓ متطابق' : number_format($partyDiff, 2) ?>
                </div>
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
            <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
            <label>إلى <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
            <a href="branch_recon.php?from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                <i class="ti ti-download"></i> تصدير إكسيل
            </a>
        </form>
    </div>

    <!-- ٣ كروت إحصائية -->
    <div class="sum-cards" style="margin-top:0;margin-bottom:18px;">
        <div class="sum-card">
            <div class="label">عدد الأزواج</div>
            <div class="val"><?= (int)$totPairsCount ?></div>
        </div>
        <div class="sum-card" style="background:var(--pos-soft);border-color:var(--pos);">
            <div class="label" style="color:var(--pos);">متطابق</div>
            <div class="val" style="color:var(--pos);"><?= (int)$matchCount ?></div>
        </div>
        <div class="sum-card" style="background:<?= $mismatchCount > 0 ? 'var(--neg-soft)' : 'var(--line-soft)' ?>;border-color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--line)' ?>;">
            <div class="label" style="color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;">فيه فرق</div>
            <div class="val" style="color:<?= $mismatchCount > 0 ? 'var(--neg)' : 'var(--ink-900)' ?>;"><?= (int)$mismatchCount ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div style="font-size:13px;color:var(--ink-600);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--line-soft);line-height:1.6;">
            صافي البضاعة اللي دخلت الفرع من كل طرف حسب دفاترنا، قصاد صافي اللي خرج لنا من عنده حسب دفاتره. الفرق صفر = الطرفين مسجّلين نفس الحاجة. اضغط على الصف تشوف الفواتير.
        </div>

        <div style="overflow-x:auto;">
        <table>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
