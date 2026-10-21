<?php
/**
 * sms_inbox_api.php — واجهة برمجة رسايل إنستاباي وإحصائياتها (بريف ١٧٤ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=stats                              ← إحصائيات الرسائل (إجمالي، وصول، سحب، مباشر) وكلمات الفلتر
 *   ?part=messages[&from=...&to=...&kind=...] ← الرسائل المحللة وإجمالياتها وأذوناتها المرتبطة
 *   ?part=webhook                            ← بيانات ربط تطبيق الأندرويد (التوكن للأونر فقط)
 *   ?part=all[&from=...&to=...&kind=...]     ← الشجرة الكاملة
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('sms_inbox');

require_once __DIR__ . '/sync_tokens.php';
require_once __DIR__ . '/sms_lib.php';
require_once __DIR__ . '/sms_permits_lib.php';

// رسايل إنستاباي في المرحلة الحالية قراءة فقط
api_method('GET');

$pdo = db();
$part = (string) ($_GET['part'] ?? 'messages');

$allowedParts = ['stats', 'messages', 'webhook', 'all'];
if (!in_array($part, $allowedParts, true)) {
    api_fail('الجزء المطلوب غير معروف', 400, 'bad_request');
}

$period = sms_parse_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['kind'] ?? null);
$from   = $period['from'];
$to     = $period['to'];
$kind   = $period['kind'];

try {
    switch ($part) {
        case 'stats':
            $stats = sms_get_stats($pdo);
            $keywords = sms_get_keywords($pdo);
            api_ok([
                'stats'    => $stats,
                'keywords' => $keywords,
            ]);

        case 'webhook':
            $webhookInfo = sms_get_webhook_info(is_owner());
            api_ok($webhookInfo);

        case 'messages':
            $allMessages = sms_fetch($pdo, $from, $to, $kind);
            $totals      = sms_calc_totals($allMessages);

            $totalFiltered = count($allMessages);
            $messagesSlice = array_slice($allMessages, 0, 500);
            $hasMore       = $totalFiltered > 500;
            $moreCount     = $totalFiltered > 500 ? $totalFiltered - 500 : 0;

            $smsIds = array_map(fn($m) => (int) $m['id'], $messagesSlice);
            try {
                $permitsMap = sms_permits_for($smsIds);
            } catch (Throwable) {
                $permitsMap = [];
            }

            api_ok([
                'period'       => $period,
                'totals'       => $totals,
                'total_count'  => $totalFiltered,
                'has_more'     => $hasMore,
                'more_count'   => $moreCount,
                'messages'     => $messagesSlice,
                'permits'      => $permitsMap,
            ]);

        case 'all':
            $stats       = sms_get_stats($pdo);
            $keywords    = sms_get_keywords($pdo);
            $webhookInfo = sms_get_webhook_info(is_owner());
            $allMessages = sms_fetch($pdo, $from, $to, $kind);
            $totals      = sms_calc_totals($allMessages);

            $totalFiltered = count($allMessages);
            $messagesSlice = array_slice($allMessages, 0, 500);
            $hasMore       = $totalFiltered > 500;
            $moreCount     = $totalFiltered > 500 ? $totalFiltered - 500 : 0;

            $smsIds = array_map(fn($m) => (int) $m['id'], $messagesSlice);
            try {
                $permitsMap = sms_permits_for($smsIds);
            } catch (Throwable) {
                $permitsMap = [];
            }

            api_ok([
                'stats'        => $stats,
                'keywords'     => $keywords,
                'webhook'      => $webhookInfo,
                'period'       => $period,
                'totals'       => $totals,
                'total_count'  => $totalFiltered,
                'has_more'     => $hasMore,
                'more_count'   => $moreCount,
                'messages'     => $messagesSlice,
                'permits'      => $permitsMap,
            ]);
    }
} catch (Throwable $e) {
    errlog_exception($e, 'sms_inbox_api');
    api_fail('حدث خطأ أثناء معالجة رسايل إنستاباي', 500, 'server_error');
}
