<?php
/**
 * supplier_purchases_api.php — API تقرير «مشتريات مورد» (بريف ١٢٥)
 * نظام الفجالة ستور المالي
 *
 *   ?part=report&from=YYYY-MM-DD&to=YYYY-MM-DD[&branch=INT]
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');
api_require('reports:supplier_purchases');

require_once __DIR__ . '/supplier_purchases_lib.php';

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'report':
        $from = (string) ($_GET['from'] ?? date('Y-m-01'));
        $to   = (string) ($_GET['to'] ?? date('Y-m-d'));
        $branch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;

        $report = supplier_purchases_report($from, $to, $branch);
        api_ok([
            'from'    => $from,
            'to'      => $to,
            'branch'  => $branch,
            'rows'    => $report['rows'],
            'totals'  => $report['totals'],
        ]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

