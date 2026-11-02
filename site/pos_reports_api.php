<?php
/** pos_reports_api.php — API تقارير حارس العجز لنقطة البيع. */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
api_method('GET');
api_require('pos:reports');
require_once __DIR__ . '/pos_reports_lib.php';

$pdo = db();
$user = current_user() ?? [];
$part = (string) ($_GET['part'] ?? 'report');

try {
    if ($part === 'init') {
        $allowed = sale_allowed_branch_ids($pdo, $user);
        $branches = [];
        $cashiers = [];
        if ($allowed) {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $branchStmt = $pdo->prepare("SELECT id, name FROM branches WHERE id IN ($placeholders) ORDER BY name");
            $branchStmt->execute($allowed);
            $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

            $cashierStmt = $pdo->prepare(
                "SELECT DISTINCT u.id, u.full_name
                   FROM users u
                   JOIN sale_sessions ss ON ss.cashier_id = u.id
                  WHERE ss.branch_id IN ($placeholders)
                  ORDER BY u.full_name"
            );
            $cashierStmt->execute($allowed);
            $cashiers = $cashierStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $meta = [
            'definitions' => pos_reports_definitions(),
            'branches' => $branches,
            'cashiers' => $cashiers,
        ];

        $report = (string) ($_GET['report'] ?? 'removed');
        $filter = pos_reports_filters($pdo, $_GET, $user);
        $result = pos_reports_run($pdo, $report, $filter);
        $reportData = [
            'report' => $report,
            'definition' => $result['definition'],
            'rows' => $result['rows'],
            'filters' => [
                'from' => $filter['from'],
                'to' => $filter['to'],
                'branch_id' => $filter['branch_id'],
                'cashier_id' => $filter['cashier_id'],
            ],
        ];

        api_ok([
            'meta'   => $meta,
            'report' => $reportData,
        ]);
    }
    if ($part === 'meta') {
        $allowed = sale_allowed_branch_ids($pdo, $user);
        if (!$allowed) {
            api_ok(['definitions' => pos_reports_definitions(), 'branches' => [], 'cashiers' => []]);
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $branchStmt = $pdo->prepare("SELECT id, name FROM branches WHERE id IN ($placeholders) ORDER BY name");
        $branchStmt->execute($allowed);
        $cashierStmt = $pdo->prepare(
            "SELECT DISTINCT u.id, u.full_name
               FROM users u
               JOIN sale_sessions ss ON ss.cashier_id = u.id
              WHERE ss.branch_id IN ($placeholders)
              ORDER BY u.full_name"
        );
        $cashierStmt->execute($allowed);
        api_ok([
            'definitions' => pos_reports_definitions(),
            'branches' => $branchStmt->fetchAll(PDO::FETCH_ASSOC),
            'cashiers' => $cashierStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }
    if ($part !== 'report') {
        api_fail('جزء غير معروف', 400, 'bad_request');
    }
    $report = (string) ($_GET['report'] ?? 'removed');
    $filter = pos_reports_filters($pdo, $_GET, $user);
    $result = pos_reports_run($pdo, $report, $filter);
    if (!empty($_GET['export'])) {
        pos_reports_export($report, $result, $filter);
    }
    api_ok([
        'report' => $report,
        'definition' => $result['definition'],
        'rows' => $result['rows'],
        'filters' => [
            'from' => $filter['from'],
            'to' => $filter['to'],
            'branch_id' => $filter['branch_id'],
            'cashier_id' => $filter['cashier_id'],
        ],
    ]);
} catch (SaleApiException $e) {
    api_fail($e->getMessage(), $e->http, $e->apiCode);
} catch (Throwable $e) {
    error_log('pos_reports_api: ' . $e->getMessage());
    api_fail('حصل خطأ أثناء تجهيز تقرير نقطة البيع.', 500, 'server');
}
