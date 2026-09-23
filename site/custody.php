<?php
/**
 * custody.php — العهد (عهدة الموظف / عهدة الفرع)
 * نظام الفجالة ستور المالي
 *
 * - عهدة الموظف: متاحة للجميع بصلاحية custody (أو custody:employee).
 * - عهدة الفرع: متاحة بصلاحية خاصة custody:branch.
 * - كل الحركات المالية تمر من finance.php.
 */
require_once __DIR__ . '/auth.php';
require_module_access('custody');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$u    = current_user();
$uid  = (int) $u['id'];
$entryCodes = entry_codes_list(true); // حسابات العهدة بس (is_custody=1)
$flash = ''; $flashType = 'success';

// صلاحيات العهد
$canBranchCustody   = is_owner() || can('custody:branch');
$canEmployeeCustody = is_owner() || can('custody:employee');

// لو المستخدم مش معاه أي صلاحية للعهد
if (!$canBranchCustody && !$canEmployeeCustody) {
    require_permission('custody'); // 403
}

// صلاحية إصدار إذون الصرف: الأونر أو من له صلاحية treasury (المحاسب)
$canIssue = is_owner() || can('treasury:statement');

// تحديد التاب النشط:
// عهدة الموظف هي الافتراضية، وعهدة الفرع لمن لديه الصلاحية فقط
$defaultTab = $canEmployeeCustody ? 'employee' : 'branch';
$activeTab = $_GET['tab'] ?? $defaultTab;
if ($activeTab === 'branch' && !$canBranchCustody) {
    $activeTab = 'employee';
}
if ($activeTab === 'employee' && !$canEmployeeCustody) {
    $activeTab = 'branch';
}

// تحديد الفرع المعروض لعهدة الفرع:
$allBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();
$branchId = null;
if (!empty($u['branch_id'])) {
    $branchId = (int) $u['branch_id'];
} elseif (isset($_GET['branch'])) {
    $branchId = (int) $_GET['branch'];
} elseif ($allBranches) {
    $branchId = (int) $allBranches[0]['id'];
}

