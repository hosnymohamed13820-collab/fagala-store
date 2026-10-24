<?php
/**
 * activity.php — صفحة «كل حركة على السيستم» للمدير
 * نظام الفجالة ستور المالي (بريف ١٩٥ · اتقفلت في بريف ٢٠٦)
 *
 * كل نوع في الخط الزمني = استعلام واحد بـLIMIT 200، والدمج في PHP.
 * الصفحة قراية بس — مفيش أي INSERT/UPDATE/DELETE من هنا.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// مخصصة للمالك فقط
if (!is_owner()) {
    http_response_code(403);
    die('هذه الصفحة مخصصة للمالك فقط.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/sms_lib.php';              // sms_parse() · sms_is_foreign()
require_once __DIR__ . '/branch_invoices_lib.php';  // br_inv_map() — خريطة وكيل كل فرع
sens_page();

$pdo = db();

// =========================================================================
//  خرائط الفروع
//  🔴 company_branch = رقم الفرع في نظام الشركة = branches.sto_id
//     (الإدارة company_branch = 1 ومش مربوطة بـsto_id في كل النسخ)
// =========================================================================

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

// =========================================================================
//  ١. كروت «محتاج تصرّف» — كل رقم استعلام مستقل
// =========================================================================

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

// رسايل إنستا **الواردة** اللي من غير إذن — آخر ٧ أيام بس.
// 🔴 كان بيعدّ كل sms_inbox من أول الدنيا (الصادر والأجنبي كمان).
// النوع بيتحدد من نص الرسالة نفسها (sms_parse) — مش عمود في القاعدة،
// فنقرا رسايل آخر ٧ أيام اللي مالهاش إذن ونحلّلها في PHP (استعلام واحد).
// 🔴 فلتر رسايل إنستا المشترك — مكان واحد بس. المكرر بالرقم المرجعي مستخبي (بريف ٢٠٥).
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

// =========================================================================
//  ٢. الفلاتر
// =========================================================================

$period = (string) ($_GET['period'] ?? 'today');
$view = (string) ($_GET['view'] ?? 'timeline');
$branchFilter = isset($_GET['branch']) && $_GET['branch'] !== 'all' && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;
$typeFilter = (string) ($_GET['type'] ?? 'all');
$customFrom = (string) ($_GET['from'] ?? '');
$customTo = (string) ($_GET['to'] ?? '');

if (!isset($_GET['view'])) {
    if ($typeFilter === 'purchases' || $typeFilter === 'returns') {
        $view = 'purchases';
    } elseif ($typeFilter === 'sales') {
        $view = 'sales';
    }
}
if (!in_array($view, ['timeline', 'sales', 'purchases'], true)) {
    $view = 'timeline';
}
if (!isset($_GET['view']) && $view === 'purchases' && $branchFilter !== null) {
    // اللينك القديم كان بيبعت branches.id؛ تاب المشتريات بيستخدم company_branch.
    $branchFilter = act_company_branch_of($branchFilter, $branchById);
}
$validTypes = ['all', 'shifts', 'custody', 'receiving', 'treasury',
               'shortage', 'attendance', 'stocktake', 'sms', 'newitems',
               'permits', 'transfers', 'salaries', 'advances'];
if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = 'all';
}

$today = date('Y-m-d');
$timeStart = microtime(true);

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

// لينكات التابات والفروع بتحافظ على نفس الفترة.
$periodParams = ['period' => $period];
if ($customFrom !== '') {
    $periodParams['from'] = $customFrom;
}
if ($customTo !== '') {
    $periodParams['to'] = $customTo;
}
function act_url(array $params): string
{
    return 'activity.php?' . http_build_query($params);
}

// =========================================================================
//  ٣. استعلامات الخط الزمني (استعلام واحد لكل نوع بـ LIMIT 200)
// =========================================================================

$feed = [];

if ($view === 'timeline') {

// ج) الورديات (shifts) + حدث «مراجعة وردية» منفصل
if ($typeFilter === 'all' || $typeFilter === 'shifts') {
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
if ($typeFilter === 'all' || $typeFilter === 'custody') {
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
if ($typeFilter === 'all' || $typeFilter === 'receiving') {
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
//    نقصان JOIN واحد = الصف يطلع «—» ويفلت من فلتر الفرع.
if ($typeFilter === 'all' || $typeFilter === 'treasury') {
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
if ($typeFilter === 'all' || $typeFilter === 'sms') {
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
if ($typeFilter === 'all' || $typeFilter === 'shortage') {
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
if ($typeFilter === 'all' || $typeFilter === 'attendance') {
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
if ($typeFilter === 'all' || $typeFilter === 'stocktake') {
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
// 🔴 العمود ده جديد: الصفوف القديمة created_at = NULL (شوف ملف الترقية)،
//    فمابنحسبهاش «صنف جديد» غلط.
$newItemsEver = false;
if ($typeFilter === 'all' || $typeFilter === 'newitems') {
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
if ($typeFilter === 'all' || $typeFilter === 'permits') {
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
if ($typeFilter === 'all' || $typeFilter === 'transfers') {
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
if ($typeFilter === 'all' || $typeFilter === 'salaries') {
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
if ($typeFilter === 'all' || $typeFilter === 'advances') {
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

}

// المبيعات: عدد التاب من كل الفروع، والتفاصيل اليومية من استعلام مجمّع واحد.
$salesBranches = br_inv_map();
$salesCounts = [];
$salesDays = [];
$salesTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
$salesTooLong = false;
if ($view === 'sales') {
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
    if (!$salesTooLong) {
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
    }
}

// المشتريات: إجماليات التابات والكروت من كل الفواتير، ثم أول ٥٠٠ صف للعرض.
$purchaseBranches = [1, 2, 3, 5, 6];
$purchaseCounts = [];
$purchaseTotals = ['sale_cnt' => 0, 'sale_sum' => 0.0, 'ret_cnt' => 0, 'ret_sum' => 0.0];
$purchaseRows = [];
if ($view === 'purchases') {
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
}

// ترتيب الخط الزمني تنازلياً حسب الوقت (الأحدث فوق)
usort($feed, function ($a, $b) {
    return strcmp($b['time'], $a['time']);
});

// اقتطاع أول 500 حركة كحد أقصى للعرض السلس
if (count($feed) > 500) {
    $feed = array_slice($feed, 0, 500);
}

$timeElapsedMs = round((microtime(true) - $timeStart) * 1000, 2);

$pageTitle = 'حركات النظام';
$active = 'activity';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* تابات الصفحة والفروع بنفس شكل صفحة المراجعة */
.branch-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.branch-tabs .btab {
    padding:7px 16px; border-radius:9px; background:var(--surface); color:var(--accent-text);
    text-decoration:none; font-size:14px; border:1.5px solid var(--line);
    transition:.15s; font-family:inherit; cursor:pointer;
}
.branch-tabs .btab:hover  { border-color:var(--accent); }
.branch-tabs .btab.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
.rv-tabs { display:flex; gap:18px; border-bottom:2px solid var(--line); margin-bottom:20px; }
.rv-tab { padding:10px 4px; cursor:pointer; color:var(--ink-500); font-weight:600; font-size:15px;
    border-bottom:3px solid transparent; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; font-family:inherit; }
