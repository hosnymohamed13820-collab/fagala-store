<?php
/**
 * reports_api.php — واجهة برمجة موديول التقارير (بريف ١٧١ + بريف ١٨١: تابات الفلوس)
 * نظام الفجالة ستور المالي — موجة ٣
 *
 *   ?part=report&type=<type>[&from=YYYY-MM-DD&to=YYYY-MM-DD] (GET)
 *
 * التابات المتاحة:
 *   - المرحلة (أ): attendance, attendance_totals, receiving, stocktake, customers
 *   - الموجة ٣ (تابات الفلوس): sales, treasury, expenses, jtotals, custody, deficits, suppliers, salaries, advances, daily
 *
 * الأرقام الحساسة: salaries و advances تُرجع HTTP 423 locked إذا كانت الأرقام مقفولة.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_module_access('reports');

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/reports_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

if (!in_array($part, ['report', 'items'], true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('GET');

$repParts = module_parts()['reports'] ?? [];
$type = (string) ($_GET['type'] ?? '');
if (!site_permission_enabled('reports:' . $type)) api_fail('مالكش صلاحية', 403, 'forbidden');

if (!array_key_exists($type, $repParts)) {
    api_fail('نوع التقرير غير صحيح', 400, 'bad_request');
}

$allowedTypes = [
    // المرحلة (أ) — غير مالية
    'attendance',
    'attendance_totals',
    'receiving',
    'stocktake',
    'customers',
    // الموجة ٣ — تابات الفلوس (بريف ١٨١)
    'sales',
    'treasury',
    'expenses',
    'jtotals',
    'custody',
    'deficits',
    'suppliers',
    'salaries',
    'advances',
    'daily',
    'dist_invoices',
];

if (!in_array($type, $allowedTypes, true)) {
    api_fail('التقرير ده لسه مش متاح من الـAPI', 403, 'forbidden');
}

// 🔴 الأرقام الحساسة: salaries و advances يرجع 423 locked لو مقفولة
if (in_array($type, ['salaries', 'advances'], true)) {
    sens_page();
    if (!sens_unlocked()) {
        api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
    }
}

// فحص صلاحية التاب المحدد
if ($type === 'daily') {
    $dsUsers = array_map('intval', explode(',', (string) setting('daily_summary_users', '')));
    if (!can('reports:daily') && !in_array((int) current_user()['id'], $dsUsers, true)) {
        api_fail('ليس لديك صلاحية للوصول إلى هذا التقرير', 403, 'forbidden');
    }
} else {
    api_require('reports:' . $type);
}

if ($part === 'items') {
    if ($type !== 'dist_invoices') {
        api_fail('الجزء ده متاح لتقرير الفواتير الموزّعة بس', 400, 'bad_request');
    }
    $distId = (int) ($_GET['dist_id'] ?? 0);
    $items = dr_items($distId);
    if (!$items['found']) {
        api_fail('الفاتورة دي مش موجودة', 404, 'not_found');
    }
    unset($items['found']);
    api_ok($items);
}

// التواريخ الافتراضية مطابقة لصفحة reports.php
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to   = (string) ($_GET['to']   ?? date('Y-m-d'));
if (!strtotime($from)) {
    $from = date('Y-m-01');
}
if (!strtotime($to)) {
    $to = date('Y-m-d');
}

$pdo = db();

switch ($type) {
    case 'attendance':
    case 'attendance_totals':
    case 'receiving':
    case 'sales':
    case 'treasury':
    case 'expenses':
    case 'jtotals':
    case 'custody':
    case 'deficits':
    case 'suppliers':
    case 'salaries':
    case 'advances':
    case 'dist_invoices':
        api_ok(build_report($pdo, $type, $from, $to));

    case 'customers':
        $branch = (int) ($_GET['branch'] ?? 0);
        $search = (string) ($_GET['q'] ?? '');
        api_ok(rep_customers_data($pdo, $from, $to, $branch, $search));

    case 'stocktake':
        $session = isset($_GET['session']) && $_GET['session'] !== '' ? (int) $_GET['session'] : 0;
        $sheets = null;
        if (isset($_GET['sheets']) && is_array($_GET['sheets'])) {
            $sheets = array_map('intval', $_GET['sheets']);
        } elseif (!empty($_GET['sheet_ids'])) {
            $sheets = array_filter(array_map('intval', explode(',', (string) $_GET['sheet_ids'])));
        }
        api_ok(rep_stocktake_data($session, $sheets));

    case 'daily':
        require_once __DIR__ . '/daily_summary_lib.php';
        $date = trim((string) ($_GET['date'] ?? ($_GET['to'] ?? date('Y-m-d'))));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $date = date('Y-m-d');
        }
        $summary = ds_build($date);
        $month   = ds_month($date);
        api_ok([
            'title'     => 'الملخص اليومي',
            'date'      => $date,
            'summary'   => $summary,
            'month'     => $month,
            'prev_date' => date('Y-m-d', strtotime($date . ' -1 day')),
            'next_date' => date('Y-m-d', strtotime($date . ' +1 day')),
        ]);
}
