<?php
/**
 * book_orders_api.php — داتا «طلبيات الكتب» (بريف ١٢٧)
 * نظام الفجالة ستور المالي
 *
 *   ?part=search&term=…      ← {rows}   (GET)
 *   ?action=save  (POST)     ← null     (book · supplier · field · qty)
 *
 * أول API بيكتب. الحفظ بيمشي على نفس خطوات البلوك القديم اللي كان
 * جوه book_orders.php بالحرف: نفس التحويلات ونفس نداء book_save_qty().
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('shortage') في book_orders.php
api_require('shortage');

require_once __DIR__ . '/book_lib.php';

$uid = (int) current_user()['id'];

// ===== الحفظ — POST بس، بـCSRF ومحمي من التكرار =====
if (($_GET['action'] ?? '') === 'save') {
    api_method('POST');
    api_csrf();
    api_idempotent();

    $in  = api_input();
    $raw = trim((string) ($in['qty'] ?? ''));
    book_save_qty(
        (int) ($in['book'] ?? 0),
        (int) ($in['supplier'] ?? 0),
        (string) ($in['field'] ?? 'req'),
        $raw === '' ? null : (float) $raw,
        $uid
    );
    // النسخ بقى في المتصفح من اللي على الشاشة، فمفيش
    // حاجة تترد هنا غير التأكيد.
    api_ok(null);
}

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'search':
        api_method('GET');
        api_ok(['rows' => book_orders_search((string) ($_GET['term'] ?? ''))]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
