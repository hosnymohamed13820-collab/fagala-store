<?php
/**
 * diag_shifts.php — كشف الورديات المتأثرة بمشكلة الصفوف المكرّرة
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  المشكلة: زرار "السهم لتحت" كان بيضيف صف جديد بنسخة من آخر صف —
 *  بما فيها اسم الحقل. يعني الصف 11 كان اسمه visa[10] زي الصف 10
 *  بالظبط. PHP لما بيلاقي اسمين متشابهين بياخد الأخير ويرمي اللي
 *  قبله. النتيجة: أي صف بعد العاشر كان بيبلع اللي قبله.
 *
 *  الصفحة دي بتدوّر على الورديات المشتبه فيها: أي وردية فيها وسيلة
 *  دفع وصلت للصف رقم 10. دي الورديات اللي محتاجة مراجعة يدوية
 *  مقابل إيصالات الفيزا الورقية.
 *
 *  ⚠️ للقراءة فقط — مابتعدّلش أي حاجة.
 *
 *  الاستخدام: ارفعها، افتحها بحساب الأونر، راجع النتايج، وبعدين امسحها.
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!is_owner()) {
    http_response_code(403);
    exit('غير مسموح — الأونر فقط.');
}

$pdo = db();

// الورديات اللي فيها وسيلة دفع وصلت للصف 10 = وصلت للحد الأقصى القديم
$sql = "
    SELECT s.id, s.business_date, s.shift_name, s.branch_id, s.status,
           s.cash_total, s.visa_total, s.wallet_total, s.instapay_total,
           s.system_total, s.difference,
           b.name AS branch_name, u.full_name AS cashier,
           l.method, COUNT(*) AS line_count, MAX(l.line_no) AS max_line
    FROM shift_payment_lines l
    JOIN shifts s ON s.id = l.shift_id
    LEFT JOIN branches b ON b.id = s.branch_id
    LEFT JOIN users u ON u.id = s.user_id
    GROUP BY s.id, l.method
    HAVING MAX(l.line_no) >= 10
    ORDER BY s.business_date DESC, s.id DESC
";
try {
    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $ex) {
    exit('فشل الاستعلام: ' . htmlspecialchars($ex->getMessage()));
}

// تجميع حسب الوردية
$byShift = [];
foreach ($rows as $r) {
    $sid = (int) $r['id'];
    if (!isset($byShift[$sid])) {
        $byShift[$sid] = ['info' => $r, 'methods' => []];
    }
    $byShift[$sid]['methods'][] = $r;
}

$methodLabels = ['visa' => 'فيزا', 'wallet' => 'محفظة', 'instapay' => 'إنستا باي'];

$page_title    = 'كشف الورديات المتأثرة';
$active_module = 'cashier';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .dg-card { background:#fff; border-radius:14px; padding:22px; margin-bottom:18px;
               box-shadow:0 2px 12px rgba(124,111,247,0.05); }
    .dg-warn { background:var(--warn-soft); color:var(--warn); padding:16px 18px;
               border-radius:12px; margin-bottom:18px; line-height:1.9; font-size:14px; }
    .dg-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
    .dg-tbl th, .dg-tbl td { padding:10px 12px; border-bottom:1px solid var(--line-soft); text-align:center; }
    .dg-tbl th { background:var(--line-soft); color:var(--accent-hover); font-weight:600; }
    .dg-tbl tr:hover td { background:rgba(124,111,247,0.04); }
    .dg-neg { color:var(--neg); font-weight:700; }
    .dg-chip { display:inline-block; padding:2px 10px; border-radius:20px; font-size:12px;
               background:var(--neg-soft); color:var(--neg); margin:2px; }
    .dg-empty { text-align:center; color:var(--ink-400); padding:40px; }
</style>

<div class="dg-warn">
    <b>⚠️ إزاي تقرا الجدول ده</b><br>
    دي الورديات اللي وصلت فيها وسيلة دفع للصف رقم ١٠ — يعني الكاشير كان
    محتاج يضيف صفوف زيادة. أي صف بعد العاشر كان بيمسح اللي قبله بسبب
    خطأ في تسمية الصفوف.<br>
    <b>مش كل وردية هنا متأثرة فعلاً</b> — اللي وقف عند ١٠ بالظبط تمام.
    اللي كتب أكتر من ١٠ هو اللي ضاعت منه فلوس.<br>
    <b>العلامة الفاصلة:</b> لو "الفرق" عجز كبير ومش منطقي، دي متأثرة.
    راجعها مقابل إيصالات الفيزا الورقية.
</div>

<div class="dg-card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:14px;">
        الورديات المشتبه فيها (<?= count($byShift) ?>)
    </h3>
    <?php if (empty($byShift)): ?>
        <div class="dg-empty">✅ مفيش أي وردية وصلت للصف ١٠ — يعني المشكلة دي ماأثرتش على أي تقفيل.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="dg-tbl">
            <thead>
                <tr>
                    <th>#</th><th>التاريخ</th><th>الفرع</th><th>الكاشير</th><th>الوردية</th>
                    <th>وسائل وصلت للحد</th>
                    <th>السيستم</th><th>المحصّل</th><th>الفرق المسجّل</th><th>الحالة</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($byShift as $sid => $sh):
                $i = $sh['info'];
                $collected = (float)$i['cash_total'] + (float)$i['visa_total']
                           + (float)$i['wallet_total'] + (float)$i['instapay_total'];
                $diff = (float) $i['difference'];
            ?>
                <tr>
                    <td><?= (int) $sid ?></td>
                    <td><?= e($i['business_date']) ?></td>
                    <td><?= e($i['branch_name'] ?? '—') ?></td>
                    <td><?= e($i['cashier'] ?? '—') ?></td>
                    <td><?= e($i['shift_name'] ?? '—') ?></td>
                    <td>
                        <?php foreach ($sh['methods'] as $m): ?>
                            <span class="dg-chip">
                                <?= e($methodLabels[$m['method']] ?? $m['method']) ?>
                                — <?= (int) $m['line_count'] ?> صف
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= number_format((float)$i['system_total'], 2) ?></td>
                    <td><?= number_format($collected, 2) ?></td>
                    <td class="<?= $diff < -1 ? 'dg-neg' : '' ?>"><?= number_format($diff, 2) ?></td>
                    <td><?= e($i['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="dg-card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:12px;">أكبر ١٥ عجز مسجّل (للمقارنة)</h3>
    <p style="font-size:13px;color:var(--ink-500);margin-bottom:12px;">
        لو وردية هنا وموجودة فوق كمان، دي على الأغلب متأثرة بالمشكلة مش عجز حقيقي.
    </p>
    <?php
    $worst = $pdo->query(
        "SELECT s.id, s.business_date, s.difference, s.system_total,
                b.name AS branch_name, u.full_name AS cashier
         FROM shifts s
         LEFT JOIN branches b ON b.id = s.branch_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.difference < 0
         ORDER BY s.difference ASC LIMIT 15"
    )->fetchAll();
    ?>
    <div style="overflow-x:auto;">
        <table class="dg-tbl">
            <thead><tr><th>#</th><th>التاريخ</th><th>الفرع</th><th>الكاشير</th><th>السيستم</th><th>العجز</th></tr></thead>
            <tbody>
            <?php foreach ($worst as $w): ?>
                <tr>
                    <td><?= (int)$w['id'] ?></td>
                    <td><?= e($w['business_date']) ?></td>
                    <td><?= e($w['branch_name'] ?? '—') ?></td>
                    <td><?= e($w['cashier'] ?? '—') ?></td>
                    <td><?= number_format((float)$w['system_total'], 2) ?></td>
                    <td class="dg-neg"><?= number_format((float)$w['difference'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
