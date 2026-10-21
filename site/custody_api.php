<?php
/**
 * custody_api.php — واجهة برمجية للعهد (عهدة الفرع / عهدة الموظف)
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٧)
 *
 * قراءة فقط (GET). العمليات المالية والكتابة ممنوعة كلياً (405).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
define('API_MODE', true);

require_once __DIR__ . '/auth.php';
require_login();
require_module_access('custody');
require_once __DIR__ . '/custody_lib.php';

// حظر أي طريقة غير GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$canBranchCustody   = is_owner() || can('custody:branch');
$canEmployeeCustody = is_owner() || can('custody:employee');

// إذا لم يكن يملك أي صلاحية
if (!$canBranchCustody && !$canEmployeeCustody) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$canIssue = is_owner() || can('treasury:operate');

$pdo = db();
$u = current_user();
$uid = (int) $u['id'];

$allBranches = custody_get_branches($pdo);
$allEmployees = custody_get_employees($pdo);
$allSuppliers = custody_get_suppliers($pdo);

$userEmp = custody_get_user_employee($pdo, $uid);
$currentUserEmpId = (int) ($userEmp['id'] ?? 0);

$defaultTab = $canEmployeeCustody ? 'employee' : 'branch';
$part = $_GET['part'] ?? ($_GET['tab'] ?? $defaultTab);

$allowedParts = ['branch', 'employee', 'meta', 'all'];
if (!in_array($part, $allowedParts, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid part'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'branch' && !$canBranchCustody) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden for branch custody'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($part === 'employee' && !$canEmployeeCustody) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden for employee custody'], JSON_UNESCAPED_UNICODE);
    exit;
}

// حل معرّف الفرع
$reqBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : null;
$branchId = custody_resolve_branch_id($u, $reqBranch, $allBranches);

// حل معرّف الموظف (الموظف العادي يرى عهدته هو فقط — الأونر فقط يقدر يختار أي موظف)
$reqEmp = isset($_GET['emp']) ? (int)$_GET['emp'] : null;
$selectedEmpId = custody_resolve_selected_emp_id($u, $reqEmp, $currentUserEmpId, $allEmployees);

$meta = [
    'allowed_tabs' => [
        'branch'   => $canBranchCustody,
        'employee' => $canEmployeeCustody,
    ],
    'default_tab' => $defaultTab,
    'can' => [
        'branch_custody'   => $canBranchCustody,
        'employee_custody' => $canEmployeeCustody,
        'treasury_operate' => $canIssue,
        'is_owner'         => is_owner(),
    ],
    'branches'  => $allBranches,
    'employees' => $allEmployees,
    'suppliers' => $allSuppliers,
    'current_user_emp_id' => $currentUserEmpId,
    'denominations' => [200, 100, 50, 20, 10, 5, 1],
    'tolerance'     => custody_tolerance(),
];

$response = ['ok' => true];

if ($part === 'meta') {
    $response['data'] = $meta;
} elseif ($part === 'branch') {
    $branchData = $branchId ? custody_get_branch_custody_data($pdo, $branchId, $allBranches) : null;
    $response['data'] = [
        'branch_id'   => $branchId,
        'branch_data' => $branchData,
    ];
} elseif ($part === 'employee') {
    $empData = custody_get_employee_custody_data($pdo, $selectedEmpId, $allEmployees);
    $response['data'] = [
        'employee_id'   => $selectedEmpId,
        'employee_data' => $empData,
    ];
} elseif ($part === 'all') {
    $data = ['meta' => $meta];
    if ($canBranchCustody && $branchId) {
        $data['branch'] = custody_get_branch_custody_data($pdo, $branchId, $allBranches);
    }
    if ($canEmployeeCustody && $selectedEmpId) {
        $data['employee'] = custody_get_employee_custody_data($pdo, $selectedEmpId, $allEmployees);
    }
    $response['data'] = $data;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

