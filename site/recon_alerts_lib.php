<?php
/**
 * recon_alerts_lib.php — مكتبة تنبيهات مطابقة الفروع والإدارة
 * نظام الفجالة ستور المالي
 *
 * تقارن كل فاتورة بالفاتورة المقابلة لها وتكتشف الأخطاء الحسابية والتكرار
 * وفروق الأسعار والمجموعات والفواتير غير المرحلة واليتيمة.
 *
 * 🔴 الملف ده دالة صافية ومستقلة:
 *    - مفيش require لـ config.php ولا recon_lib.php (عشان الاختبارات تشتغل بدون داتابيز)
 *    - مفيش أي كتابة في القاعدة (SELECT فقط في recon_alerts_load)
 *    - مفيش استدعاء لدالة date() داخل recon_alerts_detect
 */

/**
 * حساب نسبة التشابه بين فاتورتين
 * عدد الأصناف المتطابقة بالكمية بالضبط (فرق < 0.001) مقسوماً على الأكبر من عدد أصناف الفاتورتين
 */
function recon_alerts_calc_sim(array $docX, array $docY): float
{
    $itemsX = $docX['items'] ?? [];
    $itemsY = $docY['items'] ?? [];
    $cntX = count($itemsX);
    $cntY = count($itemsY);

    if ($cntX === 0 && $cntY === 0) {
        return 1.0;
    }
    $maxCnt = max($cntX, $cntY);
    if ($maxCnt === 0) {
        return 0.0;
    }

    $sameQtyCount = 0;
    foreach ($itemsX as $itemId => $itX) {
        if (isset($itemsY[$itemId])) {
            $qX = (float)$itX['q'];
            $qY = (float)$itemsY[$itemId]['q'];
            if (abs($qX - $qY) < 0.001) {
                $sameQtyCount++;
            }
        }
    }
    return (float)($sameQtyCount / $maxCnt);
}

/**
 * حساب فرق الأيام بين تاريخين
 */
function recon_alerts_days_diff(string $d1, string $d2): float
{
    $t1 = strtotime($d1);
    $t2 = strtotime($d2);
    return abs($t1 - $t2) / 86400.0;
}

/**
 * جلب فواتير المطابقة مع سطورها من قاعدة البيانات
 *
 * @param PDO $pdo
 * @param string $from تاريخ البداية (Y-m-d)
 * @param string $to تاريخ النهاية (Y-m-d)
 * @param array $parties مصفوفة الأطراف من recon_all_parties()
 * @return array
 */
