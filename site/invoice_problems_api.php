<?php
/**
 * invoice_problems_api.php — API مشاكل الفواتير (بريف ١٥٩)
 * نظام الفجالة ستور المالي
 *
 *   ?part=list                 (GET)  ← {rows}   فواتير الإدارة اللي فيها مشاكل
 *   ?part=invoice&d=<id>      (GET)  ← {header, items} فاتورة واحدة
 *   ?part=save_field           (POST) ← {source}
 *   ?part=delete_problem       (POST) ← null
 *   ?part=delete_invoice       (POST) ← null
 *
 * الشاشة (invoice_problems.php) لسه على حالها — الـAPI ده بينادي نفس دوال
 * invoice_problems_lib.php بالحرف: نفس تحويلات الأنواع ونفس مفاتيح الرد. المنطق
 * كله في المكتبة، مفيش سطر منه متعاد هنا.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('invoices:problems') في invoice_problems.php
api_require('invoices:problems');

require_once __DIR__ . '/invoice_problems_lib.php';

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);

$part = (string) ($_GET['part'] ?? '');

$reads  = ['list', 'invoice'];
$writes = ['save_field', 'delete_problem', 'delete_invoice', 'recalc_all'];

if (!in_array($part, $reads, true) && !in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

// ===== القراءة — GET بس =====
if (in_array($part, $reads, true)) {
    api_method('GET');

    if ($part === 'list') {
        api_ok(['rows' => ip_problem_invoices($pdo)]);
    }

    // $part === 'invoice'
    $distId = (int) ($_GET['d'] ?? 0);
    if ($distId <= 0) {
        api_fail('لازم تحدد رقم الفاتورة (d).', 400, 'bad_request');
    }

    $distHeader = ip_dist_header($pdo, $distId);
    if (!$distHeader) {
        api_fail('الفاتورة المطلوبة غير موجودة أو ليست من فواتير مشتريات الإدارة.', 404, 'not_found');
    }

    api_ok([
        'header' => $distHeader,
        'items'  => ip_invoice_items($pdo, $distHeader),
    ]);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

// الدوال بترمي RuntimeException برسايل للمستخدم — بنحوّلها 422.
// أي Throwable تاني مايتلقطش هنا ويروح لمعالج api_begin().
try {
    switch ($part) {
        case 'save_field':
            $res = ip_save_problem_field(
                $pdo,
                $uid,
                (int) ($in['distribution_id'] ?? 0),
                trim((string) ($in['item_code'] ?? '')),
                trim((string) ($in['item_name'] ?? '')),
                (string) ($in['field'] ?? ''),
                trim((string) ($in['value'] ?? ''))
            );
            api_ok(['source' => $res['source']]);

        case 'delete_problem':
            ip_delete_problem(
                $pdo,
                (int) ($in['distribution_id'] ?? 0),
                trim((string) ($in['item_code'] ?? ''))
            );
            api_ok(null);

        case 'delete_invoice':
            ip_delete_invoice_problems($pdo, (int) ($in['distribution_id'] ?? 0));
            api_ok(null);

        case 'recalc_all':
            if (!is_owner()) {
                api_fail('هذا الإجراء متاح للمالك فقط.', 403, 'forbidden');
            }
            sync_all_invoice_problems_auto($pdo);
            api_ok(['last_recalc' => ip_last_recalc_time($pdo)]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
