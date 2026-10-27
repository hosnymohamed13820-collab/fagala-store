<?php
/**
 * activity_lib.php — دوال وقواعد «حركات النظام» (بريف ٢٢٣)
 * نظام الفجالة ستور المالي
 *
 * الدوال المشتركة بين activity.php وactivity_api.php:
 *  - act_get_branches()     : تحميل قوائم الفروع والخرائط
 *  - act_company_branch_name(): اسم الفرع من رقم فرع الشركة
 *  - act_company_branch_of()  : تحويل branches.id لـcompany_branch
 *  - act_branch_name()        : اسم الفرع من branches.id
 *  - act_date_range()         : تحويل الفترة لـdateStart وdateEnd
 *  - act_url()                : تكوين الرابط مع الباراميترات
 *  - act_cards_data()         : كروت «محتاج تصرّف»
 *  - act_timeline_data()      : الخط الزمني بجميع أنواعه
 *  - act_groups()             : خريطة المجموعات للخط الزمني (بريف ٢٤٦)
 *  - act_group_of_type()      : تحديد مجموعة نوع الحركة
 *  - act_type_labels()        : مسميات الأنواع لشرائح الفلترة
 *  - act_sales_data()         : فواتير المبيعات والمرتجعات مع الأصناف
 *  - act_purchases_data()     : فواتير المشتريات والمردودات
 *
 * الصفحة والـAPI قراءة فقط — مفيش أي INSERT/UPDATE/DELETE هنا.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/sms_lib.php';
require_once __DIR__ . '/branch_invoices_lib.php';

/**
 * خرائط وقوائم الفروع.
 *
 * @return array{0: array, 1: array<int, array>, 2: array<int, array>}
 */
