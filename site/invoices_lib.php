<?php
/**
 * invoices_lib.php — مكتبة دوال فواتير الإدارة (بريف ١٦٥ وما بعده)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/item_lookup_lib.php';
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/group_lib.php';
require_once __DIR__ . '/item_flags_lib.php';

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

/**
 * هل سجل التوزيع تابع لمردود شراء (doc_type = 6).
 */
function dist_is_return(PDO $pdo, int $distId): bool
{
    $stDt = $pdo->prepare('SELECT doc_type FROM invoice_distribution WHERE id = ?');
    $stDt->execute([$distId]);
    return (int) $stDt->fetchColumn() === 6;
}

/**
 * تغيير حالة الفاتورة يدوياً أو تلقائياً أو معاينة النواقص (dry).
 */
function inv_set_status(
    PDO $pdo,
    int $distId,
    string $status,
    int $uid,
    bool $dry = false,
    $invId = null,
    ?string $suffix = null
): array {
    if ($distId <= 0) throw new RuntimeException('فاتورة غير صحيحة.');
    if (dist_is_return($pdo, $distId)) {
        throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
    }

    if ($dry) {
        $dryItems = [];
        try {
            $dryItems = sqlsrv_fetch_invoice_items($invId, $suffix);
        } catch (Throwable $ex) { $dryItems = []; }
        return ['pending' => invoice_pending_items($distId, $dryItems)];
    }

    if ($status === 'auto') {
        clear_invoice_status_manual($distId);
        if ($invId !== null && $invId !== '') {
            refresh_invoice_status($distId, $invId, $suffix, false);
        }
        $cur = $pdo->prepare('SELECT status FROM invoice_distribution WHERE id = ?');
        $cur->execute([$distId]);
        $newStatus = (string) $cur->fetchColumn();
        if ($newStatus === 'done') {
            require_once __DIR__ . '/receiving_flow_lib.php';
            receiving_open($pdo, 0, $distId, $uid);
        }
        return ['status' => $newStatus, 'manual' => false];
    }

    if (!set_invoice_status_manual($distId, $status, $uid)) {
        throw new RuntimeException('حالة غير صحيحة.');
    }
    if ($status === 'done') {
        require_once __DIR__ . '/receiving_flow_lib.php';
        receiving_open($pdo, 0, $distId, $uid);
    }
    return ['status' => $status, 'manual' => true];
}

/**
 * حفظ كمية صنف لفرع في فاتورة توزيع محددة (autosave).
 */
function inv_autosave(
    PDO $pdo,
    int $distId,
    string $itemCode,
    float $itemQty,
    int $branchId,
    float $qty,
    int $uid,
    $invId = null,
    ?string $suffix = null
): array {
    if ($distId <= 0 || $itemCode === '' || $branchId <= 0) {
        throw new RuntimeException('بيانات ناقصة.');
    }
    if (dist_is_return($pdo, $distId)) {
        throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
    }
    $res = set_branch_distribution($distId, $itemCode, $itemQty, $branchId, $qty, $uid);
    $bt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines WHERE distribution_id=? AND branch_id=?');
    $bt->execute([$distId, $branchId]);
    $branchTotal = (float) $bt->fetchColumn();

    if ($invId !== null && $invId !== '') {
        refresh_invoice_status($distId, $invId, $suffix, false);
    }

    try {
        require_once __DIR__ . '/invoice_problems_lib.php';
        sync_invoice_problems_for_dist($pdo, $distId);
    } catch (Throwable $e) {
        error_log('sync_invoice_problems_for_dist failed: ' . $e->getMessage());
    }

    return ['remaining' => $res['remaining'], 'branch_total' => $branchTotal];
}

/**
 * حفظ توزيع صنف من الشاشة المجمّعة (شلال على فواتير المجموعة).
 */
