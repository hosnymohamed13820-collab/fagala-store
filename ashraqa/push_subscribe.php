<?php
/**
 * push_subscribe.php — تسجيل وإلغاء اشتراك أجهزة الويب بوش
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sub_response(bool $ok, ?string $error = null, int $httpCode = 200): void
{
    http_response_code($httpCode);
    $res = ['ok' => $ok];
    if ($error !== null) {
        $res['error'] = $error;
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sub_response(false, 'طلب غير مسموح — POST فقط', 405);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    sub_response(false, 'نوع المحتوى لازم يكون application/json', 415);
}
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || !csrf_verify($csrf)) {
    sub_response(false, 'انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني', 403);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) === 0) {
    sub_response(false, 'بيانات الطلب فاضية', 400);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    sub_response(false, 'بيانات JSON غير صالحة', 400);
}

$action = (string) ($data['action'] ?? '');
$endpoint = trim((string) ($data['endpoint'] ?? ''));

if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
    sub_response(false, 'عنوان endpoint غير صالح', 400);
}

$endpointHash = hash('sha256', $endpoint);
$uid = (int) current_user()['id'];
$pdo = db();

if ($action === 'subscribe') {
    $keys = $data['keys'] ?? [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));

    if ($p256dh === '' || $auth === '') {
        sub_response(false, 'مفاتيح التشفير p256dh أو auth ناقصة', 400);
    }

    $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    try {
        $st = $pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, fail_count, last_ok_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                user_id    = VALUES(user_id),
                p256dh     = VALUES(p256dh),
                auth       = VALUES(auth),
                user_agent = VALUES(user_agent),
                fail_count = 0'
        );
        $st->execute([$uid, $endpoint, $endpointHash, $p256dh, $auth, $userAgent ?: null]);
        sub_response(true);
    } catch (Throwable $e) {
        error_log('push_subscribe error: ' . $e->getMessage());
        sub_response(false, 'فشل حفظ الاشتراك في قاعدة البيانات', 500);
    }
} elseif ($action === 'unsubscribe') {
    try {
        $st = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?');
        $st->execute([$endpointHash, $uid]);
        sub_response(true);
    } catch (Throwable $e) {
        error_log('push_unsubscribe error: ' . $e->getMessage());
        sub_response(false, 'فشل إلغاء الاشتراك', 500);
    }
} else {
    sub_response(false, 'إجراء غير معروف', 400);
}
