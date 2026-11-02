<?php
/**
 * purchase_doc_lib.php — فواتير الشراء ومرتجعات المورد على دفتر الموقع (بريف ٢١٧).
 * القيم للعرض والمخزن بس؛ الملف ده مابيلمسش حساب المورد ولا أي قيد مالي.
 */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/barcode_lib.php';

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
function purchase_normalize_lines(PDO $pdo, array $lines, ?int $origDocId = null): array
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
    $originCosts = null;
    if ($origDocId !== null) {
        $ids = array_values(array_unique(array_column($codeRows, 'item_id')));
        $originCosts = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT item_id,SUM(qty_pieces*unit_cost)/SUM(qty_pieces) unit_cost
                FROM stock_doc_lines WHERE doc_id=? AND qty_pieces>0 AND item_id IN ($marks) GROUP BY item_id");
            $st->execute(array_merge([$origDocId], $ids));
            $originCosts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
    $out = [];
    $lineNo = 1;
    foreach ($lines as $line) {
        $code = trim((string) ($line['code'] ?? ''));
        $codeRow = $codeRows[$code] ?? null;
        if ($code === '' || $codeRow === null) {
            throw new RuntimeException("الكود في السطر $lineNo مش موجود.");
        }
        $itemId = (int) $codeRow['item_id'];
        $qtyUnits = round((float) ($line['qty_units'] ?? 0), 3);
        $unitPrice = round((float) ($line['unit_price'] ?? 0), 4);
        $discount = round((float) ($line['discount_pct'] ?? 0), 3);
        $codeFactor = (float) $codeRow['unit_factor'];
        if ($originCosts !== null) {
            if (!array_key_exists($itemId, $originCosts)) throw new RuntimeException('الصنف مش موجود في فاتورة الأصل.');
            $unitPrice = round((float) $originCosts[$itemId] * $codeFactor, 4);
            $discount = 0;
        }
        $pieces = item_line_pieces($qtyUnits, null, $codeFactor);

        if ($itemId <= 0 || $qtyUnits <= 0 || $pieces['pieces'] <= 0) {
            throw new RuntimeException("بيانات السطر $lineNo ناقصة أو الكمية مش صحيحة.");
        }
        if ($unitPrice < 0 || $discount < 0 || $discount > 100) {
            throw new RuntimeException("السعر أو الخصم في السطر $lineNo مش صحيح.");
        }

        $out[] = [
            'line_no' => $lineNo++,
            'item_id' => $itemId,
            'code' => $code,
            'unit_factor' => round((float) $pieces['factor'], 4),
            'qty_units' => $qtyUnits,
            'qty_pieces' => round((float) $pieces['pieces'], 3),
            'unit_price' => $unitPrice,
            'discount_pct' => $discount,
            'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
        ];
    }
    return $out;
}

