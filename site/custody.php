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
require_once __DIR__ . '/custody_lib.php';

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

// صلاحية إصدار إذون الصرف: الأونر أو من له صلاحية حركة الخزينة (صرف واستلام)
// إذن الصرف بيطلّع فلوس ← صلاحية الحركة مش صلاحية العرض (بريف ٥١ — بعد بريف ٤١)
$canIssue = is_owner() || can('treasury:operate');

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
$allBranches = custody_get_branches($pdo);
$branchId = custody_resolve_branch_id($u, isset($_GET['branch']) ? (int)$_GET['branch'] : null, $allBranches);

// الموظف المختار لعهدة الموظف
$allEmployees = custody_get_employees($pdo);
$allSuppliers = custody_get_suppliers($pdo);

// جلب موظف المستخدم الحالي (إن وُجد)
$curUserEmp = custody_get_user_employee($pdo, $uid);
$currentUserEmpId = (int) ($curUserEmp['id'] ?? 0);

// الموظف العادي لا يرى إلا عهدته هو فقط — الأونر فقط يقدر يختار أي موظف
$selectedEmpId = custody_resolve_selected_emp_id($u, isset($_GET['emp']) ? (int)$_GET['emp'] : null, $currentUserEmpId, $allEmployees);

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
                $amount    = money_post('amount');
                $entryCodeId = ($_POST['entry_code_id'] ?? '') !== '' ? (int) $_POST['entry_code_id'] : null;
                $lineType    = trim($_POST['line_type'] ?? 'expense');
                if (!in_array($lineType, ['expense', 'advance', 'supplier'], true)) {
                    $lineType = 'expense';
                }
                $advEmployeeId = (!empty($_POST['adv_employee_id'])) ? (int) $_POST['adv_employee_id'] : null;
                $supplierId    = (!empty($_POST['supplier_id'])) ? (int) $_POST['supplier_id'] : null;

                if ($lineType === 'expense' && $desc === '') {
                    throw new RuntimeException('بيان المصروف مطلوب.');
                }

                $stC = $pdo->prepare("SELECT branch_id, employee_id FROM branch_custody WHERE id=?");
                $stC->execute([$custodyId]);
                $cRow = $stC->fetch();
                if (!$cRow) throw new RuntimeException('العهدة غير موجودة.');
                if (!empty($cRow['employee_id']) && !$canEmployeeCustody) {
                    throw new RuntimeException('غير مصرح لك بتسجيل مصروف على عهدة الموظف.');
                }
                // 🔴 الموظف العادي يشتغل على عهدته هو بس — الشاشة بتخفي الباقي، والطلب لازم يرفض كمان
                //    (من غير ده كان يقدر يبعت رقم عهدة موظف تاني ويضيف عليها سلفة لأي حد)
                if (!empty($cRow['employee_id']) && !is_owner() && (int) $cRow['employee_id'] !== $currentUserEmpId) {
                    throw new RuntimeException('دي مش عهدتك.');
                }
                if (!empty($cRow['branch_id']) && empty($cRow['employee_id']) && !$canBranchCustody) {
                    throw new RuntimeException('غير مصرح لك بتسجيل مصروف على عهدة الفرع.');
                }

                $expenseBranchId = null;
                if (!empty($cRow['employee_id'])) {
                    $expenseBranchId = (int) ($_POST['branch_id'] ?? 0);
                    $stBranch = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 AND has_finance = 1');
                    $stBranch->execute([$expenseBranchId]);
                    if (!$stBranch->fetchColumn()) throw new RuntimeException('اختار فرع صحيح للمصروف.');
                }

                add_custody_expense($custodyId, $date, $desc, $amount, $uid, $entryCodeId, $lineType, $advEmployeeId, $supplierId, $expenseBranchId);
                $flash = 'تم تسجيل العملية.';
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
                // 🔴 الموظف العادي يشتغل على عهدته هو بس — الشاشة بتخفي الباقي، والطلب لازم يرفض كمان
                //    (من غير ده كان يقدر يبعت رقم عهدة موظف تاني ويضيف عليها سلفة لأي حد)
                if (!empty($cRow['employee_id']) && !is_owner() && (int) $cRow['employee_id'] !== $currentUserEmpId) {
                    throw new RuntimeException('دي مش عهدتك.');
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
                // 🔴 الموظف العادي يشتغل على عهدته هو بس — الشاشة بتخفي الباقي، والطلب لازم يرفض كمان
                //    (من غير ده كان يقدر يبعت رقم عهدة موظف تاني ويضيف عليها سلفة لأي حد)
                if (!empty($cRow['employee_id']) && !is_owner() && (int) $cRow['employee_id'] !== $currentUserEmpId) {
                    throw new RuntimeException('دي مش عهدتك.');
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
$branchCustodyData = $branchId ? custody_get_branch_custody_data($pdo, $branchId, $allBranches) : null;
$branchName      = $branchCustodyData['branch_name'] ?? '';
$custody         = $branchCustodyData['custody'] ?? null;
$expenses        = $branchCustodyData['expenses'] ?? [];
$orders          = $branchCustodyData['orders'] ?? [];
$branchEmployees = $branchCustodyData['branch_employees'] ?? [];

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
    .top-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
    .stat { padding:18px; }
    .stat .label { font-size:13px; color:var(--ink-500); margin-bottom:6px; }
    .stat .value { font-size:24px; font-weight:700; color:var(--ink-900); }
    .stat.accent { background:var(--accent); color:var(--fill-strong-fg); }
    .stat.accent .label { color:var(--fill-strong-fg); opacity:.85; } .stat.accent .value { color:var(--fill-strong-fg); }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width:820px){ .grid2 { grid-template-columns:1fr; } }
    .card { margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
    .empty { text-align:center; color:var(--ink-400); padding:24px; font-size:14px; }
    .form-inline { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .form-inline .fld { flex:1; min-width:120px; }
    /* جرد الفئات */
    .denom-table .finput { width:90px; }
    .denom-sub { font-weight:600; color:var(--ink-900); }
    .count-result { padding:14px; border-radius:10px; margin-top:14px; font-size:15px; font-weight:600; }
    .count-result.ok { background:var(--pos-soft); color:var(--pos); }
    .count-result.def { background:var(--neg-soft); color:var(--neg); }
    .count-result.sur { background:var(--warn-soft); color:var(--warn); }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row .cst-pick-search { margin-bottom:8px; }
    .cst-pick-list { max-height:160px;overflow-y:auto;border:1.5px solid var(--line);border-radius:8px; }
    .cst-pick-opt { padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line-soft); }
    .cst-pick-chosen { margin-top:8px;font-size:13px;color:var(--pos);font-weight:600; }
    /* شريط حالة العهدة — لونه بيقول للكاشير هو في أنهي مرحلة */
    .status-bar { display:flex; gap:11px; align-items:flex-start; padding:14px 16px;
        border-radius:12px; margin-bottom:18px; font-size:14px; line-height:1.65; }
    .status-bar i { font-size:19px; flex-shrink:0; margin-top:1px; }
    .status-bar .sb-sub { display:block; font-size:13px; opacity:0.85; margin-top:2px; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
</style>

<?php if ($flash): ?>
    <div class="falert <?= $flashType === 'success' ? 'falert-pos' : 'falert-neg' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<script>
    function openModal(id){
        const el = document.getElementById(id);
        if (el) el.classList.add('open');
        if (id === 'expenseModal') {
            const type = document.querySelector('#expenseModal select#cstLineType');
            if (type) type.focus();
        }
    }
    function closeModal(id){
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
        if (id === 'expenseModal' && typeof resetExpenseModal === 'function') resetExpenseModal();
    }
</script>

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
    $empCustodyData  = custody_get_employee_custody_data($pdo, $selectedEmpId, $allEmployees);
    $selectedEmpName = $empCustodyData['employee_name'];
    $empCustody      = $empCustodyData['custody'];
    $empExpenses     = $empCustodyData['expenses'];
    $empOrders       = $empCustodyData['orders'];
    $empTrBal        = $empCustodyData['treasury_balance'];
    ?>
    <?php if (is_owner()): ?>
    <div class="top-row">
        <div class="branch-select">
            <form method="get" style="display:inline;">
                <input type="hidden" name="tab" value="employee">
                <select class="fselect" name="emp" onchange="formSubmit(this.form)">
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
        <div class="card fcard"><div class="empty">لا توجد عهدة مسجلة لحسابك حالياً.</div></div>
    <?php elseif (!$empCustody): ?>
        <div class="card fcard">
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
            <div class="status-bar falert falert-warn pending">
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
            <div class="status-bar falert falert-neg rejected">
                <i class="ti ti-alert-triangle"></i>
                <div>
                    <strong>المحاسب رفض الإقفال:</strong> <?= e($empCustody['reject_reason'] ?? '—') ?>
                    <span class="sb-sub">ظبّط المصاريف أو العدّ وقدّم تاني.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="stats fkpis" style="display:flex;gap:14px;flex-wrap:wrap;" data-xt-kpis="#custodyEmpExpenses">
            <div class="stat fkpi" style="flex:1;min-width:160px;">
                <div class="label">رصيد أول المدة</div>
                <div class="value"><?= number_format($empOpening,2) ?></div>
            </div>
            <div class="stat fkpi" style="flex:1;min-width:160px;">
                <div class="label">إجمالي المصروفات</div>
                <div class="value" style="color:var(--neg);"><span data-xt-sum="expense"><?= number_format($empTotalExp,2) ?></span></div>
            </div>
            <div class="stat fkpi accent" style="flex:1;min-width:160px;">
                <div class="label">رصيد آخر المدة (المفروض)</div>
                <div class="value"><?= number_format($empClosing,2) ?></div>
            </div>
        </div>

        <div class="grid2">
            <!-- المصروفات -->
            <div class="card fcard">
                <div class="section-title">اللي اتصرف من العهدة</div>
                <?php if ($canEditThisEmpCustody): ?>
                <div style="margin-bottom:16px;">
                    <button type="button" class="btn" onclick="openModal('expenseModal')"><i class="ti ti-plus"></i> صرف من العهدة</button>
                </div>
                <?php endif; ?>
                <?php if (empty($empExpenses)): ?>
                    <div class="empty">لا توجد مصروفات بعد.</div>
                <?php else: ?>
                <div class="ftable-wrap">
                <table class="ftable" id="custodyEmpExpenses">
                    <thead><tr><th>التاريخ</th><th>الفرع</th><th>رأس القيد</th><th>القيد</th><th>البيان</th><th class="num">المبلغ</th><?php if ($canEditThisEmpCustody): ?><th style="width:52px;"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($empExpenses as $ex): ?>
                        <tr data-v-expense="<?= (float)$ex['amount'] ?>">
                            <td><?= e($ex['expense_date']) ?></td>
                            <td><?= e($ex['branch_name'] ?? '—') ?></td>
                            <td><?= e($ex['account_root_name'] ?? '—') ?></td>
                            <td><?= e($ex['account_leaf_name'] ?? '—') ?></td>
                            <td>
                                <?php if (($ex['line_type'] ?? '') === 'advance'): ?>
                                    <span class="fchip fchip-warn">سلفة: <?= e($ex['adv_emp_name'] ?: 'موظف') ?></span>
                                <?php elseif (($ex['line_type'] ?? '') === 'supplier'): ?>
                                    <span class="fchip fchip-mute">مورد: <?= e($ex['supp_name'] ?: 'مورد') ?></span>
                                <?php endif; ?>
                                <?= e($ex['description']) ?>
                            </td>
                            <td class="num" style="font-weight:500;"><?= money_fmt((float)$ex['amount']) ?></td>
                            <?php if ($canEditThisEmpCustody): ?>
                            <td>
                                <?php
                                    $delMsg = "إلغاء المصروف ده؟\n\n" . $ex['description'] . " — " . money_fmt((float)$ex['amount']) . "\n\nالمبلغ هيرجع لخزينة عهدة الموظف.";
                                    if (($ex['line_type'] ?? '') === 'advance') {
                                        $delMsg = "إلغاء السلفة دي؟\n\n" . $ex['description'] . " — " . money_fmt((float)$ex['amount']) . "\n\nالسلفة هتتلغي ومش هتنزل على الموظف.";
                                    } elseif (($ex['line_type'] ?? '') === 'supplier') {
                                        $delMsg = "إلغاء دفعة المورد دي؟\n\n" . $ex['description'] . " — " . money_fmt((float)$ex['amount']) . "\n\nالمبلغ هيرجع لخزينة عهدة الموظف.";
                                    }
                                ?>
                                <form method="post" style="margin:0;"
                                      data-confirm="<?= e($delMsg) ?>" data-confirm-danger>
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
                </div>
                <?php endif; ?>
            </div>

            <!-- إذونات الصرف الواردة -->
            <div class="card fcard">
                <div class="section-title">إذونات الصرف الصادرة للموظف</div>
                <?php if (empty($empOrders)): ?>
                    <div class="empty">لا توجد إذونات في هذه الفترة.</div>
                <?php else: ?>
                <div class="ftable-wrap">
                <table class="ftable">
                    <thead><tr><th>التاريخ</th><th class="num">المبلغ</th><th>ملاحظات</th></tr></thead>
                    <tbody>
                    <?php foreach ($empOrders as $o): ?>
                        <tr>
                            <td><?= e($o['order_date']) ?></td>
                            <td class="num" style="font-weight:500;color:var(--pos);"><?= number_format((float)$o['amount'],2) ?></td>
                            <td><?= e($o['notes'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- محضر جرد الكاش والإقفال لعهدة الموظف -->
        <div class="card fcard">
            <div class="section-title">محضر جرد عهدة الموظف والإقفال</div>

        <?php if ($isEmpPending): ?>
            <?php
                $subCounted = (float) ($empCustody['counted_cash'] ?? 0);
                $subDiff    = $subCounted - $empClosing;
            ?>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
                الأرقام اللي اتقدّمت للمحاسب. مفيش تعديل عليها لحد ما يعتمد أو يرفض.
            </p>
            <div class="ftable-wrap">
            <table class="ftable" style="max-width:420px;">
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
            </div>
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

                <div class="ftable-wrap">
                <table class="denom-table ftable" style="max-width:420px;">
                    <thead><tr><th class="num">الفئة</th><th class="num">العدد</th><th class="num">الإجمالي</th></tr></thead>
                    <tbody>
                    <?php foreach ($denominations as $d): ?>
                        <tr>
                            <td><?= $d ?> ج.م</td>
                            <td class="num"><input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="denom-count-emp finput finput-sm num" data-denom="<?= $d ?>"
                                       value="0" oninput="recalcEmp()"></td>
                            <td class="denom-sub num" id="sub_emp_<?= $d ?>">0.00</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div id="countResultEmp" class="count-result ok" style="display:none;"></div>

                <div class="form-inline" style="margin-top:16px;">
                    <div class="fld" style="max-width:150px;">
                        <label>تاريخ الإقفال</label>
                        <input class="finput finput-sm" type="date" name="date" value="<?= date('Y-m-d') ?>">
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
                <select class="fselect" name="branch" onchange="formSubmit(this.form)">
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
    <div class="card fcard"><div class="empty">لا توجد فروع. أضف فرعاً أولاً من التعريفات.</div></div>
<?php elseif (!$custody): ?>
    <div class="card fcard">
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
        <div class="status-bar falert falert-warn pending">
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
        <div class="status-bar falert falert-neg rejected">
            <i class="ti ti-alert-triangle"></i>
            <div>
                <strong>المحاسب رفض الإقفال:</strong> <?= e($custody['reject_reason'] ?? '—') ?>
                <span class="sb-sub">ظبّط المصاريف أو العدّ وقدّم تاني.</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="stats fkpis" style="display:flex;gap:14px;flex-wrap:wrap;" data-xt-kpis="#custodyExpenses">
        <div class="stat fkpi" style="flex:1;min-width:160px;">
            <div class="label">رصيد أول المدة</div>
            <div class="value"><?= number_format($opening,2) ?></div>
        </div>
        <div class="stat fkpi" style="flex:1;min-width:160px;">
            <div class="label">إجمالي المصروفات</div>
            <div class="value" style="color:var(--neg);"><span data-xt-sum="expense"><?= number_format($totalExp,2) ?></span></div>
        </div>
        <div class="stat fkpi accent" style="flex:1;min-width:160px;">
            <div class="label">رصيد آخر المدة (المفروض)</div>
            <div class="value"><?= number_format($closing,2) ?></div>
        </div>
    </div>

    <div class="grid2">
        <!-- المصروفات -->
        <div class="card fcard">
            <div class="section-title">المصروفات</div>
            <?php if ($canEditCustody): ?>
            <div style="margin-bottom:16px;">
                <button type="button" class="btn" onclick="openModal('expenseModal')"><i class="ti ti-plus"></i> صرف من العهدة</button>
            </div>
            <?php endif; ?>
            <?php if (empty($expenses)): ?>
                <div class="empty">لا توجد مصروفات بعد.</div>
            <?php else: ?>
            <div class="ftable-wrap">
            <table class="ftable" id="custodyExpenses">
                <thead><tr><th>التاريخ</th><th>الفرع</th><th>رأس القيد</th><th>القيد</th><th>البيان</th><th class="num">المبلغ</th><?php if ($canEditCustody): ?><th style="width:52px;"></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($expenses as $ex): ?>
                    <tr data-v-expense="<?= (float)$ex['amount'] ?>">
                        <td><?= e($ex['expense_date']) ?></td>
                        <td><?= e($ex['branch_name'] ?? '—') ?></td>
                        <td><?= e($ex['account_root_name'] ?? '—') ?></td>
                        <td><?= e($ex['account_leaf_name'] ?? '—') ?></td>
                        <td><?= e($ex['description']) ?></td>
                        <td class="num" style="font-weight:500;"><?= money_fmt((float)$ex['amount']) ?></td>
                        <?php if ($canEditCustody): ?>
                        <td>
                            <form method="post" style="margin:0;"
                                  data-confirm="إلغاء المصروف ده؟&#10;&#10;<?= e($ex['description']) ?> — <?= e(money_fmt((float)$ex['amount'])) ?>&#10;&#10;المبلغ هيرجع لخزينة العهدة، وهيتسجّل قيد إلغاء في كشف الحساب." data-confirm-danger>
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
            </div>
            <?php endif; ?>
        </div>

        <!-- إذونات الصرف الواردة -->
        <div class="card fcard">
            <div class="section-title">إذونات الصرف الواردة</div>
            <?php if (empty($orders)): ?>
                <div class="empty">لا توجد إذونات في هذه الفترة.</div>
            <?php else: ?>
            <div class="ftable-wrap">
            <table class="ftable">
                <thead><tr><th>التاريخ</th><th class="num">المبلغ</th><th>ملاحظات</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= e($o['order_date']) ?></td>
                        <td class="num" style="font-weight:500;color:var(--pos);"><?= number_format((float)$o['amount'],2) ?></td>
                        <td><?= e($o['notes'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- محضر جرد الكاش والإقفال -->
    <div class="card fcard">
        <div class="section-title">محضر جرد العهدة والإقفال الشهري</div>

    <?php if ($isPendingReview): ?>
        <?php
            $subCounted = (float) ($custody['counted_cash'] ?? 0);
            $subDiff    = $subCounted - $closing;
        ?>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            الأرقام اللي اتقدّمت للمحاسب. مفيش تعديل عليها لحد ما يعتمد أو يرفض.
        </p>
        <div class="ftable-wrap">
        <table class="ftable" style="max-width:420px;">
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
        </div>
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

            <div class="ftable-wrap">
            <table class="denom-table ftable" style="max-width:420px;">
                <thead><tr><th class="num">الفئة</th><th class="num">العدد</th><th class="num">الإجمالي</th></tr></thead>
                <tbody>
                <?php foreach ($denominations as $d): ?>
                    <tr>
                        <td><?= $d ?> ج.م</td>
                        <td class="num"><input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="denom-count finput finput-sm num" data-denom="<?= $d ?>"
                                   value="0" oninput="recalc()"></td>
                        <td class="denom-sub num" id="sub_<?= $d ?>">0.00</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div id="countResult" class="count-result ok" style="display:none;"></div>

            <div class="form-inline" style="margin-top:16px;">
                <?php if ($branchEmployees): ?>
                <div class="fld" style="max-width:220px;">
                    <label>الموظف المسؤول (إجباري لو فيه عجز فوق <?= number_format($tolerance,0) ?> ج)</label>
                    <select class="fselect" name="employee_id">
                        <option value="">— بدون —</option>
                        <?php foreach ($branchEmployees as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="fld" style="max-width:150px;">
                    <label>تاريخ الإقفال</label>
                    <input class="finput finput-sm" type="date" name="date" value="<?= date('Y-m-d') ?>">
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

<?php $closedCustodies = custody_get_closed_history($pdo, $activeTab === 'employee', $branchId, $selectedEmpId); ?>
<?php if ($closedCustodies): ?>
    <h2>آخر العهد المعتمدة</h2>
    <?php foreach ($closedCustodies as $closedCustody): ?>
        <div class="fcard">
            <h3>عهدة <?= (int)$closedCustody['id'] ?> · <?= e($closedCustody['period_start']) ?> — <?= e($closedCustody['period_end'] ?? '—') ?></h3>
            <p>المحاسب استلم <?= ltr_out(number_format((float)$closedCustody['accountant_counted'], 2)) ?> ج · اعتمدها <?= e($closedCustody['reviewer_name'] ?? '—') ?> · <?= e($closedCustody['reviewed_at'] ?? '—') ?></p>
            <a class="btn btn-sm btn-secondary" href="custody_receipt.php?id=<?= (int)$closedCustody['id'] ?>" target="_blank" rel="noopener">طباعة محضر العهدة</a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal: إصدار إذن صرف لفرع -->
<?php if ($canIssue && $canBranchCustody): ?>
<div class="modal-bg" id="orderModal">
    <div class="modal fcard">
        <h3>إصدار إذن صرف لفرع</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_order">
            <div class="form-row">
                <label>الفرع</label>
                <select class="fselect" name="branch_id" required>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row ffield"><label>المبلغ (ج.م)</label><input class="finput num" type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row ffield"><label>التاريخ</label><input class="finput" type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row ffield"><label>ملاحظات</label><input class="finput" type="text" name="notes" placeholder="ملاحظات الصرف..." aria-label="ملاحظات"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">إصدار الإذن</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('orderModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal: صرف من العهدة -->
<?php
$showExpenseModal = false;
$expCustodyId = 0;
$expCustodyName = '';
$expIsEmp = false;

if ($activeTab === 'employee' && !empty($canEditThisEmpCustody) && !empty($empCustody)) {
    $showExpenseModal = true;
    $expCustodyId = (int) $empCustody['id'];
    $expCustodyName = $selectedEmpName ?: 'الموظف';
    $expIsEmp = true;
} elseif ($activeTab === 'branch' && !empty($canEditCustody) && !empty($custody)) {
    $showExpenseModal = true;
    $expCustodyId = (int) $custody['id'];
    $expCustodyName = $branchName ?: 'الفرع';
    $expIsEmp = false;
}
?>
<?php if ($showExpenseModal): ?>
<div class="modal-bg" id="expenseModal">
    <div class="modal fcard">
        <h3>صرف من العهدة</h3>
        <form method="post" id="expenseForm" onsubmit="return handleExpenseSubmit(event)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_expense">
            <input type="hidden" name="custody_id" value="<?= $expCustodyId ?>">

            <div id="cstFormBody">
                <?php if ($expIsEmp): ?>
                <!-- نوع الصرف: مصروف / سلفة لموظف / دفعة لمورد (في عهدة الموظف بس) -->
                <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                    <label for="cstLineType">نوع الصرف</label>
                    <select class="fselect" name="line_type" id="cstLineType" onchange="toggleCstLineType()">
                        <option value="expense">مصروف</option>
                        <option value="advance">سلفة لموظف</option>
                        <option value="supplier">دفعة لمورد</option>
                    </select>

                    <!-- سلفة لموظف -->
                    <div id="cstAdvanceBox" style="display:none;margin-top:12px;">
                        <input type="text" id="cstEmpSearch" class="cst-pick-search finput" placeholder="ابحث باسم الموظف..."
                               oninput="filterCstEmps()" autocomplete="off" aria-label="ابحث باسم الموظف...">
                        <div id="cstEmpList" class="cst-pick-list">
                            <?php foreach ($allEmployees as $emp): ?>
                                <div class="cst-emp-opt cst-pick-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                     onclick="pickCstEmp(this)">
                                    <?= e($emp['full_name']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="adv_employee_id" id="cstEmpId" value="">
                        <div id="cstEmpChosen" class="cst-pick-chosen"></div>
                    </div>

                    <!-- دفعة لمورد -->
                    <div id="cstSupplierBox" style="display:none;margin-top:12px;">
                        <input type="text" id="cstSuppSearch" class="cst-pick-search finput" placeholder="ابحث باسم المورد..."
                               data-picker-search="#cstSuppList" data-item=".cst-supp-opt" data-empty="مفيش مورد بالاسم ده"
                               oninput="filterCstSupps()" autocomplete="off" aria-label="ابحث باسم المورد...">
                        <div id="cstSuppList" class="cst-pick-list">
                            <?php foreach ($allSuppliers as $s): ?>
                                <div class="cst-supp-opt cst-pick-opt" data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"
                                     onclick="pickCstSupp(this)">
                                    <?= e($s['name']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="supplier_id" id="cstSuppId" value="">
                        <div id="cstSuppChosen" class="cst-pick-chosen"></div>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="line_type" id="cstLineType" value="expense">
                <?php endif; ?>
                <?php if ($expIsEmp): ?>
                <div class="form-row">
                    <label for="cstBranch">الفرع اللي اتصرف له</label>
                    <select class="fselect" name="branch_id" id="cstBranch" required>
                        <?php foreach ($allBranches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= (int)$empCustody['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-row" id="cstEntryCodeRow">
                    <label>القيد</label>
                    <?= acctree_field('entry_code_id', null, '— بدون —', 'custody') ?>
                </div>

                <div class="form-row">
                    <label for="cstAmount">المبلغ</label>
                    <input class="finput num" type="text" inputmode="decimal" data-money name="amount" id="cstAmount" required
                           autocomplete="off" placeholder="0" aria-label="المبلغ">
                </div>

                <div class="form-row">
                    <label for="cstDate">التاريخ</label>
                    <input class="finput" type="date" name="date" id="cstDate" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-row">
                    <label for="cstDesc">البيان</label>
                    <input class="finput" type="text" name="description" id="cstDesc" required aria-label="البيان" placeholder="سبب الصرف">
                </div>



                <div class="modal-actions">
                    <button type="submit" class="btn">تسجيل الصرف</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('expenseModal')">إلغاء</button>
                </div>
            </div>

            <!-- خطوة التأكيد قبل تسجيل الصرف -->
            <div id="cstConfirmBox" style="display:none;">
                <div style="background:var(--line-soft);padding:16px;border-radius:12px;border:1.5px solid var(--line);margin-bottom:18px;line-height:1.7;font-size:14px;color:var(--ink-800);">
                    انت على وشك تسجيل مصروف بقيمة <b id="cstConfirmAmount"></b> جنيه من عهدة «<span><?= e($expCustodyName) ?></span>».<br>
                    <span id="cstConfirmLineDetail"></span><br>
                    متأكد إن المبلغ ده هيتخصم من العهدة دي؟
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" id="cstConfirmBtn" onclick="confirmExpense()">تأكيد الصرف</button>
                    <button type="button" class="btn btn-cancel" onclick="cancelExpenseConfirm()">إلغاء</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?= acctree_data(true) ?>
<script src="assets/acctree.js?v=186"></script>
<script>
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => {
            if (e.target === m) {
                m.classList.remove('open');
                if (m.id === 'expenseModal' && typeof resetExpenseModal === 'function') resetExpenseModal();
            }
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            const expM = document.getElementById('expenseModal');
            if (expM && expM.classList.contains('open')) {
                closeModal('expenseModal');
            }
        }
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
                    fgToast('العجز ' + Math.abs(currentDiff).toFixed(2) + ' ج أكبر من الحد المسموح (' + tolerance + ' ج) — لازم تحدد الموظف المسؤول قبل الإقفال.', 'warn', 8000);
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

    // ===== صرف من العهدة (مودال) =====
    let cstExpenseConfirmed = false;
    let cstExpenseSending = false;

    function handleExpenseSubmit(e) {
        if (!cstExpenseConfirmed) {
            e.preventDefault();
            const form = document.getElementById('expenseForm');
            if (!form) return false;
            if (!form.checkValidity()) {
                form.reportValidity();
                return false;
            }
            const amtInput = form.querySelector('input[name="amount"]');
            const amtVal = typeof moneyVal === 'function' ? moneyVal(amtInput) : (parseFloat((amtInput.value || '').replace(/,/g, '')) || 0);
            if (amtVal <= 0) {
                fgToast('اكتب مبلغ أكبر من صفر.', 'warn');
                return false;
            }
            const lineTypeEl = document.getElementById('cstLineType');
            const lineType = lineTypeEl ? lineTypeEl.value : 'expense';

            if (lineType === 'advance') {
                const empId = document.getElementById('cstEmpId');
                if (!empId || !empId.value) {
                    fgToast('اختار الموظف.', 'warn');
                    return false;
                }
            } else if (lineType === 'supplier') {
                const suppId = document.getElementById('cstSuppId');
                if (!suppId || !suppId.value) {
                    fgToast('اختار المورد.', 'warn');
                    return false;
                }
            }

            const formattedAmt = amtVal.toLocaleString('en-US', {
                minimumFractionDigits: (amtVal % 1 === 0 ? 0 : 2),
                maximumFractionDigits: 2
            });
            const cstAmtEl = document.getElementById('cstConfirmAmount');
            if (cstAmtEl) cstAmtEl.textContent = formattedAmt;

            const cstDetailEl = document.getElementById('cstConfirmLineDetail');
            if (cstDetailEl) {
                if (lineType === 'expense') {
                    const accLabel = form.querySelector('.act-label');
                    const entryText = (accLabel && accLabel.textContent.trim()) ? accLabel.textContent.trim() : '— بدون —';
                    cstDetailEl.textContent = 'القيد: ' + entryText;
                } else if (lineType === 'advance') {
                    const empChosen = document.getElementById('cstEmpChosen');
                    const empText = empChosen ? empChosen.textContent.replace('تم اختيار:', '').trim() : '';
                    cstDetailEl.textContent = 'سلفة: ' + empText + ' — هتنزل عليه لما المحاسب يعتمد إقفال العهدة.';
                } else if (lineType === 'supplier') {
                    const suppChosen = document.getElementById('cstSuppChosen');
                    const suppText = suppChosen ? suppChosen.textContent.replace('تم اختيار:', '').trim() : '';
                    cstDetailEl.textContent = 'مورد: ' + suppText;
                }
            }

            document.getElementById('cstFormBody').style.display = 'none';
            document.getElementById('cstConfirmBox').style.display = 'block';
            return false;
        }
    }

    function setCstSending(sending) {
        cstExpenseSending = sending;
        const btn = document.getElementById('cstConfirmBtn');
        if (!btn) return;
        if (typeof fgBusy === 'function') {
            fgBusy(btn, sending, 'جاري الصرف…');
        } else {
            btn.disabled = sending;
        }
    }

    function confirmExpense() {
        if (cstExpenseSending) return;
        cstExpenseConfirmed = true;
        setCstSending(true);
        const form = document.getElementById('expenseForm');
        if (typeof formSubmit === 'function') {
            formSubmit(form);
        } else if (form) {
            form.submit();
        }
    }

    function cancelExpenseConfirm() {
        cstExpenseConfirmed = false;
        setCstSending(false);
        const confirmBox = document.getElementById('cstConfirmBox');
        const formBody = document.getElementById('cstFormBody');
        if (confirmBox) confirmBox.style.display = 'none';
        if (formBody) formBody.style.display = 'block';
    }

    function resetExpenseModal() {
        cstExpenseConfirmed = false;
        setCstSending(false);
        const confirmBox = document.getElementById('cstConfirmBox');
        const formBody = document.getElementById('cstFormBody');
        if (confirmBox) confirmBox.style.display = 'none';
        if (formBody) formBody.style.display = 'block';

        const lt = document.getElementById('cstLineType');
        if (lt && lt.tagName === 'SELECT') {
            lt.value = 'expense';
            toggleCstLineType();
        }
    }

    function toggleCstLineType() {
        const lt = document.getElementById('cstLineType');
        const val = lt ? lt.value : 'expense';
        const rowEntry = document.getElementById('cstEntryCodeRow');
        const boxAdv   = document.getElementById('cstAdvanceBox');
        const boxSupp  = document.getElementById('cstSupplierBox');
        const descInp  = document.getElementById('cstDesc');

        if (rowEntry) rowEntry.style.display = (val === 'expense') ? '' : 'none';
        if (boxAdv)   boxAdv.style.display   = (val === 'advance') ? 'block' : 'none';
        if (boxSupp)  boxSupp.style.display  = (val === 'supplier') ? 'block' : 'none';

        if (descInp) {
            descInp.required = (val === 'expense');
        }

        if (val !== 'advance') {
            const empId = document.getElementById('cstEmpId');
            const empChosen = document.getElementById('cstEmpChosen');
            const empSearch = document.getElementById('cstEmpSearch');
            if (empId) empId.value = '';
            if (empChosen) empChosen.textContent = '';
            if (empSearch) empSearch.value = '';
            document.querySelectorAll('.cst-emp-opt').forEach(o => {
                o.style.display = '';
                o.style.background = '';
            });
        }
        if (val !== 'supplier') {
            const suppId = document.getElementById('cstSuppId');
            const suppChosen = document.getElementById('cstSuppChosen');
            const suppSearch = document.getElementById('cstSuppSearch');
            if (suppId) suppId.value = '';
            if (suppChosen) suppChosen.textContent = '';
            if (suppSearch) suppSearch.value = '';
            document.querySelectorAll('.cst-supp-opt').forEach(o => {
                o.style.display = '';
                o.style.background = '';
            });
        }
    }

    function filterCstSupps() {
        const input = document.getElementById('cstSuppSearch');
        if (input && input._fgFilter) { input._fgFilter(); return; }
        const q = window.fgNorm ? window.fgNorm(input ? input.value : '') : (input ? input.value : '').trim().toLowerCase();
        document.querySelectorAll('.cst-supp-opt').forEach(o => {
            const name = window.fgNorm ? window.fgNorm(o.dataset.name || '') : (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }

    function pickCstSupp(el) {
        const suppId = document.getElementById('cstSuppId');
        const suppChosen = document.getElementById('cstSuppChosen');
        if (suppId) suppId.value = el.dataset.id;
        if (suppChosen) suppChosen.textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.cst-supp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
    }

    function filterCstEmps() {
        const input = document.getElementById('cstEmpSearch');
        const q = (input ? input.value : '').trim().toLowerCase();
        document.querySelectorAll('.cst-emp-opt').forEach(o => {
            const name = (o.dataset.name || '').toLowerCase();
            o.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    }

    function pickCstEmp(el) {
        const empId = document.getElementById('cstEmpId');
        const empChosen = document.getElementById('cstEmpChosen');
        if (empId) empId.value = el.dataset.id;
        if (empChosen) empChosen.textContent = 'تم اختيار: ' + el.dataset.name;
        document.querySelectorAll('.cst-emp-opt').forEach(o => o.style.background = '');
        el.style.background = 'var(--pos-soft)';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
