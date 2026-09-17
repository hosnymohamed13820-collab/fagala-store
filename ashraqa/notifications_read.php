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

// الجرس بيبعت رقم الإشعار — أدق من المطابقة باللينك، وبيشتغل كمان مع
// الإشعارات اللي **ملهاش لينك** (دي كانت مستحيل تتعلّم مقروءة قبل كده).
// الدرج (service worker) مالوش الرقم، فبيبعت `go` باللينك.
if (isset($_GET['id'])) {
    $nid = (int) $_GET['id'];
    $goTo = 'dashboard.php';
    if ($nid > 0) {
        try {
            // 🔴 user_id شرط إجباري — من غيره أي حد يعلّم إشعارات غيره
            $sel = db()->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
            $sel->execute([$nid, $uid]);
            $row = $sel->fetch();
            if ($row) {
                db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                    ->execute([$nid, $uid]);
                $lnk = (string) ($row['link'] ?? '');
                if ($lnk !== ''
                    && preg_match('~^[a-zA-Z0-9_./?=&%#-]+$~', $lnk)
                    && !str_contains($lnk, '://')
                    && !str_starts_with($lnk, '//')) {
                    $goTo = $lnk;
                }
            }
        } catch (Throwable $e) { /* تجاهل */ }
    }
    header('Location: ' . $goTo);
    exit;
}

if (isset($_GET['go'])) {
    $go = (string) $_GET['go'];
    // أمان: نتأكد إن الـgo رابط داخلي بس.
    // 🔴 الفاصل `~` مش `#` — الفاصل `#` بيتصادم مع الـ`#` اللي جوّه كلاس
    // الحروف (بتاع الـfragment)، فالنمط بيقفل بدري وpreg_match بترجّع false
    // والشرط بيتحقق **دايماً**. يعني مفيش إشعار كان بيتعلّم مقروء خالص.
    // و`//evil.com` رابط خارجي عند المتصفح رغم إنه عدّى من النمط.
    if (!preg_match('~^[a-zA-Z0-9_./?=&%#-]+$~', $go)
        || str_contains($go, '://')
        || str_starts_with($go, '//')) {
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
// أمان: نتأكد إن الـback رابط داخلي بس — شوف التعليق فوق عن الفاصل `~`
if (!preg_match('~^[a-zA-Z0-9_./?=&%#-]+$~', $back)
    || str_contains($back, '://')
    || str_starts_with($back, '//')) {
    $back = 'dashboard.php';
}
header('Location: ' . $back);
exit;
