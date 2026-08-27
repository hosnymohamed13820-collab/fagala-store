<?php
/**
 * prices_export.php — تصدير أسعار البيع كملف نصي
 * نظام الفجالة ستور المالي
 *
 * الصيغة: كود الصنف + TAB + السعر  (سطر لكل صنف، من غير أي أعمدة زيادة)
 *   8858784830397	15
 *   873999000579	33.5
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

$items  = merge_invoice_items(sqlsrv_fetch_invoice_items($invoiceId, $suffix));
$dist   = ensure_distribution_record($invHeader, current_user()['id'] ?? null);
$distId = (int) $dist['id'];

$prices = resolve_invoice_sale_prices($distId, $items);

$filename = 'PRICE_' . preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']) . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

foreach ($items as $it) {
    $code = trim((string) $it['item_code']);
    if ($code === '') continue;

    $price = (float) ($prices[$code] ?? 0);

    // كود + TAB واحد + السعر — مفيش أعمدة زيادة
    fwrite($out, $code . "\t" . fmt_price_out($price) . "\r\n");
}

fclose($out);
exit;
