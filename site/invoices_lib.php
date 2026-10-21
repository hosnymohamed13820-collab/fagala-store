<?php
/**
 * invoices_lib.php — مكتبة دوال فواتير الإدارة (بريف ١٦٥ وما بعده)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/item_lookup_lib.php';
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/group_lib.php';

/**
 * بحث الأصناف جوه الفواتير لبوب أب «تحديد أصناف» في المجموعات.
 */
function inv_pick_search(string $term, int $gid = 0): array
{
    if (!function_exists('ilk_pick_search')) {
        throw new RuntimeException('ارفع item_lookup_lib.php الجديد — النسخة اللي على السيرفر قديمة.');
    }
    if (!ilk_ready()) {
        throw new RuntimeException('المرآة لسه فاضية — شغّل وكيل المزامنة.');
    }

    $term = trim($term);
    $rows = ilk_pick_search($term);

    // الأصناف اللي جوه المجموعة بالفعل — عشان نمنع التكرار في الواجهة
    $have = [];
    if ($gid > 0) {
        foreach (group_selected_map($gid) as $k => $codes) {
            foreach (array_keys($codes) as $c) {
                $have[$k . '|' . $c] = true;
            }
        }
    }
    foreach ($rows as &$r) {
        $r['in_group'] = isset($have[$r['key']]);
    }
    unset($r);

    return $rows;
}

/**
 * أصناف فاتورة واحدة (تصفّح بالفاتورة جوه بوب أب تحديد الأصناف).
 */