function inv_group_autosave(
    PDO $pdo,
    int $gid,
    string $itemCode,
    int $branchId,
    float $qty,
    int $uid
): array {
    if ($gid <= 0 || $itemCode === '' || $branchId <= 0) {
        throw new RuntimeException('بيانات ناقصة.');
    }

    $g = group_load($gid);
    $sources = [];
    foreach ($g['items'] as $it) {
        if ((string) $it['item_code'] === $itemCode) { $sources = $it['sources']; break; }
    }
    if (!$sources) throw new RuntimeException('الصنف مش موجود في المجموعة.');

    $res = group_set_distribution($sources, $itemCode, $branchId, $qty, $uid);

    $groupTotals   = [];
    $invoiceTotals = [];
    $distTotalsMap = [];
    if (!empty($g['dist_ids'])) {
        $dids = array_values(array_unique(array_map('intval', $g['dist_ids'])));
        $ph   = implode(',', array_fill(0, count($dids), '?'));
        $q    = $pdo->prepare(
            "SELECT distribution_id, branch_id, SUM(amount) AS t
             FROM invoice_distribution_lines
             WHERE distribution_id IN ($ph)
             GROUP BY distribution_id, branch_id
             HAVING SUM(amount) > 0"
        );
        $q->execute($dids);
        while ($r = $q->fetch()) {
            $distTotalsMap[(int) $r['distribution_id']][(int) $r['branch_id']] = (float) $r['t'];
        }
    }
    foreach ($g['dist_ids'] as $sth => $did) {
        $rows = $distTotalsMap[(int) $did] ?? [];
        foreach ($rows as $bid => $t) {
            $invoiceTotals[(string) $sth][(string) $bid] = $t;
            $groupTotals[(string) $bid] = ($groupTotals[(string) $bid] ?? 0) + $t;
        }
    }
    $branchTotal = (float) ($groupTotals[(string) $branchId] ?? 0);
    group_refresh_statuses($gid);

    try {
        require_once __DIR__ . '/invoice_problems_lib.php';
        if (!empty($g['dist_ids'])) {
            foreach ($g['dist_ids'] as $did) {
                $did = (int) $did;
                if ($did > 0) {
                    sync_invoice_problems_for_dist($pdo, $did);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('sync_invoice_problems_for_dist failed: ' . $e->getMessage());
    }

    return [
        'remaining'      => $res['remaining'],
        'branch_total'   => $branchTotal,
        'split'          => $res['split'],
        'group_totals'   => (object) $groupTotals,
        'invoice_totals' => (object) $invoiceTotals,
    ];
}

/**
 * معاينة سحب الحجز لفاتورة توزيع محددة.
 */
function inv_reservation_preview(int $distId, $invId, ?string $suffix = null): array
{
    if ($distId <= 0 || $invId === null || $invId === '') {
        throw new RuntimeException('بيانات ناقصة.');
    }
    $rawItems = sqlsrv_fetch_invoice_items($invId, $suffix);
    $items = merge_invoice_items($rawItems);
    $preview = reservation_pull_preview($distId, $items);
    return [
        'items'            => $preview['items'],
        'total_candidates' => $preview['total_candidates'],
    ];
}

/**
 * تطبيق سحب الحجز وتوزيعه على الفروع وتحديث الحجوزات.
 */
function inv_reservation_apply(
    PDO $pdo,
    int $distId,
    string $invoiceNo,
    $invId,
    ?string $suffix,
    array $alloc,
    int $uid
): array {
    if ($distId <= 0 || $invId === null || $invId === '') {
        throw new RuntimeException('بيانات ناقصة.');
    }
    if (dist_is_return($pdo, $distId)) {
        throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
    }

    $rawItems = sqlsrv_fetch_invoice_items($invId, $suffix);
    $items = merge_invoice_items($rawItems);
    $itemQty      = [];
    $codeByItemId = [];
    foreach ($items as $it) {
        $itemQty[(string) $it['item_code']] = (float) ($it['quantity'] ?? 0);
        $iid = (int) ($it['item_id'] ?? 0);
        if ($iid > 0) $codeByItemId[$iid] = (string) $it['item_code'];
    }

    $res = reservation_pull_apply($distId, $invoiceNo, $itemQty, $alloc, $uid, $codeByItemId);
    return ['allocated_count' => $res['allocated_count']];
}

/**
 * سجلات التوزيع بتاعت السياق الحالي (فاتورة أو مجموعة).
 */
function ifl_ctx_ids(int $distId, int $groupId, string $itemCode = ''): array
{
    if ($groupId > 0) {
        // '__any__' كود مش موجود → الدالة بترجّع كل سجلات المجموعة
        // (نفس الحيلة المستخدمة في group_save_prices_bulk)
        return group_code_dist_ids($groupId, $itemCode !== '' ? $itemCode : '__any__');
    }
    return $distId > 0 ? [$distId] : [];
}

/**
 * حفظ ملاحظات المستخدم على الفاتورة.
 */
function inv_save_notes(int $distId, string $notes, int $uid): array
{
    if ($distId <= 0) {
        throw new RuntimeException('فاتورة غير صحيحة.');
    }
    save_invoice_notes($distId, $notes, $uid);
    return ['ok' => true];
}

/**
 * حفظ علامات الصنف: تخطي التصدير، لون التمييز، التوزيع الفعلي، الملاحظة.
 */
function inv_item_flags_save(
    int $did,
    int $gid,
    string $code,
    array $params,
    int $uid
): array {
    $code = trim($code);
    if ($code === '') {
        throw new RuntimeException('كود الصنف ناقص.');
    }

    // (أ) الفلاج العالمي: ينزل / ما ينزلش في ملف الفرع
    if (array_key_exists('skip_export', $params)) {
        ifl_set_skip($code, ((int) $params['skip_export']) === 1, $uid);
    }

    // (ب) الفلاجات المرتبطة بالفاتورة
    $fields = [];
    if (array_key_exists('highlight', $params))   $fields['highlight']   = (string) $params['highlight'];
    if (array_key_exists('done_actual', $params)) $fields['done_actual'] = (int) $params['done_actual'];
    if (array_key_exists('user_notes', $params))  $fields['user_notes']  = (string) $params['user_notes'];

    $n = 0;
    if ($fields) {
        $ids = ifl_ctx_ids($did, $gid, $code);
        if (!$ids) {
            throw new RuntimeException('مالقيتش سجل التوزيع بتاع الصنف ده.');
        }
        $n = ifl_save($ids, $code, $fields, $uid);
    }

    return ['invoices' => $n];
}

/**
 * مسح التمييز أو مسح علامات التوزيع الفعلي لفاتورة أو مجموعة.
 */
function inv_item_flags_clear(int $did, int $gid, string $what = 'highlight'): array
{
    $ids = ifl_ctx_ids($did, $gid);
    if (!$ids) {
        throw new RuntimeException('مفيش فاتورة أو مجموعة مفتوحة.');
    }

    $n = ($what === 'done') ? ifl_clear_done($ids) : ifl_clear_highlights($ids);
    return ['count' => $n];
}

/**
 * إضافة صنف لمشاكل الفواتير يدوياً.
 */
function inv_add_to_problems(
    PDO $pdo,
    int $did,
    string $code,
    string $note,
    int $uid,
    int $gid = 0,
    string $itemName = ''
): array {
    $code = trim($code);
    $note = trim($note);
    if ($code === '') {
        throw new RuntimeException('كود الصنف ناقص.');
    }
    if ($did <= 0 && $gid > 0) {
        $ids = ifl_ctx_ids($did, $gid, $code);
        $did = $ids[0] ?? 0;
    }
    if ($did <= 0) {
        throw new RuntimeException('معرّف التوزيع غير موجود.');
    }

    $itemName = trim($itemName);
    if ($itemName === '') {
        $stName = $pdo->prepare('SELECT name FROM hq_codes c JOIN hq_items i ON i.item_id = c.item_id WHERE c.code = ? LIMIT 1');
        $stName->execute([$code]);
        $itemName = (string) $stName->fetchColumn();
    }

    $ins = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, note, created_by)
         VALUES (?, ?, ?, 'manual', ?, ?)
         ON DUPLICATE KEY UPDATE
             note = VALUES(note),
             item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name),
             updated_at = NOW()"
    );
    $ins->execute([$did, $code, $itemName ?: null, $note, $uid]);

    return ['ok' => true];
}

