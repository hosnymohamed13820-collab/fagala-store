<?php
/**
 * branch_prices_export.php — تصدير أسعار بيع فرع (الفجالة · دجلة · بونجور · اي بي سي) كملف نصي
 * نسخة من ashraqa_prices_export.php (بريف ٣٧).
 * نظام الفجالة ستور المالي
 *
 * الصيغة: كود الصنف + TAB + السعر (سطر لكل صنف اتوزع للفرع وله سعر بيع)
 * الصنف الذي ليس له سعر لا يكتب في الملف.
 *
 * المدخلات: ?b=fagala&invoice=STH_ID&suffix=26_07
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branch_invoices_lib.php';
// 🔴 الفرع جاي من الرابط (?b=) — والصلاحية على **الفرع اللي في الرابط نفسه**،
//    عشان كاشير الفجالة مايغيّرش ?b=degla ويسحب ملف دجلة (نفس ثغرة branch_export.php).
$BR_KEY = (string) ($_GET['b'] ?? '');
$BR_MAP = br_inv_map();
if (!isset($BR_MAP[$BR_KEY])) {
    http_response_code(404);
    die('فرع غير معروف.');
}
// موديول «فواتير الفروع» بنطاقه (أو الأونر) — «الاستلامات» مالهاش دعوة
br_inv_require((int) $BR_MAP[$BR_KEY]['id']);
$BR_INV = $BR_MAP[$BR_KEY];
require_once __DIR__ . '/receiving_lib.php';

$invoiceId = $_GET['invoice'] ?? null;
$suffix    = $_GET['suffix'] ?? null;

if ($invoiceId === null || $invoiceId === '') {
    http_response_code(400);
    die('لازم تحدد الفاتورة.');
}

$invHeader = sqlsrv_fetch_invoice_header($invoiceId, $suffix);
if (!$invHeader) {
    http_response_code(404);
    die('الفاتورة غير موجودة.');
}
// 🔴 المردودات مابتظهرش لعاشور (doc_type 6 = مردود شراء). الشاشة بتمنعها،
//    والمنع هنا كمان عشان مايبقاش فيه باب جانبي بلينك متكتب بالإيد.
if ((int) ($invHeader['doc_type'] ?? 0) === 6) {
    http_response_code(403);
    die('غير مصرح.');
}

$pdo = db();
$dist = ensure_distribution_record($invHeader, current_user()['id'] ?? null);
$distId = (int) $dist['id'];

// حارس أمان: التحقق من أن الفاتورة فيها توزيع للفرع
$stCheck = $pdo->prepare(
    'SELECT notes AS item_code, SUM(amount) AS qty
       FROM invoice_distribution_lines
      WHERE distribution_id = ? AND branch_id = ?
      GROUP BY notes
      HAVING SUM(amount) > 0'
);
$stCheck->execute([$distId, (int) $BR_INV['id']]);
$ashraqaQtys = [];
foreach ($stCheck as $row) {
    $ashraqaQtys[(string)$row['item_code']] = (float)$row['qty'];
}

if (empty($ashraqaQtys)) {
    http_response_code(403);
    die('غير مصرح.');
}

// أصناف الفاتورة مدمجة
$items = merge_invoice_items(sqlsrv_fetch_invoice_items($invoiceId, $suffix));

// أسعار فرع الفرع
$prices = br_inv_get_prices((int) $BR_INV['id'], $distId);

$safeDocNo = preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']);
$filename  = strtoupper($BR_KEY) . '_PRICE_' . $safeDocNo . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

foreach ($items as $it) {
    $code = trim((string) ($it['item_code'] ?? ''));
    if ($code === '') continue;

    // الأصناف اللي اتوزّعت للفرع بس
    if (empty($ashraqaQtys[$code])) continue;

    // الصنف اللي مالوش سعر الفرع مايتكتبش سطر أصلاً
    if (!isset($prices[$code]) || (float)$prices[$code] <= 0) continue;

    $price = (float) $prices[$code];

    // كود + TAB واحد + السعر — مفيش أعمدة زيادة
    fwrite($out, $code . "\t" . fmt_price_out($price) . "\r\n");
}

fclose($out);
exit;
