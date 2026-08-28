<?php
/**
 * journal_account_export.php — تصدير حركات حساب معيّن في فترة محددة (إكسيل .xlsx)
 * نظام الفجالة ستور المالي
 *
 * المدخلات:
 *   ?acc=ID          (معرّف الحساب، 0 لـ «بدون قيد»)
 *   &branch=ID       (اختياري: معرّف الفرع)
 *   &from=YYYY-MM-DD (تاريخ البداية)
 *   &to=YYYY-MM-DD   (تاريخ النهاية)
 */
require_once __DIR__ . '/auth.php';
require_permission('reports');
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/xlsx_lib.php';

$accId = (int) ($_GET['acc'] ?? 0);
$dFrom = $_GET['from'] ?? date('Y-m-01');
$dTo   = $_GET['to']   ?? date('Y-m-d');

if (!strtotime($dFrom) || !strtotime($dTo)) {
    http_response_code(400);
    die('تاريخ غير صحيح.');
}

$pdo = db();

// اسم الحساب وشرط الفلترة
if ($accId > 0) {
    $accCond = 'COALESCE(ja.id, ec.id, 0) = ?';
    $accStmt = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
    $accStmt->execute([$accId]);
    $accountName = (string) ($accStmt->fetchColumn() ?: ('حساب #' . $accId));
} else {
    $accCond = 'COALESCE(ja.id, ec.id, 0) = 0';
    $accountName = 'بدون قيد';
}

// فلتر الفرع (اختياري)
$branchCond = '';
$branchArg  = null;
$branchName = 'كل الفروع';

if (isset($_GET['branch']) && $_GET['branch'] !== '') {
    $bVal = (int) $_GET['branch'];
    if ($bVal > 0) {
        $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id, 0) = ?';
        $branchArg  = $bVal;
        $bStmt = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
        $bStmt->execute([$bVal]);
        $branchName = (string) ($bStmt->fetchColumn() ?: ('فرع #' . $bVal));
    } else {
        $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id, 0) = 0';
        $branchName = 'بدون فرع';
    }
}

$args = [$dFrom, $dTo];
if ($accId > 0) $args[] = $accId;
if ($branchArg !== null) $args[] = $branchArg;

$st = $pdo->prepare(
    "SELECT je.id, je.entry_date, je.description, je.debit, je.credit,
            je.running_balance, je.notes, je.ref_type,
            t.name AS treasury, u.full_name AS by_name,
            cb.name AS branch_name,
            tfrom.name AS transfer_from_name,
            tto.name AS transfer_to_name
       FROM journal_entries je
       JOIN treasuries t ON t.id = je.treasury_id
       LEFT JOIN users u ON u.id = je.created_by
       LEFT JOIN treasury_movements tm
              ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement','date_fix_rev')
       LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
       LEFT JOIN accounts ja ON ja.id = je.account_id
       LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
       LEFT JOIN branch_custody bc ON bc.id = je.ref_id
            AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
       LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
       LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
       LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
       LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
       LEFT JOIN treasury_transfers tt ON tt.id = je.ref_id AND je.ref_type = 'transfer'
       LEFT JOIN treasuries tfrom ON tfrom.id = tt.from_treasury_id
       LEFT JOIN treasuries tto   ON tto.id   = tt.to_treasury_id
      WHERE je.entry_date BETWEEN ? AND ? AND $accCond $branchCond
      ORDER BY je.entry_date, je.id"
);
$st->execute($args);

$kinds = [
    'receipt'              => 'استلام',
    'disbursement'         => 'صرف',
    'transfer'             => 'تحويل',
    'shift'                => 'تقفيل وردية',
    'custody'              => 'عهدة',
    'advance'              => 'سلفة',
    'custody_return'       => 'رد عهدة',
    'custody_settlement'   => 'تسوية عهدة',
    'custody_surplus'      => 'فائض عهدة',
    'custody_deficit'      => 'عجز عهدة',
    'custody_expense'      => 'مصروف عهدة',
    'disbursement_order'   => 'إذن صرف',
];

$x = new SimpleXlsx('حركات ' . mb_substr($accountName, 0, 20));

// A=تاريخ نص · B=بيان نص · C=نوع نص · D=خزينة نص · E=فرع نص · F=مدين رقم · G=دائن رقم · H=رصيد رقم · I=بواسطة نص · J=ملاحظات نص
$x->setColumnTypes(['s', 's', 's', 's', 's', 'n', 'n', 'n', 's', 's']);

$x->addRow(['تقرير حركات الحساب للفترة'], true);
$x->addRow(['الحساب', $accountName]);
$x->addRow(['الفرع', $branchName]);
$x->addRow(['الفترة', 'من ' . $dFrom . ' إلى ' . $dTo]);
$x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
$x->addBlank();

$x->addRow(['التاريخ', 'البيان', 'النوع', 'الخزينة', 'الفرع', 'مدين', 'دائن', 'الرصيد', 'بواسطة', 'الملاحظات'], true);

$totDebit = 0.0;
$totCredit = 0.0;

foreach ($st as $r) {
    $dr  = (float) $r['debit'];
    $cr  = (float) $r['credit'];
    $bal = (float) $r['running_balance'];
    $totDebit  += $dr;
    $totCredit += $cr;

    $desc = (string) $r['description'];
    $tPath = je_transfer_path($r['transfer_from_name'] ?? null, $r['transfer_to_name'] ?? null);
    if ($tPath !== '') {
        $desc = $desc !== '' ? ($desc . ' — ' . $tPath) : $tPath;
    }

    $x->addRow([
        (string) $r['entry_date'],
        $desc,
        $kinds[$r['ref_type']] ?? ($r['ref_type'] ?: '—'),
        (string) $r['treasury'],
        (string) ($r['branch_name'] ?? '—'),
        round($dr, 2),
        round($cr, 2),
        round($bal, 2),
        (string) ($r['by_name'] ?? '—'),
        (string) ($r['notes'] ?? ''),
    ]);
}

$x->addRow(['', 'الإجمالي', '', '', '', round($totDebit, 2), round($totCredit, 2), '', '', ''], true);

$safeAcc = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $accountName);
$filename = 'ACC_MOVEMENTS_' . ($safeAcc ?: 'account') . '_' . $dFrom . '_' . $dTo . '.xlsx';

$x->download($filename);
