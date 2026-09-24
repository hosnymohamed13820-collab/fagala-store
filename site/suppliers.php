<?php
/**
 * suppliers.php — الموردون
 * نظام الفجالة ستور المالي
 *
 * - كشوف حسابات الموردين (ملخص وتفصيلي)
 */
require_once __DIR__ . '/auth.php';
require_permission('suppliers');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = 'statement';

// نقرأ رسالة الفلاش من السيشن (بعد التحويل إن وجدت)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== كشوف حسابات الموردين (تاب statement) =====
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

// موردين في السيستم بس مش موردين حقيقيين: فروع + رصيد افتتاحي + مستهلكات (اتأكدنا من الأرقام على السيرفر ٢٤ سبتمبر)
const SUPP_INTERNAL_IDS = [2, 3, 4, 7, 10, 21, 24]; // رصيد افتتاحي · مستهلكات · الرئيسية (الادارة) · فرع الفجالة · فرع ABC · فرع دجلة · فرع بونجور

// cb = hq_invoices.company_branch (ترقيم الشركة) · bid = branches.id عندنا — مش نفس الترقيم
const SUPP_BRANCHES = [
    'hq'      => ['label' => 'الإدارة',   'cb' => 1, 'bid' => 6],
    'fagala'  => ['label' => 'الفجالة',   'cb' => 2, 'bid' => 3],
    'bonjour' => ['label' => 'بونجور',    'cb' => 3, 'bid' => 1],
    'degla'   => ['label' => 'دجلة',      'cb' => 5, 'bid' => 4],
    'abc'     => ['label' => 'اي بي سي',  'cb' => 6, 'bid' => 2],
];

if (isset($_GET['supplier']) && !isset($_GET['tab'])) {
    $tab = 'statement';
}

$statementSummary   = [];
$totSumPurchases    = 0.0;
$totSumReturns      = 0.0;
$totSumPaid         = 0.0;
$totSumReceived     = 0.0;
$totSumBalance      = 0.0;
$singleSupplier     = null;
$allMoves           = [];
$priorBalance       = 0.0;
$priorPurchases     = 0.0;
$priorReturns       = 0.0;
$priorPaid          = 0.0;
$priorReceived      = 0.0;
$periodPurchases    = 0.0;
$periodReturns      = 0.0;
$periodPaid         = 0.0;
$periodReceived     = 0.0;
$finalBalance       = 0.0;
$stStart            = '2026-06-27';
$selectedSupplierId = isset($_GET['supplier']) && (int)$_GET['supplier'] > 0 ? (int)$_GET['supplier'] : 0;

$brParam = trim((string) ($_GET['br'] ?? ''));
$currBranchKey = array_key_exists($brParam, SUPP_BRANCHES) ? $brParam : '';
$currBranch = $currBranchKey !== '' ? SUPP_BRANCHES[$currBranchKey] : null;

