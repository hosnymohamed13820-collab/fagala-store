<?php
/**
 * cashier_lib.php — مكتبة تقفيل الكاشير ونطاق الفروع المركزية (بريف ١٧٦ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * دوال قراءة ومحايدة تماماً:
 *   - cashier_get_business_date(): حساب تاريخ يوم العمل المحاسبي (9ص إلى 9ص)
 *   - cashier_get_all_branches(): الفروع النشطة المتاحة للتقفيل
 *   - cashier_get_allowed_branches(): حساب نطاق رؤية الفروع المعقد (المالك، موظف الفرع، ومغطي بدون فرع)
 *   - cashier_get_today_shifts(): تقفيلات اليوم حسب نطاق المستخدم والفرع المختار
 *   - cashier_get_archive_shifts(): تقفيلات الأرشيف المعلقة
 *   - cashier_get_my_rejected(): تقفيلات الكاشير المرفوضة القابلة للتعديل
 *   - cashier_get_shift_edit_badges(): بادجات آخر تعديل للورديات المعروضة
 *   - cashier_get_seq_issues(): مشاكل تسلسل الفواتير المعينة للمستخدم
 *   - cashier_calc_today_totals(): حساب إجماليات الكاش والفيزا والإنستاباي والفرق
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/cash_denominations_lib.php';

/**
 * حساب يوم العمل المحاسبي (من 9 صباحاً إلى 9 صباح اليوم التالي).
 */
function cashier_get_business_date(?DateTime $now = null): string {
    $now = $now ? (clone $now) : new DateTime();
    if ((int)$now->format('H') < 9) {
        $now->modify('-1 day');
    }
    return $now->format('Y-m-d');
}

/**
 * جلب جميع الفروع المتاحة للتقفيل (نشطة ولها مالية ومبيعات).
 *
 * @return array<int, array{id: int|string, name: string}>
 */
function cashier_get_all_branches(PDO $pdo): array {
    try {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 AND has_sales=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * حساب الفروع المسموح للمستخدم رؤيتها.
 *
 * القواعد:
 *   - الأونر: كل الفروع.
 *   - الموظف المربوط بفرع: فرعه دائماً + أي فرع قفل عليه في يوم العمل الحالي.
 *   - الموظف غير المربوط بفرع: فقط الفروع التي قفل عليها في يوم العمل الحالي.
 *
 * @param array{id: int, branch_id?: ?int, is_owner?: int|bool} $user
 * @param array<int, array{id: int|string, name: string}> $allBranches
 * @return array<int>
 */
function cashier_get_allowed_branches(PDO $pdo, array $user, array $allBranches, string $bizDateStr): array {
    $uid      = (int) ($user['id'] ?? 0);
    $myBranch = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
    $isOwner  = !empty($user['is_owner']);

    if ($isOwner) {
        return array_map(fn($b) => (int)$b['id'], $allBranches);
    }

    $mine = $pdo->prepare(
        'SELECT DISTINCT branch_id FROM shifts WHERE user_id = ? AND business_date = ?'
    );
    $mine->execute([$uid, $bizDateStr]);
    $workedBranches = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));

    $allowedBranches = $workedBranches;
    if ($myBranch !== null && !in_array($myBranch, $allowedBranches, true)) {
        $allowedBranches[] = $myBranch;
    }

    return array_values(array_unique($allowedBranches));
}

/**
 * جلب تقفيلات اليوم حسب النطاق والفرع.
 *
 * @param array<int> $allowedBranches
 */
