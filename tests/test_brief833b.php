<?php
declare(strict_types=1);

// اختبار معزول: دوال الإنتاج مع SQLite في الذاكرة، من غير قاعدة أو شبكة حقيقية.
$root = dirname(__DIR__);
require_once $root . '/site/push_lib.php';
$source = file_get_contents($root . '/site/password_reset_lib.php');
$source = preg_replace('/^require_once .*;\R/m', '', $source);
eval(substr($source, 5));
function current_user(): array { return ['id' => $GLOBALS['actorId']]; }

class ResetTestDB extends PDO
{
    public int $permissionQueries = 0;
    public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    private function sql(string $sql): string
    {
        if (str_contains($sql, 'SELECT up.user_id, up.module_key')) $this->permissionQueries++;
        return str_replace(
            [' FOR UPDATE', 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)', 'NOW() - INTERVAL 24 HOUR', 'NOW()'],
            ['', "datetime('now', '+30 minutes')", "datetime('now', '-24 hours')", "datetime('now')"], $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args);
    }
}
function check(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    echo "PASS: $name\n";
}
$pdo = new ResetTestDB();
$pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY, is_owner INTEGER);
    CREATE TABLE users(id INTEGER PRIMARY KEY, role_id INTEGER, is_active INTEGER, full_name TEXT, branch_id INTEGER);
    CREATE TABLE user_permissions(user_id INTEGER, module_key TEXT);
    CREATE TABLE role_permissions(role_id INTEGER, module_key TEXT);
    CREATE TABLE branches(id INTEGER, name TEXT);
    CREATE TABLE password_reset_requests(id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT DEFAULT 'pending', created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        request_ip TEXT, decided_by INTEGER, decided_at TEXT, code_hash TEXT, code_expires_at TEXT, attempts INTEGER);
    CREATE TABLE notifications(user_id INTEGER, type TEXT, title TEXT, body TEXT, link TEXT);
    CREATE TABLE push_queue(user_id INTEGER, title TEXT, body TEXT, link TEXT);
    INSERT INTO roles VALUES(1,0),(2,0),(3,1);
    INSERT INTO users VALUES(1,1,1,'Actor',NULL),(2,2,1,'Target',NULL),(3,3,1,'Owner',NULL);");
$actorId = 1;
function permissions(array $actorRole, array $actorExtra, array $targetRole, array $targetExtra): void
{
    global $pdo;
    $pdo->exec('DELETE FROM role_permissions; DELETE FROM user_permissions; DELETE FROM password_reset_requests');
    // دي بيانات الاختبار بس؛ مسار الإنتاج بيتفحص بعدّاد الاستعلامات تحت.
    foreach ([['role_permissions', 1, $actorRole], ['user_permissions', 1, $actorExtra],
        ['role_permissions', 2, $targetRole], ['user_permissions', 2, $targetExtra]] as [$table, $id, $keys]) {
        foreach ($keys as $key) $pdo->exec("INSERT INTO $table VALUES($id," . $pdo->quote($key) . ')');
    }
    $pdo->exec('INSERT INTO password_reset_requests(id,user_id) VALUES(1,2)');
}
function forbidden(callable $fn): bool
{
    try { $fn(); } catch (RuntimeException $e) { return $e->getCode() === 403; }
    return false;
}
permissions(['hr'], [], ['cashier'], []);
check(forbidden(fn() => pwreset_decide($pdo, 1, true)), '1: hr alone returns 403');
permissions(['cashier'], ['hr:password_reset'], ['cashier'], []);
$before = $pdo->permissionQueries;
$result = pwreset_decide($pdo, 1, true);
check(isset($result['reset_otp']) && $pdo->permissionQueries - $before === 1, '2: cashier subset approved; one permission query');
$notification = $pdo->query('SELECT * FROM notifications')->fetch(PDO::FETCH_ASSOC);
check((int) $notification['user_id'] === 2 && $notification['title'] === 'فيه موافقة على باسورد جديد لحسابك'
    && $notification['body'] === 'لو مش انت اللي طلبت، كلّم المالك فوراً' && $notification['link'] === 'login.php', '6: actual notifications row for target');
