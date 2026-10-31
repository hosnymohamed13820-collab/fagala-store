<?php
/**
 * branch_recon_api.php — واجهة برمجية لمطابقة الفروع والإدارة
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٩)
 *
 * قراءة فقط (GET). العمليات وتصدير الإكسيل وعلامات التلوين ممنوعة كلياً (405).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
define('API_MODE', true);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('branch_recon');
require_once __DIR__ . '/branch_recon_lib.php';

// حظر أي طريقة غير GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$allParties = recon_all_parties();
$stStart = branch_recon_get_start_date($pdo);

$activeTab = trim((string)($_GET['tab'] ?? ''));
$fromParam = isset($_GET['from']) ? (string)$_GET['from'] : null;
$toParam   = isset($_GET['to']) ? (string)$_GET['to'] : null;

$range = branch_recon_get_date_range($activeTab, $fromParam, $toParam, $stStart);
$from  = $range['from'];
$to    = $range['to'];

$aKey = trim((string)($_GET['a'] ?? ''));
$bKey = trim((string)($_GET['b'] ?? ''));
$docParam = trim((string)($_GET['doc'] ?? ''));

$part = $_GET['part'] ?? '';
$allowedParts = ['', 'pairs', 'detail', 'alerts', 'ashraqa', 'ashraqa_recon', 'parties', 'meta', 'all'];

if (!in_array($part, $allowedParts, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid part'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === '') {
    if ($aKey !== '' && $bKey !== '') {
        $part = 'detail';
    } elseif ($activeTab === 'alerts') {
        $part = 'alerts';
    } elseif ($activeTab === 'ashraqa') {
        $part = 'ashraqa';
    } elseif ($activeTab === 'ash_recon') {
        $part = 'ashraqa_recon';
    } else {
        $part = 'pairs';
    }
}

$response = ['ok' => true];

if ($part === 'parties' || $part === 'meta') {
    $response['data'] = [
        'parties'    => $allParties,
        'date_range' => ['from' => $from, 'to' => $to, 'default_start' => $stStart],
    ];
} elseif ($part === 'pairs') {
    $pairsData = branch_recon_get_pairs_data($pdo, $from, $to);
    $response['data'] = array_merge($pairsData, [
        'from' => $from,
        'to'   => $to,
    ]);
} elseif ($part === 'detail') {
    if ($aKey === '' || $bKey === '' || $aKey === $bKey || !isset($allParties[$aKey], $allParties[$bKey])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing pair parties'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detailData = branch_recon_get_detail_data($pdo, $from, $to, $aKey, $bKey, $allParties);
    $response['data'] = array_merge($detailData ?? [], [
        'from' => $from,
        'to'   => $to,
    ]);
} elseif ($part === 'alerts') {
    $pFilter  = trim((string)($_GET['p'] ?? ''));
    $showDone = !empty($_GET['show_done']);
    $alertsData = branch_recon_get_alerts_data($pdo, $from, $to, $allParties, $pFilter, $showDone);
    $response['data'] = array_merge($alertsData, [
        'from'     => $from,
        'to'       => $to,
        'p_filter' => $pFilter,
    ]);
} elseif ($part === 'ashraqa') {
    if ($docParam !== '') {
        $docData = branch_recon_get_ashraqa_detail($pdo, $docParam);
        if (!$docData) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Invoice not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $response['data'] = $docData;
    } else {
        $sstt = isset($_GET['sstt']) ? (int)$_GET['sstt'] : 0;
        $supp = isset($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
        $q    = trim((string)($_GET['q'] ?? ''));
        $showCancelled = !empty($_GET['show_cancelled']);
        $listData = branch_recon_get_ashraqa_list($pdo, $from, $to, $sstt, $supp, $q, $showCancelled);
        $response['data'] = array_merge($listData, [
            'from' => $from,
            'to'   => $to,
        ]);
    }
} elseif ($part === 'ashraqa_recon') {
    $reconData = branch_recon_get_ashraqa_recon($pdo, $from, $to);
    $response['data'] = array_merge($reconData, [
        'from' => $from,
        'to'   => $to,
    ]);
} elseif ($part === 'all') {
    $pairsData = branch_recon_get_pairs_data($pdo, $from, $to);
    $response['data'] = [
        'parties'    => $allParties,
        'date_range' => ['from' => $from, 'to' => $to, 'default_start' => $stStart],
        'pairs'      => $pairsData,
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

