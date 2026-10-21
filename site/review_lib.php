<?php
/**
 * review_lib.php — مكتبة استعلامات وبيانات مراجعة المحاسب
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٨)
 *
 * قراءة فقط (GET). العمليات وتعديلات الورديات والاعتمادات والإشعارات تبقى في الصفحة.
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/**
 * فحص الورديات المرجوعة التي تجاوزت مهلتها آلياً
 */
function review_expire_shift_returns(PDO $pdo): void
{
    try {
        shift_returns_expire($pdo);
    } catch (Throwable $e) {
        error_log("shift_returns_expire in review: " . $e->getMessage());
    }
}

/**
 * جلب الورديات المنتظرة للمراجعة
 */
function review_get_pending_shifts(PDO $pdo): array
{
    return $pdo->query(
        "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
         FROM shifts s
         JOIN users u ON u.id = s.user_id
         JOIN branches b ON b.id = s.branch_id
         WHERE s.status IN ('submitted','resubmitted')
         ORDER BY s.id DESC"
    )->fetchAll();
}

/**
 * تفاصيل الورديات المنتظرة للمقارنة (فئات الكاش وأسطر الدفع الإلكتروني)
 */
function review_get_pending_shift_details(PDO $pdo, array $pendingShifts): array
{
    $empCash  = [];
    $empLines = [];
    if (!empty($pendingShifts)) {
        $ids = array_column($pendingShifts, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $q = $pdo->prepare(
            "SELECT shift_id, denomination, `count`
               FROM shift_cash
              WHERE shift_id IN ($in)"
        );
        $q->execute($ids);
        foreach ($q as $r) {
            $empCash[(int)$r['shift_id']][(string)(float)$r['denomination']] = (int)$r['count'];
        }

        $q = $pdo->prepare(
            "SELECT shift_id, method, line_no, reference, amount
               FROM shift_payment_lines
              WHERE shift_id IN ($in)
              ORDER BY shift_id, method, line_no"
        );
        $q->execute($ids);
        foreach ($q as $r) {
            $empLines[(int)$r['shift_id']][$r['method']][] = [
                'no'  => (int)$r['line_no'],
                'ref' => (string)($r['reference'] ?? ''),
                'amt' => (float)$r['amount'],
            ];
        }
    }
    return [
        'empCash'  => $empCash,
        'empLines' => $empLines,
    ];
}

/**
 * جلب سجل الورديات التي تمت مراجعتها
 */
function review_get_history_shifts(PDO $pdo, int $limit = 50): array
{
    return $pdo->query(
        "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
         FROM shifts s
         JOIN users u ON u.id = s.user_id
         JOIN branches b ON b.id = s.branch_id
         WHERE s.status IN ('approved','rejected')
         ORDER BY s.id DESC LIMIT $limit"
    )->fetchAll();
}

/**
 * جلب شارات آخر تعديل للورديات
 */
function review_get_shift_edit_badges(PDO $pdo, array $allShiftIds): array
{
    $shiftEditBadges = [];
    $shiftIds = array_values(array_unique(array_filter(array_map('intval', $allShiftIds))));
    if (!empty($shiftIds)) {
        try {
            $phBadge = implode(',', array_fill(0, count($shiftIds), '?'));
            $stBadge = $pdo->prepare("
                SELECT sel.shift_id, sel.created_at, u.full_name AS editor_name
                FROM shift_edit_log sel
                JOIN (
                    SELECT shift_id, MAX(id) AS max_id
                    FROM shift_edit_log
                    WHERE shift_id IN ($phBadge)
                    GROUP BY shift_id
                ) latest ON latest.max_id = sel.id
                JOIN users u ON u.id = sel.user_id
            ");
            $stBadge->execute($shiftIds);
            foreach ($stBadge as $br) {
                $shiftEditBadges[(int)$br['shift_id']] = [
                    'name' => $br['editor_name'],
                    'time' => date('H:i', strtotime($br['created_at'])),
                ];
            }
        } catch (Throwable $ex) {
            // جدول shift_edit_log مش موجود ← مفيش بادج ومفيش خطأ
        }
    }
    return $shiftEditBadges;
}

/**
 * جلب عهد الفروع والموظفين المقدمة للمراجعة
 */
function review_get_pending_custody(PDO $pdo): array
{
    return $pdo->query(
        "SELECT bc.*, b.name AS branch_name, u.full_name AS submitted_by_name,
                e.full_name AS employee_name
           FROM branch_custody bc
           LEFT JOIN branches b ON b.id = bc.branch_id
           LEFT JOIN users u ON u.id = bc.submitted_by
           LEFT JOIN employees e ON e.id = bc.employee_id
          WHERE bc.status = 'pending_review'
          ORDER BY bc.id DESC"
    )->fetchAll();
}

/**
 * جلب سجل مراجعة العهد
 */
function review_get_history_custody(PDO $pdo, int $limit = 50): array
{
    return $pdo->query(
        "SELECT bc.*, b.name AS branch_name, u.full_name AS reviewer_name,
                e.full_name AS employee_name
           FROM branch_custody bc
           LEFT JOIN branches b ON b.id = bc.branch_id
           LEFT JOIN users u ON u.id = bc.reviewed_by
           LEFT JOIN employees e ON e.id = bc.employee_id
          WHERE bc.status IN ('closed','rejected') AND bc.reviewed_at IS NOT NULL
          ORDER BY bc.reviewed_at DESC LIMIT $limit"
    )->fetchAll();
}

/**
 * جلب أذونات إنستا باي المقدمة والمراجعة
 */
function review_get_permits(PDO $pdo, int $historyLimit = 50): array
{
    $pendingPermits = [];
    $reviewedPermits = [];
    try {
        $pendingPermits = $pdo->query(
            "SELECT p.*, s.sender, s.body, s.received_at AS sms_received_at,
                    u_cr.full_name AS creator_name
               FROM sms_permits p
               JOIN sms_inbox s ON s.id = p.sms_id
               LEFT JOIN users u_cr ON u_cr.id = p.created_by
              WHERE p.status IN ('pending', 'approving')
              ORDER BY p.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $reviewedPermits = $pdo->query(
            "SELECT p.*, s.sender, s.body, s.received_at AS sms_received_at,
                    u_cr.full_name AS creator_name,
                    u_rv.full_name AS reviewer_name
               FROM sms_permits p
               JOIN sms_inbox s ON s.id = p.sms_id
               LEFT JOIN users u_cr ON u_cr.id = p.created_by
               LEFT JOIN users u_rv ON u_rv.id = p.reviewed_by
              WHERE p.status IN ('approved', 'rejected')
              ORDER BY p.reviewed_at DESC, p.id DESC
              LIMIT $historyLimit"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('sms_permits: ' . $e->getMessage());
    }
    return [
        'pending'  => $pendingPermits,
        'reviewed' => $reviewedPermits,
    ];
}

/**
 * قواميس الأسماء للعرض والتحليل
 */
function review_get_name_dictionaries(PDO $pdo): array
{
    return [
        'treasuries' => $pdo->query("SELECT id, name FROM treasuries")->fetchAll(PDO::FETCH_KEY_PAIR),
        'accounts'   => $pdo->query("SELECT id, name FROM accounts")->fetchAll(PDO::FETCH_KEY_PAIR),
        'suppliers'  => $pdo->query("SELECT id, name FROM suppliers")->fetchAll(PDO::FETCH_KEY_PAIR),
        'employees'  => $pdo->query("SELECT id, full_name FROM employees")->fetchAll(PDO::FETCH_KEY_PAIR),
        'branches'   => $pdo->query("SELECT id, name FROM branches")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/**
 * الفروع المتاحة للمراجعة
 */
function review_get_branches(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 AND has_sales=1 ORDER BY name')->fetchAll();
    } catch (Throwable $e) {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();
    }
}

/**
 * حساب أعداد المعلق لكل فرع والإجمالي
 */
function review_calc_branch_counts(array $pendingShifts, array $pendingCustody, array $pendingPermits): array
{
    $reviewBranchCounts = [];
    foreach ($pendingShifts as $s) {
        $bid = (int)$s['branch_id'];
        $reviewBranchCounts[$bid] = ($reviewBranchCounts[$bid] ?? 0) + 1;
    }
    foreach ($pendingCustody as $c) {
        $bid = (int)$c['branch_id'];
        $reviewBranchCounts[$bid] = ($reviewBranchCounts[$bid] ?? 0) + 1;
    }
    $pendingInstapayCount = 0;
    foreach ($pendingPermits as $p) {
        if ($p['status'] === 'pending') {
            $pendingInstapayCount++;
        }
        $pl = json_decode((string)$p['payload'], true) ?: [];
        $bId = (int)($pl['cost_branch_id'] ?? $pl['branch_id'] ?? 0);
        if ($bId > 0) {
            $reviewBranchCounts[$bId] = ($reviewBranchCounts[$bId] ?? 0) + 1;
        }
    }
    $totalPendingReviewCount = count($pendingShifts) + count($pendingCustody) + $pendingInstapayCount;
    return [
        'branch_counts'          => $reviewBranchCounts,
        'total_pending'          => $totalPendingReviewCount,
        'pending_instapay_count' => $pendingInstapayCount,
    ];
}

/**
 * جلب مشاكل تسلسل الورديات ومستخدمي الفروع
 */
function review_get_seq_issues(PDO $pdo, bool $canEditShiftDateBranch): array
{
    $seqIssues = [];
    $openSeqCount = 0;
    $seqUsersByBranch = [];
    $seqIssuesTableExists = false;
    if ($canEditShiftDateBranch) {
        try {
            $seqIssues = $pdo->query(
                "SELECT ssi.*, b.name AS branch_name, u_as.full_name AS assigned_name, u_res.full_name AS resolved_name
                 FROM shift_sequence_issues ssi
                 JOIN branches b ON b.id = ssi.branch_id
                 LEFT JOIN users u_as ON u_as.id = ssi.assigned_user_id
                 LEFT JOIN users u_res ON u_res.id = ssi.resolved_by
                 ORDER BY ssi.id DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
            $seqIssuesTableExists = true;
            foreach ($seqIssues as $iss) {
                if ($iss['status'] === 'open') {
                    $openSeqCount++;
                }
            }
            $branchUsersRaw = $pdo->query(
                "SELECT u.id, u.full_name, u.branch_id AS u_branch, e.branch_id AS e_branch
                 FROM users u
                 LEFT JOIN employees e ON e.user_id = u.id AND e.is_active = 1
                 WHERE u.is_active = 1
                 ORDER BY u.full_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($branchUsersRaw as $uRow) {
                $uId = (int)$uRow['id'];
                $uName = $uRow['full_name'];
                $uB = (int)($uRow['u_branch'] ?? 0);
                $eB = (int)($uRow['e_branch'] ?? 0);
                if ($uB > 0) {
                    $seqUsersByBranch[$uB][$uId] = ['id' => $uId, 'name' => $uName];
                }
                if ($eB > 0) {
                    $seqUsersByBranch[$eB][$uId] = ['id' => $uId, 'name' => $uName];
                }
            }
            foreach ($seqUsersByBranch as $bid => $uList) {
                $seqUsersByBranch[$bid] = array_values($uList);
            }
        } catch (Throwable $e) {
            $seqIssues = [];
            $openSeqCount = 0;
            $seqIssuesTableExists = false;
        }
    }
    return [
        'issues'          => $seqIssues,
        'open_count'      => $openSeqCount,
        'users_by_branch' => $seqUsersByBranch,
        'table_exists'    => $seqIssuesTableExists,
    ];
}

/**
 * دالة مساعدة: أرقام الكاشير لوردية
 */
function shift_cashier_amounts(array $s): array
{
    return [
        'cash'     => (float)($s['cash_total'] ?? 0),
        'visa'     => (float)($s['visa_total'] ?? 0),
        'instapay' => (float)($s['instapay_total'] ?? 0),
        'system'   => (float)($s['system_total'] ?? 0),
    ];
}

