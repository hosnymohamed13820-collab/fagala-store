<?php
/**
 * shift_data.php — يرجّع تفاصيل وردية (JSON) لتعديلها في صفحة الكاشير.
 * أمان: الكاشير يقدر يجيب وردياته المرفوضة فقط.
 */
require_once __DIR__ . '/auth.php';
require_permission('cashier');
require_once __DIR__ . '/finance.php';

header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];
$id  = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['error' => 'معرّف غير صحيح']);
    exit;
}

$pdo = db();
if (is_owner()) {
    $st = $pdo->prepare('SELECT s.*, u.full_name AS cashier_name FROM shifts s LEFT JOIN users u ON u.id = s.user_id WHERE s.id=? AND s.status IN ("submitted","resubmitted")');
    $st->execute([$id]);
} else {
    $st = $pdo->prepare('SELECT s.*, u.full_name AS cashier_name FROM shifts s LEFT JOIN users u ON u.id = s.user_id WHERE s.id=? AND s.user_id=? AND s.status="rejected"');
    $st->execute([$id, $uid]);
}
$shift = $st->fetch();

if (!$shift) {
    echo json_encode(['error' => 'الوردية غير متاحة للتعديل']);
    exit;
}

if (!is_owner() && is_shift_return_expired((int)$shift['id'])) {
    echo json_encode(['error' => 'الوقت المسموح لتعديل الوردية دي عدّى.']);
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
    'cashier_name'    => (string) ($shift['cashier_name'] ?? ''),
    'system_total'    => (float) $shift['system_total'],
    'cash'            => $cash,
    'lines'           => $lines,
    'expenses'        => shift_expenses_list($id, $pdo),
], JSON_UNESCAPED_UNICODE);
