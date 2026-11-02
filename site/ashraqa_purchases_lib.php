<?php
/** مرآة مشتريات Redux: قراية بس بالمفتاح الرباعي، من غير أي حركة مخزن. */
declare(strict_types=1);
function ashraqa_purchases_read(PDO $pdo, array $filters): array
{
$from = (isset($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['from'])) ? (string)$filters['from'] : '2026-07-01';
$to   = (isset($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['to'])) ? (string)$filters['to'] : date('Y-m-d');

$docParam = trim((string)($filters['doc'] ?? ''));
$ashraqaDetailMode = false;
$ashDoc = null;
$ashLines = [];
$ashDocNotFound = false;

if ($docParam !== '') {
    $ashraqaDetailMode = true;
    if (!preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)$/', $docParam, $dm)) {
        $ashDocNotFound = true;
    } else {
        $sstt   = (int)$dm[1];
        $brch   = (int)$dm[2];
        $fy     = (int)$dm[3];
        $addDoc = (int)$dm[4];

        // استعلام ١: رأس الفاتورة
        $stDoc = $pdo->prepare("SELECT sstt_id, brch_id, fy_no, add_doc, doc_date, supplier_id, supplier_name,
                                       net_amount, total_amount, cancelled, notes, supplier_inv_no
                                FROM ashraqa_purchases
                                WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                                LIMIT 1");
        $stDoc->execute([$sstt, $brch, $fy, $addDoc]);
        $ashDoc = $stDoc->fetch(PDO::FETCH_ASSOC);

        if (!$ashDoc) {
            $ashDocNotFound = true;
        } else {
            // استعلام ٢: سطور الفاتورة
            $stLines = $pdo->prepare("SELECT line_no, item_id, code, barcodes, item_name, unit_q, quantity, unit_price, line_total
                                      FROM ashraqa_purchase_lines
                                      WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                                      ORDER BY line_no ASC");
            $stLines->execute([$sstt, $brch, $fy, $addDoc]);
            $ashLines = $stLines->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} else {
    // استعلام الموردين للفلتر
    $stSupps = $pdo->query("SELECT DISTINCT supplier_id, supplier_name
                            FROM ashraqa_purchases
                            WHERE supplier_id IS NOT NULL
                            ORDER BY supplier_name ASC");
    $ashSuppliers = $stSupps->fetchAll(PDO::FETCH_ASSOC);

    $ashSstt = isset($filters['sstt']) ? (int)$filters['sstt'] : 0;
    $ashSupplier = isset($filters['supplier']) ? (int)$filters['supplier'] : 0;
    $ashSearch = trim((string)($filters['q'] ?? ''));
    $ashShowCancelled = !empty($filters['show_cancelled']);

    $ashWhere = ["p.doc_date >= ? AND p.doc_date <= ?"];
    $ashParams = [$from, $to];

    if ($ashSstt === 113 || $ashSstt === 115) {
        $ashWhere[] = "p.sstt_id = ?";
        $ashParams[] = $ashSstt;
    }
    if ($ashSupplier > 0) {
        $ashWhere[] = "p.supplier_id = ?";
        $ashParams[] = $ashSupplier;
    }
    if ($ashSearch !== '') {
        $ashWhere[] = "(p.notes LIKE ? OR CAST(p.add_doc AS CHAR) LIKE ?)";
        $ashParams[] = '%' . $ashSearch . '%';
        $ashParams[] = '%' . $ashSearch . '%';
    }
    if (!$ashShowCancelled) {
        $ashWhere[] = "p.cancelled = 0";
    }

    $whereStr = implode(' AND ', $ashWhere);

    // استعلام مجمع لقائمة فواتير إشراقة بـ LEFT JOIN
    $sqlAshList = "SELECT p.sstt_id, p.brch_id, p.fy_no, p.add_doc,
                          p.doc_date, p.supplier_id, p.supplier_name,
                          p.net_amount, p.cancelled, p.notes,
                          COUNT(l.line_no) AS items_count,
                          COALESCE(SUM(l.quantity * l.unit_q), 0) AS total_pieces
                   FROM ashraqa_purchases p
                   LEFT JOIN ashraqa_purchase_lines l
                     ON l.sstt_id = p.sstt_id AND l.brch_id = p.brch_id AND l.fy_no = p.fy_no AND l.add_doc = p.add_doc
                   WHERE $whereStr
                   GROUP BY p.sstt_id, p.brch_id, p.fy_no, p.add_doc
                   ORDER BY p.doc_date DESC, p.add_doc DESC";
    $summary = $pdo->prepare("SELECT COUNT(*) AS count,
        COALESCE(SUM(CASE WHEN p.sstt_id=113 AND p.cancelled=0 THEN p.net_amount ELSE 0 END),0) AS buy,
        COALESCE(SUM(CASE WHEN p.sstt_id=115 AND p.cancelled=0 THEN p.net_amount ELSE 0 END),0) AS returned
        FROM ashraqa_purchases p
        WHERE " . $whereStr);
    $summary->execute($ashParams);
    $totals = $summary->fetch(PDO::FETCH_ASSOC);
    $page = max(1, (int)($filters['page'] ?? 1));
    $sqlAshList .= ' LIMIT 100 OFFSET ' . (($page - 1) * 100);
    $stAshList = $pdo->prepare($sqlAshList);
    $stAshList->execute($ashParams);
    $ashInvoices = $stAshList->fetchAll(PDO::FETCH_ASSOC);

    $totAshBuy = 0.0;
    $totAshRet = 0.0;
    foreach ($ashInvoices as $ai) {
        if ((int)$ai['cancelled'] === 0) {
            if ((int)$ai['sstt_id'] === 113) {
                $totAshBuy += (float)$ai['net_amount'];
            } elseif ((int)$ai['sstt_id'] === 115) {
                $totAshRet += (float)$ai['net_amount'];
            }
        }
    }
}

return [
    'detail' => $ashraqaDetailMode, 'doc' => $ashDoc ?: null, 'lines' => $ashLines,
    'not_found' => $ashDocNotFound, 'suppliers' => $ashSuppliers ?? [],
    'rows' => $ashInvoices ?? [], 'totals' => $totals ?? null,
    'page' => $page ?? 1, 'from' => $from, 'to' => $to,
];
}
