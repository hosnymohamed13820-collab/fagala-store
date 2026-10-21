<?php
/**
 * dashboard_api.php — واجهة برمجة الداشبورد المالي (بريف ١٧٢ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=treasuries                             ← أرصدة الخزائن المركزية والفرعية والكاش الفعلي (حساس 423)
 *   ?part=custodies                              ← عهد الفروع والموظفين المفتوحة ونسب الاستهلاك (حساس 423)
 *   ?part=summary[&from=Y-m-d&to=Y-m-d]          ← ملخص المبيعات والمؤشرات (حساس 423)
 *   ?part=shifts[&from=Y-m-d&to=Y-m-d]           ← آخر التقفيلات وحالة المراجعة وصلاحيات الأزرار
 *   ?part=all[&from=Y-m-d&to=Y-m-d]              ← كائن الداشبورد كاملاً (حساس 423)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('dashboard');

require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/dashboard_lib.php';

// الداشبورد في المرحلة الحالية قراءة فقط
api_method('GET');

$part = (string) ($_GET['part'] ?? 'all');

$allowedParts = [
    'treasuries',
    'custodies',
    'summary',
    'shifts',
    'all',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

// فحص قفل الأرقام الحساسة (الموجة ٣ — كود 423 locked)
// الأجزاء المالية الحساسة: treasuries, custodies, summary, all
$sensitiveParts = ['treasuries', 'custodies', 'summary', 'all'];
sens_page();
if (in_array($part, $sensitiveParts, true) && !sens_unlocked()) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}

$pdo = db();
$periodInfo = dashboard_parse_period($_GET['from'] ?? null, $_GET['to'] ?? null);
$from       = $periodInfo['from'];
$to         = $periodInfo['to'];

try {
    switch ($part) {
        case 'treasuries':
            $treData = dashboard_get_treasuries($pdo);
            api_ok($treData);

        case 'custodies':
            $custodyData = dashboard_get_custodies($pdo);
            api_ok($custodyData);

        case 'summary':
            $sumData = dashboard_get_summary($pdo, $from, $to);
            api_ok([
                'period'  => $periodInfo,
                'summary' => $sumData,
            ]);

        case 'shifts':
            $shiftsData = dashboard_get_shifts($pdo, $from, $to);
            api_ok([
                'period' => $periodInfo,
                'shifts' => $shiftsData['shifts'],
                'can'    => [
                    'review'  => $shiftsData['can_review'],
                    'cashier' => $shiftsData['can_cashier'],
                ],
            ]);

        case 'all':
            $treData     = dashboard_get_treasuries($pdo);
            $custodyData = dashboard_get_custodies($pdo);
            $sumData     = dashboard_get_summary($pdo, $from, $to);
            $shiftsData  = dashboard_get_shifts($pdo, $from, $to);

            api_ok([
                'period'      => $periodInfo,
                'treasuries'  => $treData,
                'custodies'   => $custodyData,
                'summary'     => $sumData,
                'shifts'      => $shiftsData['shifts'],
                'can'         => [
                    'review'  => $shiftsData['can_review'],
                    'cashier' => $shiftsData['can_cashier'],
                ],
            ]);
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 422, 'error');
}
