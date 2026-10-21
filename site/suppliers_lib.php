<?php
/**
 * suppliers_lib.php — مكتبة كشوف حسابات الموردين المركزية (بريف ١٧٣ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * دوال قراءة ومحايدة تماماً:
 *   - suppliers_has_erp_col(): فحص عمود erp_supplier_id
 *   - suppliers_get_start_date(): تاريخ بداية كشوف الحسابات من الإعدادات
 *   - suppliers_parse_params(): استخلاص وتدقيق المعاملات من المدخلات
 *   - suppliers_get_active_list(): قائمة الموردين النشطين
 *   - suppliers_get_summary(): ملخص كل الموردين والأرصدة
 *   - suppliers_get_statement(): كشف الحساب التفصيلي لمورد محدد
 */
declare(strict_types=1);

require_once __DIR__ . '/recon_lib.php';

if (!defined('SUPP_INTERNAL_IDS')) {
    define('SUPP_INTERNAL_IDS', [2, 3, 4, 7, 10, 21, 24]);
}

if (!function_exists('suppliers_has_erp_col')) {
    function suppliers_has_erp_col(PDO $pdo): bool {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $pdo->query('SELECT erp_supplier_id FROM suppliers LIMIT 0');
            $has = true;
        } catch (Throwable) {
            $has = false;
        }
        return $has;
    }
}

/**
 * جلب تاريخ بداية كشوف الحسابات من الإعدادات (افتراضي: 2026-06-27).
 */
