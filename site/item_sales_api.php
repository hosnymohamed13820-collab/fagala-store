<?php
/**
 * item_sales_api.php — نقطة نهاية JSON لتحليل المبيعات
 * نظام الفجالة ستور المالي (بريف ٩٦ / بريف ١٠١ / بريف ١٠٦)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/item_sales_lib.php';

api_begin();
require_login();
api_method('GET');

$part = trim((string) ($_GET['part'] ?? ''));
$allowedParts = ['stock', 'movement', 'profit', 'monthly'];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير صالح أو مفقود', 400, 'bad_request');
}

$permPart = ($part === 'monthly') ? 'movement' : $part;
api_require('item_sales:' . $permPart);

if ($part === 'profit') {
    if (!sens_unlocked()) {
        api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
    }
}

$from = isset($_GET['from']) && trim((string) $_GET['from']) !== '' ? trim((string) $_GET['from']) : null;
$to   = isset($_GET['to']) && trim((string) $_GET['to']) !== '' ? trim((string) $_GET['to']) : null;
$sto  = isset($_GET['sto']) && trim((string) $_GET['sto']) !== '' ? (int) $_GET['sto'] : null;
$cat  = isset($_GET['cat']) && trim((string) $_GET['cat']) !== '' ? (int) $_GET['cat'] : null;
$by   = trim((string) ($_GET['by'] ?? 'qty'));
$limit = (int) ($_GET['limit'] ?? 50);

$period = isl_period($from, $to);
$fromDate = $period['from'];
$toDate   = $period['to'];

if ($part === 'stock') {
    $coverDays = (int) setting('item_sales_cover_days', '7');
    $data = isl_stock_status($fromDate, $toDate, $coverDays);
    if ($sto !== null && $sto > 0) {
        $data = $data[$sto] ?? [
            'sto_id'   => $sto,
            'name'     => '',
            'is_hq'    => false,
            'negative' => [],
            'zero'     => [],
            'near'     => [],
            'idle'     => [],
            'counts'   => ['negative' => 0, 'zero' => 0, 'near' => 0, 'idle' => 0],
        ];
    }
    api_ok($data);
}

if ($part === 'movement') {
    $data = isl_movement($fromDate, $toDate, $sto, $cat, $by, $limit);
    if (!(can('item_sales:profit') && sens_unlocked())) {
        $data = isl_movement_public($data);
    }
    api_ok($data);
}

if ($part === 'profit') {
    $data = isl_profit($fromDate, $toDate, $sto, $cat);
    api_ok($data);
}

if ($part === 'monthly') {
    $months = (int) ($_GET['months'] ?? 6);
    $monthlyLimit = min(500, max(1, $limit));
    $targetTo = $to ?: $toDate;
    $data = isl_monthly($targetTo, $months, $sto, $cat, $by, $monthlyLimit);
    api_ok($data);
}
