<?php
/** pos_api.php — JSON API نقطة البيع. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/sale_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));
$contracts = [
    'context'      => ['GET',  'pos:sell'],
    'lookup'       => ['GET',  'pos:sell'],
    'session_open' => ['POST', 'pos:sell'],
    'finalize'     => ['POST', 'pos:sell'],
    'hold'         => ['POST', 'pos:sell'],
    'held_list'    => ['GET',  'pos:sell'],
    'held_resume'  => ['POST', 'pos:sell'],
    'held_cancel'  => ['POST', 'pos:held_cancel'],
    // الكاشير لازم يقدر يبيع؛ الاعتماد نفسه بيتحقق من صلاحية المشرف جوه الدالة.
    'approve'      => ['POST', 'pos:sell'],
    'drawer_open'  => ['POST', 'pos:drawer'],
    'cart_event'   => ['POST', 'pos:sell'],
    // بريف ٢٤٢: المرتجع والاستبدال
    'doc_find'       => ['GET',  'pos:return_ref'],
    'return_ref'     => ['POST', 'pos:return_ref'],
    'return_general' => ['POST', 'pos:return_general'],
    'exchange'       => ['POST', ['pos:return_ref', 'pos:sell']],
];
if (!isset($contracts[$part])) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}
[$method, $permission] = $contracts[$part];
api_method($method);
if ($permission !== null) {
    if (is_array($permission)) {
        foreach ($permission as $perm) {
            api_require($perm);
        }
    } else {
        api_require($permission);
    }
}
if ($method === 'POST') {
    api_csrf();
}
if (in_array($part, ['session_open', 'finalize', 'hold', 'return_ref', 'return_general', 'exchange'], true)) {
    api_idempotent();
}

$pdo = db();
$user = current_user() ?? [];
$in = $method === 'POST' ? api_input() : $_GET;

try {
    switch ($part) {
        case 'context':
            api_ok(sale_context($pdo, (string) ($in['device_uuid'] ?? ''), $user));
        case 'lookup':
            $device = sale_device($pdo, (string) ($in['device_uuid'] ?? ''), $user);
            api_ok(['items' => sale_lookup(
                $pdo,
                (int) $device['branch_id'],
                (string) ($in['code'] ?? ''),
                (string) ($in['q'] ?? '')
            )]);
        case 'session_open':
            api_ok(sale_session_open(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                (float) ($in['opening_cash'] ?? 0),
                $user
            ));
        case 'finalize':
            api_ok(sale_finalize($pdo, $in, $user));
        case 'hold':
            api_ok(sale_hold($pdo, $in, $user));
        case 'held_list':
            api_ok(['docs' => sale_held_list(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                $user
            )]);
        case 'held_resume':
            api_ok(sale_held_resume(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                (int) ($in['doc_id'] ?? 0),
                $user
            ));
        case 'held_cancel':
            sale_held_cancel(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                (int) ($in['doc_id'] ?? 0),
                (string) ($in['reason'] ?? ''),
                $user
            );
            api_ok(['cancelled' => true]);
        case 'approve':
            api_ok(sale_approve($pdo, $in, $user));
        case 'drawer_open':
            sale_drawer_open(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                (string) ($in['reason'] ?? ''),
                $user
            );
            api_ok(['opened' => true]);
        case 'cart_event':
            sale_cart_event($pdo, $in, $user);
            api_ok(['recorded' => true]);
        case 'doc_find':
            api_ok(sale_doc_find($pdo, $in, $user));
        case 'return_ref':
            api_ok(sale_return_ref($pdo, $in, $user));
        case 'return_general':
            api_ok(sale_return_general($pdo, $in, $user));
        case 'exchange':
            api_ok(sale_exchange($pdo, $in, $user));
    }
} catch (SaleApiException $e) {
    api_fail($e->getMessage(), $e->http, $e->apiCode);
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
}
