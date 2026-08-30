<?php
/**
 * notifications_read.php — تعليم إشعارات المستخدم كمقروءة ثم التحويل.
 *
 * - لو جه ?go=<link>: بيعلّم مقروء بس صفوف المستخدم اللي ليها نفس الـlink ويحوّل عليه.
 * - لو ما جاش go: بيعلّم كل إشعارات المستخدم مقروءة ويحوّل على back.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';

require_login();

$uid = (int) current_user()['id'];

if (isset($_GET['go'])) {
    $go = (string) $_GET['go'];
    // أمان: نتأكد إن الـgo رابط داخلي بس
    if (!preg_match('#^[a-zA-Z0-9_./?=&%#-]+$#', $go) || str_contains($go, '://')) {
        $go = 'dashboard.php';
    }

    try {
        $st = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND link = ? AND is_read = 0');
        $st->execute([$uid, $go]);
    } catch (Throwable $e) { /* تجاهل */ }

    header('Location: ' . $go);
    exit;
}

// المسار القديم: تعليم الكل كمقروء
try {
    mark_notifications_read($uid);
} catch (Throwable $e) { /* تجاهل */ }

$back = (string) ($_GET['back'] ?? 'dashboard.php');
// أمان: نتأكد إن الـback رابط داخلي بس
if (!preg_match('#^[a-zA-Z0-9_./?=&%#-]+$#', $back) || str_contains($back, '://')) {
    $back = 'dashboard.php';
}
header('Location: ' . $back);
exit;
