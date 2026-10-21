<?php
/**
 * attendance_lib.php — مكتبة دوال الحضور والانصراف (بريف ١٧٠)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/**
 * حساب فترة سجل الحضور:
 * إما من/إلى، أو شهر، أو الافتراضي من ٢٦ الشهر الماضي إلى ٢٥ الشهر الحالي.
 */
function attendance_calc_period(?string $from = null, ?string $to = null, ?string $month = null): array
{
    if ($from !== null && $to !== null) {
        if (!strtotime($from)) $from = date('Y-m-01');
        if (!strtotime($to))   $to   = date('Y-m-t');
    } elseif ($month !== null && preg_match('/^\d{4}-\d{2}$/', (string)$month)) {
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
    } else {
        // نفس فترة المرتب: من ٢٦ الشهر اللي فات لـ٢٥ الشهر ده — عشان اللي
        // الموظف بيشوفه هنا يبقى هو اللي مرتبه هيتحسب عليه، مش شهر تقويمي.
        //
        // 🔴 بتقلب على الفترة الجديدة **يوم ١** من غير أي مهلة — عكس شاشة
        // الرواتب اللي ليها مهلة ٥ أيام. ده مقصود: الموظف عايز يتابع فترته
        // الجارية، والأونر عايز يخلّص صرف الفترة اللي فاتت.
        $from = date('Y-m-26', strtotime('first day of last month'));
        $to   = date('Y-m-25');
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * تحديد اليوم الشغال الفعلي (مع دعم الورديات التي تعبر منتصف الليل).
 */
function attendance_get_active_date(PDO $pdo, int $empId): array
{
    $today = date('Y-m-d');
    $attDate = $today;
    $isCarryOver = false;

    if ($empId > 0) {
        $graceHours = business_day_start_hour();
        $hourNow = (int)date('G');

        if ($hourNow < $graceHours) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            // فيه دخول امبارح ومفيش خروج؟
            $q = $pdo->prepare(
                'SELECT 1 FROM attendance
                 WHERE employee_id = ? AND att_date = ?
                   AND check_in IS NOT NULL AND check_out IS NULL'
            );
            $q->execute([$empId, $yesterday]);

            if ($q->fetch()) {
                $attDate     = $yesterday;
                $isCarryOver = true;
            }
        }
    }

    return ['att_date' => $attDate, 'is_carry_over' => $isCarryOver];
}

/**
 * جلب سجل حضور اليوم لموظف.
 */
function attendance_get_today(PDO $pdo, int $empId, string $attDate): ?array
{
    if ($empId <= 0) return null;
    $a = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date=?');
    $a->execute([$empId, $attDate]);
    $res = $a->fetch();
    return $res ?: null;
}

/**
 * جلب سجل حضور الموظف خلال فترة محددة.
 */
function attendance_get_period(PDO $pdo, int $empId, string $from, string $to): array
{
    if ($empId <= 0) return [];
    $m = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? ORDER BY att_date DESC');
    $m->execute([$empId, $from, $to]);
    return $m->fetchAll();
}

/**
 * جلب موظف مربوط بمستخدم مع اسم الفرع (بدون أي حقول حساسة).
 */
function attendance_get_my_employee(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    $st = $pdo->prepare(
        'SELECT e.id, e.full_name, e.branch_id, b.name AS branch_name,
                e.expected_in, e.expected_out, e.allow_anywhere
         FROM employees e
         LEFT JOIN branches b ON b.id = e.branch_id
         WHERE e.user_id = ? AND e.is_active = 1'
    );
    $st->execute([$userId]);
    $res = $st->fetch();
    return $res ?: null;
}