.rv-tab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
.rv-pane { display:none; } .rv-pane.active { display:block; }
.rv-tabs a { text-decoration:none; }
.activity-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px; }
.activity-stat { background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:14px; min-width:0; }
.activity-stat-label { display:block; color:var(--ink-500); font-size:12px; margin-bottom:6px; }
.activity-stat-value { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; }
.activity-table-wrap { overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; }
.activity-table-wrap .ftable { width:100%; min-width:650px; margin:0; }
.activity-table-wrap th.num, .activity-table-wrap td.num { text-align:left; font-variant-numeric:tabular-nums; white-space:nowrap; }
.activity-table-wrap tfoot { font-weight:700; }
/* كروت محتاج تصرف */
.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.action-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-decoration: none;
    color: var(--text);
    transition: transform 0.15s, border-color 0.15s, box-shadow 0.15s;
    box-shadow: var(--shadow-sm);
}
.action-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent);
    box-shadow: var(--shadow);
}
.action-card-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.action-card-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-500);
}
.action-card-num {
    font-size: 24px;
    font-weight: 700;
    line-height: 1.1;
}
.action-card-sub {
    font-size: 11px;
    color: var(--ink-400);
    margin-top: 2px;
}
.action-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

/* شريط الفلاتر */
.filter-bar {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 14px 16px;
    margin-bottom: 18px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-bar select, .filter-bar input[type="date"] {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-sm, 6px);
    padding: 6px 10px;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
}
.filter-bar select:focus, .filter-bar input:focus {
    border-color: var(--accent);
    outline: none;
}

