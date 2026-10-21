<?php
/**
 * attendance_api.php — واجهة برمجة الحضور والانصراف للموظف (بريف ١٧٠)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=me     ← {employee: {id: …, full_name: …, branch_id: …, branch_name: …, expected_in: …, expected_out: …, allow_anywhere: …}}
 *   ?part=today  ← {today: {…}|null, att_date: …, is_carry_over: bool}
 *   ?part=period ← {period: […], from: …, to: …}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('attendance');

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/attendance_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    'me',
    'today',
    'period',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

// جميع أجزاء المرحلة (أ) تدعم GET فقط
api_method('GET');

$pdo = db();
$u   = current_user();
$uid = (int) ($u['id'] ?? 0);

// الموظف بيشوف حضوره هو فقط — أي employee_id في الطلب يُتجاهل تماماً
$emp = attendance_get_my_employee($pdo, $uid);

try {
    switch ($part) {
        case 'me':
            if (!$emp) {
                api_ok(['employee' => null]);
            }
            api_ok([
                'employee' => [
                    'id'             => (int) $emp['id'],
                    'full_name'      => (string) $emp['full_name'],
                    'branch_id'      => (int) $emp['branch_id'],
                    'branch_name'    => $emp['branch_name'] ?? '—',
                    'expected_in'    => $emp['expected_in'],
                    'expected_out'   => $emp['expected_out'],
                    'allow_anywhere' => (int) $emp['allow_anywhere'],
                ],
            ]);

        case 'today':
            if (!$emp) {
                api_ok([
                    'today'         => null,
                    'att_date'      => date('Y-m-d'),
                    'is_carry_over' => false,
                ]);
            }
            $activeInfo = attendance_get_active_date($pdo, (int) $emp['id']);
            $todayAtt   = attendance_get_today($pdo, (int) $emp['id'], $activeInfo['att_date']);
            api_ok([
                'today'         => $todayAtt,
                'att_date'      => $activeInfo['att_date'],
                'is_carry_over' => $activeInfo['is_carry_over'],
            ]);

        case 'period':
            $periodDates = attendance_calc_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['month'] ?? null);
            if (!$emp) {
                api_ok([
                    'period' => [],
                    'from'   => $periodDates['from'],
                    'to'     => $periodDates['to'],
                ]);
            }
            $periodAtt = attendance_get_period($pdo, (int) $emp['id'], $periodDates['from'], $periodDates['to']);
            api_ok([
                'period' => $periodAtt,
                'from'   => $periodDates['from'],
                'to'     => $periodDates['to'],
            ]);
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 422, 'error');
}
