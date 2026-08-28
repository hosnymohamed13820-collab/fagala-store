<?php
/**
 * custody.php — عهدة الفرع
 * نظام الفجالة ستور المالي
 *
 * - إصدار إذن صرف (المحاسب/الأونر) → يفتح/يزوّد عهدة الفرع.
 * - تسجيل مصروفات (الكاشير) → تطلع من خزينة العهدة فعلياً.
 * - جرد فئات الكاش + حساب الفرق.
 * - الإقفال الشهري: استلام الباقي الفعلي → تصفير العهدة + عجز/زيادة.
 *
 * كل الحركات المالية تمر من finance.php.
 */
require_once __DIR__ . '/auth.php';
require_permission('custody');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$u    = current_user();
$uid  = (int) $u['id'];
$entryCodes = entry_codes_list(true); // حسابات العهدة بس (is_custody=1)
$flash = ''; $flashType = 'success';

// صلاحية إصدار إذون الصرف: الأونر أو من له صلاحية treasury (المحاسب)
$canIssue = is_owner() || can('treasury');

// تحديد الفرع المعروض:
// - لو المستخدم مربوط بفرع → فرعه.
// - لو أونر/محاسب → يقدر يختار فرع من القائمة.
$allBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();

$branchId = null;
if (!empty($u['branch_id'])) {
    $branchId = (int) $u['branch_id'];
} elseif (isset($_GET['branch'])) {
    $branchId = (int) $_GET['branch'];
} elseif ($allBranches) {
    $branchId = (int) $allBranches[0]['id'];
}

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'issue_order' && $canIssue) {
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
                $prgTarget = 'custody.php?branch=' . $bId; // نرجع لصفحة الفرع اللي اتصرفله
            }
            elseif ($action === 'add_expense') {
                $custodyId = (int) ($_POST['custody_id'] ?? 0);
                $date      = $_POST['date'] ?? date('Y-m-d');
                $desc      = trim($_POST['description'] ?? '');
                $amount    = (float) ($_POST['amount'] ?? 0);
                $entryCodeId = ($_POST['entry_code_id'] ?? '') !== '' ? (int) $_POST['entry_code_id'] : null;
                if ($desc === '') throw new RuntimeException('بيان المصروف مطلوب.');
                add_custody_expense($custodyId, $date, $desc, $amount, $uid, $entryCodeId);
                $flash = 'تم تسجيل المصروف.';
            }
            elseif ($action === 'delete_expense') {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                if ($lineId <= 0) throw new RuntimeException('مصروف غير محدد.');
                delete_custody_expense($lineId, $uid);
                $flash = 'تم إلغاء المصروف ورجّعنا المبلغ لخزينة العهدة.';
            }
            elseif ($action === 'close_custody') {
                $custodyId  = (int) ($_POST['custody_id'] ?? 0);
                $counted    = (float) ($_POST['counted_cash'] ?? 0);
                $date       = $_POST['date'] ?? date('Y-m-d');
                $employeeId = ($_POST['employee_id'] ?? '') !== '' ? (int) $_POST['employee_id'] : null;
                $res = close_custody($custodyId, $counted, $date, $employeeId, $uid);
                $diff = $res['difference'];
                if ($diff < 0) {
                    $flash = $res['on_employee']
                        ? 'تم إقفال العهدة. عجز ' . number_format(abs($diff),2) . ' جنيه سُجّل على الموظف.'
                        : 'تم إقفال العهدة. عجز ' . number_format(abs($diff),2) . ' جنيه تحمّله المحل (تحت الحد).';
                } elseif ($diff > 0) {
                    $flash = 'تم إقفال العهدة. زيادة ' . number_format($diff,2) . ' جنيه دخلت الخزينة.';
                } else {
                    $flash = 'تم إقفال العهدة بدون فرق. ممتاز.';
                }
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
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
    $custody = open_custody_for_branch($branchId);
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
$tolerance = (float) setting('custody_deficit_tolerance', '30');

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
        font-size:14px; font-family:inherit; background:#fff; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 18px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-danger { background:var(--neg); } .btn-danger:hover { background:#c93f3e; }
    .btn-sm { padding:7px 13px; font-size:13px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
    .stat { background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .stat .label { font-size:13px; color:var(--ink-500); margin-bottom:6px; }
    .stat .value { font-size:24px; font-weight:700; color:var(--ink-900); }
    .stat.accent { background:var(--accent); color:#fff; }
    .stat.accent .label { color:rgba(255,255,255,0.85); } .stat.accent .value { color:#fff; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width:820px){ .grid2 { grid-template-columns:1fr; } }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .empty { text-align:center; color:#bbb; padding:24px; font-size:14px; }
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
    .modal { background:#fff; border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
    .btn-cancel:hover { background:var(--line); }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="top-row">
    <div class="branch-select">
        <?php if (empty($u['branch_id']) && $allBranches): ?>
            <form method="get" style="display:inline;">
                <select name="branch" onchange="this.form.submit()">
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
            <?php if (empty($expenses)): ?>
                <div class="empty">لا توجد مصروفات بعد.</div>
            <?php else: ?>
            <table>
                <thead><tr><th>التاريخ</th><th>القيد</th><th>البيان</th><th>المبلغ</th><th style="width:52px;"></th></tr></thead>
                <tbody>
                <?php foreach ($expenses as $ex): ?>
                    <tr>
                        <td><?= e($ex['expense_date']) ?></td>
                        <td><?= !empty($ex['entry_code_name']) ? '<span style="padding:2px 9px;border-radius:20px;font-size:12px;color:var(--accent-hover);background:var(--bg);">'.e($ex['entry_code_name']).'</span>' : '<span style="color:var(--ink-300);">—</span>' ?></td>
                        <td><?= e($ex['description']) ?></td>
                        <td style="font-weight:500;"><?= money_fmt((float)$ex['amount']) ?></td>
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
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            عُدّ الكاش الموجود فعلاً بالفئات. النظام هيحسب الفرق مع آخر المدة المفروض
            (<?= number_format($closing,2) ?> جنيه)، وعند الاستلام يصفّر العهدة ويبدأ فترة جديدة.
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
                        <td><?= $d ?> جنيه</td>
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
                    <label>الموظف المسؤول (لو فيه عجز فوق <?= number_format($tolerance,0) ?>ج)</label>
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
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('تأكيد إقفال العهدة واستلام الباقي؟ لا يمكن التراجع.')">
                    <i class="ti ti-lock"></i> إقفال واستلام
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Modal: إذن صرف -->
<?php if ($canIssue && $branchId): ?>
<div class="modal-bg" id="orderModal">
    <div class="modal">
        <h3>إصدار إذن صرف</h3>
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
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" aria-label="ملاحظات"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">إصدار الإذن</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('orderModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?= acctree_data(true) ?>
<script src="assets/acctree.js?v=2"></script>
<script>
    function openModal(id){ document.getElementById(id).classList.add('open'); }
    function closeModal(id){ document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    const expected = <?= json_encode($custody ? (float)$custody['closing_balance'] : 0) ?>;
    const tolerance = <?= json_encode($tolerance) ?>;

    function recalc() {
        let total = 0;
        document.querySelectorAll('.denom-count').forEach(inp => {
            const d = parseFloat(inp.dataset.denom);
            const c = parseInt(inp.value) || 0;
            const sub = d * c;
            total += sub;
            document.getElementById('sub_' + d).textContent = sub.toFixed(2);
        });
        document.getElementById('countedCash').value = total.toFixed(2);

        const diff = total - expected;
        const box = document.getElementById('countResult');
        box.style.display = 'block';
        if (Math.abs(diff) < 0.001) {
            box.className = 'count-result ok';
            box.textContent = 'الفعلي ' + total.toFixed(2) + ' = المفروض. مفيش فرق ✓';
        } else if (diff < 0) {
            const def = Math.abs(diff);
            const who = def >= tolerance ? ' (يُسجّل على الموظف)' : ' (يتحمّله المحل)';
            box.className = 'count-result def';
            box.textContent = 'عجز ' + def.toFixed(2) + ' جنيه' + who;
        } else {
            box.className = 'count-result sur';
            box.textContent = 'زيادة ' + diff.toFixed(2) + ' جنيه (تدخل الخزينة)';
        }
    }
    recalc();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