function recon_alerts_load(PDO $pdo, string $from, string $to, array $parties): array
{
    // نافذة البحث عن المقابل من from - 75 يوم
    $queryFrom = date('Y-m-d', strtotime($from . ' -75 days'));
    $queryTo   = $to;

    $erpToParty = [];
    foreach ($parties as $pk => $p) {
        $erpToParty[(int)$p['erp']] = $pk;
    }

    $cbToParty = [];
    foreach ($parties as $pk => $p) {
        if ($pk === 'hq') {
            $cbToParty[1] = 'hq';
        } else {
            $cbToParty[(int)$p['cb']] = $pk;
        }
    }

    $allErpIds = array_keys($erpToParty);
    if (empty($allErpIds)) {
        return [];
    }
    $inErp = implode(',', array_fill(0, count($allErpIds), '?'));

    // استعلام ١ للمترحل: رؤوس فواتير hq_invoices
    $sqlInvs = "SELECT sth_id, suffix, company_branch, supplier_id, doc_type, doc_no, invoice_date, net_amount
                FROM hq_invoices
                WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                  AND doc_type IN (2, 6)
                  AND supplier_id IN ($inErp)";
    $stmtInvs = $pdo->prepare($sqlInvs);
    $stmtInvs->execute(array_merge([$queryFrom, $queryTo], $allErpIds));
    $postedInvsRaw = $stmtInvs->fetchAll(PDO::FETCH_ASSOC);

    // استعلام ٢ للمترحل: سطور فواتير hq_invoice_lines مجمعة (بالقطعة + تكلفة ومعامل القطعة)
    $sqlLines = "SELECT l.sth_id, l.suffix, l.item_id, MAX(l.item_name) AS item_name,
                        SUM(l.quantity * COALESCE(c.unit_factor, 1)) AS total_qty,
                        SUM(l.quantity * l.unit_price)              AS total_val,
                        MAX(b.cost)                                 AS piece_cost,
                        MAX(bf.max_factor)                          AS max_factor
                 FROM hq_invoice_lines l
                 INNER JOIN hq_invoices i ON i.sth_id = l.sth_id AND i.suffix = l.suffix
                 LEFT JOIN hq_codes c  ON c.code = l.item_code AND c.item_id = l.item_id
                 LEFT JOIN hq_codes b  ON b.item_id = l.item_id AND b.code_type = 0
                 LEFT JOIN (SELECT item_id, MAX(unit_factor) AS max_factor FROM hq_codes GROUP BY item_id) bf ON bf.item_id = l.item_id
                 WHERE i.invoice_date >= ? AND i.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                   AND i.doc_type IN (2, 6)
                   AND i.supplier_id IN ($inErp)
                   AND l.item_id IS NOT NULL
                 GROUP BY l.sth_id, l.suffix, l.item_id";
    $stmtLines = $pdo->prepare($sqlLines);
    $stmtLines->execute(array_merge([$queryFrom, $queryTo], $allErpIds));
    $postedLinesRaw = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

    $postedLinesMap = [];
    foreach ($postedLinesRaw as $lr) {
        $k = $lr['sth_id'] . '_' . $lr['suffix'];
        $itemId = (int)$lr['item_id'];
        $qty = (float)$lr['total_qty'];
        $val = (float)$lr['total_val'];
        $avgPrice = ($qty > 0.0001) ? ($val / $qty) : 0.0;
        $postedLinesMap[$k][$itemId] = [
            'q'    => $qty,
            'p'    => $avgPrice,
            'name' => (string)$lr['item_name'],
            'cost' => (float)($lr['piece_cost'] ?? 0),
            'maxf' => (float)($lr['max_factor'] ?? 1),
        ];
    }

    $docs = [];
    foreach ($postedInvsRaw as $inv) {
        $cb = ($inv['company_branch'] === null) ? 1 : (int)$inv['company_branch'];
        $party = $cbToParty[$cb] ?? null;
        $counter = $erpToParty[(int)$inv['supplier_id']] ?? null;

        if ($party === null || $counter === null || $party === $counter) {
            continue;
        }

        $sthId = (int)$inv['sth_id'];
        $suffix = (string)$inv['suffix'];
        $key = $sthId . '_' . $suffix;

        $docs[$key] = [
            'key'      => $key,
            'sth_id'   => $sthId,
            'suffix'   => $suffix,
            'party'    => $party,
            'counter'  => $counter,
            'type'     => (int)$inv['doc_type'],
            'no'       => (string)$inv['doc_no'],
            'date'     => date('Y-m-d', strtotime((string)$inv['invoice_date'])),
            'amount'   => (float)$inv['net_amount'],
            'unposted' => false,
            'items'    => $postedLinesMap[$key] ?? [],
        ];
    }

    // استعلام ٣ لغير المترحل: رؤوس فواتير hq_unposted
    $sqlUnp = "SELECT sth_id, doc_no, doc_type, supplier_id, net_amount, invoice_date
               FROM hq_unposted
               WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                 AND doc_type IN (2, 6)
                 AND supplier_id IN ($inErp)";
    $stmtUnp = $pdo->prepare($sqlUnp);
    $stmtUnp->execute(array_merge([$queryFrom, $queryTo], $allErpIds));
    $unpInvsRaw = $stmtUnp->fetchAll(PDO::FETCH_ASSOC);

    // استعلام ٤ لغير المترحل: سطور فواتير hq_unposted_lines مجمعة (بالقطعة + تكلفة ومعامل القطعة)
    $sqlUnpLines = "SELECT l.sth_id, l.item_id, MAX(l.item_name) AS item_name,
                           SUM(l.quantity * COALESCE(c.unit_factor, 1)) AS total_qty,
                           SUM(l.quantity * l.unit_price)              AS total_val,
                           MAX(b.cost)                                 AS piece_cost,
                           MAX(bf.max_factor)                          AS max_factor
                    FROM hq_unposted_lines l
                    INNER JOIN hq_unposted u ON u.sth_id = l.sth_id
                    LEFT JOIN hq_codes c  ON c.code = l.item_code AND c.item_id = l.item_id
                    LEFT JOIN hq_codes b  ON b.item_id = l.item_id AND b.code_type = 0
                    LEFT JOIN (SELECT item_id, MAX(unit_factor) AS max_factor FROM hq_codes GROUP BY item_id) bf ON bf.item_id = l.item_id
                    WHERE u.invoice_date >= ? AND u.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
                      AND u.doc_type IN (2, 6)
                      AND u.supplier_id IN ($inErp)
                      AND l.item_id IS NOT NULL
                    GROUP BY l.sth_id, l.item_id";
    $stmtUnpLines = $pdo->prepare($sqlUnpLines);
    $stmtUnpLines->execute(array_merge([$queryFrom, $queryTo], $allErpIds));
    $unpLinesRaw = $stmtUnpLines->fetchAll(PDO::FETCH_ASSOC);

    $unpLinesMap = [];
    foreach ($unpLinesRaw as $lr) {
        $k = (int)$lr['sth_id'];
        $itemId = (int)$lr['item_id'];
        $qty = (float)$lr['total_qty'];
        $val = (float)$lr['total_val'];
        $avgPrice = ($qty > 0.0001) ? ($val / $qty) : 0.0;
        $unpLinesMap[$k][$itemId] = [
            'q'    => $qty,
            'p'    => $avgPrice,
            'name' => (string)$lr['item_name'],
            'cost' => (float)($lr['piece_cost'] ?? 0),
            'maxf' => (float)($lr['max_factor'] ?? 1),
        ];
    }

    foreach ($unpInvsRaw as $inv) {
        $party = 'hq'; // الطرف في غير المترحل دايماً hq
        $counter = $erpToParty[(int)$inv['supplier_id']] ?? null;

        if ($counter === null || $party === $counter) {
            continue;
        }

        $sthId = (int)$inv['sth_id'];
        $suffix = '';
        $key = $sthId . '_';

        $docs[$key] = [
            'key'      => $key,
            'sth_id'   => $sthId,
            'suffix'   => $suffix,
            'party'    => $party,
            'counter'  => $counter,
            'type'     => (int)$inv['doc_type'],
            'no'       => (string)$inv['doc_no'],
            'date'     => date('Y-m-d', strtotime((string)$inv['invoice_date'])),
            'amount'   => (float)$inv['net_amount'],
            'unposted' => true,
            'items'    => $unpLinesMap[$sthId] ?? [],
        ];
    }

    return array_values($docs);
}

