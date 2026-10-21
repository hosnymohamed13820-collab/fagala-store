<?php
/**
 * settings_api.php — واجهة برمجة موديول الإعدادات العامة (بريف ١٦٧)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET:
 *   ?part=values          ← {values: {store_name: …, …}} (GET)
 *   ?part=users           ← {users: […], ds_users: […]} (GET)
 *   ?part=agents          ← {agents: […], in_window: bool, warn_min: N, alert_min: N} (GET)
 *   ?part=guard_invoices  ← {invoices: […]} (GET)
 *
 * عمليات POST (للأونر فقط + CSRF + Idempotency):
 *   ?part=invoice_guard      ← {off: N, dist_id: N} (POST)
 *   ?part=daily_summary_save ← {saved: true, …} (POST)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('settings')
api_require('settings');

require_once __DIR__ . '/settings_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    'values',
    'users',
    'agents',
    'guard_invoices',
    'guard_list',
    'invoice_guard',
    'daily_summary_save',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

$postParts = [
    'invoice_guard',
    'daily_summary_save',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($part, $postParts, true)) {
    api_method('POST');
    // نفس فحص الصفحة بالحرف: للأونر بس
    if (!is_owner()) {
        api_fail('غير مصرح لك (للأونر فقط).', 403, 'forbidden');
    }
    api_csrf();
    api_idempotent();
    $in  = api_input();
    $u   = current_user();
    $uid = (int) ($u['id'] ?? 0);
} else {
    // الأجزاء المخصصة لـ POST فقط تُرفض بـ 405 لو طلبت بـ GET
    if (in_array($part, $postParts, true)) {
        api_method('POST');
    } else {
        api_method('GET');
    }
}

$pdo = db();

try {
    switch ($part) {
        case 'values':
            api_ok(['values' => settings_get_values($pdo)]);

        case 'users':
            api_ok([
                'users'    => settings_users($pdo),
                'ds_users' => settings_ds_users($pdo),
            ]);

        case 'agents':
            api_ok(settings_agents_info());

        case 'guard_invoices':
        case 'guard_list':
            api_ok(['invoices' => settings_guard_invoices($pdo)]);

        case 'invoice_guard':
            $distId = (int) ($in['dist_id'] ?? 0);
            $off    = !empty($in['off']) ? 1 : 0;
            $res    = settings_set_invoice_guard($pdo, $distId, $off, $uid);
            api_ok($res);

        case 'daily_summary_save':
            $on    = (string) ($in['on'] ?? '0');
            $hour  = (int) ($in['hour'] ?? 24);
            $users = (string) ($in['users'] ?? '');
            $res   = settings_save_daily_summary($pdo, $on, $hour, $users);
            api_ok($res);
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 422, 'error');
}
