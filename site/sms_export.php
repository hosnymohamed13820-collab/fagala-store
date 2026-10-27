<?php
/**
 * sms_export.php — تصدير رسايل إنستاباي والبنك الأهلي إلى إكسيل (.xlsx)
 * =====================================================================
 *  للأونر فقط — تصدير الرسايل المحللة مع أعمدة داخل/خارج المنفصلة
 *  لحساب الإجماليات بسهولة.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

if (!is_owner()) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;text-align:center;padding:50px 20px;">'
        . '<h2>غير مصرح لك</h2>'
        . '<p>الصفحة دي متاحة لمالك النظام فقط.</p>'
        . '<a href="dashboard.php">الرجوع للرئيسية</a>'
        . '</div>';
    exit;
}

require_once __DIR__ . '/sms_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$kind = trim((string) ($_GET['kind'] ?? 'all'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$pdo = db();
$rows = sms_fetch($pdo, $from, $to, $kind);

$x = new SimpleXlsx('حركات إنستاباي');

// A=تاريخ · B=وقت · C=نوع · D=داخل · E=خارج · F=طرف · G=رقم مرجعي (نص) · H=رصيد متاح · I=راسل · J=نص الرسالة
$x->setColumnTypes(['s', 's', 's', 'n', 'n', 's', 's', 'n', 's', 's']);

$headers = [
    'التاريخ',
    'الوقت',
    'النوع',
    'داخل',
    'خارج',
    'الطرف',
    'الرقم المرجعي',
    'الرصيد المتاح',
    'الراسل',
    'نص الرسالة',
];
$x->addRow($headers, true);

$totIn  = 0.0;
$totOut = 0.0;

foreach ($rows as $r) {
    $dateStr = $r['txn_at'] ? date('Y-m-d', strtotime((string) $r['txn_at'])) : ($r['sms_at'] ? date('Y-m-d', strtotime((string) $r['sms_at'])) : '');
    $timeStr = $r['txn_at'] ? date('H:i', strtotime((string) $r['txn_at'])) : ($r['sms_at'] ? date('H:i', strtotime((string) $r['sms_at'])) : '');

    $k = $r['kind'];
    if ($k === 'in') {
        $kindLabel = 'داخل';
        $inAmt     = round((float) $r['amount'], 2);
        $outAmt    = '';
        $totIn    += (float) $r['amount'];
    } elseif ($k === 'out') {
        $kindLabel = 'خارج';
        $inAmt     = '';
        $outAmt    = round((float) $r['amount'], 2);
        $totOut   += (float) $r['amount'];
    } elseif ($k === 'card') {
        $kindLabel = 'سحب/خصم كارت';
        $inAmt     = '';
        $outAmt    = round((float) $r['amount'], 2);
        $totOut   += (float) $r['amount'];
    } else {
        $kindLabel = 'مش معروف';
        $inAmt     = '';
        $outAmt    = '';
    }

    $party  = (string) ($r['party'] ?? '');
    $ref    = (string) ($r['ref'] ?? '');
    $avail  = $r['available'] !== null ? round((float) $r['available'], 2) : '';
    $sender = (string) ($r['sender'] ?? '');
    $body   = (string) ($r['body'] ?? '');

    $x->addRow([
        $dateStr,
        $timeStr,
        $kindLabel,
        $inAmt,
        $outAmt,
        $party,
        $ref,
        $avail,
        $sender,
        $body,
    ]);
}

// صف الإجمالي
$x->addRow([
    '',
    '',
    'الإجمالي',
    round($totIn, 2),
    round($totOut, 2),
    '',
    '',
    '',
    '',
    '',
], true);

$filename = "INSTAPAY_{$from}_{$to}.xlsx";
$x->download($filename);
