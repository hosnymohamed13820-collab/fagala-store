<?php
/**
 * treasury.php — الخزينة وكشف الحساب
 * نظام الفجالة ستور المالي
 *
 * - عرض كل الخزائن وأرصدتها.
 * - عمليات: استلام نقدية، صرف نقدية، تحويل بين خزينتين.
 * - كشف حساب لكل خزينة بالمدين والدائن والرصيد التراكمي.
 *
 * كل العمليات بتمر من finance.php (المصدر الوحيد للحقيقة).
 */
require_once __DIR__ . '/auth.php';
require_permission('treasury');
require_once __DIR__ . '/finance.php';

$pdo       = db();
$flash     = '';
$flashType = 'success';
$uid       = (int) current_user()['id'];
$entryCodes   = entry_codes_list();
$costBranches = cost_branches_list();

// صلاحية تعديل (القيد + البيان) بعد الحفظ — الأونر بس، زي ما كان
// `set_entry_account` بالظبط قبل التعديل ده. الملاحظات مفتوحة لأي حد
// وصل للشاشة (الشاشة نفسها محمية بـ require_permission('treasury')).
// ⚠️ لو عايز المحاسب يعدّل البيان والقيد كمان: غيّر السطر ده لـ
//    $canEditEntry = is_owner() || can('treasury');
$canEditEntry = is_owner();
$canEditNotes = true;

