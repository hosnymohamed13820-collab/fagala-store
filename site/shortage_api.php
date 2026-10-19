<?php
/**
 * shortage_api.php — داتا «طلب النواقص» (بريف ١٢٣)
 * نظام الفجالة ستور المالي
 *
 *   ?part=code&code=…                       ← {row, local_ready}
 *   ?part=search&term=…                     ← {rows}
 *   ?part=filter&mode=…&sto=…&threshold=…   ← {rows}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');
api_require('shortage');

require_once __DIR__ . '/stock_query_lib.php';

// 🔴 طلب النواقص بيقرا من مرآة الوكلاء بس.
stock_mirror_only(true);

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'code':
        // «مش موجود» نتيجة عادية مش خطأ ← row = null (زي stock_query_api)
        $row = stock_item_by_code((string) ($_GET['code'] ?? ''));
        api_ok([
            'row'         => $row ?: null,
            'local_ready' => stock_local_ready(),
        ]);

    case 'search':
        api_ok(['rows' => stock_search_by_name((string) ($_GET['term'] ?? ''), 25)]);

    case 'filter':
        api_ok(['rows' => stock_filter_items(
            (string) ($_GET['mode'] ?? ''),
            (int) ($_GET['sto'] ?? 0),
            (float) ($_GET['threshold'] ?? 0)
        )]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

