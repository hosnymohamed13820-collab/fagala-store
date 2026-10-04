<?php
/**
 * item_sales_api.php — نقطة نهاية JSON لتحليل المبيعات
 * نظام الفجالة ستور المالي (بريف ٩٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/item_sales_lib.php';

header('Content-Type: application/json; charset=utf-8');

$part = trim((string) ($_GET['part'] ?? ''));
$allowedParts = ['stock', 'movement', 'profit', 'monthly'];

if (!in_array($part, $allowedParts, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'جزء غير صالح أو مفقود'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();

$permPart = ($part === 'monthly') ? 'movement' : $part;
if (!can('item_sales:' . $permPart)) {
    http_response_code(403);
    echo json_encode(['error' => 'غير مصرح لك بعرض هذا الجزء'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'profit') {
    if (!sens_unlocked()) {
        http_response_code(423);
        echo json_encode(['error' => 'الأرقام الحساسة مقفولة'], JSON_UNESCAPED_UNICODE);
        exit;
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'movement') {
    $data = isl_movement($fromDate, $toDate, $sto, $cat, $by, $limit);
    if (!(can('item_sales:profit') && sens_unlocked())) {
        $data = isl_movement_public($data);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'profit') {
    $data = isl_profit($fromDate, $toDate, $sto, $cat);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'monthly') {
    $months = (int) ($_GET['months'] ?? 6);
    $monthlyLimit = min(500, max(1, $limit));
    $targetTo = $to ?: $toDate;
    $data = isl_monthly($targetTo, $months, $sto, $cat, $by, $monthlyLimit);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