/**
 * تفكيك واختيار الفواتير من المدخلات (مصفوفة أو JSON أو سلاسل مفصولة بـ pipe).
 */
function inv_parse_invoice_picks($raw): array
{
    if (is_array($raw)) {
        $arr = $raw;
    } else {
        $str = trim((string) $raw);
        if ($str === '') return [];
        $arr = json_decode($str, true);
        if (!is_array($arr)) {
            throw new RuntimeException('بيانات الفواتير غير صحيحة (JSON غير صالح).');
        }
    }

    if (count($arr) > 2000) $arr = array_slice($arr, 0, 2000);

    $out = [];
    foreach ($arr as $p) {
        if (is_string($p)) {
            $parts = explode('|', $p, 2);
            $sth = (int) $parts[0];
            $suf = trim((string) ($parts[1] ?? ''));
        } elseif (is_array($p)) {
            $sth = (int) ($p['sth_id'] ?? ($p['s'] ?? 0));
            $suf = trim((string) ($p['suffix'] ?? ($p['f'] ?? '')));
        } else {
            continue;
        }
        if ($sth <= 0) continue;
        $out[$sth . '|' . $suf] = ['sth_id' => $sth, 'suffix' => $suf];
    }
    return array_values($out);
}

/**
 * تفكيك الأصناف المختارة لبوب أب «تحديد أصناف» ومجموعات الأصناف.
 */
