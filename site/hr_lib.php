<?php
/**
 * hr_lib.php — مكتبة استعلامات وحسابات الموارد البشرية
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٨٠: أ، ب، ج)
 *
 * قراءة فقط (GET). العمليات وصرف المرتبات والسلف والاعتمادات وتصدير الإكسيل تبقى في الصفحة.
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sensitive_lib.php';

const HR_NON_TAB_PARTS = [];
const PAYROLL_GRACE_DAYS = 5;

/**
 * استخراج التابات المسموحة للمستخدم في مديول الموارد البشرية
 */
function hr_get_allowed_tabs(array $user): array
{
    $hrParts = module_parts()['hr'] ?? [];
    $allowedTabs = [];
    foreach ($hrParts as $tabKey => $tabLabel) {
        if (in_array($tabKey, HR_NON_TAB_PARTS, true)) continue;
        if (can('hr:' . $tabKey)) {
            $allowedTabs[$tabKey] = $tabLabel;
        }
    }
    return $allowedTabs;
}

/**
 * تحديد التاب النشط
 */
function hr_resolve_active_tab(array $allowedTabs, ?string $requestedTab): string
{
    if ($requestedTab !== null && isset($allowedTabs[$requestedTab])) {
        return $requestedTab;
    }
    return (string) array_key_first($allowedTabs);
}

/**
 * خزائن الصرف المتاحة
 */
function hr_get_pay_treasuries(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name FROM treasuries WHERE branch_id IS NULL AND is_active=1
         ORDER BY FIELD(type,'cash','main','visa','instapay','card')"
    )->fetchAll();
}

/**
 * قائمة الموظفين المختصرة
 */
function hr_get_simple_employees(PDO $pdo): array
{
    return $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();
}

// =====================================================================
// 180a: الموظفون، الحضور، طلبات التصحيح
// =====================================================================

/**
 * قائمة الموظفين المفصلة مع الفروع والسلف المعلقة
 */
function hr_get_employees_list(PDO $pdo): array
{
    return $pdo->query(
        "SELECT e.*, b.name AS branch_name,
                COALESCE((SELECT SUM(COALESCE(remaining, amount)) FROM employee_advances WHERE employee_id=e.id AND status='pending'),0) AS pending_advance
         FROM employees e
         LEFT JOIN branches b ON b.id=e.branch_id WHERE e.is_active=1 ORDER BY e.full_name"
    )->fetchAll();
}

/**
 * سجلات الحضور والانصراف لشهر محدد
 */
function hr_get_attendance_rows(PDO $pdo, string $attMonth): array
{
    $st = $pdo->prepare(
        "SELECT a.*, e.full_name, e.expected_in, e.expected_out
         FROM attendance a JOIN employees e ON e.id=a.employee_id
         WHERE DATE_FORMAT(a.att_date,'%Y-%m') = ?
         ORDER BY a.att_date DESC, e.full_name"
    );
    $st->execute([$attMonth]);
    return $st->fetchAll();
}

/**
 * طلبات تصحيح الحضور والانصراف
 */