function cashier_get_today_shifts(PDO $pdo, array $allowedBranches, int $filterBranch, bool $isOwner, string $bizDateStr): array {
    $where  = ['s.business_date = ?'];
    $params = [$bizDateStr];

    if (empty($allowedBranches)) {
        $where[] = '1 = 0';
    } elseif ($filterBranch > 0) {
        $where[]  = 's.branch_id = ?';
        $params[] = $filterBranch;
    } elseif (!$isOwner) {
        $ph       = implode(',', array_fill(0, count($allowedBranches), '?'));
        $where[]  = "s.branch_id IN ({$ph})";
        $params   = array_merge($params, $allowedBranches);
    }

    $st = $pdo->prepare(
        "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
         FROM shifts s
         JOIN users u ON u.id = s.user_id
         JOIN branches b ON b.id = s.branch_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.id DESC"
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب تقفيلات الأرشيف المعلقة (غير المعتمدة وغير المرفوضة قبل اليوم المحاسبي).
 *
 * @param array<int> $allowedBranches
 */
function cashier_get_archive_shifts(PDO $pdo, array $allowedBranches, int $filterBranch, bool $isOwner, string $bizDateStr, int $limit = 100): array {
    $aWhere  = ['s.business_date < ?', "s.status NOT IN ('approved','rejected')"];
    $aParams = [$bizDateStr];

    if (empty($allowedBranches)) {
        $aWhere[] = '1 = 0';
    } elseif ($filterBranch > 0) {
        $aWhere[]  = 's.branch_id = ?';
        $aParams[] = $filterBranch;
    } elseif (!$isOwner) {
        $ph        = implode(',', array_fill(0, count($allowedBranches), '?'));
        $aWhere[]  = "s.branch_id IN ({$ph})";
        $aParams   = array_merge($aParams, $allowedBranches);
    }

    $st = $pdo->prepare(
        "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
         FROM shifts s
         JOIN users u ON u.id = s.user_id
         JOIN branches b ON b.id = s.branch_id
         WHERE " . implode(' AND ', $aWhere) . "
         ORDER BY s.business_date DESC, s.id DESC LIMIT " . (int)$limit
    );
    $st->execute($aParams);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب تقفيلات الكاشير المرفوضة مع مهلة التعديل.
 */
function cashier_get_my_rejected(PDO $pdo, int $uid): array {
    $hoursReturn = (int) setting('shift_return_hours', 48);
    try {
        $myRejectedSt = $pdo->prepare(
            "SELECT s.*, b.name AS branch_name,
                    CASE WHEN s.reviewed_at >= ? THEN TIMESTAMPDIFF(HOUR, NOW(), s.reviewed_at + INTERVAL ? HOUR) END AS hours_left,
                    (s.return_expired_at IS NOT NULL OR (s.reviewed_at >= ? AND s.reviewed_at + INTERVAL ? HOUR < NOW())) AS is_return_expired
             FROM shifts s JOIN branches b ON b.id = s.branch_id
             WHERE s.user_id = ? AND s.status = 'rejected'
             ORDER BY s.id DESC"
        );
        $retSince = shift_return_since();
        $myRejectedSt->execute([$retSince, $hoursReturn, $retSince, $hoursReturn, $uid]);
        return $myRejectedSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $myRejectedSt = $pdo->prepare(
            "SELECT s.*, b.name AS branch_name
             FROM shifts s JOIN branches b ON b.id = s.branch_id
             WHERE s.user_id = ? AND s.status = 'rejected'
             ORDER BY s.id DESC"
        );
        $myRejectedSt->execute([$uid]);
        return $myRejectedSt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * جلب بادجات التعديل الأخيرة لمجموعة من الورديات.
 *
 * @param array<int> $shiftIds
 * @return array<int, array{name: string, time: string}>
 */
function cashier_get_shift_edit_badges(PDO $pdo, array $shiftIds): array {
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $shiftIds))));
    if (empty($cleanIds)) {
        return [];
    }

    try {
        $phBadge = implode(',', array_fill(0, count($cleanIds), '?'));
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
        $stBadge->execute($cleanIds);
        $badges = [];
        foreach ($stBadge as $br) {
            $badges[(int)$br['shift_id']] = [
                'name' => $br['editor_name'],
                'time' => date('H:i', strtotime($br['created_at'])),
            ];
        }
        return $badges;
    } catch (Throwable) {
        return [];
    }
}

/**
 * جلب مشاكل تسلسل الفواتير المعينة للمستخدم.
 */
function cashier_get_seq_issues(PDO $pdo, int $uid): array {
    try {
        $stSeq = $pdo->prepare(
            "SELECT ssi.*, b.name AS branch_name
             FROM shift_sequence_issues ssi
             JOIN branches b ON b.id = ssi.branch_id
             WHERE ssi.status = 'sent' AND ssi.assigned_user_id = ?
             ORDER BY ssi.id DESC"
        );
        $stSeq->execute([$uid]);
        return $stSeq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * حساب إجماليات تقفيلات اليوم.
 *
 * @param array $todayShifts
 * @return array{
 *     cash_total: float,
 *     visa_total: float,
 *     instapay_total: float,
 *     system_total: float,
 *     collected_total: float,
 *     visa_insta_total: float,
 *     difference: float
 * }
 */
function cashier_calc_today_totals(array $todayShifts): array {
    $sumCash   = (float) array_sum(array_column($todayShifts, 'cash_total'));
    $sumVisa   = (float) array_sum(array_column($todayShifts, 'visa_total'));
    $sumInsta  = (float) array_sum(array_column($todayShifts, 'instapay_total'));
    $sumSys    = (float) array_sum(array_column($todayShifts, 'system_total'));
    $sumColl   = $sumCash + $sumVisa + $sumInsta;
    $sumVI     = $sumVisa + $sumInsta;
    $sumDiff   = $sumColl - $sumSys;

    return [
        'cash_total'       => $sumCash,
        'visa_total'       => $sumVisa,
        'instapay_total'   => $sumInsta,
        'system_total'     => $sumSys,
        'collected_total'  => $sumColl,
        'visa_insta_total' => $sumVI,
        'difference'       => $sumDiff,
    ];
}

