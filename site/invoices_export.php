<?php
/**
 * invoices_export.php — تصدير أصناف فاتورة غير محفوظة (إكسيل .xlsx)
 * نظام الفجالة ستور المالي
 *
 * عمود كود الصنف محدّد كنص من الأصل.
 * المدخلات: ?invoice=STH_ID&suffix=YY_MM
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

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

$items = merge_invoice_items(sqlsrv_fetch_invoice_items($invoiceId, $suffix));

$x = new SimpleXlsx('فاتورة ' . $invHeader['inv_no']);

// A=كود نص · B=اسم نص · الباقي أرقام
$x->setColumnTypes(['s', 's', 'n', 'n', 'n']);

$x->addRow(['بيانات الفاتورة'], true);
$x->addRow(['فاتورة رقم', (string) $invHeader['inv_no']]);
$x->addRow(['المورد',     (string) ($invHeader['supplier_name'] ?? '')]);
$x->addRow(['التاريخ', $invHeader['invoice_date']
    ? date('Y-m-d', strtotime((string) $invHeader['invoice_date'])) : '']);
$x->addBlank();

$x->addRow(['كود الصنف', 'اسم الصنف', 'الكمية', 'سعر الوحدة', 'إجمالي الصنف'], true);

$totalQty = 0.0; $totalVal = 0.0;
foreach ($items as $it) {
    $qty  = (float) $it['quantity'];
    $unit = (float) ($it['unit_price'] ?? 0);
    $val  = $qty * $unit;

    $x->addRow([
        (string) $it['item_code'],
        (string) $it['item_name'],
        round($qty, 3),
        round($unit, 2),
        round($val, 2),
    ]);

    $totalQty += $qty;
    $totalVal += $val;
}

$x->addRow(['', 'الإجمالي', round($totalQty, 3), '', round($totalVal, 2)], true);

$x->download('INVOICE_' . preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']) . '.xlsx');
