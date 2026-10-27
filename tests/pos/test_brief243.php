<?php
/** اختبار حي لبريف ٢٤٣: معادلة التقفيل، منع التكرار، واستعلامات التقارير. */
declare(strict_types=1);

$wwwRoot = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds3';
$siteRoot = getenv('WORKTREE_SITE') ?: dirname(__DIR__, 2) . '/site';
require_once $wwwRoot . '/config.php';
require_once $siteRoot . '/sale_lib.php';
require_once $siteRoot . '/pos_reports_lib.php';

$pdo = db();
$ids = ['device' => 0, 'session' => 0, 'docs' => [], 'shift' => 0];
$suffix = bin2hex(random_bytes(5));

function b243_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  ✓ $message\n";
}

try {
    $owner = $pdo->query(
        'SELECT u.*, r.is_owner FROM users u JOIN roles r ON r.id=u.role_id WHERE r.is_owner=1 AND u.is_active=1 ORDER BY u.id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        throw new RuntimeException('مفيش مالك نشط للاختبار.');
    }
    $branchId = (int) $pdo->query('SELECT id FROM branches WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();

    $pdo->prepare(
        'INSERT INTO sale_devices (branch_id,name,device_uuid,code,is_active,created_by) VALUES (?,?,?,?,1,?)'
    )->execute([$branchId, 'اختبار بريف 243', 'b243-' . $suffix, 'T243' . strtoupper(substr($suffix, 0, 4)), (int) $owner['id']]);
    $ids['device'] = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO sale_sessions (device_id,branch_id,cashier_id,business_date,opened_at,opening_cash,status)
         VALUES (?,?,?,CURDATE(),NOW(),200,'open')"
    )->execute([$ids['device'], $branchId, (int) $owner['id']]);
    $ids['session'] = (int) $pdo->lastInsertId();

    $docStmt = $pdo->prepare(
        "INSERT INTO sale_docs
            (doc_kind,status,branch_id,device_id,session_id,cashier_id,client_uuid,receipt_no,
             ref_doc_id,gross,line_discount,invoice_discount,net,paid,change_due,completed_at)
         VALUES (?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())"
    );
    $docStmt->execute(['sale', $branchId, $ids['device'], $ids['session'], (int) $owner['id'], 'b243-sale-' . $suffix,
        'B243-S-' . $suffix, null, 122, 122, 150, 28]);
    $saleDocId = (int) $pdo->lastInsertId();
    $ids['docs'][] = $saleDocId;
    $payStmt = $pdo->prepare('INSERT INTO sale_payments (doc_id,method,amount,reference) VALUES (?,?,?,?)');
    $payStmt->execute([$saleDocId, 'cash', 100, null]);
    $payStmt->execute([$saleDocId, 'visa', 50, 'V243']);

    $docStmt->execute(['return', $branchId, $ids['device'], $ids['session'], (int) $owner['id'], 'b243-return-' . $suffix,
        'B243-R-' . $suffix, $saleDocId, 12, 12, 12, 0]);
    $returnDocId = (int) $pdo->lastInsertId();
    $ids['docs'][] = $returnDocId;
    $payStmt->execute([$returnDocId, 'cash', 12, null]);

    sale_events_insert($pdo, [[
        'branch_id' => $branchId, 'device_id' => $ids['device'], 'session_id' => $ids['session'],
        'user_id' => (int) $owner['id'], 'doc_id' => null, 'event_type' => 'line_removed',
        'payload' => ['item_id' => 123, 'code' => 'A243', 'name' => 'صنف ملغي', 'qty' => 2, 'price' => 5],
        'approved_by' => null,
    ], [
        'branch_id' => $branchId, 'device_id' => $ids['device'], 'session_id' => $ids['session'],
        'user_id' => (int) $owner['id'], 'doc_id' => null, 'event_type' => 'cart_cleared',
        'payload' => ['lines_count' => 1, 'lines' => [[
            'item_id' => 124, 'code' => 'B243', 'name' => 'صنف من سلة ملغية', 'qty' => 3, 'price' => 7,
        ]]],
        'approved_by' => null,
    ]]);

    $summary = sale_session_summary($pdo, 'b243-' . $suffix, $owner);
    b243_assert((float) $summary['expected']['cash'] === 260.0, 'المتوقع كاش = ٢٦٠ بعد خصم الباقي والمرتجع');
    b243_assert((float) $summary['expected']['visa'] === 50.0, 'المتوقع فيزا = ٥٠');

    $closed = sale_session_close($pdo, 'b243-' . $suffix, [
        'client_uuid' => 'b243-close-' . $suffix,
        'cash_counts' => ['200' => 1, '50' => 1, '5' => 1],
        'payment_lines' => [['method' => 'visa', 'reference' => 'V243', 'amount' => 50]],
    ], $owner);
    $ids['shift'] = (int) $closed['shift_id'];
    b243_assert((float) $closed['difference']['cash'] === -5.0, 'فرق الكاش = −٥');
    b243_assert((float) $closed['difference']['total'] === -5.0, 'إجمالي الفرق = −٥ بنفس معادلة cashier.php');

    $shift = $pdo->query('SELECT * FROM shifts WHERE id=' . $ids['shift'])->fetch(PDO::FETCH_ASSOC);
    b243_assert($shift['status'] === 'submitted' && $shift['source'] === 'pos', 'التقفيل submitted ومصدره pos');
    b243_assert((float) $shift['system_total'] === 310.0, 'system_total = مجموع المتوقع ٣١٠ شاملاً الافتتاحي');

    $duplicateBlocked = false;
    try {
        sale_session_close($pdo, 'b243-' . $suffix, [
            'client_uuid' => 'b243-close-second-' . $suffix,
            'cash_counts' => [], 'payment_lines' => [],
        ], $owner);
    } catch (SaleApiException $e) {
        $duplicateBlocked = $e->http === 409;
    }
    b243_assert($duplicateBlocked, 'التقفيل التاني اترفض 409');

    $filter = pos_reports_filters($pdo, ['from' => date('Y-m-d'), 'to' => date('Y-m-d')], $owner);
    foreach (array_keys(pos_reports_definitions()) as $report) {
        $result = pos_reports_run($pdo, $report, $filter);
        b243_assert(isset($result['rows']) && is_array($result['rows']), 'استعلام تقرير ' . $report . ' اشتغل');
        if ($report === 'removed') {
            $fixtureRows = array_values(array_filter($result['rows'], static fn(array $row): bool => in_array($row['code'], ['A243', 'B243'], true)));
            b243_assert(count($fixtureRows) === 2, 'تقرير الملغي فك line_removed وcart_cleared لصفوف أصناف');
        }
    }

    echo "نجح اختبار بريف ٢٤٣.\n";
} finally {
    if ($ids['shift'] > 0) {
        $pdo->exec('DELETE FROM shift_payment_lines WHERE shift_id=' . $ids['shift']);
        $pdo->exec('DELETE FROM shift_cash WHERE shift_id=' . $ids['shift']);
        $pdo->exec('DELETE FROM shift_edit_log WHERE shift_id=' . $ids['shift']);
        $pdo->exec('DELETE FROM shifts WHERE id=' . $ids['shift']);
    }
    if ($ids['docs']) {
        $list = implode(',', array_map('intval', $ids['docs']));
        $pdo->exec("DELETE FROM sale_events WHERE session_id={$ids['session']} OR doc_id IN ($list)");
        $pdo->exec("DELETE FROM sale_payments WHERE doc_id IN ($list)");
        $pdo->exec("DELETE FROM sale_lines WHERE doc_id IN ($list)");
        $pdo->exec("DELETE FROM sale_docs WHERE id IN ($list)");
    }
    if ($ids['session'] > 0) {
        $pdo->exec('DELETE FROM sale_sessions WHERE id=' . $ids['session']);
    }
    if ($ids['device'] > 0) {
        $pdo->exec('DELETE FROM sale_devices WHERE id=' . $ids['device']);
    }
}
