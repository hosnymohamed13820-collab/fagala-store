<?php
/**
 * diag_lines.php — فحص بنود فاتورة وتكاليفها في المرآة المحلية
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  الغرض: نعرف ليه عمود التكلفة بيطلع أصفار في ملف الفرع.
 *  بيوريك نفس البنود من الجدولين (المرحّلة وغير المحفوظة) عشان
 *  نشوف: التكلفة صفر في نظام الشركة أصلاً، ولا إحنا بنقرا من
 *  الجدول الغلط بسبب تصادم الـ STH_ID.
 *
 *  الاستخدام: diag_lines.php?no=46   (رقم الفاتورة زي ما بيظهر)
 *  ⚠️ للقراءة فقط — امسحها بعد ما تخلص.
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!is_owner()) { http_response_code(403); exit('غير مسموح — الأونر فقط.'); }
require_once __DIR__ . '/receiving_lib.php';

$pdo = db();
$no  = trim((string) ($_GET['no'] ?? ''));

$page_title = 'فحص بنود فاتورة';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .d-card { background:#fff;border-radius:14px;padding:22px;margin-bottom:16px;
              box-shadow:0 2px 12px rgba(124,111,247,.05); }
    .d-tbl { width:100%;border-collapse:collapse;font-size:13px; }
    .d-tbl th,.d-tbl td { padding:8px 10px;border-bottom:1px solid var(--line-soft);text-align:center; }
    .d-tbl th { background:var(--line-soft);color:var(--accent-hover); }
    .d-zero { color:var(--neg);font-weight:700; }
    .d-ok { color:var(--pos);font-weight:700; }
</style>

<div class="d-card">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <label style="font-size:14px;">رقم الفاتورة (زي ما بيظهر في الشاشة):</label>
        <input type="text" name="no" value="<?= e($no) ?>" style="padding:10px 12px;border:1.5px solid var(--line);border-radius:9px;">
        <button class="btn" style="background:var(--accent);color:#fff;border:none;padding:10px 20px;border-radius:9px;cursor:pointer;">افحص</button>
    </form>
</div>

<?php if ($no !== ''): ?>
<?php
// نلاقي الفاتورة في الجدولين بالرقم (doc_no)
$hits = [];
foreach ([['hq_unposted','hq_unposted_lines','غير محفوظة'],
          ['hq_invoices','hq_invoice_lines','مرحّلة']] as [$ht, $lt, $label]) {
    try {
        $st = $pdo->prepare("SELECT sth_id, doc_no, supplier_name FROM $ht WHERE doc_no = ?");
        $st->execute([$no]);
        foreach ($st->fetchAll() as $h) {
            $ls = $pdo->prepare(
                "SELECT item_code, item_name, quantity, unit_price, cost_price
                 FROM $lt WHERE sth_id = ? ORDER BY line_no"
            );
            $ls->execute([(int) $h['sth_id']]);
            $hits[] = ['label'=>$label,'head'=>$h,'lines'=>$ls->fetchAll()];
        }
    } catch (Throwable $ex) { /* جدول مش موجود */ }
}
?>

<?php if (!$hits): ?>
    <div class="d-card">مفيش فاتورة بالرقم ده في المرآة المحلية.</div>
<?php else: foreach ($hits as $h): ?>
    <?php
        $nz = 0; foreach ($h['lines'] as $l) if ((float)$l['cost_price'] > 0) $nz++;
        $tot = count($h['lines']);
    ?>
    <div class="d-card">
        <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:8px;">
            <?= e($h['label']) ?> — فاتورة <?= e($h['head']['doc_no']) ?>
            · STH_ID = <b><?= (int)$h['head']['sth_id'] ?></b>
        </h3>
        <p style="font-size:13.5px;margin-bottom:14px;">
            المورد: <?= e($h['head']['supplier_name'] ?? '—') ?> ·
            البنود: <?= $tot ?> ·
            <span class="<?= $nz > 0 ? 'd-ok' : 'd-zero' ?>">
                <?= $nz ?> منها تكلفتها أكبر من صفر
            </span>
        </p>
        <div style="overflow-x:auto;">
        <table class="d-tbl">
            <thead><tr><th>الكود</th><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>سعر التكلفة</th></tr></thead>
            <tbody>
            <?php foreach ($h['lines'] as $l): ?>
                <tr>
                    <td style="font-family:monospace;"><?= e($l['item_code']) ?></td>
                    <td style="text-align:right;"><?= e($l['item_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format((float)$l['quantity'],3,'.',''),'0'),'.') ?></td>
                    <td><?= number_format((float)$l['unit_price'],2) ?></td>
                    <td class="<?= (float)$l['cost_price'] > 0 ? 'd-ok' : 'd-zero' ?>">
                        <?= number_format((float)$l['cost_price'],2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