// =====================================================================
//  AJAX — تعديل قيد بعد الحفظ (من غير أي ريفريش للصفحة)
//  بيتنادى قبل أي طباعة HTML. نفس باترن reports.php:
//  جسم JSON فيه csrf، والرد JSON.
//
//  🔴 المبالغ مش موجودة هنا خالص — لا في المدخلات ولا في الاستعلام.
//     التعديل = القيد · البيان · الملاحظات وبس.
// =====================================================================
if (($_GET['ajax'] ?? '') === 'je_edit') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('طلب غير صالح.');
        }
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) throw new RuntimeException('بيانات غير صالحة.');
        if (!csrf_verify((string) ($body['csrf'] ?? ''))) {
            throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش وحاول تاني.');
        }

        $jid = (int) ($body['id'] ?? 0);
        if ($jid <= 0) throw new RuntimeException('قيد غير صحيح.');

        // الصف الحالي — عشان اللي مالوش صلاحية يعدّل الملاحظة بس
        // من غير ما يقدر يغيّر البيان أو القيد بتزوير الطلب.
        $cur = $pdo->prepare('SELECT account_id, description FROM journal_entries WHERE id = ?');
        $cur->execute([$jid]);
        $curRow = $cur->fetch();
        if (!$curRow) throw new RuntimeException('القيد ده مش موجود.');

        if ($canEditEntry) {
            $accountId = ($body['account_id'] ?? '') !== '' && $body['account_id'] !== null
                ? (int) $body['account_id'] : null;
            $desc = (string) ($body['description'] ?? '');
        } else {
            // مالوش صلاحية → القيم القديمة زي ما هي، مهما بعت في الطلب
            $accountId = $curRow['account_id'] !== null ? (int) $curRow['account_id'] : null;
            $desc      = (string) $curRow['description'];
        }

        $res = update_journal_entry(
            $jid, $accountId, $desc,
            (string) ($body['notes'] ?? ''),
            $uid
        );
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            // ملاحظة: `set_entry_account` اتشال — كان بيعمل ريفريش كامل
            // للصفحة عشان يحدّد قيد واحد. بقى جزء من بوب أب التعديل
            // (`?ajax=je_edit`) اللي بيعدّل القيد والبيان والملاحظات مع
            // بعض من غير ريفريش. الدالة `set_journal_account()` لسه
            // موجودة في finance.php لو حد تاني بينديها.
            if ($action === 'receipt' || $action === 'disbursement') {
                $tid    = (int) ($_POST['treasury_id'] ?? 0);
                $amount = (float) ($_POST['amount'] ?? 0);
                $date   = $_POST['date'] ?? date('Y-m-d');
                $desc   = trim($_POST['description'] ?? '');
                $opType = $_POST['op_type'] ?? 'normal';  // normal / advance / custody
                $entryCodeId = ($_POST['entry_code_id'] ?? '') !== '' ? (int) $_POST['entry_code_id'] : null;
                $notes  = trim($_POST['notes'] ?? '');

                if ($action === 'disbursement' && $opType === 'advance') {
                    $empId = (int) ($_POST['employee_id'] ?? 0);
                    if ($empId <= 0) throw new RuntimeException('اختر الموظف للسلفة.');
                    disburse_advance($empId, $amount, $tid, $date, $desc ?: null, $uid);
                    $flash = 'تم صرف السلفة وتسجيلها على الموظف.';
                }
                elseif ($action === 'disbursement' && $opType === 'custody') {
                    $bId = (int) ($_POST['branch_id'] ?? 0);
                    if ($bId <= 0) throw new RuntimeException('اختر الفرع للعهدة.');
                    $ct = $pdo->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
                    $ct->execute([$bId]);
                    $ctId = (int) $ct->fetchColumn();
                    if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');
                    issue_disbursement_order($bId, $ctId, $amount, $date, $desc ?: null, $uid);
                    $flash = 'تم صرف العهدة للفرع (الخزينة نقصت وعهدة الفرع زادت).';
                }
                elseif ($action === 'receipt' && $opType === 'advance') {
                    $empId = (int) ($_POST['employee_id'] ?? 0);
                    if ($empId <= 0) throw new RuntimeException('اختر الموظف لرد السلفة.');
                    recover_advance($empId, $amount, $tid, $date, $desc ?: null, $uid);
                    $flash = 'تم رد السلفة وخصمها من رصيد الموظف.';
                }
                elseif ($action === 'receipt' && $opType === 'custody') {
                    $bId = (int) ($_POST['branch_id'] ?? 0);
                    if ($bId <= 0) throw new RuntimeException('اختر الفرع لاستلام العهدة.');
                    receive_custody($bId, $amount, $tid, $date, $desc ?: null, $uid);
                    $flash = 'تم استلام العهدة من الفرع (الخزينة زادت وعهدة الفرع نقصت).';
                }
                else {
                    // فرع التكلفة (تحليلي) — اسمه cost_branch_id في الفورم عشان
                    // ما يتعارضش مع branch_id بتاع صرف/استلام العهدة.
                    $costBranchId = ($_POST['cost_branch_id'] ?? '') !== ''
                        ? (int) $_POST['cost_branch_id'] : null;
                    treasury_movement($tid, $action, $amount, $date, $desc ?: null, $uid,
                                      $entryCodeId, $costBranchId, $notes ?: null);
                    $flash = ($action === 'receipt') ? 'تم تسجيل الاستلام.' : 'تم تسجيل الصرف.';
                }
            }
            elseif ($action === 'transfer') {
                $from   = (int) ($_POST['from_treasury_id'] ?? 0);
                $to     = (int) ($_POST['to_treasury_id'] ?? 0);
                $amount = (float) ($_POST['amount'] ?? 0);
                $date   = $_POST['date'] ?? date('Y-m-d');
                $notes  = trim($_POST['notes'] ?? '');
                transfer_between_treasuries($from, $to, $amount, $date, $notes ?: null, $uid);
                $flash = 'تم التحويل بنجاح.';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== جلب الخزائن =====
$treasuries = $pdo->query(
    'SELECT t.*, b.name AS branch_name
     FROM treasuries t
     LEFT JOIN branches b ON b.id = t.branch_id
     WHERE t.is_active = 1
     ORDER BY FIELD(t.type, "cash","main","visa","instapay","card","branch_custody"), t.name'
)->fetchAll();

$totalBalance = array_sum(array_column($treasuries, 'balance'));

// الموظفون (لقائمة السلفة)
$employees = $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();
// الفروع (لصرف العهدة من الخزينة)
$branchesForCustody = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();

// ===== كشف الحساب لخزينة محددة أو كل الخزائن =====
$selectedParam = $_GET['treasury'] ?? '';
$statement  = [];
$selectedTreasury = null;
$isAllStatement = ($selectedParam === 'all');

if ($isAllStatement) {
    // كشف مجمّع لكل الخزائن (مع اسم الخزينة في كل حركة)
    $st = $pdo->query(
        "SELECT je.*, t.name AS treasury_name, u.full_name AS by_name,
                COALESCE(ja.name, ec.name) AS entry_code_name,
                eu.full_name AS edited_by_name,
                cb.name AS cost_branch_name
         FROM journal_entries je
         JOIN treasuries t ON t.id = je.treasury_id
         LEFT JOIN users u ON u.id = je.created_by
         LEFT JOIN users eu ON eu.id = je.edited_by
         LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement')
         LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
         LEFT JOIN accounts ja ON ja.id = je.account_id
         LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
         LEFT JOIN branch_custody bc ON bc.id = je.ref_id
              AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
         LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
         LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
         LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
         LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
         ORDER BY je.id DESC LIMIT 300"
    );
    $statement = $st->fetchAll();
} else {
    $selectedId = (int) $selectedParam;
    if ($selectedId > 0) {
        foreach ($treasuries as $t) {
            if ((int) $t['id'] === $selectedId) { $selectedTreasury = $t; break; }
        }
        if ($selectedTreasury) {
            $st = $pdo->prepare(
                "SELECT je.*, u.full_name AS by_name,
                        COALESCE(ja.name, ec.name) AS entry_code_name,
                        eu.full_name AS edited_by_name,
                        cb.name AS cost_branch_name
                 FROM journal_entries je
                 LEFT JOIN users u ON u.id = je.created_by
                 LEFT JOIN users eu ON eu.id = je.edited_by
                 LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement')
                 LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                 LEFT JOIN accounts ja ON ja.id = je.account_id
                 LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                 LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                      AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                 LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                 LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                 LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                 LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
                 WHERE je.treasury_id = ?
                 ORDER BY je.id DESC
                 LIMIT 200"
            );
            $st->execute([$selectedId]);
            $statement = $st->fetchAll();
        }
    }
}

$page_title    = 'الخزينة وكشف الحساب';
$active_module = 'treasury';
require_once __DIR__ . '/includes/header.php';

$typeIcons = [
    'cash' => 'ti-cash', 'visa' => 'ti-credit-card', 'instapay' => 'ti-device-mobile',
    'card' => 'ti-credit-card', 'branch_custody' => 'ti-building-store',
    // ti-building-bank موجودة في tabler-subset.css (بتتستخدم في أيقونة موديول الخزينة)
    'main' => 'ti-building-bank',
];

/**
 * خلية «القيد» في كشف الحساب.
 * بقت عرض بس — التعديل بقى من زرار القلم في آخر الصف (بوب أب واحد
 * بيعدّل القيد والبيان والملاحظات مع بعض من غير ريفريش).
 */
function je_code_cell(array $row): string {
    if (!empty($row['entry_code_name'])) {
        return '<span class="je-code" style="padding:2px 9px;border-radius:20px;font-size:12px;'
             . 'color:var(--accent-hover);background:var(--bg);">'
             . e($row['entry_code_name']) . '</span>';
    }
    return '<span class="je-code" style="color:var(--ink-300);">—</span>';
}

/**
 * خلية «الملاحظات» — آخر عمود، وجواها زرار التعديل.
 * النص الطويل بيتقص بـ ellipsis والنص الكامل في الـ title (tooltip)،
 * والتصدير بياخد النص كامل من الداتا مش من الخلية.
 */
function je_notes_cell(array $row, bool $canEditEntry, bool $canEditNotes): string {
    $notes = (string) ($row['notes'] ?? '');
    $out = '<span class="je-note" title="' . e($notes) . '">'
         . ($notes !== '' ? e($notes) : '<span style="color:var(--ink-300);">—</span>')
         . '</span>';

    if ($canEditEntry || $canEditNotes) {
        $out .= '<button type="button" class="je-edit" title="تعديل القيد والبيان والملاحظات"'
              . ' data-id="'   . (int) $row['id'] . '"'
              . ' data-acc="'  . ($row['account_id'] !== null ? (int) $row['account_id'] : '') . '"'
              . ' data-desc="' . e((string) $row['description']) . '"'
              . ' data-notes="' . e($notes) . '"'
              . ' onclick="jeOpen(this)"><i class="ti ti-pencil"></i></button>';
    }
    if (!empty($row['edited_at'])) {
        $who = !empty($row['edited_by_name']) ? ' — ' . $row['edited_by_name'] : '';
        $out .= '<span class="je-edited" title="اتعدّل ' . e($row['edited_at']) . e($who) . '">معدّل</span>';
    }
    return $out;
}
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .summary-bar {
        background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#fff;
        border-radius:14px; padding:20px 24px; margin-bottom:20px;
        display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
    }
    .summary-bar .label { font-size:14px; opacity:0.85; }
    .summary-bar .value { font-size:28px; font-weight:700; margin-top:4px; }
    .summary-bar .actions { display:flex; gap:8px; flex-wrap:wrap; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 18px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-white { background:rgba(255,255,255,0.18); }
    .btn-white:hover { background:rgba(255,255,255,0.28); }
    .treasuries-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; margin-bottom:24px; }
    .treasury-card {
        background:#fff; border-radius:14px; padding:18px; cursor:pointer;
        border:2px solid transparent; transition:border-color .15s, transform .1s;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); text-decoration:none; display:block;
    }
    .treasury-card:hover { transform:translateY(-2px); }
    .treasury-card.selected { border-color:var(--accent); }
    .treasury-card .tc-top { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
    .treasury-card .tc-icon {
        width:40px; height:40px; background:var(--bg); border-radius:10px;
        display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:21px;
    }
    .treasury-card .tc-name { font-size:15px; font-weight:600; color:var(--ink-900); }
    .treasury-card .tc-type { font-size:12px; color:var(--ink-500); }
    .treasury-card .tc-balance { font-size:22px; font-weight:700; color:var(--ink-800); }
    .treasury-card .tc-balance.neg { color:var(--neg); }
    .treasury-card .tc-cur { font-size:13px; color:var(--ink-500); font-weight:400; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .amt-debit  { color:var(--neg); font-weight:500; }
    .amt-credit { color:var(--pos); font-weight:500; }
    .empty { text-align:center; color:#bbb; padding:30px; font-size:14px; }
    .section-title { font-size:17px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select, .form-row textarea {
        width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent); background:#fff; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
    /* عمود الملاحظات: النص الطويل بيتقص و tooltip بالنص الكامل.
       التصدير بياخد النص كامل من الداتا مش من الخلية. */
    .je-c-notes { white-space:nowrap; }
    .je-note { display:inline-block; max-width:190px; overflow:hidden;
        text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;
        color:var(--ink-700); font-size:13px; }
    .je-edit { background:none; border:none; cursor:pointer; color:var(--ink-400);
        padding:3px 5px; border-radius:6px; font-size:15px; vertical-align:middle; }
    .je-edit:hover { background:var(--bg); color:var(--accent); }
    .je-edited { font-size:11px; color:var(--ink-400); background:var(--line-soft);
        padding:1px 7px; border-radius:20px; margin-right:4px; vertical-align:middle; }
    .je-locked { font-size:12px; color:var(--ink-500); background:var(--line-soft);
        padding:9px 11px; border-radius:9px; line-height:1.6; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="summary-bar">
    <div>
        <div class="label">إجمالي الأرصدة (شامل عهد الفروع)</div>
        <div class="value"><?= money_fmt($totalBalance) ?> <span style="font-size:16px;">جنيه</span></div>
    </div>
    <div class="actions">
        <button class="btn btn-white" onclick="openModal('receiptModal')"><i class="ti ti-arrow-down-left"></i> استلام</button>
        <button class="btn btn-white" onclick="openModal('disburseModal')"><i class="ti ti-arrow-up-right"></i> صرف</button>
        <button class="btn btn-white" onclick="openModal('transferModal')"><i class="ti ti-transfer"></i> تحويل</button>
        <a href="?treasury=all" class="btn btn-white" style="text-decoration:none;"><i class="ti ti-list"></i> كشف حساب مجمّع</a>
    </div>
</div>

<div class="treasuries-grid">
    <?php foreach ($treasuries as $t): ?>
        <a href="?treasury=<?= (int)$t['id'] ?>"
           class="treasury-card <?= (!$isAllStatement && $selectedTreasury && (int)$selectedTreasury['id'] === (int)$t['id']) ? 'selected' : '' ?>">
            <div class="tc-top">
                <div class="tc-icon"><i class="ti <?= e($typeIcons[$t['type']] ?? 'ti-cash') ?>"></i></div>
                <div>
                    <div class="tc-name"><?= e($t['name']) ?></div>
                    <div class="tc-type"><?= e(treasury_type_label($t['type'])) ?></div>
                </div>
            </div>
            <div class="tc-balance <?= $t['balance'] < 0 ? 'neg' : '' ?>">
                <?= money_fmt((float)$t['balance']) ?> <span class="tc-cur">جنيه</span>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($isAllStatement): ?>
    <div class="card">
        <div class="section-title">
            كشف حساب مجمّع — كل الخزائن
            <span style="font-size:13px;color:var(--ink-500);font-weight:400;">(آخر 300 حركة)</span>
            <a href="treasury.php" class="btn btn-sm" style="float:left;font-size:13px;padding:5px 12px;text-decoration:none;">عرض الخزائن</a>
        </div>
        <?php if (empty($statement)): ?>
            <div class="empty">لا توجد حركات بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr>
                <th>التاريخ</th><th>الخزينة</th><th>البيان</th><th>القيد</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>بواسطة</th><th>الوقت</th><th data-xt-skip>الملاحظات</th>
            </tr></thead>
            <tbody>
                <?php foreach ($statement as $row): ?>
                    <tr id="je-<?= (int)$row['id'] ?>">
                        <td><?= e($row['entry_date']) ?></td>
                        <td style="font-weight:500;"><?= e($row['treasury_name']) ?></td>
                        <td class="je-c-desc"><?= e($row['description']) ?></td>
                        <td class="je-c-code"><?= je_code_cell($row) ?></td>
                        <td><?= !empty($row['cost_branch_name']) ? e($row['cost_branch_name']) : '<span style="color:var(--ink-300);">—</span>' ?></td>
                        <td><?= $row['debit'] > 0 ? '<span class="amt-debit">'.money_fmt((float)$row['debit']).'</span>' : '—' ?></td>
                        <td><?= $row['credit'] > 0 ? '<span class="amt-credit">'.money_fmt((float)$row['credit']).'</span>' : '—' ?></td>
                        <td style="font-weight:500;"><?= money_fmt((float)$row['running_balance']) ?></td>
                        <td style="color:var(--ink-500);font-size:13px;"><?= e($row['by_name'] ?? '—') ?></td>
                        <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($row['created_at']))) ?></td>
                        <td class="je-c-notes" data-xt-skip><?= je_notes_cell($row, $canEditEntry, $canEditNotes) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php elseif ($selectedTreasury): ?>
    <div class="card">
        <div class="section-title">
            كشف حساب: <?= e($selectedTreasury['name']) ?>
            <span style="font-size:13px;color:var(--ink-500);font-weight:400;">(آخر 200 حركة)</span>
        </div>
        <?php if (empty($statement)): ?>
            <div class="empty">لا توجد حركات على هذه الخزينة بعد.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th><th>البيان</th><th>القيد</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>بواسطة</th><th>الوقت</th><th data-xt-skip>الملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statement as $row): ?>
                    <tr id="je-<?= (int)$row['id'] ?>">
                        <td><?= e($row['entry_date']) ?></td>
                        <td class="je-c-desc"><?= e($row['description']) ?></td>
                        <td class="je-c-code"><?= je_code_cell($row) ?></td>
                        <td><?= !empty($row['cost_branch_name']) ? e($row['cost_branch_name']) : '<span style="color:var(--ink-300);">—</span>' ?></td>
                        <td><?= $row['debit'] > 0 ? '<span class="amt-debit">'.money_fmt((float)$row['debit']).'</span>' : '—' ?></td>
                        <td><?= $row['credit'] > 0 ? '<span class="amt-credit">'.money_fmt((float)$row['credit']).'</span>' : '—' ?></td>
                        <td style="font-weight:500;"><?= money_fmt((float)$row['running_balance']) ?></td>
                        <td style="color:var(--ink-500);font-size:13px;"><?= e($row['by_name'] ?? '—') ?></td>
                        <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($row['created_at']))) ?></td>
                        <td class="je-c-notes" data-xt-skip><?= je_notes_cell($row, $canEditEntry, $canEditNotes) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="empty">اختر خزينة من الأعلى لعرض كشف حسابها، أو اضغط "كشف حساب مجمّع".</div>
    </div>
