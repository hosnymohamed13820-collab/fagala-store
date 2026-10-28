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

    case 'export':
        require_once __DIR__ . '/xlsx_lib.php';

        $mode = (string) ($_GET['mode'] ?? '');
        $sto = (int) ($_GET['sto'] ?? 0);
        $threshold = (float) ($_GET['threshold'] ?? 0);
        $rawCodes = $_GET['codes'] ?? [];
        if (is_string($rawCodes)) {
            $rawCodes = explode(',', $rawCodes);
        }
        $codes = is_array($rawCodes)
            ? array_slice(array_values(array_filter(array_map(fn($c) => trim((string) $c), $rawCodes), fn($c) => $c !== '')), 0, 300)
            : [];
        $reqParam = (string) ($_GET['req'] ?? '');

        $reqMap = [];
        if ($reqParam !== '') {
            $dec = json_decode($reqParam, true);
            if (is_array($dec)) $reqMap = $dec;
        }

        $rows = [];
        if ($codes) {
            $rows = stock_items_by_codes($codes);
        } elseif ($mode !== '' && $mode !== 'none') {
            $rows = stock_filter_items($mode, $sto, $threshold);
            if ($mode !== 'zero') {
                $rows = array_filter($rows, fn($r) => ((float)($r['total'] ?? 0)) > 0.0001);
            }
        }

        $branches = stock_branches_map();
        $branches = array_merge($branches, stock_extra_stores(array_column($branches, 'sto_id')));

        $x = new SimpleXlsx('طلب نواقص');
        $types = ['s', 's', 'n'];
        $hdr = ['الكود', 'اسم الصنف', 'الكمية المطلوبة'];
        foreach ($branches as $b) {
            $hdr[] = $b['is_admin'] ? 'الإدارة' : $b['name'];
            $types[] = 'n';
        }
        $x->setColumnTypes($types);
        $x->addRow($hdr, true);

        foreach ($rows as $r) {
            $bal = $r['balances'] ?? [];
            $line = [
                (string) $r['code'],
                (string) ($r['name'] ?? ''),
                (float) ($reqMap[$r['code']] ?? 0),
            ];
            foreach ($branches as $b) {
                $line[] = (float) ($bal[$b['sto_id']] ?? 0);
            }
            $x->addRow($line);
        }

        $x->download('shortage_' . date('Y-m-d') . '.xlsx');
        exit;

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

