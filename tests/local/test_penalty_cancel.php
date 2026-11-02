<?php
/** اختبار محلي بس: POST الحقيقي ودوال الحساب والإشعار على جداول مؤقتة فاضية. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? 'site';
if (!in_array($root, ['site', 'ashraqa'], true)) throw new RuntimeException('اختار الموقع صح.');
$repo = dirname(__DIR__, 2);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=fagala;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$runtime = sys_get_temp_dir() . '/brief845-' . bin2hex(random_bytes(8));
mkdir($runtime, 0700, true);
$files = ['auth.php', 'finance.php', 'hr_lib.php', 'sensitive_lib.php', 'push_lib.php', 'errlog_lib.php', 'agent_lib.php'];
if ($root === 'site') $files[] = 'reqlog_lib.php';
foreach ($files as $file) copy("$repo/$root/$file", "$runtime/$file");
file_put_contents("$runtime/config.php", '<?php
function db(): PDO { return $GLOBALS["brief845_pdo"]; }
function setting(string $key, $default = null) { return $default; }
');
$GLOBALS['brief845_pdo'] = $pdo;
$tables = ['employee_penalties', 'employees', 'users', 'employee_bonuses', 'employee_advances',
    'employee_ledger', 'custody_deficits', 'shifts', 'salary_shift_deficits', 'notifications', 'push_queue'];
$show = $pdo->query(implode(';', array_map(static fn($t) => "SHOW CREATE TABLE `$t`", $tables)));
$ddl = [];
do {
    $sql = $show->fetch(PDO::FETCH_NUM)[1];
    $sql = preg_replace('/CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql, 1);
    $sql = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $sql);
    $ddl[] = preg_replace('/,\r?\n\)/', "\n)", $sql);
} while ($show->nextRowset());
$show->closeCursor();
$pdo->exec(implode(';', $ddl));
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->exec("INSERT INTO users (id,username,password_hash,full_name,role_id,is_active) VALUES (990845,'brief845','unused','موظف اختبار',1,1)");
$pdo->exec("INSERT INTO employees (id,user_id,full_name,base_salary,expected_monthly_hours,salary_mode,is_active) VALUES (990845,990845,'موظف اختبار',9000,240,'fixed',1)");
$pdo->exec("INSERT INTO employee_penalties (id,employee_id,amount,penalty_date,reason,status,deducted_in_salary_id) VALUES
    (1,990845,50,'2026-11-02','جزاء اختبار','pending',NULL),
    (2,990845,50,'2026-11-02','جزاء اختبار','pending',NULL),
    (3,990845,50,'2026-11-02','جزاء اختبار','deducted',123),
    (4,990845,50,'2026-11-02','جزاء اختبار','pending',NULL),
    (5,990845,50,'2026-11-02','جزاء اختبار','pending',NULL)");
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/hr.php?tab=penalties';
session_save_path($runtime);
require "$runtime/auth.php";
require "$runtime/hr_lib.php";
$_SESSION['user'] = ['id'=>1, 'is_owner'=>1];
$_SESSION['permissions'] = ['hr:penalties'];
$_SESSION['csrf'] = 'brief845-local-token';

// نفس معالج POST بالنص؛ بنوقف قبل تحويل PRG عشان نقدر نفحص الفلاش ونكرّر الطلب.
$page = file_get_contents("$repo/$root/hr.php");
$start = strpos($page, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {');
$stop = strpos($page, '    // PRG:', $start);
if ($start === false || $stop === false) throw new RuntimeException('مش لاقي معالج POST.');
$postCode = substr($page, $start, $stop - $start) . "\n}";
$send = static function (int $id, ?string $notify, string $reason = 'اتسجل بالغلط', bool $validCsrf = true) use ($pdo, $postCode): array {
    $uid = 1; $tab = 'penalties'; $flash = ''; $flashType = 'success';
    $_POST = ['action'=>'cancel_penalty', 'penalty_id'=>(string)$id, 'reason'=>$reason,
        'csrf'=>$validCsrf ? $_SESSION['csrf'] : 'wrong'];
    if ($notify !== null) $_POST['notify'] = $notify;
    eval($postCode);
    return [$flashType, $flash];
};
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
};
$row = static function (int $id) use ($pdo): array {
    $q = $pdo->prepare('SELECT * FROM employee_penalties WHERE id=?'); $q->execute([$id]); return $q->fetch();
};
$counts = static fn() => $pdo->query('SELECT (SELECT COUNT(*) FROM notifications), (SELECT COUNT(*) FROM push_queue), (SELECT COUNT(*) FROM employee_ledger)')->fetch(PDO::FETCH_NUM);
$check($send(1, '0')[0] === 'success', 'فشل إلغاء من غير إشعار');
$check($row(1)['status'] === 'cancelled' && $row(1)['cancel_reason'] === 'اتسجل بالغلط'
    && (int)$row(1)['cancelled_by'] === 1 && $row(1)['cancelled_at'] !== null, 'بيانات الإلغاء ناقصة');
$check($counts() === [0,0,0], 'من غير إشعار كتب إشعار أو قيد');
$check($send(2, '1')[0] === 'success' && $counts() === [1,1,0], 'الإشعار مش صف واحد');
$notice = $pdo->query('SELECT user_id,type,title,body,link FROM notifications')->fetch();
$check((int)$notice['user_id'] === 990845 && $notice['title'] === 'اتلغى جزاء'
    && $notice['body'] === 'جزاء بـ50.00 ج اتلغى' && $notice['link'] === 'my_salary.php', 'محتوى الإشعار غلط');
$before = $row(3);
$res = $send(3, '1');
$check($res[0] === 'error' && str_contains($res[1], 'الجزاء ده اتخصم في مرتب. إرجاع المرتب الأول وبعدين الإلغاء') && $row(3) === $before, 'المخصوم اتغيّر');
$check($send(1, '1')[0] === 'error' && $counts() === [1,1,0], 'تكرار الإلغاء بعت إشعار');
$check($send(4, null, '   ')[0] === 'error' && $row(4)['status'] === 'pending', 'سبب فاضي عدى');
$check($send(4, '1', 'سبب', false)[0] === 'error' && $row(4)['status'] === 'pending', 'CSRF غلط عدى');
$_SESSION['user']['is_owner'] = 0;
$check($send(4, '1')[0] === 'error' && $row(4)['status'] === 'pending', 'موظف معاه صلاحية الجزاءات قدر يلغي');
$_SESSION['user']['is_owner'] = 1;
$check($send(4, null)[0] === 'success' && $counts() === [1,1,0], 'غياب notify بعت إشعار');
$check($send(5, 'true')[0] === 'success' && $counts() === [1,1,0], 'قيمة notify غير 1 بعتت إشعار');
$calc = compute_salary(990845, '2026-11', '2026-11-01', '2026-11-30');
$check((float)$calc['total_penalties'] === 0.0, 'الجزاء الملغي اتحسب في المرتب');
$finance = file_get_contents("$repo/$root/finance.php");
preg_match('/\$penUpd = \$pdo->prepare\(\'([^\']+)\'\)/', $finance, $reverseMatch);
$check(!empty($reverseMatch[1]), 'مش لاقي تحديث الجزاءات في إرجاع المرتب');
$reverse = $pdo->prepare($reverseMatch[1]);
$reverse->execute([123]);
$check($row(1)['status'] === 'cancelled' && $row(2)['status'] === 'cancelled'
    && $row(3)['status'] === 'pending' && $row(3)['deducted_in_salary_id'] === null, 'إرجاع المرتب رجّع الملغي');
$check((int)$pdo->query("SELECT COUNT(*) FROM employee_penalties WHERE status='cancelled'")->fetchColumn() === 4, 'عدد الملغي غلط');
// MySQL مش بيسمح بإعادة فتح نفس الجدول المؤقت في UNION؛ فحص استعلام القايمة قراية بس على الاتصال الأصلي.
$listPdo = new PDO('mysql:host=127.0.0.1;dbname=fagala;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$rows = hr_get_penalties($listPdo, 100);
$check($rows === [] || array_key_exists('cancel_reason', $rows[0]), 'حقول القايمة ناقصة');
$check($counts() === [1,1,0], 'الإشعارات أو السجل اتغيّروا');
echo "$root: PASS — POST notify=0/1/absent/invalid, deducted, repeat, reason, CSRF, non-owner, compute_salary, reverse SQL, deductions; writes to temporary tables only.\n";