function suppliers_get_start_date(PDO $pdo): string {
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
 * تحليل وتدقيق معاملات البحث والفترة.
 *
 * @param array<string, mixed> $params
 * @return array{
 *     from: string,
 *     to: string,
 *     selected_supplier_id: int,
 *     curr_branch_key: string,
 *     curr_branch: ?array
 * }
 */
function suppliers_parse_params(array $params, string $stStart): array {
    $from = (isset($params['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['from'])) ? (string)$params['from'] : $stStart;
    $to   = (isset($params['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['to'])) ? (string)$params['to'] : date('Y-m-d');
    $selectedSupplierId = isset($params['supplier']) && (int)$params['supplier'] > 0 ? (int)$params['supplier'] : 0;

    $brParam = trim((string) ($params['br'] ?? ''));
    $currBranchKey = array_key_exists($brParam, SUPP_BRANCHES) ? $brParam : '';
    $currBranch = $currBranchKey !== '' ? SUPP_BRANCHES[$currBranchKey] : null;

    return [
        'from'                 => $from,
        'to'                   => $to,
        'selected_supplier_id' => $selectedSupplierId,
        'curr_branch_key'      => $currBranchKey,
        'curr_branch'          => $currBranch,
    ];
}

/**
 * جلب قائمة الموردين النشطين للقوائم المنسدلة.
 *
 * @return array<int, array{id: int|string, name: string}>
 */
function suppliers_get_active_list(PDO $pdo): array {
    return $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
}

/**
 * جلب ملخص كشوف حسابات الموردين في الفترة والفرع المحددين.
 *
 * @return array{
 *     statement_summary: array,
 *     totals: array{
 *         purchases: float,
 *         returns: float,
 *         paid: float,
 *         received: float,
 *         balance: float,
 *         count: int
 *     }
 * }
 */
function suppliers_get_summary(PDO $pdo, string $from, string $to, string $stStart, ?array $currBranch): array {
    $hasErp = suppliers_has_erp_col($pdo);

    // 1) فواتير الشراء والمردودات من hq_invoices
    $hqJoin = $hasErp
        ? "(s.erp_supplier_id IS NOT NULL AND h.supplier_id = s.erp_supplier_id) OR (s.erp_supplier_id IS NULL AND h.supplier_name = s.name)"
        : "h.supplier_name = s.name";

    $hqSql = "SELECT
        s.id AS supplier_id,
        SUM(CASE WHEN h.doc_type = 2 AND h.invoice_date >= ? THEN h.net_amount ELSE 0 END) AS purchases,
        SUM(CASE WHEN h.doc_type = 6 AND h.invoice_date >= ? THEN h.net_amount ELSE 0 END) AS returns,
        SUM(CASE WHEN h.doc_type = 2 THEN h.net_amount ELSE 0 END) AS tot_purchases,
        SUM(CASE WHEN h.doc_type = 6 THEN h.net_amount ELSE 0 END) AS tot_returns
     FROM hq_invoices h
     JOIN suppliers s ON ($hqJoin)
     WHERE h.invoice_date >= ? AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
       AND h.doc_type IN (2, 6)";

    $hqParams = [$from, $from, $stStart, $to];
    if ($currBranch !== null) {
        $hqSql .= " AND h.company_branch = ?";
        $hqParams[] = $currBranch['cb'];
    }
    $hqSql .= " GROUP BY s.id";

    $hqSummary = $pdo->prepare($hqSql);
    $hqSummary->execute($hqParams);
    $hqRows = $hqSummary->fetchAll();
    $hqMap = [];
    foreach ($hqRows as $r) {
        $hqMap[(int)$r['supplier_id']] = $r;
    }

    // 2) اليومية والخزينة على حساب المورد
    $jeSelectParams = [$from, $from];
    $jeWhere = ["je.entry_date >= ? AND je.entry_date < DATE_ADD(?, INTERVAL 1 DAY)"];
    $jeWhereParams = [$stStart, $to];
    $jeJoin = "";

    if ($currBranch !== null) {
        $jeJoin = " LEFT JOIN treasuries t ON t.id = je.treasury_id";
        $jeWhere[] = "t.branch_id = ?";
        $jeWhereParams[] = $currBranch['bid'];
    }

    $jeSql = "SELECT
        a.src_id AS supplier_id,
        SUM(CASE WHEN je.entry_date >= ? THEN je.debit ELSE 0 END) AS paid,
        SUM(CASE WHEN je.entry_date >= ? THEN je.credit ELSE 0 END) AS received,
        SUM(je.debit) AS tot_paid,
        SUM(je.credit) AS tot_received
     FROM journal_entries je
     JOIN accounts a ON a.id = je.account_id AND a.src_table = 'suppliers'"
     . $jeJoin
     . " WHERE " . implode(' AND ', $jeWhere)
     . " GROUP BY a.src_id";

    $jeSummary = $pdo->prepare($jeSql);
    $jeSummary->execute(array_merge($jeSelectParams, $jeWhereParams));
    $jeRows = $jeSummary->fetchAll();
    $jeMap = [];
    foreach ($jeRows as $r) {
        $jeMap[(int)$r['supplier_id']] = $r;
    }

    // 3) الموردين النشطين (باستثناء الموردين الداخليين)
    $excludedSuppIds = SUPP_INTERNAL_IDS;
    $inPlaceholders = implode(',', array_fill(0, count($excludedSuppIds), '?'));
    $stActive = $pdo->prepare(
        "SELECT id, name, phone FROM suppliers WHERE is_active = 1 AND id NOT IN ($inPlaceholders) ORDER BY name"
    );
    $stActive->execute($excludedSuppIds);
    $activeSuppliers = $stActive->fetchAll();

    $statementSummary = [];
    $totSumPurchases  = 0.0;
    $totSumReturns    = 0.0;
    $totSumPaid       = 0.0;
    $totSumReceived   = 0.0;
    $totSumBalance    = 0.0;

    foreach ($activeSuppliers as $s) {
        $sid = (int) $s['id'];
        $p   = (float) ($hqMap[$sid]['purchases'] ?? 0);
        $ret = (float) ($hqMap[$sid]['returns'] ?? 0);
        $pd  = (float) ($jeMap[$sid]['paid'] ?? 0);
        $rc  = (float) ($jeMap[$sid]['received'] ?? 0);

        $totP   = (float) ($hqMap[$sid]['tot_purchases'] ?? 0);
        $totRet = (float) ($hqMap[$sid]['tot_returns'] ?? 0);
        $totPd  = (float) ($jeMap[$sid]['tot_paid'] ?? 0);
        $totRc  = (float) ($jeMap[$sid]['tot_received'] ?? 0);
        $bal    = $totP - $totRet - $totPd + $totRc;

        // مورد كل أرقامه صفر يختفي
        if (abs($p) < 0.005 && abs($ret) < 0.005 && abs($pd) < 0.005 && abs($rc) < 0.005 && abs($bal) < 0.005) {
            continue;
        }

        $totSumPurchases += $p;
        $totSumReturns   += $ret;
        $totSumPaid      += $pd;
        $totSumReceived  += $rc;
        $totSumBalance   += $bal;

        $statementSummary[] = [
            'id'        => $sid,
            'name'      => $s['name'],
            'phone'     => $s['phone'] ?? '',
            'purchases' => $p,
            'returns'   => $ret,
            'paid'      => $pd,
            'received'  => $rc,
            'balance'   => $bal,
        ];
    }

    usort($statementSummary, fn($a, $b) => $b['balance'] <=> $a['balance']);

    return [
        'statement_summary' => $statementSummary,
        'totals'            => [
            'purchases' => $totSumPurchases,
            'returns'   => $totSumReturns,
            'paid'      => $totSumPaid,
            'received'  => $totSumReceived,
            'balance'   => $totSumBalance,
            'count'     => count($statementSummary),
        ],
    ];
}

/**
 * جلب كشف حساب تفصيلي لمورد واحد.
 *
 * @return ?array{
 *     supplier: array,
 *     prior: array{
 *         balance: float,
 *         purchases: float,
 *         returns: float,
 *         paid: float,
 *         received: float
 *     },
 *     moves: array,
 *     period: array{
 *         purchases: float,
 *         returns: float,
 *         paid: float,
 *         received: float,
 *         final_balance: float
 *     }
 * }
 */
function suppliers_get_statement(PDO $pdo, int $supplierId, string $from, string $to, string $stStart, ?array $currBranch): ?array {
    $hasErp = suppliers_has_erp_col($pdo);

    $stSupp = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stSupp->execute([$supplierId]);
    $singleSupplier = $stSupp->fetch();

    if (!$singleSupplier) {
        return null;
    }

    // حساب المورد في الشجرة
    $stAcc = $pdo->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
    $stAcc->execute([$supplierId]);
    $suppAccId = (int) $stAcc->fetchColumn();

    $priorPurchases = 0.0;
    $priorReturns   = 0.0;
    $priorPaid      = 0.0;
    $priorReceived  = 0.0;
    $priorBalance   = 0.0;

    // رصيد ما قبل الفترة
    if ($from > $stStart) {
        $priorHqSql = "SELECT
            SUM(CASE WHEN doc_type = 2 THEN net_amount ELSE 0 END) AS purchases,
            SUM(CASE WHEN doc_type = 6 THEN net_amount ELSE 0 END) AS returns
         FROM hq_invoices
         WHERE invoice_date >= ? AND invoice_date < ?
           AND doc_type IN (2, 6)
           AND " . ($hasErp && !empty($singleSupplier['erp_supplier_id'])
               ? "(supplier_id = ? OR (supplier_id IS NULL AND supplier_name = ?))"
               : "supplier_name = ?");

        $priorHqParams = [$stStart, $from];
        if ($hasErp && !empty($singleSupplier['erp_supplier_id'])) {
            $priorHqParams[] = $singleSupplier['erp_supplier_id'];
            $priorHqParams[] = $singleSupplier['name'];
        } else {
            $priorHqParams[] = $singleSupplier['name'];
        }

        if ($currBranch !== null) {
            $priorHqSql .= " AND company_branch = ?";
            $priorHqParams[] = $currBranch['cb'];
        }

        $stPriorHq = $pdo->prepare($priorHqSql);
        $stPriorHq->execute($priorHqParams);
        $priorHq = $stPriorHq->fetch();
        $priorPurchases = (float) ($priorHq['purchases'] ?? 0);
        $priorReturns   = (float) ($priorHq['returns'] ?? 0);

        if ($suppAccId > 0) {
            $priorJeSql = "SELECT
                SUM(je.debit) AS paid,
                SUM(je.credit) AS received
             FROM journal_entries je";
            $priorJeWhere = ["je.account_id = ?", "je.entry_date >= ?", "je.entry_date < ?"];
            $priorJeParams = [$suppAccId, $stStart, $from];

            if ($currBranch !== null) {
                $priorJeSql .= " LEFT JOIN treasuries t ON t.id = je.treasury_id";
                $priorJeWhere[] = "t.branch_id = ?";
                $priorJeParams[] = $currBranch['bid'];
            }
            $priorJeSql .= " WHERE " . implode(' AND ', $priorJeWhere);

            $stPriorJe = $pdo->prepare($priorJeSql);
            $stPriorJe->execute($priorJeParams);
            $priorJe = $stPriorJe->fetch();
            $priorPaid     = (float) ($priorJe['paid'] ?? 0);
            $priorReceived = (float) ($priorJe['received'] ?? 0);
        }
        $priorBalance = $priorPurchases - $priorReturns - $priorPaid + $priorReceived;
    }

    // حركات الفترة [from, to] — مع إصلاح DATETIME بـ DATE_ADD
    $periodHqSql = "SELECT sth_id, DATE(invoice_date) AS tx_date, doc_type, doc_no, net_amount
     FROM hq_invoices
     WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
       AND doc_type IN (2, 6)
       AND " . ($hasErp && !empty($singleSupplier['erp_supplier_id'])
           ? "(supplier_id = ? OR (supplier_id IS NULL AND supplier_name = ?))"
           : "supplier_name = ?");

    $periodHqParams = [$from, $to];
    if ($hasErp && !empty($singleSupplier['erp_supplier_id'])) {
        $periodHqParams[] = $singleSupplier['erp_supplier_id'];
        $periodHqParams[] = $singleSupplier['name'];
    } else {
        $periodHqParams[] = $singleSupplier['name'];
    }

    if ($currBranch !== null) {
        $periodHqSql .= " AND company_branch = ?";
        $periodHqParams[] = $currBranch['cb'];
    }
    $periodHqSql .= " ORDER BY invoice_date ASC, sth_id ASC";

    $stHq = $pdo->prepare($periodHqSql);
    $stHq->execute($periodHqParams);
    $periodHqRows = $stHq->fetchAll();

    $periodJeRows = [];
    if ($suppAccId > 0) {
        $periodJeSql = "SELECT je.id, je.entry_date AS tx_date, je.description, je.debit, je.credit,
                t.name AS treasury_name, je.notes
         FROM journal_entries je
         LEFT JOIN treasuries t ON t.id = je.treasury_id";

        $periodJeWhere = [
            "je.account_id = ?",
            "je.entry_date >= ?",
            "je.entry_date < DATE_ADD(?, INTERVAL 1 DAY)"
        ];
        $periodJeParams = [$suppAccId, $from, $to];

        if ($currBranch !== null) {
            $periodJeWhere[] = "t.branch_id = ?";
            $periodJeParams[] = $currBranch['bid'];
        }
        $periodJeSql .= " WHERE " . implode(' AND ', $periodJeWhere) . " ORDER BY je.entry_date ASC, je.id ASC";

        $stJe = $pdo->prepare($periodJeSql);
        $stJe->execute($periodJeParams);
        $periodJeRows = $stJe->fetchAll();
    }

    $allMoves = [];
    foreach ($periodHqRows as $h) {
        $amt = (float) $h['net_amount'];
        $isPurchase = ((int)$h['doc_type'] === 2);
        $allMoves[] = [
            'source'        => 'hq',
            'id'            => (int) $h['sth_id'],
            'tx_date'       => $h['tx_date'],
            'description'   => ($isPurchase ? 'فاتورة شراء ' : 'مردود ') . $h['doc_no'],
            'treasury_name' => '—',
            'credit'        => $isPurchase ? $amt : 0.0,
            'debit'         => $isPurchase ? 0.0 : $amt,
            'notes'         => '',
            'sort_order'    => 1,
        ];
    }
    foreach ($periodJeRows as $j) {
        $dr = (float) $j['debit'];
        $cr = (float) $j['credit'];
        $allMoves[] = [
            'source'        => 'je',
            'id'            => (int) $j['id'],
            'tx_date'       => $j['tx_date'],
            'description'   => (string) $j['description'],
            'treasury_name' => (string) ($j['treasury_name'] ?? '—'),
            'credit'        => $cr,
            'debit'         => $dr,
            'notes'         => (string) ($j['notes'] ?? ''),
            'sort_order'    => 2,
        ];
    }

    usort($allMoves, function ($a, $b) {
        $cmp = strcmp($a['tx_date'], $b['tx_date']);
        if ($cmp !== 0) return $cmp;
        $s = ($a['sort_order'] <=> $b['sort_order']);
        if ($s !== 0) return $s;
        return $a['id'] <=> $b['id'];
    });

    $periodPurchases = 0.0;
    $periodReturns   = 0.0;
    $periodPaid      = 0.0;
    $periodReceived  = 0.0;
    $runningBal = $priorBalance;

    foreach ($allMoves as &$m) {
        if ($m['source'] === 'hq') {
            if ($m['credit'] > 0) $periodPurchases += $m['credit'];
            if ($m['debit'] > 0)  $periodReturns   += $m['debit'];
        } else {
            if ($m['debit'] > 0)  $periodPaid      += $m['debit'];
            if ($m['credit'] > 0) $periodReceived  += $m['credit'];
        }
        $runningBal += ($m['credit'] - $m['debit']);
        $m['balance'] = $runningBal;
    }
    unset($m);
    $finalBalance = $runningBal;

    return [
        'supplier' => $singleSupplier,
        'prior'    => [
            'balance'   => $priorBalance,
            'purchases' => $priorPurchases,
            'returns'   => $priorReturns,
            'paid'      => $priorPaid,
            'received'  => $priorReceived,
        ],
        'moves'    => $allMoves,
        'period'   => [
            'purchases'     => $periodPurchases,
            'returns'       => $periodReturns,
            'paid'          => $periodPaid,
            'received'      => $periodReceived,
            'final_balance' => $finalBalance,
        ],
    ];
}