function hr_get_attendance_requests(PDO $pdo, string $reqStatus = 'pending', int $limit = 100): array
{
    if (!in_array($reqStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
        $reqStatus = 'pending';
    }
    $reqSql = "SELECT r.*, e.full_name FROM attendance_requests r JOIN employees e ON e.id=r.employee_id";
    $reqArgs = [];
    if ($reqStatus !== 'all') {
        $reqSql .= " WHERE r.status = ?";
        $reqArgs[] = $reqStatus;
    }
    $reqSql .= " ORDER BY r.status='pending' DESC, r.id DESC LIMIT $limit";
    $st = $pdo->prepare($reqSql);
    $st->execute($reqArgs);
    return $st->fetchAll();
}

/**
 * عدد طلبات التصحيح المعلقة
 */
function hr_get_pending_requests_count(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM attendance_requests WHERE status='pending'")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

// =====================================================================
// 180b: السلف، المكافآت، الجزاءات
// =====================================================================

/**
 * جلب سجل السلف والموظفين المستحقين لأرصدة دائنة (الشركة مدينة لهم)
 */
function hr_get_advances_data(PDO $pdo, int $limit = 100): array
{
    $advances = $pdo->query(
        "SELECT a.*, e.full_name FROM employee_advances a JOIN employees e ON e.id=a.employee_id ORDER BY a.id DESC LIMIT $limit"
    )->fetchAll();

    $owedToEmps = [];
    $advEmps = $pdo->query('SELECT id, full_name FROM employees WHERE is_active = 1')->fetchAll();
    $advBals = emp_ledger_balances(array_column($advEmps, 'id'), $pdo);
    foreach ($advEmps as $ae) {
        $bal = (float) ($advBals[(int) $ae['id']] ?? 0);
        if ($bal < -0.005) {
            $owedToEmps[] = ['id' => (int) $ae['id'], 'full_name' => $ae['full_name'], 'owed' => abs($bal)];
        }
    }
    usort($owedToEmps, fn($a, $b) => $b['owed'] <=> $a['owed']);

    return [
        'advances'     => $advances,
        'owed_to_emps' => $owedToEmps,
    ];
}

/**
 * جلب سجل المكافآت
 */
function hr_get_bonuses(PDO $pdo, int $limit = 100): array
{
    return $pdo->query(
        "SELECT b.*, e.full_name FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id ORDER BY b.id DESC LIMIT $limit"
    )->fetchAll();
}

/**
 * جلب سجل الجزاءات المجمع (جزاءات + عجز عهدة + عجز وردية متجاوز للعتبة)
 */
function hr_get_penalties(PDO $pdo, int $limit = 100): array
{
    $tolerance = custody_tolerance();
    $penStmt = $pdo->prepare(
        "SELECT p.penalty_date AS deduction_date,
                e.full_name,
                'penalty' AS source_type,
                b.name AS branch_name,
                p.amount,
                0.0 AS difference,
                p.reason,
                p.status,
                NULL AS shift_name,
                NULL AS shift_id,
                p.id AS sort_id
           FROM employee_penalties p
           JOIN employees e ON e.id = p.employee_id
           LEFT JOIN branches b ON b.id = e.branch_id

          UNION ALL

         SELECT DATE(cd.created_at) AS deduction_date,
                e.full_name,
                'custody_deficit' AS source_type,
                b.name AS branch_name,
                cd.amount,
                0.0 AS difference,
                COALESCE(cd.notes, 'عجز تسوية عهدة') AS reason,
                cd.status,
                NULL AS shift_name,
                NULL AS shift_id,
                cd.id AS sort_id
           FROM custody_deficits cd
           LEFT JOIN employees e ON e.id = cd.employee_id
           LEFT JOIN branches b ON b.id = COALESCE(cd.branch_id, e.branch_id)

          UNION ALL

         SELECT s.business_date AS deduction_date,
                COALESCE(e.full_name, u.full_name, '—') AS full_name,
                'shift' AS source_type,
                b.name AS branch_name,
                ABS(s.difference) AS amount,
                s.difference AS difference,
                NULL AS reason,
                s.status,
                s.shift_name,
                s.id AS shift_id,
                s.id AS sort_id
           FROM shifts s
           LEFT JOIN branches b ON b.id = s.branch_id
           LEFT JOIN users u ON u.id = s.user_id
           LEFT JOIN employees e ON e.user_id = u.id
          WHERE s.difference <> 0
            AND ABS(s.difference) - ? > 0.0001

          ORDER BY deduction_date DESC, sort_id DESC
          LIMIT $limit"
    );
    $penStmt->execute([$tolerance]);
    $penaltiesRaw = $penStmt->fetchAll();

    $penalties = [];
    foreach ($penaltiesRaw as $r) {
        if ($r['source_type'] === 'penalty') {
            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'shift_name'     => '—',
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => 'جزاء',
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => (float) $r['amount'],
                'tolerance'      => '—',
                'exceeded'       => '—',
                'reason'         => $r['reason'] ?? '—',
                'status'         => (string) $r['status'],
            ];
        } elseif ($r['source_type'] === 'custody_deficit') {
            $amount = (float) $r['amount'];
            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'shift_name'     => '—',
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => 'عجز عهدة',
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => $amount,
                'tolerance'      => '—',
                'exceeded'       => number_format($amount, 2),
                'reason'         => $r['reason'] ?? 'عجز تسوية عهدة',
                'status'         => (string) $r['status'],
            ];
        } elseif ($r['source_type'] === 'shift') {
            $diff = (float) $r['difference'];
            $vs = shift_variance_status($diff, $tolerance);
            if (!$vs['over_limit']) {
                continue;
            }
            $dedType = $vs['kind'] === 'shortage' ? 'عجز وردية' : 'زيادة وردية';
            $shiftDisplay = ($r['shift_name'] !== null && $r['shift_name'] !== '') ? 'وردية ' . $r['shift_name'] : 'وردية —';
            $branchDisplay = $r['branch_name'] ?: 'بدون فرع';
            $reason = "{$branchDisplay} · {$r['deduction_date']} · {$shiftDisplay} (إجمالي " . ($vs['kind'] === 'shortage' ? 'عجز' : 'زيادة') . ' ' . number_format($vs['amount'], 2) . ' ج)';

            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'shift_name'     => $r['shift_name'] ?: '—',
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => $dedType,
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => $vs['amount'],
                'tolerance'      => number_format($vs['tolerance'], 2),
                'exceeded'       => number_format($vs['exceeded'], 2),
                'reason'         => $reason,
                'status'         => (string) $r['status'],
            ];
        }
    }
    return $penalties;
}

