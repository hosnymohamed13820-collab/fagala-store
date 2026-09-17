<?php
/**
 * invoice_full_export.php — تصدير فاتورة شراء كاملة (إكسيل .xlsx)
 * نظام الفجالة ستور المالي
 *
 * ملف إكسيل حقيقي — عمود كود الصنف محدّد كنص من الأصل،
 * فالأكواد الطويلة مابتتحولش لصيغة علمية والأصفار البادئة مابتضيعش.
 *
 * المدخلات: ?invoice=STH_ID&suffix=26_07
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');

// الملف بياخد وقت على الفواتير الكبيرة، والجلسة بتفضل مقفولة طول ما هو شغال
// فأي حفظ AJAX للمستخدم نفسه بيستنى في الطابور. الجلسة اتقريت خلاص، فنقفلها.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

$invoiceId = $_GET['invoice'] ?? null;
$suffix    = $_GET['suffix'] ?? null;

if ($invoiceId === null || $invoiceId === '') {
    http_response_code(400);
    die('لازم تحدد الفاتورة.');
}

$pdo = db();

$invHeader = sqlsrv_fetch_invoice_header($invoiceId, $suffix);
if (!$invHeader) {
    http_response_code(404);
    die('الفاتورة غير موجودة.');
}

$items  = merge_invoice_items(sqlsrv_fetch_invoice_items($invoiceId, $suffix));
$dist   = ensure_distribution_record($invHeader, current_user()['id'] ?? null);
$distId = (int) $dist['id'];

$branches = $pdo->query(
    'SELECT id, name, sto_id FROM branches WHERE is_active=1 AND in_receiving=1 ORDER BY name'
)->fetchAll();

$salePrices  = resolve_invoice_sale_prices($distId, $items);
$allBalances = sqlsrv_fetch_item_balances_all(
    array_map(fn($i) => (int) ($i['item_id'] ?? 0), $items)
);

$x = new SimpleXlsx('فاتورة ' . $invHeader['inv_no']);

// ===== أنواع الأعمدة (قبل أي بيانات) =====
//   A=م رقم · B=كود الصنف نص · C=اسم الصنف نص · الباقي أرقام
$types = ['n', 's', 's', 'n', 'n', 'n'];
foreach ($branches as $b) { $types[] = 'n'; $types[] = 'n'; }
array_push($types, 'n', 'n', 'n', 'n');
$x->setColumnTypes($types);

// ===== بيانات الفاتورة =====
$x->addRow(['بيانات الفاتورة'], true);
$x->addRow(['رقم الفاتورة', (string) $invHeader['inv_no']]);
$x->addRow(['المورد',       (string) ($invHeader['supplier_name'] ?? '')]);
$x->addRow(['كود المورد',   (string) ($invHeader['supplier_code'] ?? '')]);
$x->addRow(['التاريخ', $invHeader['invoice_date']
    ? date('Y-m-d', strtotime((string) $invHeader['invoice_date'])) : '']);
$x->addRow(['الإجمالي', round((float) ($invHeader['total_amount'] ?? 0), 2)]);
$x->addRow(['الصافي',   round((float) ($invHeader['net_amount']   ?? 0), 2)]);
$x->addRow(['عدد الأصناف', count($items)]);
$x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
$x->addBlank();

// ===== رأس جدول الأصناف =====
$head = ['م', 'كود الصنف', 'اسم الصنف', 'الكمية', 'سعر الوحدة', 'إجمالي الصنف'];
foreach ($branches as $b) {
    $head[] = (string) $b['name'];
    $head[] = 'رصيد ' . (string) $b['name'];
}
array_push($head, 'الموزّع', 'المتبقي بالمخزن', 'سعر البيع', 'رصيد الإدارة');
$x->addRow($head, true);

// ===== الأصناف =====
$i = 1;
$totQty = 0.0; $totVal = 0.0; $totDist = 0.0; $totRem = 0.0;
$branchSums = [];

foreach ($items as $it) {
    $code = (string) $it['item_code'];
    $iid  = (int) ($it['item_id'] ?? 0);
    $qty  = (float) $it['quantity'];
    $unit = (float) ($it['unit_price'] ?? 0);
    $d    = get_item_distribution($distId, $code);

    $row = [$i++, $code, (string) $it['item_name'],
            round($qty, 3), round($unit, 2), round($qty * $unit, 2)];

    $sum = 0.0;
    foreach ($branches as $b) {
        $bid  = (int) $b['id'];
        $q    = (float) ($d[$bid] ?? 0);
        $sum += $q;
        $branchSums[$bid] = ($branchSums[$bid] ?? 0) + $q;
        $row[] = $q > 0 ? round($q, 3) : '';

        $bsto  = (int) ($b['sto_id'] ?? 0);
        $bbal  = $bsto > 0 ? ($allBalances[$iid][$bsto] ?? null) : null;
        $row[] = $bbal === null ? '' : round((float) $bbal, 3);
    }

    $rem = $qty - $sum;
    $row[] = round($sum, 3);
    $row[] = round($rem, 3);
    $row[] = isset($salePrices[$code]) ? round((float) $salePrices[$code], 3) : '';
    $adminBal = $allBalances[$iid][ADMIN_STO_ID] ?? null;
    $row[] = $adminBal === null ? '' : round((float) $adminBal, 3);

    $x->addRow($row);

    $totQty  += $qty;
    $totVal  += $qty * $unit;
    $totDist += $sum;
    $totRem  += $rem;
}

// ===== الإجماليات =====
$tot = ['', '', 'الإجمالي', round($totQty, 3), '', round($totVal, 2)];
foreach ($branches as $b) {
    $v = (float) ($branchSums[(int) $b['id']] ?? 0);
    $tot[] = $v > 0 ? round($v, 3) : '';
    $tot[] = '';
}
array_push($tot, round($totDist, 3), round($totRem, 3), '', '');
$x->addRow($tot, true);

$x->download('INVOICE_' . preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']) . '.xlsx');
