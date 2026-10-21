<?php
/**
 * undistributed_lib.php — مكتبة حسابات الأصناف غير الموزعة
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

// حسابات الفروع في نظام الشركة — المردود ليها = توزيع، مش مرتجع للمورد
const UNDIST_BRANCH_ACCOUNTS = [
    4       => 'بونجور',
    1000181 => 'ABC',
    1000180 => 'دجلة',
    3000091 => 'الفجالة',
    1000245 => 'إشراقة',
    1000252 => 'إشراقة',
];

// «الرئيسية (الادارة)» — الإدارة بتشتري/ترجّع لنفسها. مالهاش دعوة بالتوزيع (قرار محمد ٢٥ سبتمبر)
const UNDIST_SELF_ACCOUNT = 1000182;

/**
 * جلب تفاصيل فاتورة محددة (للنافذة المنبثقة / جزء invoice).
 */
function undist_get_invoice(int $sth, string $suffix): ?array
{
    if ($sth <= 0 || $suffix === '') {
        return null;
    }

    $pdo = db();
    $stHdr = $pdo->prepare(
        'SELECT sth_id, suffix, doc_no, doc_type, supplier_id, supplier_name, invoice_date
         FROM hq_invoices
         WHERE sth_id = ? AND suffix = ? AND company_branch = 1
         LIMIT 1'
    );
    $stHdr->execute([$sth, $suffix]);
    $hdr = $stHdr->fetch(PDO::FETCH_ASSOC);

    if (!$hdr) {
        return null;
    }

    $docType   = (int) $hdr['doc_type'];
    $suppId    = (int) ($hdr['supplier_id'] ?? 0);
    $typeLabel = 'أخرى';
    if ($docType === 2) {
        if (isset(UNDIST_BRANCH_ACCOUNTS[$suppId])) {
            $typeLabel = 'رجوع من فرع (' . UNDIST_BRANCH_ACCOUNTS[$suppId] . ')';
        } else {
            $typeLabel = 'شراء';
        }
    } elseif ($docType === 6) {
        if (isset(UNDIST_BRANCH_ACCOUNTS[$suppId])) {
            $typeLabel = 'توزيع على فرع (' . UNDIST_BRANCH_ACCOUNTS[$suppId] . ')';
        } else {
            $typeLabel = 'مردود للمورد';
        }
    }

    $stLines = $pdo->prepare(
        'SELECT line_no, item_code, item_name, quantity, unit_price
         FROM hq_invoice_lines
         WHERE sth_id = ? AND suffix = ?
         ORDER BY line_no ASC'
    );
    $stLines->execute([$sth, $suffix]);
    $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    return [
        'header' => [
            'doc_no'        => (string) ($hdr['doc_no'] ?? ''),
            'type_label'    => $typeLabel,
            'supplier_name' => (string) ($hdr['supplier_name'] ?? ''),
            'invoice_date'  => (string) ($hdr['invoice_date'] ?? ''),
        ],
        'lines'  => $lines,
    ];
}

/**
 * جلب الفترة المحفوظة الحالية.
 */
function undist_get_state(): ?array
{
    $st = db()->query('SELECT date_from, date_to FROM undist_state WHERE id = 1 LIMIT 1');
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * عدد الملاحظات المسجلة حالياً.
 */
function undist_notes_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM undist_notes')->fetchColumn();
}

/**
 * حفظ فترة جديدة مع التحقق الصارم ومسح الملاحظات القديمة بالتأكيد داخل معاملة واحدة.
 */
