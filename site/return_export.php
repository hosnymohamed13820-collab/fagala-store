<?php
/**
 * return_export.php — تصدير ملف TEXT لمردود الشراء أو فاتورة الفرع بالكامل
 * نظام الفجالة ستور المالي
 *
 * الفورمات (بدون هيدر، سطر لكل صنف بكميته كاملة):
 *   كود_الصنف <TAB> الكمية <TAB> سعر_التكلفة
 *
 * المدخلات: ?invoice=STH_ID&suffix=YY_MM[&cb=COMPANY_BRANCH&dt=DOC_TYPE]
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/item_flags_lib.php';

$invoiceId = $_GET['invoice'] ?? null;
// ⚠️ الـ suffix إجباري: STH_ID بيتكرر بين جدول الفواتير غير المحفوظة
//    (YY_MM) والجداول الشهرية المرحّلة.
$suffix    = trim((string) ($_GET['suffix'] ?? '')) ?: null;
$cb        = isset($_GET['cb']) && $_GET['cb'] !== '' ? (int) $_GET['cb'] : null;
$dt        = isset($_GET['dt']) && $_GET['dt'] !== '' ? (int) $_GET['dt'] : null;

if ($invoiceId === null || $suffix === null) {
    http_response_code(400);
    die('بيانات غير مكتملة.');
}

$invHeader = sqlsrv_fetch_invoice_header($invoiceId, $suffix, $cb, $dt);
if (!$invHeader) {
    http_response_code(404);
    die('الفاتورة غير موجودة.');
}
if ($cb !== null && !isset($invHeader['company_branch'])) {
    $invHeader['company_branch'] = $cb;
}
if ($dt !== null && !isset($invHeader['doc_type'])) {
    $invHeader['doc_type'] = $dt;
}

$isRet    = (int) ($invHeader['doc_type'] ?? 0) === 6;
$isBranch = !grp_is_admin_inv(['company_branch_id' => $invHeader['company_branch'] ?? 0]);

if (!$isRet && !$isBranch) {
    http_response_code(400);
    die('الملف ده للمردود وفواتير الفروع بس — فاتورة شراء الإدارة ليها ملفات الفروع.');
}

// دمج السطور المتكررة لنفس الصنف في سطر واحد بإجمالي الكمية
$rawItems = sqlsrv_fetch_invoice_items($invoiceId, $suffix);
$items    = merge_invoice_items($rawItems);

$qtyByCode  = [];
$costByCode = [];
foreach ($items as $it) {
    $code = trim((string) ($it['item_code'] ?? ''));
    if ($code === '') continue;
    $qty = (float) ($it['quantity'] ?? 0);
    if ($qty > 0) {
        $qtyByCode[$code]  = $qty;
        $costByCode[$code] = (float) ($it['cost_price'] ?? 0);
    }
}

$filtered = ifl_filter_export($qtyByCode);
$kept     = $filtered['kept'];

if (empty($kept)) {
    http_response_code(404);
    die('كل أصناف ' . ($isRet ? 'المردود' : 'الفاتورة') . ' معلّمة «لا ينزل في التقرير» — الملف هيطلع فاضي.');
}

$lines = [];
foreach ($kept as $code => $qty) {
    $cost = $costByCode[$code] ?? 0;
    $qtyOut  = ($qty == (int)$qty) ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    $costOut = rtrim(rtrim(number_format($cost, 3, '.', ''), '0'), '.');
    if ($costOut === '') $costOut = '0';
    $lines[] = $code . "\t" . $qtyOut . "\t" . $costOut;
}
$content = implode("\r\n", $lines) . "\r\n";

$invNo = (string) ($invHeader['inv_no'] ?? '');
if ($isBranch) {
    $cbVal = (int) ($invHeader['company_branch'] ?? 0);
    $typeSlug = $isRet ? 'return' : 'purchase';
    $filename = 'branch' . $cbVal . '_' . $typeSlug . '_' . $invNo . '.txt';
} else {
    $filename = 'return_' . $invNo . '.txt';
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
