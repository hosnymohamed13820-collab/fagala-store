<?php
/** notifications_api.php — API إشعارات المستخدم الحالي. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

require_once __DIR__ . '/notifications_lib.php';

$part = (string) ($_GET['part'] ?? '');
$userId = (int) (current_user()['id'] ?? 0);

if ($part === 'init') {
    api_method('GET');
    $summary = notifgrp_summary($userId);
    $groups = $summary['groups'] ?? [];
    $groupKeys = array_column($groups, 'key');
    $requestedGroup = (string) ($_GET['group'] ?? '');
    $group = in_array($requestedGroup, $groupKeys, true)
        ? $requestedGroup
        : ($groupKeys[0] ?? 'sales');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    try {
        $list = notifgrp_list($userId, $group, $page);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage(), 400, 'bad_request');
    }
    api_ok([
        'summary' => $summary,
        'list'    => $list,
        'group'   => $group,
    ]);
}

if ($part === 'summary') {
    api_method('GET');
    api_ok(notifgrp_summary($userId));
}

if ($part === 'list') {
    api_method('GET');
    $group = (string) ($_GET['group'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    try {
        $data = notifgrp_list($userId, $group, $page);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage(), 400, 'bad_request');
    }
    api_ok($data);
}

if ($part === 'mark_read') {
    api_method('POST');
    api_csrf();
    api_idempotent();
    $in = api_input();
    $id = isset($in['id']) ? (int) $in['id'] : null;
    $group = isset($in['group']) ? (string) $in['group'] : null;
    $all = !empty($in['all']);
    try {
        $changed = notifgrp_mark_read($userId, $id, $group, $all);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage(), 400, 'bad_request');
    }
    api_ok(['changed' => $changed]);
}

api_fail('جزء غير معروف', 400, 'bad_request');