/** مثال محمد: ١٠ أقلام × ١٢ جنيه وخصم فاتورة ١٠٪ = ١٠٨ جنيه، وتكلفة القطعة ١٠٫٨. */
function purchase_calculate_totals(array $lines, mixed $discount = null, bool $hasOrigin = false): array
{
    if ($hasOrigin && $discount !== null) throw new RuntimeException('المرتجع على أصل مالوش خصم فاتورة.');
    if ($discount !== null && (!is_array($discount) || !in_array($discount['type'] ?? null, ['percent', 'amount'], true)
        || !is_numeric($discount['value'] ?? null) || !is_finite((float) $discount['value']))) {
        throw new RuntimeException('خصم الفاتورة مش صحيح.');
    }
    $type = $discount['type'] ?? null;
    $value = round((float) ($discount['value'] ?? 0), 4);
    if ($value < 0 || ($type === 'percent' && $value > 100)) throw new RuntimeException('خصم الفاتورة مش صحيح.');
    $gross = 0;
    $largest = null;
    foreach ($lines as $i => &$line) {
        $after = (int) round(round($line['qty_units'] * $line['unit_price'] * (1 - $line['discount_pct'] / 100), 2) * 100);
        $line['after_cents'] = $after;
        $gross += $after;
        if ($largest === null || $after > $lines[$largest]['after_cents']) $largest = $i;
    }
    unset($line);
    if ($type === 'amount' && $value > $gross / 100) throw new RuntimeException('خصم الفاتورة أكبر من الإجمالي.');
    $target = (int) round($type === 'percent' ? $gross * $value / 100 : $value * 100);
    $allocated = 0;
    foreach ($lines as &$line) {
        $alloc = (int) round($type === 'percent' ? $line['after_cents'] * $value / 100
            : ($gross > 0 ? $value * 100 * $line['after_cents'] / $gross : 0));
        $line['alloc_cents'] = $alloc;
        $allocated += $alloc;
    }
    unset($line);
    if ($largest !== null) $lines[$largest]['alloc_cents'] += $target - $allocated;
    foreach ($lines as &$line) {
        $line['inv_discount_alloc'] = $line['alloc_cents'] / 100.0;
        $line['line_total'] = ($line['after_cents'] - $line['alloc_cents']) / 100.0;
        $line['unit_cost'] = round($line['line_total'] / $line['qty_pieces'], 4);
        $line['net_unit_cost'] = $line['line_total'] / $line['qty_units'];
        unset($line['after_cents'], $line['alloc_cents']);
    }
    unset($line);
    return ['lines'=>$lines, 'inv_discount'=>['type'=>$type, 'value'=>$value],
        'gross_amount'=>$gross / 100.0, 'total_amount'=>($gross - $target) / 100.0];
}

