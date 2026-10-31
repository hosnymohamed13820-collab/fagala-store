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
const ASHRAQA_ERP_IDS = [1000245, 1000252];
const ASHRAQA_OUR_SUPPLIER = 8;

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
    } elseif ($activeTab === 'ashraqa' || $activeTab === 'ash_recon') {
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

/**
 * مطابقة حساب إشراقة قصاد فروعنا (بريف ٨٣١)
 *
 * @param PDO $pdo
 * @param string $from
 * @param string $to
 * @return array
 */
function branch_recon_get_ashraqa_recon(PDO $pdo, string $from, string $to): array
{
    // (أ) فواتير إشراقة (٣ معاملات)
    $sqlAsh = "SELECT sstt_id, brch_id, fy_no, add_doc, doc_date, net_amount, notes
               FROM ashraqa_purchases
               WHERE supplier_id = ? AND cancelled = 0 AND doc_date BETWEEN ? AND ?
               ORDER BY doc_date ASC, add_doc ASC";
    $stAsh = $pdo->prepare($sqlAsh);
    $stAsh->execute([ASHRAQA_OUR_SUPPLIER, $from, $to]);
    $ashRows = $stAsh->fetchAll(PDO::FETCH_ASSOC);

    // (ب) فواتيرنا المترحّلة (٤ معاملات)
    $sqlOurPosted = "SELECT sth_id, suffix, doc_no, doc_type, company_branch, DATE(invoice_date) AS tx_date, net_amount
                     FROM hq_invoices
                     WHERE supplier_id IN (?, ?)
                       AND doc_type IN (2, 6)
                       AND invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                     ORDER BY invoice_date ASC, sth_id ASC";
    $stOurPosted = $pdo->prepare($sqlOurPosted);
    $stOurPosted->execute([ASHRAQA_ERP_IDS[0], ASHRAQA_ERP_IDS[1], $from, $to]);
    $ourPostedRows = $stOurPosted->fetchAll(PDO::FETCH_ASSOC);

    // (ج) فواتيرنا غير المترحّلة (٤ معاملات)
    $sqlOurUnposted = "SELECT sth_id, doc_no, doc_type, company_branch, DATE(invoice_date) AS tx_date, net_amount
                       FROM hq_unposted
                       WHERE supplier_id IN (?, ?)
                         AND doc_type IN (2, 6)
                         AND invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                       ORDER BY invoice_date ASC, sth_id ASC";
    $stOurUnposted = $pdo->prepare($sqlOurUnposted);
    $stOurUnposted->execute([ASHRAQA_ERP_IDS[0], ASHRAQA_ERP_IDS[1], $from, $to]);
    $ourUnpostedRows = $stOurUnposted->fetchAll(PDO::FETCH_ASSOC);

    // تجهيز أطرافنا الخمسة
    $allParties = recon_all_parties();
    $cbToPartyKey = [];
    $cbToPartyName = [];
    $partiesData = [];
    foreach ($allParties as $k => $p) {
        $cb = (int)($p['cb'] ?? 1);
        $name = (string)($p['label'] ?? $p['name'] ?? $k);
        $cbToPartyKey[$cb] = $k;
        $cbToPartyName[$cb] = $name;
        $partiesData[$k] = [
            'key'          => $k,
            'name'         => $name,
            'out_amt'      => 0.0,
            'in_amt'       => 0.0,
            'net_amt'      => 0.0,
            'unposted_net' => 0.0,
        ];
    }

    // تجميع إشراقة
    $ashBuy = 0.0;
    $ashRet = 0.0;
    $ashDocs = [];
    foreach ($ashRows as $idx => $r) {
        $amt = (float)$r['net_amount'];
        $sstt = (int)$r['sstt_id'];
        if ($sstt === 113) {
            $ashBuy += $amt;
        } elseif ($sstt === 115) {
            $ashRet += $amt;
        }
        $docKey = $r['sstt_id'] . '-' . $r['brch_id'] . '-' . $r['fy_no'] . '-' . $r['add_doc'];
        $ashDocs[$idx] = [
            'idx'      => $idx,
            'doc_key'  => $docKey,
            'doc_no'   => (int)$r['add_doc'],
            'type'     => $sstt,
            'date'     => (string)$r['doc_date'],
            'amount'   => $amt,
            'notes'    => $r['notes'] !== null ? (string)$r['notes'] : null,
            'cancels'  => null,
            'matched'  => false,
        ];
    }
    $ashNet = $ashBuy - $ashRet;

    // تجميع فواتيرنا المترحّلة
    $ourOut = 0.0;
    $ourIn = 0.0;
    $ourDocs = [];
    $ourIdx = 0;
    foreach ($ourPostedRows as $r) {
        $amt = (float)$r['net_amount'];
        $dt = (int)$r['doc_type'];
        $cb = ($r['company_branch'] === null) ? 1 : (int)$r['company_branch'];
        $pKey = $cbToPartyKey[$cb] ?? 'hq';
        $pLabel = $cbToPartyName[$cb] ?? ($partiesData[$pKey]['name'] ?? 'الإدارة');

        if ($dt === 6) {
            $ourOut += $amt;
            if (isset($partiesData[$pKey])) {
                $partiesData[$pKey]['out_amt'] += $amt;
            }
        } elseif ($dt === 2) {
            $ourIn += $amt;
            if (isset($partiesData[$pKey])) {
                $partiesData[$pKey]['in_amt'] += $amt;
            }
        }

        $ourDocs[$ourIdx] = [
            'idx'         => $ourIdx,
            'party_key'   => $pKey,
            'party_label' => $pLabel,
            'branch'      => $cb,
            'branch_name' => $pLabel,
            'doc_no'      => $r['doc_no'],
            'doc_type'    => $dt,
            'type'        => $dt,
            'date'        => (string)$r['tx_date'],
            'amount'      => $amt,
            'posted'      => true,
            'cancels'     => null,
            'matched'     => false,
        ];
        $ourIdx++;
    }
    $ourNet = $ourOut - $ourIn;

    // تجميع فواتيرنا غير المترحّلة
    $unpostedOut = 0.0;
    $unpostedIn = 0.0;
    $unpostedCount = count($ourUnpostedRows);
    foreach ($ourUnpostedRows as $r) {
        $amt = (float)$r['net_amount'];
        $dt = (int)$r['doc_type'];
        $cb = ($r['company_branch'] === null) ? 1 : (int)$r['company_branch'];
        $pKey = $cbToPartyKey[$cb] ?? 'hq';
        $pLabel = $cbToPartyName[$cb] ?? ($partiesData[$pKey]['name'] ?? 'الإدارة');

        if ($dt === 6) {
            $unpostedOut += $amt;
            if (isset($partiesData[$pKey])) {
                $partiesData[$pKey]['unposted_net'] += $amt;
            }
        } elseif ($dt === 2) {
            $unpostedIn += $amt;
            if (isset($partiesData[$pKey])) {
                $partiesData[$pKey]['unposted_net'] -= $amt;
            }
        }

        $ourDocs[$ourIdx] = [
            'idx'         => $ourIdx,
            'party_key'   => $pKey,
            'party_label' => $pLabel,
            'branch'      => $cb,
            'branch_name' => $pLabel,
            'doc_no'      => $r['doc_no'],
            'doc_type'    => $dt,
            'type'        => $dt,
            'date'        => (string)$r['tx_date'],
            'amount'      => $amt,
            'posted'      => false,
            'cancels'     => null,
            'matched'     => false,
        ];
        $ourIdx++;
    }
    $unpostedNet = $unpostedOut - $unpostedIn;

    foreach ($partiesData as $k => &$pd) {
        $pd['net_amt'] = $pd['out_amt'] - $pd['in_amt'];
    }
    unset($pd);

    $diff = $ashNet - $ourNet;
    $diffAfterPost = $ashNet - ($ourNet + $unpostedNet);

    // خطوة ١: مطابقة بين الطرفين (113 مع 6، و115 مع 2)
    // الشرط: abs(فرق المبلغ) <= 0.05 و abs(فرق التاريخ) <= 90 يوم
    // ترتيب الاحتمالات بفرق الأيام من الأصغر للأكبر، وكل فاتورة تتربط مرة واحدة
    $candidatePairs = [];
    foreach ($ashDocs as $aIdx => $aDoc) {
        $targetOurType = ($aDoc['type'] === 113) ? 6 : 2;
        $aTime = strtotime($aDoc['date']);
        foreach ($ourDocs as $oIdx => $oDoc) {
            if ($oDoc['doc_type'] !== $targetOurType) {
                continue;
            }
            $amtDiff = abs($aDoc['amount'] - $oDoc['amount']);
            if ($amtDiff > 0.05) {
                continue;
            }
            $oTime = strtotime($oDoc['date']);
            $diffDays = (int)round(abs($aTime - $oTime) / 86400);
            if ($diffDays > 90) {
                continue;
            }
            $candidatePairs[] = [
                'aIdx'     => $aIdx,
                'oIdx'     => $oIdx,
                'diffDays' => $diffDays,
                'amtDiff'  => $amtDiff,
            ];
        }
    }

    usort($candidatePairs, function (array $p1, array $p2): int {
        if ($p1['diffDays'] !== $p2['diffDays']) {
            return $p1['diffDays'] <=> $p2['diffDays'];
        }
        if ($p1['amtDiff'] !== $p2['amtDiff']) {
            return ($p1['amtDiff'] < $p2['amtDiff']) ? -1 : 1;
        }
        if ($p1['aIdx'] !== $p2['aIdx']) {
            return $p1['aIdx'] <=> $p2['aIdx'];
        }
        return $p1['oIdx'] <=> $p2['oIdx'];
    });

    $matchedAsh = [];
    $matchedOur = [];
    foreach ($candidatePairs as $cp) {
        $aIdx = $cp['aIdx'];
        $oIdx = $cp['oIdx'];
        if (isset($matchedAsh[$aIdx]) || isset($matchedOur[$oIdx])) {
            continue;
        }
        $matchedAsh[$aIdx] = true;
        $matchedOur[$oIdx] = true;
        $ashDocs[$aIdx]['matched'] = true;
        $ourDocs[$oIdx]['matched'] = true;
    }
    $ashMatchedCount = count($matchedAsh);
    $ourMatchedCount = count($matchedOur);

    // خطوة ٢: جوه نفس الطرف (على اللي فضل بس بعد خطوة ١)
    // ٢.أ: عندنا بين 6 و2 من نفس الفرع (±0.05 وخلال 45 يوم)
    $remainingOurByBranch = [];
    foreach ($ourDocs as $oIdx => $oDoc) {
        if (!$oDoc['matched']) {
            $remainingOurByBranch[$oDoc['branch']][] = $oIdx;
        }
    }

    foreach ($remainingOurByBranch as $branchId => $branchOurIndices) {
        $cancelsCandidates = [];
        $cnt = count($branchOurIndices);
        for ($i = 0; $i < $cnt; $i++) {
            $idx1 = $branchOurIndices[$i];
            $doc1 = $ourDocs[$idx1];
            for ($j = $i + 1; $j < $cnt; $j++) {
                $idx2 = $branchOurIndices[$j];
                $doc2 = $ourDocs[$idx2];
                if (($doc1['doc_type'] === 6 && $doc2['doc_type'] === 2) ||
                    ($doc1['doc_type'] === 2 && $doc2['doc_type'] === 6)) {
                    $amtDiff = abs($doc1['amount'] - $doc2['amount']);
                    if ($amtDiff <= 0.05) {
                        $diffDays = (int)round(abs(strtotime($doc1['date']) - strtotime($doc2['date'])) / 86400);
                        if ($diffDays <= 45) {
                            $cancelsCandidates[] = [
                                'idx1'     => $idx1,
                                'idx2'     => $idx2,
                                'diffDays' => $diffDays,
                                'amtDiff'  => $amtDiff,
                            ];
                        }
                    }
                }
            }
        }

        usort($cancelsCandidates, function (array $c1, array $c2): int {
            if ($c1['diffDays'] !== $c2['diffDays']) {
                return $c1['diffDays'] <=> $c2['diffDays'];
            }
            if ($c1['amtDiff'] !== $c2['amtDiff']) {
                return ($c1['amtDiff'] < $c2['amtDiff']) ? -1 : 1;
            }
            return $c1['idx1'] <=> $c2['idx1'];
        });

        $pairedOur = [];
        foreach ($cancelsCandidates as $cand) {
            $i1 = $cand['idx1'];
            $i2 = $cand['idx2'];
            if (isset($pairedOur[$i1]) || isset($pairedOur[$i2])) {
                continue;
            }
            $pairedOur[$i1] = true;
            $pairedOur[$i2] = true;
            $ourDocs[$i1]['cancels'] = (string)$ourDocs[$i2]['doc_no'];
            $ourDocs[$i2]['cancels'] = (string)$ourDocs[$i1]['doc_no'];
        }
    }

    // ٢.ب: عند إشراقة بين 113 و115 (±0.05 وخلال 45 يوم)
    $remainingAshIndices = [];
    foreach ($ashDocs as $aIdx => $aDoc) {
        if (!$aDoc['matched']) {
            $remainingAshIndices[] = $aIdx;
        }
    }
    $ashCancelsCandidates = [];
    $ashCnt = count($remainingAshIndices);
    for ($i = 0; $i < $ashCnt; $i++) {
        $idx1 = $remainingAshIndices[$i];
        $doc1 = $ashDocs[$idx1];
        for ($j = $i + 1; $j < $ashCnt; $j++) {
            $idx2 = $remainingAshIndices[$j];
            $doc2 = $ashDocs[$idx2];
            if (($doc1['type'] === 113 && $doc2['type'] === 115) ||
                ($doc1['type'] === 115 && $doc2['type'] === 113)) {
                $amtDiff = abs($doc1['amount'] - $doc2['amount']);
                if ($amtDiff <= 0.05) {
                    $diffDays = (int)round(abs(strtotime($doc1['date']) - strtotime($doc2['date'])) / 86400);
                    if ($diffDays <= 45) {
                        $ashCancelsCandidates[] = [
                            'idx1'     => $idx1,
                            'idx2'     => $idx2,
                            'diffDays' => $diffDays,
                            'amtDiff'  => $amtDiff,
                        ];
                    }
                }
            }
        }
    }

    usort($ashCancelsCandidates, function (array $c1, array $c2): int {
        if ($c1['diffDays'] !== $c2['diffDays']) {
            return $c1['diffDays'] <=> $c2['diffDays'];
        }
        if ($c1['amtDiff'] !== $c2['amtDiff']) {
            return ($c1['amtDiff'] < $c2['amtDiff']) ? -1 : 1;
        }
        return $c1['idx1'] <=> $c2['idx1'];
    });

    $pairedAsh = [];
    foreach ($ashCancelsCandidates as $cand) {
        $i1 = $cand['idx1'];
        $i2 = $cand['idx2'];
        if (isset($pairedAsh[$i1]) || isset($pairedAsh[$i2])) {
            continue;
        }
        $pairedAsh[$i1] = true;
        $pairedAsh[$i2] = true;
        $ashDocs[$i1]['cancels'] = (string)$ashDocs[$i2]['doc_no'];
        $ashDocs[$i2]['cancels'] = (string)$ashDocs[$i1]['doc_no'];
    }

    // بناء القائمتين غير المتطابقتين وترتيبهما تنازلياً بالمبلغ
    $ashUnmatched = [];
    foreach ($ashDocs as $ad) {
        if (!$ad['matched']) {
            $ashUnmatched[] = [
                'doc_key' => $ad['doc_key'],
                'doc_no'  => $ad['doc_no'],
                'type'    => $ad['type'],
                'date'    => $ad['date'],
                'amount'  => $ad['amount'],
                'notes'   => $ad['notes'],
                'cancels' => $ad['cancels'],
            ];
        }
    }
    usort($ashUnmatched, function (array $a, array $b): int {
        return ($b['amount'] <=> $a['amount']);
    });

    $ourUnmatched = [];
    foreach ($ourDocs as $od) {
        if (!$od['matched']) {
            $ourUnmatched[] = [
                'party_key'   => $od['party_key'],
                'party_label' => $od['party_label'],
                'branch'      => $od['branch'],
                'branch_name' => $od['branch_name'],
                'doc_no'      => $od['doc_no'],
                'doc_type'    => $od['doc_type'],
                'type'        => $od['doc_type'],
                'date'        => $od['date'],
                'amount'      => $od['amount'],
                'posted'      => $od['posted'],
                'cancels'     => $od['cancels'],
            ];
        }
    }
    usort($ourUnmatched, function (array $a, array $b): int {
        return ($b['amount'] <=> $a['amount']);
    });

    return [
        'ash_buy'           => round($ashBuy, 2),
        'ash_ret'           => round($ashRet, 2),
        'ash_net'           => round($ashNet, 2),
        'our_out'           => round($ourOut, 2),
        'our_in'            => round($ourIn, 2),
        'our_net'           => round($ourNet, 2),
        'unposted_out'      => round($unpostedOut, 2),
        'unposted_in'       => round($unpostedIn, 2),
        'unposted_net'      => round($unpostedNet, 2),
        'unposted_count'    => $unpostedCount,
        'diff'              => round($diff, 2),
        'diff_after_post'   => round($diffAfterPost, 2),
        'parties'           => array_values($partiesData),
        'ash_unmatched'     => $ashUnmatched,
        'our_unmatched'     => $ourUnmatched,
        'ash_matched_count' => $ashMatchedCount,
        'our_matched_count' => $ourMatchedCount,
    ];
}

