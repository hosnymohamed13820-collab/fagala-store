<?php
/**
 * daily_summary_api.php — داتا «الملخص اليومي» (بريف ١٢٤)
 * نظام الفجالة ستور المالي
 *
 *   ?part=summary&date=YYYY-MM-DD   ← {date, summary, month, prev_date, next_date}
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');

// الأونر · صلاحية «التقارير ← الملخص اليومي» · أو اللي في قايمة المستلمين
$dsUsers = array_map('intval', explode(',', (string) setting('daily_summary_users', '')));
if (!can('reports:daily') && !in_array((int) current_user()['id'], $dsUsers, true)) {
    api_fail('مالكش صلاحية', 403, 'forbidden');
}

require_once __DIR__ . '/daily_summary_lib.php';

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'summary':
        $date = trim((string) ($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $dsSendHour = (int) setting('daily_summary_hour', '23');
            $date = ($dsSendHour === 24 || (int) date('G') >= $dsSendHour)
                ? date('Y-m-d')
                : date('Y-m-d', strtotime('-1 day'));
        }
        $summary = ds_build($date);
        $month   = ds_month($date);
        api_ok([
            'date'      => $date,
            'summary'   => $summary,
            'month'     => $month,
            'prev_date' => date('Y-m-d', strtotime($date . ' -1 day')),
            'next_date' => date('Y-m-d', strtotime($date . ' +1 day')),
        ]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

