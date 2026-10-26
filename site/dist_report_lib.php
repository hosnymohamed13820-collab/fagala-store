<?php
/**
 * dist_report_lib.php — داتا تقرير الفواتير الموزّعة
 *
 * كل استعلامات التقرير هنا؛ صفحة العرض مافيهاش SQL خاص بالتقرير.
 */
declare(strict_types=1);

/** عرض الكمية من غير أصفار عشرية زيادة. */
function dr_qty(float|int|string|null $qty): int|float
{
    return (float) ($qty ?? 0) + 0;
}

/**
 * صف لكل فاتورة، واستعلام واحد تاني لكل كميات الفترة مجمّعة حسب الفرع.
 * فلتر الفرع بيختار الفواتير بس؛ أعمدة وكميات باقي الفروع بتفضل ظاهرة.
 */
function dr_invoices(
    string $from,
    string $to,
    ?int $branch,
    ?int $supplier,
    ?string $status
): array {
    $pdo = db();
    $where = ['d.dist_date BETWEEN ? AND ?'];
    $params = [$from, $to];

    if ($supplier !== null && $supplier > 0) {
        $where[] = 'd.supplier_id = ?';
        $params[] = $supplier;
    }
    if ($status !== null && in_array($status, ['new', 'split', 'done'], true)) {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    if ($branch !== null && $branch > 0) {
        $where[] = 'EXISTS (
            SELECT 1
              FROM invoice_distribution_lines fb
             WHERE fb.distribution_id = d.id
               AND fb.branch_id = ?
             GROUP BY fb.notes
            HAVING SUM(fb.amount) > 0
        )';
        $params[] = $branch;
    }

    $headerSql = 'SELECT d.id, d.invoice_no, d.supplier_id, d.dist_date,
                         d.total_amount, d.status, d.user_notes,
                         COALESCE(s.name, \'غير محدد\') AS supplier_name,
                         COALESCE(ic.items_count, 0) AS items_count
                    FROM invoice_distribution d
                    LEFT JOIN suppliers s ON s.id = d.supplier_id
                    LEFT JOIN (
                        SELECT item_qty.distribution_id, COUNT(DISTINCT item_qty.notes) AS items_count
                          FROM (
                                SELECT distribution_id, branch_id, notes
                                  FROM invoice_distribution_lines
                                 WHERE notes IS NOT NULL AND notes <> \'\'
                                 GROUP BY distribution_id, branch_id, notes
                                HAVING SUM(amount) > 0
                          ) item_qty
                         GROUP BY item_qty.distribution_id
                    ) ic ON ic.distribution_id = d.id
                   WHERE ' . implode(' AND ', $where) . '
                   ORDER BY d.dist_date DESC, d.id DESC';
    $st = $pdo->prepare($headerSql);
    $st->execute($params);
    $headerRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $qtyWhere = ['d.dist_date BETWEEN ? AND ?'];
    $qtyParams = [$from, $to];
    if ($supplier !== null && $supplier > 0) {
        $qtyWhere[] = 'd.supplier_id = ?';
        $qtyParams[] = $supplier;
    }
    if ($status !== null && in_array($status, ['new', 'split', 'done'], true)) {
        $qtyWhere[] = 'd.status = ?';
        $qtyParams[] = $status;
    }

    $qtySql = 'SELECT l.distribution_id, l.branch_id, b.name AS branch_name,
                      SUM(l.item_qty) AS qty
                 FROM (
                       SELECT distribution_id, branch_id, notes, SUM(amount) AS item_qty
                         FROM invoice_distribution_lines
                        GROUP BY distribution_id, branch_id, notes
                       HAVING SUM(amount) > 0
                 ) l
                 JOIN invoice_distribution d ON d.id = l.distribution_id
                 JOIN branches b ON b.id = l.branch_id
                WHERE ' . implode(' AND ', $qtyWhere) . '
                GROUP BY l.distribution_id, l.branch_id, b.name
               HAVING SUM(l.item_qty) > 0
                ORDER BY l.branch_id';
    $st = $pdo->prepare($qtySql);
    $st->execute($qtyParams);
    $qtyRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $branches = [];
    $qtyByDist = [];
    foreach ($qtyRows as $row) {
        $distId = (int) $row['distribution_id'];
        $branchId = (int) $row['branch_id'];
        $branches[$branchId] = (string) $row['branch_name'];
        $qtyByDist[$distId][$branchId] = dr_qty($row['qty']);
    }
    ksort($branches, SORT_NUMERIC);

    $invoices = [];
    foreach ($headerRows as $row) {
        $distId = (int) $row['id'];
        $byBranch = $qtyByDist[$distId] ?? [];
        $invoices[] = [
            'id'              => $distId,
            'invoice_no'      => (string) ($row['invoice_no'] ?? ''),
            'supplier_id'     => $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
            'supplier_name'   => (string) $row['supplier_name'],
            'dist_date'       => (string) $row['dist_date'],
            'status'          => (string) $row['status'],
            'user_notes'      => (string) ($row['user_notes'] ?? ''),
            'items_count'     => (int) $row['items_count'],
            'qty_by_branch'   => $byBranch,
            'qty_total'       => dr_qty(array_sum($byBranch)),
            'total_amount'    => (float) $row['total_amount'],
        ];
    }

    return ['branches' => $branches, 'invoices' => $invoices];
}

/**
 * تفاصيل فاتورة واحدة: صف لكل صنف، بكميات الفروع في استعلام واحد.
 */
function dr_items(int $distId): array
{
    $pdo = db();
    $sql = 'SELECT d.id AS dist_id, q.item_code, q.branch_id,
                   b.name AS branch_name, q.qty,
                   COALESCE(MAX(NULLIF(i.name, \'\')), q.item_code) AS item_name
              FROM invoice_distribution d
              LEFT JOIN (
                    SELECT notes AS item_code, branch_id, SUM(amount) AS qty
                      FROM invoice_distribution_lines
                     WHERE distribution_id = ?
                     GROUP BY notes, branch_id
                    HAVING SUM(amount) > 0
              ) q ON 1 = 1
              LEFT JOIN branches b ON b.id = q.branch_id
              LEFT JOIN hq_items i ON i.barcode = q.item_code
             WHERE d.id = ?
             GROUP BY d.id, q.item_code, q.branch_id, b.name, q.qty
             ORDER BY q.item_code, q.branch_id';
    $st = $pdo->prepare($sql);
    $st->execute([$distId, $distId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return ['found' => false, 'branches' => [], 'items' => []];
    }

    $branches = [];
    $itemsByCode = [];
    foreach ($rows as $row) {
        if ($row['item_code'] === null) continue;
        $code = (string) $row['item_code'];
        $branchId = (int) $row['branch_id'];
        $branches[$branchId] = (string) $row['branch_name'];
        if (!isset($itemsByCode[$code])) {
            $itemsByCode[$code] = [
                'code' => $code,
                'name' => (string) ($row['item_name'] ?: $code),
                'qty_by_branch' => [],
                'qty_total' => 0,
            ];
        }
        $qty = dr_qty($row['qty']);
        $itemsByCode[$code]['qty_by_branch'][$branchId] = $qty;
        $itemsByCode[$code]['qty_total'] = dr_qty($itemsByCode[$code]['qty_total'] + $qty);
    }
    ksort($branches, SORT_NUMERIC);

    return ['found' => true, 'branches' => $branches, 'items' => array_values($itemsByCode)];
}

/** القوائم المستخدمة في فلاتر التقرير. */
function dr_filter_options(): array
{
    $pdo = db();
    $branches = $pdo->query('SELECT id, name FROM branches ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    $suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')
        ->fetchAll(PDO::FETCH_ASSOC);
    return ['branches' => $branches, 'suppliers' => $suppliers];
}
