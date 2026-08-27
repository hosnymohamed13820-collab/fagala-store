<?php
/**
 * discount_export.php — تصدير أسعار ما بعد الخصم
 * نظام الفجالة ستور المالي
 *
 * الصيغة: كود الصنف + TAB + الكمية + TAB + السعر بعد الخصم
 *   8000500234402	24	15.13
 *   6222002205564	30	27.645
 *
 * ⚠️ دقة السعر بتيجي من DISC_PRICE_DP في receiving_lib.php (٥ خانات حالياً)
 *    لأن نظام الشركة بيخزّن تلاتة. الأصفار الزيادة بتتشال، فالسعر
 *    اللي أصلاً بخانتين بيطلع بخانتين زي الأول.
 *
 * المدخلات: ?invoice=STH_ID&suffix=26_07
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');
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

$items = sqlsrv_fetch_invoice_items($invoiceId, $suffix);

// "الاجمالي بعد" اليدوي — نفس اللي اتحسب بيه في الشاشة
$dnetRaw     = trim((string) ($_GET['dnet'] ?? ''));
$netOverride = ($dnetRaw !== '' && is_numeric($dnetRaw) && (float)$dnetRaw > 0)
    ? (float) $dnetRaw : null;

// نسب خصم لكل صنف — نفس صيغة الشاشة "كود:نسبة,كود:نسبة"
$itemPct = [];
$dpctRaw = trim((string) ($_GET['dpct'] ?? ''));
if ($dpctRaw !== '') {
    foreach (explode(',', $dpctRaw) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || strpos($pair, ':') === false) continue;
        [$c, $p] = explode(':', $pair, 2);
        $c = trim($c);
        $p = (float) $p;
        if ($c !== '' && $p > 0 && $p <= 100) $itemPct[$c] = $p;
    }
}

$calc  = calc_invoice_discount($invHeader, $items, $netOverride, $itemPct);

$filename = 'DISCOUNT_' . preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']) . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

foreach ($calc['items'] as $row) {
    $code = trim((string) $row['item_code']);
    if ($code === '') continue;

    // كود + TAB + الكمية + TAB + السعر بعد الخصم
    fwrite($out, $code . "\t"
               . fmt_price_out((float) $row['quantity']) . "\t"
               . fmt_price_out(round((float) $row['price_after'], DISC_PRICE_DP)) . "\r\n");
}

fclose($out);
exit;
