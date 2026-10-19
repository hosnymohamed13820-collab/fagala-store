<?php
/**
 * price_inquiry_api.php — داتا «استعلام الأسعار» (بريف ١٢٣)
 * نظام الفجالة ستور المالي
 *
 *   ?part=barcode&code=…   ← {row} (null لو الكود مش موجود)
 *   ?part=search&term=…    ← {rows}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');
api_require('price_inquiry');

require_once __DIR__ . '/price_lib.php';

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'barcode':
        // «مش موجود» نتيجة عادية مش خطأ ← row = null (زي stock_query_api)
        $res = lookup_price_by_barcode((string) ($_GET['code'] ?? ''));
        api_ok(['row' => $res ?: null]);

    case 'search':
        api_ok(['rows' => search_items((string) ($_GET['term'] ?? ''))]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

