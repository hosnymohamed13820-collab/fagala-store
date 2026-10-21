<?php
/**
 * treasury.php — الخزينة وكشف الحساب
 * نظام الفجالة ستور المالي
 *
 * - عرض كل الخزائن وأرصدتها.
 * - عمليات: استلام نقدية، صرف نقدية، تحويل بين خزينتين (تتطلب صلاحية treasury:operate).
 * - كشف حساب لكل خزينة بالمدين والدائن والرصيد التراكمي.
 *
 * كل العمليات بتمر من finance.php (المصدر الوحيد للحقيقة).
 */
require_once __DIR__ . '/auth.php';
require_permission('treasury:statement');
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/treasury_ops_lib.php';

$pdo       = db();
$flash     = '';
$flashType = 'success';
$uid       = (int) current_user()['id'];
$entryCodes   = entry_codes_list();
$costBranches = cost_branches_list();

// صلاحية تعديل (القيد + البيان) بعد الحفظ — الأونر بس، زي ما كان
// `set_entry_account` بالظبط قبل التعديل ده. الملاحظات مفتوحة لأي حد
// وصل للشاشة (الشاشة نفسها محمية بـ require_permission('treasury:statement')).
// ⚠️ لو عايز المحاسب يعدّل البيان والقيد كمان: غيّر السطر ده لـ
//    $canEditEntry = is_owner() || can('treasury:statement');
$canEditEntry = is_owner();
$canEditNotes = true;
$canReverse   = can('treasury:reverse');

// صرف · استلام · تحويل — جزء لوحده من ٢٢ سبتمبر ٢٠٢٦. قبلها أي حد بيشوف
// الخزينة (treasury:statement) كان بيقدر يحرّك فلوس. can() بترجّع true للأونر
// ولأي حد معاه 'treasury' كامل.
$canOperate   = can('treasury:operate');

