<?php
/**
 * stock_api.php — واجهة برمجة تطبيقات المخزن (بريف ٢١٤)
 * نظام الفجالة ستور المالي
 *
 * GET:
 *   ?action=balance&item_id=&branch_id=
 *   ?action=moves&item_id=&branch_id=&from=&to=
 *   ?action=doc&id=
 *   ?action=docs&branch_id=&type=&status=&from=&to=
 *
 * POST (CSRF):
 *   action=post (doc_id)
 *   action=cancel (doc_id, reason)
 *   action=opening (branch_id)
 *   action=adjust_create (branch_id, doc_date, notes, lines: [...], post_now)
 *
 * كل POST: stock_require_enabled() + المالك (is_real_owner())
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/stock_import_lib.php';

api_begin();
require_login();

// صلاحية المالك فقط في هذا البريف (عقد الاكتمال ١٣: 403 للمستخدمين الآخرين)
if (!is_real_owner()) {
    api_fail('غير مصرح — للمالك فقط', 403, 'forbidden');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo = db();
$uid = (int) ($_SESSION['user']['id'] ?? 1);

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    switch ($action) {
        case 'balance':
            $itemId = (int) ($_GET['item_id'] ?? 0);
            $branchId = (int) ($_GET['branch_id'] ?? 0);
            $isTransit = !empty($_GET['is_transit']) ? 1 : 0;
            if ($itemId <= 0 || $branchId <= 0) {
                api_fail('الصنف والمخزن مطلوبان', 400, 'bad_request');
            }
            api_ok(['balance' => stock_balance($pdo, $itemId, $branchId, $isTransit)]);

        case 'moves':
            $filters = [];
            if (!empty($_GET['item_id'])) {
                $filters['item_id'] = (int) $_GET['item_id'];
            }
            if (!empty($_GET['branch_id'])) {
                $filters['branch_id'] = (int) $_GET['branch_id'];
            }
            if (isset($_GET['is_transit']) && $_GET['is_transit'] !== '') {
                $filters['is_transit'] = (int) $_GET['is_transit'];
            }
            if (!empty($_GET['doc_id'])) {
                $filters['doc_id'] = (int) $_GET['doc_id'];
            }
            if (!empty($_GET['from'])) {
                $filters['from'] = (string) $_GET['from'];
            }
            if (!empty($_GET['to'])) {
                $filters['to'] = (string) $_GET['to'];
            }
            if (isset($_GET['limit'])) {
                $filters['limit'] = (int) $_GET['limit'];
            }
            if (isset($_GET['offset'])) {
                $filters['offset'] = (int) $_GET['offset'];
            }
            api_ok(['moves' => stock_moves_list($pdo, $filters)]);

        case 'doc':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                api_fail('رقم المستند مطلوب', 400, 'bad_request');
            }

            $stDoc = $pdo->prepare(
                'SELECT d.*, b.name AS branch_name, cb.name AS counter_branch_name,
                        u.full_name AS created_by_name, pu.full_name AS posted_by_name,
                        cu.full_name AS cancelled_by_name
                   FROM stock_docs d
                   LEFT JOIN branches b ON b.id = d.branch_id
                   LEFT JOIN branches cb ON cb.id = d.counter_branch_id
                   LEFT JOIN users u ON u.id = d.created_by
                   LEFT JOIN users pu ON pu.id = d.posted_by
                   LEFT JOIN users cu ON cu.id = d.cancelled_by
                  WHERE d.id = ?'
            );
            $stDoc->execute([$id]);
            $doc = $stDoc->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                api_fail('المستند غير موجود', 404, 'not_found');
            }

            $stLines = $pdo->prepare(
                'SELECT l.*, c.name AS item_name
                   FROM stock_doc_lines l
                   LEFT JOIN hq_codes c ON c.code = l.code
                  WHERE l.doc_id = ?
                  ORDER BY l.line_no ASC'
            );
            $stLines->execute([$id]);
            $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

            $stMoves = $pdo->prepare(
                'SELECT m.*, b.name AS branch_name
                   FROM stock_moves m
                   LEFT JOIN branches b ON b.id = m.branch_id
                  WHERE m.doc_id = ?
                  ORDER BY m.id ASC'
            );
            $stMoves->execute([$id]);
            $moves = $stMoves->fetchAll(PDO::FETCH_ASSOC);

            $stEvents = $pdo->prepare(
                'SELECT e.*, u.full_name AS user_name
                   FROM doc_events e
                   LEFT JOIN users u ON u.id = e.user_id
                  WHERE e.doc_kind = \'stock\' AND e.doc_id = ?
                  ORDER BY e.id ASC'
            );
            $stEvents->execute([$id]);
            $events = $stEvents->fetchAll(PDO::FETCH_ASSOC);

            api_ok([
                'doc'    => $doc,
                'lines'  => $lines,
                'moves'  => $moves,
                'events' => $events,
            ]);

        case 'docs':
            $where = [];
            $params = [];

            if (!empty($_GET['branch_id'])) {
                $where[] = 'd.branch_id = ?';
                $params[] = (int) $_GET['branch_id'];
            }
            if (!empty($_GET['type'])) {
                $where[] = 'd.doc_type = ?';
                $params[] = (string) $_GET['type'];
            }
            if (!empty($_GET['status'])) {
                $where[] = 'd.status = ?';
                $params[] = (string) $_GET['status'];
            }
            if (!empty($_GET['from'])) {
                $where[] = 'd.doc_date >= ?';
                $params[] = (string) $_GET['from'];
            }
            if (!empty($_GET['to'])) {
                $where[] = 'd.doc_date <= ?';
                $params[] = (string) $_GET['to'];
            }

            $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;

            $sql = "
                SELECT d.*, b.name AS branch_name, u.full_name AS created_by_name
                  FROM stock_docs d
                  LEFT JOIN branches b ON b.id = d.branch_id
                  LEFT JOIN users u ON u.id = d.created_by
                 $whereSql
                 ORDER BY d.doc_date DESC, d.id DESC
                 LIMIT $limit
            ";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $docs = $st->fetchAll(PDO::FETCH_ASSOC);

            api_ok(['docs' => $docs]);

        case 'recon':
            $branchId = (int) ($_GET['branch_id'] ?? 0);
            if ($branchId <= 0) {
                api_fail('رقم المخزن مطلوب', 400, 'bad_request');
            }
            try {
                $recon = stock_recon($pdo, $branchId);
                api_ok(['recon' => $recon]);
            } catch (Throwable $e) {
                api_fail($e->getMessage(), 400, 'bad_request');
            }

        default:
            api_fail('طلب غير معروف', 400, 'bad_request');
    }
}

if ($method === 'POST') {
    api_csrf();
    $input = api_input();
    $action = (string) ($input['action'] ?? $_GET['action'] ?? '');

    try {
        switch ($action) {
            case 'post':
                $docId = (int) ($input['doc_id'] ?? 0);
                if ($docId <= 0) {
                    api_fail('رقم المستند مطلوب', 400, 'bad_request');
                }
                stock_post($pdo, $docId, $uid);
                api_ok(['doc_id' => $docId]);

            case 'cancel':
                $docId = (int) ($input['doc_id'] ?? 0);
                $reason = trim((string) ($input['reason'] ?? ''));
                if ($docId <= 0) {
                    api_fail('رقم المستند مطلوب', 400, 'bad_request');
                }
                if ($reason === '') {
                    api_fail('سبب الإلغاء مطلوب', 400, 'bad_request');
                }
                stock_cancel($pdo, $docId, $uid, $reason);
                api_ok(['doc_id' => $docId]);

            case 'opening':
                $branchId = (int) ($input['branch_id'] ?? 0);
                if ($branchId <= 0) {
                    api_fail('رقم المخزن مطلوب', 400, 'bad_request');
                }
                $docId = stock_opening_from_mirror($pdo, $branchId, $uid);
                api_ok(['doc_id' => $docId]);

            case 'adjust_create':
                $branchId = (int) ($input['branch_id'] ?? 0);
                $docDate = !empty($input['doc_date']) ? (string) $input['doc_date'] : date('Y-m-d');
                $notes = (string) ($input['notes'] ?? '');
                $lines = (array) ($input['lines'] ?? []);
                $postNow = !empty($input['post_now']);

                if ($branchId <= 0) {
                    api_fail('رقم المخزن مطلوب', 400, 'bad_request');
                }
                if (empty($lines)) {
                    api_fail('سطور التسوية مطلوبة', 400, 'bad_request');
                }

                $docId = stock_doc_create($pdo, 'adjust', [
                    'branch_id' => $branchId,
                    'doc_date'  => $docDate,
                    'notes'     => $notes,
                ], $lines, $uid);

                if ($postNow) {
                    stock_post($pdo, $docId, $uid);
                }

                api_ok(['doc_id' => $docId]);

            case 'import_run':
                stock_require_enabled();
                $bId = isset($input['branch_id']) && $input['branch_id'] !== '' ? (int)$input['branch_id'] : null;
                $importRes = stock_import_run($pdo, $uid, $bId);
                api_ok(['import' => $importRes]);

            default:
                api_fail('عملية غير معروفة', 400, 'bad_request');
        }
    } catch (RuntimeException $re) {
        api_fail($re->getMessage(), 400, 'bad_request');
    }
}

api_fail('طريقة الطلب غير مدعومة', 405, 'bad_request');
