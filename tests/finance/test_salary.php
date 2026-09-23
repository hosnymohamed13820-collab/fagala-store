<?php
/**
 * test_salary.php — اختبارات آلية لحساب المرتب (بريف ٤٧)
 *
 * بيشتغل على قاعدة Laragon المحلية.
 * بيوثّق السلوك الفعلي لدالة compute_salary().
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

// قراءة الإعدادات الحالية من جدول settings
$toleranceSetting = setting('custody_deficit_tolerance', '30');
$carrySetting     = setting('cashier_deficit_carry', '1');
$tolerance        = custody_tolerance();
$carryEnabled     = cashier_deficit_carry_enabled();

echo "إعدادات عجز الكاشير الحالية:\n";
echo "  • custody_deficit_tolerance: {$toleranceSetting} (المعتمد: {$tolerance})\n";
echo "  • cashier_deficit_carry: {$carrySetting} (المعتمد: " . ($carryEnabled ? 'مفعّل' : 'معطّل') . ")\n\n";

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

$month = '2026-06';

$pdo->beginTransaction();

try {
    // -----------------------------------------------------------------
    // ٤٧٠٢ — الموظف الأساسي بساعات منتظمة
    // أساسي 9000 · منتظرة 240 · حضور 192 ساعة (24 يوم × 8)
    // المتوقع: أيام عمل 24 · إجازة 4 · استحقاق 28 · صافي 8400
    // -----------------------------------------------------------------
    $empId4702 = 990002;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4702}, 'موظف اختبار ٤٧٠٢', 9000, 240, 'hours', 1)");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4702}, {$empId4702}, '2026-06-05', 192, 'present')");

    $res4702 = compute_salary($empId4702, $month);
    $ok4702 = num_eq((float)$res4702['work_days'], 24.0)
           && num_eq((float)$res4702['leave_days'], 4.0)
           && num_eq((float)$res4702['due_days'], 28.0)
           && num_eq((float)$res4702['net_salary'], 8400.0);
    assert_case(
        '٤٧٠٢ — موظف بساعات منتظمة وحضور 192 ساعة',
        $ok4702,
        "متوقع (أيام عمل: 24، إجازة: 4، استحقاق: 28، صافي: 8400) لقيت (أيام عمل: {$res4702['work_days']}، إجازة: {$res4702['leave_days']}، استحقاق: {$res4702['due_days']}، صافي: {$res4702['net_salary']})"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٣ — نفس ٤٧٠٢ بس expected_monthly_hours = NULL
    // المتوقع: no_expected = true · صافي 0
    // -----------------------------------------------------------------
    $empId4703 = 990003;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4703}, 'موظف اختبار ٤٧٠٣', 9000, NULL, 'hours', 1)");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4703}, {$empId4703}, '2026-06-05', 192, 'present')");

    $res4703 = compute_salary($empId4703, $month);
    $ok4703 = ($res4703['no_expected'] === true) && num_eq((float)$res4703['net_salary'], 0.0);
    assert_case(
        '٤٧٠٣ — موظف بدون ساعات منتظرة (no_expected)',
        $ok4703,
        "متوقع (no_expected: true، صافي: 0) لقيت (no_expected: " . ($res4703['no_expected'] ? 'true' : 'false') . "، صافي: {$res4703['net_salary']})"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٤ — salary_mode = 'fixed' أساسي 6000 من غير حضور
    // المتوقع: صافي 6000
    // -----------------------------------------------------------------
    $empId4704 = 990004;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4704}, 'موظف اختبار ٤٧٠٤', 6000, NULL, 'fixed', 1)");

    $res4704 = compute_salary($empId4704, $month);
    $ok4704 = num_eq((float)$res4704['net_salary'], 6000.0);
    assert_case(
        '٤٧٠٤ — موظف مرتب ثابت (fixed)',
        $ok4704,
        "متوقع صافي 6000 لقيت {$res4704['net_salary']}"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٥ — ٤٧٠٢ + سلفة pending مبلغ 1000 قسط 300 + سجل الموظف debit 1000
    // المتوقع: سلف مخصومة 300 · صافي 8100
    // -----------------------------------------------------------------
    $empId4705 = 990005;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4705}, 'موظف اختبار ٤٧٠٥', 9000, 240, 'hours', 1)");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4705}, {$empId4705}, '2026-06-05', 192, 'present')");
    $pdo->exec("INSERT INTO employee_advances (id, employee_id, advance_date, amount, monthly_deduction, remaining, adv_type, status)
                VALUES ({$empId4705}, {$empId4705}, '2026-06-01', 1000, 300, 1000, 'monthly', 'pending')");
    $pdo->exec("INSERT INTO employee_ledger (id, employee_id, entry_date, move_type, direction, amount, description)
                VALUES ({$empId4705}, {$empId4705}, '2026-06-01', 'advance', 'debit', 1000, 'سلفة اختبار')");

    $res4705 = compute_salary($empId4705, $month);
    $ok4705 = num_eq((float)$res4705['total_advances'], 300.0) && num_eq((float)$res4705['net_salary'], 8100.0);
    assert_case(
        '٤٧٠٥ — سلفة بقسط 300 مع رصيد سجل مدين',
        $ok4705,
        "متوقع (سلف: 300، صافي: 8100) لقيت (سلف: {$res4705['total_advances']}، صافي: {$res4705['net_salary']})"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٦ — ٤٧٠٥ بس سجل الموظف credit 55978 (الموظف ليه فلوس)
    // المتوقع: سقف السلف صفر ← مخصوم 0 · صافي 8400 (مش 64,378 ولا أكبر من 8400)
    // -----------------------------------------------------------------
    $empId4706 = 990006;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4706}, 'موظف اختبار ٤٧٠٦', 9000, 240, 'hours', 1)");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4706}, {$empId4706}, '2026-06-05', 192, 'present')");
    $pdo->exec("INSERT INTO employee_advances (id, employee_id, advance_date, amount, monthly_deduction, remaining, adv_type, status)
                VALUES ({$empId4706}, {$empId4706}, '2026-06-01', 1000, 300, 1000, 'monthly', 'pending')");
    $pdo->exec("INSERT INTO employee_ledger (id, employee_id, entry_date, move_type, direction, amount, description)
                VALUES ({$empId4706}, {$empId4706}, '2026-06-01', 'salary', 'credit', 55978, 'رصيد دائن للموظف')");

    $res4706 = compute_salary($empId4706, $month);
    $ok4706 = num_eq((float)$res4706['total_advances'], 0.0) && num_eq((float)$res4706['net_salary'], 8400.0);
    assert_case(
        '٤٧٠٦ — حماية رصيد السجل الدائن (سقف السلف صفر ولا يجمع على الصافي)',
        $ok4706,
        "متوقع (سلف: 0، صافي: 8400) لقيت (سلف: {$res4706['total_advances']}، صافي: {$res4706['net_salary']})"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٧ — ٤٧٠٢ + جزاء pending 10000
    // المتوقع: جزاء مخصوم 8400 · صافي 0 · carry_to_advance = 1600
    // -----------------------------------------------------------------
    $empId4707 = 990007;
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active)
                VALUES ({$empId4707}, 'موظف اختبار ٤٧٠٧', 9000, 240, 'hours', 1)");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4707}, {$empId4707}, '2026-06-05', 192, 'present')");
    $pdo->exec("INSERT INTO employee_penalties (id, employee_id, amount, penalty_date, reason, status)
                VALUES ({$empId4707}, {$empId4707}, 10000, '2026-06-15', 'جزاء اختبار كبير', 'pending')");

    $res4707 = compute_salary($empId4707, $month);
    $ok4707 = num_eq((float)$res4707['total_penalties'], 8400.0)
           && num_eq((float)$res4707['net_salary'], 0.0)
           && num_eq((float)$res4707['carry_to_advance'], 1600.0);
    assert_case(
        '٤٧٠٧ — جزاء يتجاوز الاستحقاق (تصفير المرتب وترحيل الباقي لسلفة)',
        $ok4707,
        "متوقع (جزاء مخصوم: 8400، صافي: 0، مرحّل: 1600) لقيت (جزاء مخصوم: {$res4707['total_penalties']}، صافي: {$res4707['net_salary']}، مرحّل: {$res4707['carry_to_advance']})"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٨ — ٤٧٠٢ + وردية كاشير difference = -(tolerance + 50)
    // الكود الحالي بيحسب عجز الكاشير = ABS(difference) بالكامل = tolerance + 50
    // -----------------------------------------------------------------
    $userId4708 = 990008;
    $empId4708  = 990008;
    $shiftId4708 = 990008;
    $diff4708   = -($tolerance + 50.0);
    $expectedDeficitCalc = round($tolerance + 50.0, 2);

    $pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role_id, is_active)
                VALUES ({$userId4708}, 'cashier4708', 'test_hash', 'كاشير اختبار ٤٧٠٨', 1, 1)");
    $pdo->exec("INSERT INTO employees (id, full_name, base_salary, expected_monthly_hours, salary_mode, is_active, user_id)
                VALUES ({$empId4708}, 'موظف كاشير ٤٧٠٨', 9000, 240, 'hours', 1, {$userId4708})");
    $pdo->exec("INSERT INTO attendance (id, employee_id, att_date, work_hours, status)
                VALUES ({$empId4708}, {$empId4708}, '2026-06-05', 192, 'present')");
    $pdo->exec("INSERT INTO shifts (id, user_id, branch_id, business_date, shift_name, status, difference)
                VALUES ({$shiftId4708}, {$userId4708}, 1, '2026-06-10', 'صباحية', 'approved', {$diff4708})");

    $res4708 = compute_salary($empId4708, $month);
    echo "  [٤٧٠٨ توثيق]: difference = {$diff4708} | tolerance = {$tolerance} | cashier_deficits_calc = {$res4708['cashier_deficits_calc']} | total_cashier_deficits = {$res4708['total_cashier_deficits']}\n";

    $ok4708 = num_eq((float)$res4708['cashier_deficits_calc'], $expectedDeficitCalc)
           && num_eq((float)$res4708['total_cashier_deficits'], $expectedDeficitCalc)
           && num_eq((float)$res4708['net_salary'], 8400.0 - $expectedDeficitCalc);
    assert_case(
        '٤٧٠٨ — عجز كاشير متجاوز العتبة (توثيق خصم كامل الفرق)',
        $ok4708,
        "متوقع عجز محسوب {$expectedDeficitCalc} وصافي " . (8400.0 - $expectedDeficitCalc) . " لقيت محسوب {$res4708['cashier_deficits_calc']} وصافي {$res4708['net_salary']}"
    );

    // -----------------------------------------------------------------
    // ٤٧٠٩ — ٤٧٠٨ + cashierTakeOverride = 5000 + جزاء 3000 (بريف ٣٩)
    // فحص ما إذا كان بريف ٣٩ معمول له كوميت
    // -----------------------------------------------------------------
    $gitLog = shell_exec('git log --oneline -n 100 2>&1') ?? '';
    $brief39Committed = (strpos($gitLog, 'بريف ٣٩') !== false);

    if ($brief39Committed) {
        $pdo->exec("INSERT INTO employee_penalties (id, employee_id, amount, penalty_date, reason, status)
                    VALUES ({$empId4708}, {$empId4708}, 3000, '2026-06-15', 'جزاء اختبار ٤٧٠٩', 'pending')");

        $res4709 = compute_salary($empId4708, $month, null, null, null, null, null, null, 5000.0);

        // عجز مخصوم = المحسوب بس ($expectedDeficitCalc) لأن cashierTakeOverride 5000 يسقفه المحسوب ($expectedDeficitCalc)
        // الجزاء 3000 مخصوم كامل
        // الصافي = 8400 - 3000 - $expectedDeficitCalc
        // carry_to_advance = 0 (لا يقل عن 0)
        $expectedNet4709 = 8400.0 - 3000.0 - $expectedDeficitCalc;
        $ok4709 = num_eq((float)$res4709['total_cashier_deficits'], $expectedDeficitCalc)
               && num_eq((float)$res4709['total_penalties'], 3000.0)
               && num_eq((float)$res4709['net_salary'], $expectedNet4709)
               && num_eq((float)$res4709['carry_to_advance'], 0.0);
        assert_case(
            '٤٧٠٩ — سقف عجز الكاشير مع cashierTakeOverride وجزاء (بريف ٣٩)',
            $ok4709,
            "متوقع (عجز كاشير: {$expectedDeficitCalc}، جزاء: 3000، صافي: {$expectedNet4709}، مرحّل: 0) لقيت (عجز كاشير: {$res4709['total_cashier_deficits']}، جزاء: {$res4709['total_penalties']}، صافي: {$res4709['net_salary']}، مرحّل: {$res4709['carry_to_advance']})"
        );
    } else {
        echo "⏭️ ٤٧٠٩ — مستنية بريف ٣٩\n";
    }

} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n";
if ($failCount === 0) {
    echo "✅ كل اختبارات المرتب نجحت ({$passCount})\n";
    exit(0);
} else {
    echo "❌ فشل {$failCount} من أصل " . ($passCount + $failCount) . " اختبار\n";
    exit(1);
}