/**
 * اكتشاف التنبيهات والأخطاء بين فواتير الأطراف (دالة صافية بدون اتصال خارجي)
 *
 * @param array $docs فواتير المطابقة
 * @param string $showFrom تاريخ العرض من
 * @param string $showTo تاريخ العرض إلى
 * @param string $today تاريخ اليوم المرجعي
 * @param array $labels مسميات الأطراف بالعربية
 * @return array
 */
function recon_alerts_detect(array $docs, string $showFrom, string $showTo, string $today, array $labels): array
{
    $docsByKey = [];
    foreach ($docs as $d) {
        $docsByKey[$d['key']] = $d;
    }

    $used = [];
    $rawAlerts = [];

    // -------------------------------------------------------------
    // ١) تكرار: نفس party ونفس counter ونفس type، والفرق <= 60 يوم
    // بند ١ (بريف ٨٥): «الأقدم جوّه الأحدث» يعدّ تكرار بس لو old['amount'] >= 0.8 * new['amount']
    // بند ٢ (بريف ٨٥): خطوة ١ مابقتش تحط $used — بتحسب بس خريطة التوائم $twins
    // -------------------------------------------------------------
    $twins = [];
    $docList = array_values($docsByKey);
    $nDocs = count($docList);

    // ترتيب زمني لفحص التكرار (الأقدم أولاً)
    usort($docList, function ($a, $b) {
        $cmp = strcmp($a['date'], $b['date']);
        if ($cmp !== 0) return $cmp;
        return $a['sth_id'] <=> $b['sth_id'];
    });

    for ($i = 0; $i < $nDocs; $i++) {
        $old = $docList[$i];
        if (isset($twins[$old['key']])) {
            continue;
        }
        for ($j = $i + 1; $j < $nDocs; $j++) {
            $new = $docList[$j];
            if (isset($twins[$new['key']])) {
                continue;
            }
            if ($old['party'] !== $new['party'] || $old['counter'] !== $new['counter'] || (int)$old['type'] !== (int)$new['type']) {
                continue;
            }
            $days = recon_alerts_days_diff($old['date'], $new['date']);
            if ($days > 60.0) {
                continue;
            }

            $sim = recon_alerts_calc_sim($old, $new);
            $isDup = ($sim >= 0.999);
            // شرط الاحتواء: قديم جوه جديد، >= 3 أصناف، ومبلغ القديم >= 0.8 * مبلغ الجديد
            if (!$isDup && count($old['items']) >= 3 && (float)$old['amount'] >= 0.8 * (float)$new['amount']) {
                $allContained = true;
                foreach ($old['items'] as $itId => $itOld) {
                    if (!isset($new['items'][$itId]) || abs((float)$itOld['q'] - (float)$new['items'][$itId]['q']) >= 0.001) {
                        $allContained = false;
                        break;
                    }
                }
                if ($allContained) {
                    $isDup = true;
                }
            }

            if ($isDup) {
                $twins[$new['key']] = [
                    'old'        => $old,
                    'new'        => $new,
                    'fixed'      => false,
                    'cancel_doc' => null,
                ];
                // 🔴 خطوة ١ مابقتش تحط $used
            }
        }
    }

    // -------------------------------------------------------------
    // ٢) لغوا بعض جوه نفس الطرف (نفس party و counter، type عكس، <= 75 يوم، sim >= 0.8)
    // بند ٢ (بريف ٨٥): بتشتغل على التوائم الأحدث بس (اللي ليها $twinOf) — هي اللي بتطلع fixed_dup.
    // أي زوج «لغوا بعض» مالوش علاقة بتوأم مايتعملش هنا.
    // -------------------------------------------------------------
    $step2Candidates = [];
    for ($i = 0; $i < $nDocs; $i++) {
        $dA = $docList[$i];
        if (isset($used[$dA['key']])) {
            continue;
        }
        for ($j = $i + 1; $j < $nDocs; $j++) {
            $dB = $docList[$j];
            if (isset($used[$dB['key']])) {
                continue;
            }
            if ($dA['party'] !== $dB['party'] || $dA['counter'] !== $dB['counter']) {
                continue;
            }
            if ((int)$dA['type'] === (int)$dB['type']) {
                continue;
            }
            // شرط بريف ٨٥: لازم واحد منهم على الأقل يكون توأم أحدث (له twinOf)
            $isTwinA = isset($twins[$dA['key']]);
            $isTwinB = isset($twins[$dB['key']]);
            if (!$isTwinA && !$isTwinB) {
                continue;
            }

            $days = recon_alerts_days_diff($dA['date'], $dB['date']);
            if ($days > 75.0) {
                continue;
            }
            $sim = recon_alerts_calc_sim($dA, $dB);
            if ($sim < 0.799) {
                continue;
            }

            $step2Candidates[] = [
                'days'  => $days,
                'sim'   => $sim,
                'docA'  => $dA,
                'docB'  => $dB,
            ];
        }
    }

    // ترتيب المرشحين: الأقرب في التاريخ أولاً، ثم أعلى تشابه
    usort($step2Candidates, function ($a, $b) {
        if (abs($a['days'] - $b['days']) > 0.001) {
            return $a['days'] <=> $b['days'];
        }
        return $b['sim'] <=> $a['sim'];
    });

    foreach ($step2Candidates as $cand) {
        $kA = $cand['docA']['key'];
        $kB = $cand['docB']['key'];
        if (isset($used[$kA]) || isset($used[$kB])) {
            continue;
        }

        $used[$kA] = true;
        $used[$kB] = true;

        if (isset($twins[$kA])) {
            $twins[$kA]['fixed'] = true;
            $twins[$kA]['cancel_doc'] = $cand['docB'];
            $twinDoc   = $twins[$kA]['new'];
            $oldDoc    = $twins[$kA]['old'];
            $cancelDoc = $cand['docB'];
        } else {
            $twins[$kB]['fixed'] = true;
            $twins[$kB]['cancel_doc'] = $cand['docA'];
            $twinDoc   = $twins[$kB]['new'];
            $oldDoc    = $twins[$kB]['old'];
            $cancelDoc = $cand['docA'];
        }

        $partyLabel = $labels[$twinDoc['party']] ?? $twinDoc['party'];
        $typeLabel  = ((int)$twinDoc['type'] === 2) ? 'شراء' : 'مردود';
        $rawAlerts[] = [
            'kind'   => 'fixed_dup',
            'level'  => 'gray',
            'amount' => (float)$twinDoc['amount'],
            'text'   => "{$partyLabel} {$typeLabel} {$twinDoc['no']} كانت مكررة من {$oldDoc['no']}، واتعملها عكس في {$cancelDoc['no']}.",
            'fix'    => 'مفيش حاجة مطلوبة.',
            'docs'   => [$twinDoc['key'], $oldDoc['key'], $cancelDoc['key']],
            'pair'   => [$twinDoc['party'], $twinDoc['counter']],
            'lines'  => [],
        ];
    }

    // -------------------------------------------------------------
    // ٣) المقابل المباشر (X عند A على حساب B و Y عند B على حساب A، type عكس، <= 75 يوم، sim >= 0.8)
    // بند ٢ (بريف ٨٥): بتشتغل على كل اللي فاضل — والتوائم معاهم.
    // في الترتيب: sim ← الأيام ← المبلغ ← الأقدم (date ثم sth_id).
    // -------------------------------------------------------------
    $step3Candidates = [];
    $remainingDocs = [];
    foreach ($docList as $d) {
        if (!isset($used[$d['key']])) {
            $remainingDocs[] = $d;
        }
    }
    $nRem = count($remainingDocs);

    for ($i = 0; $i < $nRem; $i++) {
        $dX = $remainingDocs[$i];
        for ($j = $i + 1; $j < $nRem; $j++) {
            $dY = $remainingDocs[$j];
            // شرط المقابل: طرف/مورد متبادلين والنوع عكس
            if ($dX['party'] !== $dY['counter'] || $dX['counter'] !== $dY['party']) {
                continue;
            }
            if ((int)$dX['type'] === (int)$dY['type']) {
                continue;
            }
            $days = recon_alerts_days_diff($dX['date'], $dY['date']);
            if ($days > 75.0) {
                continue;
            }
            $sim = recon_alerts_calc_sim($dX, $dY);
            if ($sim < 0.799) {
                continue;
            }
            $amtDiff = abs((float)$dX['amount'] - (float)$dY['amount']);

            $step3Candidates[] = [
                'sim'      => $sim,
                'days'     => $days,
                'amt_diff' => $amtDiff,
                'docX'     => $dX,
                'docY'     => $dY,
            ];
        }
    }

    // ترتيب أزواج خطوة ٣: sim تنازلي ← فرق الأيام تصاعدي ← فرق المبلغ تصاعدي ← الأقدم أولاً
    usort($step3Candidates, function ($a, $b) {
        if (abs($a['sim'] - $b['sim']) > 0.0001) {
            return $b['sim'] <=> $a['sim'];
        }
        if (abs($a['days'] - $b['days']) > 0.001) {
            return $a['days'] <=> $b['days'];
        }
        if (abs($a['amt_diff'] - $b['amt_diff']) > 0.01) {
            return $a['amt_diff'] <=> $b['amt_diff'];
        }

        // الأقدم أولاً: date ثم sth_id
        $docsA = [$a['docX'], $a['docY']];
        usort($docsA, function ($d1, $d2) {
            $c = strcmp($d1['date'], $d2['date']);
            if ($c !== 0) return $c;
            return $d1['sth_id'] <=> $d2['sth_id'];
        });

        $docsB = [$b['docX'], $b['docY']];
        usort($docsB, function ($d1, $d2) {
            $c = strcmp($d1['date'], $d2['date']);
            if ($c !== 0) return $c;
            return $d1['sth_id'] <=> $d2['sth_id'];
        });

        $cmpDate1 = strcmp($docsA[0]['date'], $docsB[0]['date']);
        if ($cmpDate1 !== 0) {
            return $cmpDate1;
        }
        $cmpSth1 = $docsA[0]['sth_id'] <=> $docsB[0]['sth_id'];
        if ($cmpSth1 !== 0) {
            return $cmpSth1;
        }

        $cmpDate2 = strcmp($docsA[1]['date'], $docsB[1]['date']);
        if ($cmpDate2 !== 0) {
            return $cmpDate2;
        }
        return $docsA[1]['sth_id'] <=> $docsB[1]['sth_id'];
    });

    foreach ($step3Candidates as $cand) {
        $dX = $cand['docX'];
        $dY = $cand['docY'];
        $kX = $dX['key'];
        $kY = $dY['key'];
        if (isset($used[$kX]) || isset($used[$kY])) {
            continue;
        }

        $used[$kX] = true;
        $used[$kY] = true;

        $pA = $labels[$dX['party']] ?? $dX['party'];
        $pB = $labels[$dY['party']] ?? $dY['party'];
        $typeX = ((int)$dX['type'] === 2) ? 'شراء' : 'مردود';
        $typeY = ((int)$dY['type'] === 2) ? 'شراء' : 'مردود';

        // أ) غير مرحلة
        if ($dX['unposted'] || $dY['unposted']) {
            $unp = $dX['unposted'] ? $dX : $dY;
            $oth = $dX['unposted'] ? $dY : $dX;
            $pUnp = $labels[$unp['party']] ?? $unp['party'];
            $pOth = $labels[$oth['party']] ?? $oth['party'];
            $tUnp = ((int)$unp['type'] === 2) ? 'شراء' : 'مردود';
            $rawAlerts[] = [
                'kind'   => 'unposted',
                'level'  => 'yellow',
                'amount' => (float)$unp['amount'],
                'text'   => "{$pUnp} عندها {$tUnp} {$unp['no']} مقابل {$oth['no']} بتاعة {$pOth}، بس لسه مش مترحّلة.",
                'fix'    => "{$pUnp} ترحّلها.",
                'docs'   => [$dX['key'], $dY['key']],
                'pair'   => [$dX['party'], $dY['party']],
                'lines'  => [],
            ];
        }

        // ب) فرق أصناف (sim < 1)
        if ($cand['sim'] < 0.999) {
            $allItemIds = array_unique(array_merge(array_keys($dX['items']), array_keys($dY['items'])));
            $itemDiffs = [];
            $sumValDiff = 0.0;
            foreach ($allItemIds as $itId) {
                $itX = $dX['items'][$itId] ?? null;
                $itY = $dY['items'][$itId] ?? null;
                $qX = $itX ? (float)$itX['q'] : 0.0;
                $pX = $itX ? (float)$itX['p'] : 0.0;
                $qY = $itY ? (float)$itY['q'] : 0.0;
                $pY = $itY ? (float)$itY['p'] : 0.0;
                $itName = $itX ? $itX['name'] : ($itY ? $itY['name'] : (string)$itId);

                if (abs($qX - $qY) >= 0.001) {
                    $vDiff = abs($qX * $pX - $qY * $pY);
                    $sumValDiff += $vDiff;
                    $itemDiffs[] = [
                        'item_id' => $itId,
                        'name'    => $itName,
                        'q1'      => $qX,
                        'p1'      => $pX,
                        'q2'      => $qY,
                        'p2'      => $pY,
                        'diff'    => $vDiff,
                    ];
                }
            }
            if (!empty($itemDiffs)) {
                $nDiff = count($itemDiffs);
                $vStr = number_format($sumValDiff, 2);
                $rawAlerts[] = [
                    'kind'   => 'items',
                    'level'  => 'yellow',
                    'amount' => $sumValDiff,
                    'text'   => "{$pA} {$typeX} {$dX['no']} قصاد {$pB} {$dY['no']}: {$nDiff} صنف مختلفين بقيمة {$vStr}.",
                    'fix'    => 'راجعوا الأصناف دي مع بعض — اللي عنده الورق الصح التاني يصلّح عليه.',
                    'docs'   => [$dX['key'], $dY['key']],
                    'pair'   => [$dX['party'], $dY['party']],
                    'lines'  => $itemDiffs,
                ];
            }
        }

        // ج) فرق سعر (نفس الكمية وسعر مختلف)
        $priceDiffItems = [];
        $sumPriceDiff = 0.0;
        foreach ($dX['items'] as $itId => $itX) {
            if (isset($dY['items'][$itId])) {
                $itY = $dY['items'][$itId];
                $qX = (float)$itX['q'];
                $qY = (float)$itY['q'];
                if (abs($qX - $qY) < 0.001) {
                    $pX = (float)$itX['p'];
                    $pY = (float)$itY['p'];
                    $diffP = abs($pX - $pY);
                    $totLineDiff = abs($qX * ($pX - $pY));
                    if ($diffP > 0.01 && $totLineDiff >= 0.5) {
                        $sumPriceDiff += $totLineDiff;
                        $priceDiffItems[] = [
                            'item_id' => $itId,
                            'name'    => $itX['name'] ?: ($itY['name'] ?: (string)$itId),
                            'q1'      => $qX,
                            'p1'      => $pX,
                            'q2'      => $qY,
                            'p2'      => $pY,
                            'diff'    => $totLineDiff,
                        ];
                    }
                }
            }
        }
        if (!empty($priceDiffItems)) {
            $nP = count($priceDiffItems);
            $vP = number_format($sumPriceDiff, 2);
            $rawAlerts[] = [
                'kind'   => 'price',
                'level'  => 'yellow',
                'amount' => $sumPriceDiff,
                'text'   => "{$pA} {$dX['no']} و{$pB} {$dY['no']}: {$nP} صنف بنفس الكمية وسعر مختلف، الفرق {$vP}.",
                'fix'    => 'يتظبط السعر عند اللي غلط.',
                'docs'   => [$dX['key'], $dY['key']],
                'pair'   => [$dX['party'], $dY['party']],
                'lines'  => $priceDiffItems,
            ];
        }
    }

    // -------------------------------------------------------------
    // تنبيه سعر باكو على كود قطعة (unit) — أحمر
    // لكل مستند في فترة العرض، ولكل صنف فيه:
    // لو cost > 0 و maxf >= 4 و p / cost >= 4 ← السطر غالباً سعر باكو على كود قطعة
    // -------------------------------------------------------------
    foreach ($docsByKey as $doc) {
        if ($doc['date'] < $showFrom || $doc['date'] > $showTo) {
            continue;
        }
        $unitLines = [];
        $sumUnitDiffQty = 0.0;
        $maxfList = [];
        foreach ($doc['items'] as $itemId => $it) {
            $cost = (float)($it['cost'] ?? 0);
            $maxf = (float)($it['maxf'] ?? 0);
            $p    = (float)($it['p'] ?? 0);
            $q    = (float)($it['q'] ?? 0);
            if ($cost > 0.0001 && $maxf >= 4.0 && ($p / $cost) >= 4.0) {
                $diffQty = $q * ($maxf - 1.0);
                $sumUnitDiffQty += $diffQty;
                $maxfList[] = (int)$maxf;
                $unitLines[] = [
                    'item_id' => $itemId,
                    'name'    => (string)($it['name'] ?? ''),
                    'q1'      => $q,
                    'p1'      => $p,
                    'q2'      => $q * $maxf,
                    'p2'      => $cost,
                    'diff'    => $diffQty,
                ];
            }
        }
        if (!empty($unitLines)) {
            $partyLabel = $labels[$doc['party']] ?? $doc['party'];
            $typeLabel  = ((int)$doc['type'] === 2) ? 'شراء' : 'مردود';
            $nItems     = count($unitLines);
            $distinctMf = array_values(array_unique($maxfList));
            $maxfStr    = count($distinctMf) === 1 ? (string)$distinctMf[0] : implode('/', $distinctMf);

            $rawAlerts[] = [
                'kind'   => 'unit',
                'level'  => 'red',
                'amount' => $sumUnitDiffQty,
                'text'   => "{$partyLabel} {$typeLabel} {$doc['no']}: {$nItems} صنف متسجّلين بكود القطعة وسعر الباكو — الكمية الحقيقية غالباً ×{$maxfStr}.",
                'fix'    => 'افتح المستند وغيّر السطور دي لباركود الباكو بنفس الكمية والسعر — الفلوس مش هتتغيّر.',
                'docs'   => [$doc['key']],
                'pair'   => [$doc['party'], $doc['counter']],
                'lines'  => $unitLines,
            ];
        }
    }

    // -------------------------------------------------------------
    // بعد خطوة ٣: أي فاتورة فاضلة من غير مقابل وليها $twinOf ← dup أحمر
    // وتطلع من الحسابات قبل خطوة ٤. لو اتربطت في خطوة ٣ ← مفيش dup.
    // -------------------------------------------------------------
    foreach ($twins as $newKey => $twin) {
        if ($twin['fixed']) {
            continue;
        }
        if (isset($used[$newKey])) {
            continue;
        }

        $newDoc = $twin['new'];
        $oldDoc = $twin['old'];
        $partyLabel = $labels[$newDoc['party']] ?? $newDoc['party'];
        $typeLabel  = ((int)$newDoc['type'] === 2) ? 'شراء' : 'مردود';
        $p1 = explode('-', $newDoc['date']);
        $d1 = ($p1[2] ?? '') . '/' . ($p1[1] ?? '');
        $p2 = explode('-', $oldDoc['date']);
        $d2 = ($p2[2] ?? '') . '/' . ($p2[1] ?? '');
        $amtStr = number_format($newDoc['amount'], 2);

        $rawAlerts[] = [
            'kind'   => 'dup',
            'level'  => 'red',
            'amount' => (float)$newDoc['amount'],
            'text'   => "{$partyLabel} سجّلت {$typeLabel} {$newDoc['no']} يوم {$d1} بـ{$amtStr}، ونفس الأصناف والكميات متسجّلة قبلها في {$typeLabel} {$oldDoc['no']} يوم {$d2}.",
            'fix'    => "غالباً اتسجّلت مرتين ← {$partyLabel} تمسح {$newDoc['no']}، ولو ماينفعش تعمل عكسها على نفس الحساب.",
            'docs'   => [$newDoc['key'], $oldDoc['key']],
            'pair'   => [$newDoc['party'], $newDoc['counter']],
            'lines'  => [],
        ];
        $used[$newKey] = true;
    }

    // -------------------------------------------------------------
    // ٤) مجموعات (من اللي فضل: connected components برابط >= 2 صنف بنفس الكمية)
    // -------------------------------------------------------------
    $remDocs4 = [];
    foreach ($docList as $d) {
        if (!isset($used[$d['key']])) {
            $remDocs4[$d['key']] = $d;
        }
    }

    $adj = [];
    foreach ($remDocs4 as $k => $d) {
        $adj[$k] = [];
    }

    $remKeys = array_keys($remDocs4);
    $nRem4 = count($remKeys);
    for ($i = 0; $i < $nRem4; $i++) {
        $kX = $remKeys[$i];
        $dX = $remDocs4[$kX];
        for ($j = $i + 1; $j < $nRem4; $j++) {
            $kY = $remKeys[$j];
            $dY = $remDocs4[$kY];
            if ($dX['party'] !== $dY['counter'] || $dX['counter'] !== $dY['party'] || (int)$dX['type'] === (int)$dY['type']) {
                continue;
            }
            if (recon_alerts_days_diff($dX['date'], $dY['date']) > 75.0) {
                continue;
            }

            // فحص وجود >= 2 صنف بنفس الكمية بالضبط
            $sameQtyCnt = 0;
            foreach ($dX['items'] as $itId => $itX) {
                if (isset($dY['items'][$itId]) && abs((float)$itX['q'] - (float)$dY['items'][$itId]['q']) < 0.001) {
                    $sameQtyCnt++;
                    if ($sameQtyCnt >= 2) {
                        break;
                    }
                }
            }
            if ($sameQtyCnt >= 2) {
                $adj[$kX][] = $kY;
                $adj[$kY][] = $kX;
            }
        }
    }

    // استخراج المكونات المترابطة (BFS)
    $visitedComp = [];
    foreach ($remKeys as $k) {
        if (isset($visitedComp[$k])) {
            continue;
        }
        $q = [$k];
        $visitedComp[$k] = true;
        $comp = [];
        while (!empty($q)) {
            $curr = array_shift($q);
            $comp[] = $curr;
            foreach ($adj[$curr] as $neighbor) {
                if (!isset($visitedComp[$neighbor])) {
                    $visitedComp[$neighbor] = true;
                    $q[] = $neighbor;
                }
            }
        }

        // تفريق الفواتير حسب الطرف
        $sideA_docs = [];
        $sideB_docs = [];
        $pAKey = null;
        $pBKey = null;

        foreach ($comp as $cKey) {
            $cDoc = $remDocs4[$cKey];
            if ($pAKey === null) {
                $pAKey = $cDoc['party'];
                $pBKey = $cDoc['counter'];
                $sideA_docs[] = $cDoc;
            } elseif ($cDoc['party'] === $pAKey) {
                $sideA_docs[] = $cDoc;
            } else {
                $sideB_docs[] = $cDoc;
            }
        }

        if (!empty($sideA_docs) && !empty($sideB_docs)) {
            // مجموعة مشتركة من الناحيتين
            foreach ($comp as $cKey) {
                $used[$cKey] = true;
            }

            $sumA = array_sum(array_column($sideA_docs, 'amount'));
            $sumB = array_sum(array_column($sideB_docs, 'amount'));
            $diffGrp = abs($sumA - $sumB);

            if ($diffGrp > 1.0) {
                $nameA = $labels[$pAKey] ?? $pAKey;
                $nameB = $labels[$pBKey] ?? $pBKey;
                $nosA = implode('، ', array_column($sideA_docs, 'no'));
                $nosB = implode('، ', array_column($sideB_docs, 'no'));
                $vStr = number_format($diffGrp, 2);

                // حساب فروق الكميات للأصناف
                $qtyA = [];
                $nameMap = [];
                foreach ($sideA_docs as $d) {
                    foreach ($d['items'] as $itId => $it) {
                        $qtyA[$itId] = ($qtyA[$itId] ?? 0.0) + (float)$it['q'];
                        if (!empty($it['name'])) $nameMap[$itId] = $it['name'];
                    }
                }
                $qtyB = [];
                foreach ($sideB_docs as $d) {
                    foreach ($d['items'] as $itId => $it) {
                        $qtyB[$itId] = ($qtyB[$itId] ?? 0.0) + (float)$it['q'];
                        if (!empty($it['name'])) $nameMap[$itId] = $it['name'];
                    }
                }
                $allItIds = array_unique(array_merge(array_keys($qtyA), array_keys($qtyB)));
                $grpLines = [];
                foreach ($allItIds as $itId) {
                    $q1 = $qtyA[$itId] ?? 0.0;
                    $q2 = $qtyB[$itId] ?? 0.0;
                    if (abs($q1 - $q2) >= 0.001) {
                        $grpLines[] = [
                            'item_id' => $itId,
                            'name'    => $nameMap[$itId] ?? (string)$itId,
                            'q1'      => $q1,
                            'p1'      => 0.0,
                            'q2'      => $q2,
                            'p2'      => 0.0,
                            'diff'    => abs($q1 - $q2),
                        ];
                    }
                }

                $rawAlerts[] = [
                    'kind'   => 'group',
                    'level'  => 'red',
                    'amount' => $diffGrp,
                    'text'   => "فواتير {$nameA} ({$nosA}) قصاد فواتير {$nameB} ({$nosB}): الفرق {$vStr}.",
                    'fix'    => 'افتح الأصناف تحت — الصنف اللي كميته مختلفة هو اللي ناقص عند حد.',
                    'docs'   => $comp,
                    'pair'   => [$pAKey, $pBKey],
                    'lines'  => $grpLines,
                ];
            }
        }
    }

    // -------------------------------------------------------------
    // ٥) على حساب غلط (X عند A على حساب B فاضلة، و Z عند C على حساب A فاضلة، نوع عكس، sim >= 0.8)
    // -------------------------------------------------------------
    $remDocs5 = [];
    foreach ($docList as $d) {
        if (!isset($used[$d['key']])) {
            $remDocs5[] = $d;
        }
    }
    $nRem5 = count($remDocs5);

    for ($i = 0; $i < $nRem5; $i++) {
        $dX = $remDocs5[$i];
        if (isset($used[$dX['key']])) {
            continue;
        }
        for ($j = 0; $j < $nRem5; $j++) {
            if ($i === $j) continue;
            $dZ = $remDocs5[$j];
            if (isset($used[$dZ['key']])) {
                continue;
            }
            // Z عند طرف ثالث C على حساب A
            if ($dZ['party'] === $dX['party'] || $dZ['party'] === $dX['counter']) {
                continue;
            }
            if ($dZ['counter'] !== $dX['party']) {
                continue;
            }
            if ((int)$dZ['type'] === (int)$dX['type']) {
                continue;
            }
            if (recon_alerts_days_diff($dX['date'], $dZ['date']) > 75.0) {
                continue;
            }
            $sim = recon_alerts_calc_sim($dX, $dZ);
            if ($sim >= 0.799) {
                $used[$dX['key']] = true;
                $used[$dZ['key']] = true;

                $pA = $labels[$dX['party']] ?? $dX['party'];
                $pB = $labels[$dX['counter']] ?? $dX['counter'];
                $pC = $labels[$dZ['party']] ?? $dZ['party'];
                $typeX = ((int)$dX['type'] === 2) ? 'شراء' : 'مردود';
                $amtStr = number_format($dX['amount'], 2);

                $rawAlerts[] = [
                    'kind'   => 'wrong_acc',
                    'level'  => 'red',
                    'amount' => (float)$dX['amount'],
                    'text'   => "{$pA} سجّلت {$typeX} {$dX['no']} على حساب {$pB} بـ{$amtStr}، بس اللي قصادها متسجّل عند {$pC} ({$dZ['no']}).",
                    'fix'    => "{$pA} تعمل عكسها على حساب {$pB}، وتسجّلها صح على حساب {$pC}.",
                    'docs'   => [$dX['key'], $dZ['key']],
                    'pair'   => [$dX['party'], $dX['counter']],
                    'lines'  => [],
                ];
                break;
            }
        }
    }

    // -------------------------------------------------------------
    // ٦) الباقي (يتيمة أو قيد الانتظار)
    // -------------------------------------------------------------
    foreach ($docList as $d) {
        if (!isset($used[$d['key']])) {
            $used[$d['key']] = true;
            $daysPassed = (strtotime($today) - strtotime($d['date'])) / 86400.0;
            $pA = $labels[$d['party']] ?? $d['party'];
            $pB = $labels[$d['counter']] ?? $d['counter'];
            $typeStr = ((int)$d['type'] === 2) ? 'شراء' : 'مردود';
            $pParts = explode('-', $d['date']);
            $dStr = ($pParts[2] ?? '') . '/' . ($pParts[1] ?? '');
            $amtStr = number_format($d['amount'], 2);

            if ($daysPassed >= 7.0) {
                $rawAlerts[] = [
                    'kind'   => 'orphan',
                    'level'  => 'red',
                    'amount' => (float)$d['amount'],
                    'text'   => "{$pA} سجّلت {$typeStr} {$d['no']} يوم {$dStr} على حساب {$pB} بـ{$amtStr}، و{$pB} ماسجّلتش قصادها حاجة.",
                    'fix'    => "يا {$pB} تسجّلها، يا {$pA} تلغيها لو البضاعة ماراحتش.",
                    'docs'   => [$d['key']],
                    'pair'   => [$d['party'], $d['counter']],
                    'lines'  => [],
                ];
            } else {
                $nDays = max(0, (int)floor($daysPassed));
                $rawAlerts[] = [
                    'kind'   => 'pending',
                    'level'  => 'gray',
                    'amount' => (float)$d['amount'],
                    'text'   => "{$pA} {$typeStr} {$d['no']} ({$dStr}) لسه ماتسجّلتش عند {$pB} — عدّى {$nDays} يوم.",
                    'fix'    => 'لو عدّت أسبوع هتبقى حمرا.',
                    'docs'   => [$d['key']],
                    'pair'   => [$d['party'], $d['counter']],
                    'lines'  => [],
                ];
            }
        }
    }

    // -------------------------------------------------------------
    // الفلترة: يظهر التنبيه فقط إذا كان تاريخ فاتورة واحدة على الأقل بين showFrom و showTo
    // -------------------------------------------------------------
    $filteredAlerts = [];
    foreach ($rawAlerts as $alert) {
        $hasDocInWindow = false;
        foreach ($alert['docs'] as $dKey) {
            if (isset($docsByKey[$dKey])) {
                $dDate = $docsByKey[$dKey]['date'];
                if ($dDate >= $showFrom && $dDate <= $showTo) {
                    $hasDocInWindow = true;
                    break;
                }
            }
        }
        if ($hasDocInWindow) {
            $filteredAlerts[] = $alert;
        }
    }

    // -------------------------------------------------------------
    // الترتيب: أحمر ← أصفر ← رمادي، وجوه كل لون المبلغ تنازلي
    // -------------------------------------------------------------
    $levelWeight = ['red' => 1, 'yellow' => 2, 'gray' => 3];
    usort($filteredAlerts, function ($a, $b) use ($levelWeight) {
        $wA = $levelWeight[$a['level']] ?? 99;
        $wB = $levelWeight[$b['level']] ?? 99;
        if ($wA !== $wB) {
            return $wA <=> $wB;
        }
        return $b['amount'] <=> $a['amount'];
    });

    return $filteredAlerts;
}
