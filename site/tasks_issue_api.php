<?php
/**
 * tasks_issue_api.php — داتا «إصدار المهام» (بريف ١٤٥)
 * نظام الفجالة ستور المالي
 *
 *   ?part=meta         (GET)  ← {branches, users}
 *   ?part=create       (POST) ← {task_id, count, msg}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('tasks_issue') في tasks_issue.php:9
api_require('tasks_issue');

require_once __DIR__ . '/tasks_lib.php';

$uid = (int) current_user()['id'];

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'meta':
    case 'form_data':
    case 'targets':
        api_method('GET');
        api_ok(tasks_issue_targets_data());
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['create', 'issue', 'save'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

try {
    switch ($part) {
        case 'create':
        case 'issue':
        case 'save':
            $scope     = (string) ($in['scope'] ?? 'users');
            $branchIds = (array) ($in['branch_ids'] ?? []);
            $userIds   = (array) ($in['user_ids'] ?? []);

            $targets = !empty($in['targets'])
                ? array_values(array_filter(array_map('intval', (array) $in['targets']), fn($x) => $x > 0))
                : resolve_task_targets($scope, $branchIds, $userIds);

            if (empty($targets)) {
                throw new RuntimeException('مفيش مستهدفين للاختيار ده.');
            }

            $title    = (string) ($in['title'] ?? '');
            $details  = (string) ($in['details'] ?? '');
            $priority = (string) ($in['priority'] ?? 'normal');
            $dueDate  = (string) ($in['due_date'] ?? '');

            $taskId = create_task(
                $title,
                $details !== '' ? $details : null,
                $priority,
                $dueDate !== '' ? $dueDate : null,
                $targets,
                $uid
            );

            api_ok([
                'task_id' => $taskId,
                'count'   => count($targets),
                'msg'     => 'تم إصدار المهمة وإرسال إشعار لـ ' . count($targets) . ' مستخدم.',
            ]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}

