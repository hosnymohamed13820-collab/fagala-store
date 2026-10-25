<?php
/** purchase_docs_api.php — API فواتير الشراء ومرتجعات المورد. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/purchase_doc_lib.php';

api_begin();
require_login();
require_module_access('purchase_docs');

$pdo = db();
$uid = (int) ($_SESSION['user']['id'] ?? 0);
$userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function purchase_api_branch(int $branchId): void
{
    $userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
    if (!is_real_owner() && ($userBranch <= 0 || $branchId !== $userBranch)) {
        api_fail('مسموح تشتغل على مخزن فرعك بس', 403, 'forbidden');
    }
}

function purchase_api_doc(PDO $pdo, int $docId, bool $lock = false): array
{
    $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id=? AND doc_type IN (\'purchase\',\'purchase_return\')' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) api_fail('مستند الشراء مش موجود', 404, 'not_found');
    purchase_api_branch((int) $doc['branch_id']);
    return $doc;
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'docs');
    if ($action === 'suppliers') {
        $marks = implode(',', array_fill(0, count(PURCHASE_INTERNAL_ERP_SUPPLIERS), '?'));
        $st = $pdo->prepare("SELECT id,name,erp_supplier_id FROM suppliers WHERE is_active=1 AND (erp_supplier_id IS NULL OR erp_supplier_id NOT IN ($marks)) ORDER BY name");
        $st->execute(PURCHASE_INTERNAL_ERP_SUPPLIERS);
        api_ok(['suppliers'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'code') {
        $code = trim((string) ($_GET['code'] ?? ''));
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);
        $st = $pdo->prepare('SELECT code,item_id,name,unit_factor,price,cost FROM hq_codes WHERE code=? LIMIT 1');
        $st->execute([$code]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) api_fail('الكود مش موجود', 404, 'not_found');
        $item['last_purchase_price'] = $supplierId > 0 ? purchase_last_true_price($pdo, $supplierId, (int) $item['item_id']) : null;
        api_ok(['item'=>$item]);
    }
    if ($action === 'doc') {
        $doc = purchase_api_doc($pdo, (int) ($_GET['id'] ?? 0));
        $st = $pdo->prepare('SELECT l.*,COALESCE(n.name,l.code) item_name FROM stock_doc_lines l LEFT JOIN (SELECT item_id,MAX(name) name FROM hq_codes GROUP BY item_id) n ON n.item_id=l.item_id WHERE l.doc_id=? AND l.qty_pieces<>0 ORDER BY l.line_no');
        $st->execute([(int) $doc['id']]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $ev = $pdo->prepare('SELECT e.*,u.full_name user_name FROM doc_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.doc_kind=\'stock\' AND e.doc_id=? ORDER BY e.id');
        $ev->execute([(int) $doc['id']]);
        api_ok(['doc'=>$doc,'lines'=>$lines,'events'=>$ev->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'origins') {
        api_require('purchase_docs:create');
        $branchId = (int) ($_GET['branch_id'] ?? $userBranch);
        purchase_api_branch($branchId);
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);
        $st = $pdo->prepare('SELECT id,doc_no,doc_year,doc_date,ref_text,total_amount FROM stock_docs WHERE doc_type=\'purchase\' AND status=\'posted\' AND branch_id=? AND supplier_id=? ORDER BY doc_date DESC,id DESC LIMIT 100');
        $st->execute([$branchId,$supplierId]);
        api_ok(['origins'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'return_available') {
        api_require('purchase_docs:create');
        $origin = purchase_api_doc($pdo, (int) ($_GET['orig_doc_id'] ?? 0));
        if ($origin['doc_type'] !== 'purchase' || $origin['status'] !== 'posted') api_fail('فاتورة الأصل مش مترحلة',400,'validation');
        $st = $pdo->prepare(
            'SELECT o.item_id,MAX(o.code) code,MAX(o.unit_factor) unit_factor,MAX(o.unit_price) unit_price,
                    MAX(COALESCE(n.name,o.code)) item_name,SUM(o.qty_pieces)-COALESCE(r.returned,0) available_pieces
             FROM stock_doc_lines o
             LEFT JOIN (SELECT item_id,MAX(name) name FROM hq_codes GROUP BY item_id) n ON n.item_id=o.item_id
             LEFT JOIN (
                 SELECT l.item_id,SUM(l.qty_pieces) returned FROM stock_docs d JOIN stock_doc_lines l ON l.doc_id=d.id
                 WHERE d.doc_type=\'purchase_return\' AND d.orig_doc_id=? AND d.status=\'posted\' GROUP BY l.item_id
             ) r ON r.item_id=o.item_id
             WHERE o.doc_id=? GROUP BY o.item_id,r.returned HAVING available_pieces>0 ORDER BY item_name'
        );
        $st->execute([(int)$origin['id'],(int)$origin['id']]);
        api_ok(['lines'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    $where = ["d.doc_type IN ('purchase','purchase_return')"];
    $params = [];
    if (!is_real_owner()) { $where[]='d.branch_id=?'; $params[]=$userBranch; }
    elseif (!empty($_GET['branch_id'])) { $where[]='d.branch_id=?'; $params[]=(int)$_GET['branch_id']; }
    if (!empty($_GET['supplier_id'])) { $where[]='d.supplier_id=?'; $params[]=(int)$_GET['supplier_id']; }
    if (!empty($_GET['type'])) { $where[]='d.doc_type=?'; $params[]=(string)$_GET['type']; }
    if (!empty($_GET['status'])) { $where[]='d.status=?'; $params[]=(string)$_GET['status']; }
    if (!empty($_GET['from'])) { $where[]='d.doc_date>=?'; $params[]=(string)$_GET['from']; }
    if (!empty($_GET['to'])) { $where[]='d.doc_date<=?'; $params[]=(string)$_GET['to']; }
    $st = $pdo->prepare('SELECT d.*,s.name supplier_name,b.name branch_name,SUM(CASE WHEN l.qty_pieces<>0 THEN 1 ELSE 0 END) lines_count FROM stock_docs d JOIN suppliers s ON s.id=d.supplier_id JOIN branches b ON b.id=d.branch_id LEFT JOIN stock_doc_lines l ON l.doc_id=d.id WHERE '.implode(' AND ',$where).' GROUP BY d.id ORDER BY d.doc_date DESC,d.id DESC LIMIT 200');
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    $matches = purchase_match_hq($pdo, array_column($docs,'id'));
    foreach ($docs as &$doc) $doc['hq_state'] = $matches[(int)$doc['id']] ?? ['state'=>'missing','hq_match'=>null,'doc_no'=>null];
    unset($doc);
    api_ok(['docs'=>$docs]);
}

api_csrf();
$input = api_input();
$action = (string) ($input['action'] ?? '');
try {
    if ($action === 'save') {
        api_require('purchase_docs:create');
        $branchId = (int) ($input['branch_id'] ?? 0);
        purchase_api_branch($branchId);
        $type = (string) ($input['doc_type'] ?? 'purchase');
        $docId = !empty($input['doc_id']) ? (int) $input['doc_id'] : null;
        if ($docId !== null) purchase_api_doc($pdo, $docId);
        $id = purchase_save_draft($pdo,$type,$input,is_array($input['lines'] ?? null)?$input['lines']:[],$uid,$docId);
        api_ok(['doc_id'=>$id]);
    }
    if ($action === 'post') {
        api_require('purchase_docs:post');
        $doc = purchase_api_doc($pdo,(int)($input['doc_id'] ?? 0));
        purchase_post($pdo,(int)$doc['id'],$uid);
        $match = purchase_match_hq($pdo,[(int)$doc['id']]);
        api_ok(['doc_id'=>(int)$doc['id'],'hq_state'=>$match[(int)$doc['id']] ?? null]);
    }
    if ($action === 'cancel') {
        api_require('purchase_docs:cancel');
        $doc = purchase_api_doc($pdo,(int)($input['doc_id'] ?? 0));
        purchase_cancel($pdo,(int)$doc['id'],$uid,trim((string)($input['reason'] ?? '')));
        api_ok(['doc_id'=>(int)$doc['id']]);
    }
    api_fail('طلب غير معروف',400,'bad_request');
} catch (RuntimeException $e) {
    api_fail($e->getMessage(),400,'validation');
}
