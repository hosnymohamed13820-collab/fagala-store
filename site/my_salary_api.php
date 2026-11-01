<?php
/**
 * my_salary_api.php — نقطة نهاية JSON لتفاصيل المرتب (قراءة فقط)
 * نظام الفجالة ستور المالي (بريف ١٣٦)
 *
 *   GET /my_salary_api.php           ← تفاصيل آخر مرتب للمستخدم (أو موظف مختار للمدير)
 *   GET /my_salary_api.php?emp=123   ← للأونر ولمسؤول الرواتب فقط (hr:salaries)
 */
declare(strict_types=1);

define('API_MODE', true);
// مهلة الرقم بتتجدّد في الجلسة مع فتح التفاصيل.
define('API_KEEP_SESSION', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';
require_once __DIR__ . '/salary_view_lib.php';

api_begin();
require_login();
api_method('GET');

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$isManager = is_owner() || can('hr:salaries');
$requestedEmpId = isset($_GET['emp']) && (int)$_GET['emp'] > 0 ? (int)$_GET['emp'] : null;
$isOwn = salary_pin_is_own(db(), $uid, $requestedEmpId);
if (salary_pin_read_only($isOwn)) {
    api_fail('حسابك مشاهدة بس', 423, 'locked');
}

// الأرقام الحساسة: نفس رد مقفول اللي في item_sales_api.php
if (!salary_pin_unlocked($isOwn)) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}

$data = my_salary_data($uid, $isManager, $requestedEmpId);
$targetEmpId       = $data['target_emp_id'];
$employee          = $data['employee'];
$notLinked         = $data['not_linked'];
$salary            = $data['salary'];
$showDetails       = $data['show_details'];
$expiredOrNoSalary = $data['expired_or_no_salary'];

if ($notLinked) {
    api_ok([
        'status'  => 'not_linked',
        'message' => 'الحساب غير مربوط بملف موظف',
    ]);
}

if ($isManager && (!$employee || $targetEmpId === null || $targetEmpId <= 0)) {
    api_ok([
        'status'  => 'no_employee_selected',
        'message' => 'لم يتم تحديد موظف',
    ]);
}

if ($expiredOrNoSalary || !$showDetails || !$salary) {
    api_ok([
        'status'   => 'no_salary_or_expired',
        'message'  => 'مفيش بيانات مرتب دلوقتي',
        'employee' => $employee ? [
            'id'          => (int)$employee['id'],
            'full_name'   => (string)$employee['full_name'],
            'salary_mode' => (string)($employee['salary_mode'] ?? ''),
        ] : null,
    ]);
}

// تجهيز الأرقام والحسابات مطابقة لـ my_salary.php
$baseSalary = (float)$salary['base_salary'];
$bonuses    = (float)$salary['total_bonuses'];
$advances   = (float)$salary['total_advances'];
$penalties  = (float)$salary['total_penalties'];
$deficits   = (float)$salary['total_deficits'];
$cashierDef = (float)($salary['total_cashier_deficits'] ?? 0);
$netSalary  = (float)$salary['net_salary'];
$grossSalary = $netSalary - $bonuses + $advances + $penalties + $deficits + $cashierDef;

$isFixed = ($salary['work_hours'] === null && $salary['due_days'] === null);

api_ok([
    'status'   => 'ok',
    'employee' => [
        'id'          => (int)$employee['id'],
        'full_name'   => (string)$employee['full_name'],
        'salary_mode' => (string)($employee['salary_mode'] ?? ''),
    ],
    'period' => [
        'salary_month' => (string)$salary['salary_month'],
        'from'         => $salary['period_from'] ?? null,
        'to'           => $salary['period_to'] ?? null,
        'paid_at'      => $salary['paid_at'] ?? null,
    ],
    'attendance' => [
        'work_hours' => $salary['work_hours'] !== null ? (float)$salary['work_hours'] : null,
        'work_days'  => $salary['work_days'] !== null ? (float)$salary['work_days'] : null,
        'leave_days' => (!$isFixed && isset($salary['leave_days'])) ? (int)$salary['leave_days'] : null,
        'due_days'   => $salary['due_days'] !== null ? (float)$salary['due_days'] : null,
        'is_fixed'   => $isFixed,
    ],
    'amounts' => [
        'base_salary'      => $baseSalary,
        'gross_salary'     => $grossSalary,
        'total_bonuses'    => $bonuses,
        'total_advances'   => $advances,
        'total_penalties'  => $penalties,
        'total_deficits'   => $deficits,
        'cashier_deficits' => $cashierDef,
        'net_salary'       => $netSalary,
    ],
]);
