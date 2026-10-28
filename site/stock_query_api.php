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
 *   ?part=export&mode=…&sto=…&threshold=…   ← ملف إكسيل
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
    case 'export':
        $rawCodes = $_GET['codes'] ?? [];
        if (is_string($rawCodes)) {
            $rawCodes = explode(',', $rawCodes);
        }
        $codes = is_array($rawCodes)
            ? array_slice(array_values(array_filter(array_map(fn($c) => trim((string) $c), $rawCodes), fn($c) => $c !== '')), 0, 300)
            : [];
        if ($codes) {
            $rows = stock_items_by_codes($codes);
        } else {
            $mode = (string) ($_GET['mode'] ?? '');
            if (!in_array($mode, ['admin_only', 'branch_only', 'has_stock', 'zero', 'below'], true)) {
                api_fail('فلتر التصدير مش صحيح', 400, 'bad_request');
            }
            $rows = stock_filter_items(
                $mode,
                (int) ($_GET['sto'] ?? 0),
                (float) ($_GET['threshold'] ?? 0)
            );
        }

        $branches = stock_branches_map();
        $branches = array_merge($branches, stock_extra_stores(array_column($branches, 'sto_id')));

        require_once __DIR__ . '/xlsx_lib.php';
        $xlsx = new SimpleXlsx('استعلام الأرصدة');
        $xlsx->setColumnTypes(array_merge(['s', 's'], array_fill(0, count($branches), 'n')));
        $xlsx->addRow(array_merge(
            ['الكود', 'اسم الصنف'],
            array_map(fn($branch) => $branch['is_admin'] ? 'الإدارة' : $branch['name'], $branches)
        ), true);
        foreach ($rows as $row) {
            $balances = $row['balances'] ?? [];
            $xlsx->addRow(array_merge(
                [(string) $row['code'], (string) $row['name']],
                array_map(fn($branch) => (float) ($balances[$branch['sto_id']] ?? 0), $branches)
            ));
        }
        $xlsx->download('stock_query_' . date('Y-m-d') . '.xlsx');
        exit;

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
