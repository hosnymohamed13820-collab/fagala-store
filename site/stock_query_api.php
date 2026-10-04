<?php
/**
 * stock_query_api.php — داتا «استعلام الأرصدة» (بريف ١١٠)
 * نظام الفجالة ستور المالي
 *
 * أول صفحة اتنقلت على الـAPI الموحّد، وده النموذج للباقي: الـAJAX اللي
 * كان جوه stock_query.php (?ajax=…) اتنقل هنا بنفس الدوال بالظبط، والصفحة
 * بتكلّمه بـapiFetch. التطبيقات (ديسكتوب/موبايل) هتكلّمه نفس الكلام.
 *
 *   ?part=code&code=…                       ← {row} (null لو الكود مش موجود)
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
// نفس حماية الصفحة بالظبط (require_module_access('reports') + can('reports:stock'))
api_require('reports:stock');

require_once __DIR__ . '/stock_query_lib.php';

$part = (string) ($_GET['part'] ?? '');

// 🔴 مفيش getMessage() في أي رد — أي exception بيمسكه api_begin()
//    ويرجّع {"code":"server"} ويتسجّل في سجل الأخطاء.
switch ($part) {
    case 'code':
        // «مش موجود» نتيجة عادية مش خطأ ← row = null
        api_ok(['row' => stock_item_by_code((string) ($_GET['code'] ?? ''))]);

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
