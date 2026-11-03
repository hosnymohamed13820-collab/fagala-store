<?php
/**
 * custody_lib.php — مكتبة استعلامات وبيانات العهد (الفرع والموظف)
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٧)
 *
 * قراءة فقط (GET) دون أي مسار لتحريك الأموال أو التعديل.
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/reports_lib.php';

/** بيانات المحضر المقفول؛ نفس صلاحيات التابات واختيار الفرع والموظف في صفحة العهد. */
function custody_get_receipt(PDO $pdo, int $custodyId, array $user): array
{
    $st = $pdo->prepare(
        "SELECT bc.*, b.name AS branch_name, e.full_name AS employee_name,
                re.full_name AS responsible_name, su.full_name AS submitter_name,
                ru.full_name AS reviewer_name
         FROM branch_custody bc
         LEFT JOIN branches b ON b.id=bc.branch_id
         LEFT JOIN employees e ON e.id=bc.employee_id
         LEFT JOIN employees re ON re.id=bc.responsible_employee_id
         LEFT JOIN users su ON su.id=bc.submitted_by
         LEFT JOIN users ru ON ru.id=bc.reviewed_by
         WHERE bc.id=?"
    );
    $st->execute([$custodyId]);
    $custody = $st->fetch();
    if (!$custody) {
        throw new RuntimeException('العهدة مش موجودة.', 404);
    }

    if (!empty($custody['employee_id'])) {
        $canReview = can('review');
        if (!is_owner() && !can('custody:employee') && !$canReview) {
            throw new RuntimeException('مش مسموح لك تعرض عهدة الموظف.', 403);
        }
        if (!$canReview) {
            // زي custody.php: اختيار أي موظف للمالك بس، والباقي عهدة موظف حسابه النشط.
            $employee = custody_get_user_employee($pdo, (int)$user['id']);
            $allowedEmpId = custody_resolve_selected_emp_id($user, (int)$custody['employee_id'], (int)($employee['id'] ?? 0), []);
            if ($allowedEmpId <= 0 || $allowedEmpId !== (int)$custody['employee_id']) {
                throw new RuntimeException('دي مش عهدتك.', 403);
            }
        }
    } elseif (!can('review')) {
        if (!is_owner() && !can('custody:branch')) {
            throw new RuntimeException('مش مسموح لك تعرض عهدة الفرع.', 403);
        }
        $allowedBranchId = custody_resolve_branch_id($user, (int)$custody['branch_id'], []);
        if ($allowedBranchId !== (int)$custody['branch_id']) {
            throw new RuntimeException('مش مسموح لك تعرض عهدة الفرع ده.', 403);
        }
    }
    if ($custody['status'] !== 'closed') {
        throw new RuntimeException('المحضر بيتطبع بعد اعتماد العهدة بس.', 409);
    }

    $lines = $pdo->prepare('SELECT expense_date, description, amount FROM custody_lines WHERE custody_id=? ORDER BY expense_date, id');
    $lines->execute([$custodyId]);
    return ['custody' => $custody, 'expenses' => $lines->fetchAll()];
}

/** آخر العهد المعتمدة للتاب والموظف أو الفرع المختار بالفعل في الصفحة. */
function custody_get_closed_history(PDO $pdo, bool $employeeTab, ?int $branchId, int $employeeId): array
{
    $scope = $employeeTab ? 'bc.employee_id=?' : 'bc.branch_id=? AND bc.employee_id IS NULL';
    $st = $pdo->prepare(
        "SELECT bc.id, bc.period_start, bc.period_end, bc.accountant_counted,
                bc.reviewed_at, u.full_name AS reviewer_name
         FROM branch_custody bc LEFT JOIN users u ON u.id=bc.reviewed_by
         WHERE bc.status='closed' AND $scope ORDER BY bc.id DESC LIMIT 50"
    );
    $st->execute([$employeeTab ? $employeeId : $branchId]);
    return $st->fetchAll();
}

/**
 * جلب قائمة الفروع النشطة التي لها إدارة مالية
 */