<?php endif; ?>

<!-- ===== Modals ===== -->
<?php
// قوائم الخزائن لإعادة الاستخدام
function treasury_options(array $treasuries): string {
    $html = '';
    foreach ($treasuries as $t) {
        $html .= '<option value="' . (int)$t['id'] . '">'
               . e($t['name']) . ' (' . e(treasury_type_label($t['type'])) . ')</option>';
    }
    return $html;
}
$opts = treasury_options($treasuries);
// قائمة التحويل: الخزائن المركزية فقط (بدون عهد الفروع — العهد بتتم بإذن صرف/استلام)
$centralForTransfer = array_filter($treasuries, fn($t) => $t['type'] !== 'branch_custody');
$transferOpts = treasury_options($centralForTransfer);
$today = date('Y-m-d');
?>

<div class="modal-bg" id="receiptModal">
    <div class="modal">
        <h3>استلام نقدية</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="receipt">
            <div class="form-row"><label>الخزينة</label><select name="treasury_id" required><?= $transferOpts ?></select></div>
            <div class="form-row">
                <label>القيد</label>
                <?= acctree_field('entry_code_id') ?>
            </div>
            <div class="form-row cost-branch-row" id="receiptCostBranchRow">
                <label>اسم الفرع</label>
                <select name="cost_branch_id">
                    <option value="">— بدون تحديد —</option>
                    <?php foreach ($costBranches as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>"><?= e($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= $today ?>" required></div>
            <div class="form-row"><label>البيان</label><input type="text" name="description" placeholder="سبب الاستلام" aria-label="البيان"></div>
            <div class="form-row" id="receiptNotesRow">
                <label>ملاحظات <span style="color:var(--ink-400);font-weight:400;">(اختياري)</span></label>
                <textarea name="notes" rows="3" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
            </div>

            <!-- نوع الاستلام: عادي / رد سلفة / استلام عهدة -->
            <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                <label>نوع الاستلام</label>
                <select name="op_type" id="receiptType" onchange="toggleReceiptType()">
                    <option value="normal">استلام عادي</option>
                    <option value="advance">رد سلفة من موظف</option>
                    <option value="custody">استلام عهدة من فرع</option>
                </select>
                <div id="recAdvanceBox" style="display:none;margin-top:12px;">
                    <input type="text" id="recEmpSearch" placeholder="ابحث باسم الموظف..."
                           oninput="filterRecEmps()" autocomplete="off"
                           style="width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;margin-bottom:8px;" aria-label="ابحث باسم الموظف...">
                    <div id="recEmpList" style="max-height:160px;overflow-y:auto;border:1.5px solid var(--line);border-radius:8px;">
                        <?php foreach ($employees as $emp): ?>
                            <div class="rec-emp-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                 onclick="pickRecEmp(this)"
                                 style="padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line-soft);">
                                <?= e($emp['full_name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="employee_id" id="recEmpId" value="">
                    <div id="recEmpChosen" style="margin-top:8px;font-size:13px;color:var(--pos);font-weight:600;"></div>
                </div>
                <div id="recCustodyBox" style="display:none;margin-top:12px;">
                    <label>الفرع</label>
                    <select name="branch_id" id="recCustodyBranch">
                        <option value="">— اختر الفرع —</option>
                        <?php foreach ($branchesForCustody as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:6px;">الخزينة هتزيد وعهدة الفرع هتنقص.</p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">تسجيل الاستلام</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('receiptModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="disburseModal">
    <div class="modal">
        <h3>صرف نقدية</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="disbursement">
            <?php
            /* كل خزينة كيان مستقل — الصرف متاح من أي خزينة.
               خزينة الكاش وحدها مستثناة: بتستقبل مبيعات الفروع وبتحوّل
               للرئيسية وبس. متشالة من القايمة هنا، و`treasury_movement()`
               في finance.php بترفضها كمان كطبقة تانية لو جه طلب متزوّر.
               الافتراضي = الرئيسية، لأنها اللي بيطلع منها معظم الصرف. */
            $disbTreasuries = array_values(array_filter(
                $centralForTransfer,
                static fn(array $t): bool => $t['type'] !== 'cash'
            ));
            $defaultDisb = null;
            foreach ($disbTreasuries as $t) {
                if ($t['type'] === 'main') { $defaultDisb = (int) $t['id']; break; }
            }
            ?>
            <div class="form-row">
                <label>الخزينة</label>
                <select name="treasury_id" required>
                    <?php foreach ($disbTreasuries as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                                <?= $defaultDisb === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= e($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>القيد</label>
                <?= acctree_field('entry_code_id') ?>
            </div>
            <div class="form-row cost-branch-row" id="disburseCostBranchRow">
                <label>اسم الفرع</label>
                <select name="cost_branch_id">
                    <option value="">— بدون تحديد —</option>
                    <?php foreach ($costBranches as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>"><?= e($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= $today ?>" required></div>
            <div class="form-row"><label>البيان</label><input type="text" name="description" placeholder="سبب الصرف" aria-label="البيان"></div>
            <div class="form-row" id="disburseNotesRow">
                <label>ملاحظات <span style="color:var(--ink-400);font-weight:400;">(اختياري)</span></label>
                <textarea name="notes" rows="3" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
            </div>

            <!-- نوع الصرف: عادي / سلفة موظف / عهدة فرع -->
            <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                <label>نوع الصرف</label>
                <select name="op_type" id="disburseType" onchange="toggleDisburseType()">
                    <option value="normal">صرف عادي</option>
                    <option value="advance">سلفة لموظف</option>
                    <option value="custody">عهدة لفرع</option>
                </select>

                <!-- سلفة موظف -->
                <div id="advanceBox" style="display:none;margin-top:12px;">
                    <input type="text" id="empSearch" placeholder="ابحث باسم الموظف..."
                           oninput="filterEmps()" autocomplete="off"
                           style="width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;margin-bottom:8px;" aria-label="ابحث باسم الموظف...">
                    <div id="empList" style="max-height:160px;overflow-y:auto;border:1.5px solid var(--line);border-radius:8px;">
                        <?php foreach ($employees as $emp): ?>
                            <div class="emp-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                 onclick="pickEmp(this)"
                                 style="padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line-soft);">
                                <?= e($emp['full_name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="employee_id" id="empId" value="">
                    <div id="empChosen" style="margin-top:8px;font-size:13px;color:var(--pos);font-weight:600;"></div>
                </div>

                <!-- عهدة فرع -->
                <div id="custodyBox" style="display:none;margin-top:12px;">
                    <label>الفرع</label>
                    <select name="branch_id" id="custodyBranch">
                        <option value="">— اختر الفرع —</option>
                        <?php foreach ($branchesForCustody as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:6px;">
                        الخزينة هتنقص وعهدة الفرع هتزيد بنفس المبلغ.
                    </p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">تسجيل الصرف</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('disburseModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="transferModal">
    <div class="modal">
        <h3>تحويل بين خزينتين</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="transfer">
            <div class="form-row"><label>من خزينة</label><select name="from_treasury_id" required><?= $transferOpts ?></select></div>
            <div class="form-row"><label>إلى خزينة</label><select name="to_treasury_id" required><?= $transferOpts ?></select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= $today ?>" required></div>
            <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" aria-label="ملاحظات"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">تنفيذ التحويل</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('transferModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== تعديل قيد بعد الحفظ =====
     مش <form> عن قصد — الحفظ بيتم بـ fetch والصف بيتحدّث في مكانه،
     من غير أي ريفريش للصفحة (الفلاتر ومكان الاسكرول بيفضلوا زي ما هما). -->
<div class="modal-bg" id="jeModal">
    <div class="modal">
        <h3>تعديل القيد</h3>

        <div class="je-locked">
            <b>المبلغ والتاريخ والخزينة مايتعدلوش من هنا.</b><br>
            تصحيح مبلغ بيتعمل بقيد عكسي عشان الرصيد التراكمي في الكشف يفضل مظبوط.
        </div>

        <?php if ($canEditEntry): ?>
        <div class="form-row" style="margin-top:14px;">
            <label>القيد</label>
            <?= acctree_field('je_account', null, '— بدون قيد —', 'all', 'jeAccount') ?>
        </div>
        <div class="form-row">
            <label>البيان</label>
            <input type="text" id="jeDesc" maxlength="255" aria-label="البيان">
        </div>
        <?php endif; ?>

        <div class="form-row"<?= $canEditEntry ? '' : ' style="margin-top:14px;"' ?>>
            <label>ملاحظات</label>
            <textarea id="jeNotes" rows="5" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
        </div>

        <div id="jeError" class="flash error" style="display:none;margin-bottom:0;"></div>

        <div class="modal-actions">
            <button type="button" class="btn" id="jeSaveBtn" onclick="jeSave()">
                <i class="ti ti-device-floppy"></i> حفظ
            </button>
            <button type="button" class="btn btn-cancel" onclick="closeModal('jeModal')">إلغاء</button>
        </div>
    </div>
</div>

<?= acctree_data() ?>
<script src="assets/acctree.js?v=2"></script>
<script>
    function openModal(id){ document.getElementById(id).classList.add('open'); }
    function closeModal(id){ document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    // نوع الصرف: إظهار/إخفاء الأقسام
    function toggleDisburseType(){
        const t = document.getElementById('disburseType').value;
        document.getElementById('advanceBox').style.display = (t === 'advance') ? 'block' : 'none';
        document.getElementById('custodyBox').style.display = (t === 'custody') ? 'block' : 'none';
        if (t !== 'advance') {
            document.getElementById('empId').value = '';
            document.getElementById('empChosen').textContent = '';
        }
        if (t !== 'custody') {
            document.getElementById('custodyBranch').value = '';
        }
        // فرع التكلفة للصرف العادي بس — السلفة والعهدة ليهم موظف/فرع محدد أصلاً
        const cb = document.getElementById('disburseCostBranchRow');
        cb.style.display = (t === 'normal') ? '' : 'none';
        if (t !== 'normal') cb.querySelector('select').value = '';
        // الملاحظات للصرف العادي بس — السلفة والعهدة بيمروا على دوال
        // تانية (disburse_advance / issue_disbursement_order) لسه ما
        // اتضافلهاش الملاحظات. بنخفيها بدل ما المحاسب يكتب وتضيع.
        const nb = document.getElementById('disburseNotesRow');
        nb.style.display = (t === 'normal') ? '' : 'none';
        if (t !== 'normal') nb.querySelector('textarea').value = '';
    }
    function filterEmps(){
        const q = (document.getElementById('empSearch').value || '').trim().toLowerCase();
        document.querySelectorAll('.emp-opt').forEach(o => {
            const name = (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }
    function pickEmp(el){
        document.getElementById('empId').value = el.dataset.id;
        document.getElementById('empChosen').textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.emp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
    }

    // الاستلام: نوع الاستلام
    function toggleReceiptType(){
        const t = document.getElementById('receiptType').value;
        document.getElementById('recAdvanceBox').style.display = (t === 'advance') ? 'block' : 'none';
        document.getElementById('recCustodyBox').style.display = (t === 'custody') ? 'block' : 'none';
        if (t !== 'advance') { document.getElementById('recEmpId').value=''; document.getElementById('recEmpChosen').textContent=''; }
        if (t !== 'custody') { document.getElementById('recCustodyBranch').value=''; }
        // فرع التكلفة للاستلام العادي بس
        const cb = document.getElementById('receiptCostBranchRow');
        cb.style.display = (t === 'normal') ? '' : 'none';
        if (t !== 'normal') cb.querySelector('select').value = '';
        // نفس سبب الصرف — الملاحظات للاستلام العادي بس
        const nb = document.getElementById('receiptNotesRow');
        nb.style.display = (t === 'normal') ? '' : 'none';
        if (t !== 'normal') nb.querySelector('textarea').value = '';
    }
    function filterRecEmps(){
        const q = (document.getElementById('recEmpSearch').value || '').trim().toLowerCase();
        document.querySelectorAll('.rec-emp-opt').forEach(o => {
            const name = (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }
    function pickRecEmp(el){
        document.getElementById('recEmpId').value = el.dataset.id;
        document.getElementById('recEmpChosen').textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.rec-emp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
    }

    /* =================================================================
       تعديل قيد بعد الحفظ — من غير أي ريفريش
       -----------------------------------------------------------------
       الصفحة مابتتحمّلش تاني خالص: بنبعت fetch، وبنحدّث الخلايا
       الـ٣ (القيد · البيان · الملاحظات) في نفس الصف بس.
       الفلاتر ومكان الاسكرول بيفضلوا زي ما هما لأن مفيش navigation.

       🔴 المبالغ مش في الطلب ده أصلاً — لا في المدخلات ولا في الرد.
       ================================================================= */
    const JE_CSRF   = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    const JE_CAN_ED = <?= $canEditEntry ? 'true' : 'false' ?>;
    let jeCurrentId = 0;

    function jeOpen(btn){
        jeCurrentId = parseInt(btn.dataset.id, 10) || 0;
        if (!jeCurrentId) return;

        const err = document.getElementById('jeError');
        err.style.display = 'none';
        err.textContent = '';

        if (JE_CAN_ED) {
            // acctreeSet بتضبط القيمة والاسم الظاهر مع بعض
            acctreeSet(document.getElementById('jeAccount'), btn.dataset.acc || '');
            document.getElementById('jeDesc').value = btn.dataset.desc || '';
        }
        document.getElementById('jeNotes').value = btn.dataset.notes || '';

        openModal('jeModal');
        (JE_CAN_ED ? document.getElementById('jeDesc')
                   : document.getElementById('jeNotes')).focus();
    }

    async function jeSave(){
        if (!jeCurrentId) return;
        const btn = document.getElementById('jeSaveBtn');
        const err = document.getElementById('jeError');
        if (btn.disabled) return;                 // منع الدوس المكرر

        const payload = {
            csrf:  JE_CSRF,
            id:    jeCurrentId,
            notes: document.getElementById('jeNotes').value
        };
        if (JE_CAN_ED) {
            payload.account_id  = document.querySelector('#jeAccount input[type=hidden]').value;
            payload.description = document.getElementById('jeDesc').value;
            if (!payload.description.trim()) {
                err.textContent = 'البيان مايصحّش يكون فاضي.';
                err.style.display = 'block';
                return;
            }
        }

        btn.disabled = true;
        const oldHtml = btn.innerHTML;
        btn.textContent = 'جارِ الحفظ…';
        try {
            const res  = await fetch('treasury.php?ajax=je_edit', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'حصل خطأ.');

            jeApplyRow(data);
            closeModal('jeModal');
        } catch (ex) {
            err.textContent = ex.message || 'الاتصال فشل — حاول تاني.';
            err.style.display = 'block';
        } finally {
            btn.disabled  = false;
            btn.innerHTML = oldHtml;
        }
    }

    /* تحديث الصف في مكانه بالقيم اللي رجعت من السيرفر (مش اللي في الفورم) */
    function jeApplyRow(d){
        const tr = document.getElementById('je-' + d.id);
        if (!tr) return;

        const codeCell = tr.querySelector('.je-c-code');
        if (codeCell) {
            const span = codeCell.querySelector('.je-code');
            if (d.entry_code) {
                span.textContent = d.entry_code;
                span.style.cssText = 'padding:2px 9px;border-radius:20px;font-size:12px;'
                                   + 'color:var(--accent-hover);background:var(--bg);';
            } else {
                span.textContent = '—';
                span.style.cssText = 'color:var(--ink-300);';
            }
        }

        const descCell = tr.querySelector('.je-c-desc');
        if (descCell) descCell.textContent = d.description;

        const noteCell = tr.querySelector('.je-c-notes');
        if (noteCell) {
            const note = noteCell.querySelector('.je-note');
            note.title = d.notes || '';
            if (d.notes) {
                note.textContent = d.notes;
            } else {
                note.innerHTML = '<span style="color:var(--ink-300);">—</span>';
            }
            const btn = noteCell.querySelector('.je-edit');
            if (btn) {
                btn.dataset.acc   = d.account_id === null ? '' : d.account_id;
                btn.dataset.desc  = d.description;
                btn.dataset.notes = d.notes || '';
            }
            if (!noteCell.querySelector('.je-edited')) {
                const badge = document.createElement('span');
                badge.className   = 'je-edited';
                badge.textContent = 'معدّل';
                noteCell.appendChild(badge);
            }
        }

        // وميض أخضر خفيف عشان المستخدم يشوف الصف اللي اتغيّر
        tr.style.transition = 'background-color .35s';
        tr.style.backgroundColor = 'var(--pos-soft)';
        setTimeout(() => { tr.style.backgroundColor = ''; }, 900);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
