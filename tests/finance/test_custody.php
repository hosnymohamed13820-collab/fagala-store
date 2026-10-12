<?php
/**
 * test_custody.php — اختبارات آلية لمنطق العهدة وإذن الصرف والمراجعة (بريف ٥٥)
 *
 * بيشتغل على قاعدة Laragon المحلية.
 * بيوثّق السلوك الفعلي لدوال العهدة:
 *   - issue_disbursement_order()
 *   - issue_employee_disbursement_order()
 *   - add_custody_expense()
 *   - delete_custody_expense()
 *   - submit_custody_count()
 *   - save_custody_review()
 *   - receive_custody()
 *
 * كل الداتا المدخلة بـ id >= 990000 وجوّه transaction بيترجع في الآخر في finally.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$laragonWww = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
if (!file_exists($laragonWww . '/config.php')) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/config.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/finance.php';

$passCount = 0;
$failCount = 0;

function num_eq(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

function assert_case(string $name, bool $cond, string $details = ''): void
{
    global $passCount, $failCount;
    if ($cond) {
        echo "✅ {$name}\n";
        $passCount++;
    } else {
        echo "❌ {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
        $failCount++;
    }
}

function get_t_bal(PDO $pdo, int $tId): float
{
    $st = $pdo->prepare("SELECT balance FROM treasuries WHERE id = ?");
    $st->execute([$tId]);
    return (float) $st->fetchColumn();
}

function count_new_j(PDO $pdo, int $sinceId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE id > ?");
    $st->execute([$sinceId]);
    return (int) $st->fetchColumn();
}

function get_c_row(PDO $pdo, int $cId): array
{
    $st = $pdo->prepare("SELECT * FROM branch_custody WHERE id = ?");
    $st->execute([$cId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

$pdo->beginTransaction();

try {
    $mainTreasuryId = require_main_treasury();

    // اختيار فرع حقيقي متاح (ليس لديه عهدة open أو pending_review أو rejected) وله خزينة عهدة
    $stBranch = $pdo->query(
        "SELECT b.id AS branch_id, b.name AS branch_name, t.id AS treasury_id
         FROM branches b
         JOIN treasuries t ON t.branch_id = b.id AND t.type = 'branch_custody'
         WHERE b.id NOT IN (
             SELECT DISTINCT branch_id FROM branch_custody 
             WHERE status IN ('open', 'pending_review', 'rejected') AND branch_id IS NOT NULL
         )
         ORDER BY b.id ASC LIMIT 1"
    );
    $branchRow = $stBranch->fetch(PDO::FETCH_ASSOC);

    if (!$branchRow) {
        // لو كله مشغول نعمل فرع وهمي id >= 990000 وخزينة له
        $branchId = 990001;
        $pdo->prepare("INSERT INTO branches (id, name, is_active) VALUES (?, 'فرع اختبار عهدة ٥٥', 1)")->execute([$branchId]);
        $pdo->prepare(
            "INSERT INTO treasuries (id, name, type, branch_id, balance, is_active)
             VALUES (990001, 'عهدة فرع اختبار ٥٥', 'branch_custody', ?, 0.00, 1)"
        )->execute([$branchId]);
        $custodyTreasuryId = 990001;
    } else {
        $branchId = (int) $branchRow['branch_id'];
        $custodyTreasuryId = (int) $branchRow['treasury_id'];
    }

    $startJournalId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM journal_entries")->fetchColumn();
    $mainBal0 = get_t_bal($pdo, $mainTreasuryId);
    $custBal0 = get_t_bal($pdo, $custodyTreasuryId);

    // -----------------------------------------------------------------
    // 1) إذن صرف 1000 ← عهدة جديدة open أول مدتها 1000 · الرئيسية -1000 والعهدة +1000 · قيدين بالظبط
    // -----------------------------------------------------------------
    $order1Id = issue_disbursement_order($branchId, $custodyTreasuryId, 1000.0, '2026-09-24', 'إذن عهدة ١', 1);

    $stC = $pdo->prepare("SELECT id FROM branch_custody WHERE branch_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stC->execute([$branchId]);
    $custodyId = (int) $stC->fetchColumn();

    $cRow1 = get_c_row($pdo, $custodyId);
    $mainBal1 = get_t_bal($pdo, $mainTreasuryId);
    $custBal1 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ1 = count_new_j($pdo, $startJournalId);

    $okStep1 = ($custodyId > 0)
        && ($cRow1['status'] === 'open')
        && num_eq((float)$cRow1['opening_balance'], 1000.0)
        && num_eq((float)$cRow1['total_expenses'], 0.0)
        && num_eq((float)$cRow1['closing_balance'], 1000.0)
        && num_eq($mainBal1, $mainBal0 - 1000.0)
        && num_eq($custBal1, $custBal0 + 1000.0)
        && ($newJ1 === 2);

    assert_case(
        '٥٥٥١ — إذن صرف 1000: عهدة جديدة open وأول مدة 1000 وتحريك الخزائن وقيدين',
        $okStep1,
        "عهدة: {$custodyId}، حالة: {$cRow1['status']}، أول: {$cRow1['opening_balance']}، رئيسية: {$mainBal1}، عهدة: {$custBal1}، قيود: {$newJ1}"
    );

    // -----------------------------------------------------------------
    // 2) إذن تاني 500 ← نفس العهدة، أول المدة 1500، مفيش صف branch_custody جديد
    // -----------------------------------------------------------------
    $jPoint1 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    $order2Id = issue_disbursement_order($branchId, $custodyTreasuryId, 500.0, '2026-09-24', 'إذن عهدة ٢', 1);

    $stCCount = $pdo->prepare("SELECT COUNT(*) FROM branch_custody WHERE branch_id = ? AND status = 'open'");
    $stCCount->execute([$branchId]);
    $openCustodyCount = (int) $stCCount->fetchColumn();

    $cRow2 = get_c_row($pdo, $custodyId);
    $mainBal2 = get_t_bal($pdo, $mainTreasuryId);
    $custBal2 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ2 = count_new_j($pdo, $jPoint1);

    $okStep2 = ($openCustodyCount === 1)
        && num_eq((float)$cRow2['opening_balance'], 1500.0)
        && num_eq((float)$cRow2['total_expenses'], 0.0)
        && num_eq((float)$cRow2['closing_balance'], 1500.0)
        && num_eq($mainBal2, $mainBal1 - 500.0)
        && num_eq($custBal2, $custBal1 + 500.0)
        && ($newJ2 === 2);

    assert_case(
        '٥٥٥٢ — إذن صرف ثانٍ 500: نفس العهدة وأول المدة زاد لـ 1500 وقيدين',
        $okStep2,
        "عدد العهدات: {$openCustodyCount}، أول المدة: {$cRow2['opening_balance']}، قيود: {$newJ2}"
    );

    // -----------------------------------------------------------------
    // 3) مصروف 200 ← total_expenses 200 · closing 1300 · مسحه ← رجع 0
    // -----------------------------------------------------------------
    $jPoint2 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    $expLineId = add_custody_expense($custodyId, '2026-09-24', 'مصروف تجربة ١', 200.0, 1);

    $cRow3 = get_c_row($pdo, $custodyId);
    $custBal3 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ3 = count_new_j($pdo, $jPoint2);

    $okStep3 = num_eq((float)$cRow3['total_expenses'], 200.0)
        && num_eq((float)$cRow3['closing_balance'], 1300.0)
        && num_eq($custBal3, $custBal2 - 200.0)
        && ($newJ3 === 1);

    assert_case(
        '٥٥٥٣ — إضافة مصروف 200: تحديث المصروفات لـ 200 وآخر المدة 1300 وخصم من خزينة العهدة',
        $okStep3,
        "مصروفات: {$cRow3['total_expenses']}، آخر المدة: {$cRow3['closing_balance']}، رصيد العهدة: {$custBal3}"
    );

    // مسح المصروف
    $jPoint3 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    delete_custody_expense($expLineId, 1);

    $cRow4 = get_c_row($pdo, $custodyId);
    $custBal4 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ4 = count_new_j($pdo, $jPoint3);

    $okStep4 = num_eq((float)$cRow4['total_expenses'], 0.0)
        && num_eq((float)$cRow4['closing_balance'], 1500.0)
        && num_eq($custBal4, $custBal2)
        && ($newJ4 === 1);

    assert_case(
        '٥٥٥٤ — مسح المصروف: عودة المصروفات لـ 0 وآخر المدة لـ 1500 وقيد عكسي',
        $okStep4,
        "مصروفات: {$cRow4['total_expenses']}، آخر المدة: {$cRow4['closing_balance']}، رصيد العهدة: {$custBal4}، قيود: {$newJ4}"
    );

    // -----------------------------------------------------------------
    // 4) تقديم (submit_custody_count) ← pending_review · إذن صرف ❌ «مقدّمة للمراجعة» · مصروف ❌
    // -----------------------------------------------------------------
    $jPoint4 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    submit_custody_count($custodyId, 1500.0, '2026-09-24', null, 1);

    $cRow5 = get_c_row($pdo, $custodyId);
    $newJ5 = count_new_j($pdo, $jPoint4);

    $okStep5 = ($cRow5['status'] === 'pending_review') && ($newJ5 === 0);
    assert_case('٥٥٥٥ — تقديم العهدة: تحول الحالة لـ pending_review بدون أي قيود مالية', $okStep5, "حالة: {$cRow5['status']}، قيود: {$newJ5}");

    // محاولة إذن صرف على عهدة مقدمة للمراجعة ❌
    $orderPendingBlocked = false;
    $orderPendingMsg = '';
    try {
        issue_disbursement_order($branchId, $custodyTreasuryId, 100.0, '2026-09-24', 'ممنوع', 1);
    } catch (Throwable $ex) {
        $orderPendingBlocked = true;
        $orderPendingMsg = $ex->getMessage();
    }
    assert_case(
        '٥٥٥٦ — حظر إذن الصرف أثناء وجود عهدة مقدّمة للمراجعة والرسالة فيها مقدّمة للمراجعة',
        $orderPendingBlocked && str_contains($orderPendingMsg, 'مقدّمة للمراجعة'),
        "الرسالة: {$orderPendingMsg}"
    );

    // محاولة إضافة مصروف على عهدة مقدمة للمراجعة ❌
    $expPendingBlocked = false;
    $expPendingMsg = '';
    try {
        add_custody_expense($custodyId, '2026-09-24', 'ممنوع', 50.0, 1);
    } catch (Throwable $ex) {
        $expPendingBlocked = true;
        $expPendingMsg = $ex->getMessage();
    }
    assert_case('٥٥٥٧ — حظر إضافة مصروف على عهدة مقدّمة للمراجعة', $expPendingBlocked, "الرسالة: {$expPendingMsg}");

    // -----------------------------------------------------------------
    // 5) رفض (save_custody_review(…,'rejected','سبب')) ← rejected · صفر قيود جديدة · رفض من غير سبب ❌
    // -----------------------------------------------------------------
    $noReasonBlocked = false;
    try {
        save_custody_review($custodyId, 1500.0, 'rejected', '', 1);
    } catch (Throwable) {
        $noReasonBlocked = true;
    }
    assert_case('٥٥٥٨ — رفض العهدة بدون سبب يرمي استثناء ويُمنع', $noReasonBlocked);

    $jPoint5 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    save_custody_review($custodyId, 1500.0, 'rejected', 'سبب الرفض للاختبار ٥٥', 1);

    $cRow6 = get_c_row($pdo, $custodyId);
    $newJ6 = count_new_j($pdo, $jPoint5);

    $okStep6 = ($cRow6['status'] === 'rejected') && ($newJ6 === 0);
    assert_case('٥٥٥٩ — رفض العهدة بسبب: تحول لـ rejected وصفر قيود مالية جديدة', $okStep6, "حالة: {$cRow6['status']}، قيود: {$newJ6}");

    // -----------------------------------------------------------------
    // 6) على المرفوضة:
    //    - مصروف ✅
    //    - إذن صرف 300 ✅ نفس الصف، أول المدة زاد 300، الحالة لسه rejected (بريف ٥٣)
    //    - receive_custody 200 ← أول المدة نقص 200
    // -----------------------------------------------------------------
    // مصروف 100 على المرفوضة
    $jPoint6 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    $lineRejId = add_custody_expense($custodyId, '2026-09-24', 'مصروف على مرفوضة', 100.0, 1);
    $cRow7 = get_c_row($pdo, $custodyId);
    $custBal7 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ7 = count_new_j($pdo, $jPoint6);

    $okStep7a = num_eq((float)$cRow7['total_expenses'], 100.0)
        && num_eq((float)$cRow7['closing_balance'], 1400.0)
        && num_eq($custBal7, $custBal4 - 100.0)
        && ($newJ7 === 1);
    assert_case('٥٥٦٠ — إضافة مصروف على العهدة المرفوضة مسموح ويحدّث الإجماليات وقيد واحد', $okStep7a);

    // مسح المصروف على المرفوضة لاختبار المسح وتصفير التدفق الخارجي
    $jPoint6Del = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    delete_custody_expense($lineRejId, 1);
    $cRow7Del = get_c_row($pdo, $custodyId);
    $okStep7aDel = num_eq((float)$cRow7Del['total_expenses'], 0.0) && num_eq((float)$cRow7Del['closing_balance'], 1500.0);
    assert_case('٥٥٦١ — مسح المصروف على العهدة المرفوضة مسموح ويرجع المصروفات لـ 0', $okStep7aDel);

    // إذن صرف 300 على المرفوضة
    $jPoint7 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    $order3Id = issue_disbursement_order($branchId, $custodyTreasuryId, 300.0, '2026-09-24', 'تغذية عهدة مرفوضة', 1);
    $cRow8 = get_c_row($pdo, $custodyId);
    $custBal8 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ8 = count_new_j($pdo, $jPoint7);

    $okStep7b = ($cRow8['status'] === 'rejected')
        && num_eq((float)$cRow8['opening_balance'], 1800.0)
        && num_eq((float)$cRow8['closing_balance'], 1800.0)
        && num_eq($custBal8, $custBal4 + 300.0)
        && ($newJ8 === 2);
    assert_case('٥٥٦٢ — إذن صرف 300 على المرفوضة: نفس الصف وأول المدة 1800 والحالة لسه rejected (بريف ٥٣)', $okStep7b, "حالة: {$cRow8['status']}، أول: {$cRow8['opening_balance']}، آخر: {$cRow8['closing_balance']}");

    // رد عهدة 200 للخزينة الرئيسية
    $jPoint8 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    receive_custody($branchId, 200.0, $mainTreasuryId, '2026-09-24', 'رد عهدة من الفرع', 1);
    $cRow9 = get_c_row($pdo, $custodyId);
    $mainBal9 = get_t_bal($pdo, $mainTreasuryId);
    $custBal9 = get_t_bal($pdo, $custodyTreasuryId);
    $newJ9 = count_new_j($pdo, $jPoint8);

    $okStep7c = num_eq((float)$cRow9['opening_balance'], 1600.0)
        && num_eq((float)$cRow9['closing_balance'], 1600.0)
        && num_eq($custBal9, $custBal8 - 200.0)
        && ($newJ9 === 2);
    assert_case('٥٥٦٣ — رد عهدة 200: أول المدة نقص لـ 1600 وقيدين (خروج من العهدة ودخول الرئيسية)', $okStep7c, "أول: {$cRow9['opening_balance']}، آخر: {$cRow9['closing_balance']}، قيود: {$newJ9}");

    // -----------------------------------------------------------------
    // 7) تقديم تاني + اعتماد (approved) بـ accountantCounted مختلف عن رقم الكاشير ← closed
    //    القيد بيتعمل برقم المحاسب (accountant_counted) ورقم الكاشير (counted_cash) متخزّن زي ما هو.
    // -----------------------------------------------------------------
    // الكاشير عدّ 1590
    submit_custody_count($custodyId, 1590.0, '2026-09-24', null, 1);

    // المحاسب استلم 1600 (المفروض 1600، رصيد العهدة 1600، فالاستلام 1600 يصفّر العهدة تماماً)
    $jPoint9 = (int) $pdo->query("SELECT MAX(id) FROM journal_entries")->fetchColumn();
    save_custody_review($custodyId, 1600.0, 'approved', null, 1);

    $cRow10 = get_c_row($pdo, $custodyId);
    $custBal10 = get_t_bal($pdo, $custodyTreasuryId);

    $okStep8 = ($cRow10['status'] === 'closed')
        && num_eq((float)$cRow10['counted_cash'], 1590.0)
        && num_eq((float)$cRow10['accountant_counted'], 1600.0)
        && num_eq($custBal10, 0.0);

    assert_case(
        '٥٥٦٤ — اعتماد العهدة: إقفال closed برقم المحاسب (1600) وحفظ رقم الكاشير (1590) وتصفير العهدة',
        $okStep8,
        "حالة: {$cRow10['status']}، كاشير: {$cRow10['counted_cash']}، محاسب: {$cRow10['accountant_counted']}، رصيد العهدة: {$custBal10}"
    );

    // -----------------------------------------------------------------
    // 8) عهدة موظف (issue_employee_disbursement_order):
    //    نفس أول ٣ حالات + حارس التكرار: نفس المبلغ لنفس الموظف مرتين ورا بعض ← التانية ❌ «منذ لحظات»
    // -----------------------------------------------------------------
    $empId = 990055;
    $pdo->prepare(
        "INSERT INTO employees (id, full_name, branch_id, is_active) VALUES (?, 'موظف عهدة اختبار ٥٥', ?, 1)"
    )->execute([$empId, $branchId]);

    // إذن صرف أول للموظف 1000
    $empOrder1 = issue_employee_disbursement_order($empId, 1000.0, '2026-09-24', 'عهدة موظف ١', 1);

    $stEmpC = $pdo->prepare("SELECT * FROM branch_custody WHERE employee_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stEmpC->execute([$empId]);
    $empCustRow1 = $stEmpC->fetch(PDO::FETCH_ASSOC);
    $empCustId = (int) $empCustRow1['id'];
    $empTreasuryId = (int) $empCustRow1['custody_treasury_id'];

    $okEmpStep1 = ($empCustId > 0)
        && ($empCustRow1['status'] === 'open')
        && num_eq((float)$empCustRow1['opening_balance'], 1000.0)
        && num_eq((float)$empCustRow1['closing_balance'], 1000.0);
    assert_case('٥٥٦٤ — عهدة موظف: إذن صرف 1000 يفتح عهدة جديدة للموظف بحالة open ورصيد 1000', $okEmpStep1);

    // حارس منع التكرار اللحظي: نفس المبلغ لنفس الموظف مرتين ورا بعض ❌
    $dupBlocked = false;
    $dupMsg = '';
    try {
        issue_employee_disbursement_order($empId, 1000.0, '2026-09-24', 'تكرار نفس المبلغ', 1);
    } catch (Throwable $ex) {
        $dupBlocked = true;
        $dupMsg = $ex->getMessage();
    }
    assert_case(
        '٥٥٦٥ — حارس التكرار اللحظي لعهدة الموظف: رفض تكرار نفس المبلغ لنفس الموظف والرسالة فيها «منذ لحظات»',
        $dupBlocked && str_contains($dupMsg, 'منذ لحظات'),
        "الرسالة: {$dupMsg}"
    );

    // إذن صرف بمبلغ مختلف 500 للموظف ✅ (نفس العهدة، أول المدة 1500)
    $empOrder2 = issue_employee_disbursement_order($empId, 500.0, '2026-09-24', 'عهدة موظف ٢ مبلغ مختلف', 1);
    $empCustRow2 = get_c_row($pdo, $empCustId);
    $okEmpStep2 = num_eq((float)$empCustRow2['opening_balance'], 1500.0)
        && num_eq((float)$empCustRow2['closing_balance'], 1500.0);
    assert_case('٥٥٦٦ — عهدة موظف: إذن صرف 500 بمبلغ مختلف ينجح ويزيد أول المدة لـ 1500', $okEmpStep2);

    // مصروف 200 على عهدة الموظف ومسحه
    $empLine1 = add_custody_expense($empCustId, '2026-09-24', 'انتقالات موظف', 200.0, 1);
    $empCustRow3 = get_c_row($pdo, $empCustId);
    $okEmpStep3 = num_eq((float)$empCustRow3['total_expenses'], 200.0) && num_eq((float)$empCustRow3['closing_balance'], 1300.0);
    assert_case('٥٥٦٧ — عهدة موظف: إضافة مصروف 200 يحدّث المصروفات وآخر المدة لـ 1300', $okEmpStep3);

    delete_custody_expense($empLine1, 1);
    $empCustRow4 = get_c_row($pdo, $empCustId);
    $okEmpStep4 = num_eq((float)$empCustRow4['total_expenses'], 0.0) && num_eq((float)$empCustRow4['closing_balance'], 1500.0);
    assert_case('٥٥٦٨ — عهدة موظف: مسح المصروف يعيد المصروفات لـ 0 وآخر المدة لـ 1500', $okEmpStep4);

    // -----------------------------------------------------------------
    // 9) في الآخر: مجموع (credit − debit) لكل القيود الجديدة = صفر على مستوى كل الخزاين (الفلوس بتتنقل مش بتتخلق)
    // -----------------------------------------------------------------
    $stBalAll = $pdo->prepare("SELECT SUM(credit) - SUM(debit) FROM journal_entries WHERE id > ?");
    $stBalAll->execute([$startJournalId]);
    $netMoneyFlow = (float) $stBalAll->fetchColumn();

    $okZeroBalance = abs($netMoneyFlow) < 0.005;
    assert_case(
        '٥٥٦٩ — توازن القيود المحاسبية: مجموع (credit - debit) لكل القيود الجديدة = 0.00 بالتمام',
        $okZeroBalance,
        "الفرق الإجمالي: {$netMoneyFlow}"
    );

    // -----------------------------------------------------------------
    // 10) بريف 112: سلف الموظفين ودفعات الموردين من عهدة الموظف
    // -----------------------------------------------------------------
    $empAdvBeneficiaryId = 990113;
    $pdo->prepare(
        "INSERT INTO employees (id, full_name, branch_id, is_active) VALUES (?, 'موظف مستلف اختبار ١١٢', ?, 1)"
    )->execute([$empAdvBeneficiaryId, $branchId]);

    // تجهيز مورد وحسابه في الشجرة
    $supp112Id = 990112;
    $pdo->prepare(
        "INSERT INTO suppliers (id, name, is_active) VALUES (?, 'مورد اختبار ١١٢', 1)"
    )->execute([$supp112Id]);

    $stSuppParent = $pdo->query("SELECT id FROM accounts WHERE dynamic_src = 'suppliers' LIMIT 1");
    $suppParentId = (int) $stSuppParent->fetchColumn();
    $suppAccId = 990112;
    $pdo->prepare(
        "INSERT INTO accounts (id, parent_id, name, type, is_active, src_table, src_id)
         VALUES (?, ?, 'مورد اختبار ١١٢', 'liability', 1, 'suppliers', ?)"
    )->execute([$suppAccId, $suppParentId ?: null, $supp112Id]);

    // عهدة موظف جديدة نظيفة لاختبار سيناريو بريف 112
    $empCustOwnerId = 990114;
    $pdo->prepare(
        "INSERT INTO employees (id, full_name, branch_id, is_active) VALUES (?, 'مسؤول عهدة اختبار ١١٢', ?, 1)"
    )->execute([$empCustOwnerId, $branchId]);

    // الحالة 1: إذن صرف 1000 لعهدة الموظف
    issue_employee_disbursement_order($empCustOwnerId, 1000.0, '2026-10-12', 'عهدة بريف 112', 1);
    $stC112 = $pdo->prepare("SELECT * FROM branch_custody WHERE employee_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stC112->execute([$empCustOwnerId]);
    $c112Row = $stC112->fetch(PDO::FETCH_ASSOC);
    $c112Id = (int) $c112Row['id'];

    $ok112_1 = ($c112Id > 0) && num_eq((float)$c112Row['closing_balance'], 1000.0);
    assert_case('١١٢٠١ — عهدة موظف: إذن صرف 1000 يفتح عهدة برصيد 1000', $ok112_1);

    // الحالة 7: محاولة صرف سلفة على عهدة فرع ← ترمي Exception
    $branchCustodyBlocked = false;
    try {
        add_custody_expense($custodyId, '2026-10-12', 'سلفة غير مسموحة', 100.0, 1, null, 'advance', $empAdvBeneficiaryId);
    } catch (Throwable $e) {
        $branchCustodyBlocked = true;
    }
    assert_case('١١٢٠٧ — حارس عهدة الفرع: رفض صرف سلفة لموظف من عهدة الفرع', $branchCustodyBlocked);

    // الحالة 8: محاولة صرف لموظف أو مورد غير موجود ← ترمي Exception
    $missingEntitiesBlocked = false;
    try {
        add_custody_expense($c112Id, '2026-10-12', 'موظف وهمي', 100.0, 1, null, 'advance', 888888);
    } catch (Throwable $e) {
        try {
            add_custody_expense($c112Id, '2026-10-12', 'مورد وهمي', 100.0, 1, null, 'supplier', null, 888888);
        } catch (Throwable $e2) {
            $missingEntitiesBlocked = true;
        }
    }
    assert_case('١١٢٠٨ — حراس التحقق: رفض صرف لموظف أو مورد غير موجود', $missingEntitiesBlocked);

    // الحالة 2: صرف سلفة لموظف تاني (200 ج) من العهدة
    $mainBalBeforeAdv = get_t_bal($pdo, $mainTreasuryId);
    $advLine1 = add_custody_expense($c112Id, '2026-10-12', '', 200.0, 1, null, 'advance', $empAdvBeneficiaryId);
    $c112Row2 = get_c_row($pdo, $c112Id);
    $mainBalAfterAdv = get_t_bal($pdo, $mainTreasuryId);

    $stAdvCheck = $pdo->prepare("SELECT COUNT(*) FROM employee_advances WHERE employee_id = ?");
    $stAdvCheck->execute([$empAdvBeneficiaryId]);
    $advCount = (int) $stAdvCheck->fetchColumn();

    $stLedgerCheck = $pdo->prepare("SELECT COUNT(*) FROM employee_ledger WHERE employee_id = ?");
    $stLedgerCheck->execute([$empAdvBeneficiaryId]);
    $ledgerCount = (int) $stLedgerCheck->fetchColumn();

    $stLineRow = $pdo->prepare("SELECT * FROM custody_lines WHERE id = ?");
    $stLineRow->execute([$advLine1]);
    $advLineRow = $stLineRow->fetch(PDO::FETCH_ASSOC);

    $ok112_2 = num_eq((float)$c112Row2['closing_balance'], 800.0)
        && num_eq($mainBalAfterAdv, $mainBalBeforeAdv)
        && ($advCount === 0)
        && ($ledgerCount === 0)
        && (($advLineRow['line_type'] ?? '') === 'advance')
        && ((int)$advLineRow['employee_id'] === $empAdvBeneficiaryId)
        && str_contains($advLineRow['description'], 'موظف مستلف');
    assert_case('١١٢٠٢ — عهدة موظف: صرف سلفة 200 لموظف ينزل رصيد العهدة لـ 800 دون المساس بالخزينة الرئيسية أو شؤون العاملين', $ok112_2);

    // الحالة 3: صرف دفعة لمورد (300 ج) من العهدة
    $mainBalBeforeSupp = get_t_bal($pdo, $mainTreasuryId);
    $suppLine1 = add_custody_expense($c112Id, '2026-10-12', '', 300.0, 1, null, 'supplier', null, $supp112Id);
    $c112Row3 = get_c_row($pdo, $c112Id);
    $mainBalAfterSupp = get_t_bal($pdo, $mainTreasuryId);

    $stLineSupp = $pdo->prepare("SELECT * FROM custody_lines WHERE id = ?");
    $stLineSupp->execute([$suppLine1]);
    $suppLineRow = $stLineSupp->fetch(PDO::FETCH_ASSOC);

    $ok112_3 = num_eq((float)$c112Row3['closing_balance'], 500.0)
        && num_eq($mainBalAfterSupp, $mainBalBeforeSupp)
        && (($suppLineRow['line_type'] ?? '') === 'supplier')
        && ((int)$suppLineRow['supplier_id'] === $supp112Id)
        && ((int)$suppLineRow['entry_code_id'] === $suppAccId)
        && str_contains($suppLineRow['description'], 'مورد اختبار');
    assert_case('١١٢٠٣ — عهدة موظف: صرف دفعة لمورد 300 ينزل رصيد العهدة لـ 500 ويربط بحساب الشجرة دون المساس بالخزينة الرئيسية', $ok112_3);

    // الحالة 4: مسح سلفة قبل التقديم
    delete_custody_expense($advLine1, 1);
    $c112Row4 = get_c_row($pdo, $c112Id);
    $ok112_4 = num_eq((float)$c112Row4['closing_balance'], 700.0) && num_eq((float)$c112Row4['total_expenses'], 300.0);
    assert_case('١١٢٠٤ — عهدة موظف: مسح سلفة قبل التقديم يعيد رصيد العهدة لـ 700', $ok112_4);

    // الحالة 5: إعادة صرف السلفة (200 ج) + تقديم العهدة للجرد
    $advLine2 = add_custody_expense($c112Id, '2026-10-12', '', 200.0, 1, null, 'advance', $empAdvBeneficiaryId);
    $c112Row5 = get_c_row($pdo, $c112Id);
    submit_custody_count($c112Id, 500.0, date('Y-m-d'), 1);
    $c112Row5Sub = get_c_row($pdo, $c112Id);
    $ok112_5 = num_eq((float)$c112Row5['closing_balance'], 500.0)
        && ($c112Row5Sub['status'] === 'pending_review')
        && num_eq((float)$c112Row5Sub['counted_cash'], 500.0);
    assert_case('١١٢٠٥ — عهدة موظف: إعادة صرف السلفة وتقديم الجرد يضع العهدة pending_review برصيد 500', $ok112_5);

    // الحالة 6: اعتماد المحاسب للعهدة
    $mainBalBeforeApprove = get_t_bal($pdo, $mainTreasuryId);
    $revResult = save_custody_review($c112Id, 500.0, 'approved', null, 1);
    $c112Row6 = get_c_row($pdo, $c112Id);
    $mainBalAfterApprove = get_t_bal($pdo, $mainTreasuryId);

    // فحص السلفة في employee_advances
    $stAdvApproved = $pdo->prepare("SELECT * FROM employee_advances WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
    $stAdvApproved->execute([$empAdvBeneficiaryId]);
    $advRowApproved = $stAdvApproved->fetch(PDO::FETCH_ASSOC);

    // فحص كشف حساب الموظف في employee_ledger
    $stLedgerApproved = $pdo->prepare("SELECT * FROM employee_ledger WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
    $stLedgerApproved->execute([$empAdvBeneficiaryId]);
    $ledgerRowApproved = $stLedgerApproved->fetch(PDO::FETCH_ASSOC);

    $ok112_6 = ($c112Row6['status'] === 'closed')
        && !empty($advRowApproved)
        && ($advRowApproved['status'] === 'pending')
        && num_eq((float)$advRowApproved['amount'], 200.0)
        && !empty($ledgerRowApproved)
        && ($ledgerRowApproved['direction'] === 'debit')
        && num_eq((float)$ledgerRowApproved['amount'], 200.0)
        && num_eq($mainBalAfterApprove, $mainBalBeforeApprove + 500.0);
    assert_case('١١٢٠٦ — اعتماد المحاسب: اعتماد العهدة ينزل السلفة في employee_advances وقيد مدين في employee_ledger وصافي الكاش بالخزينة', $ok112_6);

} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n";
if ($failCount === 0) {
    echo "✅ كل اختبارات العهدة وإذن الصرف والمراجعة نجحت ({$passCount})\n";
    exit(0);
} else {
    echo "❌ فشل {$failCount} من أصل " . ($passCount + $failCount) . " اختبار\n";
    exit(1);
}
