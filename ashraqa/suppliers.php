<?php
/**
 * suppliers.php — الموردون
 * نظام الفجالة ستور المالي
 *
 * - فاتورة يدوي (هيدر): المورد + الصافي + الإجمالي + كاش/آجل.
 *   كاش → تطلع من الخزينة فوراً. آجل → مستحقة + جدول دفعات.
 * - سداد دفعات على الفواتير الآجلة (تطلع من الخزينة).
 * - عرض فواتير الاستلامات المربوطة (الضغط على رقمها يفتحها في الاستلامات).
 */
require_once __DIR__ . '/auth.php';
require_permission('suppliers');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = $_GET['tab'] ?? 'invoices';

// خزائن الدفع (كاش/فيزا... المركزية)
$payTreasuries = $pdo->query(
    "SELECT id, name, type FROM treasuries WHERE branch_id IS NULL AND is_active=1
     ORDER BY FIELD(type,'cash','main','visa','instapay','card')"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_invoice') {
                $supplierName = trim($_POST['supplier_name'] ?? '');
                $net   = (float) ($_POST['net_amount'] ?? 0);
                $total = (float) ($_POST['total_amount'] ?? 0);
                $payType = $_POST['pay_type'] ?? 'credit';
                $date  = $_POST['invoice_date'] ?? date('Y-m-d');
                $invNo = trim($_POST['invoice_no'] ?? '');
                $treasuryId = $payType === 'cash' ? (int) ($_POST['treasury_id'] ?? 0) : null;
                add_supplier_invoice($supplierName, $net, $total, $payType, $date,
                    $treasuryId ?: null, $uid, $invNo ?: null);
                $flash = $payType === 'cash'
                    ? 'تم تسجيل الفاتورة وسدادها من الخزينة.'
                    : 'تم تسجيل الفاتورة الآجلة.';
                $tab = 'invoices';
            }
            elseif ($action === 'pay') {
                $invoiceId  = (int) ($_POST['invoice_id'] ?? 0);
                $amount     = (float) ($_POST['amount'] ?? 0);
                $treasuryId = (int) ($_POST['treasury_id'] ?? 0);
                $date       = $_POST['date'] ?? date('Y-m-d');
                $notes      = trim($_POST['notes'] ?? '');
                pay_supplier_invoice($invoiceId, $amount, $treasuryId, $date, $notes ?: null, $uid);
                $flash = 'تم تسجيل الدفعة.';
                $tab = 'invoices';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== جلب البيانات =====
// الفواتير اليدوي (من supplier_invoices)
$manualInvoices = $pdo->query(
    "SELECT si.*, s.name AS supplier_name
     FROM supplier_invoices si
     JOIN suppliers s ON s.id = si.supplier_id
     ORDER BY si.id DESC LIMIT 100"
)->fetchAll();

// فواتير الاستلامات المربوطة (من invoice_distribution)
$receivedInvoices = $pdo->query(
    "SELECT d.*, s.name AS supplier_name
     FROM invoice_distribution d
     LEFT JOIN suppliers s ON s.id = d.supplier_id
     ORDER BY d.id DESC LIMIT 100"
)->fetchAll();

// أرصدة الموردين
$suppliers = $pdo->query(
    'SELECT * FROM suppliers WHERE is_active=1 ORDER BY balance DESC, name'
)->fetchAll();

$statusLabels = [
    'unpaid'  => ['مستحقة', 'var(--neg)', 'var(--neg-soft)'],
    'partial' => ['جزئية', 'var(--warn)', 'var(--warn-soft)'],
    'paid'    => ['مدفوعة', 'var(--pos)', 'var(--pos-soft)'],
];

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
$from               = '';
$to                 = '';

if ($tab === 'statement') {
    try {
        $stSet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'supplier_statement_start' LIMIT 1");
        $stSet->execute();
        $v = $stSet->fetchColumn();
        if ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
            $stStart = (string)$v;
        }
    } catch (Throwable) {}

    $hasErp = suppliers_has_erp_col($pdo);

    if ($selectedSupplierId <= 0) {
        // (أ) الملخص: ٣ استعلامات مجمعة
        // 1) فواتير الشراء والمردودات من hq_invoices
        if ($hasErp) {
            $hqSummary = $pdo->prepare(
                "SELECT
                    s.id AS supplier_id,
                    SUM(CASE WHEN h.doc_type = 2 THEN h.net_amount ELSE 0 END) AS purchases,
                    SUM(CASE WHEN h.doc_type = 6 THEN h.net_amount ELSE 0 END) AS returns
                 FROM hq_invoices h
                 JOIN suppliers s ON (
                     (s.erp_supplier_id IS NOT NULL AND h.supplier_id = s.erp_supplier_id)
                     OR (s.erp_supplier_id IS NULL AND h.supplier_name = s.name)
                 )
                 WHERE h.invoice_date >= ? AND h.doc_type IN (2, 6)
                 GROUP BY s.id"
            );
        } else {
            $hqSummary = $pdo->prepare(
                "SELECT
                    s.id AS supplier_id,
                    SUM(CASE WHEN h.doc_type = 2 THEN h.net_amount ELSE 0 END) AS purchases,
                    SUM(CASE WHEN h.doc_type = 6 THEN h.net_amount ELSE 0 END) AS returns
                 FROM hq_invoices h
                 JOIN suppliers s ON h.supplier_name = s.name
                 WHERE h.invoice_date >= ? AND h.doc_type IN (2, 6)
                 GROUP BY s.id"
            );
        }
        $hqSummary->execute([$stStart]);
        $hqRows = $hqSummary->fetchAll();
        $hqMap = [];
        foreach ($hqRows as $r) {
            $hqMap[(int)$r['supplier_id']] = $r;
        }

        // 2) اليومية والخزينة على حساب المورد
        $jeSummary = $pdo->prepare(
            "SELECT
                a.src_id AS supplier_id,
                SUM(je.debit) AS paid,
                SUM(je.credit) AS received
             FROM journal_entries je
             JOIN accounts a ON a.id = je.account_id AND a.src_table = 'suppliers'
             WHERE je.entry_date >= ?
             GROUP BY a.src_id"
        );
        $jeSummary->execute([$stStart]);
        $jeRows = $jeSummary->fetchAll();
        $jeMap = [];
        foreach ($jeRows as $r) {
            $jeMap[(int)$r['supplier_id']] = $r;
        }

        // 3) الموردين النشطين
        $activeSuppliers = $pdo->query(
            "SELECT id, name, phone FROM suppliers WHERE is_active = 1 ORDER BY name"
        )->fetchAll();

        foreach ($activeSuppliers as $s) {
            $sid = (int) $s['id'];
            $p   = (float) ($hqMap[$sid]['purchases'] ?? 0);
            $ret = (float) ($hqMap[$sid]['returns'] ?? 0);
            $pd  = (float) ($jeMap[$sid]['paid'] ?? 0);
            $rc  = (float) ($jeMap[$sid]['received'] ?? 0);
            $bal = $p - $ret - $pd + $rc;

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
            $x->addRow(['كشوف حسابات الموردين — من ' . $stStart . ' حتى ' . date('Y-m-d')]);
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
            $x->download('SUPPLIERS_SUMMARY_' . date('Y-m-d') . '.xlsx');
            exit;
        }
    } else {
        // (ب) كشف مورد واحد
        $stSupp = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stSupp->execute([$selectedSupplierId]);
        $singleSupplier = $stSupp->fetch();

        if ($singleSupplier) {
            $from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $stStart;
            $to   = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) ? $_GET['to'] : date('Y-m-d');

            // حساب المورد في الشجرة
            $stAcc = $pdo->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
            $stAcc->execute([$selectedSupplierId]);
            $suppAccId = (int) $stAcc->fetchColumn();

            // رصيد ما قبل الفترة
            if ($from > $stStart) {
                if ($hasErp && !empty($singleSupplier['erp_supplier_id'])) {
                    $stPriorHq = $pdo->prepare(
                        "SELECT
                            SUM(CASE WHEN doc_type = 2 THEN net_amount ELSE 0 END) AS purchases,
                            SUM(CASE WHEN doc_type = 6 THEN net_amount ELSE 0 END) AS returns
                         FROM hq_invoices
                         WHERE invoice_date >= ? AND invoice_date < ?
                           AND doc_type IN (2, 6)
                           AND (supplier_id = ? OR (supplier_id IS NULL AND supplier_name = ?))"
                    );
                    $stPriorHq->execute([$stStart, $from, $singleSupplier['erp_supplier_id'], $singleSupplier['name']]);
                } else {
                    $stPriorHq = $pdo->prepare(
                        "SELECT
                            SUM(CASE WHEN doc_type = 2 THEN net_amount ELSE 0 END) AS purchases,
                            SUM(CASE WHEN doc_type = 6 THEN net_amount ELSE 0 END) AS returns
                         FROM hq_invoices
                         WHERE invoice_date >= ? AND invoice_date < ?
                           AND doc_type IN (2, 6)
                           AND supplier_name = ?"
                    );
                    $stPriorHq->execute([$stStart, $from, $singleSupplier['name']]);
                }
                $priorHq = $stPriorHq->fetch();
                $priorPurchases = (float) ($priorHq['purchases'] ?? 0);
                $priorReturns   = (float) ($priorHq['returns'] ?? 0);

                if ($suppAccId > 0) {
                    $stPriorJe = $pdo->prepare(
                        "SELECT
                            SUM(debit) AS paid,
                            SUM(credit) AS received
                         FROM journal_entries
                         WHERE account_id = ?
                           AND entry_date >= ? AND entry_date < ?"
                    );
                    $stPriorJe->execute([$suppAccId, $stStart, $from]);
                    $priorJe = $stPriorJe->fetch();
                    $priorPaid     = (float) ($priorJe['paid'] ?? 0);
                    $priorReceived = (float) ($priorJe['received'] ?? 0);
                }
                $priorBalance = $priorPurchases - $priorReturns - $priorPaid + $priorReceived;
            }

            // حركات الفترة [from, to]
            if ($hasErp && !empty($singleSupplier['erp_supplier_id'])) {
                $stHq = $pdo->prepare(
                    "SELECT sth_id, DATE(invoice_date) AS tx_date, doc_type, doc_no, net_amount
                     FROM hq_invoices
                     WHERE invoice_date >= ? AND invoice_date <= ?
                       AND doc_type IN (2, 6)
                       AND (supplier_id = ? OR (supplier_id IS NULL AND supplier_name = ?))
                     ORDER BY invoice_date ASC, sth_id ASC"
                );
                $stHq->execute([$from, $to, $singleSupplier['erp_supplier_id'], $singleSupplier['name']]);
            } else {
                $stHq = $pdo->prepare(
                    "SELECT sth_id, DATE(invoice_date) AS tx_date, doc_type, doc_no, net_amount
                     FROM hq_invoices
                     WHERE invoice_date >= ? AND invoice_date <= ?
                       AND doc_type IN (2, 6)
                       AND supplier_name = ?
                     ORDER BY invoice_date ASC, sth_id ASC"
                );
                $stHq->execute([$from, $to, $singleSupplier['name']]);
            }
            $periodHqRows = $stHq->fetchAll();

            $periodJeRows = [];
            if ($suppAccId > 0) {
                $stJe = $pdo->prepare(
                    "SELECT je.id, je.entry_date AS tx_date, je.description, je.debit, je.credit,
                            t.name AS treasury_name, je.notes
                     FROM journal_entries je
                     LEFT JOIN treasuries t ON t.id = je.treasury_id
                     WHERE je.account_id = ?
                       AND je.entry_date >= ? AND je.entry_date <= ?
                     ORDER BY je.entry_date ASC, je.id ASC"
                );
                $stJe->execute([$suppAccId, $from, $to]);
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
                $sheetTitle = mb_substr('كشف ' . $singleSupplier['name'], 0, 31);
                $x = new SimpleXlsx($sheetTitle);
                $x->addRow(['كشف حساب مورد: ' . $singleSupplier['name']]);
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
                $x->download('SUPPLIER_' . ($safeName ?: $selectedSupplierId) . '_' . $from . '_' . $to . '.xlsx');
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
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; }
    .btn-ghost { background:var(--bg); color:var(--accent-text); }
    .btn-ghost:hover { background:var(--line); }
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
    .btn-cancel { background:var(--line-soft); color:var(--ink-500); }
    .btn-cancel:hover { background:var(--line); }
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

<div class="tabs">
    <a href="?tab=statement" class="tab <?= $tab==='statement'?'active':'' ?>">كشوف الحسابات</a>
    <a href="?tab=invoices" class="tab <?= $tab==='invoices'?'active':'' ?>">الفواتير اليدوي</a>
    <a href="?tab=received" class="tab <?= $tab==='received'?'active':'' ?>">فواتير الاستلامات</a>
    <a href="?tab=balances" class="tab <?= $tab==='balances'?'active':'' ?>">أرصدة الموردين</a>
</div>

<?php if ($tab === 'statement'): ?>
    <?php if ($selectedSupplierId <= 0 || !$singleSupplier): ?>
        <!-- (أ) الملخص: كل الموردين -->
        <div class="toolbar">
            <div>
                <span class="section-title" style="margin-bottom:0;">كشوف حسابات الموردين</span>
                <div style="font-size:13px;color:var(--ink-500);margin-top:4px;">
                    الفترة من <?= e($stStart) ?> حتى اليوم (بعد جرد ٢٧ يونيو)
                </div>
            </div>
            <a href="?tab=statement&export=xlsx" class="btn btn-sm btn-ghost">
                <i class="ti ti-download"></i> تصدير إكسيل
            </a>
        </div>

        <div class="card">
            <?php if (empty($statementSummary)): ?>
                <div class="empty">لا يوجد موردون نشطون.</div>
            <?php else: ?>
            <table>
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
                <tbody>
                <?php foreach ($statementSummary as $s): ?>
                    <tr>
                        <td style="font-weight:600;">
                            <a href="?tab=statement&supplier=<?= (int)$s['id'] ?>" class="inv-link">
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
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid var(--line);">
                        <td>الإجمالي</td>
                        <td><?= number_format($totSumPurchases, 2) ?></td>
                        <td><?= number_format($totSumReturns, 2) ?></td>
                        <td><?= number_format($totSumPaid, 2) ?></td>
                        <td><?= number_format($totSumReceived, 2) ?></td>
                        <td style="color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--ink-400)') ?>;">
                            <?= number_format($totSumBalance, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>

        <div class="sum-cards">
            <div class="sum-card">
                <div class="label">إجمالي المشتريات</div>
                <div class="val"><?= number_format($totSumPurchases, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المردودات</div>
                <div class="val"><?= number_format($totSumReturns, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المصروف للموردين</div>
                <div class="val"><?= number_format($totSumPaid, 2) ?></div>
            </div>
            <div class="sum-card">
                <div class="label">إجمالي المستلم من الموردين</div>
                <div class="val"><?= number_format($totSumReceived, 2) ?></div>
            </div>
            <div class="sum-card" style="background:<?= $totSumBalance > 0 ? 'var(--neg-soft)' : ($totSumBalance < 0 ? 'var(--pos-soft)' : 'var(--line-soft)') ?>;border-color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--line)') ?>;">
                <div class="label">إجمالي الرصيد القائم</div>
                <div class="val" style="color:<?= $totSumBalance > 0 ? 'var(--neg)' : ($totSumBalance < 0 ? 'var(--pos)' : 'var(--ink-900)') ?>;">
                    <?= number_format($totSumBalance, 2) ?>
                </div>
                <small style="font-size:11px;color:var(--ink-500);display:block;margin-top:2px;">
                    <?= $totSumBalance > 0 ? 'احنا مديونين للموردين' : ($totSumBalance < 0 ? 'الموردين مدينين لنا' : 'الأرصدة متوازنة') ?>
                </small>
            </div>
        </div>

    <?php else: ?>
        <!-- (ب) كشف حساب مورد واحد -->
        <div class="toolbar" style="align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div>
                <a href="?tab=statement" class="btn btn-sm btn-ghost" style="margin-bottom:8px;">
                    <i class="ti ti-arrow-right"></i> كل الموردين
                </a>
                <div class="section-title" style="margin-bottom:2px;font-size:18px;">
                    <?= e($singleSupplier['name']) ?>
                </div>
                <div style="font-size:12px;color:var(--ink-500);">
                    <?= !empty($singleSupplier['phone']) ? ('تليفون: ' . e($singleSupplier['phone'])) : '' ?>
                </div>
            </div>

            <form method="get" class="filter-form" style="margin-bottom:0;">
                <input type="hidden" name="tab" value="statement">
                <input type="hidden" name="supplier" value="<?= $selectedSupplierId ?>">
                <label>من <input type="date" name="from" value="<?= e($from) ?>" min="<?= e($stStart) ?>"></label>
                <label>إلى <input type="date" name="to" value="<?= e($to) ?>"></label>
                <button type="submit" class="btn btn-sm">تطبيق</button>
                <a href="?tab=statement&supplier=<?= $selectedSupplierId ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=xlsx" class="btn btn-sm btn-ghost">
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
<?php elseif ($tab === 'invoices'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($manualInvoices) ?> فاتورة</span>
        <button class="btn" onclick="openInvoice()"><i class="ti ti-plus"></i> فاتورة جديدة</button>
    </div>
    <div class="card">
        <?php if (empty($manualInvoices)): ?>
            <div class="empty">لا توجد فواتير يدوي بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>المورد</th><th>رقم</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحالة</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($manualInvoices as $inv): ?>
                <?php
                    $remaining = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                    $sl = $statusLabels[$inv['status']] ?? ['—','var(--ink-500)','var(--line)'];
                ?>
                <tr>
                    <td><?= e($inv['invoice_date']) ?></td>
                    <td style="font-weight:500;"><?= e($inv['supplier_name']) ?></td>
                    <td><?= e($inv['invoice_no'] ?? '—') ?></td>
                    <td><?= number_format((float)$inv['total_amount'],2) ?></td>
                    <td><?= number_format((float)$inv['paid_amount'],2) ?></td>
                    <td style="font-weight:500;"><?= number_format($remaining,2) ?></td>
                    <td><span class="badge" style="color:<?= $sl[1] ?>;background:<?= $sl[2] ?>;"><?= e($sl[0]) ?></span></td>
                    <td>
                        <?php if ($inv['status'] !== 'paid'): ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openPay(<?= (int)$inv['id'] ?>, <?= $remaining ?>, <?= json_encode($inv["supplier_name"], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>سداد</button>
                        <?php else: ?>
                            <span style="color:var(--ink-300);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Modal: فاتورة جديدة -->
    <div class="modal-bg" id="invoiceModal">
        <div class="modal">
            <h3>فاتورة مورد جديدة</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_invoice">
                <div class="form-row"><label>اسم المورد *</label><input type="text" name="supplier_name" required aria-label="اسم المورد"></div>
                <div class="form-row"><label>رقم الفاتورة</label><input type="text" name="invoice_no" aria-label="رقم الفاتورة"></div>
                <div class="form-row"><label>الصافي</label><input type="number" step="0.01" min="0" name="net_amount" value="0" aria-label="الصافي"></div>
                <div class="form-row"><label>الإجمالي *</label><input type="number" step="0.01" min="0.01" name="total_amount" required aria-label="الإجمالي"></div>
                <div class="form-row"><label for="paySupplier">التاريخ</label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-row">
                    <label>نوع الدفع</label>
                    <div class="pay-type-row">
                        <div class="pay-opt">
                            <input type="radio" name="pay_type" id="pt_credit" value="credit" checked onchange="toggleTreasury()">
                            <label for="pt_credit">آجل</label>
                        </div>
                        <div class="pay-opt">
                            <input type="radio" name="pay_type" id="pt_cash" value="cash" onchange="toggleTreasury()">
                            <label for="pt_cash">كاش</label>
                        </div>
                    </div>
                </div>
                <div class="form-row" id="treasuryRow" style="display:none;">
                    <label>خزينة الدفع *</label>
                    <select name="treasury_id">
                        <?php foreach ($payTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeM('invoiceModal')">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: سداد -->
    <div class="modal-bg" id="payModal">
        <div class="modal">
            <h3>سداد دفعة</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="invoice_id" id="payInvoiceId" value="">
                <div class="form-row"><label>المورد</label><input type="text" id="paySupplier" readonly></div>
                <div class="form-row"><label for="payAmount">المبلغ * (المتبقي: <span id="payRemaining">0</span>)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="payAmount" required></div>
                <div class="form-row">
                    <label>خزينة الدفع *</label>
                    <select name="treasury_id" required>
                        <?php foreach ($payTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" aria-label="ملاحظات"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn">تسجيل الدفعة</button>
                    <button type="button" class="btn btn-cancel" onclick="closeM('payModal')">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'received'): ?>
    <div class="card">
        <div class="section-title">فواتير الاستلامات (اضغط رقم الفاتورة لفتحها في الاستلامات)</div>
        <?php if (empty($receivedInvoices)): ?>
            <div class="empty">لا توجد فواتير استلامات بعد. تظهر هنا تلقائياً عند فتحها في الاستلامات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>المورد</th><th>رقم الفاتورة</th><th>الإجمالي</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($receivedInvoices as $d): ?>
                <tr>
                    <td><?= e($d['dist_date']) ?></td>
                    <td style="font-weight:500;"><?= e($d['supplier_name'] ?? '—') ?></td>
                    <td><a class="inv-link" href="invoices.php?invoice=<?= e($d['invoice_no']) ?>"><?= e($d['invoice_no']) ?></a></td>
                    <td><?= number_format((float)$d['total_amount'],2) ?></td>
                    <td style="font-size:13px;color:var(--ink-500);"><?= e($d['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php else: // balances ?>
    <div class="card">
        <div class="section-title">أرصدة الموردين (المستحق عليهم)</div>
        <?php if (empty($suppliers)): ?>
            <div class="empty">لا يوجد موردون بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>المورد</th><th>التليفون</th><th>الرصيد المستحق</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($s['name']) ?></td>
                    <td><?= e($s['phone'] ?? '—') ?></td>
                    <td style="font-weight:600;color:<?= $s['balance']>0?'var(--neg)':'var(--pos)' ?>;">
                        <?= number_format((float)$s['balance'],2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
    function openM(id){ document.getElementById(id).classList.add('open'); }
    function closeM(id){ document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    function openInvoice(){ toggleTreasury(); openM('invoiceModal'); }

    function toggleTreasury(){
        const cash = document.getElementById('pt_cash').checked;
        const row = document.getElementById('treasuryRow');
        row.style.display = cash ? 'block' : 'none';
        row.querySelector('select').required = cash;
    }

    function openPay(id, remaining, supplier){
        document.getElementById('payInvoiceId').value = id;
        document.getElementById('paySupplier').value = supplier;
        document.getElementById('payRemaining').textContent = remaining.toFixed(2);
        document.getElementById('payAmount').value = remaining.toFixed(2);
        document.getElementById('payAmount').max = remaining;
        openM('payModal');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
