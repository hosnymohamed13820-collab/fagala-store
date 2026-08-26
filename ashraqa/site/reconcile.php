<?php
/**
 * reconcile.php — التسويات
 * نظام الفجالة ستور المالي
 *
 * - مقارنة الرصيد الدفتري لكل خزينة بالرصيد الفعلي المعدود.
 * - تسجيل تسوية: لو فيه فرق، يتصحّح الرصيد بحركة في كشف الحساب.
 * - سجل التسويات السابقة.
 * كل حركة تمر من finance.php ومقترنة باليوزر والوقت.
 */
require_once __DIR__ . '/auth.php';
require_permission('reconcile');
require_once __DIR__ . '/finance.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        try {
            $treasuryId = (int) ($_POST['treasury_id'] ?? 0);
            $counted    = (float) ($_POST['counted_balance'] ?? 0);
            $date       = $_POST['date'] ?? date('Y-m-d');
            $notes      = trim($_POST['notes'] ?? '');
            $res = reconcile_treasury($treasuryId, $counted, $date, $notes ?: null, $uid);
            $diff = $res['difference'];
            if (abs($diff) < 0.0001) {
                $flash = 'تمت التسوية: الرصيد مطابق تماماً.';
            } elseif ($diff < 0) {
                $flash = 'تمت التسوية: عجز ' . number_format(abs($diff),2) . ' جنيه صُحّح من الخزينة.';
            } else {
                $flash = 'تمت التسوية: زيادة ' . number_format($diff,2) . ' جنيه أُضيفت للخزينة.';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
}

$treasuries = $pdo->query(
    "SELECT t.*, b.name AS branch_name FROM treasuries t
     LEFT JOIN branches b ON b.id = t.branch_id
     WHERE t.is_active = 1 AND t.type <> 'branch_custody'
     ORDER BY FIELD(t.type,'cash','main','visa','instapay','card'), t.name"
)->fetchAll();

$recons = $pdo->query(
    "SELECT r.*, t.name AS treasury_name, u.full_name AS by_name
     FROM reconciliations r
     JOIN treasuries t ON t.id = r.treasury_id
     LEFT JOIN users u ON u.id = r.created_by
     ORDER BY r.id DESC LIMIT 50"
)->fetchAll();

$typeLabel = ['cash'=>'كاش','visa'=>'فيزا','instapay'=>'إنستا باي','card'=>'كارت','main'=>'رئيسية','branch_custody'=>'عهدة فرع'];

$page_title    = 'التسويات';
$active_module = 'reconcile';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:#e6f7ed; color:#1a7f4b; }
    .flash.error   { background:#fde8e8; color:#c0392b; }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:#1E1B4B; font-weight:600; margin-bottom:14px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid #f0eef9; }
    th { color:#8884a8; font-weight:600; font-size:13px; }
    td { color:#3c3960; }
    .btn { background:#7C6FF7; color:#fff; border:none; padding:8px 16px; border-radius:9px;
        font-size:13px; font-family:inherit; cursor:pointer; }
    .btn:hover { background:#6a5de8; }
    .recon-input { width:120px; padding:8px 10px; border:1.5px solid #e3e0f5; border-radius:8px;
        font-size:14px; text-align:center; font-family:inherit; }
    .diff-pos { color:#1a7f4b; font-weight:600; } .diff-neg { color:#c0392b; font-weight:600; } .diff-zero { color:#8884a8; }
    .empty { text-align:center; color:#bbb; padding:24px; font-size:14px; }
    .hint { color:#8884a8; font-size:13px; margin-bottom:14px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <div class="section-title">تسوية الخزائن</div>
    <p class="hint">اكتب الرصيد الفعلي المعدود لكل خزينة. لو فيه فرق مع الدفتري، النظام بيصحّحه ويسجّل التسوية.</p>
    <table>
        <thead><tr><th>الخزينة</th><th>النوع</th><th>الرصيد الدفتري</th><th>الرصيد الفعلي</th><th>الفرق</th><th>إجراء</th></tr></thead>
        <tbody>
        <?php foreach ($treasuries as $t): ?>
            <tr>
                <td style="font-weight:500;">
                    <?= e($t['name']) ?>
                    <?php if ($t['branch_name']): ?><span style="color:#8884a8;font-size:12px;">(<?= e($t['branch_name']) ?>)</span><?php endif; ?>
                </td>
                <td><?= e($typeLabel[$t['type']] ?? $t['type']) ?></td>
                <td style="font-weight:600;" id="book_<?= (int)$t['id'] ?>" data-book="<?= (float)$t['balance'] ?>">
                    <?= number_format((float)$t['balance'],2) ?>
                </td>
                <td colspan="3">
                    <form method="post" style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="treasury_id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="date" value="<?= date('Y-m-d') ?>">
                        <input type="number" step="0.01" name="counted_balance" class="recon-input"
                               value="<?= (float)$t['balance'] ?>" data-book="<?= (float)$t['balance'] ?>"
                               oninput="showDiff(this)" required>
                        <span class="diff-zero" data-diff="<?= (int)$t['id'] ?>">فرق: 0.00</span>
                        <input type="text" name="notes" placeholder="ملاحظات" style="flex:1;padding:8px 10px;border:1.5px solid #e3e0f5;border-radius:8px;font-family:inherit;font-size:13px;">
                        <button type="submit" class="btn" onclick="return confirm('تأكيد تسوية الخزينة؟')">تسوية</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <div class="section-title">سجل التسويات</div>
    <?php if (empty($recons)): ?>
        <div class="empty">لا توجد تسويات بعد.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>التاريخ</th><th>الخزينة</th><th>الدفتري</th><th>الفعلي</th><th>الفرق</th><th>ملاحظات</th><th>بواسطة</th><th>الوقت</th></tr></thead>
        <tbody>
        <?php foreach ($recons as $r): ?>
            <?php $d = (float)$r['difference']; ?>
            <tr>
                <td><?= e($r['recon_date']) ?></td>
                <td style="font-weight:500;"><?= e($r['treasury_name']) ?></td>
                <td><?= number_format((float)$r['book_balance'],2) ?></td>
                <td><?= number_format((float)$r['counted_balance'],2) ?></td>
                <td class="<?= abs($d)<0.01?'diff-zero':($d<0?'diff-neg':'diff-pos') ?>"><?= number_format($d,2) ?></td>
                <td style="font-size:13px;color:#8884a8;"><?= e($r['notes'] ?? '—') ?></td>
                <td style="color:#8884a8;font-size:13px;"><?= e($r['by_name'] ?? '—') ?></td>
                <td style="color:#a8a4c0;font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($r['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
    function showDiff(input) {
        const book = parseFloat(input.dataset.book) || 0;
        const counted = parseFloat(input.value) || 0;
        const diff = counted - book;
        const id = input.closest('tr').querySelector('[data-diff]');
        id.textContent = 'فرق: ' + diff.toFixed(2);
        id.className = Math.abs(diff) < 0.01 ? 'diff-zero' : (diff < 0 ? 'diff-neg' : 'diff-pos');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
