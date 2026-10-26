<?php
/**
 * activity_api.php — داتا «حركات النظام» للمدير (بريف ٢٢٣)
 * نظام الفجالة ستور المالي
 *
 * نموذج الـAPI الموحّد:
 *   ?part=cards                                            ← كروت «محتاج تصرّف»
 *   ?part=timeline&period=…&from=…&to=…&branch=…&type=…   ← الخط الزمني
 *   ?part=sales&period=…&from=…&to=…&branch=…&page=…      ← فواتير المبيعات
 *   ?part=purchases&period=…&from=…&to=…&branch=…         ← فواتير المشتريات
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');

// مخصصة للمالك فقط زي الصفحة
if (!is_owner()) {
    api_fail('هذه الصفحة مخصصة للمالك فقط', 403, 'forbidden');
}

// 🔴 الأرقام الحساسة: نفس قفل الصفحة بالحرف — الأجزاء اللي فيها مبالغ بترجع 423
require_once __DIR__ . '/sensitive_lib.php';
sens_page();

$part = (string) ($_GET['part'] ?? $_GET['view'] ?? '');
if ($part === '') {
    $part = 'timeline';
}

// cards مفيهاش مبالغ (أعداد وتواريخ بس) — الباقي فيه فلوس
if (in_array($part, ['sales', 'purchases', 'timeline'], true) && !sens_unlocked()) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}

require_once __DIR__ . '/activity_lib.php';

$period = (string) ($_GET['period'] ?? 'today');
$customFrom = (string) ($_GET['from'] ?? '');
$customTo = (string) ($_GET['to'] ?? '');
[$dateStart, $dateEnd, $period] = act_date_range($period, $customFrom, $customTo);

$branchFilter = isset($_GET['branch']) && $_GET['branch'] !== 'all' && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;

// 🔴 مفيش getMessage() في أي رد — أي exception بيمسكه api_begin()
switch ($part) {
    case 'cards':
        api_ok(act_cards_data());

    case 'timeline':
        $typeFilter = (string) ($_GET['type'] ?? 'all');
        $validTypes = ['all', 'shifts', 'custody', 'receiving', 'treasury',
                       'shortage', 'attendance', 'stocktake', 'sms', 'newitems',
                       'permits', 'transfers', 'salaries', 'advances'];
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }
        [$branchesList, $branchById, $branchBySto] = act_get_branches();
        $timelineData = act_timeline_data($dateStart, $dateEnd, $typeFilter, $branchFilter, $branchById);
        api_ok([
            'period'         => $period,
            'date_start'     => $dateStart,
            'date_end'       => $dateEnd,
            'branch'         => $branchFilter,
            'type'           => $typeFilter,
            'count'          => $timelineData['count'],
            'new_items_ever' => $timelineData['new_items_ever'],
            'feed'           => $timelineData['feed'],
        ]);

    case 'sales':
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $salesData = act_sales_data($dateStart, $dateEnd, $period, $branchFilter, $page, 200);
        api_ok([
            'period'           => $period,
            'date_start'       => $dateStart,
            'date_end'         => $dateEnd,
            'too_long'         => $salesData['too_long'],
            'branch_filter'    => $salesData['branch_filter'],
            'counts'           => $salesData['counts'],
            'days'             => $salesData['days'],
            'totals'           => $salesData['totals'],
            'net'              => $salesData['net'],
            'total_count'      => $salesData['total_count'],
            'total_pages'      => $salesTotalPages = $salesData['total_pages'],
            'page'             => $salesData['page'],
            'limit'            => $salesData['limit'],
            'invoices'         => $salesData['invoices'],
            'lines_by_invoice' => $salesData['lines_by_invoice'],
        ]);

    case 'purchases':
        [$branchesList, $branchById, $branchBySto] = act_get_branches();
        if ($branchFilter !== null && !in_array($branchFilter, [1, 2, 3, 5, 6], true)) {
            $branchFilter = act_company_branch_of($branchFilter, $branchById);
        }
        $purchasesData = act_purchases_data($dateStart, $dateEnd, $branchFilter);
        api_ok([
            'period'        => $period,
            'date_start'    => $dateStart,
            'date_end'      => $dateEnd,
            'branch_filter' => $purchasesData['branch_filter'],
            'counts'        => $purchasesData['counts'],
            'totals'        => $purchasesData['totals'],
            'net'           => $purchasesData['net'],
            'total_count'   => $purchasesData['total_count'],
            'rows'          => $purchasesData['rows'],
        ]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