function purchase_save_draft(PDO $pdo, string $type, array $head, array $lines, int $uid, ?int $docId = null): int
{
    if (!in_array($type, ['purchase', 'purchase_return'], true)) {
        throw new RuntimeException('نوع مستند الشراء مش صحيح.');
    }
    $branchId = (int) ($head['branch_id'] ?? 0);
    $supplierId = (int) ($head['supplier_id'] ?? 0);
    $returnReason = $type === 'purchase_return' ? trim((string) ($head['return_reason'] ?? '')) : '';
    if (mb_strlen($returnReason)>255) throw new RuntimeException('سبب المرتجع بحد أقصى ٢٥٥ حرف.');
    if ($branchId <= 0 || $branchId === 5 || !stock_get_branch_info($branchId)) {
        throw new RuntimeException('المخزن غير صالح.');
    }
    purchase_supplier($pdo, $supplierId);
    $origDocId = !empty($head['orig_doc_id']) ? (int) $head['orig_doc_id'] : null;
    if ($type === 'purchase' && $origDocId !== null) {
        throw new RuntimeException('فاتورة الشراء مالهاش مستند أصل.');
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        if ($origDocId !== null) {
            $origin = $pdo->prepare('SELECT doc_type,status,branch_id,supplier_id FROM stock_docs WHERE id=? FOR UPDATE');
            $origin->execute([$origDocId]);
            $source = $origin->fetch(PDO::FETCH_ASSOC);
            if (!$source || $source['doc_type'] !== 'purchase' || $source['status'] !== 'posted'
                || (int) $source['branch_id'] !== $branchId || (int) $source['supplier_id'] !== $supplierId) {
                throw new RuntimeException('فاتورة الأصل لازم تكون مترحلة ولنفس المخزن والمورد.');
            }
        }
        $calc = purchase_calculate_totals(purchase_normalize_lines($pdo, $lines, $origDocId), $head['inv_discount'] ?? null, $origDocId !== null);
        $normalized = $calc['lines'];
        $total = $calc['total_amount'];
        $invType = $calc['inv_discount']['type'];
        $invValue = $calc['inv_discount']['value'];
        $gross = $calc['gross_amount'];
        $canEditDate = (function_exists('is_real_owner') && is_real_owner()) || (string) setting('purchase_date_editable', '1') !== '0';
        if ($docId === null) {
            $docDate = $canEditDate ? (string) ($head['doc_date'] ?? date('Y-m-d')) : date('Y-m-d');
            $st = $pdo->prepare(
                'INSERT INTO stock_docs
                 (doc_type, branch_id, supplier_id, orig_doc_id, total_amount, inv_discount_type, inv_discount_value, gross_amount, status, doc_date, ref_text, notes, return_reason, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?, NOW())'
            );
            $st->execute([
                $type, $branchId, $supplierId, $origDocId, $total, $invType, $invValue, $gross,
                $docDate,
                trim((string) ($head['ref_text'] ?? '')) ?: null,
                trim((string) ($head['notes'] ?? '')) ?: null,
                $returnReason ?: null,
                $uid,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $event = 'created';
        } else {
            $lock = $pdo->prepare('SELECT status, doc_type, doc_date FROM stock_docs WHERE id = ? FOR UPDATE');
            $lock->execute([$docId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if ($current && $current['doc_type'] !== $type) {
                throw new RuntimeException($current['doc_type'] === 'purchase_return' ? 'المستند ده مرتجع ومينفعش يتحوّل شراء' : 'المستند ده شراء ومينفعش يتحوّل مرتجع');
            }
            if (!$current || $current['status'] !== 'draft' || $current['doc_type'] !== $type) {
                throw new RuntimeException('المستند مش مسودة قابلة للتعديل.');
            }
            $docDate = $canEditDate ? (string) ($head['doc_date'] ?? $current['doc_date']) : (string) $current['doc_date'];
            $pdo->prepare(
                'UPDATE stock_docs SET branch_id=?, supplier_id=?, orig_doc_id=?, total_amount=?, inv_discount_type=?, inv_discount_value=?, gross_amount=?, doc_date=?, ref_text=?, notes=?, return_reason=?, hq_match=NULL WHERE id=?'
            )->execute([
                $branchId, $supplierId, $origDocId, $total, $invType, $invValue, $gross,
                $docDate,
                trim((string) ($head['ref_text'] ?? '')) ?: null,
                trim((string) ($head['notes'] ?? '')) ?: null,
                $returnReason ?: null,
                $docId,
            ]);
            // المسودات بتتعدل من غير حذف تاريخي: بنصفّر القديم، والسطور الحالية
            // بتتكتب تاني بنفس line_no عن طريق مفتاح المستند والسطر.
            $pdo->prepare('UPDATE stock_doc_lines SET qty_units=0,qty_pieces=0,unit_cost=0,unit_price=0,discount_pct=0,line_total=0,inv_discount_alloc=0,notes=\'سطر اتشال من المسودة\' WHERE doc_id=?')->execute([$docId]);
            $event = 'edited';
        }

        $values = [];
        $params = [];
        foreach ($normalized as $line) {
            $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push($params, $docId, $line['line_no'], $line['item_id'], $line['code'], $line['unit_factor'],
                $line['qty_units'], $line['qty_pieces'], $line['unit_cost'], $line['unit_price'],
                $line['discount_pct'], $line['line_total'], $line['inv_discount_alloc'], $line['notes']);
        }
        $pdo->prepare(
            'INSERT INTO stock_doc_lines
             (doc_id,line_no,item_id,code,unit_factor,qty_units,qty_pieces,unit_cost,unit_price,discount_pct,line_total,inv_discount_alloc,notes)
             VALUES ' . implode(',', $values) . '
             ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),code=VALUES(code),unit_factor=VALUES(unit_factor),
             qty_units=VALUES(qty_units),qty_pieces=VALUES(qty_pieces),unit_cost=VALUES(unit_cost),
             unit_price=VALUES(unit_price),discount_pct=VALUES(discount_pct),line_total=VALUES(line_total),
             inv_discount_alloc=VALUES(inv_discount_alloc),notes=VALUES(notes)'
        )->execute($params);
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

/**
 * جلب أصناف فاتورة الشراء لطباعة الليبلز (بريف ٨٦٤)
 * الداتا من stock_docs و stock_doc_lines و hq_codes.
 * بترجّع [doc => [...], items => [...]] أو null لو الفاتورة مش موجودة.
 */
function purchase_doc_items_for_labels(PDO $pdo, string $term): ?array
{
    $term = trim($term);
    if ($term === '') {
        return null;
    }

    $doc = null;

    // مسودة-123 أو draft-123
    if (preg_match('/^(?:مسودة|draft)[-_]?(\d+)$/ui', $term, $m)) {
        $st = $pdo->prepare("SELECT * FROM stock_docs WHERE id = ? AND doc_type IN ('purchase', 'purchase_return') LIMIT 1");
        $st->execute([(int) $m[1]]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
    }
    // 2026/123
    elseif (preg_match('/^(\d{4})\/(\d+)$/', $term, $m)) {
        $st = $pdo->prepare("SELECT * FROM stock_docs WHERE doc_year = ? AND doc_no = ? AND doc_type IN ('purchase', 'purchase_return') LIMIT 1");
        $st->execute([(int) $m[1], (int) $m[2]]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
    }
    // رقم خالص (كـ doc_no الأول لو مرحلة، أو كـ id)
    elseif (ctype_digit($term)) {
        $num = (int) $term;
        $st = $pdo->prepare("SELECT * FROM stock_docs WHERE (doc_no = ? OR id = ?) AND doc_type IN ('purchase', 'purchase_return') ORDER BY (doc_no = ?) DESC, id DESC LIMIT 1");
        $st->execute([$num, $num, $num]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$doc) {
        return null;
    }

    $stLines = $pdo->prepare(
        "SELECT l.code, l.item_id, l.unit_factor, l.qty_units, l.qty_pieces
           FROM stock_doc_lines l
          WHERE l.doc_id = ? AND l.qty_pieces > 0
          ORDER BY l.line_no ASC"
    );
    $stLines->execute([(int) $doc['id']]);
    $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    $docInfo = [
        'id'       => (int) $doc['id'],
        'doc_no'   => $doc['doc_no'] ? (int) $doc['doc_no'] : null,
        'doc_year' => $doc['doc_year'] ? (int) $doc['doc_year'] : null,
        'doc_type' => $doc['doc_type'],
        'status'   => $doc['status'],
    ];

    if (!$lines) {
        return ['doc' => $docInfo, 'items' => []];
    }

    // قراءة من hq_codes باستعلام مجمّع واحد (قاعدة مفيش استعلام في حلقة)
    $codes = array_values(array_unique(array_filter(array_column($lines, 'code'))));
    $hqMap = [];
    if ($codes) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stHq = $pdo->prepare(
            "SELECT code, name, price, code_type, unit_factor FROM hq_codes WHERE code IN ($in)"
        );
        $stHq->execute($codes);
        foreach ($stHq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hqMap[(string) $r['code']] = $r;
        }
    }

    $items = [];
    foreach ($lines as $line) {
        $code = (string) $line['code'];
        $hq = $hqMap[$code] ?? null;
        $name = (string) ($hq['name'] ?? $code);
        $price = round((float) ($hq['price'] ?? 0), 2);
        $codeType = (int) ($hq['code_type'] ?? 0);
        $factor = round((float) ($line['unit_factor'] ?? ($hq['unit_factor'] ?? 1)), 3);
        // عدد الليبلز بالقطعة = الكمية × معامل الوحدة
        $copies = max(1, (int) round((float) $line['qty_pieces']));

        $items[] = [
            'code'      => $code,
            'name'      => $name,
            'price'     => $price,
            'code_type' => $codeType,
            'factor'    => $factor,
            'copies'    => $copies,
            'printable' => function_exists('barcode_printable') ? barcode_printable($code) : true,
        ];
    }

    return [
        'doc'   => $docInfo,
        'items' => $items,
    ];
}
