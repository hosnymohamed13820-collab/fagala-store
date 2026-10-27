<?php
/** المصدر الموحد لفئات عد الكاش في التقفيل اليدوي ونقطة البيع. */
declare(strict_types=1);

function cashier_cash_denominations(): array
{
    return [200, 100, 50, 20, 10, 5, 1, 0.5, 0.25];
}
