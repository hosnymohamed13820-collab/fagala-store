<?php
/**
 * review_api.php — واجهة برمجية لمراجعة المحاسب (ورديات، عهد، أذونات، مشاكل التسلسل)
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٨)
 *
 * قراءة فقط (GET). العمليات المالية وتعديل الورديات واعتمادها ممنوعة كلياً (405).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
define('API_MODE', true);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('review');
require_once __DIR__ . '/review_lib.php';

// حظر أي طريقة غير GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$part = $_GET['part'] ?? 'all';
$allowedParts = ['shifts', 'custody', 'permits', 'seq_issues', 'counts', 'meta', 'all'];

if (!in_array($part, $allowedParts, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid part'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

// تشغيل فحص انتهاء صلاحية مرتجعات الورديات آلياً
review_expire_shift_returns($pdo);

$response = ['ok' => true];

if ($part === 'shifts' || $part === 'all') {
    $pendingShifts = review_get_pending_shifts($pdo);
    $shiftDetails  = review_get_pending_shift_details($pdo, $pendingShifts);
    $historyShifts = review_get_history_shifts($pdo, 50);

    $allReviewShiftIds = [];
    foreach ($pendingShifts as $ps) $allReviewShiftIds[] = (int)$ps['id'];
    foreach ($historyShifts as $hs) $allReviewShiftIds[] = (int)$hs['id'];
    $editBadges = review_get_shift_edit_badges($pdo, $allReviewShiftIds);

    $shiftsData = [
        'pending'     => $pendingShifts,
        'details'     => $shiftDetails,
        'history'     => $historyShifts,
        'edit_badges' => $editBadges,
    ];
    if ($part === 'shifts') {
        $response['data'] = $shiftsData;
    }
}

if ($part === 'custody' || $part === 'all') {
    $custodyData = [
        'pending' => review_get_pending_custody($pdo),
        'history' => review_get_history_custody($pdo, 50),
    ];
    if ($part === 'custody') {
        $response['data'] = $custodyData;
    }
}

if ($part === 'permits' || $part === 'all') {
    $permitsData = review_get_permits($pdo, 50);
    if ($part === 'permits') {
        $response['data'] = $permitsData;
    }
}

$canEditShiftDateBranch = can_edit_shift_date_branch();

if ($part === 'seq_issues' || $part === 'all') {
    $seqData = review_get_seq_issues($pdo, $canEditShiftDateBranch);
    if ($part === 'seq_issues') {
        $response['data'] = $seqData;
    }
}

if ($part === 'counts' || $part === 'meta' || $part === 'all') {
    // نحتاج الكاونتات
    $pShifts  = isset($pendingShifts) ? $pendingShifts : review_get_pending_shifts($pdo);
    $pCustody = isset($custodyData['pending']) ? $custodyData['pending'] : review_get_pending_custody($pdo);
    $pPermits = isset($permitsData['pending']) ? $permitsData['pending'] : review_get_permits($pdo)['pending'];

    $counts = review_calc_branch_counts($pShifts, $pCustody, $pPermits);
    if ($part === 'counts') {
        $response['data'] = $counts;
    }
}

if ($part === 'meta' || $part === 'all') {
    $dictionaries = review_get_name_dictionaries($pdo);
    $branches     = review_get_branches($pdo);

    $meta = [
        'branches'     => $branches,
        'dictionaries' => $dictionaries,
        'counts'       => $counts,
        'can'          => [
            'review'                  => true,
            'edit_shift_date_branch'  => $canEditShiftDateBranch,
        ],
    ];
    if ($part === 'meta') {
        $response['data'] = $meta;
    }
}

if ($part === 'all') {
    $response['data'] = [
        'meta'       => $meta,
        'shifts'     => $shiftsData,
        'custody'    => $custodyData,
        'permits'    => $permitsData,
        'seq_issues' => $seqData,
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

