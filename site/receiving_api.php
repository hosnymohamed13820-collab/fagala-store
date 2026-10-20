<?php
/**
 * receiving_api.php — API شاشة استلام الفروع (بريف ١٥٢ — مرحلة أ)
 * نظام الفجالة ستور المالي
 *
 *   ?part=list&tab=…                 (GET)  ← {rows, headers}
 *   ?part=get&r=…&diff=0|1           (GET)  ← {receipt, items, status, is_group,
 *                                              show_diff, rejecter_name, last_scanned}
 *   ?part=start      (POST) receipt_id            ← null
 *   ?part=lookup     (POST) receipt_id · code     ← {item_code, scanned_code, item_name, scanned_qty}
 *   ?part=scan       (POST) receipt_id · code · qty ← {…, added_qty}
 *   ?part=update_qty (POST) receipt_id · code · qty ← {item_code, scanned_qty}
 *
 * مرحلة (أ) بس: review · reject_review · approve · delete_* مش هنا (بريف تاني).
 * كل المنطق في receiving_flow_lib.php — الصفحة بتنادي نفس الدوال، فمفيش سطر
 * SQL متعاد هنا ومفيش رقم بيتصرف في مكان غير اللي بيتعرض في التاني.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('receiving') في receiving.php
api_require('receiving');

require_once __DIR__ . '/receiving_flow_lib.php';

$pdo  = db();
$user = current_user();
$uid  = (int) ($user['id'] ?? 0);
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
$isOwner = is_owner();
$supervisorId = (int) setting('receiving_supervisor_id', '0');
$isSupervisor = ($supervisorId > 0 && $uid === $supervisorId);
$canManageAll = $isOwner || $isSupervisor;

$part = (string) ($_GET['part'] ?? '');

$reads  = ['list', 'get'];
$writes = ['start', 'lookup', 'scan', 'update_qty'];

if (!in_array($part, $reads, true) && !in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

// ===== القراية — GET بس =====
if (in_array($part, $reads, true)) {
    api_method('GET');

    if ($part === 'list') {
        // نفس استعلام الصفحة ونفس تجميع الكروت. الـtab موجود في الشاشة بس
        // مش مستخدم في أي استعلام (الصفحة مابتفلترش بيه أصلاً).
        $data = receiving_list_data($pdo, $canManageAll, $userBranchId);
        api_ok([
            'rows'    => $data['all_receipts'],
            'headers' => array_values($data['headers_map']),
        ]);
    }

    // $part === 'get'
    $receipt = receipt_get($pdo, (int) ($_GET['r'] ?? 0));
    if (!$receipt) {
        api_fail('سجل الاستلام غير موجود.', 404, 'not_found');
    }

    // 🔴 نفس حماية الصفحة (سطر ٧٩٥): موظف فرع يفتح استلام فرع آخر ← ممنوع
    $scopeErr = receiving_scope_error($receipt, $canManageAll, $userBranchId);
    if ($scopeErr !== null) {
        api_fail($scopeErr, 403, 'forbidden');
    }

    // diff=1 أو diff=0: الصفحة بتعتبر وجود البارامتر كفاية (isset) — نفسها هنا
    api_ok(receiving_detail_data($pdo, $receipt, isset($_GET['diff'])));
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

// نفس اللي الصفحة بتعمله: receipt_id من الـbody (أو r من الرابط)
$receiptId = (int) ($in['receipt_id'] ?? ($_GET['r'] ?? 0));
$receipt = receipt_get($pdo, $receiptId);
if (!$receipt) {
    api_fail('سجل الاستلام غير موجود.', 404, 'not_found');
}

// نفس حارس النطاق اللي الصفحة بتستدعيه قبل أي عملية كتابة — الرفض هنا 403
$scopeErr = receiving_scope_error($receipt, $canManageAll, $userBranchId);
if ($scopeErr !== null) {
    api_fail($scopeErr, 403, 'forbidden');
}

// الدوال بترمي RuntimeException برسايل الصفحة نفسها — بنحوّلها 422.
try {
    switch ($part) {
        case 'start':
            receipt_start($pdo, $receipt, $uid);
            api_ok(null);

        case 'lookup':
            api_ok(receipt_lookup($pdo, $receipt, (string) ($in['code'] ?? '')));

        case 'scan':
            // 🔴 الصفحة بتحوّل qty<=0 لـ1 بصمت، والـAPI بيرفض: مسح بـ0 في
            //    الاستلام الأعمى معناه زيادة كمية مش طلبها حد. (حالة ١٠)
            $qty = (float) ($in['qty'] ?? 1.0);
            if ($qty <= 0) {
                throw new RuntimeException('الكمية لازم تكون أكبر من صفر.');
            }
            api_ok(receipt_scan($pdo, $receipt, (string) ($in['code'] ?? ''), $qty, $uid));

        case 'update_qty':
            // سالب = صفر زي الصفحة بالحرف (الدالة نفسها بتعملها)
            api_ok(receipt_set_qty($pdo, $receipt, (string) ($in['code'] ?? ''), (float) ($in['qty'] ?? 0), $uid));
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
