<?php
/**
 * group_prices_export.php — تصدير أسعار البيع لمجموعة فواتير كاملة
 * نظام الفجالة ستور المالي
 *
 * ملف واحد لكل أصناف المجموعة — الصنف الموجود في أكتر من فاتورة
 * بيطلع **سطر واحد** (group_load بيدمج الأصناف بالكود أصلاً).
 *
 * الصيغة: كود الصنف + TAB + السعر (سطر لكل صنف، من غير أي أعمدة زيادة)
 *   8858784830397	15
 *   873999000579	33.5
 *
 * المدخلات: ?group=ID
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');
require_once __DIR__ . '/group_lib.php';

$groupId = (int) ($_GET['group'] ?? 0);
if ($groupId <= 0) {
    http_response_code(400);
    die('لازم تحدد المجموعة.');
}

$group = group_get($groupId);
if (!$group) {
    http_response_code(404);
    die('المجموعة غير موجودة.');
}

$data  = group_load($groupId);
$items = $data['items'] ?? [];

if (!$items) {
    http_response_code(404);
    die('مفيش أصناف في فواتير المجموعة.');
}

// نفس منطق الشاشة: المخزّن أولاً، والناقص/القديم يتجاب من السيستم
$prices = group_resolve_prices($items);

$filename = 'PRICE_GRP_' . $groupId . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out  = fopen('php://output', 'w');
$seen = [];   // حزام أمان ضد أي تكرار

foreach ($items as $it) {
    $code = trim((string) ($it['item_code'] ?? ''));
    if ($code === '' || isset($seen[$code])) continue;
    $seen[$code] = true;

    $price = (float) ($prices[$code] ?? 0);

    // كود + TAB واحد + السعر — مفيش أعمدة زيادة
    fwrite($out, $code . "\t" . fmt_price_out($price) . "\r\n");
}

fclose($out);
exit;
