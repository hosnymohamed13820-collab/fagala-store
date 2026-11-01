<?php
/**
 * employee_statement_api.php — نقطة نهاية JSON لكشف حساب الموظف (قراءة فقط)
 * نظام الفجالة ستور المالي (بريف ١٣٦)
 *
 *   GET /employee_statement_api.php           ← كشف حساب المستخدم الحالي
 *   GET /employee_statement_api.php?emp=123   ← للأونر ولمسؤول السلف فقط (hr:advances)
 */
declare(strict_types=1);

define('API_MODE', true);
// مهلة الرقم بتتجدّد في الجلسة مع فتح الكشف.
define('API_KEEP_SESSION', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/salary_view_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';

api_begin();
require_login();
api_method('GET');

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$isManager = is_owner() || can('hr:advances');
$requestedEmpId = isset($_GET['emp']) && (int)$_GET['emp'] > 0 ? (int)$_GET['emp'] : null;

$pinRequired = salary_pin_statement_required(db(), $uid, $isManager, $requestedEmpId);
if ($pinRequired && !salary_pin_unlocked()) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}

$data = employee_statement_data($uid, $isManager, $requestedEmpId);
$targetEmpId    = $data['target_emp_id'];
$employee       = $data['employee'];
$notLinked      = $data['not_linked'];
$ledgerEntries  = $data['ledger_entries'];
$currentBalance = $data['current_balance'];

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

$moveTypeLabels = [
    'advance'          => 'سلفة',
    'salary_deduction' => 'خصم من المرتب',
    'cash_repay'       => 'سداد نقدي',
    'cancel'           => 'إلغاء سلفة',
    'opening'          => 'رصيد افتتاحي',
];

$curBalInfo = format_ledger_balance($currentBalance);

api_ok([
    'status'   => 'ok',
    'employee' => $employee ? [
        'id'        => (int)$employee['id'],
        'full_name' => (string)$employee['full_name'],
    ] : null,
    'balance' => [
        'current_balance' => (float)$currentBalance,
        'label'           => $curBalInfo['label'],
        'type'            => $curBalInfo['type'],
    ],
    'entries_count' => count($ledgerEntries),
    'entries'       => array_map(function (array $entry) use ($moveTypeLabels) {
        $typeLabel = $moveTypeLabels[$entry['move_type']] ?? $entry['move_type'];
        $desc = trim($entry['description']);
        return [
            'id'            => $entry['id'],
            'entry_date'    => $entry['entry_date'],
            'direction'     => $entry['direction'],
            'amount'        => $entry['amount'],
            'move_type'     => $entry['move_type'],
            'type_label'    => $typeLabel,
            'description'   => $desc,
            'treasury_id'   => $entry['treasury_id'],
            'treasury_name' => $entry['treasury_name'],
            'ref_type'      => $entry['ref_type'],
            'ref_id'        => $entry['ref_id'],
            'running_bal'   => $entry['running_bal'],
        ];
    }, $ledgerEntries),
]);