/* جدول الخط الزمني */
.timeline-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px;
    box-shadow: var(--shadow-sm);
}
.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.timeline-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.timeline-meta {
    font-size: 12px;
    color: var(--ink-400);
}
.timeline-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.activity-row:hover {
    background: var(--line-soft);
}
.activity-link {
    color: var(--accent-text);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12.5px;
}
.activity-link:hover {
    text-decoration: underline;
}
.empty-box {
    text-align: center;
    padding: 40px 16px;
    color: var(--ink-400);
}

/* أعمدة الجدول — العرض من هنا مش من inline style (عشان الموبايل يلغيها) */
.act-col-time   { width: 115px; }
.act-col-type   { width: 120px; }
.act-col-branch { width: 110px; }
.act-col-actor  { width: 130px; }
.act-col-link   { width: 75px; text-align: center; }

/* موبايل ٣٩٠ — مفيش سكرول بالعرض */
@media (max-width: 600px) {
    .action-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .action-card { padding: 10px 12px; }
    .action-card-num { font-size: 20px; }
    .action-card-icon { width: 34px; height: 34px; font-size: 18px; }
    .filter-bar { padding: 10px; gap: 8px; }
    .filter-bar > div { width: 100%; }
    .filter-bar select, .filter-bar input[type="date"] { flex: 1 1 auto; min-width: 0; }
    .filter-bar .btn { width: 100%; margin-right: 0 !important; }
    .timeline-card { padding: 10px; }
    .act-table th, .act-table td { padding: 7px 6px; font-size: 11.5px; }
    .act-table .act-col-time { width: 62px; }
    .act-table .act-col-type { width: 82px; }
    .act-table .act-col-branch { width: 66px; }
    .act-table .act-col-actor { display: none; }
    .act-table .act-col-link { width: 38px; }
    .act-table .fchip { white-space: normal; padding: 2px 6px; }
    .act-table .fchip i { display: none; }
    .activity-link { font-size: 11px; }
}
</style>

