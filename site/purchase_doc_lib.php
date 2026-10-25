<?php
/**
 * purchase_doc_lib.php — فواتير الشراء ومرتجعات المورد على دفتر الموقع (بريف ٢١٧).
 * القيم للعرض والمخزن بس؛ الملف ده مابيلمسش حساب المورد ولا أي قيد مالي.
 */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';

const PURCHASE_INTERNAL_ERP_SUPPLIERS = [4, 1000180, 1000181, 3000091, 1000182, 1000245, 1000252];

function purchase_supplier(PDO $pdo, int $supplierId, bool $forUpdate = false): array
{
    $sql = 'SELECT id, name, erp_supplier_id, erp_supplier_code, is_active FROM suppliers WHERE id = ?'
         . ($forUpdate ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$supplierId]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$supplier || !(int) $supplier['is_active']) {
        throw new RuntimeException('المورد مش موجود أو متوقف.');
    }
    if ($supplier['erp_supplier_id'] !== null
        && in_array((int) $supplier['erp_supplier_id'], PURCHASE_INTERNAL_ERP_SUPPLIERS, true)) {
        throw new RuntimeException('المورد ده فرع داخلي، ويتسجل من التحويلات مش فاتورة شراء.');
    }
    return $supplier;
}

/** @return array<int,array<string,mixed>> */
function purchase_normalize_lines(PDO $pdo, array $lines): array
{
    if (!$lines) {
        throw new RuntimeException('لازم تضيف صنف واحد على الأقل.');
    }

    $codes = [];
    foreach ($lines as $line) {
        $code = trim((string) ($line['code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    $codeRows = item_code_factors($pdo, array_values(array_unique($codes)));
    $out = [];
    $lineNo = 1;
    foreach ($lines as $line) {
        $code = trim((string) ($line['code'] ?? ''));
        $codeRow = $codeRows[$code] ?? null;
        $itemId = (int) ($line['item_id'] ?? ($codeRow['item_id'] ?? 0));
        $qtyUnits = round((float) ($line['qty_units'] ?? 0), 3);
        $unitPrice = round((float) ($line['unit_price'] ?? 0), 4);
        $discount = round((float) ($line['discount_pct'] ?? 0), 3);
        $lineFactor = isset($line['unit_factor']) && (float) $line['unit_factor'] > 0
            ? (float) $line['unit_factor'] : null;
        $codeFactor = $codeRow !== null ? (float) $codeRow['unit_factor'] : null;
        $pieces = item_line_pieces($qtyUnits, $lineFactor, $codeFactor);

        if ($code === '' || $itemId <= 0 || $qtyUnits <= 0 || $pieces['pieces'] <= 0) {
            throw new RuntimeException("بيانات السطر $lineNo ناقصة أو الكمية مش صحيحة.");
        }
        if ($unitPrice < 0 || $discount < 0 || $discount > 100) {
            throw new RuntimeException("السعر أو الخصم في السطر $lineNo مش صحيح.");
        }

        $lineTotal = round($qtyUnits * $unitPrice * (1 - ($discount / 100)), 2);
        $unitCost = round($lineTotal / $pieces['pieces'], 4);
        $out[] = [
            'line_no' => $lineNo++,
            'item_id' => $itemId,
            'code' => $code,
            'unit_factor' => round((float) $pieces['factor'], 4),
            'qty_units' => $qtyUnits,
            'qty_pieces' => round((float) $pieces['pieces'], 3),
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'discount_pct' => $discount,
            'line_total' => $lineTotal,
            'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
        ];
    }
    return $out;
}

function purchase_save_draft(PDO $pdo, string $type, array $head, array $lines, int $uid, ?int $docId = null): int
{
    if (!in_array($type, ['purchase', 'purchase_return'], true)) {
        throw new RuntimeException('نوع مستند الشراء مش صحيح.');
    }
    $branchId = (int) ($head['branch_id'] ?? 0);
    $supplierId = (int) ($head['supplier_id'] ?? 0);
    if ($branchId <= 0 || $branchId === 5 || !stock_get_branch_info($branchId)) {
        throw new RuntimeException('المخزن غير صالح.');
    }
    purchase_supplier($pdo, $supplierId);
    $normalized = purchase_normalize_lines($pdo, $lines);
    $total = round(array_sum(array_column($normalized, 'line_total')), 2);
    $origDocId = !empty($head['orig_doc_id']) ? (int) $head['orig_doc_id'] : null;
    if ($type === 'purchase' && $origDocId !== null) {
        throw new RuntimeException('فاتورة الشراء مالهاش مستند أصل.');
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        if ($docId === null) {
            $st = $pdo->prepare(
                'INSERT INTO stock_docs
                 (doc_type, branch_id, supplier_id, orig_doc_id, total_amount, status, doc_date, ref_text, notes, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, \'draft\', ?, ?, ?, ?, NOW())'
            );
            $st->execute([
                $type, $branchId, $supplierId, $origDocId, $total,
                (string) ($head['doc_date'] ?? date('Y-m-d')),
                trim((string) ($head['ref_text'] ?? '')) ?: null,
                trim((string) ($head['notes'] ?? '')) ?: null,
                $uid,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $event = 'created';
        } else {
            $lock = $pdo->prepare('SELECT status, doc_type FROM stock_docs WHERE id = ? FOR UPDATE');
            $lock->execute([$docId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$current || $current['status'] !== 'draft' || $current['doc_type'] !== $type) {
                throw new RuntimeException('المستند مش مسودة قابلة للتعديل.');
            }
            $pdo->prepare(
                'UPDATE stock_docs SET branch_id=?, supplier_id=?, orig_doc_id=?, total_amount=?, doc_date=?, ref_text=?, notes=?, hq_match=NULL WHERE id=?'
            )->execute([
                $branchId, $supplierId, $origDocId, $total,
                (string) ($head['doc_date'] ?? date('Y-m-d')),
                trim((string) ($head['ref_text'] ?? '')) ?: null,
                trim((string) ($head['notes'] ?? '')) ?: null,
                $docId,
            ]);
            // المسودات بتتعدل من غير حذف تاريخي: بنصفّر القديم، والسطور الحالية
            // بتتكتب تاني بنفس line_no عن طريق مفتاح المستند والسطر.
            $pdo->prepare('UPDATE stock_doc_lines SET qty_units=0,qty_pieces=0,unit_cost=0,unit_price=0,discount_pct=0,line_total=0,notes=\'سطر اتشال من المسودة\' WHERE doc_id=?')->execute([$docId]);
            $event = 'edited';
        }

        $ins = $pdo->prepare(
            'INSERT INTO stock_doc_lines
             (doc_id,line_no,item_id,code,unit_factor,qty_units,qty_pieces,unit_cost,unit_price,discount_pct,line_total,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),code=VALUES(code),unit_factor=VALUES(unit_factor),
             qty_units=VALUES(qty_units),qty_pieces=VALUES(qty_pieces),unit_cost=VALUES(unit_cost),
             unit_price=VALUES(unit_price),discount_pct=VALUES(discount_pct),line_total=VALUES(line_total),notes=VALUES(notes)'
        );
        foreach ($normalized as $line) {
            $ins->execute([
                $docId, $line['line_no'], $line['item_id'], $line['code'], $line['unit_factor'],
                $line['qty_units'], $line['qty_pieces'], $line['unit_cost'], $line['unit_price'],
                $line['discount_pct'], $line['line_total'], $line['notes'],
            ]);
        }
        $pdo->prepare(
            'INSERT INTO doc_events (doc_kind,doc_id,event,user_id,at,details) VALUES (\'stock\',?,?,?,NOW(),?)'
        )->execute([$docId, $event, $uid, json_encode(['lines_count'=>count($normalized),'total_amount'=>$total], JSON_UNESCAPED_UNICODE)]);
        if ($ownTx) $pdo->commit();
        return $docId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function purchase_validate_return(PDO $pdo, array $doc): void
{
    if ($doc['doc_type'] !== 'purchase_return' || empty($doc['orig_doc_id'])) return;

    $orig = $pdo->prepare('SELECT id,doc_type,status,branch_id,supplier_id FROM stock_docs WHERE id=? FOR UPDATE');
    $orig->execute([(int) $doc['orig_doc_id']]);
    $origin = $orig->fetch(PDO::FETCH_ASSOC);
    if (!$origin || $origin['doc_type'] !== 'purchase' || $origin['status'] !== 'posted') {
        throw new RuntimeException('فاتورة الأصل مش موجودة أو مش مترحلة.');
    }
    if ((int) $origin['branch_id'] !== (int) $doc['branch_id'] || (int) $origin['supplier_id'] !== (int) $doc['supplier_id']) {
        throw new RuntimeException('المرتجع لازم يبقى لنفس مخزن ومورد فاتورة الأصل.');
    }

    $st = $pdo->prepare(
        'SELECT n.item_id, COALESCE(c.name,n.item_id) AS item_name, COALESCE(o.bought,0) AS bought,
                COALESCE(r.returned,0) AS returned, n.now_qty
         FROM (SELECT item_id,SUM(qty_pieces) now_qty FROM stock_doc_lines WHERE doc_id=? GROUP BY item_id) n
         LEFT JOIN (SELECT item_id,SUM(qty_pieces) bought FROM stock_doc_lines WHERE doc_id=? GROUP BY item_id) o ON o.item_id=n.item_id
         LEFT JOIN (SELECT item_id,MAX(name) name FROM hq_codes GROUP BY item_id) c ON c.item_id=n.item_id
         LEFT JOIN (
             SELECT l.item_id,SUM(l.qty_pieces) returned
             FROM stock_docs d JOIN stock_doc_lines l ON l.doc_id=d.id
             WHERE d.doc_type=\'purchase_return\' AND d.orig_doc_id=? AND d.status=\'posted\'
             GROUP BY l.item_id
         ) r ON r.item_id=n.item_id'
    );
    $st->execute([(int) $doc['id'], (int) $origin['id'], (int) $origin['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $over = round((float) $row['returned'] + (float) $row['now_qty'] - (float) $row['bought'], 3);
        if ($over > 0) {
            throw new RuntimeException('مرتجع الصنف «' . $row['item_name'] . '» أكبر من المتاح بـ' . number_format($over, 3, '.', '') . ' قطعة.');
        }
    }
}

function purchase_post(PDO $pdo, int $docId, int $uid): void
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        stock_require_enabled();
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id=? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc || !in_array($doc['doc_type'], ['purchase','purchase_return'], true) || $doc['status'] !== 'draft') {
            throw new RuntimeException('مستند الشراء مش مسودة قابلة للترحيل.');
        }
        stock_require_live((int) $doc['branch_id']);
        purchase_supplier($pdo, (int) $doc['supplier_id'], true);

        if ($doc['doc_type'] === 'purchase' && trim((string) $doc['ref_text']) !== '') {
            $dup = $pdo->prepare(
                'SELECT id FROM stock_docs WHERE doc_type=\'purchase\' AND status=\'posted\' AND supplier_id=?
                 AND ref_text=? AND YEAR(doc_date)=YEAR(?) AND id<>? LIMIT 1 FOR UPDATE'
            );
            $dup->execute([(int) $doc['supplier_id'], $doc['ref_text'], $doc['doc_date'], $docId]);
            $dupId = $dup->fetchColumn();
            if ($dupId !== false) throw new RuntimeException('الفاتورة دي اتسجّلت قبل كده #' . $dupId . '.');
        }
        purchase_validate_return($pdo, $doc);
        stock_post($pdo, $docId, $uid);
        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function purchase_cancel(PDO $pdo, int $docId, int $uid, string $reason): void
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id=? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc || !in_array($doc['doc_type'], ['purchase','purchase_return'], true)) {
            throw new RuntimeException('مستند الشراء مش موجود.');
        }
        stock_require_live((int) $doc['branch_id']);
        if ($doc['doc_type'] === 'purchase') {
            $ret = $pdo->prepare('SELECT id FROM stock_docs WHERE orig_doc_id=? AND doc_type=\'purchase_return\' AND status=\'posted\' LIMIT 1 FOR UPDATE');
            $ret->execute([$docId]);
            if ($ret->fetchColumn() !== false) throw new RuntimeException('ألغي المرتجع الأول قبل فاتورة الشراء.');
        }
        stock_cancel($pdo, $docId, $uid, $reason);
        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * مطابقة كل المستندات المطلوبة مع True باستعلام مرآة واحد.
 * @return array<int,array{state:string,hq_match:?string,doc_no:?string}>
 */
function purchase_match_hq(PDO $pdo, array $docIds): array
{
    $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds), static fn($v) => $v > 0)));
    if (!$docIds) return [];
    $marks = implode(',', array_fill(0, count($docIds), '?'));
    $st = $pdo->prepare(
        "SELECT d.id doc_id,h.sth_id,h.suffix,h.doc_no
         FROM stock_docs d
         JOIN suppliers s ON s.id=d.supplier_id
         JOIN branches b ON b.id=d.branch_id
         JOIN hq_invoices h ON h.supplier_id=s.erp_supplier_id
          AND h.doc_type=IF(d.doc_type='purchase',2,6)
          AND h.company_branch=b.sto_id
          AND DATE(h.invoice_date) BETWEEN DATE_SUB(d.doc_date,INTERVAL 2 DAY) AND DATE_ADD(d.doc_date,INTERVAL 2 DAY)
          AND ABS(h.net_amount-d.total_amount)<=1
         WHERE d.id IN ($marks) AND d.status='posted'"
    );
    $st->execute($docIds);
    $found = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(int) $row['doc_id']][] = $row;

    $result = [];
    $unique = [];
    foreach ($docIds as $id) {
        $rows = $found[$id] ?? [];
        if (count($rows) === 1) {
            $match = $rows[0]['sth_id'] . ':' . $rows[0]['suffix'];
            $unique[$id] = $match;
            $result[$id] = ['state'=>'matched','hq_match'=>$match,'doc_no'=>(string) $rows[0]['doc_no']];
        } elseif (count($rows) > 1) {
            $result[$id] = ['state'=>'ambiguous','hq_match'=>null,'doc_no'=>null];
        } else {
            $result[$id] = ['state'=>'missing','hq_match'=>null,'doc_no'=>null];
        }
    }

    $case = [];
    $params = [];
    foreach ($docIds as $id) {
        $case[] = 'WHEN ? THEN ?';
        $params[] = $id;
        $params[] = $unique[$id] ?? null;
    }
    $params = array_merge($params, $docIds);
    if (empty($GLOBALS['VIEW_ALL_PAGE'])) {
        $pdo->prepare('UPDATE stock_docs SET hq_match=CASE id ' . implode(' ', $case) . ' END WHERE id IN (' . $marks . ')')->execute($params);
    }
    return $result;
}

function purchase_last_true_price(PDO $pdo, int $supplierId, int $itemId): ?float
{
    $supplier = purchase_supplier($pdo, $supplierId);
    if (empty($supplier['erp_supplier_id'])) return null;
    $st = $pdo->prepare(
        'SELECT l.unit_price FROM hq_invoices h JOIN hq_invoice_lines l ON l.sth_id=h.sth_id AND l.suffix=h.suffix
         WHERE h.doc_type=2 AND h.supplier_id=? AND l.item_id=? ORDER BY h.invoice_date DESC,h.sth_id DESC LIMIT 1'
    );
    $st->execute([(int) $supplier['erp_supplier_id'], $itemId]);
    $price = $st->fetchColumn();
    return $price === false ? null : round((float) $price, 4);
}
