<?php
/**
 * shift_data.php — يرجّع تفاصيل وردية (JSON) لتعديلها في صفحة الكاشير.
 * أمان: الكاشير يقدر يجيب وردياته المرفوضة فقط.
 */
require_once __DIR__ . '/auth.php';
require_permission('cashier');

header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];
$id  = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['error' => 'معرّف غير صحيح']);
    exit;
}

$pdo = db();
if (is_owner()) {
    $st = $pdo->prepare('SELECT * FROM shifts WHERE id=? AND status IN ("submitted","resubmitted")');
    $st->execute([$id]);
} else {
    $st = $pdo->prepare('SELECT * FROM shifts WHERE id=? AND user_id=? AND status="rejected"');
    $st->execute([$id, $uid]);
}
$shift = $st->fetch();

if (!$shift) {
    echo json_encode(['error' => 'الوردية غير متاحة للتعديل']);
    exit;
}

// فئات الكاش
$cash = [];
$c = $pdo->prepare('SELECT denomination, count FROM shift_cash WHERE shift_id=?');
$c->execute([$id]);
foreach ($c as $row) {
    $val = (float) $row['denomination'];
    $k = ($val == (int)$val) ? (int)$val : (string)$val;
    $cash[$k] = (int) $row['count'];
}

// سطور الدفع
$lines = [];
$l = $pdo->prepare('SELECT method, line_no, reference, amount FROM shift_payment_lines WHERE shift_id=?');
$l->execute([$id]);
foreach ($l as $row) {
    $lines[] = [
        'method'    => $row['method'],
        'line_no'   => (int) $row['line_no'],
        'reference' => $row['reference'],
        'amount'    => (float) $row['amount'],
    ];
}

echo json_encode([
    'branch_id'       => (int) $shift['branch_id'],
    'business_date'   => $shift['business_date'],
    'shift_name'      => $shift['shift_name'] ?? '',
    'system_total'    => (float) $shift['system_total'],
    'cash'            => $cash,
    'lines'           => $lines,
], JSON_UNESCAPED_UNICODE);
