<?php
/** CLI بس: تشغيل صفحة بمستخدم حقيقي على نسخة إشراقة المحلية، من غير تعديل بياناتها. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('LARAGON_WWW') ?: dirname(__DIR__, 2) . '/tmp/brief848/ashraqa';
$page = $argv[1] ?? 'dashboard.php';
$uid = (int)($argv[2] ?? 1);
if (!preg_match('/^[a-z_]+\.php$/', $page) || !is_file($root . '/' . $page)) throw new RuntimeException('صفحة مش موجودة');
require $root . '/config.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ashraqa') throw new RuntimeException('الاختبار لقاعدة إشراقة بس');
$st = $pdo->prepare('SELECT u.*, r.is_owner FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1');
$st->execute([$uid]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('لازم مستخدم حقيقي');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = ($argv[4] ?? 'GET') === 'POST' ? 'POST' : 'GET';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page;
parse_str($argv[3] ?? '', $_GET);
session_save_path(dirname($root));
session_name('ASHSESS');
session_id('brief848-' . $uid . '-' . bin2hex(random_bytes(6)));
session_start();
$_SESSION = ['app'=>'ashraqa','user'=>$user,'permissions'=>[], 'sens_until'=>time()+600];
require $root . '/auth.php';
load_permissions($uid);
$pdo->beginTransaction();
// بعد مسجل الطلبات: حتى كتابات صيانة GET والسجل بترجع بالـrollback.
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = error_get_last();
    fwrite(STDERR, json_encode(['http'=>http_response_code() ?: 200,'fatal'=>$error && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true) ? $error['message'] : null], JSON_UNESCAPED_UNICODE) . "\n");
});
chdir($root);
require $root . '/' . $page;
