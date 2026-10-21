<?php
/**
 * invoices_api.php — واجهة برمجة موديول الفواتير (بريف ١٦٥: مرحلة أ١ — البحث والبوب أب)
 * نظام الفجالة ستور المالي
 *
 *   ?part=item_search&term=…                       ← {rows: […]} (GET)
 *   ?part=item_history&code=…&item_id=…            ← {item: {…}, prices: […], invoices: […], …} (GET)
 *   ?part=pick_search&term=…[&g=…]                 ← {rows: […]} (GET)
 *   ?part=pick_items&sth=…&suffix=…[&g=…]          ← {title: '…', rows: […]} (GET)
 *   ?part=pick_group_invoices&g2=…                 ← {title: '…', rows: […]} (GET)
 *   ?part=group_rows                               ← {unposted: […], posted: […], branch_count: N} (GET)
 *
 * جميع عمليات المرحلة (أ١) هي قراءة GET فقط. عمليات POST تُرفض بـ 405 Method Not Allowed.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('invoices')
api_require('invoices');

require_once __DIR__ . '/item_lookup_lib.php';
require_once __DIR__ . '/invoices_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    'item_search',
    'item_history',
    'pick_search',
    'pick_items',
    'pick_group_invoices',
    'group_rows',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('GET');

try {
    switch ($part) {
        case 'item_search':
            if (!ilk_ready()) {
                api_fail('المرآة لسه فاضية — شغّل وكيل المزامنة.', 422, 'not_ready');
            }
            $term = (string) ($_GET['term'] ?? '');
            api_ok(['rows' => ilk_search($term, 15)]);

        case 'item_history':
            if (!ilk_ready()) {
                api_fail('المرآة لسه فاضية — شغّل وكيل المزامنة.', 422, 'not_ready');
            }
            $code   = (string) ($_GET['code'] ?? '');
            $itemId = (int) ($_GET['item_id'] ?? 0);
            $res = ilk_item_history($code, $itemId);
            api_ok($res);

        case 'pick_search':
            $term = (string) ($_GET['term'] ?? '');
            $gid  = (int) ($_GET['g'] ?? 0);
            api_ok(['rows' => inv_pick_search($term, $gid)]);

        case 'pick_items':
            $sth = (int) ($_GET['sth'] ?? 0);
            $suf = trim((string) ($_GET['suffix'] ?? ''));
            $gid = (int) ($_GET['g'] ?? 0);
            api_ok(inv_pick_items($sth, $suf, $gid));

        case 'pick_group_invoices':
            $g2 = (int) ($_GET['g2'] ?? 0);
            api_ok(inv_pick_group_invoices($g2));

        case 'group_rows':
            api_ok(inv_group_picker_data());
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
}
