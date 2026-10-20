<?php
/**
 * tasks_api.php — داتا «المهام» (بريف ١٤٥)
 * نظام الفجالة ستور المالي
 *
 *   ?part=inbox               ← {rows, pending_count}  (GET)
 *   ?part=sent                ← {rows}                 (GET)
 *   ?part=get&id=…            ← {task, targets, …}     (GET)
 *   ?part=mark_done    (POST) ← {task_id, done}        (task_id)
 *   ?part=add_comment  (POST) ← {task_id, added}       (task_id, body)
 *   ?part=upload_attachment (POST) ← {task_id, uploaded} (task_id, attachment)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('tasks') في tasks.php:11
api_require('tasks');

require_once __DIR__ . '/tasks_lib.php';

$uid = (int) current_user()['id'];

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'inbox':
        api_method('GET');
        $inbox = tasks_inbox_list($uid);
        $pendingCount = 0;
        foreach ($inbox as $t) {
            if (($t['my_status'] ?? '') === 'pending') {
                $pendingCount++;
            }
        }
        api_ok([
            'rows'          => $inbox,
            'pending_count' => $pendingCount,
        ]);

    case 'sent':
        api_method('GET');
        api_ok(['rows' => tasks_sent_list($uid)]);

    case 'get':
    case 'view':
        api_method('GET');
        $taskId = (int) ($_GET['id'] ?? $_GET['task_id'] ?? $_GET['task'] ?? 0);
        $detail = tasks_get_detail($taskId, $uid);
        if (!$detail) {
            api_fail('المهمة مش موجودة أو مش مصرّح لك تشوفها', 404, 'not_found');
        }
        api_ok($detail);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['mark_done', 'add_comment', 'upload_attachment'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

try {
    switch ($part) {
        case 'mark_done':
            $tid = (int) ($in['task_id'] ?? $in['id'] ?? 0);
            if ($tid <= 0) {
                throw new RuntimeException('رقم المهمة غير صالح.');
            }
            $chk = tasks_get_detail($tid, $uid);
            if (!$chk) {
                api_fail('المهمة مش موجودة أو مش مصرّح لك تعدّلها', 403, 'forbidden');
            }
            mark_task_done($tid, $uid);
            api_ok([
                'task_id' => $tid,
                'done'    => true,
            ]);

        case 'add_comment':
            $tid = (int) ($in['task_id'] ?? $in['id'] ?? 0);
            if ($tid <= 0) {
                throw new RuntimeException('رقم المهمة غير صالح.');
            }
            $body = trim((string) ($in['body'] ?? $in['comment'] ?? ''));
            if ($body === '') {
                throw new RuntimeException('اكتب رد الأول.');
            }
            $chk = tasks_get_detail($tid, $uid);
            if (!$chk) {
                api_fail('المهمة مش موجودة أو مش مصرّح لك ترد عليها', 403, 'forbidden');
            }
            add_task_comment($tid, $uid, $body);
            api_ok([
                'task_id' => $tid,
                'added'   => true,
            ]);

        case 'upload_attachment':
            $tid = (int) ($in['task_id'] ?? $in['id'] ?? $_POST['task_id'] ?? 0);
            if ($tid <= 0 || !can_view_task($tid, $uid)) {
                api_fail('مش مصرّح لك ترفع على المهمة دي.', 403, 'forbidden');
            }
            if (empty($_FILES['attachment']['name'])) {
                throw new RuntimeException('اختار ملف الأول.');
            }
            add_task_attachment($tid, $uid, $_FILES['attachment']);
            api_ok([
                'task_id'  => $tid,
                'uploaded' => true,
            ]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}