// =====================================================================
// 180c: المرتبات (حساب المستحقات والأقساط)
// =====================================================================

/**
 * حساب فترات صرف المرتبات مع مهلة الخمسة أيام
 */
function hr_get_payroll_dates(?string $fromParam, ?string $toParam, ?string $monthParam): array
{
    $salAnchor = (int) date('j') <= PAYROLL_GRACE_DAYS
        ? strtotime('first day of last month')
        : strtotime('first day of this month');
    $defaultSalTo   = date('Y-m-25', $salAnchor);
    $defaultSalFrom = date('Y-m-26', strtotime('-1 month', $salAnchor));

    $salFrom = $fromParam ?? $defaultSalFrom;
    $salTo   = $toParam   ?? $defaultSalTo;
    if (!strtotime($salFrom)) $salFrom = $defaultSalFrom;
    if (!strtotime($salTo))   $salTo   = $defaultSalTo;

    $salMonth = $monthParam ?? date('Y-m', strtotime($salTo));
    if (!preg_match('/^\d{4}-\d{2}$/', $salMonth)) $salMonth = date('Y-m');

    return [
        'from'  => $salFrom,
        'to'    => $salTo,
        'month' => $salMonth,
    ];
}

/**
 * حساب مستحقات الرواتب والاستقطاعات بالقرش لجميع الموظفين
 */
