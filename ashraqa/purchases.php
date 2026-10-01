<?php
/**
 * purchases.php — مشتريات ومرتجعات إشراقة
 * نظام الفجالة ستور المالي (موقع إشراقة)
 *
 * عرض فواتير الشراء (113) ومرتجعات الشراء (115) لإشراقة
 * قراءة فقط من مرآة ashraqa_purchases / ashraqa_purchase_lines.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('ashraqa_purchases');

$pdo = db();

$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])) ? (string)$_GET['from'] : '2026-07-01';
$to   = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])) ? (string)$_GET['to'] : date('Y-m-d');

$docParam = trim((string)($_GET['doc'] ?? ''));
$ashraqaDetailMode = false;
$ashDoc = null;
$ashLines = [];
$ashDocNotFound = false;

if ($docParam !== '') {
    $ashraqaDetailMode = true;
    if (!preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)$/', $docParam, $dm)) {
        $ashDocNotFound = true;
    } else {
        $sstt   = (int)$dm[1];
        $brch   = (int)$dm[2];
        $fy     = (int)$dm[3];
        $addDoc = (int)$dm[4];

        // استعلام ١: رأس الفاتورة
        $stDoc = $pdo->prepare("SELECT sstt_id, brch_id, fy_no, add_doc, doc_date, supplier_id, supplier_name,
                                       net_amount, total_amount, cancelled, notes, supplier_inv_no
                                FROM ashraqa_purchases
                                WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                                LIMIT 1");
        $stDoc->execute([$sstt, $brch, $fy, $addDoc]);
        $ashDoc = $stDoc->fetch(PDO::FETCH_ASSOC);

        if (!$ashDoc) {
            $ashDocNotFound = true;
        } else {
            // استعلام ٢: سطور الفاتورة
            $stLines = $pdo->prepare("SELECT line_no, item_id, code, barcodes, item_name, unit_q, quantity, unit_price, line_total
                                      FROM ashraqa_purchase_lines
                                      WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                                      ORDER BY line_no ASC");
            $stLines->execute([$sstt, $brch, $fy, $addDoc]);
            $ashLines = $stLines->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} else {
    // استعلام الموردين للفلتر
    $stSupps = $pdo->query("SELECT DISTINCT supplier_id, supplier_name
                            FROM ashraqa_purchases
                            WHERE supplier_id IS NOT NULL
                            ORDER BY supplier_name ASC");
    $ashSuppliers = $stSupps->fetchAll(PDO::FETCH_ASSOC);

    $ashSstt = isset($_GET['sstt']) ? (int)$_GET['sstt'] : 0;
    $ashSupplier = isset($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
    $ashSearch = trim((string)($_GET['q'] ?? ''));
    $ashShowCancelled = !empty($_GET['show_cancelled']);

    $ashWhere = ["p.doc_date >= ? AND p.doc_date <= ?"];
    $ashParams = [$from, $to];

    if ($ashSstt === 113 || $ashSstt === 115) {
        $ashWhere[] = "p.sstt_id = ?";
        $ashParams[] = $ashSstt;
    }
    if ($ashSupplier > 0) {
        $ashWhere[] = "p.supplier_id = ?";
        $ashParams[] = $ashSupplier;
    }
    if ($ashSearch !== '') {
        $ashWhere[] = "(p.notes LIKE ? OR CAST(p.add_doc AS CHAR) LIKE ?)";
        $ashParams[] = '%' . $ashSearch . '%';
        $ashParams[] = '%' . $ashSearch . '%';
    }
    if (!$ashShowCancelled) {
        $ashWhere[] = "p.cancelled = 0";
    }

    $whereStr = implode(' AND ', $ashWhere);

    // استعلام مجمع لقائمة فواتير إشراقة بـ LEFT JOIN
    $sqlAshList = "SELECT p.sstt_id, p.brch_id, p.fy_no, p.add_doc,
                          p.doc_date, p.supplier_id, p.supplier_name,
                          p.net_amount, p.cancelled, p.notes,
                          COUNT(l.line_no) AS items_count,
                          COALESCE(SUM(l.quantity * l.unit_q), 0) AS total_pieces
                   FROM ashraqa_purchases p
                   LEFT JOIN ashraqa_purchase_lines l
                     ON l.sstt_id = p.sstt_id AND l.brch_id = p.brch_id AND l.fy_no = p.fy_no AND l.add_doc = p.add_doc
                   WHERE $whereStr
                   GROUP BY p.sstt_id, p.brch_id, p.fy_no, p.add_doc
                   ORDER BY p.doc_date DESC, p.add_doc DESC";
    $stAshList = $pdo->prepare($sqlAshList);
    $stAshList->execute($ashParams);
    $ashInvoices = $stAshList->fetchAll(PDO::FETCH_ASSOC);

    $totAshBuy = 0.0;
    $totAshRet = 0.0;
    foreach ($ashInvoices as $ai) {
        if ((int)$ai['cancelled'] === 0) {
            if ((int)$ai['sstt_id'] === 113) {
                $totAshBuy += (float)$ai['net_amount'];
            } elseif ((int)$ai['sstt_id'] === 115) {
                $totAshRet += (float)$ai['net_amount'];
            }
        }
    }
}

$page_title    = 'مشتريات ومرتجعات إشراقة';
$active_module = 'ashraqa_purchases';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-top:20px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line); border-radius:12px; padding:14px 16px; }
    .sum-card .label { font-size:12px; color:var(--ink-500); margin-bottom:4px; font-weight:500; }
    .sum-card .val { font-size:18px; font-weight:700; color:var(--ink-900); }
    .filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filter-form label { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-700); font-weight:500; }
    .filter-form input[type="date"], .filter-form input[type="text"], .filter-form select { padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:var(--surface); color:var(--ink-900); }
    .recon-row { cursor:pointer; transition:background 0.15s ease; }
    .recon-row:hover { background:var(--line-soft); }
</style>

<?php if ($ashraqaDetailMode): ?>
    <!-- ========================================================= -->
    <!-- تفاصيل الفاتورة                                          -->
    <!-- ========================================================= -->
    <div style="margin-bottom:14px;">
        <a href="purchases.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-sm btn-ghost">
            <i class="ti ti-arrow-right"></i> رجوع لقائمة مشتريات إشراقة
        </a>
    </div>

    <?php if ($ashDocNotFound || !$ashDoc): ?>
        <div class="card empty" style="text-align:center;padding:30px;color:var(--neg);font-size:15px;font-weight:600;">
            الفاتورة مش موجودة
        </div>
    <?php else: ?>
        <div class="card" style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                <div>
                    <span class="section-title" style="margin-bottom:4px;font-size:17px;">
                        فاتورة <?= (int)$ashDoc['sstt_id'] === 113 ? 'شراء' : 'مرتجع شراء' ?> رقم <?= e((string)$ashDoc['add_doc']) ?>
                        <?php if ((int)$ashDoc['cancelled'] === 1): ?>
                            <span style="font-size:12px;background:var(--neg-soft);color:var(--neg);padding:2px 8px;border-radius:6px;font-weight:600;margin-right:8px;">ملغية</span>
                        <?php endif; ?>
                    </span>
                    <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                        التاريخ: <?= e((string)$ashDoc['doc_date']) ?> · المورد: <?= e((string)$ashDoc['supplier_name']) ?> (<?= (int)$ashDoc['supplier_id'] ?>)
                        <?php if (!empty($ashDoc['notes'])): ?>
                            · ملاحظات: <?= e((string)$ashDoc['notes']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:18px;font-weight:700;color:var(--ink-900);direction:ltr;text-align:right;">
                    <?= number_format((float)$ashDoc['net_amount'], 2) ?>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>الصنف عندهم</th>
                            <th>الباركود</th>
                            <th>الكمية</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ashLines as $ln): ?>
                            <?php
                            $bcs = !empty($ln['barcodes']) ? explode('|', (string)$ln['barcodes']) : [];
                            $firstBc = trim($bcs[0] ?? '');
                            $uq = (float)$ln['unit_q'];
                            $q  = (float)$ln['quantity'];
                            ?>
                            <tr>
                                <td><?= (int)$ln['line_no'] ?></td>
                                <td style="font-weight:600;"><?= e((string)$ln['item_name']) ?></td>
                                <td style="direction:ltr;text-align:right;font-family:monospace;"><?= e((string)$firstBc) ?></td>
                                <td>
                                    <?php if (abs($uq - 1.0) >= 0.001 && $uq > 0.0001): ?>
                                        <?= number_format($q, 2) ?> × <?= number_format($uq, 2) ?> = <?= number_format($q * $uq, 2) ?> قطعة
                                    <?php else: ?>
                                        <?= number_format($q, 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="direction:ltr;text-align:right;"><?= number_format((float)$ln['unit_price'], 2) ?></td>
                                <td style="direction:ltr;text-align:right;font-weight:600;"><?= number_format((float)$ln['line_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ========================================================= -->
    <!-- قائمة الفواتير                                           -->
    <!-- ========================================================= -->
    <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <span class="section-title" style="margin-bottom:0;">مشتريات ومرتجعات إشراقة</span>
            <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                الفترة من <?= e((string)$from) ?> حتى <?= e((string)$to) ?>
            </div>
        </div>
        <form method="get" class="filter-form" style="margin-bottom:0;">
            <label>النوع
                <select name="sstt" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <option value="113" <?= $ashSstt === 113 ? 'selected' : '' ?>>شراء 113</option>
                    <option value="115" <?= $ashSstt === 115 ? 'selected' : '' ?>>مرتجع شراء 115</option>
                </select>
            </label>
            <label>المورد
                <select name="supplier" onchange="this.form.submit()">
                    <option value="0">كل الموردين</option>
                    <?php foreach ($ashSuppliers as $sp): ?>
                        <option value="<?= (int)$sp['supplier_id'] ?>" <?= $ashSupplier === (int)$sp['supplier_id'] ? 'selected' : '' ?>><?= e((string)$sp['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>بحث <input type="text" name="q" value="<?= e($ashSearch) ?>" placeholder="رقم الفاتورة أو الملاحظات"></label>
            <label>من <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
            <label>إلى <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
            <label style="cursor:pointer;">
                <input type="checkbox" name="show_cancelled" value="1" <?= $ashShowCancelled ? 'checked' : '' ?> onchange="this.form.submit()"> اعرض الملغي
            </label>
        </form>
    </div>

    <!-- كروت إحصائية -->
    <div class="sum-cards" style="margin-top:0;margin-bottom:18px;">
        <div class="sum-card">
            <div class="label">عدد الفواتير</div>
            <div class="val"><?= count($ashInvoices) ?></div>
        </div>
        <div class="sum-card" style="background:var(--pos-soft);border-color:var(--pos);">
            <div class="label" style="color:var(--pos);">إجمالي الشراء</div>
            <div class="val" style="color:var(--pos);direction:ltr;text-align:right;"><?= number_format($totAshBuy, 2) ?></div>
        </div>
        <div class="sum-card" style="background:var(--neg-soft);border-color:var(--neg);">
            <div class="label" style="color:var(--neg);">إجمالي المرتجع</div>
            <div class="val" style="color:var(--neg);direction:ltr;text-align:right;"><?= number_format($totAshRet, 2) ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>النوع</th>
                        <th>التاريخ</th>
                        <th>المورد</th>
                        <th>الملاحظات</th>
                        <th>عدد الأصناف</th>
                        <th>إجمالي القطع</th>
                        <th>الصافي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ashInvoices)): ?>
                        <tr><td colspan="8" class="empty">لا توجد فواتير مطابقة للبحث</td></tr>
                    <?php else: ?>
                        <?php foreach ($ashInvoices as $ai): ?>
                            <?php
                            $rowDocKey = $ai['sstt_id'] . '-' . $ai['brch_id'] . '-' . $ai['fy_no'] . '-' . $ai['add_doc'];
                            $rowUrl = 'purchases.php?doc=' . urlencode($rowDocKey) . '&from=' . urlencode($from) . '&to=' . urlencode($to);
                            $isCanc = ((int)$ai['cancelled'] === 1);
                            ?>
                            <tr class="recon-row" onclick="window.location='<?= $rowUrl ?>'" style="<?= $isCanc ? 'opacity:0.55;' : '' ?>">
                                <td style="font-weight:700;">
                                    <a href="<?= $rowUrl ?>" class="inv-link" onclick="event.stopPropagation();">
                                        <?= e((string)$ai['add_doc']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= (int)$ai['sstt_id'] === 113 ? 'شراء' : 'مرتجع شراء' ?>
                                    <?php if ($isCanc): ?>
                                        <span style="font-size:11px;background:var(--line-soft);color:var(--ink-500);padding:1px 6px;border-radius:4px;margin-right:4px;">ملغية</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string)$ai['doc_date']) ?></td>
                                <td><?= e((string)$ai['supplier_name']) ?></td>
                                <td style="color:var(--ink-600);font-size:13px;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= e((string)$ai['notes']) ?>">
                                    <?= e((string)$ai['notes']) ?>
                                </td>
                                <td><?= (int)$ai['items_count'] ?></td>
                                <td style="direction:ltr;text-align:right;"><?= number_format((float)$ai['total_pieces'], 2) ?></td>
                                <td style="font-weight:700;direction:ltr;text-align:right;">
                                    <?= number_format((float)$ai['net_amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
