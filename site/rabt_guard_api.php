<?php
/**
 * rabt_guard_api.php — داتا «حارس الربط» (بريف ١٤٧)
 * نظام الفجالة ستور المالي
 *
 *   ?part=open               ← {rows}                 (GET)
 *   ?part=commands           ← {rows}                 (GET)
 *   ?part=closed             ← {rows}                 (GET)
 *   ?part=dashboard          ← {open, commands, closed} (GET)
 *   ?part=close       (POST) ← {id, closed}           (id, reason?)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس فحص الصفحة بالحرف: الصفحة للأونر بس (rabt_guard.php:15-18)
if (!is_owner()) {
    api_fail('غير مصرح — الصفحة دي للأونر بس.', 403, 'forbidden');
}

require_once __DIR__ . '/rabt_guard_lib.php';

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'open':
        api_method('GET');
        api_ok(['rows' => rabt_guard_open_incidents()]);

    case 'commands':
        api_method('GET');
        $limit = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;
        api_ok(['rows' => rabt_guard_recent_commands($limit)]);

    case 'closed':
        api_method('GET');
        $limit = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;
        api_ok(['rows' => rabt_guard_closed_incidents($limit)]);

    case 'all':
    case 'dashboard':
        api_method('GET');
        api_ok([
            'open'     => rabt_guard_open_incidents(),
            'commands' => rabt_guard_recent_commands(50),
            'closed'   => rabt_guard_closed_incidents(50),
        ]);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['close'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

try {
    switch ($part) {
        case 'close':
            $id = (int) ($in['id'] ?? $in['incident_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('رقم الحادثة غير صالح.');
            }
            $reason = trim((string) ($in['reason'] ?? 'قفلها الأونر'));
            $ok = rabt_guard_close_incident($id, $reason !== '' ? $reason : 'قفلها الأونر');
            api_ok([
                'id'     => $id,
                'closed' => $ok,
            ]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}