// الموظف المختار لعهدة الموظف
$allEmployees = $pdo->query('SELECT id, full_name, job_title, branch_id FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();

// جلب موظف المستخدم الحالي (إن وُجد)
$uEmpStmt = $pdo->prepare("SELECT id, full_name FROM employees WHERE user_id = ? AND is_active = 1 LIMIT 1");
$uEmpStmt->execute([$uid]);
$curUserEmp = $uEmpStmt->fetch();
$currentUserEmpId = (int) ($curUserEmp['id'] ?? 0);

// الموظف العادي لا يرى إلا عهدته هو فقط — الأونر فقط يقدر يختار أي موظف
if (!is_owner()) {
    $selectedEmpId = $currentUserEmpId;
} else {
    $selectedEmpId = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
    if ($selectedEmpId <= 0) {
        if ($currentUserEmpId > 0) {
            $selectedEmpId = $currentUserEmpId;
        } elseif (!empty($allEmployees)) {
            $selectedEmpId = (int) $allEmployees[0]['id'];
        }
    }
}

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'issue_order' && $canIssue && $canBranchCustody) {
                $bId    = (int) ($_POST['branch_id'] ?? 0);
                $amount = (float) ($_POST['amount'] ?? 0);
                $date   = $_POST['date'] ?? date('Y-m-d');
                $notes  = trim($_POST['notes'] ?? '');
                // خزينة عهدة الفرع
                $ct = $pdo->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
                $ct->execute([$bId]);
                $ctId = (int) $ct->fetchColumn();
                if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');
                issue_disbursement_order($bId, $ctId, $amount, $date, $notes ?: null, $uid);
                $flash = 'تم إصدار إذن الصرف وتزويد عهدة الفرع.';
                $branchId = $bId;
                $prgTarget = 'custody.php?tab=branch&branch=' . $bId;
            }
            elseif ($action === 'add_expense') {
                $custodyId = (int) ($_POST['custody_id'] ?? 0);
                $date      = $_POST['date'] ?? date('Y-m-d');
                $desc      = trim($_POST['description'] ?? '');
                $amount    = (float) ($_POST['amount'] ?? 0);
                $entryCodeId = ($_POST['entry_code_id'] ?? '') !== '' ? (int) $_POST['entry_code_id'] : null;
                if ($desc === '') throw new RuntimeException('بيان المصروف مطلوب.');

                $stC = $pdo->prepare("SELECT branch_id, employee_id FROM branch_custody WHERE id=?");
                $stC->execute([$custodyId]);
                $cRow = $stC->fetch();
                if (!$cRow) throw new RuntimeException('العهدة غير موجودة.');
                if (!empty($cRow['employee_id']) && !$canEmployeeCustody) {
                    throw new RuntimeException('غير مصرح لك بتسجيل مصروف على عهدة الموظف.');
                }
                if (!empty($cRow['branch_id']) && empty($cRow['employee_id']) && !$canBranchCustody) {
                    throw new RuntimeException('غير مصرح لك بتسجيل مصروف على عهدة الفرع.');
                }

                add_custody_expense($custodyId, $date, $desc, $amount, $uid, $entryCodeId);
                $flash = 'تم تسجيل المصروف.';
                if (!empty($cRow['employee_id'])) {
                    $prgTarget = 'custody.php?tab=employee&emp=' . (int)$cRow['employee_id'];
                } elseif (!empty($cRow['branch_id'])) {
                    $prgTarget = 'custody.php?tab=branch&branch=' . (int)$cRow['branch_id'];
                }
            }
            elseif ($action === 'delete_expense') {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                if ($lineId <= 0) throw new RuntimeException('مصروف غير محدد.');

                $stC = $pdo->prepare("SELECT bc.branch_id, bc.employee_id FROM custody_lines cl JOIN branch_custody bc ON bc.id = cl.custody_id WHERE cl.id=?");
                $stC->execute([$lineId]);
                $cRow = $stC->fetch();
                if (!$cRow) throw new RuntimeException('المصروف غير موجود.');
                if (!empty($cRow['employee_id']) && !$canEmployeeCustody) {
                    throw new RuntimeException('غير مصرح لك بإلغاء مصروف عهدة الموظف.');
                }
                if (!empty($cRow['branch_id']) && empty($cRow['employee_id']) && !$canBranchCustody) {
                    throw new RuntimeException('غير مصرح لك بإلغاء مصروف عهدة الفرع.');
                }

                delete_custody_expense($lineId, $uid);
                $flash = 'تم إلغاء المصروف ورجّعنا المبلغ لخزينة العهدة.';
                if (!empty($cRow['employee_id'])) {
                    $prgTarget = 'custody.php?tab=employee&emp=' . (int)$cRow['employee_id'];
                } elseif (!empty($cRow['branch_id'])) {
                    $prgTarget = 'custody.php?tab=branch&branch=' . (int)$cRow['branch_id'];
                }
            }
            elseif ($action === 'close_custody') {
                $custodyId  = (int) ($_POST['custody_id'] ?? 0);
                $counted    = (float) ($_POST['counted_cash'] ?? 0);
                $date       = $_POST['date'] ?? date('Y-m-d');
                $employeeId = ($_POST['employee_id'] ?? '') !== '' ? (int) $_POST['employee_id'] : null;

                $stC = $pdo->prepare("SELECT branch_id, employee_id FROM branch_custody WHERE id=?");
                $stC->execute([$custodyId]);
                $cRow = $stC->fetch();
                if (!$cRow) throw new RuntimeException('العهدة غير موجودة.');
                if (!empty($cRow['employee_id']) && !$canEmployeeCustody) {
                    throw new RuntimeException('غير مصرح لك بإقفال عهدة الموظف.');
                }
                if (!empty($cRow['branch_id']) && empty($cRow['employee_id']) && !$canBranchCustody) {
                    throw new RuntimeException('غير مصرح لك بإقفال عهدة الفرع.');
                }

                $res = submit_custody_count($custodyId, $counted, $date, $employeeId, $uid);
                $diff = $res['difference'];
                if ($diff < 0) {
                    $flash = 'تم إقفال العهدة وإرسالها لمراجعة المحاسب (عجز متوقع: ' . number_format(abs($diff),2) . ' ج). الفلوس هتدخل الخزينة بعد الاعتماد.';
                } elseif ($diff > 0) {
                    $flash = 'تم إقفال العهدة وإرسالها لمراجعة المحاسب (زيادة متوقعة: ' . number_format($diff,2) . ' ج). الفلوس هتدخل الخزينة بعد الاعتماد.';
                } else {
                    $flash = 'تم إقفال العهدة وإرسالها لمراجعة المحاسب. الفلوس هتدخل الخزينة بعد الاعتماد.';
                }
                if (!empty($cRow['employee_id'])) {
                    $prgTarget = 'custody.php?tab=employee&emp=' . (int)$cRow['employee_id'];
                } elseif (!empty($cRow['branch_id'])) {
                    $prgTarget = 'custody.php?tab=branch&branch=' . (int)$cRow['branch_id'];
                }
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($prgTarget ?? ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'])));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== جلب بيانات الفرع والعهدة =====
$branchName = '';
$custody = null; $expenses = []; $orders = [];
if ($branchId) {
    foreach ($allBranches as $b) if ((int)$b['id'] === $branchId) $branchName = $b['name'];
    $custody = current_custody_for_branch($branchId);
    if ($custody) {
        $cid = (int) $custody['id'];
        $e = $pdo->prepare(
            'SELECT cl.*, ec.name AS entry_code_name
             FROM custody_lines cl
             LEFT JOIN accounts ec ON ec.id = cl.entry_code_id
             WHERE cl.custody_id=? ORDER BY cl.id DESC'
        );
        $e->execute([$cid]);
        $expenses = $e->fetchAll();
        $o = $pdo->prepare('SELECT * FROM disbursement_orders WHERE branch_id=? AND order_date >= ? ORDER BY id DESC');
        $o->execute([$branchId, $custody['period_start']]);
        $orders = $o->fetchAll();
    }
}

// موظفو الفرع (لاختيار المسؤول عن العجز)
$branchEmployees = [];
if ($branchId) {
    $em = $pdo->prepare('SELECT id, full_name FROM employees WHERE branch_id=? AND is_active=1 ORDER BY full_name');
    $em->execute([$branchId]);
    $branchEmployees = $em->fetchAll();
}

$denominations = [200, 100, 50, 20, 10, 5, 1];
$tolerance = custody_tolerance();

// حالة العهدة بتحكم الشاشة كلها:
//  open/rejected → الكاشير شغّال: بيسجّل مصاريف ويعدّ ويقدّم.
//  pending_review → مقفولة عليه: مستنية المحاسب، والفلوس مادخلتش الخزينة لسه.
// 🔴 الإخفاء ده **مش** حماية — الحارس الحقيقي جوّه submit_custody_count()
//    و add_custody_expense() في finance.php. ده تنظيم شكل بس.
$custodyStatus = $custody['status'] ?? '';
$canEditCustody = in_array($custodyStatus, ['open', 'rejected'], true);
$isPendingReview = $custodyStatus === 'pending_review';

$page_title    = 'عهدة الفرع';
$active_module = 'custody';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .top-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .branch-select select { padding:10px 14px; border:1.5px solid var(--line); border-radius:10px;
        font-size:14px; font-family:inherit; background:var(--surface); }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
    .stat { background:var(--surface); border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .stat .label { font-size:13px; color:var(--ink-500); margin-bottom:6px; }
    .stat .value { font-size:24px; font-weight:700; color:var(--ink-900); }
    .stat.accent { background:var(--accent); color:#fff; }
    .stat.accent .label { color:rgba(255,255,255,0.85); } .stat.accent .value { color:#fff; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width:820px){ .grid2 { grid-template-columns:1fr; } }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .empty { text-align:center; color:var(--ink-400); padding:24px; font-size:14px; }
    .form-inline { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .form-inline .fld { flex:1; min-width:120px; }
    .form-inline label { display:block; font-size:12px; color:var(--ink-500); margin-bottom:4px; }
    .form-inline input, .form-inline select { width:100%; padding:9px 11px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    /* جرد الفئات */
    .denom-table input { width:90px; padding:7px 9px; border:1.5px solid var(--line); border-radius:8px;
        font-size:14px; text-align:center; font-family:inherit; }
    .denom-sub { font-weight:600; color:var(--ink-900); }
    .count-result { padding:14px; border-radius:10px; margin-top:14px; font-size:15px; font-weight:600; }
    .count-result.ok { background:var(--pos-soft); color:var(--pos); }
    .count-result.def { background:var(--neg-soft); color:var(--neg); }
    .count-result.sur { background:var(--warn-soft); color:var(--warn); }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    /* شريط حالة العهدة — لونه بيقول للكاشير هو في أنهي مرحلة */
    .status-bar { display:flex; gap:11px; align-items:flex-start; padding:14px 16px;
        border-radius:12px; margin-bottom:18px; font-size:14px; line-height:1.65; }
    .status-bar i { font-size:19px; flex-shrink:0; margin-top:1px; }
    .status-bar .sb-sub { display:block; font-size:13px; opacity:0.85; margin-top:2px; }
    .status-bar.pending  { background:var(--warn-soft); color:var(--warn); }
    .status-bar.rejected { background:var(--neg-soft);  color:var(--neg); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div style="display:flex;gap:8px;">
        <?php if ($canEmployeeCustody): ?>
        <a href="custody.php?tab=employee<?= $selectedEmpId ? ('&emp=' . (int)$selectedEmpId) : '' ?>" class="btn <?= $activeTab==='employee' ? '' : 'btn-cancel' ?>">
            <i class="ti ti-users"></i> عهدة موظف
        </a>
        <?php endif; ?>
        <?php if ($canBranchCustody): ?>
        <a href="custody.php?tab=branch<?= $branchId ? ('&branch=' . (int)$branchId) : '' ?>" class="btn <?= $activeTab==='branch' ? '' : 'btn-cancel' ?>">
            <i class="ti ti-building-store"></i> عهدة الفرع
        </a>
        <?php endif; ?>
    </div>
    <?php if ($canIssue && $activeTab === 'branch' && $branchId): ?>
        <button type="button" class="btn" onclick="openModal('orderModal')">
            <i class="ti ti-plus"></i> إصدار إذن صرف لفرع
        </button>
    <?php endif; ?>
</div>

<?php if ($activeTab === 'employee'): ?>
    <?php
    $selectedEmpName = '';
    $empCustody = null;
    $empExpenses = [];
    $empOrders = [];
    $empTrBal = 0.0;

    if ($selectedEmpId > 0) {
        foreach ($allEmployees as $eRow) {
            if ((int)$eRow['id'] === $selectedEmpId) {
                $selectedEmpName = $eRow['full_name'];
                break;
            }
        }
        $empCustody = current_custody_for_employee($selectedEmpId);
        if ($empCustody) {
            $stExp = $pdo->prepare(
                "SELECT cl.*, ec.name AS entry_code_name
                 FROM custody_lines cl
                 LEFT JOIN accounts ec ON ec.id = cl.entry_code_id
                 WHERE cl.custody_id = ?
                 ORDER BY cl.id DESC"
            );
            $stExp->execute([(int)$empCustody['id']]);
            $empExpenses = $stExp->fetchAll();

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
    ?>
    <?php if (is_owner()): ?>
    <div class="top-row">
        <div class="branch-select">
            <form method="get" style="display:inline;">
                <input type="hidden" name="tab" value="employee">
                <select name="emp" onchange="formSubmit(this.form)">
                    <option value="">— اختر الموظف —</option>
                    <?php foreach ($allEmployees as $em): ?>
                        <option value="<?= (int)$em['id'] ?>" <?= $selectedEmpId===(int)$em['id']?'selected':'' ?>>
                            <?= e($em['full_name']) ?><?= !empty($em['job_title']) ? ' (' . e($em['job_title']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($selectedEmpId <= 0): ?>
        <div class="card"><div class="empty">لا توجد عهدة مسجلة لحسابك حالياً.</div></div>
    <?php elseif (!$empCustody): ?>
        <div class="card">
            <div class="empty">
                <?php if (is_owner()): ?>
                    لا توجد عهدة مفتوحة للموظف <?= e($selectedEmpName) ?> حالياً.<br>
                    <span style="font-size:13px;color:var(--ink-400);margin-top:8px;display:inline-block;">
                        (لإصدار إذن صرف: اذهب إلى صفحة <b>الخزينة</b> واضغط زر «صرف» واختر «عهدة لموظف»)
                    </span>
                <?php else: ?>
                    لا توجد عهدة مفتوحة لك حالياً.
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php
            $empOpening = (float) $empCustody['opening_balance'];
            $empTotalExp = (float) $empCustody['total_expenses'];
            $empClosing = $empOpening - $empTotalExp;
            $empStatus = (string) $empCustody['status'];
            $isEmpPending = $empStatus === 'pending_review';
            $isEmpRej = $empStatus === 'rejected';
            $canEditThisEmpCustody = in_array($empStatus, ['open', 'rejected'], true);
        ?>
        <?php if ($isEmpPending): ?>
            <div class="status-bar pending">
                <i class="ti ti-clock"></i>
                <div>
                    <strong>مقدّمة لمراجعة المحاسب.</strong>
                    الفلوس <u>مادخلتش الخزينة لسه</u> — هتدخل أول ما المحاسب يعتمد ويتم عكس إذن الصرف ونزول المصاريف.
                    <?php if (!empty($empCustody['submitted_at'])): ?>
                        <span class="sb-sub">اتقدّمت <?= e($empCustody['submitted_at']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($isEmpRej): ?>
            <div class="status-bar rejected">
                <i class="ti ti-alert-triangle"></i>
                <div>
                    <strong>المحاسب رفض الإقفال:</strong> <?= e($empCustody['reject_reason'] ?? '—') ?>
                    <span class="sb-sub">ظبّط المصاريف أو العدّ وقدّم تاني.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="stats" style="display:flex;gap:14px;flex-wrap:wrap;">
            <div class="stat" style="flex:1;min-width:160px;">
                <div class="label">رصيد أول المدة</div>
                <div class="value"><?= number_format($empOpening,2) ?></div>
            </div>
            <div class="stat" style="flex:1;min-width:160px;">
                <div class="label">إجمالي المصروفات</div>
                <div class="value" style="color:var(--neg);"><?= number_format($empTotalExp,2) ?></div>
            </div>
            <div class="stat accent" style="flex:1;min-width:160px;">
                <div class="label">رصيد آخر المدة (المفروض)</div>
                <div class="value"><?= number_format($empClosing,2) ?></div>
            </div>
        </div>

        <div class="grid2">
            <!-- المصروفات -->
            <div class="card">
                <div class="section-title">المصروفات</div>
                <?php if ($canEditThisEmpCustody): ?>
                <form method="post" class="form-inline" style="margin-bottom:16px;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_expense">
                    <input type="hidden" name="custody_id" value="<?= (int)$empCustody['id'] ?>">
                    <div class="fld" style="max-width:150px;">
                        <label>القيد</label>
                        <?= acctree_field('entry_code_id', null, '— بدون —', 'custody') ?>
                    </div>
                    <div class="fld"><label>البيان</label><input type="text" name="description" required aria-label="البيان"></div>
                    <div class="fld" style="max-width:110px;"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
                    <div class="fld" style="max-width:140px;"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                    <button type="submit" class="btn btn-sm">إضافة</button>
                </form>
                <?php endif; ?>
                <?php if (empty($empExpenses)): ?>
                    <div class="empty">لا توجد مصروفات بعد.</div>
                <?php else: ?>
                <table>
                    <thead><tr><th>التاريخ</th><th>القيد</th><th>البيان</th><th>المبلغ</th><?php if ($canEditThisEmpCustody): ?><th style="width:52px;"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($empExpenses as $ex): ?>
                        <tr>
                            <td><?= e($ex['expense_date']) ?></td>
                            <td><?= !empty($ex['entry_code_name']) ? '<span style="padding:2px 9px;border-radius:20px;font-size:12px;color:var(--accent-text);background:var(--bg);">'.e($ex['entry_code_name']).'</span>' : '<span style="color:var(--ink-300);">—</span>' ?></td>
                            <td><?= e($ex['description']) ?></td>
                            <td style="font-weight:500;"><?= money_fmt((float)$ex['amount']) ?></td>
                            <?php if ($canEditThisEmpCustody): ?>
                            <td>
                                <form method="post" style="margin:0;"
                                      onsubmit="return confirm('إلغاء المصروف ده؟\n\n<?= e(addslashes($ex['description'])) ?> — <?= e(money_fmt((float)$ex['amount'])) ?>\n\nالمبلغ هيرجع لخزينة عهدة الموظف.');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="line_id" value="<?= (int)$ex['id'] ?>">
                                    <button type="submit" title="إلغاء المصروف"
                                            style="background:none;border:none;cursor:pointer;color:var(--neg);
                                                   font-size:17px;padding:4px 8px;border-radius:7px;line-height:1;">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- إذونات الصرف الواردة -->
            <div class="card">
                <div class="section-title">إذونات الصرف الصادرة للموظف</div>
                <?php if (empty($empOrders)): ?>
                    <div class="empty">لا توجد إذونات في هذه الفترة.</div>
                <?php else: ?>
                <table>
                    <thead><tr><th>التاريخ</th><th>المبلغ</th><th>ملاحظات</th></tr></thead>
                    <tbody>
                    <?php foreach ($empOrders as $o): ?>
                        <tr>
                            <td><?= e($o['order_date']) ?></td>
                            <td style="font-weight:500;color:var(--pos);"><?= number_format((float)$o['amount'],2) ?></td>
                            <td><?= e($o['notes'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- محضر جرد الكاش والإقفال لعهدة الموظف -->
        <div class="card">
            <div class="section-title">محضر جرد عهدة الموظف والإقفال</div>

        <?php if ($isEmpPending): ?>
            <?php
                $subCounted = (float) ($empCustody['counted_cash'] ?? 0);
                $subDiff    = $subCounted - $empClosing;
            ?>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
                الأرقام اللي اتقدّمت للمحاسب. مفيش تعديل عليها لحد ما يعتمد أو يرفض.
            </p>
            <table style="max-width:420px;">
                <tbody>
                    <tr><td>آخر المدة المفروض</td>
                        <td style="font-weight:600;"><?= number_format($empClosing,2) ?></td></tr>
                    <tr><td>اللي اتعدّ فعلاً</td>
                        <td style="font-weight:600;"><?= number_format($subCounted,2) ?></td></tr>
                    <tr><td>الفرق المتوقع</td>
                        <td style="font-weight:700;color:<?= $subDiff < 0 ? 'var(--neg)' : ($subDiff > 0 ? 'var(--warn)' : 'var(--pos)') ?>;">
                            <?= number_format($subDiff,2) ?>
                        </td></tr>
                </tbody>
            </table>
            <p style="color:var(--ink-400);font-size:12px;margin-top:12px;">
                الفرق ده متوقع بس — الرقم النهائي هو اللي المحاسب هيستلمه فعلاً.
            </p>
        <?php else: ?>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
                عُدّ الكاش الموجود فعلاً بالفئات. النظام هيحسب الفرق مع آخر المدة المفروض
                (<?= number_format($empClosing,2) ?> جنيه). بعد ما تبعت، المحاسب بيراجع —
                <strong>وعند الاعتماد بيتعمل قيد عكسي لإذن الصرف والمصاريف تنزل على الخزينة مباشرة وتختفي العهدة من الداشبورد.</strong>
            </p>
            <form method="post" id="closeEmpForm">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="close_custody">
                <input type="hidden" name="custody_id" value="<?= (int)$empCustody['id'] ?>">
                <input type="hidden" name="employee_id" value="<?= (int)$selectedEmpId ?>">
                <input type="hidden" name="counted_cash" id="countedCashEmp" value="0">

                <table class="denom-table" style="max-width:420px;">
                    <thead><tr><th>الفئة</th><th>العدد</th><th>الإجمالي</th></tr></thead>
                    <tbody>
                    <?php foreach ($denominations as $d): ?>
                        <tr>
                            <td><?= $d ?> ج.م</td>
                            <td><input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="denom-count-emp" data-denom="<?= $d ?>"
                                       value="0" oninput="recalcEmp()"></td>
                            <td class="denom-sub" id="sub_emp_<?= $d ?>">0.00</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div id="countResultEmp" class="count-result ok" style="display:none;"></div>

                <div class="form-inline" style="margin-top:16px;">
                    <div class="fld" style="max-width:150px;">
                        <label>تاريخ الإقفال</label>
                        <input type="date" name="date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <button type="submit" class="btn btn-danger">
                        <i class="ti ti-lock"></i> إقفال وإرسال للمراجعة
                    </button>
                </div>
            </form>
        <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>

<div class="top-row">
    <div class="branch-select">
        <?php if (empty($u['branch_id']) && $allBranches): ?>
            <form method="get" style="display:inline;">
                <select name="branch" onchange="formSubmit(this.form)">
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php else: ?>
            <strong style="font-size:17px;color:var(--ink-900);"><?= e($branchName) ?></strong>
        <?php endif; ?>
    </div>

<?php if (!$branchId): ?>
    <div class="card"><div class="empty">لا توجد فروع. أضف فرعاً أولاً من التعريفات.</div></div>
<?php elseif (!$custody): ?>
    <div class="card">
        <div class="empty">
            لا توجد عهدة مفتوحة لفرع <?= e($branchName) ?>.<br>
            صرف العهدة بيتم من شاشة الخزينة (زر صرف ← نوع الصرف: عهدة لفرع).
        </div>
    </div>
<?php else: ?>
    <?php
        $opening = (float) $custody['opening_balance'];
        $totalExp = (float) $custody['total_expenses'];
        // آخر المدة = أول المدة − المصروفات (حساب لحظي مضمون، مش من قاعدة البيانات)
        $closing = $opening - $totalExp;
    ?>
    <?php if ($isPendingReview): ?>
        <div class="status-bar pending">
            <i class="ti ti-clock"></i>
            <div>
                <strong>مقدّمة لمراجعة المحاسب.</strong>
                الفلوس <u>مادخلتش الخزينة لسه</u> — هتدخل أول ما المحاسب يعتمد.
                <?php if (!empty($custody['submitted_at'])): ?>
                    <span class="sb-sub">اتقدّمت <?= e($custody['submitted_at']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($custodyStatus === 'rejected'): ?>
        <div class="status-bar rejected">
            <i class="ti ti-alert-triangle"></i>
            <div>
                <strong>المحاسب رفض الإقفال:</strong> <?= e($custody['reject_reason'] ?? '—') ?>
                <span class="sb-sub">ظبّط المصاريف أو العدّ وقدّم تاني.</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="stats" style="display:flex;gap:14px;flex-wrap:wrap;">
        <div class="stat" style="flex:1;min-width:160px;">
            <div class="label">رصيد أول المدة</div>
            <div class="value"><?= number_format($opening,2) ?></div>
        </div>
        <div class="stat" style="flex:1;min-width:160px;">
            <div class="label">إجمالي المصروفات</div>
            <div class="value" style="color:var(--neg);"><?= number_format($totalExp,2) ?></div>
        </div>
        <div class="stat accent" style="flex:1;min-width:160px;">
            <div class="label">رصيد آخر المدة (المفروض)</div>
            <div class="value"><?= number_format($closing,2) ?></div>
        </div>
    </div>

    <div class="grid2">
        <!-- المصروفات -->
        <div class="card">
            <div class="section-title">المصروفات</div>
            <?php if ($canEditCustody): ?>
            <form method="post" class="form-inline" style="margin-bottom:16px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_expense">
                <input type="hidden" name="custody_id" value="<?= (int)$custody['id'] ?>">
                <div class="fld" style="max-width:150px;">
                    <label>القيد</label>
                    <?= acctree_field('entry_code_id', null, '— بدون —', 'custody') ?>
                </div>
                <div class="fld"><label>البيان</label><input type="text" name="description" required aria-label="البيان"></div>
                <div class="fld" style="max-width:110px;"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
                <div class="fld" style="max-width:140px;"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                <button type="submit" class="btn btn-sm">إضافة</button>
            </form>
            <?php endif; ?>
            <?php if (empty($expenses)): ?>
                <div class="empty">لا توجد مصروفات بعد.</div>
            <?php else: ?>
            <table>
                <thead><tr><th>التاريخ</th><th>القيد</th><th>البيان</th><th>المبلغ</th><?php if ($canEditCustody): ?><th style="width:52px;"></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($expenses as $ex): ?>
                    <tr>
                        <td><?= e($ex['expense_date']) ?></td>
                        <td><?= !empty($ex['entry_code_name']) ? '<span style="padding:2px 9px;border-radius:20px;font-size:12px;color:var(--accent-text);background:var(--bg);">'.e($ex['entry_code_name']).'</span>' : '<span style="color:var(--ink-300);">—</span>' ?></td>
                        <td><?= e($ex['description']) ?></td>
                        <td style="font-weight:500;"><?= money_fmt((float)$ex['amount']) ?></td>
                        <?php if ($canEditCustody): ?>
                        <td>
                            <form method="post" style="margin:0;"
                                  onsubmit="return confirm('إلغاء المصروف ده؟\n\n<?= e(addslashes($ex['description'])) ?> — <?= e(money_fmt((float)$ex['amount'])) ?>\n\nالمبلغ هيرجع لخزينة العهدة، وهيتسجّل قيد إلغاء في كشف الحساب.');">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="line_id" value="<?= (int)$ex['id'] ?>">
                                <button type="submit" title="إلغاء المصروف"
                                        style="background:none;border:none;cursor:pointer;color:var(--neg);
                                               font-size:17px;padding:4px 8px;border-radius:7px;line-height:1;">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- إذونات الصرف الواردة -->
        <div class="card">
            <div class="section-title">إذونات الصرف الواردة</div>
            <?php if (empty($orders)): ?>
                <div class="empty">لا توجد إذونات في هذه الفترة.</div>
            <?php else: ?>
            <table>
                <thead><tr><th>التاريخ</th><th>المبلغ</th><th>ملاحظات</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= e($o['order_date']) ?></td>
                        <td style="font-weight:500;color:var(--pos);"><?= number_format((float)$o['amount'],2) ?></td>
                        <td><?= e($o['notes'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- محضر جرد الكاش والإقفال -->
    <div class="card">
        <div class="section-title">محضر جرد العهدة والإقفال الشهري</div>

    <?php if ($isPendingReview): ?>
        <?php
            $subCounted = (float) ($custody['counted_cash'] ?? 0);
            $subDiff    = $subCounted - $closing;
        ?>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            الأرقام اللي اتقدّمت للمحاسب. مفيش تعديل عليها لحد ما يعتمد أو يرفض.
        </p>
        <table style="max-width:420px;">
            <tbody>
                <tr><td>آخر المدة المفروض</td>
                    <td style="font-weight:600;"><?= number_format($closing,2) ?></td></tr>
                <tr><td>اللي اتعدّ فعلاً</td>
                    <td style="font-weight:600;"><?= number_format($subCounted,2) ?></td></tr>
                <tr><td>الفرق المتوقع</td>
                    <td style="font-weight:700;color:<?= $subDiff < 0 ? 'var(--neg)' : ($subDiff > 0 ? 'var(--warn)' : 'var(--pos)') ?>;">
                        <?= number_format($subDiff,2) ?>
                    </td></tr>
            </tbody>
        </table>
        <p style="color:var(--ink-400);font-size:12px;margin-top:12px;">
            الفرق ده متوقع بس — الرقم النهائي هو اللي المحاسب هيستلمه فعلاً.
        </p>
    <?php else: ?>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            عُدّ الكاش الموجود فعلاً بالفئات. النظام هيحسب الفرق مع آخر المدة المفروض
            (<?= number_format($closing,2) ?> جنيه). بعد ما تبعت، المحاسب بيراجع —
            <strong>والفلوس بتدخل الخزينة وقت اعتماده هو، مش دلوقتي.</strong>
        </p>
        <form method="post" id="closeForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="close_custody">
            <input type="hidden" name="custody_id" value="<?= (int)$custody['id'] ?>">
            <input type="hidden" name="counted_cash" id="countedCash" value="0">

            <table class="denom-table" style="max-width:420px;">
                <thead><tr><th>الفئة</th><th>العدد</th><th>الإجمالي</th></tr></thead>
                <tbody>
                <?php foreach ($denominations as $d): ?>
                    <tr>
                        <td><?= $d ?> ج.م</td>
                        <td><input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="denom-count" data-denom="<?= $d ?>"
                                   value="0" oninput="recalc()"></td>
                        <td class="denom-sub" id="sub_<?= $d ?>">0.00</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="countResult" class="count-result ok" style="display:none;"></div>

            <div class="form-inline" style="margin-top:16px;">
                <?php if ($branchEmployees): ?>
                <div class="fld" style="max-width:220px;">
                    <label>الموظف المسؤول (إجباري لو فيه عجز فوق <?= number_format($tolerance,0) ?> ج)</label>
                    <select name="employee_id">
                        <option value="">— بدون —</option>
                        <?php foreach ($branchEmployees as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="fld" style="max-width:150px;">
                    <label>تاريخ الإقفال</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>">
                </div>
                <button type="submit" class="btn btn-danger">
                    <i class="ti ti-lock"></i> إقفال وإرسال للمراجعة
                </button>
            </div>
        </form>
    <?php endif; ?>
    </div>
<?php endif; // قفل if (!$branchId): ... else ?>
<?php endif; // قفل if ($activeTab === 'employee'): ... else ?>

<!-- Modal: إذن صرف لفرع -->
<?php if ($canIssue && $canBranchCustody): ?>
<div class="modal-bg" id="orderModal">
    <div class="modal">
        <h3>إصدار إذن صرف لفرع</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_order">
            <div class="form-row">
                <label>الفرع</label>
                <select name="branch_id" required>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>المبلغ (ج.م)</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" placeholder="ملاحظات الصرف..." aria-label="ملاحظات"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">إصدار الإذن</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('orderModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?= acctree_data(true) ?>
<script src="assets/acctree.js?v=3"></script>
<script>
    function openModal(id){ const el = document.getElementById(id); if (el) el.classList.add('open'); }
    function closeModal(id){ const el = document.getElementById(id); if (el) el.classList.remove('open'); }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    // ===== جرد عهدة الفرع =====
    const expected = <?= json_encode($custody ? (float)$custody['closing_balance'] : 0) ?>;
    const tolerance = <?= json_encode($tolerance) ?>;
    let currentDiff = 0;

    function recalc() {
        const hidden = document.getElementById('countedCash');
        if (!hidden) return;
        let total = 0;
        document.querySelectorAll('.denom-count').forEach(inp => {
            const d = parseFloat(inp.dataset.denom);
            const c = parseInt(inp.value) || 0;
            const sub = d * c;
            total += sub;
            const subEl = document.getElementById('sub_' + d);
            if (subEl) subEl.textContent = sub.toFixed(2);
        });
        hidden.value = total.toFixed(2);

        const diff = total - expected;
        currentDiff = diff;
        const box = document.getElementById('countResult');
        if (box) {
            box.style.display = 'block';
            if (Math.abs(diff) < 0.001) {
                box.className = 'count-result ok';
                box.textContent = 'الفعلي ' + total.toFixed(2) + ' = المفروض. مفيش فرق ✓';
            } else if (diff < 0) {
                const def = Math.abs(diff);
                const who = def > tolerance ? ' (يُسجّل على الموظف)' : ' (يتحمّله المحل)';
                box.className = 'count-result def';
                box.textContent = 'عجز ' + def.toFixed(2) + ' جنيه' + who;
            } else {
                box.className = 'count-result sur';
                box.textContent = 'زيادة ' + diff.toFixed(2) + ' جنيه (تدخل الخزينة بعد اعتماد المحاسب)';
            }
        }
    }
    recalc();

    const closeForm = document.getElementById('closeForm');
    if (closeForm) {
        closeForm.addEventListener('submit', function (e) {
            if (currentDiff < 0 && Math.abs(currentDiff) > tolerance) {
                const empSelect = closeForm.querySelector('select[name="employee_id"]');
                if (!empSelect || !empSelect.value) {
                    e.preventDefault();
                    alert('العجز ' + Math.abs(currentDiff).toFixed(2) + ' ج أكبر من الحد المسموح (' + tolerance + ' ج) — لازم تحدد الموظف المسؤول قبل الإقفال.');
                    if (empSelect) empSelect.focus();
                    return false;
                }
            }
            if (!confirm('تأكيد إقفال العهدة وإرسالها لمراجعة المحاسب؟\n\nالفلوس مش هتدخل الخزينة دلوقتي — هتدخل بعد ما المحاسب يعتمد.')) {
                e.preventDefault();
                return false;
            }
        });
    }

    // ===== جرد عهدة الموظف =====
    const empExpected = <?= json_encode(!empty($empClosing) ? $empClosing : 0) ?>;
    let empCurrentDiff = 0;

    function recalcEmp() {
        const hidden = document.getElementById('countedCashEmp');
        if (!hidden) return;
        let total = 0;
        document.querySelectorAll('.denom-count-emp').forEach(inp => {
            const d = parseFloat(inp.dataset.denom);
            const c = parseInt(inp.value) || 0;
            const sub = d * c;
            total += sub;
            const subEl = document.getElementById('sub_emp_' + d);
            if (subEl) subEl.textContent = sub.toFixed(2);
        });
        hidden.value = total.toFixed(2);

        const diff = total - empExpected;
        empCurrentDiff = diff;
        const box = document.getElementById('countResultEmp');
        if (box) {
            box.style.display = 'block';
            if (Math.abs(diff) < 0.001) {
                box.className = 'count-result ok';
                box.textContent = 'الفعلي ' + total.toFixed(2) + ' = المفروض. مفيش فرق ✓';
            } else if (diff < 0) {
                box.className = 'count-result def';
                box.textContent = 'عجز ' + Math.abs(diff).toFixed(2) + ' جنيه';
            } else {
                box.className = 'count-result sur';
                box.textContent = 'زيادة ' + diff.toFixed(2) + ' جنيه (تدخل الخزينة بعد اعتماد المحاسب)';
            }
        }
    }
    recalcEmp();

    const closeEmpForm = document.getElementById('closeEmpForm');
    if (closeEmpForm) {
        closeEmpForm.addEventListener('submit', function (e) {
            if (!confirm('تأكيد إقفال عهدة الموظف وإرسالها لمراجعة المحاسب؟\n\nالفلوس مش هتدخل الخزينة دلوقتي — هتدخل بعد ما المحاسب يعتمد ويتم قيد عكسي لإذن الصرف ونزول المصاريف.')) {
                e.preventDefault();
                return false;
            }
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
