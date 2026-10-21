<?php
/**
 * dashboard_lib.php — مكتبة دوال الداشبورد المالي (بريف ١٧٢ — موجة ٣)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/**
 * تحديد الفترة الزمنية المعروضة في الداشبورد.
 *
 * @return array{from: string, to: string, is_today: bool}
 */
function dashboard_parse_period(?string $from = null, ?string $to = null): array
{
    $today = date('Y-m-d');
    $f = $from ?? $today;
    $t = $to   ?? $today;
    if (!strtotime($f)) $f = $today;
    if (!strtotime($t)) $t = $today;
    return [
        'from'     => $f,
        'to'       => $t,
        'is_today' => ($f === $today && $t === $today),
    ];
}

/**
 * جلب أرصدة الخزائن المركزية والفرعية وخزينة الكاش الفعلي.
 * ملاحظة هامة (الموجة ٣): الرصيد متخزن في treasuries.balance ويُقرأ كما هو دون إعادة حساب.
 *
 * @return array{
 *     treasuries: array,
 *     central: array,
 *     main_total: float,
 *     real_cash: float
 * }
 */
function dashboard_get_treasuries(PDO $pdo): array
{
    $treasuries = $pdo->query(
        "SELECT t.*, b.name AS branch_name
         FROM treasuries t LEFT JOIN branches b ON b.id = t.branch_id
         WHERE t.is_active = 1
         ORDER BY FIELD(t.type,'cash','main','visa','instapay','card','branch_custody','employee_custody'), t.name"
    )->fetchAll();

    $centralTreasuries = array_values(array_filter(
        $treasuries,
        fn($t) => !in_array($t['type'], ['branch_custody', 'employee_custody'], true)
    ));

    $mainTotal = 0.0;
    $realCash  = 0.0;
    foreach ($centralTreasuries as $t) {
        $bal = (float) $t['balance'];
        $mainTotal += $bal;
        if (in_array($t['type'], ['main', 'cash'], true)) {
            $realCash += $bal;
        }
    }

    return [
        'treasuries' => $treasuries,
        'central'    => $centralTreasuries,
        'main_total' => $mainTotal,
        'real_cash'  => $realCash,
    ];
}

/**
 * جلب عهد الفروع وعهد الموظفين المفتوحة مع حساب نسب الاستهلاك والألوان.
 *
 * @return array{
 *     custody_bars: array,
 *     emp_custody_bars: array
 * }
 */
