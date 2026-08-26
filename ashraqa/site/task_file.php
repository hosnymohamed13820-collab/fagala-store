<?php
/**
 * task_file.php — تحميل مرفق مهمة (محمي)
 * نظام الفجالة ستور المالي
 *
 * الملفات متخزنة في uploads/tasks/ ومقفولة بـ .htaccess —
 * الوصول الوحيد من هنا، بعد التأكد إن المستخدم طرف في المهمة
 * (مستهدف / منشئ / أونر / مسؤول متابعة النواقص).
 */
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/shortage_lib.php';

$id  = (int) ($_GET['id'] ?? 0);
$uid = (int) current_user()['id'];

$st = db()->prepare('SELECT * FROM task_attachments WHERE id = ? LIMIT 1');
$st->execute([$id]);
$att = $st->fetch();

if (!$att || !can_view_task((int) $att['task_id'], $uid)) {
    http_response_code(404);
    exit('الملف غير موجود أو مش مصرّح لك.');
}

$path = task_upload_dir() . '/' . basename((string) $att['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('الملف اتمسح من السيرفر.');
}

header('Content-Type: ' . $att['mime']);
header('Content-Length: ' . filesize($path));
// الصور والـPDF بيتعرضوا في المتصفح — أسرع للموظف من التنزيل
header('Content-Disposition: inline; filename="' . rawurlencode((string) $att['orig_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
