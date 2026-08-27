<?php
/**
 * invoices_recalc.php — إعادة حساب حالات كل الفواتير الموزّعة
 * نظام الفجالة ستور المالي
 *
 * بتتشغّل مرة واحدة بعد ترقية الحالات (upgrade_invoice_status.sql)
 * عشان الفواتير القديمة تاخد حالتها الصح.
 *
 * بعد كده الحالة بتتحدّث تلقائيًا مع كل توزيع/تصدير — مش محتاج تشغّلها تاني
 * إلا لو عدّلت التوزيع مباشرة في الداتابيز.
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');
require_once __DIR__ . '/receiving_lib.php';

header('Content-Type: text/html; charset=utf-8');

$pdo  = db();
$rows = $pdo->query('SELECT id, invoice_no FROM invoice_distribution ORDER BY id')->fetchAll();

echo '<div style="font-family:system-ui;padding:24px;direction:rtl;">';
echo '<h2>إعادة حساب حالات الفواتير</h2>';
echo '<p>عدد التوزيعات: <b>' . count($rows) . '</b></p>';

$counts = ['new' => 0, 'split' => 0, 'done' => 0];
$failed = 0;

foreach ($rows as $r) {
    $distId = (int) $r['id'];
    $invNo  = (string) $r['invoice_no'];

    try {
        // ندوّر على الفاتورة في جداول الشهور عشان نجيب أصنافها
        $items = [];
        foreach (sqlsrv_posted_tables() as $t) {
            $head = SQLSRV_PREFIX . '[' . $t['table'] . ']';
            $q = sqlsrv_query(
                "SELECT TOP 1 " . COL_INV_ID . " AS inv_id FROM {$head}
                 WHERE LTRIM(RTRIM(" . COL_INV_NO . ")) = ?",
                [$invNo]
            );
            if ($q) {
                $items = sqlsrv_fetch_invoice_items($q[0]['inv_id'], $t['suffix']);
                break;
            }
        }

        if (!$items) {
            // مالقيناش أصنافها — نسيبها زي ما هي
            $failed++;
            continue;
        }

        $st = recalc_invoice_status($distId, $items);
        $counts[$st] = ($counts[$st] ?? 0) + 1;

    } catch (Throwable $ex) {
        $failed++;
        error_log('recalc: فشل ' . $invNo . ' — ' . $ex->getMessage());
    }
}

echo '<ul style="line-height:1.9;">';
echo '<li>جديدة: <b>' . $counts['new'] . '</b></li>';
echo '<li>تم التقسيم: <b>' . $counts['split'] . '</b></li>';
echo '<li>تم التوزيع: <b>' . $counts['done'] . '</b></li>';
if ($failed) echo '<li style="color:var(--warn);">تعذّر حسابها: <b>' . $failed . '</b></li>';
echo '</ul>';

echo '<p><a href="invoices.php">رجوع للاستلامات</a></p>';
echo '</div>';