function custody_get_branches(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();
}

/**
 * جلب قائمة الموظفين النشطين
 */
function custody_get_employees(PDO $pdo): array
{
    return $pdo->query('SELECT id, full_name, job_title, branch_id FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();
}

/**
 * جلب قائمة الموردين النشطين
 */
function custody_get_suppliers(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name')->fetchAll();
}

/**
 * جلب موظف المستخدم الحالي إن وُجد
 */
function custody_get_user_employee(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT id, full_name FROM employees WHERE user_id = ? AND is_active = 1 LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * تحديد معرف الموظف المختار لعهدة الموظف
 * 🔴 الموظف العادي لا يرى إلا عهدته هو فقط — الأونر فقط يقدر يختار أي موظف
 */
function custody_resolve_selected_emp_id(array $user, ?int $requestedEmpId, int $currentUserEmpId, array $allEmployees): int
{
    $isOwner = !empty($user['is_owner']);
    if (!$isOwner) {
        return $currentUserEmpId;
    }

    $selectedEmpId = $requestedEmpId !== null ? (int)$requestedEmpId : 0;
    if ($selectedEmpId <= 0) {
        if ($currentUserEmpId > 0) {
            $selectedEmpId = $currentUserEmpId;
        } elseif (!empty($allEmployees)) {
            $selectedEmpId = (int) $allEmployees[0]['id'];
        }
    }
    return $selectedEmpId;
}

/**
 * تحديد معرف الفرع المعروض لعهدة الفرع
 */
function custody_resolve_branch_id(array $user, ?int $requestedBranchId, array $allBranches): ?int
{
    if (!empty($user['branch_id'])) {
        return (int) $user['branch_id'];
    }
    if ($requestedBranchId !== null && $requestedBranchId > 0) {
        return (int) $requestedBranchId;
    }
    if (!empty($allBranches)) {
        return (int) $allBranches[0]['id'];
    }
    return null;
}

/**
 * جلب بيانات عهدة الفرع وحركاتها وإحصائياتها
 */
function custody_get_branch_custody_data(PDO $pdo, int $branchId, array $allBranches): array
{
    $branchName = '';
    foreach ($allBranches as $b) {
        if ((int)$b['id'] === $branchId) {
            $branchName = (string)$b['name'];
            break;
        }
    }

    $custody = current_custody_for_branch($branchId);
    $expenses = [];
    $orders = [];
    if ($custody) {
        $cid = (int) $custody['id'];
        $e = $pdo->prepare(
            'SELECT cl.*, ec.name AS entry_code_name, br.name AS branch_name
             FROM custody_lines cl
             LEFT JOIN accounts ec ON ec.id = cl.entry_code_id
             LEFT JOIN branches br ON br.id = cl.branch_id
             WHERE cl.custody_id=? ORDER BY cl.id DESC'
        );
        $e->execute([$cid]);
        $expenses = $e->fetchAll();
        $accountLabels = rep_account_labels(array_column($expenses, 'entry_code_id'));
        foreach ($expenses as &$expense) {
            $labels = $accountLabels[(int) $expense['entry_code_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
            $expense['account_root_name'] = $labels['root_name'];
            $expense['account_leaf_name'] = $labels['leaf_name'];
        }
        unset($expense);

        $o = $pdo->prepare('SELECT * FROM disbursement_orders WHERE branch_id=? AND order_date >= ? ORDER BY id DESC');
        $o->execute([$branchId, $custody['period_start']]);
        $orders = $o->fetchAll();
    }

    $em = $pdo->prepare('SELECT id, full_name FROM employees WHERE branch_id=? AND is_active=1 ORDER BY full_name');
    $em->execute([$branchId]);
    $branchEmployees = $em->fetchAll();

    $opening = $custody ? (float)$custody['opening_balance'] : 0.0;
    $totalExp = $custody ? (float)$custody['total_expenses'] : 0.0;
    $closing = $opening - $totalExp;
    $custodyStatus = $custody['status'] ?? '';

    return [
        'branch_id'        => $branchId,
        'branch_name'      => $branchName,
        'custody'          => $custody,
        'expenses'         => $expenses,
        'orders'           => $orders,
        'branch_employees' => $branchEmployees,
        'opening_balance'  => $opening,
        'total_expenses'   => $totalExp,
        'closing_balance'  => $closing,
        'status'           => $custodyStatus,
        'is_pending_review'=> $custodyStatus === 'pending_review',
        'is_rejected'      => $custodyStatus === 'rejected',
        'can_edit'         => in_array($custodyStatus, ['open', 'rejected'], true),
    ];
}

/**
 * جلب بيانات عهدة الموظف وحركاتها ورصيد خزينتها وإحصائياتها
 */
function custody_get_employee_custody_data(PDO $pdo, int $selectedEmpId, array $allEmployees): array
{
    $selectedEmpName = '';
    foreach ($allEmployees as $eRow) {
        if ((int)$eRow['id'] === $selectedEmpId) {
            $selectedEmpName = (string)$eRow['full_name'];
            break;
        }
    }

    $empCustody = null;
    $empExpenses = [];
    $empOrders = [];
    $empTrBal = 0.0;

    if ($selectedEmpId > 0) {
        $empCustody = current_custody_for_employee($selectedEmpId);
        if ($empCustody) {
            $stExp = $pdo->prepare(
                "SELECT cl.*, ec.name AS entry_code_name, br.name AS branch_name,
                        emp.full_name AS adv_emp_name,
                        supp.name AS supp_name
                 FROM custody_lines cl
                 LEFT JOIN accounts ec ON ec.id = cl.entry_code_id
                 LEFT JOIN branches br ON br.id = cl.branch_id
                 LEFT JOIN employees emp ON emp.id = cl.employee_id
                 LEFT JOIN suppliers supp ON supp.id = cl.supplier_id
                 WHERE cl.custody_id = ?
                 ORDER BY cl.id DESC"
            );
            $stExp->execute([(int)$empCustody['id']]);
            $empExpenses = $stExp->fetchAll();
            $accountLabels = rep_account_labels(array_column($empExpenses, 'entry_code_id'));
            foreach ($empExpenses as &$expense) {
                $labels = $accountLabels[(int) $expense['entry_code_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
                $expense['account_root_name'] = $labels['root_name'];
                $expense['account_leaf_name'] = $labels['leaf_name'];
            }
            unset($expense);

            $stOrd = $pdo->prepare(
                "SELECT * FROM disbursement_orders
                 WHERE employee_id = ? AND order_date >= ?
                 ORDER BY id DESC"
            );
            $stOrd->execute([(int)$selectedEmpId, $empCustody['period_start']]);
            $empOrders = $stOrd->fetchAll();

            if (!empty($empCustody['custody_treasury_id'])) {
                $stTr = $pdo->prepare("SELECT balance FROM treasuries WHERE id=?");
                $stTr->execute([(int)$empCustody['custody_treasury_id']]);
                $empTrBal = (float)$stTr->fetchColumn();
            }
        }
    }

    $empOpening = $empCustody ? (float)$empCustody['opening_balance'] : 0.0;
    $empTotalExp = $empCustody ? (float)$empCustody['total_expenses'] : 0.0;
    $empClosing = $empOpening - $empTotalExp;
    $empStatus = $empCustody ? (string)$empCustody['status'] : '';

    return [
        'employee_id'      => $selectedEmpId,
        'employee_name'    => $selectedEmpName,
        'custody'          => $empCustody,
        'expenses'         => $empExpenses,
        'orders'           => $empOrders,
        'treasury_balance' => $empTrBal,
        'opening_balance'  => $empOpening,
        'total_expenses'   => $empTotalExp,
        'closing_balance'  => $empClosing,
        'status'           => $empStatus,
        'is_pending_review'=> $empStatus === 'pending_review',
        'is_rejected'      => $empStatus === 'rejected',
        'can_edit'         => in_array($empStatus, ['open', 'rejected'], true),
    ];
}

