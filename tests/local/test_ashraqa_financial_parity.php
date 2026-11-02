<?php
/** مقارنة الكود الحقيقي القديم والجديد على نفس الداتا. القيود بتتولد في جداول مؤقتة بس. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv) < 3) {
    $repo = dirname(__DIR__,2);
    $runtime = $repo . '/tmp/brief848-financial';
    $variants = [['site','site','ashraqa'],['old','ashraqa','ashraqa'],['fagala','site','fagala'],['baseline','site','fagala']];
    $outputs = [];
    foreach ($variants as [$variant,$source,$database]) {
        $root = $runtime . '/' . $variant . '/' . ($database === 'ashraqa' ? 'ashraqa' : 'fagala');
        $sourceRoot = $repo . '/' . $source;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $relative = substr($file->getPathname(), strlen($sourceRoot)+1);
            if (preg_match('~^(?:config\.php|uploads[/\\\\]|data[/\\\\]|downloads[/\\\\]|logs[/\\\\])~', $relative)) continue;
            $target = $root . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target),0700,true);
            copy($file->getPathname(),$target);
        }
        file_put_contents($root . '/config.php', '<?php function db(): PDO { return $GLOBALS["brief848_pdo"]; } function setting(string $key,$default=null) { return $GLOBALS["brief848_settings"][$key] ?? $default; }');
        if ($variant === 'baseline') {
            foreach (['finance.php','cashier.php'] as $file) {
                $process = proc_open(['git','show','HEAD:site/' . $file],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
                $contents = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process) !== 0) throw new RuntimeException($errors);
                file_put_contents($root . '/' . $file,$contents);
            }
        }
        $process = proc_open([PHP_BINARY,__FILE__,$root,$database],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        $json = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($variant . ': ' . $errors);
        $outputs[$variant] = json_decode($json,true,512,JSON_THROW_ON_ERROR);
        file_put_contents($runtime . '/' . $variant . '.json',$json);
    }
    if ($outputs['site'] !== $outputs['old'] || $outputs['fagala'] !== $outputs['baseline']) throw new RuntimeException('الأرقام أو القيود اختلفت');
    echo "PASS: 3 real Ashraqa shifts + 1 Fagala shift; exact collected/difference/journals; repeated approval/correction; cash policy; October payroll\n";
    exit;
}
$root = $argv[1] ?? '';
$schema = $argv[2] ?? 'ashraqa';
if (!in_array($schema, ['ashraqa','fagala'], true) || !is_file($root . '/config.php')) throw new RuntimeException('إعداد اختبار غير صحيح');
$pdo = new PDO("mysql:host=127.0.0.1;dbname=$schema;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$GLOBALS['brief848_pdo'] = $pdo;
$GLOBALS['brief848_settings'] = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
require $root . '/finance.php';
require $root . '/hr_lib.php';
// نفس reader اللي hr.php بيستعمله، قبل عزل جداول الاختبار المالي.
$employees = hr_get_employees_list($pdo);
$payroll = hr_calc_payroll_data($pdo, $employees, '2026-10-01', '2026-10-31', '2026-10');
if ($schema === 'ashraqa') {
    $shifts = $pdo->query('SELECT s.*, SUM(e.amount) AS expenses FROM shifts s JOIN shift_expenses e ON e.shift_id=s.id GROUP BY s.id ORDER BY s.id DESC LIMIT 3')->fetchAll();
    if (count($shifts) !== 3) throw new RuntimeException('لازم ٣ ورديات حقيقية فيها مصروفات');
} else {
    $shifts = $pdo->query('SELECT s.*, 0 AS expenses FROM shifts s WHERE cash_total>0 AND visa_total>0 AND instapay_total>0 ORDER BY s.id DESC LIMIT 1')->fetchAll();
}
// نسخة مؤقتة بنفس المفاتيح والصفوف، بتختفي مع قفل الاتصال.
$tables = ['shifts','treasuries','journal_entries','shift_reviews','treasury_movements'];
$sql = array_map(static fn($t) => "CREATE TEMPORARY TABLE brief848_$t LIKE `$t`; INSERT INTO brief848_$t SELECT * FROM `$t`; ALTER TABLE brief848_$t RENAME TO `$t`", $tables);
if ($schema === 'ashraqa') $sql[] = 'CREATE TEMPORARY TABLE brief848_shift_expenses LIKE shift_expenses; INSERT INTO brief848_shift_expenses SELECT * FROM shift_expenses; ALTER TABLE brief848_shift_expenses RENAME TO shift_expenses';
$pdo->exec(implode(';', $sql));
$expenseRows = $schema === 'ashraqa' ? $pdo->query('SELECT shift_id,account_id,amount,note FROM shift_expenses ORDER BY id')->fetchAll() : [];
$expenseMap = [];
foreach ($expenseRows as $row) $expenseMap[(int)$row['shift_id']][] = $row;
// بنشغّل كتلة التحصيل الأصلية نفسها من cashier.php، مش معادلة مكتوبة في الاختبار.
$cashierSource = file_get_contents($root . '/cashier.php');
$start = strpos($cashierSource, '// حساب المصروفات النثرية');
if ($start === false || str_contains(substr($cashierSource, 0, $start), "if (site_profile()['shift_expenses_mode']")) {
    $start = strpos($cashierSource, '// الفرق المجمع');
}
$end = strpos($cashierSource, '$pdo->beginTransaction();', $start);
$cashierCalculation = substr($cashierSource, $start, $end-$start);
$ids = implode(',', array_map('intval', array_column($shifts, 'id')));
$pdo->exec("DELETE FROM journal_entries WHERE ref_type IN ('shift','shift_expense') AND ref_id IN ($ids)");
$maxId = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM journal_entries')->fetchColumn();
$results = [];
foreach ($shifts as $shift) {
    $id = (int)$shift['id'];
    $amounts = ['cash'=>(float)$shift['cash_total'],'visa'=>(float)$shift['visa_total'],'instapay'=>(float)$shift['instapay_total'],'system'=>(float)$shift['system_total']];
    $cashTotal = $amounts['cash']; $methodTotals = $amounts; $systemTotal = $amounts['system'];
    $lines = $expenseMap[$id] ?? [];
    $_POST = ['exp_amount'=>array_column($lines,'amount'),'exp_account_id'=>array_column($lines,'account_id'),'exp_note'=>array_column($lines,'note')];
    eval($cashierCalculation);
    save_shift_review($id, $amounts, 'approved', null, 1);
    $afterFirst = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    save_shift_review($id, $amounts, 'approved', null, 1);
    $afterSecond = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    if ($afterFirst !== $afterSecond) throw new RuntimeException('إعادة المراجعة كررت القيود');
    $st = $pdo->prepare('SELECT treasury_id, account_id, debit, credit, description, ref_type, ref_id, notes FROM journal_entries WHERE id > ? AND ref_id=? ORDER BY id');
    $st->execute([$maxId, $id]);
    $firstEntries = $st->fetchAll();
    // التصحيح وإعادة المراجعة بعد تغير إجمالي مصروفات الدرج.
    $amounts['cash'] -= 1.25;
    if ($schema === 'ashraqa') $pdo->exec("UPDATE shift_expenses SET amount=amount+2.35 WHERE shift_id=$id ORDER BY id LIMIT 1");
    save_shift_review($id, $amounts, 'approved', null, 1);
    $st->execute([$maxId, $id]);
    $results[] = ['id'=>$id,'cash'=>$shift['cash_total'],'expenses'=>$shift['expenses'],'visa'=>$shift['visa_total'],'instapay'=>$shift['instapay_total'],'system'=>$shift['system_total'],'collected'=>round($collected,2),'difference'=>round($collected-$amounts['system'],2),'first_entries'=>$firstEntries,'after_correction'=>$st->fetchAll(),'repeat_noop'=>true];
}
$cashId = central_treasury_by_type('cash');
try {
    treasury_movement($cashId, 'disbursement', 0.01, '2026-10-31', 'اختبار معزول', 1);
    $cashDisbursement = 'allowed';
} catch (RuntimeException $error) {
    $cashDisbursement = 'blocked';
}
if ($cashDisbursement !== ($schema === 'ashraqa' ? 'allowed' : 'blocked')) throw new RuntimeException('سياسة صرف الكاش اختلفت');
echo json_encode(['schema'=>$schema,'shifts'=>$results,'payroll'=>$payroll,'cash_disbursement'=>$cashDisbursement],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION) . "\n";
