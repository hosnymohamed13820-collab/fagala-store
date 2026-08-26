<?php
/**
 * hr.php — الموارد البشرية (للإدارة)
 * نظام الفجالة ستور المالي
 *
 * تبويبات: نظرة عامة | السلف | الجزاءات | الإجازات | الرواتب | طلبات التصحيح
 * - الرواتب بتصرف من الخزينة وبتخصم السلف والجزاءات وعجز العهدة.
 * - طلبات تصحيح الحضور تتعتمد/ترفض هنا.
 */
require_once __DIR__ . '/auth.php';
require_permission('hr');
require_once __DIR__ . '/finance.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = $_GET['tab'] ?? 'employees';

$payTreasuries = $pdo->query(
    "SELECT id, name FROM treasuries WHERE branch_id IS NULL AND is_active=1
     ORDER BY FIELD(type,'cash','main','visa','instapay','card')"
)->fetchAll();
$employees = $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_penalty') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $date = $_POST['date'] ?? date('Y-m-d');
                $reason = trim($_POST['reason'] ?? '');
                if ($empId<=0 || $amount<=0) throw new RuntimeException('بيانات غير مكتملة.');
                $pdo->prepare('INSERT INTO employee_penalties (employee_id, amount, penalty_date, reason, status) VALUES (?,?,?,?,"pending")')
                    ->execute([$empId,$amount,$date,$reason ?: null]);
                $flash = 'تم تسجيل الجزاء.'; $tab='penalties';
            }
            elseif ($action === 'add_leave') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $start = $_POST['start_date'] ?? date('Y-m-d');
                $end   = $_POST['end_date'] ?? $start;
                $type  = $_POST['leave_type'] ?? 'annual';
                $days  = max(1, (int)((strtotime($end)-strtotime($start))/86400)+1);
                if ($empId<=0) throw new RuntimeException('اختر الموظف.');
                $pdo->prepare('INSERT INTO employee_leaves (employee_id, start_date, end_date, leave_type, days) VALUES (?,?,?,?,?)')
                    ->execute([$empId,$start,$end,$type,$days]);
                $flash = 'تم تسجيل الإجازة.'; $tab='leaves';
            }
            elseif ($action === 'pay_salary') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $month = $_POST['salary_month'] ?? date('Y-m');
                $treasuryId = (int)($_POST['treasury_id'] ?? 0);
                $date = $_POST['date'] ?? date('Y-m-d');
                pay_salary($empId, $month, $treasuryId, $date, $uid);
                $flash = 'تم صرف المرتب وخصم المستحقات.'; $tab='salaries';
            }
            elseif ($action === 'pay_salary_batch') {
                $empIds = $_POST['employee_ids'] ?? [];
                if (!is_array($empIds) || empty($empIds)) {
                    throw new RuntimeException('اختر موظفاً واحداً على الأقل.');
                }
                $month = trim((string)($_POST['salary_month'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    throw new RuntimeException('اختر شهراً صحيحاً.');
                }
                $treasuryId = (int)($_POST['treasury_id'] ?? 0);
                if ($treasuryId <= 0) {
                    throw new RuntimeException('اختر خزينة الصرف.');
                }
                $date = trim((string)($_POST['date'] ?? ''));
                if (!strtotime($date)) {
                    $date = date('Y-m-d');
                }

                $empNamesStmt = $pdo->query('SELECT id, full_name FROM employees');
                $empNames = $empNamesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $paidCount = 0;
                $fails = [];
                foreach ($empIds as $rawId) {
                    $empId = (int) $rawId;
                    if ($empId <= 0) continue;
                    $name = $empNames[$empId] ?? ('موظف #' . $empId);
                    try {
                        pay_salary($empId, $month, $treasuryId, $date, $uid);
                        $paidCount++;
                    } catch (Throwable $ex) {
                        $fails[] = $name . ' (' . $ex->getMessage() . ')';
                    }
                }

                if ($paidCount > 0 && empty($fails)) {
                    $flash = "تم صرف مرتبات $paidCount موظف بنجاح لشهر $month.";
                    $flashType = 'success';
                } elseif ($paidCount > 0 && !empty($fails)) {
                    $flash = "تم صرف $paidCount مرتب بنجاح. وتعذر صرف " . count($fails) . " مرتب: " . implode(' · ', $fails);
                    $flashType = 'error';
                } else {
                    $flash = "لم يتم صرف أي مرتب. تعذر الصرف لـ: " . implode(' · ', $fails);
                    $flashType = 'error';
                }
                $tab = 'salaries';
            }
            elseif ($action === 'approve_request') {
                approve_attendance_request((int)($_POST['request_id'] ?? 0), $uid);
                $flash = 'تم اعتماد الطلب وتطبيقه على الحضور.'; $tab='requests';
            }
            elseif ($action === 'update_request_time') {
                if (!can_edit_attendance_time()) {
                    throw new RuntimeException('غير مصرّح لك بتعديل أوقات طلبات التصحيح.');
                }
                $rid = (int)($_POST['request_id'] ?? 0);
                $in  = trim($_POST['requested_in'] ?? '');
                $out = trim($_POST['requested_out'] ?? '');
                if ($in === '' && $out === '') {
                    throw new RuntimeException('أدخل وقت الدخول أو الخروج.');
                }
                $chk = $pdo->prepare('SELECT id FROM attendance_requests WHERE id = ? AND status = "pending"');
                $chk->execute([$rid]);
                if (!$chk->fetch()) {
                    throw new RuntimeException('الطلب غير متاح للتعديل.');
                }
                $pdo->prepare('UPDATE attendance_requests SET requested_in = ?, requested_out = ? WHERE id = ? AND status = "pending"')
                    ->execute([$in !== '' ? $in : null, $out !== '' ? $out : null, $rid]);
                $flash = 'تم تعديل أوقات الطلب بنجاح.'; $tab='requests';
            }
            elseif ($action === 'reject_request') {
                $rid = (int)($_POST['request_id'] ?? 0);
                $pdo->prepare('UPDATE attendance_requests SET status="rejected", reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status="pending"')
                    ->execute([$uid,$rid]);
                $flash = 'تم رفض الطلب.'; $tab='requests';
            }
            elseif ($action === 'delete_attendance') {
                delete_attendance((int)($_POST['attendance_id'] ?? 0));
                $flash = 'تم مسح سجل الحضور لليوم ده. يقدر الموظف يسجّل من جديد.'; $tab='attendance';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage(); $flashType='error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ('hr.php?tab=' . urlencode($tab)));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// بيانات التبويبات
$empList = $pdo->query(
    "SELECT e.*, b.name AS branch_name,
            COALESCE((SELECT SUM(amount) FROM employee_advances WHERE employee_id=e.id AND status='pending'),0) AS pending_advance
     FROM employees e
     LEFT JOIN branches b ON b.id=e.branch_id WHERE e.is_active=1 ORDER BY e.full_name"
)->fetchAll();
$advances = $pdo->query(
    "SELECT a.*, e.full_name FROM employee_advances a JOIN employees e ON e.id=a.employee_id ORDER BY a.id DESC LIMIT 100"
)->fetchAll();
$penalties = $pdo->query(
    "SELECT p.*, e.full_name FROM employee_penalties p JOIN employees e ON e.id=p.employee_id ORDER BY p.id DESC LIMIT 100"
)->fetchAll();
$leaves = $pdo->query(
    "SELECT l.*, e.full_name FROM employee_leaves l JOIN employees e ON e.id=l.employee_id ORDER BY l.id DESC LIMIT 100"
)->fetchAll();
$salaries = $pdo->query(
    "SELECT s.*, e.full_name FROM salaries s JOIN employees e ON e.id=s.employee_id ORDER BY s.id DESC LIMIT 100"
)->fetchAll();
$requests = $pdo->query(
    "SELECT r.*, e.full_name FROM attendance_requests r JOIN employees e ON e.id=r.employee_id ORDER BY r.status='pending' DESC, r.id DESC LIMIT 100"
)->fetchAll();
$pendingReqCount = 0;
foreach ($requests as $r) if ($r['status']==='pending') $pendingReqCount++;

// بيانات الحضور للشهر المختار (تبويب الحضور)
$attMonth = $_GET['month'] ?? date('Y-m');
$attRows = [];
if ($tab === 'attendance') {
    $st = $pdo->prepare(
        "SELECT a.*, e.full_name, e.expected_in, e.expected_out
         FROM attendance a JOIN employees e ON e.id=a.employee_id
         WHERE DATE_FORMAT(a.att_date,'%Y-%m') = ?
         ORDER BY a.att_date DESC, e.full_name"
    );
    $st->execute([$attMonth]);
    $attRows = $st->fetchAll();
}

$page_title    = 'الموارد البشرية';
$active_module = 'hr';
require_once __DIR__ . '/includes/header.php';

$leaveLabels = ['annual'=>'سنوية','sick'=>'مرضية','unpaid'=>'بدون راتب','other'=>'أخرى'];
$statusBadge = function($st) {
    $m = ['pending'=>['معلّق','var(--warn)','var(--warn-soft)'],'deducted'=>['مخصوم','var(--pos)','var(--pos-soft)'],
          'approved'=>['معتمد','var(--pos)','var(--pos-soft)'],'rejected'=>['مرفوض','var(--neg)','var(--neg-soft)'],
          'paid'=>['مدفوع','var(--pos)','var(--pos-soft)'],'draft'=>['مسودة','#666','#eee'],
          'recovered'=>['مسترد','var(--pos)','var(--pos-soft)']];
    return $m[$st] ?? [$st,'#666','#eee'];
};
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab { padding:9px 16px; border-radius:10px; text-decoration:none; color:var(--accent-hover); background:#fff;
        font-size:14px; font-weight:500; position:relative; }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .tab .count { background:var(--neg); color:#fff; border-radius:20px; padding:1px 7px; font-size:11px; margin-right:4px; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; }
    .btn-approve { background:var(--pos); } .btn-reject { background:var(--neg); }
    .btn-ghost { background:var(--bg); color:var(--accent-hover); }
    .btn-cancel { background:var(--line-soft); color:#666; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .empty { text-align:center; color:#bbb; padding:30px; font-size:14px; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tabs">
    <a href="?tab=employees" class="tab <?= $tab==='employees'?'active':'' ?>">الموظفون</a>
    <a href="?tab=advances"  class="tab <?= $tab==='advances'?'active':'' ?>">السلف</a>
    <a href="?tab=penalties" class="tab <?= $tab==='penalties'?'active':'' ?>">الجزاءات</a>
    <a href="?tab=leaves"    class="tab <?= $tab==='leaves'?'active':'' ?>">الإجازات</a>
    <a href="?tab=salaries"  class="tab <?= $tab==='salaries'?'active':'' ?>">الرواتب</a>
    <a href="?tab=attendance" class="tab <?= $tab==='attendance'?'active':'' ?>">الحضور والانصراف</a>
    <a href="?tab=requests"  class="tab <?= $tab==='requests'?'active':'' ?>">
        طلبات التصحيح<?php if ($pendingReqCount): ?><span class="count"><?= $pendingReqCount ?></span><?php endif; ?>
    </a>
</div>

<?php if ($tab === 'employees'): ?>
    <div class="card">
        <div class="section-title">الموظفون (<?= count($empList) ?>) — التعديل من التعريفات</div>
        <?php if (empty($empList)): ?>
            <div class="empty">لا يوجد موظفون. أضفهم من التعريفات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الاسم</th><th>الفرع</th><th>الوظيفة</th><th>الراتب</th><th>السلفة المعلّقة</th><th>الحضور المتوقع</th><th>الانصراف</th></tr></thead>
            <tbody>
            <?php foreach ($empList as $e2): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($e2['full_name']) ?></td>
                    <td><?= e($e2['branch_name'] ?? '—') ?></td>
                    <td><?= e($e2['job_title'] ?? '—') ?></td>
                    <td><?= number_format((float)$e2['base_salary'],2) ?></td>
                    <td><?= (float)$e2['pending_advance'] > 0
                            ? '<span style="color:var(--neg);font-weight:600;">'.number_format((float)$e2['pending_advance'],2).'</span>'
                            : '<span style="color:var(--ink-500);">0.00</span>' ?></td>
                    <td><?= e($e2['expected_in'] ? substr($e2['expected_in'],0,5) : '—') ?></td>
                    <td><?= e($e2['expected_out'] ? substr($e2['expected_out'],0,5) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'advances'): ?>
    <div class="card">
        <div class="section-title">السلف (تُصرف من الخزينة)</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">السلفة تُصرف من موديول الخزينة (زر صرف ← سلفة لموظف).</p>
        <?php if (empty($advances)): ?>
            <div class="empty">لا توجد سلف.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>المبلغ</th><th>الحالة</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($advances as $a): $b=$statusBadge($a['status']); ?>
                <tr>
                    <td><?= e($a['advance_date']) ?></td>
                    <td style="font-weight:500;"><?= e($a['full_name']) ?></td>
                    <td><?= number_format((float)$a['amount'],2) ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                    <td style="font-size:13px;color:var(--ink-500);"><?= e($a['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'penalties'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($penalties) ?> جزاء</span>
        <button class="btn" onclick="document.getElementById('penaltyModal').classList.add('open')"><i class="ti ti-plus"></i> إضافة جزاء</button>
    </div>
    <div class="card">
        <?php if (empty($penalties)): ?>
            <div class="empty">لا توجد جزاءات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>المبلغ</th><th>السبب</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php foreach ($penalties as $p): $b=$statusBadge($p['status']); ?>
                <tr>
                    <td><?= e($p['penalty_date']) ?></td>
                    <td style="font-weight:500;"><?= e($p['full_name']) ?></td>
                    <td><?= number_format((float)$p['amount'],2) ?></td>
                    <td><?= e($p['reason'] ?? '—') ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'leaves'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($leaves) ?> إجازة</span>
        <button class="btn" onclick="document.getElementById('leaveModal').classList.add('open')"><i class="ti ti-plus"></i> إضافة إجازة</button>
    </div>
    <div class="card">
        <?php if (empty($leaves)): ?>
            <div class="empty">لا توجد إجازات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الموظف</th><th>من</th><th>إلى</th><th>النوع</th><th>الأيام</th></tr></thead>
            <tbody>
            <?php foreach ($leaves as $l): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($l['full_name']) ?></td>
                    <td><?= e($l['start_date']) ?></td>
                    <td><?= e($l['end_date']) ?></td>
                    <td><?= e($leaveLabels[$l['leave_type']] ?? $l['leave_type']) ?></td>
                    <td><?= (int)$l['days'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'salaries'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($salaries) ?> مرتب</span>
        <div style="display:flex;gap:8px;">
            <button class="btn" onclick="document.getElementById('batchSalaryModal').classList.add('open')"><i class="ti ti-users"></i> صرف لمجموعة</button>
            <button class="btn btn-ghost" onclick="document.getElementById('salaryModal').classList.add('open')"><i class="ti ti-cash"></i> صرف فردي</button>
        </div>
    </div>
    <div class="card">
        <?php if (empty($salaries)): ?>
            <div class="empty">لا توجد رواتب مصروفة.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الشهر</th><th>الموظف</th><th>الأساسي</th><th>سلف</th><th>جزاءات</th><th>عجز عهدة</th><th>الصافي</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php foreach ($salaries as $s): $b=$statusBadge($s['status']); ?>
                <tr>
                    <td><?= e($s['salary_month']) ?></td>
                    <td style="font-weight:500;"><?= e($s['full_name']) ?></td>
                    <td><?= number_format((float)$s['base_salary'],2) ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_advances'],2) ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_penalties'],2) ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_deficits'],2) ?></td>
                    <td style="font-weight:700;color:var(--pos);"><?= number_format((float)$s['net_salary'],2) ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'requests'): ?>
    <div class="card">
        <div class="section-title">طلبات تصحيح الحضور</div>
        <?php if (empty($requests)): ?>
            <div class="empty">لا توجد طلبات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الموظف</th><th>اليوم</th><th>دخول مطلوب</th><th>خروج مطلوب</th><th>السبب</th><th>الحالة</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): $b=$statusBadge($r['status']); ?>
                <tr>
                    <td style="font-weight:500;"><?= e($r['full_name']) ?></td>
                    <td><?= e($r['req_date']) ?></td>
                    <td><?= e($r['requested_in'] ? substr($r['requested_in'],0,5) : '—') ?></td>
                    <td><?= e($r['requested_out'] ? substr($r['requested_out'],0,5) : '—') ?></td>
                    <td style="font-size:13px;"><?= e($r['reason'] ?? '—') ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                    <td>
                        <?php if ($r['status']==='pending'): ?>
                            <?php if (can_edit_attendance_time()): ?>
                                <button type="button" class="btn btn-sm btn-ghost btn-edit-req"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-in="<?= e($r['requested_in'] ? substr($r['requested_in'],0,5) : '') ?>"
                                        data-out="<?= e($r['requested_out'] ? substr($r['requested_out'],0,5) : '') ?>"
                                        data-name="<?= e($r['full_name']) ?>"
                                        data-date="<?= e($r['req_date']) ?>"
                                        title="تعديل الأوقات"><i class="ti ti-edit"></i> تعديل</button>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="approve_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-approve">اعتماد</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reject_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-reject">رفض</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php else: // attendance ?>
    <div class="card">
        <div class="section-title">سجل الحضور والانصراف</div>
        <form method="get" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="tab" value="attendance">
            <label style="font-size:13px;color:var(--ink-500);">الشهر:</label>
            <input type="month" name="month" value="<?= e($attMonth) ?>" onchange="this.form.submit()"
                   style="padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
        </form>
        <?php if (empty($attRows)): ?>
            <div class="empty">لا يوجد سجل حضور لهذا الشهر.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>الحضور</th><th>الانصراف</th><th>ساعات العمل</th><th>مكان البصمة</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php
                $statusMap = ['present'=>['حاضر','var(--pos)','var(--pos-soft)'],'absent'=>['غائب','var(--neg)','var(--neg-soft)'],
                              'leave'=>['إجازة','var(--warn)','var(--warn-soft)'],'holiday'=>['عطلة','#666','#eee']];
                // خلية مكان البصمة: رابط خريطة + تنبيه لو برّة النطاق
                $locCell = function($lat, $lng, $dist, $outside, $label) {
                    if ($lat === null || $lng === null || $lat === '' || $lng === '') return '';
                    $url = 'https://www.google.com/maps?q=' . $lat . ',' . $lng;
                    $color = $outside ? 'var(--neg)' : 'var(--pos)';
                    $txt = $outside ? ('⚠ برّة (' . (int)$dist . 'م)') : '✓ داخل النطاق';
                    return '<a href="' . $url . '" target="_blank" style="color:' . $color . ';font-size:12px;text-decoration:none;white-space:nowrap;" title="افتح على الخريطة">'
                         . $label . ' ' . $txt . '</a>';
                };
                foreach ($attRows as $a):
                    $sb = $statusMap[$a['status']] ?? ['—','#666','#eee'];
                    $inLoc  = $locCell($a['check_in_lat'] ?? null,  $a['check_in_lng'] ?? null,  $a['check_in_dist'] ?? null,  !empty($a['check_in_outside']),  'دخول:');
                    $outLoc = $locCell($a['check_out_lat'] ?? null, $a['check_out_lng'] ?? null, $a['check_out_dist'] ?? null, !empty($a['check_out_outside']), 'خروج:');
                    $rowFlagged = !empty($a['check_in_outside']) || !empty($a['check_out_outside']);
            ?>
                <tr<?= $rowFlagged ? ' style="background:#fff6f6;"' : '' ?>>
                    <td><?= e($a['att_date']) ?></td>
                    <td style="font-weight:500;"><?= e($a['full_name']) ?></td>
                    <td><?= e($a['check_in'] ? substr($a['check_in'],0,5) : '—') ?></td>
                    <td><?= e($a['check_out'] ? substr($a['check_out'],0,5) : '—') ?></td>
                    <td><?= number_format((float)$a['work_hours'],2) ?></td>
                    <td>
                        <?php if ($inLoc || $outLoc): ?>
                            <div style="display:flex;flex-direction:column;gap:2px;"><?= $inLoc ?><?= $outLoc ?></div>
                        <?php else: ?>
                            <span style="color:var(--ink-300);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="color:<?=$sb[1]?>;background:<?=$sb[2]?>;"><?=e($sb[0])?></span></td>
                    <td>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('تأكيد مسح سجل حضور <?= e($a['full_name']) ?> يوم <?= e($a['att_date']) ?>؟ هيقدر يسجّل من جديد.')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_attendance">
                            <input type="hidden" name="attendance_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-reject" title="مسح سجل اليوم"><i class="ti ti-trash"></i> مسح</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Modals -->
<div class="modal-bg" id="penaltyModal">
    <div class="modal">
        <h3>إضافة جزاء</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_penalty">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>السبب</label><input type="text" name="reason" aria-label="السبب"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('penaltyModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="leaveModal">
    <div class="modal">
        <h3>إضافة إجازة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_leave">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>من</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>إلى</label><input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>النوع</label><select name="leave_type">
                <option value="annual">سنوية</option><option value="sick">مرضية</option>
                <option value="unpaid">بدون راتب</option><option value="other">أخرى</option>
            </select></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('leaveModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="salaryModal">
    <div class="modal">
        <h3>صرف مرتب</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay_salary">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>الشهر</label><input type="month" name="salary_month" value="<?= date('Y-m') ?>" required></div>
            <div class="form-row"><label>خزينة الصرف</label><select name="treasury_id" required>
                <?php foreach ($payTreasuries as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <p style="font-size:12px;color:var(--ink-500);">سيتم خصم السلف والجزاءات وعجز العهدة المعلّقة تلقائياً.</p>
            <div class="modal-actions">
                <button type="submit" class="btn">صرف المرتب</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('salaryModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="batchSalaryModal">
    <div class="modal" style="max-width:500px;">
        <h3>صرف رواتب مجموعة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay_salary_batch">
            <div class="form-row">
                <label>الشهر</label>
                <input type="month" name="salary_month" value="<?= date('Y-m') ?>" required>
            </div>
            <div class="form-row">
                <label>خزينة الصرف</label>
                <select name="treasury_id" required>
                    <option value="">— اختر الخزينة —</option>
                    <?php foreach ($payTreasuries as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>تاريخ الصرف</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-row">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="margin:0;font-weight:600;">الموظفون (<?= count($empList) ?>)</label>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-sm btn-ghost" id="btnBatchSelectAll" style="padding:2px 8px;font-size:12px;">تحديد الكل</button>
                        <button type="button" class="btn btn-sm btn-ghost" id="btnBatchDeselectAll" style="padding:2px 8px;font-size:12px;">إلغاء تحديد الكل</button>
                    </div>
                </div>
                <div style="max-height:200px;overflow-y:auto;border:1.5px solid var(--line);border-radius:9px;padding:8px 12px;background:var(--line-soft);">
                    <?php foreach ($empList as $emp): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="employee_ids[]" class="batch-emp-cb" value="<?= (int)$emp['id'] ?>" style="width:auto;margin:0;">
                            <span>
                                <b><?= e($emp['full_name']) ?></b>
                                <?php if (!empty($emp['branch_name'])): ?>
                                    <span style="color:var(--ink-500);font-size:12px;"> (<?= e($emp['branch_name']) ?>)</span>
                                <?php endif; ?>
                                <span style="color:var(--ink-500);font-size:12px;"> — راتب <?= number_format((float)$emp['base_salary'], 2) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <p style="font-size:12px;color:var(--ink-500);">سيتم صرف المرتبات للموظفين المحددين وخصم السلف والجزاءات وعجز العهدة تلقائياً لكل موظف.</p>
            <div class="modal-actions">
                <button type="submit" class="btn"><i class="ti ti-check"></i> صرف الرواتب المحددة</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('batchSalaryModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?php if (can_edit_attendance_time()): ?>
<div class="modal-bg" id="editRequestModal">
    <div class="modal">
        <h3>تعديل وقت طلب التصحيح</h3>
        <p style="font-size:13px;color:var(--ink-500);margin-bottom:14px;">
            الموظف: <strong id="editReqEmpName" style="color:var(--ink-900);"></strong> · التاريخ: <strong id="editReqDate" style="color:var(--ink-900);"></strong>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_request_time">
            <input type="hidden" name="request_id" id="editReqId" value="">
            <div class="form-row">
                <label>وقت الحضور المطلوب</label>
                <input type="time" name="requested_in" id="editReqIn">
            </div>
            <div class="form-row">
                <label>وقت الانصراف المطلوب</label>
                <input type="time" name="requested_out" id="editReqOut">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ التعديل</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('editRequestModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-req');
        if (!btn) return;
        var modal = document.getElementById('editRequestModal');
        if (!modal) return;
        document.getElementById('editReqId').value = btn.dataset.id;
        document.getElementById('editReqIn').value = btn.dataset.in || '';
        document.getElementById('editReqOut').value = btn.dataset.out || '';
        document.getElementById('editReqEmpName').textContent = btn.dataset.name;
        document.getElementById('editReqDate').textContent = btn.dataset.date;
        modal.classList.add('open');
    });
    var btnBatchAll = document.getElementById('btnBatchSelectAll');
    var btnBatchNone = document.getElementById('btnBatchDeselectAll');
    if (btnBatchAll) {
        btnBatchAll.addEventListener('click', function() {
            document.querySelectorAll('.batch-emp-cb').forEach(function(cb) { cb.checked = true; });
        });
    }
    if (btnBatchNone) {
        btnBatchNone.addEventListener('click', function() {
            document.querySelectorAll('.batch-emp-cb').forEach(function(cb) { cb.checked = false; });
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