permissions(['hr:password_reset'], ['cashier'], ['cashier'], ['treasury']);
check(forbidden(fn() => pwreset_decide($pdo, 1, true)), '3: treasury escalation returns 403');
permissions(['hr', 'cashier'], ['hr:password_reset'], ['cashier'], ['hr:advances']);
check(isset(pwreset_decide($pdo, 1, true)['reset_otp']), '4: hr covers hr:advances');
permissions(['hr:password_reset', 'cashier', 'hr:advances'], [], ['cashier', 'hr'], []);
check(forbidden(fn() => pwreset_decide($pdo, 1, true)), 'extra: hr:advances does not cover hr');
$actorId = 3;
check(isset(pwreset_decide($pdo, 1, true)['reset_otp']), '5: owner can approve broader target');
$pdo->exec("INSERT INTO password_reset_requests(id,user_id) VALUES(2,3)");
check(forbidden(fn() => pwreset_decide($pdo, 2, true)), '5: owner cannot approve self');
$actorId = 1;
foreach (['view_all', 'hr:password_reset'] as $protected) {
    permissions(['hr:password_reset', 'cashier', 'view_all', 'hr'], [], ['cashier', $protected], []);
    check(forbidden(fn() => pwreset_decide($pdo, 1, true)), 'extra: protected target ' . $protected);
}
permissions(['cashier'], ['hr:password_reset'], ['cashier'], []);
$pdo->exec("INSERT INTO password_reset_requests(id,user_id) VALUES(2,2),(3,3)");
$before = $pdo->permissionQueries;
$list = pwreset_list($pdo);
check(count($list['requests']) === 3 && !$list['requests'][0]['can_decide'] && $list['requests'][1]['can_decide']
    && $list['requests'][2]['can_decide'] && $pdo->permissionQueries - $before === 1, 'extra: list batches all targets into one permission query');
permissions(['view_all', 'hr'], [], ['cashier'], []);
$_SERVER['REQUEST_METHOD'] = 'GET';
check(forbidden(fn() => pwreset_manager($pdo)), 'extra: view_all GET does not grant approval');
$api = file_get_contents($root . '/site/password_reset_api.php');
$page = file_get_contents($root . '/site/password_reset.php');
check(str_contains($api, '$input[\'reset_otp\']') && str_contains($page, 'name="reset_otp"')
    && str_contains($page, 'new FormData(form)') && !preg_match('/\x27code\x27|name="code"/', $source . $api . $page), '7: reset_otp API and FormData; no old field');

// بنشغّل جسم الدالة نفسه في namespace مع cURL وshutdown وهميين؛ بنعدّي حارس CLI بس.
foreach (['site', 'ashraqa'] as $dir) {
    $namespace = $dir . 'KickTest';
    $push = file_get_contents($root . '/' . $dir . '/push_lib.php');
    $worker = substr($push, strpos($push, "if (!defined('PUSH_KICK_HOSTS'))"));
    $worker = str_replace("PHP_SAPI === 'cli'", 'false', $worker);
    eval('namespace ' . $namespace . '; $callbacks = []; $urls = [];
        function register_shutdown_function($fn) { global $callbacks; $callbacks[] = $fn; }
        function curl_init() { return true; }
        function curl_setopt_array($ch, $opts) { global $urls; $urls[] = $opts[CURLOPT_URL]; }
        function curl_exec($ch) { return true; }
        function curl_close($ch) {} ' . $worker);
    $fn = $namespace . '\\push_kick_worker';
    $_SERVER['HTTP_HOST'] = 'evil.com';
    $fn();
    check($callbacks === [] && $urls === [], '8 ' . $dir . ': evil.com schedules no callback or curl');
    $_SERVER['HTTP_HOST'] = 'HoSnImAnAgEmEnT.com:443';
    $_SERVER['HTTPS'] = 'on';
    $fn();
    check(count($callbacks) === 1, '8 ' . $dir . ': allowed host schedules callback');
    $callbacks[0]();
    check($urls === ['https://hosnimanagement.com/push_worker.php'], '8 ' . $dir . ': canonical HTTPS host, same worker path');
}
echo "All brief833b checks passed (isolated SQLite and mocked cURL).\n";
