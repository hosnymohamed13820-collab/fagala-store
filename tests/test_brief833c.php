<?php
declare(strict_types=1);

// اختبار معزول لدوال الإنتاج والـAPI والرندر؛ مفيش قاعدة حقيقية ولا شبكة.
ob_start();
session_start();
$root = dirname(__DIR__);
require_once $root . '/site/push_lib.php';
foreach (['account_lib', 'password_reset_lib'] as $lib) {
    $source = preg_replace('/^require_once .*;\R/m', '', file_get_contents($root . '/site/' . $lib . '.php'));
    eval(substr($source, 5));
}
class SelfResetDB extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    private function sql(string $sql): string
    {
        return str_replace(
            ["GET_LOCK('fagala_pwreset_request', 5)", "RELEASE_LOCK('fagala_pwreset_request')", ' FOR UPDATE',
                'DATE_ADD(NOW(), INTERVAL 30 MINUTE)', 'NOW() - INTERVAL 24 HOUR', 'NOW() - INTERVAL 1 HOUR', 'NOW()', 'IF('],
            ['1', '1', '', "datetime('now', '+30 minutes')", "datetime('now', '-24 hours')", "datetime('now', '-1 hour')", "datetime('now')", 'IIF('], $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args);
    }
}
class ApiReply extends Exception
{
    public function __construct(public array $payload, public int $status = 200) { parent::__construct(); }
}
function db(): PDO { return $GLOBALS['pdo']; }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_real_owner(): bool { return !empty(current_user()['is_owner']); }
function api_method(string ...$methods): void { if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) api_fail('method', 405); }
function api_begin(): void {}
function api_input(): array { return $_POST; }
function api_csrf(): void { if (($_POST['csrf'] ?? '') !== 'test-csrf') api_fail('csrf', 403); }
function require_login(): void { if (!current_user()) api_fail('login', 401); }
function api_ok(mixed $data = null): never { throw new ApiReply($data); }
function api_fail(string $error, int $http = 400, string $code = 'bad_request'): never { throw new ApiReply(['error' => $error, 'code' => $code], $http); }
function check(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    echo "PASS: $name\n";
}
$apiSource = preg_replace('/^require_once .*;\R/m', '', file_get_contents($root . '/site/password_reset_api.php'));
$apiSource = str_replace("define('API_MODE', true);", '', $apiSource);
function callApi(array $input): ApiReply
{
    global $apiSource;
    $_POST = $input + ['csrf' => 'test-csrf'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    try { eval(substr($apiSource, 5)); } catch (ApiReply $reply) { return $reply; }
    throw new RuntimeException('API returned no response');
}
$pdo = new SelfResetDB();
$pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY, is_owner INTEGER);
    CREATE TABLE users(id INTEGER PRIMARY KEY, role_id INTEGER, is_active INTEGER DEFAULT 1, username TEXT, full_name TEXT, password_hash TEXT, pw_version INTEGER DEFAULT 1);
    CREATE TABLE user_permissions(user_id INTEGER, module_key TEXT);
    CREATE TABLE role_permissions(role_id INTEGER, module_key TEXT);
    CREATE TABLE password_reset_requests(id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT DEFAULT 'pending', created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        request_ip TEXT, decided_by INTEGER, decided_at TEXT, code_hash TEXT, code_expires_at TEXT, attempts INTEGER DEFAULT 0, used_at TEXT);
    CREATE TABLE password_reset_limits(username_key TEXT, request_ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE notifications(user_id INTEGER, type TEXT, title TEXT, body TEXT, link TEXT);
    CREATE TABLE push_queue(user_id INTEGER, title TEXT, body TEXT, link TEXT);
    INSERT INTO roles VALUES(1,0),(2,1);
    INSERT INTO users(id,role_id,username,full_name) VALUES(1,1,'cashier','Cashier'),(2,1,'other','Other'),(3,2,'owner','Owner'),(4,1,'manager','Manager');
    INSERT INTO user_permissions VALUES(4,'hr:password_reset');");
$pdo->prepare('UPDATE users SET password_hash=?')->execute([password_hash('old-password', PASSWORD_DEFAULT)]);
$_SESSION['user'] = ['id' => 1, 'username' => 'cashier', 'pw_version' => 1, 'is_owner' => false];
$reply = callApi(['action' => 'request_self', 'username' => 'other']);
check($reply->status === 200 && $reply->payload['message'] === 'الطلب اتبعت. أول ما المالك أو المسؤول يوافق هيديك كود'
    && $pdo->query('SELECT user_id FROM password_reset_requests')->fetchAll(PDO::FETCH_COLUMN) === [1], '1: request_self ignores POST username');
$notifications = $pdo->query('SELECT user_id FROM notifications ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN);
check($notifications === [3, 4] && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE type='password_reset' AND title='طلب إعادة باسورد' AND link='password_resets.php'")->fetchColumn() === 2,
    'same request notifications reach owner and approver');
callApi(['action' => 'request_self']);
check((int) $pdo->query('SELECT COUNT(*) FROM password_reset_requests')->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM password_reset_limits')->fetchColumn() === 2, 'same request deduplication and limits');
$_SESSION['user'] = ['id' => 3, 'username' => 'owner', 'is_owner' => true];
check(callApi(['action' => 'request_self'])->status === 403
    && (int) $pdo->query('SELECT COUNT(*) FROM password_reset_requests')->fetchColumn() === 1, '2: owner request_self returns 403');
$_SESSION['user'] = ['id' => 1, 'username' => 'cashier', 'pw_version' => 1, 'is_owner' => false];
check(callApi(['action' => 'request_self', 'csrf' => 'wrong'])->status === 403, 'self endpoint checks CSRF');
$savedUser = $_SESSION['user'];
unset($_SESSION['user']);
check(callApi(['action' => 'redeem_self'])->status === 401, 'self endpoint requires login');
$_SESSION['user'] = $savedUser;
check(pwreset_open_request($pdo, 1) === 'pending' && pwreset_open_request($pdo, 2) === null, '5: pending and missing open request');
$pdo->prepare("UPDATE password_reset_requests SET status='approved', code_hash=?, code_expires_at=datetime('now','+30 minutes') WHERE user_id=1")
    ->execute([password_hash('123456', PASSWORD_DEFAULT)]);
check(pwreset_open_request($pdo, 1) === 'approved', '5: approved open request');
$pdo->exec("UPDATE password_reset_requests SET code_expires_at=datetime('now','-1 minute')");
check(pwreset_open_request($pdo, 1) === null, '5: expired approval returns null');
$pdo->exec("UPDATE password_reset_requests SET code_expires_at=datetime('now','+30 minutes'), created_at=datetime('now','-25 hours')");
check(pwreset_open_request($pdo, 1) === null, '5: older than 24 hours returns null');
$pdo->exec("UPDATE password_reset_requests SET created_at=datetime('now'), attempts=5");
check(pwreset_open_request($pdo, 1) === null, '5: exhausted code returns null');
$pdo->exec('UPDATE password_reset_requests SET attempts=0');
$oldSessionId = session_id();
$reply = callApi(['action' => 'redeem_self', 'username' => 'other', 'reset_otp' => '123456', 'new_password' => 'new-password', 'new_password2' => 'new-password']);
$user = $pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check($reply->status === 200 && password_verify('new-password', $user['password_hash']) && (int) $user['pw_version'] === 2
    && $_SESSION['user']['pw_version'] === 2 && session_id() !== $oldSessionId && !isset($reply->payload['redirect']) && !isset($_SESSION['pwreset_success'])
    && $reply->payload['message'] === 'اتغيّر الباسورد، وأي جهاز تاني كان داخل بحسابك هيخرج', '3: redeem_self changes password/version, keeps and rotates session, no redirect');
check((int) $pdo->query('SELECT pw_version FROM users WHERE id=2')->fetchColumn() === 1
    && pwreset_open_request($pdo, 1) === null, 'self redeem ignores other username and closes request');
$pdo->prepare("INSERT INTO password_reset_requests(user_id,status,code_hash,code_expires_at) VALUES(2,'approved',?,datetime('now','+30 minutes'))")
    ->execute([password_hash('654321', PASSWORD_DEFAULT)]);
$reply = callApi(['action' => 'redeem', 'username' => 'other', 'reset_otp' => '654321', 'new_password' => 'public-password', 'new_password2' => 'public-password']);
check($reply->status === 200 && $reply->payload['redirect'] === 'login.php' && $_SESSION['pwreset_success'] === true
    && $_SESSION['user']['pw_version'] === 2, '4: public redeem preserves redirect and success flag');

// بنرندر نفس الصفحة عشان نتأكد من ظهور الفورم بعد الريفريش وإخفائه عن المالك.
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'test-csrf'; }
function salary_pin_is_set(PDO $pdo, int $uid): bool { return false; }
function salary_pin_fields(bool $set): void {}
function salary_pin_form_script(): void {}
$pageSource = preg_replace('/^require_once .*;\R/m', '', file_get_contents($root . '/site/account.php'));
function renderAccount(): string
{
    global $pageSource;
    ob_start();
    eval(substr($pageSource, 5));
    return ob_get_clean();
}
check(str_contains(renderAccount(), 'id="selfResetRedeem" hidden'), 'render: closed request hides form');
$pdo->exec('INSERT INTO password_reset_requests(user_id) VALUES(1)');
check(str_contains(renderAccount(), 'id="selfResetRedeem" >'), 'render: pending request survives refresh');
$pdo->exec("UPDATE password_reset_requests SET status='approved',code_expires_at=datetime('now','+30 minutes') WHERE user_id=1 AND status='pending'");
check(str_contains(renderAccount(), 'id="selfResetRedeem" >'), 'render: approved request survives refresh');
$_SESSION['user'] = ['id' => 3, 'username' => 'owner', 'is_owner' => true];
check(!str_contains(renderAccount(), 'name="action" value="request_self"') && !str_contains(renderAccount(), 'name="reset_otp"'), 'render: owner has no self reset controls');
echo "All brief833c checks passed (isolated SQLite, API and render).\n";
session_write_close();
ob_end_flush();