<div class="content-container">

    <nav class="rv-tabs" aria-label="أقسام حركات النظام">
        <?php foreach (['timeline' => 'الخط الزمني', 'sales' => 'المبيعات', 'purchases' => 'المشتريات'] as $tabKey => $tabLabel): ?>
            <a class="rv-tab <?= $view === $tabKey ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => $tabKey]))) ?>"><?= e($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($view === 'timeline'): ?>

    <!-- كروت «محتاج تصرّف» -->
    <div class="action-grid">
        <!-- ورديات مستنية مراجعة -->
        <a href="review.php" class="action-card">
            <div class="action-card-info">
                <span class="action-card-label">ورديات مستنية مراجعة</span>
                <span class="action-card-num" style="color:<?= $pendingShiftsCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $pendingShiftsCount ?>
                </span>
                <span class="action-card-sub"><?= $pendingShiftsCount > 0 ? 'تحتاج فحص المحاسب' : 'كل الورديات مراجعة' ?></span>
            </div>
            <div class="action-card-icon" style="background:var(--warn-soft);color:var(--warn);">
                <i class="ti ti-clock"></i>
            </div>
        </a>

        <!-- عهد مستنية اعتماد -->
        <a href="custody.php" class="action-card">
            <div class="action-card-info">
                <span class="action-card-label">عهد مستنية اعتماد</span>
                <span class="action-card-num" style="color:<?= $pendingCustodyCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $pendingCustodyCount ?>
                </span>
                <span class="action-card-sub"><?= $pendingCustodyCount > 0 ? 'بانتظار الاعتماد' : 'لا توجد عهد معلقة' ?></span>
            </div>
            <div class="action-card-icon" style="background:var(--accent-soft);color:var(--accent-text);">
                <i class="ti ti-cash"></i>
            </div>
        </a>

        <!-- فواتير متأخر استلامها -->
        <a href="receiving.php" class="action-card">
            <div class="action-card-info">
                <span class="action-card-label">فواتير متأخر استلامها</span>
                <span class="action-card-num" style="color:<?= $lateInvoicesTotal > 0 ? 'var(--neg)' : 'var(--pos)' ?>;">
                    <?= $lateInvoicesTotal ?>
                </span>
                <span class="action-card-sub">
                    <?php if ($lateInvoicesTotal > 0 && !empty($lateInvoicesBreakdown)): ?>
                        <?= e(implode(' · ', array_slice($lateInvoicesBreakdown, 0, 2))) ?>
                    <?php else: ?>
                        لا توجد فواتير متأخرة
                    <?php endif; ?>
                </span>
            </div>
            <div class="action-card-icon" style="background:var(--neg-soft);color:var(--neg);">
                <i class="ti ti-truck"></i>
            </div>
        </a>

        <!-- رسايل إنستا الواردة من غير إذن — آخر ٧ أيام -->
        <a href="sms_inbox.php?from=<?= e($smsCardFrom) ?>&to=<?= e($smsCardTo) ?>&kind=in" class="action-card">
            <div class="action-card-info">
                <span class="action-card-label">رسايل إنستا بدون إذن</span>
                <span class="action-card-num" style="color:<?= $smsNoPermitCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $smsNoPermitCount ?>
                </span>
                <span class="action-card-sub"><?= $smsNoPermitCount > 0 ? 'واردة آخر ٧ أيام — تحتاج تسوية أو إذن' : 'كل الرسايل الواردة مضبوطة' ?></span>
            </div>
            <div class="action-card-icon" style="background:var(--accent-soft);color:var(--accent-text);">
                <i class="ti ti-device-mobile"></i>
            </div>
        </a>
    </div>

    <?php endif; ?>

    <!-- شريط الفلاتر -->
    <form method="GET" action="activity.php" class="filter-bar">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="periodSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-calendar"></i> الفترة:</label>
            <select name="period" id="periodSelect" onchange="toggleCustomDates(this.value)">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>النهارده</option>
                <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>أمس</option>
                <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>آخر ٧ أيام</option>
                <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>آخر ٣٠ يوم</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>الشهر ده</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>فترة مخصصة...</option>
            </select>
        </div>

        <div id="customDatesWrap" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;align-items:center;gap:6px;">
            <input type="date" name="from" value="<?= e($customFrom) ?>" title="من تاريخ">
            <span style="color:var(--ink-400);">إلى</span>
            <input type="date" name="to" value="<?= e($customTo) ?>" title="إلى تاريخ">
        </div>

        <?php if ($view === 'timeline'): ?>
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="branchSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-building-store"></i> الفرع:</label>
            <select name="branch" id="branchSelect">
                <option value="all">كل الفروع</option>
                <?php foreach ($branchesList as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $branchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;align-items:center;gap:6px;">
            <label for="typeSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-filter"></i> النوع:</label>
            <select name="type" id="typeSelect">
                <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>كل الحركات</option>
                <option value="newitems" <?= $typeFilter === 'newitems' ? 'selected' : '' ?>>أصناف جديدة</option>
                <option value="shifts" <?= $typeFilter === 'shifts' ? 'selected' : '' ?>>ورديات</option>
                <option value="custody" <?= $typeFilter === 'custody' ? 'selected' : '' ?>>عهد</option>
                <option value="receiving" <?= $typeFilter === 'receiving' ? 'selected' : '' ?>>استلام فواتير</option>
                <option value="treasury" <?= $typeFilter === 'treasury' ? 'selected' : '' ?>>خزينة</option>
                <option value="transfers" <?= $typeFilter === 'transfers' ? 'selected' : '' ?>>تحويلات خزائن</option>
                <option value="shortage" <?= $typeFilter === 'shortage' ? 'selected' : '' ?>>نواقص وطلبات</option>
                <option value="attendance" <?= $typeFilter === 'attendance' ? 'selected' : '' ?>>حضور وانصراف</option>
                <option value="stocktake" <?= $typeFilter === 'stocktake' ? 'selected' : '' ?>>جرد</option>
                <option value="sms" <?= $typeFilter === 'sms' ? 'selected' : '' ?>>رسايل بنك</option>
                <option value="permits" <?= $typeFilter === 'permits' ? 'selected' : '' ?>>أذون إنستاباي</option>
                <option value="salaries" <?= $typeFilter === 'salaries' ? 'selected' : '' ?>>صرف مرتبات</option>
                <option value="advances" <?= $typeFilter === 'advances' ? 'selected' : '' ?>>سلف</option>
            </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-sm" style="margin-right:auto;">
            <i class="ti ti-filter"></i> تصفية
        </button>
    </form>

    <?php if ($view === 'timeline'): ?>
    <!-- الخط الزمني -->
    <div class="timeline-card">
        <div class="timeline-header">
            <h3 class="timeline-title">
                <i class="ti ti-list-details" style="color:var(--accent-text);"></i> سجل الحركات بالوقت
                <span class="fchip fchip-mute" style="font-size:11px;"><?= count($feed) ?> حركة</span>
            </h3>
            <span class="timeline-meta">
                الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?> · تم التحميل في <?= e((string)$timeElapsedMs) ?> مللي ثانية
            </span>
        </div>

        <div class="timeline-table-wrap">
            <table class="ftable act-table" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th class="act-col-time">الوقت</th>
                        <th class="act-col-type">النوع</th>
                        <th class="act-col-branch">الفرع</th>
                        <th>الوصف</th>
                        <th class="act-col-actor">مين</th>
                        <th class="act-col-link">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feed)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-box">
                                    <i class="ti ti-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                                    <?php if ($typeFilter === 'newitems'): ?>
                                        <?php if (!$newItemsEver): ?>
                                            مفيش أصناف اتضافت من وقت ما بدأنا نسجّل.
                                        <?php else: ?>
                                            مفيش أصناف جديدة في الفترة المختارة.
                                        <?php endif; ?>
                                    <?php else: ?>
                                        لا توجد حركات مسجلة في هذه الفترة بالفلاتر المحددة.
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feed as $item): ?>
                            <tr class="activity-row">
                                <td class="act-col-time" style="font-size:12px;color:var(--ink-500);white-space:nowrap;" dir="ltr">
                                    <?= e($item['time_label'] ?? date('m/d H:i', strtotime($item['time']))) ?>
                                </td>
                                <td class="act-col-type">
                                    <span class="fchip <?= e($item['badge_class']) ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;">
                                        <i class="ti <?= e($item['icon']) ?>"></i> <?= e($item['type_label']) ?>
                                    </span>
                                </td>
                                <td class="act-col-branch" style="font-weight:600;font-size:12.5px;">
                                    <?= e($item['branch']) ?>
                                </td>
                                <td style="font-size:13px;line-height:1.4;">
                                    <?php /* desc جواه HTML من sens_mask — الأجزاء النصية اتعملها e() وقت البناء */ ?>
                                    <?= $item['desc'] ?>
                                </td>
                                <td class="act-col-actor" style="font-size:12px;color:var(--ink-600);">
                                    <?= e($item['actor']) ?>
                                </td>
                                <td class="act-col-link">
                                    <?php if (!empty($item['link'])): ?>
                                        <a href="<?= e($item['link']) ?>" class="activity-link" title="عرض التفاصيل">
                                            فتح <i class="ti ti-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($view === 'sales'): ?>
        <nav class="branch-tabs" aria-label="فروع المبيعات">
            <a class="btab <?= $branchFilter === null ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'sales', 'branch' => 'all']))) ?>">الكل</a>
            <?php foreach ($salesBranches as $b): ?>
                <a class="btab <?= $branchFilter === (int) $b['id'] ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'sales', 'branch' => (int) $b['id']]))) ?>">
                    <?= e($b['name']) ?> (<?= number_format($salesCounts[(string) $b['agent']] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if ($salesTooLong): ?>
            <div class="timeline-card">اختار فترة أقل من سنة.</div>
        <?php else: ?>
            <?php $salesNet = $salesTotals['sale_sum'] - $salesTotals['ret_sum']; ?>
            <div class="activity-stats">
                <?php foreach ([
                    ['فواتير البيع', number_format($salesTotals['sale_cnt'])],
                    ['إجمالي البيع', sens_mask(number_format($salesTotals['sale_sum'], 2))],
                    ['عدد المرتجعات', number_format($salesTotals['ret_cnt'])],
                    ['إجمالي المرتجعات', sens_mask(number_format($salesTotals['ret_sum'], 2))],
                    ['الصافي', sens_mask(number_format($salesNet, 2))],
                ] as [$label, $value]): ?>
                    <div class="activity-stat"><span class="activity-stat-label"><?= e($label) ?></span><span class="activity-stat-value"><?= $value ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="timeline-card">
                <div class="timeline-header"><h3 class="timeline-title">المبيعات اليومية</h3><span class="timeline-meta">الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?></span></div>
                <div class="activity-table-wrap">
                    <table class="ftable">
                        <thead><tr><th>اليوم</th>
                            <?php if ($branchFilter === null): ?>
                                <?php foreach ($salesBranches as $b): ?><th class="num"><?= e($b['name']) ?></th><?php endforeach; ?>
                                <th class="num">الإجمالي</th>
                            <?php else: ?>
                                <th class="num">فواتير البيع</th><th class="num">البيع</th><th class="num">عدد المرتجعات</th><th class="num">المرتجعات</th><th class="num">الصافي</th>
                            <?php endif; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($salesDays as $day => $byAgent): ?>
                                <tr><td><a class="activity-link" href="daily_summary.php?date=<?= e(urlencode($day)) ?>"><?= e($day) ?></a></td>
                                    <?php if ($branchFilter === null): ?>
                                        <?php $dayNet = 0.0; foreach ($salesBranches as $b): ?>
                                            <?php $r = $byAgent[(string) $b['agent']] ?? null; $branchNet = $r ? (float) $r['sale_sum'] - (float) $r['ret_sum'] : 0.0; $dayNet += $branchNet; ?>
                                            <td class="num"><?= sens_mask(number_format($branchNet, 2)) ?></td>
                                        <?php endforeach; ?>
                                        <td class="num"><?= sens_mask(number_format($dayNet, 2)) ?></td>
                                    <?php else: ?>
                                        <?php $r = reset($byAgent); ?>
                                        <td class="num"><?= number_format((int) $r['sale_cnt']) ?></td>
                                        <td class="num"><?= sens_mask(number_format((float) $r['sale_sum'], 2)) ?></td>
                                        <td class="num"><?= number_format((int) $r['ret_cnt']) ?></td>
                                        <td class="num"><?= sens_mask(number_format((float) $r['ret_sum'], 2)) ?></td>
                                        <td class="num"><?= sens_mask(number_format((float) $r['sale_sum'] - (float) $r['ret_sum'], 2)) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$salesDays): ?><tr><td colspan="<?= $branchFilter === null ? count($salesBranches) + 2 : 6 ?>">مفيش مبيعات في الفترة دي.</td></tr><?php endif; ?>
                        </tbody>
                        <tfoot><tr><td>الإجمالي</td>
                            <?php if ($branchFilter === null): ?>
                                <?php foreach ($salesBranches as $b): ?>
                                    <?php $branchTotal = 0.0; foreach ($salesDays as $byAgent) { $r = $byAgent[(string) $b['agent']] ?? null; if ($r) { $branchTotal += (float) $r['sale_sum'] - (float) $r['ret_sum']; } } ?>
                                    <td class="num"><?= sens_mask(number_format($branchTotal, 2)) ?></td>
                                <?php endforeach; ?>
                                <td class="num"><?= sens_mask(number_format($salesNet, 2)) ?></td>
                            <?php else: ?>
                                <td class="num"><?= number_format($salesTotals['sale_cnt']) ?></td>
                                <td class="num"><?= sens_mask(number_format($salesTotals['sale_sum'], 2)) ?></td>
                                <td class="num"><?= number_format($salesTotals['ret_cnt']) ?></td>
                                <td class="num"><?= sens_mask(number_format($salesTotals['ret_sum'], 2)) ?></td>
                                <td class="num"><?= sens_mask(number_format($salesNet, 2)) ?></td>
                            <?php endif; ?>
                        </tr></tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <nav class="branch-tabs" aria-label="فروع المشتريات">
            <a class="btab <?= $branchFilter === null ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'purchases', 'branch' => 'all']))) ?>">الكل</a>
            <?php foreach ($purchaseBranches as $cb): ?>
                <a class="btab <?= $branchFilter === $cb ? 'active' : '' ?>" href="<?= e(act_url(array_merge($periodParams, ['view' => 'purchases', 'branch' => $cb]))) ?>">
                    <?= e(act_company_branch_name($cb, $branchBySto)) ?> (<?= number_format($purchaseCounts[$cb][2] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </nav>
        <?php $purchaseNet = $purchaseTotals['sale_sum'] - $purchaseTotals['ret_sum']; $purchaseCount = $purchaseTotals['sale_cnt'] + $purchaseTotals['ret_cnt']; ?>
        <div class="activity-stats">
            <?php foreach ([
                ['فواتير الشراء', number_format($purchaseTotals['sale_cnt'])],
                ['إجمالي الشراء', sens_mask(number_format($purchaseTotals['sale_sum'], 2))],
                ['عدد المردودات', number_format($purchaseTotals['ret_cnt'])],
                ['إجمالي المردودات', sens_mask(number_format($purchaseTotals['ret_sum'], 2))],
                ['الصافي', sens_mask(number_format($purchaseNet, 2))],
            ] as [$label, $value]): ?>
                <div class="activity-stat"><span class="activity-stat-label"><?= e($label) ?></span><span class="activity-stat-value"><?= $value ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="timeline-card">
            <div class="timeline-header"><h3 class="timeline-title">فواتير المشتريات والمردودات</h3><span class="timeline-meta">الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?></span></div>
            <div class="activity-table-wrap">
                <table class="ftable">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>رقم الفاتورة</th><th>المورد</th><?php if ($branchFilter === null): ?><th>الفرع</th><?php endif; ?><th class="num">الإجمالي</th><th class="num">الصافي</th></tr></thead>
                    <tbody>
                        <?php foreach ($purchaseRows as $r): ?>
                            <?php $cb = (int) $r['company_branch']; $dt = (int) $r['doc_type']; ?>
                            <tr><td><?= e((string) $r['event_time']) ?></td>
                                <td><span class="fchip <?= $dt === 2 ? 'fchip-pos' : 'fchip-warn' ?>"><?= $dt === 2 ? 'شراء' : 'مردود' ?></span></td>
                                <td><a class="activity-link" href="<?= e('invoices.php?invoice=' . (int) $r['sth_id'] . '&suffix=' . urlencode((string) $r['suffix']) . '&cb=' . $cb . '&dt=' . $dt) ?>"><?= e((string) $r['doc_no']) ?></a></td>
                                <td><?= e((string) ($r['supplier_name'] ?: 'غير محدد')) ?></td>
                                <?php if ($branchFilter === null): ?><td><?= e(act_company_branch_name($cb, $branchBySto)) ?></td><?php endif; ?>
                                <td class="num"><?= sens_mask(number_format((float) $r['total_amount'], 2)) ?></td>
                                <td class="num"><?= sens_mask(number_format((float) $r['net_amount'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$purchaseRows): ?><tr><td colspan="<?= $branchFilter === null ? 7 : 6 ?>">مفيش فواتير في الفترة دي.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><td colspan="<?= $branchFilter === null ? 5 : 4 ?>">الإجمالي: شراء <?= number_format($purchaseTotals['sale_cnt']) ?> (<?= sens_mask(number_format($purchaseTotals['sale_sum'], 2)) ?>) · مردود <?= number_format($purchaseTotals['ret_cnt']) ?> (<?= sens_mask(number_format($purchaseTotals['ret_sum'], 2)) ?>)</td><td class="num">—</td><td class="num"><?= sens_mask(number_format($purchaseNet, 2)) ?></td></tr></tfoot>
                </table>
            </div>
            <?php if ($purchaseCount > 500): ?><p class="timeline-meta">ظاهر أول ٥٠٠ من <?= number_format($purchaseCount) ?> — ضيّق الفترة</p><?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function toggleCustomDates(val) {
    var el = document.getElementById('customDatesWrap');
    if (el) el.style.display = (val === 'custom') ? 'flex' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
