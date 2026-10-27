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
    try { $fn(); } catch (InvalidArgumentException $e) {
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

echo "نجح: $checks فحص\n";
