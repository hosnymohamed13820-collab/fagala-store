<?php
/**
 * اختبار كود إشراقة الحقيقي على جداول MySQL مؤقتة؛ الصرف والـcommit مايلمسوش أي سجل أصلي.
 * php tests/local/test_ashraqa_payroll_parity.php [--schema-fallback]
 * الافتراضي قاعدة ashraqa المحلية. البديل الصريح بيستعير هيكل fagala بس، وده مش اعتماد لقاعدة إشراقة.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$repo = dirname(__DIR__, 2);
$testHost = getenv('ASHRAQA_TEST_HOST') ?: '127.0.0.1';
if (!in_array($testHost, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('الاختبار محلي بس');
$pdo = new PDO('mysql:host=' . $testHost . ';charset=utf8mb4',
    getenv('ASHRAQA_TEST_USER') ?: 'root', getenv('ASHRAQA_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false]);
$hasAshraqa = (bool)$pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='ashraqa'")->fetchColumn();
if (!$hasAshraqa && !in_array('--schema-fallback', $argv, true)) {
    echo "SKIP: قاعدة ashraqa مش موجودة محلياً؛ البديل المعزول محتاج --schema-fallback.\n";
    exit(2);
}
$schema = $hasAshraqa ? 'ashraqa' : 'fagala';
$pdo->exec("USE `$schema`");
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// نفس ملفات إشراقة، مع config اختبار منفصل في مجلد مؤقت. مفيش تغيير في config المشروع.
$runtime = sys_get_temp_dir() . '/brief844-' . bin2hex(random_bytes(8));
mkdir($runtime . '/includes', 0700, true);
$files = ['finance.php', 'push_lib.php', 'errlog_lib.php', 'agent_lib.php', 'auth.php', 'sensitive_lib.php', 'hr_lib.php',
    'hr.php', 'hr_api.php', 'salary_view_lib.php', 'my_salary.php', 'employee_statement.php',
    'my_salary_api.php', 'employee_statement_api.php', 'api_lib.php',
    'includes/header.php', 'includes/footer.php', 'includes/nav_icons.php'];
foreach ($files as $file) copy($repo . '/ashraqa/' . $file, $runtime . '/' . $file);
file_put_contents($runtime . '/config.php', '<?php
function db(): PDO { return $GLOBALS["brief844_pdo"]; }
function setting(string $key, $default = null) { return $GLOBALS["brief844_settings"][$key] ?? $default; }
');
$GLOBALS['brief844_pdo'] = $pdo;
$GLOBALS['brief844_settings'] = ['custody_deficit_tolerance' => '30', 'cashier_deficit_carry' => '1'];

// بناخد تعريف الجداول والمفاتيح من SHOW CREATE، ونشيل foreign keys علشان الجداول المؤقتة.
// كل الكتابة في الجداول المؤقتة بالاتصال ده بس.
$tables = ['employees', 'users', 'attendance', 'employee_advances', 'employee_ledger', 'employee_bonuses',
    'employee_penalties', 'custody_deficits', 'shifts', 'salaries', 'payroll_adjustments',
    'salary_shift_deficits', 'salary_advance_recoveries', 'treasuries', 'journal_entries', 'notifications'];
try {
    $owner = $pdo->query('SELECT * FROM users WHERE id=1')->fetch();
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $show = $pdo->query(implode(';', array_map(static fn(string $t): string => "SHOW CREATE TABLE `$t`", $tables)));
    $ddl = [];
    do {
        $definition = $show->fetch(PDO::FETCH_NUM)[1];
        $definition = preg_replace('/CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition, 1);
        $definition = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $definition);
        $definition = preg_replace('/,\r?\n\)/', "\n)", $definition);
        $ddl[] = $definition;
    } while ($show->nextRowset());
    $show->closeCursor();
    $pdo->exec(implode(';', $ddl));
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $columns = implode(',', array_map(static fn(string $key): string => "`$key`", array_keys($owner)));
    $marks = implode(',', array_fill(0, count($owner), '?'));
    $pdo->prepare("INSERT INTO users ($columns) VALUES ($marks)")->execute(array_values($owner));
    $pdo->exec("INSERT INTO users (id,username,password_hash,full_name,role_id,is_active) VALUES
        (990843,'brief844-c','unused','اختبار ج',1,1),(990844,'brief844-d','unused','اختبار د',1,1)");
    $pdo->exec("INSERT INTO employees (id,user_id,full_name,base_salary,expected_monthly_hours,salary_mode,is_active) VALUES
        (990841,NULL,'اختبار أ',9000,240,'hours',1),(990842,NULL,'اختبار ب',9000,240,'hours',1),
        (990843,990843,'اختبار ج',9000,240,'hours',1),(990844,990844,'اختبار د',9000,240,'hours',1),
        (990845,NULL,'اختبار خصومات',9000,240,'hours',1),(990846,NULL,'اختبار ترحيل',9000,240,'hours',1)");
    $pdo->exec("INSERT INTO attendance (employee_id,att_date,work_hours,status) VALUES
        (990841,'2026-06-05',192,'present'),(990842,'2026-06-05',192,'present'),
        (990843,'2026-06-05',192,'present'),(990844,'2026-06-05',192,'present'),
        (990845,'2026-06-05',192,'present'),(990846,'2026-06-05',192,'present')");
    $pdo->exec("INSERT INTO employee_penalties (employee_id,amount,penalty_date,status) VALUES
        (990845,125.25,'2026-06-01','pending'),(990846,8500,'2026-06-01','pending')");
    $pdo->exec("INSERT INTO custody_deficits (custody_id,branch_id,employee_id,amount,status) VALUES
        (990845,1,990845,80.10,'pending'),(990846,1,990846,75.50,'pending')");
    $pdo->exec("INSERT INTO employee_advances (employee_id,amount,remaining,monthly_deduction,adv_type,advance_date,status)
        VALUES (990842,10000,10000,0,'monthly','2026-06-01','pending')");
    $pdo->exec("INSERT INTO employee_ledger (employee_id,entry_date,direction,amount,move_type,description)
        VALUES (990842,'2026-06-01','debit',10000,'advance','سلفة اختبار')");
    $pdo->exec("INSERT INTO shifts (user_id,branch_id,business_date,shift_name,status,difference) VALUES
        (990843,1,'2026-06-10','80','approved',-4504.50),(990844,1,'2026-06-10','81','approved',-4504.50)");
    $pdo->exec("INSERT INTO payroll_adjustments (employee_id,month,cashier_take)
        VALUES (990843,'2026-06',0),(990844,'2026-06',0)");
    $pdo->exec("INSERT INTO treasuries (id,name,type,balance,is_active) VALUES (990844,'خزينة اختبار','cash',100000,1)");

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/hr.php';
    $_SERVER['REQUEST_URI'] = '/hr.php?tab=salaries';
    session_save_path($runtime);
    session_start();
    $_SESSION['user'] = ['id' => 1, 'username' => $owner['username'], 'full_name' => $owner['full_name'],
        'role_id' => $owner['role_id'], 'is_owner' => 1, 'perms_version' => $owner['perms_version'],
        'pw_version' => $owner['pw_version'] ?? 1, 'branch_id' => null];
    $_SESSION['app'] = 'ashraqa';
    $_SESSION['sens_until'] = time() + 600;
    require $runtime . '/auth.php';
    require $runtime . '/hr_lib.php';

    $eq = static function (string $label, float $actual, float $expected): void {
        if ((int)round($actual * 100) !== (int)round($expected * 100)) {
            throw new RuntimeException("$label: لقيت {$actual}، المتوقع $expected");
        }
    };
    $cases = [
        ['أ',990841,true,8400.0,8400.0,0.0,0.0],
        ['ب',990842,true,0.0,-1600.0,8400.0,0.0],
        ['ج',990843,true,8400.0,8400.0,0.0,4504.50],
        ['د',990844,false,8400.0,8400.0,0.0,0.0],
        ['خصومات',990845,true,8194.65,8194.65,0.0,0.0],
        ['ترحيل جزاءات وعهدة',990846,true,0.0,-175.50,0.0,175.50],
    ];
    $results = [];
    foreach ($cases as [$label,$empId,$carry,$net,$display,$advance,$standing]) {
        $GLOBALS['brief844_settings']['cashier_deficit_carry'] = $carry ? '1' : '0';
        $emps = array_values(array_filter(hr_get_employees_list($pdo), static fn(array $e): bool => (int)$e['id'] === $empId));
        $row = hr_calc_payroll_data($pdo, $emps, '2026-06-01', '2026-06-30', '2026-06')[0];
        $cashierOverride = in_array($empId, [990843,990844], true) ? 0.0 : null;
        $calc = compute_salary($empId, '2026-06', '2026-06-01', '2026-06-30', null, null, null, null, $cashierOverride);
        $mapping = ['advances'=>'total_advances','penalties'=>'total_penalties','deficits'=>'total_deficits',
            'cashier_deficit'=>'total_cashier_deficits','net_salary'=>'net_salary','carry_to_advance'=>'carry_to_advance'];
        foreach ($mapping as $screen => $computed) $eq("$label/$screen", (float)$row[$screen], (float)$calc[$computed]);
        $eq("$label/net", (float)$row['net_salary'], $net);
        $eq("$label/display", (float)$row['display_net'], $display);
        $eq("$label/advance", (float)$row['advances'], $advance);
        $eq("$label/carry", (float)$row['carry_to_advance'], $standing);
        if ($empId === 990845) {
            $eq('خصومات/جزاءات', (float)$row['penalties'], 125.25);
            $eq('خصومات/عهدة', (float)$row['deficits'], 80.10);
        }

        // بنشغّل الصرف نفسه، مش الحساب بس؛ الـcommit بيعدّل جداول مؤقتة حصراً.
        $salaryId = pay_salary($empId, '2026-06', 990844, '2026-06-30', 1,
            '2026-06-01', '2026-06-30', null, null, null, null, $cashierOverride);
        $paid = $pdo->query("SELECT * FROM salaries WHERE id=$salaryId")->fetch();
        foreach ($mapping as $screen => $computed) {
            if ($computed !== 'carry_to_advance') $eq("$label/paid/$computed", (float)$paid[$computed], (float)$row[$screen]);
        }
        $standingRows = $pdo->query("SELECT amount,remaining FROM employee_advances WHERE employee_id=$empId AND adv_type='standing' AND notes='باقي خصومات من مرتب شهر 2026-06'")->fetchAll();
        $eq("$label/standing", array_sum(array_column($standingRows,'amount')), $standing);
        if (count($standingRows) !== ($standing > 0 ? 1 : 0)) throw new RuntimeException("$label: عدد السلف المرحلة غلط");
        if (in_array($empId, [990843,990844], true)) {
            $eq("$label/cashier_calc", (float)$row['cashier_deficit_calc'], 4504.50);
            $eq("$label/ledger", emp_ledger_balance($empId, $pdo), $standing);
            $eq("$label/shift_link", (float)$pdo->query("SELECT COUNT(*) FROM salary_shift_deficits WHERE employee_id=$empId")->fetchColumn(), 1);
        }
        if ($empId === 990842) $eq('ب/remaining', (float)$pdo->query("SELECT remaining FROM employee_advances WHERE employee_id=990842")->fetchColumn(), 1600);
        $eq("$label/treasury", (float)$pdo->query("SELECT COALESCE(SUM(debit-credit),0) FROM journal_entries WHERE ref_type='salary' AND ref_id=$salaryId")->fetchColumn(), $net);
        $results[] = ['case'=>$label,'advances'=>$row['advances'],'penalties'=>$row['penalties'],'custody'=>$row['deficits'],
            'cashier'=>$row['cashier_deficit'],'net'=>$row['net_salary'],'display_net'=>$row['display_net'],
            'carry'=>$row['carry_to_advance'],'paid'=>(float)$paid['net_salary']];
    }
    // رندر نفس hr.php بالأونر، من غير استبدال auth أو header بدوال وهمية.
    $_GET = ['tab'=>'salaries','from'=>'2026-06-01','to'=>'2026-06-30','month'=>'2026-06'];
    ob_start();
    require $runtime . '/hr.php';
    $html = ob_get_clean();
    if (!str_contains($html, 'sal-tbl') || !str_contains($html, 'batchPayrollForm')) throw new RuntimeException('رندر المرتبات ناقص');
    if (getenv('ASHRAQA_TEST_HTML')) file_put_contents(getenv('ASHRAQA_TEST_HTML'), $html);
    echo ($hasAshraqa ? 'ASHRAQA' : 'SCHEMA_FALLBACK: قاعدة ashraqa مش موجودة؛ هيكل fagala بجداول مؤقتة بس'), "\n";
    foreach ($results as $result) echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), "\n";
    echo 'PASS: الأربع حالات وحالتين خصومات إضافيين والصرف الفعلي متطابقين بالقرش؛ رندر الأونر ', strlen($html), " بايت من غير Fatal.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // قفل الاتصال بيمسح كل الجداول المؤقتة؛ مفيش DELETE لأي سجل مالي.
    $GLOBALS['brief844_pdo'] = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionFile = $runtime . '/sess_' . session_id();
        session_write_close();
        if (is_file($sessionFile)) unlink($sessionFile);
    }
    foreach ($files as $file) if (is_file($runtime . '/' . $file)) unlink($runtime . '/' . $file);
    if (is_file($runtime . '/config.php')) unlink($runtime . '/config.php');
    rmdir($runtime . '/includes');
    rmdir($runtime);
}
