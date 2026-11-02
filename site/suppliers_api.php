<?php
/**
 * suppliers_api.php — واجهة برمجة كشوف حسابات الموردين (بريف ١٧٣ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=summary[&br=...&from=...&to=...]         ← ملخص أرصدة الموردين والفروع والإجماليات
 *   ?part=statement&supplier=ID[&br=...&from=...&to=...] ← كشف حساب المورد وحركاته التفصيلية
 *   ?part=list                                     ← قائمة الموردين النشطين
 *   ?part=all[&supplier=ID&br=...&from=...&to=...] ← الشجرة الكاملة
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('suppliers');

// في إشراقة القايمة المحلية بس؛ كشف ترو يتقفل قبل مكتباته واستعلاماته.
if (!site_profile()['true_integration']) {
    $localPart = (string) ($_GET['part'] ?? (isset($_GET['supplier']) ? 'statement' : 'summary'));
    api_require('suppliers:' . $localPart);
    if ($localPart !== 'list') api_fail('الجزء المطلوب غير معروف', 400, 'bad_request');
    api_method('GET');
    require_once __DIR__ . '/suppliers_lib.php';
    $activeList = suppliers_get_active_list(db());
    api_ok(['suppliers' => $activeList, 'count' => count($activeList)]);
}

require_once __DIR__ . '/suppliers_lib.php';

// الموردون في المرحلة الحالية قراءة فقط
api_method('GET');

// ?party= القديم محظور في الـAPI ومكانه branch_recon_api.php
if (isset($_GET['party']) && $_GET['party'] !== '') {
    api_fail('الطرف القديم تم نقله إلى branch_recon_api.php', 400, 'deprecated_param');
}

$pdo     = db();
$stStart = suppliers_get_start_date($pdo);
$params  = suppliers_parse_params($_GET, $stStart);
$from    = $params['from'];
$to      = $params['to'];
$sid     = $params['selected_supplier_id'];
$brKey   = $params['curr_branch_key'];
$branch  = $params['curr_branch'];

$part = (string) ($_GET['part'] ?? ($sid > 0 ? 'statement' : 'summary'));

$allowedParts = ['summary', 'statement', 'list', 'all'];
if (!in_array($part, $allowedParts, true)) {
    api_fail('الجزء المطلوب غير معروف', 400, 'bad_request');
}

try {
    switch ($part) {
        case 'list':
            $activeList = suppliers_get_active_list($pdo);
            api_ok([
                'suppliers' => $activeList,
                'count'     => count($activeList),
            ]);

        case 'summary':
            $summaryData = suppliers_get_summary($pdo, $from, $to, $stStart, $branch);
            api_ok([
                'start_date'        => $stStart,
                'from'              => $from,
                'to'                => $to,
                'branch_key'        => $brKey,
                'branch'            => $branch,
                'branches'          => SUPP_BRANCHES,
                'statement_summary' => $summaryData['statement_summary'],
                'totals'            => $summaryData['totals'],
                'can'               => [
                    'branch_recon' => can('branch_recon'),
                ],
            ]);

        case 'statement':
            if ($sid <= 0) {
                api_fail('معرف المورد مطلوب', 400, 'bad_request');
            }
            $statementData = suppliers_get_statement($pdo, $sid, $from, $to, $stStart, $branch);
            if ($statementData === null) {
                api_fail('المورد غير موجود', 404, 'not_found');
            }
            api_ok(array_merge([
                'start_date' => $stStart,
                'from'       => $from,
                'to'         => $to,
                'branch_key' => $brKey,
                'branch'     => $branch,
            ], $statementData));

        case 'all':
            if ($sid > 0) {
                $statementData = suppliers_get_statement($pdo, $sid, $from, $to, $stStart, $branch);
                if ($statementData === null) {
                    api_fail('المورد غير موجود', 404, 'not_found');
                }
                api_ok(array_merge([
                    'start_date' => $stStart,
                    'from'       => $from,
                    'to'         => $to,
                    'branch_key' => $brKey,
                    'branch'     => $branch,
                    'branches'   => SUPP_BRANCHES,
                    'suppliers'  => suppliers_get_active_list($pdo),
                ], $statementData));
            } else {
                $summaryData = suppliers_get_summary($pdo, $from, $to, $stStart, $branch);
                api_ok([
                    'start_date'        => $stStart,
                    'from'              => $from,
                    'to'                => $to,
                    'branch_key'        => $brKey,
                    'branch'            => $branch,
                    'branches'          => SUPP_BRANCHES,
                    'suppliers'         => suppliers_get_active_list($pdo),
                    'statement_summary' => $summaryData['statement_summary'],
                    'totals'            => $summaryData['totals'],
                    'can'               => [
                        'branch_recon' => can('branch_recon'),
                    ],
                ]);
            }
    }
} catch (Throwable $e) {
    errlog_exception($e, 'suppliers_api');
    api_fail('حدث خطأ أثناء معالجة كشوف حسابات الموردين', 500, 'server_error');
}