function dashboard_get_custodies(PDO $pdo): array
{
    // عهد الفروع
    $custodies = $pdo->query(
        "SELECT bc.*, b.name AS branch_name, t.balance AS treasury_balance
         FROM branch_custody bc
         JOIN branches b ON b.id = bc.branch_id
         JOIN treasuries t ON t.id = bc.custody_treasury_id
         WHERE bc.status = 'open' AND bc.employee_id IS NULL"
    )->fetchAll();

    $allBranchesWithCustody = $pdo->query(
        "SELECT b.id, b.name, t.id AS treasury_id, t.balance
         FROM branches b
         JOIN treasuries t ON t.branch_id = b.id AND t.type='branch_custody'
         WHERE b.is_active = 1 AND b.has_finance = 1
         ORDER BY b.name"
    )->fetchAll();

    $openByBranch = [];
    foreach ($custodies as $c) {
        $openByBranch[(int) $c['branch_id']] = $c;
    }

    $custodyBars = [];
    foreach ($allBranchesWithCustody as $br) {
        $bid  = (int) $br['id'];
        $open = $openByBranch[$bid] ?? null;
        if ($open) {
            $opening = (float) $open['opening_balance'];
            $balance = (float) $open['treasury_balance'];
        } else {
            $opening = 0.0;
            $balance = 0.0;
        }
        $pct = $opening > 0 ? max(0, min(100, ($balance / $opening) * 100)) : 0;
        if ($opening <= 0)  $color = 'empty';
        elseif ($pct <= 20) $color = 'red';
        elseif ($pct < 50)  $color = 'orange';
        else                $color = 'green';

        $custodyBars[] = [
            'name'    => $br['name'],
            'opening' => $opening,
            'balance' => $balance,
            'pct'     => $pct,
            'color'   => $color,
        ];
    }

    // عهد الموظفين (المفتوحة فقط)
    $empCustodies = $pdo->query(
        "SELECT bc.*, e.full_name AS employee_name, t.balance AS treasury_balance
         FROM branch_custody bc
         JOIN employees e ON e.id = bc.employee_id
         JOIN treasuries t ON t.id = bc.custody_treasury_id
         WHERE bc.status = 'open' AND bc.employee_id IS NOT NULL
         ORDER BY e.full_name"
    )->fetchAll();

    $empCustodyBars = [];
    foreach ($empCustodies as $ec) {
        $opening = (float) $ec['opening_balance'];
        $balance = (float) $ec['treasury_balance'];
        $pct     = $opening > 0 ? max(0, min(100, ($balance / $opening) * 100)) : 0;
        if ($opening <= 0)  $color = 'empty';
        elseif ($pct <= 20) $color = 'red';
        elseif ($pct < 50)  $color = 'orange';
        else                $color = 'green';

        $empCustodyBars[] = [
            'name'    => $ec['employee_name'],
            'opening' => $opening,
            'balance' => $balance,
            'pct'     => $pct,
            'color'   => $color,
        ];
    }

    return [
        'custody_bars'     => $custodyBars,
        'emp_custody_bars' => $empCustodyBars,
    ];
}

/**
 * جلب ملخص المؤشرات للفترة (المبيعات المعتمدة، التقفيلات التي بها فرق، بانتظار مراجعة، دفعات الموردين).
 *
 * @return array{
 *     total_sales: float,
 *     shifts_with_diff: int,
 *     pending_review: int,
 *     upcoming_payments: int
 * }
 */
function dashboard_get_summary(PDO $pdo, string $from, string $to): array
{
    $salesStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(cash_total+visa_total+instapay_total),0)
         FROM shifts WHERE status='approved' AND business_date BETWEEN ? AND ?"
    );
    $salesStmt->execute([$from, $to]);
    $totalSales = (float) $salesStmt->fetchColumn();

    $diffStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM shifts WHERE ABS(difference) > 0.001 AND business_date BETWEEN ? AND ?"
    );
    $diffStmt->execute([$from, $to]);
    $shiftsWithDiff = (int) $diffStmt->fetchColumn();

    $pendingReview = (int) $pdo->query(
        "SELECT COUNT(*) FROM shifts WHERE status IN ('submitted','resubmitted')"
    )->fetchColumn();

    $upcomingPayments = (int) $pdo->query(
        "SELECT COUNT(*) FROM supplier_payment_schedules
         WHERE status='pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
    )->fetchColumn();

    return [
        'total_sales'       => $totalSales,
        'shifts_with_diff'  => $shiftsWithDiff,
        'pending_review'    => $pendingReview,
        'upcoming_payments' => $upcomingPayments,
    ];
}

/**
 * جلب حالة آخر ١٠ تقفيلات في الفترة مع مؤشرات أزرار الإجراءات السريعة.
 *
 * @return array{
 *     shifts: array,
 *     can_review: bool,
 *     can_cashier: bool
 * }
 */
function dashboard_get_shifts(PDO $pdo, string $from, string $to): array
{
    $periodShifts = $pdo->prepare(
        "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
         FROM shifts s JOIN users u ON u.id=s.user_id JOIN branches b ON b.id=s.branch_id
         WHERE s.business_date BETWEEN ? AND ?
         ORDER BY s.id DESC LIMIT 10"
    );
    $periodShifts->execute([$from, $to]);

    return [
        'shifts'      => $periodShifts->fetchAll(),
        'can_review'  => can('review'),
        'can_cashier' => can('cashier'),
    ];
}