// =====================================================================
//  AJAX — قيد عكسي لحركة صرف أو استلام
//  حارس الصلاحية: treasury:reverse في أول سطر.
// =====================================================================
if (($_GET['ajax'] ?? '') === 'je_reverse') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!can('treasury:reverse')) {
            throw new RuntimeException('غير مصرح لك بعمل قيد عكسي.');
        }
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

        $revEntryId = reverse_treasury_movement($jid, $uid);

        echo json_encode(['ok' => true, 'reversal_entry_id' => $revEntryId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  AJAX — تعديل قيد بعد الحفظ (من غير أي ريفريش للصفحة)
//  بيتنادى قبل أي طباعة HTML. نفس باترن reports.php:
//  جسم JSON فيه csrf، والرد JSON.
//
//  🔴 المبالغ مش موجودة هنا خالص — لا في المدخلات ولا في الاستعلام.
//     التعديل = القيد · البيان · الملاحظات · والتاريخ بقيد عكسي.
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
        // من غير ما يقدر يغيّر البيان أو القيد أو التاريخ بتزوير الطلب.
        $cur = $pdo->prepare('SELECT account_id, description, entry_date, ref_type, ref_id FROM journal_entries WHERE id = ?');
        $cur->execute([$jid]);
        $curRow = $cur->fetch();
        if (!$curRow) throw new RuntimeException('القيد ده مش موجود.');

        if ($canEditEntry) {
            $accountId = ($body['account_id'] ?? '') !== '' && $body['account_id'] !== null
                ? (int) $body['account_id'] : null;
            $desc    = (string) ($body['description'] ?? '');
            $reqDate = isset($body['date']) ? trim((string) $body['date']) : (string) $curRow['entry_date'];
        } else {
            // مالوش صلاحية → القيم القديمة زي ما هي، مهما بعت في الطلب
            $accountId = $curRow['account_id'] !== null ? (int) $curRow['account_id'] : null;
            $desc      = (string) $curRow['description'];
            $reqDate   = (string) $curRow['entry_date'];
        }

        // الترتيب مهم: update_journal_entry الأول عشان البيان والحساب
        // والملاحظة يبقوا نهائيين على القيد الأصلي، وبعدين change_movement_date
        // عشان القيد الجديد ياخد القيم المحدّثة.
        $res = update_journal_entry(
            $jid, $accountId, $desc,
            (string) ($body['notes'] ?? ''),
            $uid
        );

        $dateChanged = false;
        if ($canEditEntry && $reqDate !== '' && $reqDate !== (string) $curRow['entry_date']) {
            if ((string) ($curRow['ref_type'] ?? '') === 'reversal') {
                throw new RuntimeException('الحركة دي اتعكست — مينفعش تغيّر تاريخها.');
            }
            $refId = (int) ($curRow['ref_id'] ?? 0);
            if ($refId > 0 && treasury_movements_has_reverse_cols($pdo)) {
                $chkRev = $pdo->prepare('SELECT reversed_at FROM treasury_movements WHERE id = ?');
                $chkRev->execute([$refId]);
                $revAt = $chkRev->fetchColumn();
                if ($revAt !== false && $revAt !== null) {
                    throw new RuntimeException('الحركة دي اتعكست — مينفعش تغيّر تاريخها.');
                }
            }

            $dateRes = change_movement_date($jid, $reqDate, $uid);
            if (!empty($dateRes['changed'])) {
                $dateChanged = true;
                $res['date_res'] = $dateRes;
            }
        }

        echo json_encode(['ok' => true, 'date_changed' => $dateChanged] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canOperate) {
        // 🔴 حارس السيرفر — إخفاء الزراير لوحده مش حماية (أي حد يقدر يبعت POST بإيده).
        //    قبل أي action: اللي مالوش الجزء مايعدّيش لأي عملية، حتى لو اتضافت عملية جديدة بعدين.
        $flash = 'مالكش صلاحية صرف أو استلام أو تحويل.';
        $flashType = 'error';
    } elseif (!csrf_verify($_POST['csrf'] ?? '')) {
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
                $flash = treasury_apply_op($action, $_POST, $uid);
            }
            elseif ($action === 'transfer') {
                $from   = (int) ($_POST['from_treasury_id'] ?? 0);
                $to     = (int) ($_POST['to_treasury_id'] ?? 0);
                $amount = money_post('amount');
                if ($amount <= 0) throw new RuntimeException('المبلغ لازم يكون أكبر من صفر.');
                $date   = fin_valid_date((string) ($_POST['date'] ?? date('Y-m-d')));
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

require_once __DIR__ . '/treasury_lib.php';

// ===== جلب الخزائن والأرصدة المخزنة =====
$treData      = treasury_get_treasuries($pdo);
$treasuries   = $treData['treasuries'];
$totalBalance = $treData['total_balance'];
$realCash     = $treData['real_cash'];

// القوائم المساعدة
$auxLists           = treasury_get_auxiliary_lists($pdo);
$employees          = $auxLists['employees'];
$suppliers          = $auxLists['suppliers'];
$branchesForCustody = $auxLists['branches_for_custody'];

// ===== فلتر التاريخ لكشف الحساب =====
$periodInfo = treasury_parse_period($_GET['from'] ?? null, $_GET['to'] ?? null);
$stFrom     = $periodInfo['from'];
$stTo       = $periodInfo['to'];
$stMaxRows  = 5000;

// ===== كشف الحساب لخزينة محددة أو كل الخزائن =====
$selectedParam = (string) ($_GET['treasury'] ?? '');
$statementData = treasury_get_statement($pdo, $selectedParam, $stFrom, $stTo, $treasuries, $stMaxRows);

$selectedTreasuryId = $statementData['selected_treasury_id'];
$statement          = $statementData['statement'];
$selectedTreasury   = $statementData['selected_treasury'];
$isAllStatement     = $statementData['is_all_statement'];
$totalDebit         = $statementData['totals']['total_debit'];
$totalCredit        = $statementData['totals']['total_credit'];
$totalNet           = $statementData['totals']['total_net'];
$totalCount         = $statementData['totals']['total_count'];

$page_title    = 'الخزينة وكشف الحساب';
$active_module = 'treasury';
require_once __DIR__ . '/sensitive_lib.php';
sens_page();
require_once __DIR__ . '/includes/header.php';

$typeIcons = [
    'cash' => 'ti-cash', 'visa' => 'ti-credit-card', 'instapay' => 'ti-device-mobile',
    'card' => 'ti-credit-card', 'branch_custody' => 'ti-building-store',
    'employee_custody' => 'ti-users',
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
             . 'color:var(--accent-text);background:var(--bg);">'
             . e($row['entry_code_name']) . '</span>';
    }
    return '<span class="je-code trs-dash">—</span>';
}

/**
 * خلية «الملاحظات» — آخر عمود، وجواها زرار التعديل.
 * النص الطويل بيتقص بـ ellipsis والنص الكامل في الـ title (tooltip)،
 * والتصدير بياخد النص كامل من الداتا مش من الخلية.
 */
function je_notes_cell(array $row, bool $canEditEntry, bool $canEditNotes, bool $canReverse = false, string $treasuryName = ''): string {
    $notes = (string) ($row['notes'] ?? '');
    $refType = (string) ($row['ref_type'] ?? '');
    $isReversed = !empty($row['reversed_at']);
    $isReversal = ($refType === 'reversal');

    $out = '<span class="je-note" title="' . e($notes) . '">'
         . ($notes !== '' ? e($notes) : '<span class="trs-dash">—</span>')
         . '</span>';

    if ($canEditEntry || $canEditNotes) {
        $out .= '<button type="button" class="je-edit" title="تعديل القيد والبيان والملاحظات"'
              . ' data-id="'       . (int) ($row['id'] ?? 0) . '"'
              . ' data-acc="'      . (isset($row['account_id']) && $row['account_id'] !== null ? (int) $row['account_id'] : '') . '"'
              . ' data-desc="'     . e((string) ($row['description'] ?? '')) . '"'
              . ' data-notes="'    . e($notes) . '"'
              . ' data-date="'     . e((string) ($row['entry_date'] ?? '')) . '"'
              . ' data-reftype="'  . e($refType) . '"'
              . ' data-reversed="' . ($isReversed ? '1' : '0') . '"'
              . ' onclick="jeOpen(this)"><i class="ti ti-pencil"></i></button>';
    }

    if ($canReverse && in_array($refType, ['receipt', 'disbursement'], true) && !$isReversed) {
        $tName = (string) ($row['treasury_name'] ?? $treasuryName);
        $amt = ((float) ($row['debit'] ?? 0) > 0) ? (float) $row['debit'] : (float) ($row['credit'] ?? 0);
        $out .= '<button type="button" class="je-rev" title="قيد عكسي"'
              . ' data-id="'       . (int) $row['id'] . '"'
              . ' data-type="'     . e($refType) . '"'
              . ' data-amount="'   . number_format($amt, 2) . '"'
              . ' data-treasury="' . e($tName) . '"'
              . ' data-date="'     . e(substr((string) $row['entry_date'], 0, 10)) . '"'
              . ' onclick="jeReverse(this)"><i class="ti ti-refresh"></i></button>';
    }

    if ($isReversed) {
        $out .= '<span class="je-edited" title="اتعكس ' . e((string) $row['reversed_at']) . '">اتعكس</span>';
    }
    if ($isReversal) {
        $out .= '<span class="je-edited">قيد عكسي</span>';
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
    .btn-white { background:rgba(255,255,255,0.18); }
    .btn-white:hover { background:rgba(255,255,255,0.28); }
    .summary-bar .sens-eye { color: #fff; border-color: rgba(255,255,255,.4); }
    .summary-bar .sens-eye .sens-eye-sub { color: rgba(255,255,255,.75); }
    .summary-bar .sens-eye:hover { background: rgba(255,255,255,0.18); border-color: #fff; color: #fff; }
    /* خزينة الكاش الفعلي — نفس كارت الداشبورد س 161-168 */
    .tre-box { background:var(--surface); border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .tre-box .tre-top { display:flex; align-items:center; gap:8px; color:var(--ink-500); font-size:13px; margin-bottom:10px; }
    .tre-box .tre-val { font-size:26px; font-weight:800; color:var(--ink-900); }
    .tre-box .tre-cur { font-size:13px; color:var(--ink-400); font-weight:500; }
    .tre-box.realcash {
        border:2px solid var(--pos);
        background:linear-gradient(135deg,var(--pos-soft) 0%,var(--surface) 70%);
        display:flex; align-items:center; justify-content:space-between;
        gap:16px; padding:16px 22px;
        margin-bottom:16px;
    }
    .tre-box.realcash .tre-top { margin-bottom:0; font-size:14px; color:var(--pos); }
    .tre-box.realcash .tre-val { font-size:30px; }
    /* 🔴 الكارت ده كان أخضر مهما كانت القيمة — «خزينة الكاش الفعلي -82,131»
           في إطار أخضر. اللون لازم يتبع الإشارة وإلا بيبقى بلا معنى.
           و`#fff` كان مثبّت فالجراديانت كان بيبيّض في الوضع الليلي. */
    .tre-box.realcash.neg {
        border-color:var(--neg);
        background:linear-gradient(135deg,var(--neg-soft) 0%,var(--surface) 70%);
    }
    .tre-box.realcash.neg .tre-top { color:var(--neg); }
    .treasuries-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; margin-bottom:24px; }
    .treasury-card {
        background:var(--surface); border-radius:14px; padding:18px; cursor:pointer;
        border:2px solid transparent; transition:border-color .15s, transform .1s;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); text-decoration:none; display:block;
    }
    .treasury-card:hover { transform:translateY(-2px); }
    .treasury-card.selected { border-color:var(--accent-text); }
    .treasury-card .tc-top { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
    .treasury-card .tc-icon {
        width:40px; height:40px; background:var(--bg); border-radius:10px;
        display:flex; align-items:center; justify-content:center; color:var(--accent-text); font-size:21px;
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
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .section-title { font-size:17px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    /* شريط الفلتر وكروت الإجماليات لكشف الحساب */
    .filter-bar { background:var(--line-soft); border-radius:12px; padding:12px 16px; margin-bottom:16px;
        display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .filter-bar label { font-size:13px; color:var(--ink-700); font-weight:500; }
    .filter-bar input[type="date"] { padding:8px 12px; border:1.5px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:var(--surface); }
    .filter-bar input[type="date"]:focus { outline:none; border-color:var(--accent-text); }
    .btn-filter { padding:8px 16px; font-size:13px; }
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:16px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line); border-radius:12px; padding:12px 16px; }
    .sc-label { font-size:12px; color:var(--ink-500); margin-bottom:5px; }
    .sc-value { font-size:19px; font-weight:700; color:var(--ink-900); direction:ltr; text-align:right; }
    .sc-cur { font-size:13px; font-weight:400; color:var(--ink-500); }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select, .form-row textarea {
        width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    /* عمود الملاحظات: النص الطويل بيتقص و tooltip بالنص الكامل.
       التصدير بياخد النص كامل من الداتا مش من الخلية. */
    .je-c-notes { white-space:nowrap; }
    .je-note { display:inline-block; max-width:190px; overflow:hidden;
        text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;
        color:var(--ink-700); font-size:13px; }
    .je-edit { background:none; border:none; cursor:pointer; color:var(--ink-400);
        padding:3px 5px; border-radius:6px; font-size:15px; vertical-align:middle; }
    .je-edit:hover { background:var(--bg); color:var(--accent-text); }
    .je-rev { background:none; border:none; cursor:pointer; color:var(--ink-400);
        padding:3px 5px; border-radius:6px; font-size:15px; vertical-align:middle; }
    .je-rev:hover { background:var(--bg); color:var(--accent-text); }
    .je-edited { font-size:11px; color:var(--ink-400); background:var(--line-soft);
        padding:1px 7px; border-radius:20px; margin-right:4px; vertical-align:middle; }
    .je-locked { font-size:12px; color:var(--ink-500); background:var(--line-soft);
        padding:9px 11px; border-radius:9px; line-height:1.6; }
    /* جدول كشف الحساب ١٣ عمود — أعرض من الكارت على الشاشة العادية كمان.
       من غير التمرير ده عمود «مدين» بيتقص وبيعرض آخر ٣ أرقام من المبلغ. */
    .stmt-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .stmt-scroll > table { min-width: max-content; }

    /* كلاسات بدل style="" المتكرر — بريف ٤٥. القيم منقولة بالحرف، متغيّرهاش. */
    .trs-dash { color:var(--ink-400); }
    .form-row input.trs-pick-search { width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;margin-bottom:8px; }
    .trs-pick-list { max-height:160px;overflow-y:auto;border:1.5px solid var(--line);border-radius:8px; }
    .trs-pick-opt { padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line-soft); }
    .trs-pick-chosen { margin-top:8px;font-size:13px;color:var(--pos);font-weight:600; }
    .trs-fw5 { font-weight:500; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="summary-bar">
    <div>
        <div class="label">إجمالي الأرصدة (شامل عهد الفروع)</div>
        <div class="value"><?= sens_mask(ltr_out(money_fmt($totalBalance))) ?> <span style="font-size:16px;">ج.م</span></div>
    </div>
    <div class="actions">
        <?= sens_eye() ?>
        <?php if ($canOperate): ?>
        <button class="btn btn-white" onclick="openModal('receiptModal')"><i class="ti ti-arrow-down-left"></i> استلام</button>
        <button class="btn btn-white" onclick="openModal('disburseModal')"><i class="ti ti-arrow-up-right"></i> صرف</button>
        <button class="btn btn-white" onclick="openModal('transferModal')"><i class="ti ti-transfer"></i> تحويل</button>
        <?php endif; ?>
        <a href="?treasury=all&from=<?= e($stFrom) ?>&to=<?= e($stTo) ?>" class="btn btn-white" style="text-decoration:none;"><i class="ti ti-list"></i> كشف حساب مجمّع</a>
    </div>
</div>

<div class="tre-box realcash<?= (sens_unlocked() && $realCash < 0 ? ' neg' : '') ?>" title="الخزينة الرئيسية + خزينة الكاش">
    <div class="tre-top"><i class="ti ti-cash"></i> خزينة الكاش الفعلي</div>
    <div class="tre-val"><?= sens_mask(ltr_out(money_fmt($realCash))) ?> <span class="tre-cur">ج.م</span></div>
</div>

<div class="treasuries-grid">
    <?php foreach ($treasuries as $t): ?>
        <a href="?treasury=<?= (int)$t['id'] ?>&from=<?= e($stFrom) ?>&to=<?= e($stTo) ?>"
           class="treasury-card <?= (!$isAllStatement && $selectedTreasury && (int)$selectedTreasury['id'] === (int)$t['id']) ? 'selected' : '' ?>">
            <div class="tc-top">
                <div class="tc-icon"><i class="ti <?= e($typeIcons[$t['type']] ?? 'ti-cash') ?>"></i></div>
                <div>
                    <div class="tc-name"><?= e($t['name']) ?></div>
                    <div class="tc-type"><?= e(treasury_type_label($t['type'])) ?></div>
                </div>
            </div>
            <div class="tc-balance <?= (sens_unlocked() && (float)$t['balance'] < 0 ? 'neg' : '') ?>">
                <?= sens_mask(ltr_out(money_fmt((float)$t['balance']))) ?> <span class="tc-cur">ج.م</span>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($isAllStatement): ?>
    <div class="card">
        <div class="section-title">
            كشف حساب مجمّع — كل الخزائن
            <span style="font-size:13px;color:var(--ink-500);font-weight:400;">(كل حركات الفترة)</span>
            <a href="treasury.php" class="btn btn-sm" style="float:left;font-size:13px;padding:5px 12px;text-decoration:none;">عرض الخزائن</a>
        </div>

        <form method="get" class="filter-bar">
            <input type="hidden" name="treasury" value="all">
            <label for="stAllFrom">من</label>
            <input type="date" name="from" id="stAllFrom" value="<?= e($stFrom) ?>">
            <label for="stAllTo">إلى</label>
            <input type="date" name="to" id="stAllTo" value="<?= e($stTo) ?>">
            <button type="submit" class="btn btn-filter"><i class="ti ti-filter"></i> تطبيق</button>
        </form>

        <div class="sum-cards st-cards">
            <div class="sum-card">
                <div class="sc-label">إجمالي المدين</div>
                <div class="sc-value amt-debit" data-sc="debit" data-server="<?= (float)$totalDebit ?>"><?= ltr_out(money_fmt($totalDebit)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
            <div class="sum-card">
                <div class="sc-label">إجمالي الدائن</div>
                <div class="sc-value amt-credit" data-sc="credit" data-server="<?= (float)$totalCredit ?>"><?= ltr_out(money_fmt($totalCredit)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
            <div class="sum-card">
                <div class="sc-label">الصافي (مدين − دائن)</div>
                <div class="sc-value <?= $totalNet > 0 ? 'amt-debit' : ($totalNet < 0 ? 'amt-credit' : '') ?>" data-sc="net" data-server="<?= (float)$totalNet ?>"><?= ltr_out(money_fmt($totalNet)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
        </div>
        <div class="st-filter-note" style="display:none;font-size:12.5px;color:var(--warn);margin:-8px 0 14px;font-weight:500;">
            <i class="ti ti-filter"></i> الأرقام دي <b>للصفوف الظاهرة بس</b> (<span class="st-fc"></span>).
        </div>

        <?php if ($totalCount > $stMaxRows): ?>
            <div style="font-size:12.5px;color:var(--ink-500);margin:-8px 0 14px;">
                الفترة دي فيها <?= number_format($totalCount) ?> حركة — الجدول بيعرض
                آخر <?= number_format($stMaxRows) ?> منهم بس (صغّر الفترة عشان تشوف الباقي)، لكن <b>الكروت فوق محسوبة على الفترة كلها</b>.
            </div>
        <?php endif; ?>

        <?php if (empty($statement)): ?>
            <div class="empty">لا توجد حركات بعد.</div>
        <?php else: ?>
        <div class="stmt-scroll">
        <table>
            <thead><tr>
                <th>التاريخ</th><th>الخزينة</th><th>البيان</th><th>رأس القيد</th><th>القيد</th><th>اسم المورد</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>بواسطة</th><th>الوقت</th><th data-xt-skip>الملاحظات</th>
            </tr></thead>
            <tbody>
                <?php foreach ($statement as $row): ?>
                    <tr id="je-<?= (int)$row['id'] ?>">
                        <td><?= e($row['entry_date']) ?></td>
                        <td class="trs-fw5"><?= e($row['treasury_name']) ?></td>
                        <td class="je-c-desc">
                            <?= e($row['description']) ?>
                            <?php $tPath = je_transfer_path($row['transfer_from_name'] ?? null, $row['transfer_to_name'] ?? null); ?>
                            <?php if ($tPath !== ''): ?>
                                <div style="font-size:11.5px;color:var(--ink-400);margin-top:2px;">
                                    <?= e($tPath) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($row['parent_account_name']) ? e($row['parent_account_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td class="je-c-code"><?= je_code_cell($row) ?></td>
                        <td><?= !empty($row['supplier_name']) ? e($row['supplier_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td><?= !empty($row['cost_branch_name']) ? e($row['cost_branch_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td><?= $row['debit'] > 0 ? '<span class="amt-debit">'.money_fmt_table((float)$row['debit']).'</span>' : '—' ?></td>
                        <td><?= $row['credit'] > 0 ? '<span class="amt-credit">'.money_fmt_table((float)$row['credit']).'</span>' : '—' ?></td>
                        <td class="trs-fw5"><?= ltr_out(money_fmt_table((float)$row['running_balance'])) ?></td>
                        <td style="color:var(--ink-500);font-size:13px;"><?= e($row['by_name'] ?? '—') ?></td>
                        <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($row['created_at']))) ?></td>
                        <td class="je-c-notes" data-xt-skip><?= je_notes_cell($row, $canEditEntry, $canEditNotes, $canReverse, (string) ($row['treasury_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
<?php elseif ($selectedTreasury): ?>
    <div class="card">
        <div class="section-title">
            كشف حساب: <?= e($selectedTreasury['name']) ?>
            <span style="font-size:13px;color:var(--ink-500);font-weight:400;">(كل حركات الفترة)</span>
        </div>

        <form method="get" class="filter-bar">
            <input type="hidden" name="treasury" value="<?= (int)$selectedTreasury['id'] ?>">
            <label for="stOneFrom">من</label>
            <input type="date" name="from" id="stOneFrom" value="<?= e($stFrom) ?>">
            <label for="stOneTo">إلى</label>
            <input type="date" name="to" id="stOneTo" value="<?= e($stTo) ?>">
            <button type="submit" class="btn btn-filter"><i class="ti ti-filter"></i> تطبيق</button>
        </form>

        <div class="sum-cards st-cards">
            <div class="sum-card">
                <div class="sc-label">إجمالي المدين</div>
                <div class="sc-value amt-debit" data-sc="debit" data-server="<?= (float)$totalDebit ?>"><?= ltr_out(money_fmt($totalDebit)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
            <div class="sum-card">
                <div class="sc-label">إجمالي الدائن</div>
                <div class="sc-value amt-credit" data-sc="credit" data-server="<?= (float)$totalCredit ?>"><?= ltr_out(money_fmt($totalCredit)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
            <div class="sum-card">
                <div class="sc-label">الصافي (مدين − دائن)</div>
                <div class="sc-value <?= $totalNet > 0 ? 'amt-debit' : ($totalNet < 0 ? 'amt-credit' : '') ?>" data-sc="net" data-server="<?= (float)$totalNet ?>"><?= ltr_out(money_fmt($totalNet)) ?> <span class="sc-cur">ج.م</span></div>
            </div>
        </div>
        <div class="st-filter-note" style="display:none;font-size:12.5px;color:var(--warn);margin:-8px 0 14px;font-weight:500;">
            <i class="ti ti-filter"></i> الأرقام دي <b>للصفوف الظاهرة بس</b> (<span class="st-fc"></span>).
        </div>

        <?php if ($totalCount > $stMaxRows): ?>
            <div style="font-size:12.5px;color:var(--ink-500);margin:-8px 0 14px;">
                الفترة دي فيها <?= number_format($totalCount) ?> حركة — الجدول بيعرض
                آخر <?= number_format($stMaxRows) ?> منهم بس (صغّر الفترة عشان تشوف الباقي)، لكن <b>الكروت فوق محسوبة على الفترة كلها</b>.
            </div>
        <?php endif; ?>

        <?php if (empty($statement)): ?>
            <div class="empty">لا توجد حركات على هذه الخزينة بعد.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th><th>البيان</th><th>رأس القيد</th><th>القيد</th><th>اسم المورد</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>بواسطة</th><th>الوقت</th><th data-xt-skip>الملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statement as $row): ?>
                    <tr id="je-<?= (int)$row['id'] ?>">
                        <td><?= e($row['entry_date']) ?></td>
                        <td class="je-c-desc">
                            <?= e($row['description']) ?>
                            <?php $tPath = je_transfer_path($row['transfer_from_name'] ?? null, $row['transfer_to_name'] ?? null); ?>
                            <?php if ($tPath !== ''): ?>
                                <div style="font-size:11.5px;color:var(--ink-400);margin-top:2px;">
                                    <?= e($tPath) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($row['parent_account_name']) ? e($row['parent_account_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td class="je-c-code"><?= je_code_cell($row) ?></td>
                        <td><?= !empty($row['supplier_name']) ? e($row['supplier_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td><?= !empty($row['cost_branch_name']) ? e($row['cost_branch_name']) : '<span class="trs-dash">—</span>' ?></td>
                        <td><?= $row['debit'] > 0 ? '<span class="amt-debit">'.money_fmt_table((float)$row['debit']).'</span>' : '—' ?></td>
                        <td><?= $row['credit'] > 0 ? '<span class="amt-credit">'.money_fmt_table((float)$row['credit']).'</span>' : '—' ?></td>
                        <td class="trs-fw5"><?= ltr_out(money_fmt_table((float)$row['running_balance'])) ?></td>
                        <td style="color:var(--ink-500);font-size:13px;"><?= e($row['by_name'] ?? '—') ?></td>
                        <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($row['created_at']))) ?></td>
                        <td class="je-c-notes" data-xt-skip><?= je_notes_cell($row, $canEditEntry, $canEditNotes, $canReverse, (string) ($selectedTreasury['name'] ?? '')) ?></td>
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
function treasury_options(array $treasuries, ?int $selectedId = null): string {
    $html = '';
    foreach ($treasuries as $t) {
        $html .= '<option value="' . (int)$t['id'] . '"'
               . ($selectedId === (int)$t['id'] ? ' selected' : '') . '>'
               . e($t['name']) . ' (' . e(treasury_type_label($t['type'])) . ')</option>';
    }
    return $html;
}
$opts = treasury_options($treasuries);
// قائمة التحويل: الخزائن المركزية فقط (بدون عهد الفروع والموظفين — العهد بتتم بإذن صرف/استلام)
$centralForTransfer = array_filter($treasuries, fn($t) => !in_array($t['type'], ['branch_custody', 'employee_custody'], true));
$transferOpts = treasury_options($centralForTransfer);
// قوائم الاستلام والصرف: الخزائن المركزية المفعّل ظهورها في الإيصالات فقط
$receiptTreasuries = array_values(array_filter(
    $centralForTransfer,
    fn(array $t): bool => (int)($t['show_in_receipts'] ?? 1) === 1
));
// الخزينة اللي كشفها مفتوح بتتختار لوحدها — لو مش في القايمة بتتجاهل
$receiptOpts = treasury_options($receiptTreasuries, $selectedTreasuryId);
$today = date('Y-m-d');
?>

<div class="modal-bg" id="receiptModal">
    <div class="modal">
        <h3>استلام نقدية</h3>
        <form method="post" id="receiptForm" onsubmit="return handleReceiptSubmit(event)" data-busy="جاري الحفظ...">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="receipt">
            <!-- نوع الاستلام: عادي / مورد / رد سلفة / استلام عهدة -->
            <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                <label for="receiptType">نوع الاستلام</label>
                <select name="op_type" id="receiptType" onchange="toggleReceiptType()">
                    <option value="normal">استلام عادي</option>
                    <option value="supplier">استلام من مورد</option>
                    <option value="advance">رد سلفة من موظف</option>
                    <option value="custody">استلام عهدة من فرع</option>
                </select>
                <div id="recSupplierBox" style="display:none;margin-top:12px;">
                    <input type="text" id="recSuppSearch" class="trs-pick-search" placeholder="ابحث باسم المورد..."
                           data-picker-search="#recSuppList" data-item=".rec-supp-opt" data-empty="مفيش مورد بالاسم ده"
                           oninput="filterRecSupps()" autocomplete="off" aria-label="ابحث باسم المورد...">
                    <div id="recSuppList" class="trs-pick-list">
                        <?php foreach ($suppliers as $s): ?>
                            <div class="rec-supp-opt trs-pick-opt" data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"
                                 onclick="pickRecSupp(this)">
                                <?= e($s['name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="supplier_id" id="recSuppId" value="">
                    <div id="recSuppChosen" class="trs-pick-chosen"></div>
                </div>
                <div id="recAdvanceBox" style="display:none;margin-top:12px;">
                    <input type="text" id="recEmpSearch" class="trs-pick-search" placeholder="ابحث باسم الموظف..."
                           oninput="filterRecEmps()" autocomplete="off" aria-label="ابحث باسم الموظف...">
                    <div id="recEmpList" class="trs-pick-list">
                        <?php foreach ($employees as $emp): ?>
                            <div class="rec-emp-opt trs-pick-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                 onclick="pickRecEmp(this)">
                                <?= e($emp['full_name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="employee_id" id="recEmpId" value="">
                    <div id="recEmpChosen" class="trs-pick-chosen"></div>
                </div>
                <div id="recCustodyBox" style="display:none;margin-top:12px;">
                    <label for="recCustodyBranch">الفرع</label>
                    <select name="branch_id" id="recCustodyBranch">
                        <option value="">— اختر الفرع —</option>
                        <?php foreach ($branchesForCustody as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:6px;">الخزينة هتزيد وعهدة الفرع هتنقص.</p>
                </div>
            </div>


            <div class="form-row"><label for="rcTreasury">الخزينة</label><select name="treasury_id" id="rcTreasury" required><?= $receiptOpts ?></select></div>
            <div class="form-row" id="receiptAccountRow">
                <label>القيد</label>
                <?= acctree_field('entry_code_id') ?>
            </div>
            <div class="form-row cost-branch-row" id="receiptCostBranchRow">
                <label for="rcCostBranch">اسم الفرع</label>
                <select name="cost_branch_id" id="rcCostBranch">
                    <option value="">— بدون تحديد —</option>
                    <?php foreach ($costBranches as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>"><?= e($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label for="rcAmount">المبلغ</label><input type="text" inputmode="decimal" data-money name="amount" id="rcAmount" required
                   autocomplete="off" placeholder="0" aria-label="المبلغ"></div>
            <div class="form-row"><label for="rcDate">التاريخ</label><input type="date" name="date" id="rcDate" value="<?= $today ?>" required></div>
            <div class="form-row"><label for="rcDesc">البيان</label><input type="text" name="description" id="rcDesc" placeholder="سبب الاستلام" aria-label="البيان"></div>
            <div class="form-row" id="receiptNotesRow">
                <label for="rcNotes">ملاحظات <span style="color:var(--ink-400);font-weight:400;">(اختياري)</span></label>
                <textarea name="notes" id="rcNotes" rows="3" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
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
        <form method="post" id="disburseForm" onsubmit="return handleDisburseSubmit(event)">
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
                static fn(array $t): bool => $t['type'] !== 'cash' && (int)($t['show_in_receipts'] ?? 1) === 1
            ));
            $defaultDisb = null;
            foreach ($disbTreasuries as $t) {
                if ($t['type'] === 'main') { $defaultDisb = (int) $t['id']; break; }
            }
            // الخزينة اللي كشفها مفتوح تغلب الرئيسية — لو موجودة في القايمة
            // (خزينة الكاش مش فيها، فبترجع للرئيسية)
            foreach ($disbTreasuries as $t) {
                if ($selectedTreasuryId === (int) $t['id']) { $defaultDisb = $selectedTreasuryId; break; }
            }
            ?>
            <div id="disbFormBody">
                <!-- نوع الصرف: عادي / مورد / سلفة موظف / عهدة فرع -->
                <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                    <label for="disburseType">نوع الصرف</label>
                    <select name="op_type" id="disburseType" onchange="toggleDisburseType()">
                        <option value="normal">صرف عادي</option>
                        <option value="supplier">صرف لمورد</option>
                        <option value="advance">سلفة لموظف</option>
                        <option value="custody">عهدة لفرع</option>
                        <option value="employee_custody">عهدة لموظف</option>
                    </select>

                    <!-- صرف لمورد -->
                    <div id="supplierBox" style="display:none;margin-top:12px;">
                        <input type="text" id="suppSearch" class="trs-pick-search" placeholder="ابحث باسم المورد..."
                               data-picker-search="#suppList" data-item=".supp-opt" data-empty="مفيش مورد بالاسم ده"
                               oninput="filterSupps()" autocomplete="off" aria-label="ابحث باسم المورد...">
                        <div id="suppList" class="trs-pick-list">
                            <?php foreach ($suppliers as $s): ?>
                                <div class="supp-opt trs-pick-opt" data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"
                                     onclick="pickSupp(this)">
                                    <?= e($s['name']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="supplier_id" id="suppId" value="">
                        <div id="suppChosen" class="trs-pick-chosen"></div>
                    </div>

                    <!-- سلفة موظف -->
                    <div id="advanceBox" style="display:none;margin-top:12px;">
                        <input type="text" id="empSearch" class="trs-pick-search" placeholder="ابحث باسم الموظف..."
                               oninput="filterEmps()" autocomplete="off" aria-label="ابحث باسم الموظف...">
                        <div id="empList" class="trs-pick-list">
                            <?php foreach ($employees as $emp): ?>
                                <div class="emp-opt trs-pick-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                     onclick="pickEmp(this)">
                                    <?= e($emp['full_name']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="employee_id" id="empId" value="">
                        <div id="empChosen" class="trs-pick-chosen"></div>
                    </div>

                    <!-- عهدة فرع -->
                    <div id="custodyBox" style="display:none;margin-top:12px;">
                        <label for="custodyBranch">الفرع</label>
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


                <div class="form-row">
                    <label for="dsTreasury">الخزينة</label>
                    <select name="treasury_id" id="dsTreasury" required>
                        <?php foreach ($disbTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"
                                    <?= $defaultDisb === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= e($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" id="disburseAccountRow">
                    <label>القيد</label>
                    <?= acctree_field('entry_code_id') ?>
                </div>
                <div class="form-row cost-branch-row" id="disburseCostBranchRow">
                    <label for="dsCostBranch">اسم الفرع</label>
                    <select name="cost_branch_id" id="dsCostBranch">
                        <option value="">— بدون تحديد —</option>
                        <?php foreach ($costBranches as $cb): ?>
                            <option value="<?= (int)$cb['id'] ?>"><?= e($cb['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="dsAmount">المبلغ</label><input type="text" inputmode="decimal" data-money name="amount" id="dsAmount" required
                       autocomplete="off" placeholder="0" aria-label="المبلغ"></div>
                <div class="form-row"><label for="dsDate">التاريخ</label><input type="date" name="date" id="dsDate" value="<?= $today ?>" required></div>
                <div class="form-row"><label for="dsDesc">البيان</label><input type="text" name="description" id="dsDesc" placeholder="سبب الصرف" aria-label="البيان"></div>
                <div class="form-row" id="disburseNotesRow">
                    <label for="dsNotes">ملاحظات <span style="color:var(--ink-400);font-weight:400;">(اختياري)</span></label>
                    <textarea name="notes" id="dsNotes" rows="3" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn">تسجيل الصرف</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('disburseModal')">إلغاء</button>
                </div>
            </div>

            <!-- خطوة التأكيد قبل تسجيل الصرف -->
            <div id="disbConfirmBox" style="display:none;">
                <div style="background:var(--line-soft);padding:16px;border-radius:12px;border:1.5px solid var(--line);margin-bottom:18px;line-height:1.7;font-size:14px;color:var(--ink-800);">
                    انت على وشك تسجيل مصروف بقيمة <b id="disbConfirmAmount"></b> جنيه من خزينة «<span id="disbConfirmTreasury"></span>».<br>
                    <span id="disbConfirmNormal">القيد: <b id="disbConfirmAccount"></b> · الفرع: <b id="disbConfirmBranch"></b><br></span>
                    متأكد إن المبلغ ده هيتخصم من الخزينة دي؟
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" id="disbConfirmBtn" onclick="confirmDisburse()">تأكيد الصرف</button>
                    <button type="button" class="btn btn-cancel" onclick="cancelDisburseConfirm()">إلغاء</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="transferModal">
    <div class="modal">
        <h3>تحويل بين خزينتين</h3>
        <form method="post" id="transferForm" onsubmit="return handleTransferSubmit(event)" data-busy="جاري التحويل...">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="transfer">
            <div class="form-row"><label for="trFrom">من خزينة</label><select name="from_treasury_id" id="trFrom" required><?= $transferOpts ?></select></div>
            <div class="form-row"><label for="trTo">إلى خزينة</label><select name="to_treasury_id" id="trTo" required><?= $transferOpts ?></select></div>
            <div class="form-row"><label for="trAmount">المبلغ</label><input type="text" inputmode="decimal" data-money name="amount" id="trAmount" required
                   autocomplete="off" placeholder="0" aria-label="المبلغ"></div>
            <div class="form-row"><label for="trDate">التاريخ</label><input type="date" name="date" id="trDate" value="<?= $today ?>" required></div>
            <div class="form-row"><label for="trNotes">ملاحظات</label><input type="text" name="notes" id="trNotes" aria-label="ملاحظات"></div>
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
            <b>المبلغ والخزينة مايتعدلوش من هنا.</b><br>
            تعديل التاريخ بيتم بقيد عكسي تلقائياً عشان الرصيد التراكمي في الكشف يفضل مظبوط.
        </div>

        <?php if ($canEditEntry): ?>
        <div class="form-row" style="margin-top:14px;">
            <label for="jeDate">التاريخ</label>
            <input type="date" id="jeDate" aria-label="التاريخ">
            <div id="jeDateHint" style="display:none;font-size:12px;color:var(--ink-500);margin-top:4px;">
                تاريخ القيد ده جاي من الموديول بتاعه (وردية / عهدة / راتب / تحويل / فاتورة مورد) ويتعدّل من هناك.
            </div>
        </div>
        <div class="form-row">
            <label>القيد</label>
            <?= acctree_field('je_account', null, '— بدون قيد —', 'all', 'jeAccount') ?>
        </div>
        <div class="form-row">
            <label for="jeDesc">البيان</label>
            <input type="text" id="jeDesc" maxlength="255" aria-label="البيان">
        </div>
        <?php endif; ?>

        <div class="form-row"<?= $canEditEntry ? '' : ' style="margin-top:14px;"' ?>>
            <label for="jeNotes">ملاحظات</label>
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
<script src="assets/acctree.js?v=186"></script>
<script>
    function openModal(id){
        if (id === 'disburseModal') resetDisburseModal();
        document.getElementById(id).classList.add('open');
        const type = id === 'receiptModal' ? document.getElementById('receiptType')
                   : id === 'disburseModal' ? document.getElementById('disburseType') : null;
        if (type) type.focus();
    }
    function closeModal(id){
        if (id === 'disburseModal') resetDisburseModal();
        document.getElementById(id).classList.remove('open');
    }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => {
            if (e.target === m) {
                if (m.id === 'disburseModal') resetDisburseModal();
                m.classList.remove('open');
            }
        });
    });

    // القيد والفرع إجباريين في الاستلام/الصرف العادي بس (السلفة والعهدة والمورد ليهم فحصهم).
    // خانة القيد input hidden — `required` مابيشتغلش عليها، فالفحص هنا.
    // والسيرفر بيرفض برضو (treasury.php — «اختار القيد.» / «اختار الفرع.»).
    function normalOpMissing(form, typeSelectId){
        const op = document.getElementById(typeSelectId).value;
        if (op === 'supplier') {
            if (!form.querySelector('input[name="supplier_id"]').value) return 'اختار المورد.';
            if (!form.querySelector('select[name="cost_branch_id"]').value) return 'اختار الفرع.';
            return '';
        }
        if (op === 'advance' || op === 'employee_custody') {
            if (!form.querySelector('input[name="employee_id"]').value) return 'اختار الموظف.';
            return '';
        }
        if (op === 'custody') {
            if (!form.querySelector('select[name="branch_id"]').value) return 'اختار الفرع.';
            return '';
        }
        if (op !== 'normal') return '';
        if (!form.querySelector('input[name="entry_code_id"]').value) return 'اختار القيد.';
        if (!form.querySelector('select[name="cost_branch_id"]').value) return 'اختار الفرع.';
        return '';
    }
    function handleReceiptSubmit(e){
        const amt = moneyVal(document.getElementById('receiptForm').querySelector('input[name="amount"]'));
        if (amt <= 0) { e.preventDefault(); fgToast('اكتب مبلغ أكبر من صفر.', 'warn'); return false; }
        const missing = normalOpMissing(document.getElementById('receiptForm'), 'receiptType');
        if (missing) { e.preventDefault(); fgToast(missing, 'warn'); return false; }
        return true;
    }
    // تأكيد التحويل — أي تحويل بين خزينتين
    function handleTransferSubmit(e){
        const form = document.getElementById('transferForm');
        const from = form.querySelector('select[name="from_treasury_id"]');
        const to   = form.querySelector('select[name="to_treasury_id"]');
        if (from.value === to.value) {
            e.preventDefault();
            fgToast('مينفعش تحوّل لنفس الخزينة.', 'warn');
            return false;
        }
        const amtVal = moneyVal(form.querySelector('input[name="amount"]'));
        if (amtVal <= 0) { e.preventDefault(); fgToast('اكتب مبلغ أكبر من صفر.', 'warn'); return false; }
        const amt = amtVal.toLocaleString('en-US', {
            minimumFractionDigits: (amtVal % 1 === 0 ? 0 : 2),
            maximumFractionDigits: 2
        });
        const msg = 'تحويل ' + amt + ' ج من «' + from.options[from.selectedIndex].text.trim()
                  + '» إلى «' + to.options[to.selectedIndex].text.trim() + '» — متأكد؟';
        if (!confirm(msg)) { e.preventDefault(); return false; }
        return true;
    }

    // تأكيد صرف النقدية قبل الإرسال
    let disbConfirmed = false;
    let disbSending = false;
    function handleDisburseSubmit(e){
        if (!disbConfirmed) {
            e.preventDefault();
            const form = document.getElementById('disburseForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return false;
            }
            const amtInput = form.querySelector('input[name="amount"]');
            const amtVal = moneyVal(amtInput);
            if (amtVal <= 0) { fgToast('اكتب مبلغ أكبر من صفر.', 'warn'); return false; }
            const formattedAmt = amtVal.toLocaleString('en-US', {
                minimumFractionDigits: (amtVal % 1 === 0 ? 0 : 2),
                maximumFractionDigits: 2
            });
            const missing = normalOpMissing(form, 'disburseType');
            if (missing) { fgToast(missing, 'warn'); return false; }
            const treSelect = form.querySelector('select[name="treasury_id"]');
            const treName = treSelect && treSelect.selectedIndex >= 0
                ? treSelect.options[treSelect.selectedIndex].text.trim()
                : '';
            document.getElementById('disbConfirmAmount').textContent = formattedAmt;
            document.getElementById('disbConfirmTreasury').textContent = treName;
            // القيد والفرع في التأكيد — عشان لو القيد غلط ياخد باله قبل ما الفلوس تتسجّل
            const opType = document.getElementById('disburseType').value;
            const isNormal = opType === 'normal';
            const isSupplier = opType === 'supplier';
            document.getElementById('disbConfirmNormal').style.display = (isNormal || isSupplier) ? '' : 'none';
            if (isNormal) {
                const accLabel = form.querySelector('.act-label');
                const brSelect = form.querySelector('select[name="cost_branch_id"]');
                document.getElementById('disbConfirmAccount').textContent = accLabel ? accLabel.textContent.trim() : '';
                document.getElementById('disbConfirmBranch').textContent = brSelect.options[brSelect.selectedIndex].text.trim();
            } else if (isSupplier) {
                const suppChosenText = document.getElementById('suppChosen').textContent.replace('تم اختيار:', '').trim();
                const brSelect = form.querySelector('select[name="cost_branch_id"]');
                document.getElementById('disbConfirmAccount').textContent = 'مورد: ' + suppChosenText;
                document.getElementById('disbConfirmBranch').textContent = brSelect.options[brSelect.selectedIndex].text.trim();
            } else if (opType === 'advance' || opType === 'employee_custody') {
                const empChosenText = document.getElementById('empChosen').textContent.replace('تم اختيار:', '').trim();
                document.getElementById('disbConfirmNormal').style.display = '';
                document.getElementById('disbConfirmAccount').textContent = (opType === 'employee_custody' ? 'عهدة موظف: ' : 'سلفة: ') + empChosenText;
                document.getElementById('disbConfirmBranch').textContent = '—';
            }
            document.getElementById('disbFormBody').style.display = 'none';
            document.getElementById('disbConfirmBox').style.display = 'block';
            return false;
        }
    }
    /* 🔴 حارس الإرسال المزدوج — دي شاشة صرف فلوس. دوستين بسرعة =
       إرسالين = صرفين. `formSubmit` بيبعت الحدث بس، مابيمنعش التكرار. */
    function setDisbSending(sending){
        disbSending = sending;
        const btn = document.getElementById('disbConfirmBtn');
        if (!btn) return;
        fgBusy(btn, sending, 'جاري الصرف…');
    }
    function confirmDisburse(){
        if (disbSending) return;
        disbConfirmed = true;
        setDisbSending(true);
        formSubmit(document.getElementById('disburseForm'));
    }
    function cancelDisburseConfirm(){
        disbConfirmed = false;
        setDisbSending(false);
        document.getElementById('disbConfirmBox').style.display = 'none';
        document.getElementById('disbFormBody').style.display = 'block';
    }
    function resetDisburseModal(){
        disbConfirmed = false;
        setDisbSending(false);
        const confirmBox = document.getElementById('disbConfirmBox');
        const formBody = document.getElementById('disbFormBody');
        if (confirmBox) confirmBox.style.display = 'none';
        if (formBody) formBody.style.display = 'block';
    }

    // نوع الصرف: إظهار/إخفاء الأقسام
    function toggleDisburseType(){
        const t = document.getElementById('disburseType').value;
        const accRow = document.getElementById('disburseAccountRow');
        if (accRow) accRow.style.display = (t === 'normal') ? '' : 'none';
        document.getElementById('supplierBox').style.display = (t === 'supplier') ? 'block' : 'none';
        document.getElementById('advanceBox').style.display = (t === 'advance' || t === 'employee_custody') ? 'block' : 'none';
        document.getElementById('custodyBox').style.display = (t === 'custody') ? 'block' : 'none';
        if (t !== 'supplier') {
            document.getElementById('suppId').value = '';
            document.getElementById('suppChosen').textContent = '';
        }
        if (t !== 'advance' && t !== 'employee_custody') {
            document.getElementById('empId').value = '';
            document.getElementById('empChosen').textContent = '';
        }
        if (t !== 'custody') {
            document.getElementById('custodyBranch').value = '';
        }
        // فرع التكلفة للصرف العادي وللمورد — السلفة والعهدة ليهم موظف/فرع محدد أصلاً
        const cb = document.getElementById('disburseCostBranchRow');
        cb.style.display = (t === 'normal' || t === 'supplier') ? '' : 'none';
        if (t !== 'normal' && t !== 'supplier') cb.querySelector('select').value = '';
        // الملاحظات للصرف العادي وللمورد
        const nb = document.getElementById('disburseNotesRow');
        nb.style.display = (t === 'normal' || t === 'supplier') ? '' : 'none';
        if (t !== 'normal' && t !== 'supplier') nb.querySelector('textarea').value = '';
    }
    function filterSupps(){
        var inp = document.getElementById('suppSearch');
        if (inp && inp._fgFilter) { inp._fgFilter(); return; }
        const q = window.fgNorm ? window.fgNorm(inp ? inp.value : '') : (inp ? inp.value : '').trim().toLowerCase();
        document.querySelectorAll('.supp-opt').forEach(o => {
            const name = window.fgNorm ? window.fgNorm(o.dataset.name || '') : (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }
    function pickSupp(el){
        document.getElementById('suppId').value = el.dataset.id;
        document.getElementById('suppChosen').textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.supp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
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
        const accRow = document.getElementById('receiptAccountRow');
        if (accRow) accRow.style.display = (t === 'normal') ? '' : 'none';
        document.getElementById('recSupplierBox').style.display = (t === 'supplier') ? 'block' : 'none';
        document.getElementById('recAdvanceBox').style.display = (t === 'advance') ? 'block' : 'none';
        document.getElementById('recCustodyBox').style.display = (t === 'custody') ? 'block' : 'none';
        if (t !== 'supplier') { document.getElementById('recSuppId').value=''; document.getElementById('recSuppChosen').textContent=''; }
        if (t !== 'advance') { document.getElementById('recEmpId').value=''; document.getElementById('recEmpChosen').textContent=''; }
        if (t !== 'custody') { document.getElementById('recCustodyBranch').value=''; }
        // فرع التكلفة للاستلام العادي وللمورد
        const cb = document.getElementById('receiptCostBranchRow');
        cb.style.display = (t === 'normal' || t === 'supplier') ? '' : 'none';
        if (t !== 'normal' && t !== 'supplier') cb.querySelector('select').value = '';
        // نفس سبب الصرف — الملاحظات للاستلام العادي وللمورد
        const nb = document.getElementById('receiptNotesRow');
        nb.style.display = (t === 'normal' || t === 'supplier') ? '' : 'none';
        if (t !== 'normal' && t !== 'supplier') nb.querySelector('textarea').value = '';
    }
    function filterRecSupps(){
        var inp = document.getElementById('recSuppSearch');
        if (inp && inp._fgFilter) { inp._fgFilter(); return; }
        const q = window.fgNorm ? window.fgNorm(inp ? inp.value : '') : (inp ? inp.value : '').trim().toLowerCase();
        document.querySelectorAll('.rec-supp-opt').forEach(o => {
            const name = window.fgNorm ? window.fgNorm(o.dataset.name || '') : (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }
    function pickRecSupp(el){
        document.getElementById('recSuppId').value = el.dataset.id;
        document.getElementById('recSuppChosen').textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.rec-supp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
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
    let jeCurrentDate = '';
    let jeCurrentRefType = '';

    function jeOpen(btn){
        jeCurrentId = parseInt(btn.dataset.id, 10) || 0;
        if (!jeCurrentId) return;

        jeCurrentDate = btn.dataset.date || '';
        jeCurrentRefType = btn.dataset.reftype || '';

        const err = document.getElementById('jeError');
        err.style.display = 'none';
        err.textContent = '';

        if (JE_CAN_ED) {
            const dateInput = document.getElementById('jeDate');
            const dateHint  = document.getElementById('jeDateHint');
            if (dateInput) {
                dateInput.value = jeCurrentDate;
                const canChangeDate = (jeCurrentRefType === 'receipt' || jeCurrentRefType === 'disbursement')
                    && btn.dataset.reversed !== '1'
                    && jeCurrentRefType !== 'reversal';
                dateInput.disabled = !canChangeDate;
                if (dateHint) {
                    if (btn.dataset.reversed === '1' || jeCurrentRefType === 'reversal') {
                        dateHint.textContent = 'الحركة دي اتعكست — مينفعش تغيّر تاريخها.';
                        dateHint.style.display = 'block';
                    } else {
                        dateHint.textContent = 'تغيير التاريخ متاح لحركات الصرف والاستلام اليدوي بس. باقي الحركات بتعدّل من الموديول بتاعها.';
                        dateHint.style.display = canChangeDate ? 'none' : 'block';
                    }
                }
            }
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

            const dateInput = document.getElementById('jeDate');
            if (dateInput && !dateInput.disabled) {
                const newDate = dateInput.value.trim();
                if (!newDate) {
                    err.textContent = 'التاريخ مايصحّش يكون فاضي.';
                    err.style.display = 'block';
                    return;
                }
                if (newDate !== jeCurrentDate) {
                    const msg = 'تغيير التاريخ هيعمل قيد عكسي بالتاريخ القديم (' + jeCurrentDate + ') '
                              + 'وقيد جديد بالتاريخ الجديد (' + newDate + ').\n'
                              + 'الصف القديم هيفضل ظاهر في الكشف مع علامة «معدّل».\n\n'
                              + 'متأكد إنك عايز تغيّر التاريخ؟';
                    if (!confirm(msg)) {
                        return;
                    }
                }
                payload.date = newDate;
            }
        }

        fgBusy(btn, true, 'جارِ الحفظ…');
        try {
            const res  = await fetch('treasury.php?ajax=je_edit', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'حصل خطأ.');

            if (data.date_changed) {
                location.reload();
                return;
            }

            jeApplyRow(data);
            closeModal('jeModal');
        } catch (ex) {
            err.textContent = ex.message || 'الاتصال فشل — حاول تاني.';
            err.style.display = 'block';
        } finally {
            fgBusy(btn, false);
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
                                   + 'color:var(--accent-text);background:var(--bg);';
            } else {
                span.textContent = '—';
                span.style.cssText = 'color:var(--ink-400);';
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
                note.innerHTML = '<span class="trs-dash">—</span>';
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

    async function jeReverse(btn) {
        const id = parseInt(btn.dataset.id, 10) || 0;
        if (!id) return;
        const type = btn.dataset.type || '';
        const op = (type === 'disbursement') ? 'صرف' : 'استلام';
        const revOp = (type === 'disbursement') ? 'استلام' : 'صرف';
        const amt = btn.dataset.amount || '';
        const tre = btn.dataset.treasury || '';
        const dt = btn.dataset.date || '';

        const msg = 'عكس ' + op + ' ' + amt + ' ج من «' + tre + '»؟\n'
                  + 'هيتعمل ' + revOp + ' بنفس المبلغ بتاريخ ' + dt + '.';
        if (!confirm(msg)) return;

        fgBusy(btn, true, 'جاري الإلغاء...');
        try {
            const res = await fetch('treasury.php?ajax=je_reverse', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: id, csrf: JE_CSRF })
            });
            const data = await res.json();
            if (data.ok) {
                location.reload();
            } else {
                fgToast(data.error || 'حدث خطأ أثناء عكس القيد.', 'err');
                fgBusy(btn, false);
            }
        } catch (e) {
            fgToast('تعذر الاتصال بالسيرفر، يرجى المحاولة مرة أخرى.', 'err');
            fgBusy(btn, false);
        }
    }
</script>

<script>
/* =====================================================================
   كروت الكشف تمشي مع الفلتر — RULES.md قسم ٦
   ---------------------------------------------------------------------
   الصفوف الظاهرة ← الجدول ← الكروت. الكارت بيجمع من الـDOM، فمستحيل
   يقول رقم والجدول اللي تحته يقول تاني.

   🔴 بس فيه تفصيلة هنا مش موجودة في أي شاشة تانية:
      الجدول ده **مقصوص** — بيعرض آخر ٣٠٠ حركة بس، والكروت من السيرفر
      محسوبة على **الفترة كلها** (وفيه رسالة تحت بتقول كده).
      فلو جمعنا الظاهر على طول، الشاشة هتفتح من غير أي فلتر وتقول
      إجمالي ٣٠٠ حركة بدل الشهر كله — وده أوحش من المشكلة الأصلية.

      عشان كده: فيه فلتر شغّال ← اجمع الظاهر ووري السطر التحذيري.
                 مفيش فلتر     ← رجّع رقم السيرفر واخفي السطر.
   ===================================================================== */
(function () {
    function num(txt) {
        var v = parseFloat(String(txt).replace(/,/g, '').replace(/[^\d.\-]/g, ''));
        return isNaN(v) ? 0 : v;
    }
    function fmt(v) {
        return v.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    /* بنكتب في العقدة النصية الأولى بس عشان <span>ج.م</span> مايتمسحش */
    function setVal(el, v) {
        /* 🔴 الرقم بقى جوه <bdi> عشان إشارة السالب ماتتقلبش. لازم نحدّثه
           هو بالذات — لو عدّينا على el.textContent كنا هنمسح وحدة العملة
           اللي جنبه، والكارت يفضل رقم من غير «ج.م» بعد أول فلترة. */
        var bdi = el.querySelector('bdi');
        if (bdi) { bdi.textContent = fmt(v); return; }
        if (el.firstChild && el.firstChild.nodeType === 3) el.firstChild.nodeValue = fmt(v) + ' ';
        else el.textContent = fmt(v);
    }

    /* الكروت متكررة (كل الخزائن / خزينة محددة) — بنربط كل بلوك
       بالجدول اللي بعده، عشان الاتنين يشتغلوا من غير id مكرر. */
    var blocks = document.querySelectorAll('.st-cards');
    for (var b = 0; b < blocks.length; b++) {
        (function (box) {
            var note = box.parentNode.querySelector('.st-filter-note');
            var table = null, n = box.nextElementSibling;
            while (n) { if (n.tagName === 'TABLE') { table = n; break; }
                        table = n.querySelector && n.querySelector('table');
                        if (table) break; n = n.nextElementSibling; }
            if (!table) return;

            var cells = {};
            var scs = box.querySelectorAll('[data-sc]');
            for (var i = 0; i < scs.length; i++) cells[scs[i].dataset.sc] = scs[i];

            /* أرقام الأعمدة بالاسم مش بالعد */
            var dIdx = -1, cIdx = -1, ths = table.querySelectorAll('thead th');
            for (var t = 0; t < ths.length; t++) {
                var h = ths[t].textContent.trim();
                if (h === 'مدين') dIdx = t;
                if (h === 'دائن') cIdx = t;
            }
            if (dIdx < 0 || cIdx < 0) return;

            document.addEventListener('xtable:filter', function (ev) {
                /* 🔴 الحدث بيطلع لفوق والصفحة فيها أكتر من جدول */
                if (!ev.detail || ev.detail.table !== table) return;

                if (!ev.detail.active) {
                    /* الفلتر اتقفل — رجّع رقم السيرفر (الفترة كلها) */
                    for (var k in cells) if (cells.hasOwnProperty(k)) {
                        setVal(cells[k], parseFloat(cells[k].dataset.server) || 0);
                    }
                    if (note) note.style.display = 'none';
                    return;
                }

                var d = 0, c = 0, rows = ev.detail.rows;
                for (var r = 0; r < rows.length; r++) {
                    d += num(rows[r].cells[dIdx] ? rows[r].cells[dIdx].textContent : '');
                    c += num(rows[r].cells[cIdx] ? rows[r].cells[cIdx].textContent : '');
                }
                if (cells.debit)  setVal(cells.debit, d);
                if (cells.credit) setVal(cells.credit, c);
                if (cells.net)    setVal(cells.net, d - c);

                if (note) {
                    var fc = note.querySelector('.st-fc');
                    if (fc) fc.textContent = ev.detail.shown + ' من ' + ev.detail.total;
                    note.style.display = '';
                }
            });
        })(blocks[b]);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