function inv_parse_item_picks($raw): array
{
    if (is_array($raw)) {
        $arr = $raw;
    } else {
        $str = trim((string) $raw);
        if ($str === '') return [];
        $arr = json_decode($str, true);
        if (!is_array($arr)) {
            throw new RuntimeException('بيانات الأصناف غير صحيحة (JSON غير صالح).');
        }
    }

    if (count($arr) > 2000) $arr = array_slice($arr, 0, 2000);

    $out = [];
    foreach ($arr as $p) {
        if (!is_array($p)) continue;
        $sth  = (int) ($p['s'] ?? ($p['sth_id'] ?? 0));
        $code = trim((string) ($p['c'] ?? ($p['item_code'] ?? '')));
        if ($sth <= 0 || $code === '') continue;
        $suf  = trim((string) ($p['f'] ?? ($p['suffix'] ?? '')));
        $out[$sth . '|' . $suf . '|' . $code] = [
            'sth_id' => $sth, 'suffix' => $suf, 'item_code' => $code,
        ];
    }
    return array_values($out);
}

/**
 * إنشاء مجموعة فواتير كاملة جديدة.
 */
function inv_group_create($rawInvoices, int $uid, string $name = ''): array
{
    $picks = inv_parse_invoice_picks($rawInvoices);
    $gid   = group_create($picks, $uid, trim($name), 'full');
    return ['group_id' => $gid];
}

/**
 * إنشاء مجموعة أصناف محددة جديدة.
 */
function inv_group_create_items($rawItems, int $uid, string $name = ''): array
{
    $picks = inv_parse_item_picks($rawItems);
    $gid   = group_create_items($picks, $uid, trim($name));
    return ['group_id' => $gid];
}

/**
 * إضافة فواتير كاملة لمجموعة موجودة.
 */
function inv_group_add_invoices(int $gid, $rawInvoices, int $uid): array
{
    if ($gid <= 0) {
        throw new RuntimeException('مجموعة غير صحيحة.');
    }
    $picks = inv_parse_invoice_picks($rawInvoices);
    if (!$picks) {
        throw new RuntimeException('ماخترتش أي فاتورة.');
    }
    $n = group_add_invoices($gid, $picks, $uid);
    return ['added' => $n];
}

/**
 * إضافة أصناف محددة لمجموعة أصناف موجودة.
 */
function inv_group_add_items(int $gid, $rawItems, int $uid): array
{
    if ($gid <= 0) {
        throw new RuntimeException('مجموعة غير صحيحة.');
    }
    $picks = inv_parse_item_picks($rawItems);
    if (!$picks) {
        throw new RuntimeException('ماخترتش أي صنف.');
    }
    return group_add_items($gid, $picks, $uid);
}

/**
 * مسح مجموعة بالكامل.
 */
function inv_group_delete(int $gid): array
{
    if ($gid <= 0) {
        throw new RuntimeException('مجموعة غير صحيحة.');
    }
    group_delete($gid);
    return ['deleted' => true];
}

/**
 * شيل فاتورة واحدة من مجموعة.
 */
function inv_group_remove_invoice(int $gid, int $sthId, string $suffix = ''): array
{
    if ($gid <= 0 || $sthId <= 0) {
        throw new RuntimeException('بيانات ناقصة.');
    }
    $ok = group_remove_invoice($gid, $sthId, $suffix);
    return ['removed' => $ok];
}

/**
 * شيل صنف واحد من مجموعة أصناف.
 */
function inv_group_remove_item(int $gid, string $itemCode): array
{
    $itemCode = trim($itemCode);
    if ($gid <= 0 || $itemCode === '') {
        throw new RuntimeException('بيانات ناقصة.');
    }
    $n = group_remove_item($gid, $itemCode);
    return ['removed_count' => $n];
}


