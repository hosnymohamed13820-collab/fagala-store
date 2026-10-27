<?php
/**
 * sale_lib.php — دوال نقطة البيع المشتركة.
 */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';

/**
 * الفرع جاهز إن مبيعات نقطة البيع تحرك مخزنه على الموقع.
 */
function sale_branch_ready(int $branchId): bool
{
    return stock_enabled() && stock_branch_mode($branchId) === 'live';
}
