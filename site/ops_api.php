<?php
/**
 * ops_api.php — واجهة برمجة موديول المراقبة والتشغيل (بريف ١٦١)
 * نظام الفجالة ستور المالي
 *
 *   ?part=perf[&day=YYYY-MM-DD]       ← {available_days, selected_day, ...} (GET, ops:perf)
 *   ?part=audit[&from=...&to=...&...]  ← {rows, total, users_list, pages_list} (GET, ops:audit + sens_unlocked)
 *   ?part=devices                     ← {rows} (GET, ops:devices)
 *   ?part=db                          ← {ready, pending_count, rows} (GET, ops:db)
 *   ?part=revoke_token&token_id=...   ← {ok: true} (POST, ops:devices + is_owner)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_module_access('ops');

require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/ops_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$reads  = ['perf', 'audit', 'devices', 'db'];
$writes = ['revoke_token'];

if (!in_array($part, $reads, true) && !in_array($part, $writes, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

// ===== عمليات القراءة — GET بس =====
if (in_array($part, $reads, true)) {
    api_method('GET');

    switch ($part) {
        case 'perf':
            api_require('ops:perf');
            $day = isset($_GET['day']) ? trim((string)$_GET['day']) : null;
            api_ok(ops_perf_data($day !== '' ? $day : null));

        case 'audit':
            api_require('ops:audit');
            if (!sens_unlocked()) {
                api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
            }
            $from = (string) ($_GET['from'] ?? '');
            $to   = (string) ($_GET['to'] ?? '');
            $userId = isset($_GET['user_id']) && trim((string)$_GET['user_id']) !== '' ? (int)$_GET['user_id'] : null;
            $page   = (string) ($_GET['page'] ?? '');
            $action = (string) ($_GET['action_search'] ?? '');
            $pageNum = max(1, (int) ($_GET['p'] ?? 1));
            $limit   = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));

            api_ok(ops_audit_data($from, $to, $userId, $page, $action, $pageNum, $limit));

        case 'devices':
            api_require('ops:devices');
            api_ok(ops_devices_data());

        case 'db':
            api_require('ops:db');
            api_ok(ops_db_data());
    }
}

// ===== عمليات الكتابة — POST بس بـ CSRF و Idempotency =====
api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

switch ($part) {
    case 'revoke_token':
        api_require('ops:devices');
        if (!is_owner()) {
            api_fail('خاصية إلغاء التوكن مقتصرة على مدير النظام (الأونر)', 403, 'forbidden');
        }
        $tokenId = (int) ($in['token_id'] ?? ($_POST['token_id'] ?? 0));
        if ($tokenId <= 0) {
            api_fail('معرف التوكن غير صالح', 422, 'invalid_token_id');
        }
        $success = ops_revoke_token($tokenId);
        if (!$success) {
            api_fail('التوكن غير موجود أو تم إلغاؤه بالفعل', 404, 'not_found');
        }
        api_ok(['ok' => true]);
}
