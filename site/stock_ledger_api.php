<?php
/**
 * stock_ledger_api.php — داتا «دفتر المخزن» (بريف ٢٢٩)
 * نظام الفجالة ستور المالي
 *
 * GET:
 *   ?part=docs&branch_id=&type=&status=&from=&to=   ← {docs, count}
 *   ?part=runs                                       ← {runs, count}   (آخر ٥٠ دورة استيراد)
 *   ?part=openings                                   ← {openings, count} (الأرصدة الافتتاحية المرحلة)
 *
 * الصلاحية **نفس صلاحية stock_ledger.php بالظبط**: require_module_access('stock_ledger')
 * + is_real_owner() — غير كده 403.
 *
 * ملحوظة: كل الاستعلامات في stock_ledger_lib.php، والصفحة بتستخدم نفس الدوال
 * فمستحيل أرقام الـAPI تختلف عن اللي على الشاشة.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');

// نفس فحص الصفحة (اللي بيرد 403 JSON جوه API_MODE) + المالك الحقيقي بس
require_module_access('stock_ledger');
if (!is_real_owner()) {
    api_fail('غير مصرح — دفتر المخزن متاح للمالك فقط', 403, 'forbidden');
}

require_once __DIR__ . '/stock_ledger_lib.php';

$pdo = db();
$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'docs':
        $docs = stock_ledger_docs(
            $pdo,
            (int) ($_GET['branch_id'] ?? 0),
            (string) ($_GET['type'] ?? ''),
            (string) ($_GET['status'] ?? ''),
            (string) ($_GET['from'] ?? ''),
            (string) ($_GET['to'] ?? '')
        );
        api_ok(['docs' => $docs, 'count' => count($docs)]);

    case 'runs':
        $runs = stock_ledger_import_runs($pdo);
        api_ok(['runs' => $runs, 'count' => count($runs)]);

    case 'openings':
        $openings = array_values(stock_ledger_posted_openings($pdo));
        api_ok(['openings' => $openings, 'count' => count($openings)]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
