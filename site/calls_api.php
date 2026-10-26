<?php
/**
 * calls_api.php — داتا وإعدادات متابعة مكالمات الفروع (بريف ٢٢٣ب)
 * نظام الفجالة ستور المالي
 *
 *   GET ?part=missed|log|summary
 *   POST action=save_followup_minutes
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_require('calls');
api_method('GET', 'POST');

require_once __DIR__ . '/calls_lib.php';

$pdo = db();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    if (!is_owner()) {
        api_fail('غير مصرح.', 403, 'forbidden');
    }
    api_csrf();
    api_idempotent();

    $input = api_input();
    if ((string) ($input['action'] ?? '') !== 'save_followup_minutes') {
        api_fail('إجراء غير معروف', 400, 'bad_request');
    }

    $minutes = (int) ($input['minutes'] ?? 0);
    if ($minutes < 5 || $minutes > 240) {
        api_fail('المهلة يجب أن تكون بين ٥ و ٢٤٠ دقيقة.', 400, 'bad_request');
    }

    if (!calls_set_followup_minutes($pdo, $minutes)) {
        api_fail('فشل حفظ المهلة', 500, 'server');
    }
    api_ok(['saved' => true, 'minutes' => $minutes]);
}

$isOwner = is_owner();
$userBranchId = (int) ($_SESSION['user']['branch_id'] ?? 0);
$branchScope = calls_resolve_branch_scope($pdo, $isOwner, $userBranchId, $_GET);
if ($branchScope['branch_missing']) {
    api_fail('حسابك مش مربوط بفرع', 403, 'forbidden');
}

$selectedBranch = $branchScope['selected_branch'];
$periodFilter = calls_resolve_period($_GET);
$dateFrom = $periodFilter['date_from'];
$dateTo = $periodFilter['date_to'];
$followupMinutes = calls_get_followup_minutes($pdo);
$part = (string) ($_GET['part'] ?? '');

$context = [
    'branch_id' => $selectedBranch,
    'period'    => $periodFilter['period'],
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
];

switch ($part) {
    case 'missed':
        api_ok(array_merge(
            $context,
            calls_get_missed_data($pdo, $selectedBranch, $dateFrom, $dateTo, $followupMinutes)
        ));

    case 'log':
        api_ok(array_merge(
            $context,
            calls_get_log_data($pdo, $selectedBranch, $dateFrom, $dateTo, 500)
        ));

    case 'summary':
        api_ok(array_merge($context, [
            'rows'             => calls_get_summary_data($pdo, $selectedBranch, $dateFrom, $dateTo, $followupMinutes),
            'followup_minutes' => $followupMinutes,
        ]));

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
