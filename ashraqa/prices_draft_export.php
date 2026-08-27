<?php
/**
 * prices_draft_export.php — تصدير أسعار تقرير «أصناف بدون سعر بيع»
 * نظام الفجالة ستور المالي
 *
 * نفس صيغة prices_export.php بالحرف — الفرق الوحيد إن المصدر هنا
 * جدول التقرير (item_price_drafts) مش أصناف فاتورة استلام:
 *
 *      كود الصنف + TAB + السعر  (سطر لكل صنف، CRLF، من غير أعمدة زيادة)
 *      8858784830397	15
 *      873999000579	33.5
 *
 * التنسيق بيتعمل بـ fmt_price_out() من receiving_lib.php — نفس الدالة
 * اللي بيستخدمها تصدير الاستلامات، عشان الملفين يطلعوا متطابقين.
 *
 * بيصدّر **كل صفوف التقرير** (مش المعدّل بس).
 */
require_once __DIR__ . '/auth.php';
require_permission('reports');
require_once __DIR__ . '/receiving_lib.php';   // fmt_price_out()
require_once __DIR__ . '/price_draft_lib.php';

$rows = pdft_rows();

if (!$rows) {
    http_response_code(404);
    die('مفيش أصناف في التقرير.');
}

$filename = 'PRICE_NOSALE_' . date('Ymd_Hi') . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

foreach ($rows as $r) {
    $code = trim((string) $r['code']);
    if ($code === '') continue;

    // كود + TAB واحد + السعر — مفيش أعمدة زيادة
    fwrite($out, $code . "\t" . fmt_price_out((float) $r['sale']) . "\r\n");
}

fclose($out);
exit;
