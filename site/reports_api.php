<?php
/**
 * reports_api.php — واجهة برمجة موديول التقارير (بريف ١٧١ — مرحلة أ: غير المالية)
 * نظام الفجالة ستور المالي
 *
 *   ?part=report&type=<type>[&from=YYYY-MM-DD&to=YYYY-MM-DD] (GET)
 *
 * التابات المتاحة في المرحلة (أ):
 *   - attendance: تقرير الحضور
 *   - attendance_totals: تقرير إجمالي الحضور
 *   - receiving: استلام الفواتير
 *   - stocktake: أرشيف الجرد
 *   - customers: داتا العملاء
 *
 * بقية التابات (المالية) محجوزة للموجة ٣، وتُرجع 403 «التقرير ده لسه مش متاح من الـAPI».
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_module_access('reports');

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/reports_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

if ($part !== 'report') {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('GET');

$repParts = module_parts()['reports'] ?? [];
$type = (string) ($_GET['type'] ?? '');

if (!array_key_exists($type, $repParts)) {
    api_fail('نوع التقرير غير صحيح', 400, 'bad_request');
}

$allowedTypes = [
    'attendance',
    'attendance_totals',
    'receiving',
    'stocktake',
    'customers',
];

if (!in_array($type, $allowedTypes, true)) {
    api_fail('التقرير ده لسه مش متاح من الـAPI', 403, 'forbidden');
}

// فحص صلاحية التاب المحدد: reports:<type>
api_require('reports:' . $type);

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
}
