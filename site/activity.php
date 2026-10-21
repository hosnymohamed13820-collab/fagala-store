<?php
/**
 * activity.php — صفحة «كل حركة على السيستم» للمدير
 * نظام الفجالة ستور المالي (بريف ١٩٥)
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
sens_page();

$pdo = db();

// =========================================================================
//  ١. كروت «محتاج تصرّف»
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

// رسايل إنستا من غير إذن
$stSmsNoPermit = $pdo->query("
    SELECT COUNT(*)
    FROM sms_inbox s
    LEFT JOIN sms_permits p ON p.sms_id = s.id
    WHERE p.id IS NULL
");
$smsNoPermitCount = (int) ($stSmsNoPermit ? $stSmsNoPermit->fetchColumn() : 0);

// =========================================================================
//  ٢. الفلاتر
// =========================================================================

$period = (string) ($_GET['period'] ?? 'today');
$branchFilter = isset($_GET['branch']) && $_GET['branch'] !== 'all' && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;
$typeFilter = (string) ($_GET['type'] ?? 'all');
$customFrom = (string) ($_GET['from'] ?? '');
$customTo = (string) ($_GET['to'] ?? '');

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

// قائمة الفروع للفلتر
$branchesList = $pdo->query("SELECT id, name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
//  ٣. استعلامات الخط الزمني (استعلام واحد لكل نوع بـ LIMIT 200)
// =========================================================================

$feed = [];

// أ) مشتريات الإدارة والفروع (doc_type = 2)
if ($typeFilter === 'all' || $typeFilter === 'purchases') {
    $sql = "
        SELECT 
            i.sth_id, i.doc_no, i.doc_type, i.supplier_name, i.total_amount, i.net_amount,
            i.company_branch AS branch_id, b.name AS branch_name,
            COALESCE(i.invoice_date, i.synced_at) AS event_time
        FROM hq_invoices i
        LEFT JOIN branches b ON b.id = i.company_branch
        WHERE i.doc_type = 2
          AND (i.invoice_date BETWEEN ? AND ? OR (i.invoice_date IS NULL AND i.synced_at BETWEEN ? AND ?))
    ";
    $params = [$dateStart, $dateEnd, $dateStart, $dateEnd];
    if ($branchFilter !== null) {
        $sql .= " AND i.company_branch = ?";
        $params[] = $branchFilter;
    }
    $sql .= " ORDER BY event_time DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $feed[] = [
            'time'        => (string) $r['event_time'],
            'type'        => 'purchases',
            'type_label'  => 'فاتورة شراء',
            'badge_class' => 'fchip-info',
            'icon'        => 'ti-file-invoice',
            'branch'      => $r['branch_name'] ?: 'الإدارة',
            'desc'        => 'فاتورة شراء #' . $r['doc_no'] . ' من المورد ' . ($r['supplier_name'] ?: 'غير محدد') . ' بمبلغ ' . sens_mask(number_format((float)$r['total_amount'], 2)) . ' ج',
            'actor'       => 'الإدارة',
            'link'        => 'invoices.php?search=' . urlencode((string)$r['doc_no']),
        ];
    }
}

// ب) مردودات المشتريات (doc_type = 6)
if ($typeFilter === 'all' || $typeFilter === 'returns') {
    $sql = "
        SELECT 
            i.sth_id, i.doc_no, i.doc_type, i.supplier_name, i.total_amount, i.net_amount,
            i.company_branch AS branch_id, b.name AS branch_name,
            COALESCE(i.invoice_date, i.synced_at) AS event_time
        FROM hq_invoices i
        LEFT JOIN branches b ON b.id = i.company_branch
        WHERE i.doc_type = 6
          AND (i.invoice_date BETWEEN ? AND ? OR (i.invoice_date IS NULL AND i.synced_at BETWEEN ? AND ?))
    ";
    $params = [$dateStart, $dateEnd, $dateStart, $dateEnd];
    if ($branchFilter !== null) {
        $sql .= " AND i.company_branch = ?";
        $params[] = $branchFilter;
    }
    $sql .= " ORDER BY event_time DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $feed[] = [
            'time'        => (string) $r['event_time'],
            'type'        => 'returns',
            'type_label'  => 'مردود شراء',
            'badge_class' => 'fchip-warn',
            'icon'        => 'ti-arrow-back-up',
            'branch'      => $r['branch_name'] ?: 'الإدارة',
            'desc'        => 'مردود شراء #' . $r['doc_no'] . ' للمورد ' . ($r['supplier_name'] ?: 'غير محدد') . ' بمبلغ ' . sens_mask(number_format((float)$r['total_amount'], 2)) . ' ج',
            'actor'       => 'الإدارة',
            'link'        => 'invoice_problems.php',
        ];
    }
}

// ج) الورديات (shifts)
if ($typeFilter === 'all' || $typeFilter === 'shifts') {
    $sql = "
        SELECT 
            s.id, s.branch_id, b.name AS branch_name, s.user_id, u.full_name AS user_name,
            s.shift_name, s.cash_total, s.difference, s.status, s.business_date,
            s.created_at AS event_time
        FROM shifts s
        LEFT JOIN branches b ON b.id = s.branch_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.created_at BETWEEN ? AND ?
    ";
    $params = [$dateStart, $dateEnd];
    if ($branchFilter !== null) {
        $sql .= " AND s.branch_id = ?";
        $params[] = $branchFilter;
    }
    $sql .= " ORDER BY s.created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $statusLabels = [
            'submitted'   => 'بانتظار المراجعة',
            'resubmitted' => 'أعيد تقديمها',
            'approved'    => 'معتمدة',
            'rejected'    => 'مرفوضة',
        ];
        $stTxt = $statusLabels[$r['status']] ?? $r['status'];
        $diffTxt = (float)$r['difference'] !== 0.0 ? ' (فرق: ' . sens_mask(number_format((float)$r['difference'], 2)) . ' ج)' : '';
        $feed[] = [
            'time'        => (string) $r['event_time'],
            'type'        => 'shifts',
            'type_label'  => 'وردية',
            'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : ($r['status'] === 'rejected' ? 'fchip-neg' : 'fchip-info'),
            'icon'        => 'ti-clock',
            'branch'      => $r['branch_name'] ?: 'غير محدد',
            'desc'        => 'تقفيل ' . ($r['shift_name'] ?: 'وردية') . ' — كاش: ' . sens_mask(number_format((float)$r['cash_total'], 2)) . ' ج' . $diffTxt . ' [' . $stTxt . ']',
            'actor'       => $r['user_name'] ?: 'كاشير',
            'link'        => 'review.php?shift_id=' . (int)$r['id'],
        ];
    }
}

// د) العهد (branch_custody)
if ($typeFilter === 'all' || $typeFilter === 'custody') {
    $sql = "
        SELECT 
            c.id, c.branch_id, b.name AS branch_name, c.submitted_by AS user_id, u.full_name AS user_name,
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
            'badge_class' => $r['status'] === 'closed' ? 'fchip-pos' : ($r['status'] === 'pending_review' ? 'fchip-warn' : 'fchip-info'),
            'icon'        => 'ti-cash',
            'branch'      => $r['branch_name'] ?: 'غير محدد',
            'desc'        => 'حركة عهدة #' . $r['id'] . ' — مصروفات: ' . sens_mask(number_format((float)$r['total_expenses'], 2)) . ' ج (الحالة: ' . $r['status'] . ')',
            'actor'       => $r['user_name'] ?: 'مسؤول العهدة',
            'link'        => 'custody.php',
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
            'badge_class' => $r['status'] === 'approved' ? 'fchip-pos' : ($r['status'] === 'received' ? 'fchip-info' : 'fchip-warn'),
            'icon'        => 'ti-truck',
            'branch'      => $r['branch_name'] ?: 'غير محدد',
            'desc'        => 'محضر استلام بضاعة #' . $r['id'] . ($r['invoice_no'] ? ' لفاتورة #' . $r['invoice_no'] : '') . ' [حالة: ' . $r['status'] . ']',
            'actor'       => $actor,
            'link'        => 'receiving.php?r=' . (int)$r['id'],
        ];
    }
}

// و) الخزينة (journal_entries)
if ($typeFilter === 'all' || $typeFilter === 'treasury') {
    $sql = "
        SELECT 
            j.id, j.treasury_id, t.name AS treasury_name, t.branch_id, b.name AS branch_name,
            j.description, j.debit, j.credit, j.created_by, u.full_name AS user_name,
            j.created_at AS event_time, j.entry_date
        FROM journal_entries j
        LEFT JOIN treasuries t ON t.id = j.treasury_id
        LEFT JOIN branches b ON b.id = t.branch_id
        LEFT JOIN users u ON u.id = j.created_by
        WHERE j.created_at BETWEEN ? AND ?
    ";
    $params = [$dateStart, $dateEnd];
    if ($branchFilter !== null) {
        $sql .= " AND t.branch_id = ?";
        $params[] = $branchFilter;
    }
    $sql .= " ORDER BY j.created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $amt = (float)$r['debit'] > 0 ? (float)$r['debit'] : (float)$r['credit'];
        $kindTxt = (float)$r['debit'] > 0 ? 'قبض' : 'صرف';
        $feed[] = [
            'time'        => (string) $r['event_time'],
            'type'        => 'treasury',
            'type_label'  => 'سند خزينة',
            'badge_class' => 'fchip-info',
            'icon'        => 'ti-building-bank',
            'branch'      => $r['branch_name'] ?: ($r['treasury_name'] ?: 'خزينة رئيسية'),
            'desc'        => 'حركة ' . $kindTxt . ': ' . $r['description'] . ' بمبلغ ' . sens_mask(number_format($amt, 2)) . ' ج',
            'actor'       => $r['user_name'] ?: 'محاسب',
            'link'        => 'treasury.php?t=' . (int)$r['treasury_id'],
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
        WHERE s.sms_at BETWEEN ? AND ?
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
            'desc'        => 'رسالة من ' . $r['sender'] . ': ' . mb_substr($r['body'], 0, 70) . '...' . ($hasPermit ? ' (بإذن مسجل)' : ' (بدون إذن)'),
            'actor'       => $r['sender'],
            'link'        => 'sms_inbox.php',
        ];
    }
}

// ح) طلبات النواقص والفروع (shortage_requests)
if ($typeFilter === 'all' || $typeFilter === 'shortage') {
    $sql = "
        SELECT 
            sr.id, sr.from_branch_id, b1.name AS from_branch_name,
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
            'badge_class' => 'fchip-info',
            'icon'        => 'ti-shopping-cart',
            'branch'      => $brTxt,
            'desc'        => 'طلب نواقص #' . $r['id'] . ($r['notes'] ? ' (' . mb_substr($r['notes'], 0, 50) . ')' : ''),
            'actor'       => $r['user_name'] ?: 'مسؤول الفرع',
            'link'        => 'shortage.php',
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
        $timeTxt = $r['check_in'] ? 'دخول: ' . substr((string)$r['check_in'], 0, 5) : '';
        if ($r['check_out']) $timeTxt .= ' · خروج: ' . substr((string)$r['check_out'], 0, 5);
        $feed[] = [
            'time'        => (string) $r['event_time'],
            'type'        => 'attendance',
            'type_label'  => 'حضور وانصراف',
            'badge_class' => 'fchip-info',
            'icon'        => 'ti-user-check',
            'branch'      => $r['branch_name'] ?: 'غير محدد',
            'desc'        => 'تسجيل حضور للموظف ' . $r['employee_name'] . ' (' . $timeTxt . ')',
            'actor'       => $r['employee_name'],
            'link'        => 'attendance.php',
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
            'desc'        => 'جلسة جرد #' . $r['id'] . ' [الحالة: ' . ($r['status'] === 'closed' ? 'مغلقة' : 'نشطة') . ']',
            'actor'       => $r['closed_by_name'] ?: ($r['started_by_name'] ?: 'مسؤول الجرد'),
            'link'        => 'stocktake.php',
        ];
    }
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
    background: var(--surface-hover, rgba(0, 0, 0, 0.02));
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
</style>

<div class="content-container">

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

        <!-- رسايل إنستا من غير إذن -->
        <a href="sms_inbox.php" class="action-card">
            <div class="action-card-info">
                <span class="action-card-label">رسايل إنستا بدون إذن</span>
                <span class="action-card-num" style="color:<?= $smsNoPermitCount > 0 ? 'var(--warn)' : 'var(--pos)' ?>;">
                    <?= $smsNoPermitCount ?>
                </span>
                <span class="action-card-sub"><?= $smsNoPermitCount > 0 ? 'تحتاج تسوية أو إذن' : 'كل الرسايل مضبوطة' ?></span>
            </div>
            <div class="action-card-icon" style="background:var(--accent-soft);color:var(--accent-text);">
                <i class="ti ti-device-mobile"></i>
            </div>
        </a>
    </div>

    <!-- شريط الفلاتر -->
    <form method="GET" action="activity.php" class="filter-bar">
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="periodSelect" style="font-size:12.5px;font-weight:600;color:var(--ink-500);"><i class="ti ti-calendar"></i> الفترة:</label>
            <select name="period" id="periodSelect" onchange="toggleCustomDates(this.value)">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>النهارده</option>
                <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>أمس</option>
                <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>آخر ٧ أيام</option>
                <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>آخر ٣٠ يوم</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>فترة مخصصة...</option>
            </select>
        </div>

        <div id="customDatesWrap" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;align-items:center;gap:6px;">
            <input type="date" name="from" value="<?= e($customFrom) ?>" title="من تاريخ">
            <span style="color:var(--ink-400);">إلى</span>
            <input type="date" name="to" value="<?= e($customTo) ?>" title="إلى تاريخ">
        </div>

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
                <option value="purchases" <?= $typeFilter === 'purchases' ? 'selected' : '' ?>>مشتريات</option>
                <option value="returns" <?= $typeFilter === 'returns' ? 'selected' : '' ?>>مردودات</option>
                <option value="shifts" <?= $typeFilter === 'shifts' ? 'selected' : '' ?>>ورديات</option>
                <option value="custody" <?= $typeFilter === 'custody' ? 'selected' : '' ?>>عهد</option>
                <option value="receiving" <?= $typeFilter === 'receiving' ? 'selected' : '' ?>>استلام فواتير</option>
                <option value="treasury" <?= $typeFilter === 'treasury' ? 'selected' : '' ?>>خزينة</option>
                <option value="shortage" <?= $typeFilter === 'shortage' ? 'selected' : '' ?>>نواقص وطلبات</option>
                <option value="attendance" <?= $typeFilter === 'attendance' ? 'selected' : '' ?>>حضور وانصراف</option>
                <option value="stocktake" <?= $typeFilter === 'stocktake' ? 'selected' : '' ?>>جرد</option>
                <option value="sms" <?= $typeFilter === 'sms' ? 'selected' : '' ?>>رسايل بنك</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary btn-sm" style="margin-right:auto;">
            <i class="ti ti-filter"></i> تصفية
        </button>
    </form>

    <!-- الخط الزمني -->
    <div class="timeline-card">
        <div class="timeline-header">
            <h3 class="timeline-title">
                <i class="ti ti-list-details" style="color:var(--accent-text);"></i> سجل الحركات بالوقت
                <span class="fchip fchip-info" style="font-size:11px;"><?= count($feed) ?> حركة</span>
            </h3>
            <span class="timeline-meta">
                الفترة: <?= e(substr($dateStart, 0, 10)) ?> إلى <?= e(substr($dateEnd, 0, 10)) ?> · تم التحميل في <?= e((string)$timeElapsedMs) ?> مللي ثانية
            </span>
        </div>

        <div class="timeline-table-wrap">
            <table class="ftable" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th style="width:115px;">الوقت</th>
                        <th style="width:120px;">النوع</th>
                        <th style="width:110px;">الفرع</th>
                        <th>الوصف</th>
                        <th style="width:130px;">مين</th>
                        <th style="width:75px;text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feed)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-box">
                                    <i class="ti ti-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                                    لا توجد حركات مسجلة في هذه الفترة بالفلاتر المحددة.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feed as $item): ?>
                            <tr class="activity-row">
                                <td style="font-size:12px;color:var(--ink-500);white-space:nowrap;" dir="ltr">
                                    <?= e(date('m/d H:i', strtotime($item['time']))) ?>
                                </td>
                                <td>
                                    <span class="fchip <?= e($item['badge_class']) ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;">
                                        <i class="ti <?= e($item['icon']) ?>"></i> <?= e($item['type_label']) ?>
                                    </span>
                                </td>
                                <td style="font-weight:600;font-size:12.5px;">
                                    <?= e($item['branch']) ?>
                                </td>
                                <td style="font-size:13px;line-height:1.4;">
                                    <?= e($item['desc']) ?>
                                </td>
                                <td style="font-size:12px;color:var(--ink-600);">
                                    <?= e($item['actor']) ?>
                                </td>
                                <td style="text-align:center;">
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

</div>

<script>
function toggleCustomDates(val) {
    var el = document.getElementById('customDatesWrap');
    if (el) el.style.display = (val === 'custom') ? 'flex' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
