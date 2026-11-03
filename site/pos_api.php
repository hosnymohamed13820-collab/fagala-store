<?php
/** pos_api.php — JSON API نقطة البيع. */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


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
    'session_summary' => ['GET', 'pos:sell'],
    'session_close' => ['POST', 'pos:sell'],
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
    // بريف ٢٤٤: الطباعة وإعادة الطباعة
    'print'            => ['POST', 'pos:sell'],
    'session_invoices' => ['GET',  'pos:sell'],
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
if (in_array($part, ['session_open', 'session_close', 'finalize', 'hold', 'return_ref', 'return_general', 'exchange'], true)) {
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
            $lookupMode = (string) ($in['mode'] ?? 'middle');
            api_ok(['items' => sale_lookup(
                $pdo,
                (int) $device['branch_id'],
                (string) ($in['code'] ?? ''),
                (string) ($in['q'] ?? ''),
                $lookupMode,
                array_key_exists('mode', $in)
            )]);
        case 'session_open':
            api_ok(sale_session_open(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                (float) ($in['opening_cash'] ?? 0),
                $user
            ));
        case 'session_summary':
            api_ok(sale_session_summary(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                $user
            ));
        case 'session_close':
            api_ok(sale_session_close(
                $pdo,
                (string) ($in['device_uuid'] ?? ''),
                $in,
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
            $deviceUuid = (string) ($in['device_uuid'] ?? '');
            $reason = (string) ($in['reason'] ?? '');
            $device = sale_device($pdo, $deviceUuid, $user);
            sale_drawer_open(
                $pdo,
                $deviceUuid,
                $reason,
                $user
            );
            $stEvent = $pdo->prepare(
                "SELECT e.id, e.payload, e.created_at, b.name AS branch_name, b.phone AS branch_phone,
                        d.name AS device_name, u.full_name AS cashier_name
                   FROM sale_events e
                   LEFT JOIN branches b ON b.id = e.branch_id
                   LEFT JOIN sale_devices d ON d.id = e.device_id
                   LEFT JOIN users u ON u.id = e.user_id
                  WHERE e.device_id = ? AND e.user_id = ? AND e.event_type = 'drawer_open'
                  ORDER BY e.id DESC LIMIT 1"
            );
            $stEvent->execute([(int) $device['id'], (int) $user['id']]);
            $event = $stEvent->fetch(PDO::FETCH_ASSOC);
            $eventId = (int) ($event['id'] ?? 0);

            $printMode = (string) ($device['print_mode'] ?? 'browser');
            if ($printMode === 'queue') {
                if (!$event) {
                    throw new RuntimeException('سجل فتح الدرج مش موجود.');
                }
                $eventPayload = json_decode((string) ($event['payload'] ?? '{}'), true) ?: [];
                $dt = strtotime((string) $event['created_at']);
                $payload = [
                    'kind' => 'drawer_receipt',
                    'branch_name' => (string) ($event['branch_name'] ?? ''),
                    'branch_phone' => (string) ($event['branch_phone'] ?? ''),
                    'date' => $dt ? date('d/m/Y', $dt) : '',
                    'time' => $dt ? date('h:i A', $dt) : '',
                    'device_name' => (string) ($event['device_name'] ?? ''),
                    'cashier_name' => (string) ($event['cashier_name'] ?? ''),
                    'reason' => trim((string) ($eventPayload['reason'] ?? '')),
                    'signature_label' => 'توقيع المسؤول',
                ];
                $st = $pdo->prepare(
                    'INSERT INTO print_queue (branch_id, kind, ref_id, payload, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    (int) $device['branch_id'],
                    'drawer_receipt',
                    $eventId,
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    (int) $user['id'],
                ]);
            }
            $url = 'pos_receipt.php?type=drawer&id=' . $eventId;
            api_ok([
                'opened'   => true,
                'event_id' => $eventId,
                'mode'     => $printMode,
                'url'      => $url,
            ]);
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
        case 'print':
            $deviceUuid = (string) ($in['device_uuid'] ?? '');
            $device = sale_device($pdo, $deviceUuid, $user);
            $docId = (int) ($in['doc_id'] ?? 0);
            $reprint = !empty($in['reprint']);

            if ($docId <= 0) {
                throw new SaleApiException('معرّف الفاتورة غير صحيح.', 400, 'bad_request');
            }

            $docStmt = $pdo->prepare('SELECT id, branch_id, device_id, session_id, receipt_no, status, doc_kind FROM sale_docs WHERE id = ? LIMIT 1');
            $docStmt->execute([$docId]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                throw new SaleApiException('الفاتورة مش موجودة.', 404, 'not_found');
            }
            if ($doc['status'] !== 'completed') {
                throw new SaleApiException('الفاتورة لسه مش مكتملة.', 409, 'locked');
            }
            $isOwner = !empty($user['is_owner']) || (function_exists('is_real_owner') && is_real_owner());
            if (!$isOwner && (int) $doc['branch_id'] !== (int) $device['branch_id']) {
                throw new SaleApiException('الفاتورة تابعة لفرع تاني.', 403, 'forbidden');
            }

            if ($reprint) {
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sale_events WHERE doc_id = ? AND event_type = 'receipt_reprint'");
                $cntStmt->execute([$docId]);
                $reprintNo = ((int) $cntStmt->fetchColumn()) + 1;

                sale_events_insert($pdo, [[
                    'branch_id'   => (int) $device['branch_id'],
                    'device_id'   => (int) $device['id'],
                    'session_id'  => !empty($doc['session_id']) ? (int) $doc['session_id'] : null,
                    'user_id'     => (int) $user['id'],
                    'doc_id'      => $docId,
                    'event_type'  => 'receipt_reprint',
                    'payload'     => [
                        'reprint_no'  => $reprintNo,
                        'receipt_no'  => (string) $doc['receipt_no'],
                        'device_uuid' => $deviceUuid,
                    ],
                    'approved_by' => null,
                ]]);
            }

            $printMode = (string) ($device['print_mode'] ?? 'browser');
            if ($printMode === 'queue') {
                $payload = sale_receipt_payload($docId, $reprint, $user);
                $st = $pdo->prepare(
                    'INSERT INTO print_queue (branch_id, kind, ref_id, payload, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    (int) $device['branch_id'],
                    'sale_receipt',
                    $docId,
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    (int) $user['id'],
                ]);
                $jobId = (int) $pdo->lastInsertId();
                api_ok([
                    'mode'    => 'queue',
                    'job_id'  => $jobId,
                    'reprint' => $reprint,
                ]);
            } else {
                $url = 'pos_receipt.php?id=' . $docId . ($reprint ? '&reprint=1' : '');
                api_ok([
                    'mode'    => 'browser',
                    'url'     => $url,
                    'reprint' => $reprint,
                ]);
            }

        case 'session_invoices':
            $deviceUuid = (string) ($in['device_uuid'] ?? '');
            $device = sale_device($pdo, $deviceUuid, $user);
            $session = sale_open_session($pdo, (int) $device['id']);
            if (!$session) {
                api_ok(['docs' => []]);
            }
            $st = $pdo->prepare(
                "SELECT d.id, d.doc_kind, d.receipt_no, d.gross, d.line_discount, d.invoice_discount,
                        d.net, d.paid, d.change_due, d.completed_at,
                        u.full_name AS cashier_name,
                        c.name AS customer_name,
                        (SELECT COUNT(*) FROM sale_events WHERE doc_id = d.id AND event_type = 'receipt_reprint') AS reprint_count
                   FROM sale_docs d
                   JOIN users u ON u.id = d.cashier_id
                   LEFT JOIN customers c ON c.id = d.customer_id
                  WHERE d.session_id = ? AND d.status = 'completed'
                  ORDER BY d.id DESC
                  LIMIT 50"
            );
            $st->execute([(int) $session['id']]);
            api_ok(['docs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
} catch (SaleApiException $e) {
    api_fail($e->getMessage(), $e->http, $e->apiCode);
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
}