function undist_set_period(string $from, string $to, bool $confirm, ?int $uid = null): void
{
    $pdo = db();
    $from = trim($from);
    $to   = trim($to);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new InvalidArgumentException('صيغة التاريخ غير صالحة.');
    }

    if ($from > $to) {
        throw new InvalidArgumentException('تاريخ البداية بعد النهاية.');
    }

    $diffDays = (strtotime($to) - strtotime($from)) / 86400;
    if ($diffDays > 180) {
        throw new InvalidArgumentException('الفترة طويلة — ١٨٠ يوم بالكتير.');
    }

    $curState = undist_get_state();
    $samePeriod = ($curState && $curState['date_from'] === $from && $curState['date_to'] === $to);

    if (!$samePeriod) {
        $notesCount = undist_notes_count();
        if ($notesCount > 0 && !$confirm) {
            throw new RuntimeException('يوجد ملاحظات مسجلة على الفترة الحالية، تأكيد المسح مطلوب.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM undist_notes');
            $st = $pdo->prepare(
                'INSERT INTO undist_state (id, date_from, date_to, updated_by)
                 VALUES (1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    date_from  = VALUES(date_from),
                    date_to    = VALUES(date_to),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $st->execute([$from, $to, ($uid && $uid > 0) ? $uid : null]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * حفظ أو مسح ملاحظة صنف.
 */
function undist_save_note(string $code, string $note, ?int $uid = null): void
{
    $pdo = db();
    $code = trim($code);
    $note = trim($note);

    if ($code === '') {
        throw new InvalidArgumentException('كود الصنف مطلوب.');
    }

    if ($note === '') {
        $st = $pdo->prepare('DELETE FROM undist_notes WHERE item_code = ?');
        $st->execute([$code]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO undist_notes (item_code, note, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                note       = VALUES(note),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $st->execute([$code, $note, ($uid && $uid > 0) ? $uid : null]);
    }
}

/**
 * حساب تقرير الأصناف غير الموزعة للفترة المحددة (أو الفترة المحفوظة).
 * يرجع نفس المصفوفات والإحصائيات التي يستعملها رندر الصفحة بالحرف.
 */
function undist_calculate_report(?string $from = null, ?string $to = null): array
{
    $pdo = db();
    $stateRow = undist_get_state();
    $hasState = ($stateRow !== null);

    $savedFrom = $from !== null ? $from : ($hasState ? (string) $stateRow['date_from'] : '');
    $savedTo   = $to !== null ? $to : ($hasState ? (string) $stateRow['date_to'] : '');

    $notesCount = undist_notes_count();

    $defaultFrom = ($savedFrom !== '') ? $savedFrom : date('Y-m-d', strtotime('-7 days'));
    $defaultTo   = ($savedTo !== '')   ? $savedTo   : date('Y-m-d');

    $items            = [];
    $purchaseInvoices = [];
    $diffCount        = 0;
    $zeroDiffCount    = 0;
    $mismatchCount    = 0;
    $lastBalanceSync  = null;

    if ($savedFrom !== '' && $savedTo !== '') {
        $branchIds       = array_keys(UNDIST_BRANCH_ACCOUNTS);
        $inPlaceholders  = implode(',', array_fill(0, count($branchIds), '?'));

        // الاستعلام ١: التجميع لكل صنف في الإدارة فقط (company_branch = 1) مع استبعاد حساب الإدارة
        $aggSql = "SELECT 
            l.item_code,
            MAX(l.item_name) AS item_name,
            MAX(l.item_id) AS item_id,
            SUM(CASE WHEN h.doc_type = 2 AND (h.supplier_id NOT IN ($inPlaceholders) OR h.supplier_id IS NULL) THEN l.quantity ELSE 0 END) AS qty_purchased,
            SUM(CASE WHEN h.doc_type = 2 AND h.supplier_id IN ($inPlaceholders) THEN l.quantity ELSE 0 END) AS qty_back_branch,
            SUM(CASE WHEN h.doc_type = 6 AND (h.supplier_id NOT IN ($inPlaceholders) OR h.supplier_id IS NULL) THEN l.quantity ELSE 0 END) AS qty_returned_vendor,
            SUM(CASE WHEN h.doc_type = 6 AND h.supplier_id IN ($inPlaceholders) THEN l.quantity ELSE 0 END) AS qty_distributed_branch
        FROM hq_invoice_lines l
        JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
        WHERE h.company_branch = 1
          AND (h.supplier_id IS NULL OR h.supplier_id <> ?)
          AND h.doc_type IN (2, 6)
          AND h.invoice_date >= ?
          AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
          AND l.item_code <> ''
        GROUP BY l.item_code";

        $aggParams = array_merge($branchIds, $branchIds, $branchIds, $branchIds, [UNDIST_SELF_ACCOUNT, $savedFrom, $savedTo]);
        $stAgg     = $pdo->prepare($aggSql);
        $stAgg->execute($aggParams);
        $aggRows   = $stAgg->fetchAll(PDO::FETCH_ASSOC);

        // الاستعلام ٢: فواتير الشراء والمردود لنفس الفترة في الإدارة فقط (company_branch = 1) مع استبعاد حساب الإدارة
        $invSql = "SELECT DISTINCT
            l.item_code,
            h.sth_id,
            h.suffix,
            h.doc_no,
            h.doc_type,
            h.supplier_id,
            h.supplier_name,
            h.invoice_date
        FROM hq_invoice_lines l
        JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
        WHERE h.company_branch = 1
          AND (h.supplier_id IS NULL OR h.supplier_id <> ?)
          AND h.doc_type IN (2, 6)
          AND h.invoice_date >= ?
          AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
          AND l.item_code <> ''
        ORDER BY h.invoice_date ASC";

        $stInv = $pdo->prepare($invSql);
        $stInv->execute([UNDIST_SELF_ACCOUNT, $savedFrom, $savedTo]);
        $invRows = $stInv->fetchAll(PDO::FETCH_ASSOC);

        $itemInvoices = [];
        foreach ($invRows as $inv) {
            $code       = (string) $inv['item_code'];
            $docType    = (int) $inv['doc_type'];
            $suppId     = (int) ($inv['supplier_id'] ?? 0);
            $isBranch   = isset(UNDIST_BRANCH_ACCOUNTS[$suppId]);
            $branchName = $isBranch ? UNDIST_BRANCH_ACCOUNTS[$suppId] : null;

            if ($docType === 2 && !$isBranch) {
                $itemInvoices[$code]['purchases'][] = [
                    'sth_id'        => (int) $inv['sth_id'],
                    'suffix'        => (string) $inv['suffix'],
                    'doc_no'        => (string) $inv['doc_no'],
                    'supplier_name' => (string) ($inv['supplier_name'] ?? ''),
                    'invoice_date'  => (string) $inv['invoice_date'],
                ];
            } elseif ($docType === 2 && $isBranch) {
                $grp   = 'رجع من ' . $branchName;
                $docNo = (string) $inv['doc_no'];
                $label = $docNo . ' · ' . $grp;
                $itemInvoices[$code]['returns'][] = [
                    'sth_id'  => (int) $inv['sth_id'],
                    'suffix'  => (string) $inv['suffix'],
                    'doc_no'  => $docNo,
                    'grp'     => $grp,
                    'label'   => $label,
                    'is_back' => true,
                ];
            } elseif ($docType === 6) {
                $grp   = ($branchName !== null ? $branchName : 'مورد');
                $docNo = (string) $inv['doc_no'];
                $label = $docNo . ' · ' . $grp;
                $itemInvoices[$code]['returns'][] = [
                    'sth_id'  => (int) $inv['sth_id'],
                    'suffix'  => (string) $inv['suffix'],
                    'doc_no'  => $docNo,
                    'grp'     => $grp,
                    'label'   => $label,
                    'is_back' => false,
                ];
            }
        }

        // الاستعلام ٣: الملاحظات المحفوظة
        $notesMap = [];
        $stNotes  = $pdo->query('SELECT item_code, note FROM undist_notes');
        while ($nRow = $stNotes->fetch(PDO::FETCH_ASSOC)) {
            $notesMap[(string) $nRow['item_code']] = (string) $nRow['note'];
        }

        // الاستعلام ٤: أرصدة الإدارة في hq_balances (sto_id = 1) وتاريخ آخر تحديث
        $balancesMap = [];
        $stBal = $pdo->query('SELECT item_id, balance FROM hq_balances WHERE sto_id = 1');
        while ($bRow = $stBal->fetch(PDO::FETCH_ASSOC)) {
            $balancesMap[(int) $bRow['item_id']] = round((float) $bRow['balance'], 3);
        }
        $stSync = $pdo->query('SELECT MAX(updated_at) FROM hq_balances WHERE sto_id = 1');
        $lastBalanceSync = $stSync->fetchColumn();

        // إحصاء تكرار item_id لمعرفة الأصناف المشتركة بين أكثر من كود في الجدول
        $itemIdCounts = [];
        foreach ($aggRows as $r) {
            $iid = ($r['item_id'] !== null && $r['item_id'] !== '') ? (int) $r['item_id'] : null;
            if ($iid !== null && $iid > 0) {
                $itemIdCounts[$iid] = ($itemIdCounts[$iid] ?? 0) + 1;
            }
        }

        // ترتيب مجموعات المردود
        $branchOrder = array_values(array_unique(array_values(UNDIST_BRANCH_ACCOUNTS)));
        $branchOrderMap = array_flip($branchOrder);
        $getGrpWeight = function ($grp) use ($branchOrderMap) {
            if (isset($branchOrderMap[$grp])) {
                return 100 + $branchOrderMap[$grp];
            }
            if (str_starts_with($grp, 'رجع من ')) {
                $sub = mb_substr($grp, 7);
                $idx = $branchOrderMap[$sub] ?? 50;
                return 200 + $idx;
            }
            if ($grp === 'مورد') {
                return 300;
            }
            return 400;
        };

        // بناء قائمة الأصناف مع حساب الفرق وصافي التوزيع والرصيد
        foreach ($aggRows as $r) {
            $code             = (string) $r['item_code'];
            $name             = (string) ($r['item_name'] ?? '');
            $itemId           = ($r['item_id'] !== null && $r['item_id'] !== '') ? (int) $r['item_id'] : null;
            $isShared         = ($itemId !== null && ($itemIdCounts[$itemId] ?? 0) > 1);
            $sharedCount      = $isShared ? $itemIdCounts[$itemId] : 1;

            $qtyPurchased     = round((float) ($r['qty_purchased'] ?? 0), 3);
            $qtyBackBranch    = round((float) ($r['qty_back_branch'] ?? 0), 3);
            $qtyRetVendor     = round((float) ($r['qty_returned_vendor'] ?? 0), 3);
            $qtyDistBranchRaw = round((float) ($r['qty_distributed_branch'] ?? 0), 3);
            $qtyDistBranch    = round($qtyDistBranchRaw - $qtyBackBranch, 3);
            $diff             = round($qtyPurchased - $qtyRetVendor - $qtyDistBranch, 3);

            if ($diff == 0) {
                $zeroDiffCount++;
            } else {
                $diffCount++;
            }

            // رصيد الإدارة والمقارنة مع الفرق
            $balance     = null;
            $balMismatch = false;
            $balDiff     = null;

            if ($itemId !== null) {
                $balance = $balancesMap[$itemId] ?? 0.0;
                if (!$isShared) {
                    $balDiff = round($balance - $diff, 3);
                    if ($balance != $diff) {
                        $balMismatch = true;
                        $mismatchCount++;
                    }
                }
            }

            // تجميع فواتير المردود في مجموعات حسب الفرع / رجع من / مورد
            $rawReturns = $itemInvoices[$code]['returns'] ?? [];
            $returnGroups = [];
            foreach ($rawReturns as $retInv) {
                $returnGroups[$retInv['grp']][] = $retInv;
            }
            uksort($returnGroups, function ($a, $b) use ($getGrpWeight) {
                $wA = $getGrpWeight($a);
                $wB = $getGrpWeight($b);
                if ($wA !== $wB) {
                    return $wA <=> $wB;
                }
                return strcmp($a, $b);
            });

            $items[] = [
                'item_code'       => $code,
                'item_name'       => $name,
                'item_id'         => $itemId,
                'is_shared'       => $isShared,
                'shared_count'    => $sharedCount,
                'qty_purchased'   => $qtyPurchased,
                'qty_back_branch' => $qtyBackBranch,
                'qty_ret_vendor'  => $qtyRetVendor,
                'qty_dist_branch' => $qtyDistBranch,
                'diff'            => $diff,
                'balance'         => $balance,
                'bal_mismatch'    => $balMismatch,
                'bal_diff'        => $balDiff,
                'note'            => $notesMap[$code] ?? '',
                'purchases'       => $itemInvoices[$code]['purchases'] ?? [],
                'returns'         => $rawReturns,
                'return_groups'   => $returnGroups,
            ];
        }

        // الترتيب: الفرق من الأكبر للأصغر، وبعدين الاسم
        usort($items, function ($a, $b) {
            if ($a['diff'] != $b['diff']) {
                return ($b['diff'] <=> $a['diff']);
            }
            return strcmp($a['item_name'], $b['item_name']);
        });

        // إجمالي فواتير الشراء
        $purchaseInvoices = [];
        foreach ($items as $it) {
            foreach ($it['purchases'] as $p) {
                $key = $p['sth_id'] . '|' . $p['suffix'];
                if (!isset($purchaseInvoices[$key])) {
                    $purchaseInvoices[$key] = [
                        'key'           => $key,
                        'doc_no'        => (string) $p['doc_no'],
                        'supplier_name' => (string) ($p['supplier_name'] ?? ''),
                        'invoice_date'  => (string) $p['invoice_date'],
                        'count'         => 0,
                    ];
                }
                $purchaseInvoices[$key]['count']++;
            }
        }
        usort($purchaseInvoices, function ($a, $b) {
            if ($a['count'] !== $b['count']) {
                return ($b['count'] <=> $a['count']);
            }
            return strcmp($a['invoice_date'], $b['invoice_date']);
        });
    }

    return [
        'has_state'         => $hasState,
        'saved_from'        => $savedFrom,
        'saved_to'          => $savedTo,
        'default_from'      => $defaultFrom,
        'default_to'        => $defaultTo,
        'notes_count'       => $notesCount,
        'items'             => $items,
        'purchase_invoices' => $purchaseInvoices,
        'diff_count'        => $diffCount,
        'zero_diff_count'   => $zeroDiffCount,
        'mismatch_count'    => $mismatchCount,
        'last_balance_sync' => $lastBalanceSync,
    ];
}
