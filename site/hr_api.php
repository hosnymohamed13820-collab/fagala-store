<?php
/**
 * hr_api.php — واجهة برمجية للموارد البشرية
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٨٠: أ، ب، ج)
 *
 * قراءة فقط (GET). العمليات المالية وصرف المرتبات ممنوعة كلياً (405).
 * الأرقام الحساسة (المرتبات والسلف) محمية بقفل 423 عند القفل.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
define('API_MODE', true);

require_once __DIR__ . '/auth.php';
require_login();
require_module_access('hr');
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/hr_lib.php';

// حظر أي طريقة غير GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();
$allowedTabs = hr_get_allowed_tabs($u);
if (empty($allowedTabs)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — No HR modules allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$defaultTab = hr_resolve_active_tab($allowedTabs, null);
$part = $_GET['part'] ?? ($_GET['tab'] ?? 'meta');

$validParts = ['employees', 'attendance', 'requests', 'bonuses', 'penalties', 'deficits', 'advances', 'salaries', 'meta', 'all'];
if (!in_array($part, $validParts, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid part'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$response = ['ok' => true];
$empListFull = null; // النسخة الكاملة من الموظفين — للحسابات الداخلية فقط، مش بترجع في الرد

/**
 * إخفاء مفاتيح الأرقام الحساسة من صفوف الموظفين لما الأرقام مقفولة
 * (base_salary / expected_monthly_hours / salary_mode)
 */
function hr_api_mask_employee_salary(array $employees): array
{
    if (sens_unlocked()) {
        return $employees;
    }
    $hiddenKeys = ['base_salary', 'expected_monthly_hours', 'salary_mode'];
    foreach ($employees as $i => $emp) {
        if (!is_array($emp)) {
            continue;
        }
        foreach ($hiddenKeys as $k) {
            unset($employees[$i][$k]);
        }
    }
    return $employees;
}

// 180a: الموظفين
if ($part === 'employees') {
    if (!can('hr:employees')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for employees'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response['data'] = [
        'employees' => hr_api_mask_employee_salary(hr_get_employees_list($pdo)),
    ];
}

// 180a: الحضور
elseif ($part === 'attendance') {
    if (!can('hr:attendance')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for attendance'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $attMonth = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $attMonth)) $attMonth = date('Y-m');
    $response['data'] = [
        'month' => $attMonth,
        'rows'  => hr_get_attendance_rows($pdo, $attMonth),
    ];
}

// 180a: طلبات التصحيح
elseif ($part === 'requests') {
    if (!can('hr:requests')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for requests'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $reqStatus = (string)($_GET['rstatus'] ?? 'pending');
    $response['data'] = [
        'status'        => $reqStatus,
        'requests'      => hr_get_attendance_requests($pdo, $reqStatus, 100),
        'pending_count' => hr_get_pending_requests_count($pdo),
    ];
}

// 180b: المكافآت
elseif ($part === 'bonuses') {
    if (!can('hr:bonuses')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for bonuses'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response['data'] = [
        'bonuses' => hr_get_bonuses($pdo, 100),
    ];
}

// 180b: الجزاءات
elseif ($part === 'penalties') {
    if (!can('hr:penalties')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for penalties'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response['data'] = [
        'penalties' => hr_get_penalties($pdo, 100),
    ];
}

elseif ($part === 'deficits') {
    if (!can('hr:penalties')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for deficits'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response['data'] = [
        'deficits' => hr_get_deficits($pdo, 100),
    ];
}

// 180b: السلف (حساس)
elseif ($part === 'advances') {
    if (!can('hr:advances')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for advances'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!sens_unlocked()) {
        http_response_code(423);
        echo json_encode(['ok' => false, 'error' => 'locked'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response['data'] = hr_get_advances_data($pdo, 100);
}

// 180c: المرتبات (حساس)
elseif ($part === 'salaries') {
    if (!can('hr:salaries')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden for salaries'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!sens_unlocked()) {
        http_response_code(423);
        echo json_encode(['ok' => false, 'error' => 'locked'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dates = hr_get_payroll_dates($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['month'] ?? null);
    $empList = hr_get_employees_list($pdo);
    $payroll = hr_calc_payroll_data($pdo, $empList, $dates['from'], $dates['to'], $dates['month']);

    $totGross = 0.0;
    $totBonuses = 0.0;
    $totDeductions = 0.0;
    $totToPay = 0.0;
    $totRemaining = 0.0;
    foreach ($payroll as $p) {
        $totGross      += (float)$p['gross_salary'];
        $totBonuses    += (float)$p['total_bonuses'];
        $totDeductions += (float)$p['total_deductions'];
        $totToPay      += (float)$p['net_salary'];
        $totRemaining  += (float)$p['carry_to_advance'];
    }

    $response['data'] = [
        'dates'    => $dates,
        'payroll'  => $payroll,
        'totals'   => [
            'gross'      => round($totGross, 2),
            'bonuses'    => round($totBonuses, 2),
            'deductions' => round($totDeductions, 2),
            'net_to_pay' => round($totToPay, 2),
            'carry_rem'  => round($totRemaining, 2),
        ],
    ];
}

// معلومات المديول والقوائم
elseif ($part === 'meta') {
    $response['data'] = [
        'allowed_tabs'     => $allowedTabs,
        'default_tab'      => $defaultTab,
        'sens_unlocked'    => sens_unlocked(),
        'pay_treasuries'   => hr_get_pay_treasuries($pdo),
        'simple_employees' => hr_get_simple_employees($pdo),
        'pending_requests' => hr_get_pending_requests_count($pdo),
    ];
}

// الشجرة الكاملة
elseif ($part === 'all') {
    $data = [
        'allowed_tabs'     => $allowedTabs,
        'default_tab'      => $defaultTab,
        'sens_unlocked'    => sens_unlocked(),
        'pay_treasuries'   => hr_get_pay_treasuries($pdo),
        'simple_employees' => hr_get_simple_employees($pdo),
        'pending_requests' => hr_get_pending_requests_count($pdo),
    ];
    if (can('hr:employees')) {
        $empListFull = hr_get_employees_list($pdo);
        $data['employees'] = hr_api_mask_employee_salary($empListFull);
    }
    if (can('hr:attendance')) {
        $attMonth = date('Y-m');
        $data['attendance'] = [
            'month' => $attMonth,
            'rows'  => hr_get_attendance_rows($pdo, $attMonth),
        ];
    }
    if (can('hr:requests')) {
        $data['requests'] = hr_get_attendance_requests($pdo, 'pending', 100);
    }
    if (can('hr:bonuses')) {
        $data['bonuses'] = hr_get_bonuses($pdo, 100);
    }
    if (can('hr:penalties')) {
        $data['penalties'] = hr_get_penalties($pdo, 100);
        $data['deficits'] = hr_get_deficits($pdo, 100);
    }
    if (can('hr:advances')) {
        if (sens_unlocked()) {
            $data['advances'] = hr_get_advances_data($pdo, 100);
        } else {
            $data['advances'] = ['locked' => true];
        }
    }
    if (can('hr:salaries')) {
        if (sens_unlocked()) {
            $dates = hr_get_payroll_dates(null, null, null);
            $empList = $empListFull ?? hr_get_employees_list($pdo);
            $data['salaries'] = [
                'dates'   => $dates,
                'payroll' => hr_calc_payroll_data($pdo, $empList, $dates['from'], $dates['to'], $dates['month']),
            ];
        } else {
            $data['salaries'] = ['locked' => true];
        }
    }
    $response['data'] = $data;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

