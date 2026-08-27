<?php
/**
 * notifications_read.php — تعليم كل إشعارات المستخدم كمقروءة ثم الرجوع.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';

require_login();

$uid = (int) current_user()['id'];
try {
    mark_notifications_read($uid);
} catch (Throwable $e) { /* تجاهل */ }

$back = $_GET['back'] ?? 'dashboard.php';
// أمان: نتأكد إن الـback رابط داخلي بس
if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $back) || str_contains($back, '://')) {
    $back = 'dashboard.php';
}
header('Location: ' . $back);
exit;