if ($tab === 'statement') {
    try {
        $stSet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'supplier_statement_start' LIMIT 1");
        $stSet->execute();
        $v = $stSet->fetchColumn();
        if ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
            $stStart = (string)$v;
        }
    } catch (Throwable) {}

    $from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $stStart;
    $to   = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) ? $_GET['to'] : date('Y-m-d');

    $hasErp = suppliers_has_erp_col($pdo);

    if ($selectedSupplierId <= 0) {
        // (أ) الملخص: ٣ استعلامات مجمعة
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
        if ($currBranchKey === 'hq') {
            $hqSql .= " AND (h.company_branch = 1 OR h.company_branch IS NULL)";
        } elseif ($currBranch !== null) {
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

        if ($currBranchKey === 'hq') {
            $jeJoin = " LEFT JOIN treasuries t ON t.id = je.treasury_id";
            $jeWhere[] = "(je.treasury_id IS NULL OR t.branch_id IS NULL OR t.branch_id = 6)";
        } elseif ($currBranch !== null) {
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
        $inPlaceholders = implode(',', array_fill(0, count(SUPP_INTERNAL_IDS), '?'));
        $stActive = $pdo->prepare(
            "SELECT id, name, phone FROM suppliers WHERE is_active = 1 AND id NOT IN ($inPlaceholders) ORDER BY name"
        );
        $stActive->execute(SUPP_INTERNAL_IDS);
        $activeSuppliers = $stActive->fetchAll();

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

        // تصدير إكسيل للملخص
        if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
            require_once __DIR__ . '/xlsx_lib.php';
            $x = new SimpleXlsx('ملخص الموردين');
            $headerTitle = 'كشوف حسابات الموردين' . ($currBranch ? ' — فرع ' . $currBranch['label'] : '') . ' — من ' . $from . ' حتى ' . $to;
            $x->addRow([$headerTitle]);
            $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
            $x->addBlank();
            $x->addRow(['المورد', 'التليفون', 'المشتريات', 'المردودات', 'المصروف له', 'المستلم منه', 'الرصيد'], true);
            foreach ($statementSummary as $row) {
                $x->addRow([
                    $row['name'],
                    (string)$row['phone'],
                    round($row['purchases'], 2),
                    round($row['returns'], 2),
                    round($row['paid'], 2),
                    round($row['received'], 2),
                    round($row['balance'], 2),
                ]);
            }
            $x->addRow(['الإجمالي', '', round($totSumPurchases, 2), round($totSumReturns, 2), round($totSumPaid, 2), round($totSumReceived, 2), round($totSumBalance, 2)], true);
            $brSuffix = $currBranchKey ? '_' . strtoupper($currBranchKey) : '';
            $x->download('SUPPLIERS_SUMMARY' . $brSuffix . '_' . $from . '_' . $to . '.xlsx');
            exit;
        }
    } else {
        // (ب) كشف مورد واحد
        $stSupp = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stSupp->execute([$selectedSupplierId]);
        $singleSupplier = $stSupp->fetch();

        if ($singleSupplier) {
            // حساب المورد في الشجرة
            $stAcc = $pdo->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
            $stAcc->execute([$selectedSupplierId]);
            $suppAccId = (int) $stAcc->fetchColumn();

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

                if ($currBranchKey === 'hq') {
                    $priorHqSql .= " AND (company_branch = 1 OR company_branch IS NULL)";
                } elseif ($currBranch !== null) {
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

                    if ($currBranchKey === 'hq') {
                        $priorJeSql .= " LEFT JOIN treasuries t ON t.id = je.treasury_id";
                        $priorJeWhere[] = "(je.treasury_id IS NULL OR t.branch_id IS NULL OR t.branch_id = 6)";
                    } elseif ($currBranch !== null) {
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

            if ($currBranchKey === 'hq') {
                $periodHqSql .= " AND (company_branch = 1 OR company_branch IS NULL)";
            } elseif ($currBranch !== null) {
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

                if ($currBranchKey === 'hq') {
                    $periodJeWhere[] = "(je.treasury_id IS NULL OR t.branch_id IS NULL OR t.branch_id = 6)";
                } elseif ($currBranch !== null) {
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

            // تصدير إكسيل لكشف مورد واحد
            if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
                require_once __DIR__ . '/xlsx_lib.php';
                $branchLabel = $currBranch ? ' (' . $currBranch['label'] . ')' : '';
                $sheetTitle = mb_substr('كشف ' . $singleSupplier['name'] . $branchLabel, 0, 31);
                $x = new SimpleXlsx($sheetTitle);
                $x->addRow(['كشف حساب مورد: ' . $singleSupplier['name'] . ($currBranch ? ' — فرع ' . $currBranch['label'] : '')]);
                $x->addRow(['الفترة', 'من ' . $from . ' إلى ' . $to]);
                $x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
                $x->addBlank();
                $x->addRow(['التاريخ', 'البيان', 'الخزينة', 'له', 'عليه', 'الرصيد'], true);
                if ($from > $stStart) {
                    $x->addRow([$from, 'رصيد ما قبل الفترة', '—', '', '', round($priorBalance, 2)]);
                }
                foreach ($allMoves as $m) {
                    $x->addRow([
                        $m['tx_date'],
                        $m['description'] . (!empty($m['notes']) ? (' (' . $m['notes'] . ')') : ''),
                        $m['treasury_name'],
                        $m['credit'] > 0 ? round($m['credit'], 2) : '',
                        $m['debit'] > 0 ? round($m['debit'], 2) : '',
                        round($m['balance'], 2),
                    ]);
                }
                $x->addBlank();
                $x->addRow(['المشتريات', round($periodPurchases, 2)]);
                $x->addRow(['المردودات', round($periodReturns, 2)]);
                $x->addRow(['المصروف له', round($periodPaid, 2)]);
                $x->addRow(['المستلم منه', round($periodReceived, 2)]);
                $x->addRow(['الرصيد الختامي', round($finalBalance, 2)], true);

                $safeName = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $singleSupplier['name']);
                $brSuffix = $currBranchKey ? '_' . strtoupper($currBranchKey) : '';
                $x->download('SUPPLIER_' . ($safeName ?: $selectedSupplierId) . $brSuffix . '_' . $from . '_' . $to . '.xlsx');
                exit;
            }
        }
    }
}

$page_title    = 'الموردون';
$active_module = 'suppliers';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab { padding:10px 20px; border-radius:10px; text-decoration:none; color:var(--accent-text); background:var(--surface);
        font-size:14px; font-weight:500; }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; font-size:14px; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:460px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .pay-type-row { display:flex; gap:10px; }
    .pay-opt { flex:1; }
    .pay-opt input { display:none; }
    .pay-opt label { display:block; text-align:center; padding:11px; border:1.5px solid var(--line);
        border-radius:9px; cursor:pointer; font-size:14px; color:var(--ink-700); }
    .pay-opt input:checked + label { border-color:var(--accent-text); background:var(--bg); color:var(--accent-text); font-weight:600; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-top:20px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line); border-radius:12px; padding:14px 16px; }
    .sum-card .label { font-size:12px; color:var(--ink-500); margin-bottom:4px; font-weight:500; }
    .sum-card .val { font-size:18px; font-weight:700; color:var(--ink-900); }
    .filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filter-form label { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-700); font-weight:500; }
    .filter-form input[type="date"] { padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:var(--surface); color:var(--ink-900); }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

    <?php if ($selectedSupplierId <= 0 || !$singleSupplier): ?>
        <!-- تابات الفروع -->
        <div class="tabs">
            <a href="?tab=statement&br=&from=<?= e($from) ?>&to=<?= e($to) ?>" class="tab <?= $currBranchKey === '' ? 'active' : '' ?>">الكل</a>
            <?php foreach (SUPP_BRANCHES as $k => $b): ?>
                <a href="?tab=statement&br=<?= e($k) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="tab <?= $currBranchKey === $k ? 'active' : '' ?>"><?= e($b['label']) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- (أ) الملخص: كل الموردين -->
        <div class="toolbar" style="align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
            <div>
                <span class="section-title" style="margin-bottom:0;">كشوف حسابات الموردين<?= $currBranch ? ' — فرع ' . e($currBranch['label']) : '' ?></span>
                <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                    الفترة من <?= e($from) ?> حتى <?= e($to) ?> (رصيد ما بعد جرد ٢٧ يونيو)
                </div>
            </div>
            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="tab" value="statement">
                <input type="hidden" name="br" value="<?= e($currBranchKey) ?>">
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>" onchange="this.form.submit()"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
                <a href="?tab=statement&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>

        <div class="sum-cards" style="margin-top:0;margin-bottom:18px;">
            <div class="sum-card">
                <div class="label">عدد الموردين</div>
                <div class="val" id="cardSuppCount"><?= count($statementSummary) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المشتريات</div>
                <div class="val" id="cardTotPurchases"><?= number_format($totSumPurchases, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المردودات</div>
                <div class="val" id="cardTotReturns"><?= number_format($totSumReturns, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المصروف للموردين</div>
                <div class="val" id="cardTotPaid"><?= number_format($totSumPaid, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المستلم من الموردين</div>
                <div class="val" id="cardTotReceived"><?= number_format($totSumReceived, 2) ?></div>
            </div>
            <div class="sum-card" id="cardBalanceBox" style="background:<?= $totSumBalance > 0 ? 'var(--neg-soft)' : ($totSumBalance < 0 ? 'var(--pos-soft)' : 'var(--line-soft)') ?>;border-color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--line)') ?>;">
                <div class="label">إجمالي الرصيد القائم</div>
                <div class="val" id="cardTotBalance" style="color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--ink-900)') ?>;">
                    <?= number_format($totSumBalance, 2) ?>
                </div>
                <small id="cardBalanceHint" style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?= $totSumBalance > 0 ? 'احنا مديونين للموردين' : ($totSumBalance < 0 ? 'الموردين مدينين لنا' : 'الأرصدة متوازنة') ?>
                </small>
            </div>
        </div>

        <div class="card">
            <?php if (empty($statementSummary)): ?>
                <div class="empty">لا يوجد موردون بحركات أو أرصدة في هذه الفترة.</div>
            <?php else: ?>
            <div style="margin-bottom:14px;max-width:320px;">
                <input type="search" id="suppSearch" placeholder="بحث باسم المورد..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-size:14px;font-family:inherit;background:var(--surface);color:var(--ink-900);">
            </div>
            <div style="overflow-x:auto;">
            <table id="suppTable">
                <thead>
                    <tr>
                        <th>المورد</th>
                        <th>المشتريات</th>
                        <th>المردودات</th>
                        <th>المصروف له</th>
                        <th>المستلم منه</th>
                        <th>الرصيد</th>
                    </tr>
                </thead>
                <tbody id="suppTbody">
                <?php foreach ($statementSummary as $s): ?>
                    <tr data-name="<?= e(mb_strtolower($s['name'])) ?>"
                        data-p="<?= $s['purchases'] ?>"
                        data-r="<?= $s['returns'] ?>"
                        data-pd="<?= $s['paid'] ?>"
                        data-rc="<?= $s['received'] ?>"
                        data-b="<?= $s['balance'] ?>">
                        <td style="font-weight:600;">
                            <a href="?tab=statement&supplier=<?= (int)$s['id'] ?>&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="inv-link">
                                <?= e($s['name']) ?>
                            </a>
                        </td>
                        <td><?= $s['purchases'] > 0 ? number_format($s['purchases'], 2) : '—' ?></td>
                        <td><?= $s['returns'] > 0 ? number_format($s['returns'], 2) : '—' ?></td>
                        <td><?= $s['paid'] > 0 ? number_format($s['paid'], 2) : '—' ?></td>
                        <td><?= $s['received'] > 0 ? number_format($s['received'], 2) : '—' ?></td>
                        <td style="font-weight:700;color:<?= $s['balance'] > 0 ? 'var(--neg)' : ($s['balance'] < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($s['balance'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="noSuppMatch" style="display:none;"><td colspan="6" class="empty">لا توجد نتائج مطابقة للبحث.</td></tr>
                </tbody>
                <tfoot id="suppTfoot">
                    <tr style="font-weight:700;border-top:2px solid var(--line);">
                        <td>الإجمالي</td>
                        <td id="footP"><?= number_format($totSumPurchases, 2) ?></td>
                        <td id="footR"><?= number_format($totSumReturns, 2) ?></td>
                        <td id="footPd"><?= number_format($totSumPaid, 2) ?></td>
                        <td id="footRc"><?= number_format($totSumReceived, 2) ?></td>
                        <td id="footB" style="color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($totSumBalance, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var searchInput = document.getElementById('suppSearch');
            if (!searchInput) return;

            var tbody = document.getElementById('suppTbody');
            if (!tbody) return;
            var rows = Array.from(tbody.querySelectorAll('tr[data-name]'));
            var noMatch = document.getElementById('noSuppMatch');

            var cardCount = document.getElementById('cardSuppCount');
            var cardP = document.getElementById('cardTotPurchases');
            var cardR = document.getElementById('cardTotReturns');
            var cardPd = document.getElementById('cardTotPaid');
            var cardRc = document.getElementById('cardTotReceived');
            var cardB = document.getElementById('cardTotBalance');
            var cardBBox = document.getElementById('cardBalanceBox');
            var cardBHint = document.getElementById('cardBalanceHint');

            var footP = document.getElementById('footP');
            var footR = document.getElementById('footR');
            var footPd = document.getElementById('footPd');
            var footRc = document.getElementById('footRc');
            var footB = document.getElementById('footB');

            function fmt(n) {
                return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function recalculate() {
                var query = searchInput.value.trim().toLowerCase();
                var visibleCount = 0;
                var sumP = 0, sumR = 0, sumPd = 0, sumRc = 0, sumB = 0;

                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    var name = r.getAttribute('data-name') || '';
                    var matches = !query || name.indexOf(query) !== -1;
                    if (matches) {
                        r.style.display = '';
                        visibleCount++;
                        sumP += parseFloat(r.getAttribute('data-p') || 0);
                        sumR += parseFloat(r.getAttribute('data-r') || 0);
                        sumPd += parseFloat(r.getAttribute('data-pd') || 0);
                        sumRc += parseFloat(r.getAttribute('data-rc') || 0);
                        sumB += parseFloat(r.getAttribute('data-b') || 0);
                    } else {
                        r.style.display = 'none';
                    }
                }

                if (noMatch) {
                    noMatch.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
                }

                if (cardCount) cardCount.textContent = visibleCount.toLocaleString('en-US');
                if (cardP) cardP.textContent = fmt(sumP);
                if (cardR) cardR.textContent = fmt(sumR);
                if (cardPd) cardPd.textContent = fmt(sumPd);
                if (cardRc) cardRc.textContent = fmt(sumRc);
                if (cardB) cardB.textContent = fmt(sumB);

                if (footP) footP.textContent = fmt(sumP);
                if (footR) footR.textContent = fmt(sumR);
                if (footPd) footPd.textContent = fmt(sumPd);
                if (footRc) footRc.textContent = fmt(sumRc);
                if (footB) {
                    footB.textContent = fmt(sumB);
                    footB.style.color = sumB > 0.005 ? 'var(--neg)' : (sumB < -0.005 ? 'var(--pos)' : 'var(--ink-400)');
                }

                if (cardBBox && cardB && cardBHint) {
                    if (sumB > 0.005) {
                        cardBBox.style.background = 'var(--neg-soft)';
                        cardBBox.style.borderColor = 'var(--neg)';
                        cardB.style.color = 'var(--neg)';
                        cardBHint.textContent = 'احنا مديونين للموردين';
                    } else if (sumB < -0.005) {
                        cardBBox.style.background = 'var(--pos-soft)';
                        cardBBox.style.borderColor = 'var(--pos)';
                        cardB.style.color = 'var(--pos)';
                        cardBHint.textContent = 'الموردين مدينين لنا';
                    } else {
                        cardBBox.style.background = 'var(--line-soft)';
                        cardBBox.style.borderColor = 'var(--line)';
                        cardB.style.color = 'var(--ink-900)';
                        cardBHint.textContent = 'الأرصدة متوازنة';
                    }
                }
            }

            searchInput.addEventListener('input', recalculate);
        })();
        </script>

    <?php else: ?>
        <!-- (ب) كشف حساب مورد واحد -->
        <div class="toolbar" style="align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div>
                <a href="?tab=statement&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-ghost" style="margin-bottom:8px;">
                    <i class="ti ti-arrow-right"></i> كل الموردين
                </a>
                <div class="section-title" style="margin-bottom:2px;font-size:18px;">
                    <?= e($singleSupplier['name']) ?><?= $currBranch ? ' — <span style="font-size:14px;color:var(--accent-text);font-weight:500;">فرع: ' . e($currBranch['label']) . '</span>' : '' ?>
                </div>
                <div style="font-size:12px;color:var(--ink-500);">
                    <?= !empty($singleSupplier['phone']) ? ('تليفون: ' . e($singleSupplier['phone'])) : '' ?>
                </div>
            </div>

            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="tab" value="statement">
                <input type="hidden" name="supplier" value="<?= $selectedSupplierId ?>">
                <input type="hidden" name="br" value="<?= e($currBranchKey) ?>">
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>"></label>
                <button type="submit" class="btn btn-sm">تطبيق</button>
                <a href="?tab=statement&supplier=<?= $selectedSupplierId ?>&br=<?= e($currBranchKey) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
                    <i class="ti ti-download"></i> تصدير إكسيل
                </a>
            </form>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>البيان</th>
                        <th>الخزينة</th>
                        <th>له</th>
                        <th>عليه</th>
                        <th>الرصيد</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($from > $stStart): ?>
                    <tr style="background:var(--line-soft);font-weight:600;">
                        <td><?= e($from) ?></td>
                        <td colspan="2">رصيد ما قبل الفترة</td>
                        <td>—</td>
                        <td>—</td>
                        <td style="color:<?= $priorBalance > 0 ? 'var(--neg)' : ($priorBalance < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($priorBalance, 2) ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php if (empty($allMoves) && $from <= $stStart): ?>
                    <tr><td colspan="6" class="empty">لا توجد حركات لهذا المورد في هذه الفترة.</td></tr>
                <?php else: ?>
                    <?php foreach ($allMoves as $m): ?>
                        <tr>
                            <td><?= e($m['tx_date']) ?></td>
                            <td>
                                <?= e($m['description']) ?>
                                <?php if (!empty($m['notes'])): ?>
                                    <div style="font-size:12px;color:var(--ink-500);margin-top:2px;"><?= e($m['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($m['treasury_name']) ?></td>
                            <td style="font-weight:500;color:<?= $m['credit'] > 0 ? 'var(--pos)' : '' ?>;">
                                <?= $m['credit'] > 0 ? number_format($m['credit'], 2) : '—' ?>
                            </td>
                            <td style="font-weight:500;color:<?= $m['debit'] > 0 ? 'var(--neg)' : '' ?>;">
                                <?= $m['debit'] > 0 ? number_format($m['debit'], 2) : '—' ?>
                            </td>
                            <td style="font-weight:700;color:<?= $m['balance'] > 0 ? 'var(--neg)' : ($m['balance'] < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                                <?= number_format($m['balance'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="sum-cards">
            <div class="sum-card">
                <div class="label">المشتريات</div>
                <div class="val"><?= number_format($periodPurchases, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المردودات</div>
                <div class="val"><?= number_format($periodReturns, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المصروف له</div>
                <div class="val"><?= number_format($periodPaid, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">المستلم منه</div>
                <div class="val"><?= number_format($periodReceived, 2) ?></div>
            </div>
            <div class="sum-card" style="background:<?= $finalBalance > 0 ? 'var(--neg-soft)' : ($finalBalance < 0 ? 'var(--pos-soft)' : 'var(--line-soft)') ?>;border-color:<?= $finalBalance > 0 ? 'var(--neg)' : ($finalBalance < 0 ? 'var(--pos)' : 'var(--line)') ?>;">
                <div class="label">الرصيد الختامي</div>
                <div class="val" style="color:<?= $finalBalance > 0 ? 'var(--neg)' : ($finalBalance < 0 ? 'var(--pos)' : 'var(--ink-900)') ?>;">
                    <?= number_format($finalBalance, 2) ?>
                </div>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?= $finalBalance > 0 ? 'احنا مديونين له' : ($finalBalance < 0 ? 'هو مدين لنا' : 'خالص') ?>
                </small>
            </div>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
