<?php
/**
 * suppliers.php — الموردون
 * نظام الفجالة ستور المالي
 *
 * - فاتورة يدوي (هيدر): المورد + الصافي + الإجمالي + كاش/آجل.
 *   كاش → تطلع من الخزينة فوراً. آجل → مستحقة + جدول دفعات.
 * - سداد دفعات على الفواتير الآجلة (تطلع من الخزينة).
 * - عرض فواتير الاستلامات المربوطة (الضغط على رقمها يفتحها في الاستلامات).
 */
require_once __DIR__ . '/auth.php';
require_permission('suppliers');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = $_GET['tab'] ?? 'invoices';

// خزائن الدفع (كاش/فيزا... المركزية)
$payTreasuries = $pdo->query(
    "SELECT id, name, type FROM treasuries WHERE branch_id IS NULL AND is_active=1
     ORDER BY FIELD(type,'cash','main','visa','instapay','card')"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_invoice') {
                $supplierName = trim($_POST['supplier_name'] ?? '');
                $net   = (float) ($_POST['net_amount'] ?? 0);
                $total = (float) ($_POST['total_amount'] ?? 0);
                $payType = $_POST['pay_type'] ?? 'credit';
                $date  = $_POST['invoice_date'] ?? date('Y-m-d');
                $invNo = trim($_POST['invoice_no'] ?? '');
                $treasuryId = $payType === 'cash' ? (int) ($_POST['treasury_id'] ?? 0) : null;
                add_supplier_invoice($supplierName, $net, $total, $payType, $date,
                    $treasuryId ?: null, $uid, $invNo ?: null);
                $flash = $payType === 'cash'
                    ? 'تم تسجيل الفاتورة وسدادها من الخزينة.'
                    : 'تم تسجيل الفاتورة الآجلة.';
                $tab = 'invoices';
            }
            elseif ($action === 'pay') {
                $invoiceId  = (int) ($_POST['invoice_id'] ?? 0);
                $amount     = (float) ($_POST['amount'] ?? 0);
                $treasuryId = (int) ($_POST['treasury_id'] ?? 0);
                $date       = $_POST['date'] ?? date('Y-m-d');
                $notes      = trim($_POST['notes'] ?? '');
                pay_supplier_invoice($invoiceId, $amount, $treasuryId, $date, $notes ?: null, $uid);
                $flash = 'تم تسجيل الدفعة.';
                $tab = 'invoices';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== جلب البيانات =====
// الفواتير اليدوي (من supplier_invoices)
$manualInvoices = $pdo->query(
    "SELECT si.*, s.name AS supplier_name
     FROM supplier_invoices si
     JOIN suppliers s ON s.id = si.supplier_id
     ORDER BY si.id DESC LIMIT 100"
)->fetchAll();

// فواتير الاستلامات المربوطة (من invoice_distribution)
$receivedInvoices = $pdo->query(
    "SELECT d.*, s.name AS supplier_name
     FROM invoice_distribution d
     LEFT JOIN suppliers s ON s.id = d.supplier_id
     ORDER BY d.id DESC LIMIT 100"
)->fetchAll();

// أرصدة الموردين
$suppliers = $pdo->query(
    'SELECT * FROM suppliers WHERE is_active=1 ORDER BY balance DESC, name'
)->fetchAll();

$statusLabels = [
    'unpaid'  => ['مستحقة', 'var(--neg)', 'var(--neg-soft)'],
    'partial' => ['جزئية', 'var(--warn)', 'var(--warn-soft)'],
    'paid'    => ['مدفوعة', 'var(--pos)', 'var(--pos-soft)'],
];

$page_title    = 'الموردون';
$active_module = 'suppliers';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab { padding:10px 20px; border-radius:10px; text-decoration:none; color:var(--accent-hover); background:#fff;
        font-size:14px; font-weight:500; }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; }
    .btn-ghost { background:var(--bg); color:var(--accent-hover); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .inv-link { color:var(--accent); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:#bbb; padding:30px; font-size:14px; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:460px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent); background:#fff; }
    .pay-type-row { display:flex; gap:10px; }
    .pay-opt { flex:1; }
    .pay-opt input { display:none; }
    .pay-opt label { display:block; text-align:center; padding:11px; border:1.5px solid var(--line);
        border-radius:9px; cursor:pointer; font-size:14px; color:var(--ink-700); }
    .pay-opt input:checked + label { border-color:var(--accent); background:var(--bg); color:var(--accent-hover); font-weight:600; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tabs">
    <a href="?tab=invoices" class="tab <?= $tab==='invoices'?'active':'' ?>">الفواتير اليدوي</a>
    <a href="?tab=received" class="tab <?= $tab==='received'?'active':'' ?>">فواتير الاستلامات</a>
    <a href="?tab=balances" class="tab <?= $tab==='balances'?'active':'' ?>">أرصدة الموردين</a>
</div>

<?php if ($tab === 'invoices'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($manualInvoices) ?> فاتورة</span>
        <button class="btn" onclick="openInvoice()"><i class="ti ti-plus"></i> فاتورة جديدة</button>
    </div>
    <div class="card">
        <?php if (empty($manualInvoices)): ?>
            <div class="empty">لا توجد فواتير يدوي بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>المورد</th><th>رقم</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحالة</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($manualInvoices as $inv): ?>
                <?php
                    $remaining = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                    $sl = $statusLabels[$inv['status']] ?? ['—','#666','#eee'];
                ?>
                <tr>
                    <td><?= e($inv['invoice_date']) ?></td>
                    <td style="font-weight:500;"><?= e($inv['supplier_name']) ?></td>
                    <td><?= e($inv['invoice_no'] ?? '—') ?></td>
                    <td><?= number_format((float)$inv['total_amount'],2) ?></td>
                    <td><?= number_format((float)$inv['paid_amount'],2) ?></td>
                    <td style="font-weight:500;"><?= number_format($remaining,2) ?></td>
                    <td><span class="badge" style="color:<?= $sl[1] ?>;background:<?= $sl[2] ?>;"><?= e($sl[0]) ?></span></td>
                    <td>
                        <?php if ($inv['status'] !== 'paid'): ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openPay(<?= (int)$inv['id'] ?>, <?= $remaining ?>, <?= json_encode($inv["supplier_name"], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>سداد</button>
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

    <!-- Modal: فاتورة جديدة -->
    <div class="modal-bg" id="invoiceModal">
        <div class="modal">
            <h3>فاتورة مورد جديدة</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_invoice">
                <div class="form-row"><label>اسم المورد *</label><input type="text" name="supplier_name" required aria-label="اسم المورد"></div>
                <div class="form-row"><label>رقم الفاتورة</label><input type="text" name="invoice_no" aria-label="رقم الفاتورة"></div>
                <div class="form-row"><label>الصافي</label><input type="number" step="0.01" min="0" name="net_amount" value="0" aria-label="الصافي"></div>
                <div class="form-row"><label>الإجمالي *</label><input type="number" step="0.01" min="0.01" name="total_amount" required aria-label="الإجمالي"></div>
                <div class="form-row"><label for="paySupplier">التاريخ</label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-row">
                    <label>نوع الدفع</label>
                    <div class="pay-type-row">
                        <div class="pay-opt">
                            <input type="radio" name="pay_type" id="pt_credit" value="credit" checked onchange="toggleTreasury()">
                            <label for="pt_credit">آجل</label>
                        </div>
                        <div class="pay-opt">
                            <input type="radio" name="pay_type" id="pt_cash" value="cash" onchange="toggleTreasury()">
                            <label for="pt_cash">كاش</label>
                        </div>
                    </div>
                </div>
                <div class="form-row" id="treasuryRow" style="display:none;">
                    <label>خزينة الدفع *</label>
                    <select name="treasury_id">
                        <?php foreach ($payTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeM('invoiceModal')">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: سداد -->
    <div class="modal-bg" id="payModal">
        <div class="modal">
            <h3>سداد دفعة</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="invoice_id" id="payInvoiceId" value="">
                <div class="form-row"><label>المورد</label><input type="text" id="paySupplier" readonly></div>
                <div class="form-row"><label for="payAmount">المبلغ * (المتبقي: <span id="payRemaining">0</span>)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="payAmount" required></div>
                <div class="form-row">
                    <label>خزينة الدفع *</label>
                    <select name="treasury_id" required>
                        <?php foreach ($payTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" aria-label="ملاحظات"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn">تسجيل الدفعة</button>
                    <button type="button" class="btn btn-cancel" onclick="closeM('payModal')">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'received'): ?>
    <div class="card">
        <div class="section-title">فواتير الاستلامات (اضغط رقم الفاتورة لفتحها في الاستلامات)</div>
        <?php if (empty($receivedInvoices)): ?>
            <div class="empty">لا توجد فواتير استلامات بعد. تظهر هنا تلقائياً عند فتحها في الاستلامات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>المورد</th><th>رقم الفاتورة</th><th>الإجمالي</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($receivedInvoices as $d): ?>
                <tr>
                    <td><?= e($d['dist_date']) ?></td>
                    <td style="font-weight:500;"><?= e($d['supplier_name'] ?? '—') ?></td>
                    <td><a class="inv-link" href="invoices.php?invoice=<?= e($d['invoice_no']) ?>"><?= e($d['invoice_no']) ?></a></td>
                    <td><?= number_format((float)$d['total_amount'],2) ?></td>
                    <td style="font-size:13px;color:var(--ink-500);"><?= e($d['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php else: // balances ?>
    <div class="card">
        <div class="section-title">أرصدة الموردين (المستحق عليهم)</div>
        <?php if (empty($suppliers)): ?>
            <div class="empty">لا يوجد موردون بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>المورد</th><th>التليفون</th><th>الرصيد المستحق</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($s['name']) ?></td>
                    <td><?= e($s['phone'] ?? '—') ?></td>
                    <td style="font-weight:600;color:<?= $s['balance']>0?'var(--neg)':'var(--pos)' ?>;">
                        <?= number_format((float)$s['balance'],2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
    function openM(id){ document.getElementById(id).classList.add('open'); }
    function closeM(id){ document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    function openInvoice(){ toggleTreasury(); openM('invoiceModal'); }

    function toggleTreasury(){
        const cash = document.getElementById('pt_cash').checked;
        const row = document.getElementById('treasuryRow');
        row.style.display = cash ? 'block' : 'none';
        row.querySelector('select').required = cash;
    }

    function openPay(id, remaining, supplier){
        document.getElementById('payInvoiceId').value = id;
        document.getElementById('paySupplier').value = supplier;
        document.getElementById('payRemaining').textContent = remaining.toFixed(2);
        document.getElementById('payAmount').value = remaining.toFixed(2);
        document.getElementById('payAmount').max = remaining;
        openM('payModal');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