function act_get_branches(): array
{
    $pdo = db();
    $branchesList = $pdo->query(
        'SELECT id, name, sto_id, in_receiving FROM branches ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $branchById  = [];   // branches.id  => صف الفرع
    $branchBySto = [];   // sto_id       => صف الفرع
    foreach ($branchesList as $b) {
        $branchById[(int) $b['id']] = $b;
        $sid = (int) ($b['sto_id'] ?? 0);
        if ($sid > 1) {
            $branchBySto[$sid] = $b;
        }
    }

    return [$branchesList, $branchById, $branchBySto];
}

/** اسم الفرع من رقم فرع الشركة (company_branch). */
function act_company_branch_name($companyBranch, array $branchBySto): string
{
    $cb = (int) $companyBranch;
    if ($cb === 1) {
        return 'الإدارة';
    }
    return (string) ($branchBySto[$cb]['name'] ?? ('فرع الشركة ' . $cb));
}

/**
 * رقم فرع الشركة اللي يخص فرع عندنا (للفلتر).
 * الإدارة (in_receiving = 0) = 1 · فرع بيع = sto_id · فرع مالوش مخزن (زي إشراقة) = 0.
 */
function act_company_branch_of(int $branchId, array $branchById): int
{
    $b = $branchById[$branchId] ?? null;
    if ($b === null) {
        return $branchId;
    }
    $sid = (int) ($b['sto_id'] ?? 0);
    if ($sid > 1) {
        return $sid;
    }
    return ((int) ($b['in_receiving'] ?? 0) === 0) ? 1 : 0;
}

/** اسم الفرع من branches.id — ولو مش معروف «غير محدد». */
function act_branch_name(?int $branchId, array $branchById): string
{
    if ($branchId === null) {
        return 'غير محدد';
    }
    return (string) ($branchById[$branchId]['name'] ?? ('فرع #' . $branchId));
}

/**
 * حساب نطاق التاريخ بحسب الفترة المختارة.
 *
 * @return array{0: string, 1: string, 2: string} [dateStart, dateEnd, period]
 */
function act_date_range(string $period, string $customFrom = '', string $customTo = ''): array
{
    $today = date('Y-m-d');
    switch ($period) {
        case 'yesterday':
            $yest = date('Y-m-d', strtotime('-1 day'));
            $dateStart = $yest . ' 00:00:00';
            $dateEnd = $yest . ' 23:59:59';
            break;
        case '7d':
            $dateStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $dateEnd = $today . ' 23:59:59';
            break;
        case '30d':
            $dateStart = date('Y-m-d 00:00:00', strtotime('-29 days'));
            $dateEnd = $today . ' 23:59:59';
            break;
        case 'month':
            $dateStart = date('Y-m-01 00:00:00');
            $dateEnd = $today . ' 23:59:59';
            break;
        case 'custom':
            $dateStart = ($customFrom ?: $today) . ' 00:00:00';
            $dateEnd = ($customTo ?: $today) . ' 23:59:59';
            break;
        case 'today':
        default:
            $period = 'today';
            $dateStart = $today . ' 00:00:00';
            $dateEnd = $today . ' 23:59:59';
            break;
    }

    return [$dateStart, $dateEnd, $period];
}

/**
 * تكوين رابط الصفحة مع الباراميترات.
 */
function act_url(array $params): string
{
    return 'activity.php?' . http_build_query($params);
}

/**
 * استعلام كروت «محتاج تصرّف».
 *
 * @return array{
 *     pending_shifts: int,
 *     pending_custody: int,
 *     late_invoices_total: int,
 *     late_invoices_breakdown: array<int, string>,
 *     sms_no_permit: int,
 *     sms_card_from: string,
 *     sms_card_to: string
 * }
 */
function act_cards_data(): array
{
    $pdo = db();

    // ورديات مستنية مراجعة
    $stShifts = $pdo->query("SELECT COUNT(*) FROM shifts WHERE status IN ('submitted', 'resubmitted')");
    $pendingShiftsCount = (int) ($stShifts ? $stShifts->fetchColumn() : 0);

    // عهد مستنية اعتماد
    $stCustody = $pdo->query("SELECT COUNT(*) FROM branch_custody WHERE status = 'pending_review'");
    $pendingCustodyCount = (int) ($stCustody ? $stCustody->fetchColumn() : 0);

    // فواتير متأخر استلامها (> يوم)
    $stLateInvoices = $pdo->query("
        SELECT r.branch_id, b.name AS branch_name, COUNT(*) AS cnt
        FROM receipts r
        LEFT JOIN branches b ON b.id = r.branch_id
        WHERE r.status IN ('sent', 'receiving')
          AND r.created_at < NOW() - INTERVAL 1 DAY
        GROUP BY r.branch_id, b.name
    ");
    $lateInvoicesRows = $stLateInvoices ? $stLateInvoices->fetchAll(PDO::FETCH_ASSOC) : [];
    $lateInvoicesTotal = 0;
    $lateInvoicesBreakdown = [];
    foreach ($lateInvoicesRows as $lir) {
        $lateInvoicesTotal += (int) $lir['cnt'];
        $lateInvoicesBreakdown[] = ($lir['branch_name'] ?: 'فرع ' . $lir['branch_id']) . ': ' . $lir['cnt'];
    }

    // رسايل إنستا الواردة اللي من غير إذن — آخر ٧ أيام بس.
    $smsExtra = 'AND s.dup_of IS NULL';
    $smsCardFrom = date('Y-m-d', strtotime('-6 days'));
    $smsCardTo   = date('Y-m-d');

    $stSmsNoPermit = $pdo->prepare("
        SELECT s.id, s.sender, s.body, s.sms_at
        FROM sms_inbox s
        LEFT JOIN sms_permits p ON p.sms_id = s.id
        WHERE s.sms_at >= ? AND s.sms_at < DATE_ADD(?, INTERVAL 1 DAY)
          AND p.id IS NULL {$smsExtra}
    ");
    $stSmsNoPermit->execute([$smsCardFrom, $smsCardTo]);
    $smsNoPermitCount = 0;
    foreach ($stSmsNoPermit->fetchAll(PDO::FETCH_ASSOC) as $sr) {
        $parsed = sms_parse((string) $sr['body'], (string) $sr['sms_at']);
        // الواردة بس · ومن غير رسايل البطاقة مسبقة الدفع (مالهاش علاقة بالشغل)
        if ($parsed['kind'] === 'in' && !sms_is_foreign((string) $sr['body'])) {
            $smsNoPermitCount++;
        }
    }

    return [
        'pending_shifts'          => $pendingShiftsCount,
        'pending_custody'         => $pendingCustodyCount,
        'late_invoices_total'     => $lateInvoicesTotal,
        'late_invoices_breakdown' => $lateInvoicesBreakdown,
        'sms_no_permit'           => $smsNoPermitCount,
        'sms_card_from'           => $smsCardFrom,
        'sms_card_to'             => $smsCardTo,
    ];
}

/**
 * خريطة مجموعات حركات الخط الزمني (بريف ٢٤٦).
 *
 * @return array<string, array{key: string, name: string, icon: string, types: list<string>}>
 */
function act_groups(): array
{
    return [
        'sales' => [
            'key'   => 'sales',
            'name'  => 'المبيعات والورديات',
            'icon'  => 'ti-building-store',
            'types' => ['shifts', 'sms', 'permits'],
        ],
        'purchases' => [
            'key'   => 'purchases',
            'name'  => 'المشتريات والمخزن',
            'icon'  => 'ti-packages',
            'types' => ['receiving', 'shortage', 'newitems', 'stocktake'],
        ],
        'treasury' => [
            'key'   => 'treasury',
            'name'  => 'الخزينة والعهد',
            'icon'  => 'ti-building-bank',
            'types' => ['treasury', 'transfers', 'custody'],
        ],
        'employees' => [
            'key'   => 'employees',
            'name'  => 'الموظفين',
            'icon'  => 'ti-users',
            'types' => ['salaries', 'advances', 'attendance'],
        ],
        'other' => [
            'key'   => 'other',
            'name'  => 'أخرى',
            'icon'  => 'ti-list-details',
            'types' => [],
        ],
    ];
}

/**
 * اسم المجموعة لنوع الحركة — أي نوع غير معرّف يروح 'other' عشان مفيش حركة تختفي.
 */
function act_group_of_type(string $type): string
{
    static $typeToGroup = null;
    if ($typeToGroup === null) {
        $typeToGroup = [];
        foreach (act_groups() as $gKey => $gMeta) {
            foreach ($gMeta['types'] as $t) {
                $typeToGroup[$t] = $gKey;
            }
        }
    }
    return $typeToGroup[$type] ?? 'other';
}

/**
 * مسميات الأنواع لشرائح الفلترة (chips) داخل المجموعات.
 *
 * @return array<string, string>
 */
function act_type_labels(): array
{
    return [
        'shifts'     => 'ورديات',
        'sms'        => 'رسايل بنك',
        'permits'    => 'أذون إنستاباي',
        'receiving'  => 'استلام فواتير',
        'shortage'   => 'نواقص وطلبات',
        'newitems'   => 'أصناف جديدة',
        'stocktake'  => 'جرد',
        'treasury'   => 'خزينة',
        'transfers'  => 'تحويلات خزائن',
        'custody'    => 'عهد',
        'salaries'   => 'صرف مرتبات',
        'advances'   => 'سلف',
        'attendance' => 'حضور وانصراف',
    ];
}

/**
 * استعلامات الخط الزمني (استعلام واحد لكل نوع بـLIMIT 200).
 *
 * @param string $dateStart تاريخ البداية 'Y-m-d H:i:s'
 * @param string $dateEnd   تاريخ النهاية 'Y-m-d H:i:s'
 * @param string $typeFilter نوع الحركة
 * @param int|null $branchFilter فلتر الفرع branches.id
 * @param array|null $branchById خريطة branches.id => صف الفرع
 * @param string $groupFilter فلتر المجموعة (sales, purchases, treasury, employees, other, all)
 * @return array{feed: array<int, array>, new_items_ever: bool, count: int, groups: array<string, array>}
 */
function act_timeline_data(
    string $dateStart,
    string $dateEnd,
    string $typeFilter = 'all',
    ?int $branchFilter = null,
    ?array $branchById = null,
    string $groupFilter = 'all'
): array {
    $pdo = db();
    if ($branchById === null) {
        [, $branchById] = act_get_branches();
    }

    $allGroups = act_groups();
    if ($groupFilter !== 'all' && !isset($allGroups[$groupFilter])) {
        $groupFilter = 'all';
    }

    $shouldRun = static function (string $type) use ($typeFilter, $groupFilter): bool {
        if ($typeFilter !== 'all') {
            return $typeFilter === $type;
        }
        if ($groupFilter !== 'all') {
            return act_group_of_type($type) === $groupFilter;
        }
        return true;
    };

    $feed = [];
    $smsExtra = 'AND s.dup_of IS NULL';

    // ج) الورديات (shifts) + حدث «مراجعة وردية» منفصل
    if ($shouldRun('shifts')) {
        $sql = "
            SELECT
                s.id, s.branch_id, b.name AS branch_name, s.user_id, u.full_name AS user_name,
                s.shift_name, s.cash_total, s.difference, s.status, s.business_date,
                s.created_at, s.reviewed_at, s.reviewed_by, ru.full_name AS reviewer_name
            FROM shifts s
            LEFT JOIN branches b ON b.id = s.branch_id
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN users ru ON ru.id = s.reviewed_by
            WHERE (s.created_at BETWEEN ? AND ? OR s.reviewed_at BETWEEN ? AND ?)
        ";
        $params = [$dateStart, $dateEnd, $dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND s.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY GREATEST(COALESCE(s.created_at, '1000-01-01 00:00:00'),
                                   COALESCE(s.reviewed_at, '1000-01-01 00:00:00')) DESC
                  LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $statusLabels = [
            'submitted'   => 'بانتظار المراجعة',
            'resubmitted' => 'أعيد تقديمها',
            'approved'    => 'معتمدة',
            'rejected'    => 'مرفوضة',
        ];

        $inRange = static function (?string $t) use ($dateStart, $dateEnd): bool {
            return $t !== null && $t >= $dateStart && $t <= $dateEnd;
        };

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $brName  = $r['branch_name'] ?: 'غير محدد';
            $stTxt   = $statusLabels[$r['status']] ?? e((string) $r['status']);
            $diffTxt = (float) $r['difference'] !== 0.0 ? ' (فرق: ' . sens_mask(number_format((float) $r['difference'], 2)) . ' ج)' : '';
            // اسم الوردية جاي من القاعدة → e() هنا مرة واحدة، والوصف بيطبعه خام
            $shiftNo = 'تقفيل ' . e($r['shift_name'] ?: 'وردية');

            // ١) حدث التقفيل نفسه
            if ($inRange($r['created_at'] !== null ? (string) $r['created_at'] : null)) {
                $feed[] = [
                    'time'        => (string) $r['created_at'],
                    'type'        => 'shifts',
                    'group'       => 'sales',
                    'type_label'  => 'وردية',
                    'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : ($r['status'] === 'rejected' ? 'fchip-neg' : 'fchip-mute'),
                    'icon'        => 'ti-clock',
                    'branch'      => $brName,
                    'desc'        => $shiftNo . ' — كاش: ' . sens_mask(number_format((float) $r['cash_total'], 2)) . ' ج' . $diffTxt . ' [' . $stTxt . ']',
                    'actor'       => $r['user_name'] ?: 'كاشير',
                    'link'        => 'review.php?tab=' . ($r['status'] === 'approved' || $r['status'] === 'rejected' ? 'history' : 'pending'),
                ];
            }

            // ٢) حدث المراجعة (اعتماد/رفض) — سطر لوحده
            if ($inRange($r['reviewed_at'] !== null ? (string) $r['reviewed_at'] : null)
                && in_array($r['status'], ['approved', 'rejected'], true)) {
                $feed[] = [
                    'time'        => (string) $r['reviewed_at'],
                    'type'        => 'shifts',
                    'group'       => 'sales',
                    'type_label'  => 'مراجعة وردية',
                    'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : 'fchip-neg',
                    'icon'        => 'ti-check',
                    'branch'      => $brName,
                    'desc'        => ($r['status'] === 'approved' ? 'اعتمدت ' : 'اترفضت ') . $shiftNo
                                   . ' — كاش: ' . sens_mask(number_format((float) $r['cash_total'], 2)) . ' ج' . $diffTxt,
                    'actor'       => $r['reviewer_name'] ?: 'المحاسب',
                    'link'        => 'review.php?tab=history',
                ];
            }
        }
    }

    // د) العهد (branch_custody)
    if ($shouldRun('custody')) {
        $sql = "
            SELECT
                c.id, c.branch_id, b.name AS branch_name, c.employee_id,
                c.submitted_by AS user_id, u.full_name AS user_name,
                c.opening_balance, c.total_expenses, c.closing_balance, c.difference, c.status,
                COALESCE(c.submitted_at, c.created_at) AS event_time
            FROM branch_custody c
            LEFT JOIN branches b ON b.id = c.branch_id
            LEFT JOIN users u ON u.id = c.submitted_by
            WHERE c.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND c.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY event_time DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'custody',
                'group'       => 'treasury',
                'type_label'  => 'عهدة',
                'badge_class' => $r['status'] === 'closed' ? 'fchip-pos' : ($r['status'] === 'pending_review' ? 'fchip-warn' : 'fchip-mute'),
                'icon'        => 'ti-cash',
                'branch'      => $r['branch_name'] ?: 'غير محدد',
                'desc'        => 'حركة عهدة #' . e((string) $r['id']) . ' — مصروفات: ' . sens_mask(number_format((float)$r['total_expenses'], 2)) . ' ج (الحالة: ' . e((string) $r['status']) . ')',
                'actor'       => $r['user_name'] ?: 'مسؤول العهدة',
                // العهدة إما عهدة فرع (بالفرع) أو عهدة موظف (بالموظف)
                'link'        => !empty($r['employee_id'])
                               ? 'custody.php?tab=employee&emp=' . (int) $r['employee_id']
                               : 'custody.php?tab=branch&branch=' . (int) $r['branch_id'],
            ];
        }
    }

    // هـ) استلام الفواتير في الفروع (receipts)
    if ($shouldRun('receiving')) {
        $sql = "
            SELECT
                r.id, r.group_id, r.distribution_id, r.branch_id, b.name AS branch_name,
                r.status, r.started_by, u_st.full_name AS started_by_name,
                r.received_by, u_rc.full_name AS received_by_name,
                r.approved_by, u_ap.full_name AS approved_by_name,
                r.created_at AS event_time, r.received_at, r.approved_at,
                d.invoice_no
            FROM receipts r
            LEFT JOIN branches b ON b.id = r.branch_id
            LEFT JOIN invoice_distribution d ON d.id = r.distribution_id
            LEFT JOIN users u_st ON u_st.id = r.started_by
            LEFT JOIN users u_rc ON u_rc.id = r.received_by
            LEFT JOIN users u_ap ON u_ap.id = r.approved_by
            WHERE r.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND r.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY r.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $actor = $r['received_by_name'] ?: ($r['started_by_name'] ?: ($r['approved_by_name'] ?: 'الفرع'));
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'receiving',
                'group'       => 'purchases',
                'type_label'  => 'استلام بضاعة',
                'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : ($r['status'] === 'received' ? 'fchip-mute' : 'fchip-warn'),
                'icon'        => 'ti-truck',
                'branch'      => $r['branch_name'] ?: 'غير محدد',
                'desc'        => 'محضر استلام بضاعة #' . e((string) $r['id']) . ($r['invoice_no'] ? ' لفاتورة #' . e((string) $r['invoice_no']) : '') . ' [حالة: ' . e((string) $r['status']) . ']',
                'actor'       => $actor,
                'link'        => 'receiving.php?r=' . (int) $r['id'],
            ];
        }
    }

    // و) الخزينة (journal_entries)
    // 🔴 فرع التكلفة = نفس COALESCE وبنفس الـJOINs بتاعة reports_lib.php (case 'treasury').
    if ($shouldRun('treasury')) {
        $sql = "
            SELECT
                j.id, j.treasury_id, t.name AS treasury_name,
                j.description, j.debit, j.credit, j.created_by, u.full_name AS user_name,
                j.created_at AS event_time, j.entry_date,
                cb.id AS cost_branch_id, cb.name AS cost_branch_name
            FROM journal_entries j
            JOIN treasuries t ON t.id = j.treasury_id
            LEFT JOIN users u ON u.id = j.created_by
            LEFT JOIN treasury_movements tm ON tm.id = j.ref_id AND j.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
            LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
            LEFT JOIN accounts ja ON ja.id = j.account_id
            LEFT JOIN shifts sh ON sh.id = j.ref_id AND j.ref_type = 'shift'
            LEFT JOIN branch_custody bc ON bc.id = j.ref_id
                 AND j.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
            LEFT JOIN custody_lines cl ON cl.id = j.ref_id AND j.ref_type = 'custody_expense'
            LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
            LEFT JOIN disbursement_orders dor ON dor.id = j.ref_id AND j.ref_type = 'disbursement_order'
            LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id)
            WHERE j.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id) = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY j.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $amt = (float) $r['debit'] > 0 ? (float) $r['debit'] : (float) $r['credit'];
            $kindTxt = (float) $r['debit'] > 0 ? 'قبض' : 'صرف';
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'treasury',
                'group'       => 'treasury',
                'type_label'  => 'سند خزينة',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-building-bank',
                'branch'      => $r['cost_branch_name'] ?: ($r['treasury_name'] ?: 'خزينة رئيسية'),
                'desc'        => 'حركة ' . $kindTxt . ': ' . e((string) $r['description']) . ' بمبلغ ' . sens_mask(number_format($amt, 2)) . ' ج',
                'actor'       => $r['user_name'] ?: 'محاسب',
                'link'        => 'treasury.php?treasury=' . (int) $r['treasury_id'],
            ];
        }
    }

    // ز) رسايل إنستا والبنك (sms_inbox)
    if ($shouldRun('sms')) {
        $sql = "
            SELECT
                s.id, s.sender, s.body, s.sms_at AS event_time,
                p.id AS permit_id, p.action AS permit_action, p.amount AS permit_amount, p.status AS permit_status
            FROM sms_inbox s
            LEFT JOIN sms_permits p ON p.sms_id = s.id
            WHERE s.sms_at BETWEEN ? AND ? {$smsExtra}
            ORDER BY s.sms_at DESC LIMIT 200
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$dateStart, $dateEnd]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $hasPermit = !empty($r['permit_id']);
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'sms',
                'group'       => 'sales',
                'type_label'  => 'رسالة بنك',
                'badge_class' => $hasPermit ? 'fchip-pos' : 'fchip-warn',
                'icon'        => 'ti-device-mobile',
                'branch'      => 'إنستاباي',
                'desc'        => 'رسالة من ' . e((string) $r['sender']) . ': ' . e(mb_substr((string) $r['body'], 0, 70)) . '...' . ($hasPermit ? ' (بإذن مسجل)' : ' (بدون إذن)'),
                'actor'       => $r['sender'],
                'link'        => 'sms_inbox.php?from=' . urlencode(substr((string) $r['event_time'], 0, 10))
                               . '&to=' . urlencode(substr((string) $r['event_time'], 0, 10))
                               . '&kind=in',
            ];
        }
    }

    // ح) طلبات النواقص والفروع (shortage_requests)
    if ($shouldRun('shortage')) {
        $sql = "
            SELECT
                sr.id, sr.task_id, sr.from_branch_id, b1.name AS from_branch_name,
                sr.to_branch_id, b2.name AS to_branch_name,
                sr.requested_by, u.full_name AS user_name,
                sr.notes, sr.source, sr.created_at AS event_time
            FROM shortage_requests sr
            LEFT JOIN branches b1 ON b1.id = sr.from_branch_id
            LEFT JOIN branches b2 ON b2.id = sr.to_branch_id
            LEFT JOIN users u ON u.id = sr.requested_by
            WHERE sr.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND (sr.from_branch_id = ? OR sr.to_branch_id = ?)";
            $params[] = $branchFilter;
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY sr.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $brTxt = ($r['from_branch_name'] ?: 'فرع ' . $r['from_branch_id']) . ($r['to_branch_name'] ? ' ← ' . $r['to_branch_name'] : '');
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'shortage',
                'group'       => 'purchases',
                'type_label'  => 'طلب نواقص',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-shopping-cart',
                'branch'      => $brTxt,
                'desc'        => 'طلب نواقص #' . e((string) $r['id']) . ($r['notes'] ? ' (' . e(mb_substr((string) $r['notes'], 0, 50)) . ')' : ''),
                'actor'       => $r['user_name'] ?: 'مسؤول الفرع',
                'link'        => !empty($r['task_id']) ? 'tasks.php?task=' . (int) $r['task_id'] : 'shortage.php',
            ];
        }
    }

    // ط) الحضور والانصراف (attendance)
    if ($shouldRun('attendance')) {
        $sql = "
            SELECT
                a.id, a.employee_id, e.full_name AS employee_name, e.branch_id, b.name AS branch_name,
                a.att_date, a.check_in, a.check_out, a.work_hours, a.status,
                a.created_at AS event_time
            FROM attendance a
            LEFT JOIN employees e ON e.id = a.employee_id
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE a.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND e.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY a.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $timeTxt = $r['check_in'] ? 'دخول: ' . substr((string) $r['check_in'], 0, 5) : '';
            if ($r['check_out']) $timeTxt .= ' · خروج: ' . substr((string) $r['check_out'], 0, 5);
            $attDate = (string) $r['att_date'];
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'attendance',
                'group'       => 'employees',
                'type_label'  => 'حضور وانصراف',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-user-check',
                'branch'      => $r['branch_name'] ?: 'غير محدد',
                'desc'        => 'تسجيل حضور للموظف ' . e((string) $r['employee_name']) . ' (' . e($timeTxt) . ')',
                'actor'       => $r['employee_name'],
                'link'        => 'reports.php?type=attendance&from=' . urlencode($attDate) . '&to=' . urlencode($attDate),
            ];
        }
    }

    // ي) جلسات الجرد (stocktake_sessions)
    if ($shouldRun('stocktake')) {
        $sql = "
            SELECT
                ss.id, ss.status, ss.has_balance, ss.started_by, u_st.full_name AS started_by_name,
                ss.closed_by, u_cl.full_name AS closed_by_name,
                ss.started_at AS event_time, ss.closed_at
            FROM stocktake_sessions ss
            LEFT JOIN users u_st ON u_st.id = ss.started_by
            LEFT JOIN users u_cl ON u_cl.id = ss.closed_by
            WHERE ss.started_at BETWEEN ? AND ?
            ORDER BY ss.started_at DESC LIMIT 200
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$dateStart, $dateEnd]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'stocktake',
                'group'       => 'purchases',
                'type_label'  => 'جلسة جرد',
                'badge_class' => $r['status'] === 'closed' ? 'fchip-pos' : 'fchip-warn',
                'icon'        => 'ti-checklist',
                'branch'      => 'المخازن',
                'desc'        => 'جلسة جرد #' . e((string) $r['id']) . ' [الحالة: ' . ($r['status'] === 'closed' ? 'مغلقة' : 'نشطة') . ']',
                'actor'       => $r['closed_by_name'] ?: ($r['started_by_name'] ?: 'مسؤول الجرد'),
                'link'        => 'reports.php?type=stocktake&session=' . (int) $r['id'],
            ];
        }
    }

    // ل) الأصناف الجديدة (hq_codes.created_at — بريف ٢٠٦)
    $newItemsEver = false;
    if ($shouldRun('newitems')) {
        $stEver = $pdo->query('SELECT 1 FROM hq_codes WHERE created_at IS NOT NULL LIMIT 1');
        $newItemsEver = (bool) ($stEver ? $stEver->fetchColumn() : false);

        $st = $pdo->prepare("
            SELECT item_id, MIN(code) AS code, MIN(name) AS name, MAX(price) AS price,
                   MIN(created_at) AS event_time
            FROM hq_codes
            WHERE created_at IS NOT NULL
              AND created_at BETWEEN ? AND ?
            GROUP BY item_id
            ORDER BY event_time DESC
            LIMIT 200
        ");
        $st->execute([$dateStart, $dateEnd]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $priceTxt = $r['price'] !== null ? sens_mask(number_format((float) $r['price'], 2)) . ' ج' : 'من غير سعر';
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'newitems',
                'group'       => 'purchases',
                'type_label'  => 'صنف جديد',
                'badge_class' => 'fchip-pos',
                'icon'        => 'ti-package',
                'branch'      => 'الإدارة',
                'desc'        => 'صنف جديد: ' . e((string) $r['code']) . ' — ' . e((string) $r['name']) . ' — ' . $priceTxt,
                'actor'       => 'المزامنة',
                'link'        => 'stock_query.php',
            ];
        }
    }

    // م) اعتماد ورفض أذونات إنستاباي (sms_permits.reviewed_at)
    if ($shouldRun('permits')) {
        $sql = "
            SELECT
                p.id, p.sms_id, p.action, p.amount, p.status, p.reviewed_at,
                p.reviewed_by, ru.full_name AS reviewer_name,
                s.sender
            FROM sms_permits p
            LEFT JOIN users ru ON ru.id = p.reviewed_by
            LEFT JOIN sms_inbox s ON s.id = p.sms_id
            WHERE p.reviewed_at BETWEEN ? AND ?
              AND p.status IN ('approved', 'rejected')
            ORDER BY p.reviewed_at DESC
            LIMIT 200
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$dateStart, $dateEnd]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $actTxt = $r['action'] === 'receipt' ? 'قبض' : 'صرف';
            $feed[] = [
                'time'        => (string) $r['reviewed_at'],
                'type'        => 'permits',
                'group'       => 'sales',
                'type_label'  => $r['status'] === 'approved' ? 'اعتماد إذن' : 'رفض إذن',
                'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : 'fchip-neg',
                'icon'        => 'ti-circle-check',
                'branch'      => 'إنستاباي',
                'desc'        => 'إذن ' . $actTxt . ' بمبلغ ' . sens_mask(number_format((float) $r['amount'], 2)) . ' ج'
                               . ' — ' . ($r['status'] === 'approved' ? 'اتعتمد' : 'اترفض'),
                'actor'       => $r['reviewer_name'] ?: 'المحاسب',
                'link'        => 'review.php?tab=instapay',
            ];
        }
    }

    // ن) التحويلات بين الخزائن (treasury_transfers)
    if ($shouldRun('transfers')) {
        $sql = "
            SELECT
                tt.id, tt.from_treasury_id, tf.name AS from_name, tf.branch_id AS from_branch_id,
                tt.to_treasury_id, tto.name AS to_name, tto.branch_id AS to_branch_id,
                tt.amount, tt.transfer_date, tt.notes, tt.created_at AS event_time,
                tt.created_by, u.full_name AS user_name
            FROM treasury_transfers tt
            LEFT JOIN treasuries tf ON tf.id = tt.from_treasury_id
            LEFT JOIN treasuries tto ON tto.id = tt.to_treasury_id
            LEFT JOIN users u ON u.id = tt.created_by
            WHERE tt.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND (tf.branch_id = ? OR tto.branch_id = ?)";
            $params[] = $branchFilter;
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY tt.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $brTxt = $r['from_branch_id'] !== null
                ? act_branch_name((int) $r['from_branch_id'], $branchById)
                : ($r['to_branch_id'] !== null ? act_branch_name((int) $r['to_branch_id'], $branchById) : 'خزائن مركزية');
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'transfers',
                'group'       => 'treasury',
                'type_label'  => 'تحويل خزائن',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-transfer',
                'branch'      => $brTxt,
                'desc'        => 'تحويل من «' . e($r['from_name'] ?: '—') . '» إلى «' . e($r['to_name'] ?: '—')
                               . '» بمبلغ ' . sens_mask(number_format((float) $r['amount'], 2)) . ' ج'
                               . ($r['notes'] ? ' — ' . e(mb_substr((string) $r['notes'], 0, 40)) : ''),
                'actor'       => $r['user_name'] ?: 'محاسب',
                'link'        => 'treasury.php?treasury=' . (int) $r['to_treasury_id'],
            ];
        }
    }

    // س) صرف المرتبات (salaries.paid_at)
    if ($shouldRun('salaries')) {
        $sql = "
            SELECT
                s.id, s.employee_id, e.full_name AS employee_name, e.branch_id, b.name AS branch_name,
                s.salary_month, s.net_salary, s.paid_at AS event_time
            FROM salaries s
            LEFT JOIN employees e ON e.id = s.employee_id
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE s.paid_at BETWEEN ? AND ?
              AND s.status = 'paid'
              AND s.reversed_at IS NULL
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND e.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY s.paid_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'salaries',
                'group'       => 'employees',
                'type_label'  => 'صرف مرتب',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-wallet',
                'branch'      => $r['branch_name'] ?: 'غير محدد',
                'desc'        => 'صرف مرتب شهر ' . e((string) $r['salary_month']) . ' للموظف ' . e($r['employee_name'] ?: '#' . $r['employee_id'])
                               . ' — الصافي ' . sens_mask(number_format((float) $r['net_salary'], 2)) . ' ج',
                'actor'       => 'المحاسب',
                'link'        => 'hr.php?tab=salaries&month=' . urlencode((string) $r['salary_month']),
            ];
        }
    }

    // ع) السلف (employee_advances)
    if ($shouldRun('advances')) {
        $sql = "
            SELECT
                a.id, a.employee_id, e.full_name AS employee_name, e.branch_id, b.name AS branch_name,
                a.amount, a.advance_date, a.status, a.notes, a.created_at AS event_time
            FROM employee_advances a
            LEFT JOIN employees e ON e.id = a.employee_id
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE a.created_at BETWEEN ? AND ?
        ";
        $params = [$dateStart, $dateEnd];
        if ($branchFilter !== null) {
            $sql .= " AND e.branch_id = ?";
            $params[] = $branchFilter;
        }
        $sql .= " ORDER BY a.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $advStatus = ['pending' => 'مستنية', 'deducted' => 'اتخصمت', 'recovered' => 'اتسددت'];
            $feed[] = [
                'time'        => (string) $r['event_time'],
                'type'        => 'advances',
                'group'       => 'employees',
                'type_label'  => 'سلفة',
                'badge_class' => 'fchip-mute',
                'icon'        => 'ti-cash-banknote',
                'branch'      => $r['branch_name'] ?: 'غير محدد',
                'desc'        => 'سلفة للموظف ' . e($r['employee_name'] ?: '#' . $r['employee_id'])
                               . ' بمبلغ ' . sens_mask(number_format((float) $r['amount'], 2)) . ' ج'
                               . ' [' . e((string) ($advStatus[$r['status']] ?? $r['status'])) . ']'
                               . ($r['notes'] ? ' — ' . e(mb_substr((string) $r['notes'], 0, 40)) : ''),
                'actor'       => 'المحاسب',
                'link'        => 'hr.php?tab=advances',
            ];
        }
    }

    // ضمان وجود مفتاح group لكل حركة
    foreach ($feed as &$item) {
        if (!isset($item['group'])) {
            $item['group'] = act_group_of_type((string) ($item['type'] ?? ''));
        }
    }
    unset($item);

    // ترتيب الخط الزمني تنازلياً حسب الوقت (الأحدث فوق)
    usort($feed, function ($a, $b) {
        return strcmp($b['time'], $a['time']);
    });

    // اقتطاع أول 500 حركة كحد أقصى للعرض السلس
    if (count($feed) > 500) {
        $feed = array_slice($feed, 0, 500);
    }

    // تجميع شارات وأعداد المجموعات من الداتا المجلوبة مباشرة (بدون استعلام إضافي)
    $groupsSummary = [];
    foreach ($allGroups as $gKey => $gMeta) {
        $groupsSummary[$gKey] = [
            'key'   => $gKey,
            'name'  => $gMeta['name'],
            'icon'  => $gMeta['icon'],
            'types' => $gMeta['types'],
            'count' => 0,
        ];
    }
    foreach ($feed as $item) {
        $g = $item['group'] ?? 'other';
        if (!isset($groupsSummary[$g])) {
            $g = 'other';
        }
        $groupsSummary[$g]['count']++;
    }

    return [
        'feed'           => $feed,
        'new_items_ever' => $newItemsEver,
        'count'          => count($feed),
        'groups'         => $groupsSummary,
    ];
}

