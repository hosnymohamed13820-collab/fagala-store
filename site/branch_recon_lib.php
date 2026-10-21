<?php
/**
 * branch_recon_lib.php — مكتبة استعلامات وبيانات مطابقة الفروع والإدارة
 * نظام الفجالة ستور المالي — موجة ٣ (بريف ١٧٩)
 *
 * قراءة فقط (GET). العمليات وتصدير الإكسيل وعلامات التلوين تبقى في الصفحة.
 */
declare(strict_types=1);

require_once __DIR__ . '/recon_lib.php';
require_once __DIR__ . '/item_flags_lib.php';
require_once __DIR__ . '/recon_alerts_lib.php';

const RECON_ALERTS_DEFAULT_START = '2026-10-01';

/**
 * جلب تاريخ البداية الافتراضي من الإعدادات
 */
function branch_recon_get_start_date(PDO $pdo): string
{
    $stStart = '2026-06-27';
    try {
        $stSet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'supplier_statement_start' LIMIT 1");
        $stSet->execute();
        $v = $stSet->fetchColumn();
        if ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
            $stStart = (string)$v;
        }
    } catch (Throwable) {}
    return $stStart;
}

/**
 * ضبط فترة المطابقة بناءً على التاب
 */
function branch_recon_get_date_range(?string $activeTab, ?string $fromParam, ?string $toParam, string $defaultStart): array
{
    if ($activeTab === 'alerts') {
        $from = (isset($fromParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromParam)) ? $fromParam : RECON_ALERTS_DEFAULT_START;
    } elseif ($activeTab === 'ashraqa') {
        $from = (isset($fromParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromParam)) ? $fromParam : '2026-07-01';
    } else {
        $from = (isset($fromParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromParam)) ? $fromParam : $defaultStart;
    }
    $to = (isset($toParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toParam)) ? $toParam : date('Y-m-d');
    return ['from' => $from, 'to' => $to];
}

/**
 * جلب مصفوفة الأزواج العشرة وحالة التطابق
 */
function branch_recon_get_pairs_data(PDO $pdo, string $from, string $to): array
{
    $matrix = recon_get_matrix($pdo, $from, $to);
    $pairs  = recon_get_all_pairs($matrix);

    $totPairsCount = count($pairs);
    $matchCount = 0;
    $mismatchCount = 0;
    foreach ($pairs as $p) {
        if ($p['is_match']) {
            $matchCount++;
        } else {
            $mismatchCount++;
        }
    }
    return [
        'matrix'         => $matrix,
        'pairs'          => $pairs,
        'match_count'    => $matchCount,
        'mismatch_count' => $mismatchCount,
        'total_pairs'    => $totPairsCount,
    ];
}

/**
 * جلب كشف مقارنة الزوج (party moves)
 */
function branch_recon_get_detail_data(PDO $pdo, string $from, string $to, string $aKey, string $bKey, array $allParties): ?array
{
    if ($aKey === '' || $bKey === '' || $aKey === $bKey || !isset($allParties[$aKey], $allParties[$bKey])) {
        return null;
    }
    $partyA = $allParties[$aKey];
    $partyB = $allParties[$bKey];
    $detailData = recon_get_party_moves($pdo, $from, $to, $aKey, $bKey);
    $partyMovesOur = $detailData['movesA'];
    $partyMovesHis = $detailData['movesB'];
    $partyTotOurBuy = $detailData['totA_buy'];
    $partyTotOurRet = $detailData['totA_ret'];
    $partyTotHisBuy = $detailData['totB_buy'];
    $partyTotHisRet = $detailData['totB_ret'];

    $partyNetOur = $partyTotOurBuy - $partyTotOurRet;
    $partyNetHis = $partyTotHisRet - $partyTotHisBuy;
    $partyDiff   = $partyNetOur - $partyNetHis;
    $partyIsMatch = abs($partyDiff) < 0.01;

    $allSthIds = array_values(array_unique(array_filter(array_merge(
        array_column($partyMovesOur, 'sth_id'),
        array_column($partyMovesHis, 'sth_id')
    ))));
    $reconMarks = recon_get_marks($pdo, $allSthIds);

    return [
        'partyA'         => $partyA,
        'partyB'         => $partyB,
        'movesA'         => $partyMovesOur,
        'movesB'         => $partyMovesHis,
        'totA_buy'       => $partyTotOurBuy,
        'totA_ret'       => $partyTotOurRet,
        'totB_buy'       => $partyTotHisBuy,
        'totB_ret'       => $partyTotHisRet,
        'netA'           => $partyNetOur,
        'netB'           => $partyNetHis,
        'diff'           => $partyDiff,
        'is_match'       => $partyIsMatch,
        'recon_marks'    => $reconMarks,
    ];
}

/**
 * جلب تنبيهات المطابقة والفحص
 */