function hr_calc_payroll_data(PDO $pdo, array $empList, string $salFrom, string $salTo, string $salMonth): array
{
    // 1) ساعات العمل المجمّعة
    $stAtt = $pdo->prepare(
        "SELECT employee_id, COALESCE(SUM(work_hours), 0) AS total_hours
         FROM attendance
         WHERE att_date BETWEEN ? AND ?
         GROUP BY employee_id"
    );
    $stAtt->execute([$salFrom, $salTo]);
    $empHours = $stAtt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2) المكافآت المعلّقة
    $bonPendingStmt = $pdo->query(
        "SELECT employee_id,
                COUNT(*) AS cnt,
                MIN(id) AS single_id,
                COALESCE(SUM(amount), 0) AS total_amount
         FROM employee_bonuses
         WHERE status = 'pending'
         GROUP BY employee_id"
    );
    $bonPendingMap = [];
    foreach ($bonPendingStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
        $bonPendingMap[(int)$bRow['employee_id']] = [
            'cnt'          => (int)$bRow['cnt'],
            'single_id'    => $bRow['single_id'] !== null ? (int)$bRow['single_id'] : null,
            'total_amount' => (float)$bRow['total_amount'],
        ];
    }

    // 3) السلف المعلّقة
    $advStmt = $pdo->query(
        "SELECT employee_id, id, amount, remaining, monthly_deduction, adv_type
           FROM employee_advances
          WHERE status = 'pending'
          ORDER BY employee_id ASC, id ASC"
    );
    $advRowsByEmp = [];
    foreach ($advStmt->fetchAll(PDO::FETCH_ASSOC) as $advR) {
        $advRowsByEmp[(int)$advR['employee_id']][] = $advR;
    }

    $allEmpIds = !empty($empList) ? array_column($empList, 'id') : [];
    $ledgerBalancesMap = emp_ledger_balances($allEmpIds, $pdo);

    // 4) الجزاءات المعلّقة
    $penMap = $pdo->query(
        "SELECT employee_id, COALESCE(SUM(amount), 0)
         FROM employee_penalties WHERE status='pending'
         GROUP BY employee_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // 5) عجز العهدة المعلّق
    $defMap = $pdo->query(
        "SELECT employee_id, COALESCE(SUM(amount), 0)
         FROM custody_deficits WHERE status='pending'
         GROUP BY employee_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // 6) عجز الكاشير المتجاوز للعتبة
    $tolerance = custody_tolerance();
    $cashierDefStmt = $pdo->prepare(
        "SELECT e.id AS employee_id,
                COALESCE(SUM(ABS(s.difference)), 0) AS exceeded_total,
                COUNT(*) AS shift_cnt
           FROM shifts s
           JOIN users     u ON u.id = s.user_id
           JOIN employees e ON e.user_id = u.id
           LEFT JOIN salary_shift_deficits d ON d.shift_id = s.id
          WHERE s.business_date BETWEEN ? AND ?
            AND s.difference < 0
            AND ABS(s.difference) - ? > 0.0001
            AND d.id IS NULL
          GROUP BY e.id"
    );
    $cashierDefStmt->execute([$salFrom, $salTo, $tolerance]);
    $cashierDeficitMap = [];
    foreach ($cashierDefStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
        $cashierDeficitMap[(int)$cRow['employee_id']] = [
            'exceeded_total' => (float)$cRow['exceeded_total'],
            'shift_cnt'      => (int)$cRow['shift_cnt'],
        ];
    }

    // 7) المرتبات المصروفة للشهر المختار
    $stPaid = $pdo->prepare("SELECT employee_id, id FROM salaries WHERE salary_month = ? AND reversed_at IS NULL");
    $stPaid->execute([$salMonth]);
    $paidMonthMap = $stPaid->fetchAll(PDO::FETCH_KEY_PAIR);

    // 8) مسودات التعديلات
    $stAdj = $pdo->prepare(
        "SELECT employee_id, leave_days, due_days, monthly_take, standing_take, cashier_take
           FROM payroll_adjustments
          WHERE month = ?"
    );
    $stAdj->execute([$salMonth]);
    $adjustmentsMap = [];
    foreach ($stAdj->fetchAll(PDO::FETCH_ASSOC) as $adjRow) {
        $adjustmentsMap[(int)$adjRow['employee_id']] = $adjRow;
    }

    $payrollData = [];
    foreach ($empList as $e) {
        $empId           = (int) $e['id'];
        $salaryMode      = $e['salary_mode'] ?? 'hours';
        $isFixed         = ($salaryMode === 'fixed');
        $base            = (float) $e['base_salary'];
        $expectedHours   = isset($e['expected_monthly_hours']) && $e['expected_monthly_hours'] !== null && $e['expected_monthly_hours'] !== '' ? (float) $e['expected_monthly_hours'] : null;
        $empLedgerBal    = $ledgerBalancesMap[$empId] ?? 0.0;
        $isPaid          = isset($paidMonthMap[$empId]);
        $adj             = (!$isPaid && isset($adjustmentsMap[$empId])) ? $adjustmentsMap[$empId] : null;

        if ($isFixed) {
            $hours          = 0.0;
            $noExpected     = false;
            $workDays       = 0.0;
            $leaveDaysCalc  = 0;
            $leaveDays      = 0;
            $dueDaysRawCalc = 0.0;
            $dueDaysCalc    = 0.0;
            $dayRate        = $base > 0 ? ($base / 30.0) : 0.0;
            $gross          = $base;
        } else {
            $hours          = (float) ($empHours[$empId] ?? 0);
            $salDays        = salary_days_from_hours($hours, $expectedHours);
            $noExpected     = !empty($salDays['no_expected']);
            $workDays       = $salDays['work_days'];
            $leaveDaysCalc  = $salDays['leave_days'];
            $leaveDays      = $leaveDaysCalc;
            $dueDaysRawCalc = $salDays['due_days'];
            $dueDaysCalc    = $dueDaysRawCalc;

            if ($adj !== null && !$noExpected) {
                if ($adj['leave_days'] !== null && is_numeric($adj['leave_days']) && (int)$adj['leave_days'] >= 0) {
                    $leaveDays = (int)$adj['leave_days'];
                    $dueDaysCalc = round($workDays + $leaveDays, 2);
                }
                if ($adj['due_days'] !== null && is_numeric($adj['due_days'])) {
                    $dueOverride = (float)$adj['due_days'];
                    if (abs($dueOverride - $dueDaysCalc) > 0.005) {
                        $dueDaysCalc = round($dueOverride, 2);
                    }
                }
            }

            $dayRate        = $base > 0 ? ($base / 30.0) : 0.0;
            $gross          = $noExpected ? 0.0 : ($dayRate * $dueDaysCalc);
        }

        $bonInfo         = $bonPendingMap[$empId] ?? ['cnt' => 0, 'single_id' => null, 'total_amount' => 0.0];
        $bon             = (float) $bonInfo['total_amount'];
        $bonCnt          = (int) $bonInfo['cnt'];

        $pot             = ($noExpected && !$isFixed) ? 0.0 : ($gross + $bon);

        $empAdvRows          = $advRowsByEmp[$empId] ?? [];
        $maxLedgerCap        = $empLedgerBal > 0 ? $empLedgerBal : 0.0;
        $remCap              = $maxLedgerCap;
        $monthlyAdvOwed      = 0.0;
        $monthlyAdvTakeCalc  = 0.0;
        $standingAdvOwed     = 0.0;
        $standingAdvTakeCalc = 0.0;

        foreach ($empAdvRows as $advR) {
            $advOutstanding = $advR['remaining'] !== null ? (float) $advR['remaining'] : (float) $advR['amount'];
            $advMonthlyDed  = (float) $advR['monthly_deduction'];
            $advTake        = ($advMonthlyDed > 0) ? min($advMonthlyDed, $advOutstanding) : $advOutstanding;
            $advTake        = min($advTake, $remCap);
            $remCap        -= $advTake;

            if (($advR['adv_type'] ?? 'monthly') === 'standing') {
                $standingAdvTakeCalc += $advTake;
                $standingAdvOwed     += $advOutstanding;
            } else {
                $monthlyAdvTakeCalc  += $advTake;
                $monthlyAdvOwed      += $advOutstanding;
            }
        }

        $monthlyAdvTake = $monthlyAdvTakeCalc;
        if ($adj !== null && $adj['monthly_take'] !== null && is_numeric($adj['monthly_take'])) {
            $monthlyAdvTake = max(0.0, (float)$adj['monthly_take']);
        }

        $standingAdvTake = $standingAdvTakeCalc;
        if ($adj !== null && $adj['standing_take'] !== null && is_numeric($adj['standing_take'])) {
            $standingAdvTake = max(0.0, (float)$adj['standing_take']);
        }

        $totalAdvances   = $monthlyAdvTake + $standingAdvTake;
        $totalAdvances   = min($totalAdvances, $maxLedgerCap);
        $advApplied      = min($totalAdvances, $pot);
        $advApplied      = max(0.0, round($advApplied, 2));
        $pot            -= $advApplied;

        $penFull         = (float) ($penMap[$empId] ?? 0);
        $penApplied      = min($penFull, $pot);
        $penApplied      = max(0.0, round($penApplied, 2));
        $pot            -= $penApplied;

        $defFull         = (float) ($defMap[$empId] ?? 0);
        $defApplied      = min($defFull, $pot);
        $defApplied      = max(0.0, round($defApplied, 2));
        $pot            -= $defApplied;

        $cDefInfo        = $cashierDeficitMap[$empId] ?? ['exceeded_total' => 0.0, 'shift_cnt' => 0];
        $cashierDefCalc  = (float) $cDefInfo['exceeded_total'];
        $cashierShiftCnt = (int) $cDefInfo['shift_cnt'];

        $cashierTake = $cashierDefCalc;
        if ($adj !== null && $adj['cashier_take'] !== null && is_numeric($adj['cashier_take'])) {
            $cashierTake = min(max(0.0, (float)$adj['cashier_take']), $cashierDefCalc);
        }

        $cashierApplied  = min($cashierTake, $pot);
        $cashierApplied  = max(0.0, round($cashierApplied, 2));
        $pot            -= $cashierApplied;

        $totalDeductions = round($advApplied + $penApplied + $defApplied + $cashierApplied, 2);
        $net             = ($noExpected && !$isFixed) ? 0.0 : max(0.0, round($pot, 2));

        $cashierCarry = cashier_deficit_carry_enabled()
            ? max(0.0, $cashierDefCalc - $cashierApplied)
            : 0.0;

        $carryToAdvance  = round(($penFull - $penApplied)
                               + ($defFull - $defApplied)
                               + $cashierCarry, 2);
        $carryToAdvance  = max(0.0, $carryToAdvance);

        $potInitial         = ($noExpected && !$isFixed) ? 0.0 : ($gross + $bon);
        $cashierReq         = cashier_deficit_carry_enabled() ? $cashierTake : $cashierApplied;
        $requiredDeductions = $totalAdvances + $penFull + $defFull + $cashierReq;
        $displayNet         = ($noExpected && !$isFixed) ? 0.0 : round($potInitial - $requiredDeductions, 2);

        $payrollData[] = [
            'id'                        => $empId,
            'full_name'                 => $e['full_name'],
            'salary_mode'               => $salaryMode,
            'is_fixed'                  => $isFixed,
            'branch_name'               => $e['branch_name'] ?? '',
            'base_salary'               => $base,
            'expected_monthly_hours'    => $expectedHours,
            'no_expected'               => $noExpected,
            'day_hours'                 => $salDays['day_hours'] ?? 0.0,
            'work_hours'                => $hours,
            'work_days'                 => $workDays,
            'leave_days'                => $leaveDays,
            'leave_days_calc'           => $leaveDaysCalc,
            'due_days_calc'             => $dueDaysCalc,
            'due_days_raw_calc'         => $dueDaysRawCalc,
            'day_rate'                  => $dayRate,
            'gross_salary'              => $gross,
            'bonuses'                   => $bon,
            'bonus_cnt'                 => $bonCnt,
            'total_bonuses'             => $bon,
            'advances'                  => $advApplied,
            'ledger_balance'            => $empLedgerBal,
            'monthly_adv_owed'          => $monthlyAdvOwed,
            'monthly_adv_take'          => $monthlyAdvTake,
            'monthly_adv_take_calc'     => $monthlyAdvTakeCalc,
            'standing_adv_owed'         => $standingAdvOwed,
            'standing_adv_take'         => $standingAdvTake,
            'standing_adv_take_calc'    => $standingAdvTakeCalc,
            'cashier_deficit'           => $cashierApplied,
            'cashier_deficit_calc'      => $cashierDefCalc,
            'cashier_deficit_take_calc' => $cashierDefCalc,
            'cashier_shift_cnt'         => $cashierShiftCnt,
            'penalties'                 => $penApplied,
            'penalties_full'            => $penFull,
            'deficits'                  => $defApplied,
            'deficits_full'             => $defFull,
            'carry_to_advance'          => $carryToAdvance,
            'total_deductions'          => $totalDeductions,
            'net_salary'                => $net,
            'display_net'               => $displayNet,
            'is_paid'                   => $isPaid,
        ];
    }
    return $payrollData;
}

