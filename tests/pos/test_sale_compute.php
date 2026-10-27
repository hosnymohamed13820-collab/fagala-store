<?php
declare(strict_types=1);

require_once __DIR__ . '/../../site/sale_lib.php';

$checks = 0;
function pos_assert(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function pos_throws(callable $fn, string $contains): void
{
    try { $fn(); } catch (Throwable $e) {
        pos_assert(str_contains($e->getMessage(), $contains), 'رسالة الخطأ مختلفة: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('كان لازم يحصل خطأ: '.$contains);
}

$calc = sale_compute([
    ['qty'=>3, 'unit_price'=>15, 'line_discount'=>5],
    ['qty'=>1, 'unit_price'=>40, 'line_discount'=>0],
], ['type'=>'percent', 'value'=>10]);
pos_assert($calc['gross'] === 85.0, 'gross');
pos_assert($calc['line_discount'] === 5.0, 'line discount');
pos_assert($calc['invoice_discount'] === 8.0, 'invoice discount');
pos_assert($calc['net'] === 72.0, 'net');

$p = sale_validate_payments([['method'=>'cash','amount'=>100]], 72, 3);
pos_assert($p['change_due'] === 28.0, 'cash change');
$p = sale_validate_payments([['method'=>'visa','amount'=>50],['method'=>'cash','amount'=>30]], 72, 3);
pos_assert($p['change_due'] === 8.0, 'split change');

pos_throws(fn()=>sale_compute([['qty'=>1,'unit_price'=>10,'line_discount'=>11]]), 'أكبر من قيمة السطر');
pos_throws(fn()=>sale_validate_payments([
    ['method'=>'cash','amount'=>20],['method'=>'cash','amount'=>20],
    ['method'=>'cash','amount'=>20],['method'=>'cash','amount'=>20],
],72,3), 'أقصى عدد');
pos_throws(fn()=>sale_validate_payments([['method'=>'cash','amount'=>-1]],72,3), 'أكبر من صفر');
pos_throws(fn()=>sale_validate_payments([['method'=>'visa','amount'=>80]],72,3), 'مينفعش يزيدوا');
pos_throws(fn()=>sale_validate_payments([['method'=>'cash','amount'=>70]],72,3), 'أقل من الصافي');

// بريف ٢٤٢: حساب المرتجعات والحالات ٢ و٣ و٤
$origDoc = ['gross' => 85.0, 'line_discount' => 5.0, 'invoice_discount' => 8.0, 'net' => 72.0];
$origLinesById = [
    101 => ['id' => 101, 'line_no' => 1, 'item_id' => 1, 'code' => 'A', 'unit_name' => 'قطعة', 'unit_factor' => 1.0, 'qty' => 3.0, 'unit_price' => 15.0, 'line_discount' => 5.0, 'net' => 40.0, 'unit_cost' => 10.0],
    102 => ['id' => 102, 'line_no' => 2, 'item_id' => 2, 'code' => 'B', 'unit_name' => 'قطعة', 'unit_factor' => 1.0, 'qty' => 1.0, 'unit_price' => 40.0, 'line_discount' => 0.0, 'net' => 40.0, 'unit_cost' => 25.0],
];

// حالة ٢: قطعة واحدة من السطر الأول = ١٢.٠٠ بالظبط
$ret1 = sale_calculate_return_lines($origDoc, $origLinesById, [], [
    ['ref_line_id' => 101, 'qty' => 1.0],
]);
pos_assert($ret1['total_net'] === 12.0, 'حالة ٢: مرتجع قطعة واحدة = ١٢.٠٠');

// حالة ٣: الفاتورة كلها على ٣ مرتجعات والمجموع ٧٢.٠٠ بالظبط
$prevReturns = [
    101 => ['qty' => 1.0, 'net' => $ret1['total_net']],
];
$ret2 = sale_calculate_return_lines($origDoc, $origLinesById, $prevReturns, [
    ['ref_line_id' => 101, 'qty' => 1.0],
]);
pos_assert($ret2['total_net'] === 12.0, 'حالة ٣ - المرتجع الثاني = ١٢.٠٠');

$prevReturns[101]['qty'] = 2.0;
$prevReturns[101]['net'] = 24.0;
$ret3 = sale_calculate_return_lines($origDoc, $origLinesById, $prevReturns, [
    ['ref_line_id' => 101, 'qty' => 1.0],
    ['ref_line_id' => 102, 'qty' => 1.0],
]);
pos_assert($ret3['total_net'] === 48.0, 'حالة ٣ - المرتجع الثالث = ٤٨.٠٠');
$total3Returns = round($ret1['total_net'] + $ret2['total_net'] + $ret3['total_net'], 2);
pos_assert($total3Returns === 72.0, 'حالة ٣: مجموع ٣ مرتجعات = ٧٢.٠٠ بالظبط');

// حالة ٤: مرتجع رابع بعد ما الفاتورة رجعت كلها ← 409
$prevReturns[101]['qty'] = 3.0;
$prevReturns[101]['net'] = 36.0;
$prevReturns[102] = ['qty' => 1.0, 'net' => 36.0];
pos_throws(function () use ($origDoc, $origLinesById, $prevReturns) {
    sale_calculate_return_lines($origDoc, $origLinesById, $prevReturns, [
        ['ref_line_id' => 101, 'qty' => 1.0],
    ]);
}, 'أكبر من المتاح');

// فحص بند صفر (التحقق الأمني من العميل)
pos_assert(sale_require_customer(new PDO('sqlite::memory:'), null) === null, 'عميل فارغ');
pos_assert(sale_require_customer(new PDO('sqlite::memory:'), 0) === null, 'عميل صفر');
$memPdo = new PDO('sqlite::memory:');
$memPdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, is_active INTEGER)');
$memPdo->exec('INSERT INTO customers (id, is_active) VALUES (10, 1), (20, 0)');
pos_assert(sale_require_customer($memPdo, 10) === 10, 'عميل نشط');
pos_throws(fn() => sale_require_customer($memPdo, 20), 'موقوف');
pos_throws(fn() => sale_require_customer($memPdo, 999), 'مش موجود');

// حالة ٦: فاتورة فيزا ٧٢ ← رد كاش يتطلب اعتماد مشرف، ورد فيزا يعدي من غير اعتماد
$vpCash = sale_validate_return_payments(['visa' => 72.0], [], [['method' => 'cash', 'amount' => 72.0]], 72.0);
pos_assert($vpCash['override_needed'] === true, 'حالة ٦: رد كاش لفاتورة فيزا يتطلب اعتماد');
$vpVisa = sale_validate_return_payments(['visa' => 72.0], [], [['method' => 'visa', 'amount' => 72.0]], 72.0);
pos_assert($vpVisa['override_needed'] === false, 'حالة ٦: رد فيزا لنفس الفاتورة لا يتطلب اعتماد');
pos_throws(fn() => sale_validate_return_payments(['visa' => 72.0], [], [['method' => 'cash', 'amount' => 50.0]], 72.0), 'يساوي صافي المرتجع');

echo "نجح: $checks فحص\n";