function branch_recon_get_alerts_data(PDO $pdo, string $from, string $to, array $allParties, ?string $pFilter = '', bool $showDone = false): array
{
    $labels = [];
    foreach ($allParties as $pk => $p) {
        $labels[$pk] = $p['label'];
    }

    $tAlerts0 = microtime(true);
    $alertDocs = recon_alerts_load($pdo, $from, $to, $allParties);
    $allDetectedAlerts = recon_alerts_detect($alertDocs, $from, $to, date('Y-m-d'), $labels);
    $alertsPerfTime = microtime(true) - $tAlerts0;

    if ($pFilter !== null && $pFilter !== '' && isset($allParties[$pFilter])) {
        $allDetectedAlerts = array_values(array_filter($allDetectedAlerts, function ($al) use ($pFilter) {
            return in_array($pFilter, $al['pair'] ?? [], true);
        }));
    }

    $alertSthIds = [];
    foreach ($allDetectedAlerts as $al) {
        foreach ($al['docs'] as $dKey) {
            $parts = explode('_', $dKey);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                $alertSthIds[] = (int)$parts[0];
            }
        }
    }
    $reconMarks = recon_get_marks($pdo, $alertSthIds);

    $alerts = [];
    $doneAlerts = [];
    foreach ($allDetectedAlerts as $al) {
        $isGreen = false;
        foreach ($al['docs'] as $dKey) {
            if (($reconMarks[$dKey] ?? '') === 'green') {
                $isGreen = true;
                break;
            }
        }
        if ($isGreen) {
            $doneAlerts[] = $al;
            if ($showDone) {
                $alerts[] = $al;
            }
        } else {
            $alerts[] = $al;
        }
    }
    $doneCount = count($doneAlerts);

    $redCount = 0; $redAmt = 0.0;
    $yellowCount = 0; $yellowAmt = 0.0;
    $grayCount = 0; $grayAmt = 0.0;
    foreach ($alerts as $al) {
        if ($al['level'] === 'red') {
            $redCount++;
            $redAmt += (float)$al['amount'];
        } elseif ($al['level'] === 'yellow') {
            $yellowCount++;
            $yellowAmt += (float)$al['amount'];
        } elseif ($al['level'] === 'gray') {
            $grayCount++;
            $grayAmt += (float)$al['amount'];
        }
    }

    return [
        'alerts'       => $alerts,
        'done_count'   => $doneCount,
        'red_count'    => $redCount,
        'red_amt'      => $redAmt,
        'yellow_count' => $yellowCount,
        'yellow_amt'   => $yellowAmt,
        'gray_count'   => $grayCount,
        'gray_amt'     => $grayAmt,
        'perf_time'    => $alertsPerfTime,
        'recon_marks'  => $reconMarks,
    ];
}

/**
 * جلب تفاصيل فاتورة إشراقة مع أسطرها والباركودات
 */
function branch_recon_get_ashraqa_detail(PDO $pdo, string $docParam): ?array
{
    if (!preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)$/', $docParam, $dm)) {
        return null;
    }
    $sstt   = (int)$dm[1];
    $brch   = (int)$dm[2];
    $fy     = (int)$dm[3];
    $addDoc = (int)$dm[4];

    $stDoc = $pdo->prepare("SELECT sstt_id, brch_id, fy_no, add_doc, doc_date, supplier_id, supplier_name,
                                  net_amount, total_amount, cancelled, notes, supplier_inv_no
                           FROM ashraqa_purchases
                           WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                           LIMIT 1");
    $stDoc->execute([$sstt, $brch, $fy, $addDoc]);
    $ashDoc = $stDoc->fetch(PDO::FETCH_ASSOC);

    if (!$ashDoc) {
        return null;
    }

    $stLines = $pdo->prepare("SELECT line_no, item_id, code, barcodes, item_name, unit_q, quantity, unit_price, line_total
                              FROM ashraqa_purchase_lines
                              WHERE sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?
                              ORDER BY line_no ASC");
    $stLines->execute([$sstt, $brch, $fy, $addDoc]);
    $ashLines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    $allBarcodes = [];
    foreach ($ashLines as $ln) {
        if (!empty($ln['barcodes'])) {
            $bcs = explode('|', $ln['barcodes']);
            foreach ($bcs as $b) {
                $b = trim($b);
                if ($b !== '') {
                    $allBarcodes[] = $b;
                }
            }
        }
    }
    $allBarcodes = array_values(array_unique($allBarcodes));
    $ashCodesMap = [];
    if (!empty($allBarcodes)) {
        $inBc = implode(',', array_fill(0, count($allBarcodes), '?'));
        $stCodes = $pdo->prepare("SELECT code, name FROM hq_codes WHERE code IN ($inBc)");
        $stCodes->execute($allBarcodes);
        while ($r = $stCodes->fetch(PDO::FETCH_ASSOC)) {
            $ashCodesMap[trim($r['code'])] = $r['name'];
        }
    }

    return [
        'doc'       => $ashDoc,
        'lines'     => $ashLines,
        'codes_map' => $ashCodesMap,
    ];
}

/**
 * جلب قائمة فواتير مشتريات إشراقة والموردين والإجماليات
 */
function branch_recon_get_ashraqa_list(PDO $pdo, string $from, string $to, int $ashSstt = 0, int $ashSupplier = 0, string $ashSearch = '', bool $ashShowCancelled = false): array
{
    $stSupps = $pdo->query("SELECT DISTINCT supplier_id, supplier_name
                            FROM ashraqa_purchases
                            WHERE supplier_id IS NOT NULL
                            ORDER BY supplier_name ASC");
    $ashSuppliers = $stSupps->fetchAll(PDO::FETCH_ASSOC);

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

    return [
        'suppliers' => $ashSuppliers,
        'invoices'  => $ashInvoices,
        'tot_buy'   => $totAshBuy,
        'tot_ret'   => $totAshRet,
    ];
}