/**
 * استعلام فواتير المبيعات والمرتجعات مع الأصناف المقسمة على صفحات.
 *
 * @param string $dateStart تاريخ البداية
 * @param string $dateEnd   تاريخ النهاية
 * @param string $period    الفترة (للتحقق من شرط السنة الواحدة للفترة المخصصة)
 * @param int|null $branchFilter branches.id
 * @param int $page         رقم الصفحة (تبدأ من 1)
 * @param int $limit        حد الصفحة (افتراضي 200)
 * @return array
 */
function act_sales_data(
    string $dateStart,
    string $dateEnd,
    string $period = 'today',
    ?int $branchFilter = null,
    int $page = 1,
    int $limit = 200
): array {
    $pdo = db();
    $salesBranches = br_inv_map();
    $salesCounts = [];
    $salesDays = [];
    $salesTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
    $salesTooLong = false;
    $salesInvoices = [];
    $salesLinesByInvoice = [];
    $salesAgentNames = [];
    $totalSalesCount = 0;
    $salesTotalPages = 1;
    $salesPage = max(1, $page);
    $salesLimit = max(1, $limit);
    $salesOffset = 0;

    $agents = array_values(array_map(static fn($b) => (string) $b['agent'], $salesBranches));
    $agentMarks = implode(',', array_fill(0, count($agents), '?'));
    $selectedAgent = null;
    foreach ($salesBranches as $b) {
        if ($branchFilter === (int) $b['id']) {
            $selectedAgent = (string) $b['agent'];
            break;
        }
    }
    if ($branchFilter !== null && $selectedAgent === null) {
        $branchFilter = null;
    }
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', substr($dateStart, 0, 10));
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', substr($dateEnd, 0, 10));
    $salesTooLong = $period === 'custom' && $startDay !== false && $endDay !== false
        && $startDay->diff($endDay)->days > 365;

    if (!$salesTooLong && !empty($agents)) {
        $st = $pdo->prepare("SELECT agent_key, SUM(CASE WHEN doc_type = 4 THEN 1 ELSE 0 END) AS sale_cnt
            FROM br_invoices
            WHERE invoice_date BETWEEN ? AND ? AND doc_type IN (4,7,17) AND agent_key IN ($agentMarks)
            GROUP BY agent_key");
        $st->execute(array_merge([$dateStart, $dateEnd], $agents));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $salesCounts[(string) $r['agent_key']] = (int) $r['sale_cnt'];
        }

        $st = $pdo->prepare("SELECT agent_key, DATE(invoice_date) AS d,
                SUM(CASE WHEN doc_type = 4 THEN 1 ELSE 0 END) AS sale_cnt,
                SUM(CASE WHEN doc_type = 4 THEN net_amount ELSE 0 END) AS sale_sum,
                SUM(CASE WHEN doc_type IN (7,17) THEN 1 ELSE 0 END) AS ret_cnt,
                SUM(CASE WHEN doc_type IN (7,17) THEN net_amount ELSE 0 END) AS ret_sum
            FROM br_invoices
            WHERE invoice_date BETWEEN ? AND ? AND doc_type IN (4,7,17) AND agent_key IN ($agentMarks)
            GROUP BY agent_key, DATE(invoice_date)
            ORDER BY d DESC, agent_key");
        $st->execute(array_merge([$dateStart, $dateEnd], $agents));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $agent = (string) $r['agent_key'];
            if ($selectedAgent !== null && $agent !== $selectedAgent) {
                continue;
            }
            $day = (string) $r['d'];
            $salesDays[$day][$agent] = $r;
            foreach (['sale_cnt', 'ret_cnt'] as $key) {
                $salesTotals[$key] += (int) $r[$key];
            }
            foreach (['sale_sum', 'ret_sum'] as $key) {
                $salesTotals[$key] += (float) $r[$key];
            }
        }

        // جلب فواتير الصفحة الحالية وأصنافها (بريف ٢٠٨ج)
        $totalSalesCount = (int) $salesTotals['sale_cnt'] + (int) $salesTotals['ret_cnt'];
        $salesTotalPages = max(1, (int) ceil($totalSalesCount / $salesLimit));
        if ($salesPage > $salesTotalPages && $totalSalesCount > 0) {
            $salesPage = $salesTotalPages;
        }
        $salesOffset = ($salesPage - 1) * $salesLimit;

        if ($totalSalesCount > 0) {
            $invWhere = "invoice_date BETWEEN ? AND ? AND doc_type IN (4, 7, 17)";
            $invParams = [$dateStart, $dateEnd];
            if ($selectedAgent !== null) {
                $invWhere .= " AND agent_key = ?";
                $invParams[] = $selectedAgent;
            } else {
                $invWhere .= " AND agent_key IN ($agentMarks)";
                $invParams = array_merge($invParams, $agents);
            }
            $stInvoices = $pdo->prepare("SELECT agent_key, sth_id, suffix, doc_no, doc_type, invoice_date, total_amount, net_amount
                FROM br_invoices
                WHERE $invWhere
                ORDER BY invoice_date DESC, sth_id DESC
                LIMIT $salesLimit OFFSET $salesOffset");
            $stInvoices->execute($invParams);
            $salesInvoices = $stInvoices->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($salesInvoices)) {
                $tupleMarks = [];
                $lineParams = [];
                foreach ($salesInvoices as $inv) {
                    $tupleMarks[] = '(?, ?, ?)';
                    $lineParams[] = (string) $inv['agent_key'];
                    $lineParams[] = (int) $inv['sth_id'];
                    $lineParams[] = (string) $inv['suffix'];
                }
                $tupleSql = implode(',', $tupleMarks);
                $stLines = $pdo->prepare("SELECT agent_key, sth_id, suffix, item_code, item_name, quantity, unit_price
                    FROM br_invoice_lines
                    WHERE (agent_key, sth_id, suffix) IN ($tupleSql)
                    ORDER BY agent_key, sth_id, suffix, line_no ASC");
                $stLines->execute($lineParams);
                while ($lr = $stLines->fetch(PDO::FETCH_ASSOC)) {
                    $invKey = $lr['agent_key'] . '|' . $lr['sth_id'] . '|' . $lr['suffix'];
                    $salesLinesByInvoice[$invKey][] = $lr;
                }
            }
        }
        foreach ($salesBranches as $b) {
            $salesAgentNames[(string) $b['agent']] = (string) $b['name'];
        }
    }

    return [
        'too_long'          => $salesTooLong,
        'branches'          => $salesBranches,
        'agent_names'       => $salesAgentNames,
        'counts'            => $salesCounts,
        'days'              => $salesDays,
        'totals'            => $salesTotals,
        'net'               => $salesTotals['sale_sum'] - $salesTotals['ret_sum'],
        'total_count'       => $totalSalesCount,
        'total_pages'       => $salesTotalPages,
        'page'              => $salesPage,
        'offset'            => $salesOffset,
        'limit'             => $salesLimit,
        'invoices'          => $salesInvoices,
        'lines_by_invoice'  => $salesLinesByInvoice,
        'branch_filter'     => $branchFilter,
    ];
}

/**
 * استعلام فواتير المشتريات والمردودات (إجماليات التابات وأول ٥٠٠ صف).
 *
 * @param string $dateStart    تاريخ البداية
 * @param string $dateEnd      تاريخ النهاية
 * @param int|null $branchFilter رقم فرع الشركة company_branch (1,2,3,5,6)
 * @return array
 */
function act_purchases_data(
    string $dateStart,
    string $dateEnd,
    ?int $branchFilter = null
): array {
    $pdo = db();
    $purchaseBranches = [1, 2, 3, 5, 6];
    $purchaseCounts = [];
    $purchaseTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
    $purchaseRows = [];

    if ($branchFilter !== null && $branchFilter !== 0 && !in_array($branchFilter, $purchaseBranches, true)) {
        $branchFilter = null;
    }

    $st = $pdo->prepare("SELECT company_branch, doc_type, COUNT(*) AS cnt, SUM(net_amount) AS net_sum
        FROM hq_invoices
        WHERE doc_type IN (2,6) AND company_branch IN (1,2,3,5,6)
          AND (invoice_date BETWEEN ? AND ? OR (invoice_date IS NULL AND synced_at BETWEEN ? AND ?))
        GROUP BY company_branch, doc_type");
    $st->execute([$dateStart, $dateEnd, $dateStart, $dateEnd]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cb = (int) $r['company_branch'];
        $count = (int) $r['cnt'];
        $purchaseCounts[$cb][(int) $r['doc_type']] = $count;
        if ($branchFilter !== null && $branchFilter !== $cb) {
            continue;
        }
        $prefix = (int) $r['doc_type'] === 2 ? 'sale' : 'ret';
        $purchaseTotals[$prefix . '_cnt'] += $count;
        $purchaseTotals[$prefix . '_sum'] += (float) $r['net_sum'];
    }

    $purchaseParams = [$dateStart, $dateEnd, $dateStart, $dateEnd];
    $purchaseSql = "SELECT sth_id, suffix, doc_no, supplier_name, doc_type, company_branch,
            total_amount, net_amount, COALESCE(invoice_date, synced_at) AS event_time
        FROM hq_invoices
        WHERE doc_type IN (2,6) AND company_branch IN (1,2,3,5,6)
          AND (invoice_date BETWEEN ? AND ? OR (invoice_date IS NULL AND synced_at BETWEEN ? AND ?))";
    if ($branchFilter !== null) {
        $purchaseSql .= ' AND company_branch = ?';
        $purchaseParams[] = $branchFilter;
    }
    $purchaseSql .= ' ORDER BY event_time DESC, sth_id DESC, suffix DESC LIMIT 500';
    $st = $pdo->prepare($purchaseSql);
    $st->execute($purchaseParams);
    $purchaseRows = $st->fetchAll(PDO::FETCH_ASSOC);

    return [
        'branches'      => $purchaseBranches,
        'counts'        => $purchaseCounts,
        'totals'        => $purchaseTotals,
        'net'           => $purchaseTotals['sale_sum'] - $purchaseTotals['ret_sum'],
        'total_count'   => $purchaseTotals['sale_cnt'] + $purchaseTotals['ret_cnt'],
        'rows'          => $purchaseRows,
        'branch_filter' => $branchFilter,
    ];
}