function inv_pick_items(int $sth, string $suf = '', int $gid = 0): array
{
    if ($sth <= 0) {
        throw new RuntimeException('رقم الفاتورة ناقص.');
    }

    $h = sqlsrv_fetch_invoice_header($sth, $suf !== '' ? $suf : null);
    if (!$h) {
        throw new RuntimeException('الفاتورة مش موجودة في المرآة — يمكن اتلغت أو السينك متأخر.');
    }

    $realSuf = (string) ($h['suffix'] ?? $suf);
    $isRet   = ((int) ($h['doc_type'] ?? 0) === 6);
    $isUnp   = ($realSuf === 'YY_MM');

    // نفس الدمج المستخدم في شاشة الفاتورة: الكود المكرر سطر واحد
    $lines = merge_invoice_items(sqlsrv_fetch_invoice_items($sth, $realSuf ?: null));

    // الموجود في المجموعة بالفعل
    $have = $gid > 0 ? (group_selected_map($gid)[$sth . '|' . $realSuf] ?? []) : [];

    $rows = [];
    foreach ($lines as $ln) {
        $code = trim((string) ($ln['item_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $rows[] = [
            'key'        => $sth . '|' . $realSuf . '|' . $code,
            'sth_id'     => $sth,
            'suffix'     => $realSuf,
            'invoice_no' => (string) ($h['inv_no'] ?? ''),
            'type_label' => $isRet ? 'مردود' : 'فاتورة شراء',
            'is_return'  => $isRet,
            'unposted'   => $isUnp,
            'supplier'   => (string) ($h['supplier_name'] ?? ''),
            'date'       => (string) ($h['invoice_date'] ?? ''),
            'item_code'  => $code,
            'item_name'  => (string) ($ln['item_name'] ?? ''),
            'qty'        => (float) ($ln['quantity'] ?? 0),
            'in_group'   => !empty($have[$code]),
        ];
    }

    return [
        'title' => trim(($h['inv_no'] ?? '') . ' — ' . ($h['supplier_name'] ?? '')),
        'rows'  => $rows,
    ];
}

/**
 * فواتير مجموعة معيّنة (تصفّح بالمجموعة).
 */
function inv_pick_group_invoices(int $g2): array
{
    $og = $g2 > 0 ? group_get($g2) : null;
    if (!$og) {
        throw new RuntimeException('المجموعة مش موجودة.');
    }

    $rows = [];
    foreach (group_invoices($g2) as $iv) {
        $rows[] = [
            'sth_id'     => (int) $iv['sth_id'],
            'suffix'     => (string) $iv['suffix'],
            'invoice_no' => (string) ($iv['invoice_no'] ?? ''),
            'supplier'   => (string) ($iv['supplier_name'] ?? ''),
            'date'       => substr((string) ($iv['invoice_date'] ?? ''), 0, 10),
            'unposted'   => ((string) $iv['suffix'] === 'YY_MM'),
        ];
    }

    return [
        'title' => trim(($og['name'] ?: ('مجموعة #' . $g2)) . ' — ' . count($rows) . ' فاتورة'),
        'rows'  => $rows,
    ];
}

/**
 * جلب بيانات صفوف بوب أب المجموعات (unposted, posted, branch_count).
 */
function inv_group_picker_data(): array
{
    $unposted = sqlsrv_fetch_unposted_invoices();
    $posted   = sqlsrv_fetch_invoices(2026);

    $branchCount = 0;
    foreach (array_merge($unposted, $posted) as $iv) {
        if (!grp_is_admin_inv($iv)) {
            $branchCount++;
        }
    }

    return [
        'unposted'     => $unposted,
        'posted'       => $posted,
        'branch_count' => $branchCount,
    ];
}


require_once __DIR__ . '/item_flags_lib.php';

/**
 * فتح فاتورة محددة (بالـ suffix بتاع الشهر والفرع ونوع المستند).
 */
function inv_load_open_invoice(
    PDO $pdo,
    int|string $openInvoiceId,
    ?string $openSuffix = null,
    ?int $openCb = null,
    ?int $openDt = null,
    int $uid = 0
): array {
    $openInvoice = sqlsrv_fetch_invoice_header($openInvoiceId, $openSuffix, $openCb, $openDt);
    if (!$openInvoice) {
        return [
            'openInvoice'     => null,
            'isReturnDoc'     => false,
            'isBranchInv'     => false,
            'noDistDoc'       => false,
            'branchName'      => '',
            'items'           => [],
            'totalQty'        => 0.0,
            'distRecord'      => null,
            'distId'          => 0,
            'branchTotals'    => [],
            'orphanCodes'     => [],
            'distByItem'      => [],
            'pendingItems'    => [],
            'salePrices'      => [],
            'itemFlags'       => [],
            'skipMap'         => [],
            'allBalances'     => [],
            'storeNames'      => [],
            'itemBalances'    => [],
            'ashraqaBalances' => [],
            'ashraqaSync'     => null,
        ];
    }

    if ($openCb !== null && !isset($openInvoice['company_branch'])) {
        $openInvoice['company_branch'] = $openCb;
    }
    if ($openDt !== null && !isset($openInvoice['doc_type'])) {
        $openInvoice['doc_type'] = $openDt;
    }

    $isReturnDoc = ((int) ($openInvoice['doc_type'] ?? 0) === 6);
    $isBranchInv = !grp_is_admin_inv(['company_branch_id' => $openInvoice['company_branch'] ?? 0]);
    $noDistDoc   = $isReturnDoc || $isBranchInv;
    $branchName  = '';

    if ($isBranchInv) {
        $cbId = (int) ($openInvoice['company_branch'] ?? 0);
        $bq = $pdo->prepare('SELECT name FROM branches WHERE sto_id = ? LIMIT 1');
        $bq->execute([$cbId]);
        $branchName = (string) ($bq->fetchColumn() ?: ('فرع #' . $cbId));
    }

    $items = merge_invoice_items(sqlsrv_fetch_invoice_items($openInvoiceId, $openSuffix));
    $totalQty = array_sum(array_map(fn($it) => (float) ($it['quantity'] ?? 0), $items));
    $distRecord = ensure_distribution_record($openInvoice, $uid);
    $distId = (int) $distRecord['id'];

    $bq = $pdo->prepare(
        'SELECT branch_id, SUM(amount) AS total FROM invoice_distribution_lines
         WHERE distribution_id = ? GROUP BY branch_id HAVING SUM(amount) > 0'
    );
    $bq->execute([$distId]);
    $branchTotals = [];
    foreach ($bq as $r) {
        $branchTotals[(int) $r['branch_id']] = (float) $r['total'];
    }

    $exclCodes = ifl_skip_map(array_map(fn($i) => (string) ($i['item_code'] ?? ''), $items));
    $liveCodes = [];
    foreach ($items as $i) {
        $liveCodes[(string) ($i['item_code'] ?? '')] = true;
    }

    $dq = $pdo->prepare(
        'SELECT branch_id, notes AS item_code, SUM(amount) AS qty
         FROM invoice_distribution_lines WHERE distribution_id = ?
         GROUP BY branch_id, notes HAVING SUM(amount) > 0'
    );
    $dq->execute([$distId]);
    $orphanCodes = [];
    $distByItem = [];
    foreach ($dq as $r) {
        $c = (string) $r['item_code'];
        $distByItem[$c] = ($distByItem[$c] ?? 0.0) + (float) $r['qty'];
        $isOrphan = $liveCodes && !isset($liveCodes[$c]);
        if ($isOrphan) {
            $orphanCodes[$c] = true;
        }
        if (isset($exclCodes[$c]) || $isOrphan) {
            $b = (int) $r['branch_id'];
            $branchTotals[$b] = max(0, ($branchTotals[$b] ?? 0) - (float) $r['qty']);
            if ($branchTotals[$b] <= 0) {
                unset($branchTotals[$b]);
            }
        }
    }

    $pendingItems = [];
    foreach ($items as $it) {
        $code = (string) ($it['item_code'] ?? '');
        $req  = (float) ($it['quantity'] ?? 0);
        $done = (float) ($distByItem[$code] ?? 0);
        if ($done < $req - 0.0001) {
            $pendingItems[] = [
                'code' => $code,
                'name' => (string) ($it['item_name'] ?? $code),
                'done' => $done,
                'qty'  => $req,
            ];
        }
    }

    $salePrices = resolve_invoice_sale_prices($distId, $items);
    $iflDistIds = [$distId];
    $itemFlags  = ifl_load($iflDistIds);
    $skipMap    = ifl_skip_map(array_map(fn($i) => (string) ($i['item_code'] ?? ''), $items));

    $allBalances = sqlsrv_fetch_item_balances_all(
        array_map(fn($i) => (int) ($i['item_id'] ?? 0), $items)
    );
    $storeNames = sqlsrv_store_names();
    $itemBalances = [];
    foreach ($allBalances as $iid => $byStore) {
        $itemBalances[$iid] = $byStore[ADMIN_STO_ID] ?? 0;
    }
    $ashraqaBalances = ashraqa_fetch_balances(
        array_map(fn($i) => (string) ($i['item_code'] ?? ''), $items)
    );
    $ashraqaSync = ashraqa_last_sync();

    return [
        'openInvoice'     => $openInvoice,
        'isReturnDoc'     => $isReturnDoc,
        'isBranchInv'     => $isBranchInv,
        'noDistDoc'       => $noDistDoc,
        'branchName'      => $branchName,
        'items'           => $items,
        'totalQty'        => $totalQty,
        'distRecord'      => $distRecord,
        'distId'          => $distId,
        'branchTotals'    => $branchTotals,
        'orphanCodes'     => $orphanCodes,
        'distByItem'      => $distByItem,
        'pendingItems'    => $pendingItems,
        'salePrices'      => $salePrices,
        'itemFlags'       => $itemFlags,
        'skipMap'         => $skipMap,
        'allBalances'     => $allBalances,
        'storeNames'      => $storeNames,
        'itemBalances'    => $itemBalances,
        'ashraqaBalances' => $ashraqaBalances,
        'ashraqaSync'     => $ashraqaSync,
    ];
}

/**
 * تحميل بيانات تاب الفواتير المجمّعة (كروت أو مجموعة مفتوحة).
 */
function inv_load_group(?int $grpOpenId = null): array
{
    $grpOpenId  = (int) ($grpOpenId ?? 0);
    $grpCurrent = $grpOpenId > 0 ? group_get($grpOpenId) : null;
    $grpData    = null;
    $grpCards   = [];
    $allBalances     = [];
    $itemBalances    = [];
    $ashraqaBalances = [];
    $ashraqaSync     = null;
    $salePrices      = [];
    $itemFlags       = [];
    $skipMap         = [];

    if ($grpCurrent) {
        $grpData = group_load((int) $grpCurrent['id']);
        if ($grpData['items']) {
            $grpItemIds = array_map(fn($i) => (int) $i['item_id'], $grpData['items']);
            $allBalances = sqlsrv_fetch_item_balances_all($grpItemIds);
            foreach ($allBalances as $iid => $byStore) {
                $itemBalances[$iid] = $byStore[ADMIN_STO_ID] ?? 0;
            }
            $ashraqaBalances = ashraqa_fetch_balances(
                array_map(fn($i) => (string) $i['item_code'], $grpData['items'])
            );
            $ashraqaSync = ashraqa_last_sync();
            $salePrices = group_resolve_prices($grpData['items']);
            $iflDistIds = array_values($grpData['dist_ids']);
            $itemFlags  = ifl_load($iflDistIds);
            $skipMap    = ifl_skip_map(
                array_map(fn($i) => (string) $i['item_code'], $grpData['items'])
            );
        }
    } else {
        $grpCards = group_list();
    }

    return [
        'grpOpenId'       => $grpOpenId,
        'grpCurrent'      => $grpCurrent,
        'grpData'         => $grpData,
        'grpCards'        => $grpCards,
        'allBalances'     => $allBalances,
        'itemBalances'    => $itemBalances,
        'ashraqaBalances' => $ashraqaBalances,
        'ashraqaSync'     => $ashraqaSync,
        'salePrices'      => $salePrices,
        'itemFlags'       => $itemFlags,
        'skipMap'         => $skipMap,
    ];
}

/**
 * تحميل بيانات تاب حساب الخصم.
 */
function inv_load_discount(
    ?string $discInvId = null,
    ?string $discSuffix = null,
    ?float $discNetOverride = null,
    array $discItemPct = []
): array {
    $discUnposted = sqlsrv_fetch_unposted_invoices();
    $discPosted   = sqlsrv_fetch_invoices(2026);
    $invoices     = array_merge($discUnposted, $discPosted);

    $discHeader = null;
    $discItems  = [];
    $discCalc   = null;

    if ($discInvId !== null && $discInvId !== '') {
        $discHeader = sqlsrv_fetch_invoice_header($discInvId, $discSuffix);
        if ($discHeader) {
            $discItems = sqlsrv_fetch_invoice_items($discInvId, $discSuffix);
            $discCalc  = calc_invoice_discount($discHeader, $discItems, $discNetOverride, $discItemPct);
        }
    }

    return [
        'discUnposted'    => $discUnposted,
        'discPosted'      => $discPosted,
        'invoices'        => $invoices,
        'discInvId'       => $discInvId,
        'discSuffix'      => $discSuffix,
        'discNetOverride' => $discNetOverride,
        'discItemPct'     => $discItemPct,
        'discHeader'      => $discHeader,
        'discItems'       => $discItems,
        'discCalc'        => $discCalc,
    ];
}

/**
 * تحميل قائمة فواتير الإدارة (مرحلة أو غير مرحلة) مع التجميع وحالات الفواتير.
 */
function inv_load_list(string $tab = 'posted', string $invScope = 'admin'): array
{
    if ($tab === 'unposted') {
        $invoices = sqlsrv_fetch_unposted_invoices();
    } else {
        $invoices = sqlsrv_fetch_invoices(2026);
    }

    $itemCountMap = [];
    try {
        $nExpr = "COUNT(DISTINCT CASE WHEN TRIM(COALESCE(item_code,'')) <> '' THEN TRIM(item_code)
                                      ELSE CONCAT('#', COALESCE(item_id, 0)) END)";
        if ($tab === 'unposted') {
            foreach (db()->query("SELECT sth_id, {$nExpr} AS n_items FROM hq_unposted_lines GROUP BY sth_id") as $r) {
                $itemCountMap[$r['sth_id'] . '|YY_MM'] = (int) $r['n_items'];
            }
        } else {
            foreach (db()->query("SELECT sth_id, suffix, {$nExpr} AS n_items FROM hq_invoice_lines GROUP BY sth_id, suffix") as $r) {
                $itemCountMap[$r['sth_id'] . '|' . $r['suffix']] = (int) $r['n_items'];
            }
        }
    } catch (Throwable $ex) {
        $itemCountMap = [];
    }

    $invoiceGroups = group_invoices_by_branch($invoices);

    $adminCount = 0;
    $branchCount = 0;
    foreach ($invoiceGroups as $g) {
        $cnt = count($g['invoices']);
        if (!empty($g['is_admin']) || (int) ($g['branch_id'] ?? 0) === 1) {
            $adminCount += $cnt;
        } else {
            $branchCount += $cnt;
        }
    }

    if ($invScope !== 'all') {
        $filteredGroups = [];
        foreach ($invoiceGroups as $g) {
            $isAdm = !empty($g['is_admin']) || (int) ($g['branch_id'] ?? 0) === 1;
            if ($invScope === 'admin' && $isAdm) {
                $filteredGroups[] = $g;
            } elseif ($invScope === 'branches' && !$isAdm) {
                $filteredGroups[] = $g;
            }
        }
        $invoiceGroups = $filteredGroups;
    }

    $invStatuses = get_invoice_statuses(array_map(
        fn($i) => (string) ($i['inv_no'] ?? ''), $invoices
    ));
    $invNotes = get_invoices_with_notes(array_map(
        fn($i) => (string) ($i['inv_no'] ?? ''), $invoices
    ));

    return [
        'invoices'      => $invoices,
        'itemCountMap'  => $itemCountMap,
        'invoiceGroups' => $invoiceGroups,
        'adminCount'    => $adminCount,
        'branchCount'   => $branchCount,
        'invStatuses'   => $invStatuses,
        'invNotes'      => $invNotes,
    ];
}
