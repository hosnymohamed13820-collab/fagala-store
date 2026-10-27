<?php
/** اختبار حي لبريف ٢٤٣: معادلة التقفيل، منع التكرار، واستعلامات التقارير. */
declare(strict_types=1);

$wwwRoot = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds3';
$siteRoot = getenv('WORKTREE_SITE') ?: dirname(__DIR__, 2) . '/site';
require_once $wwwRoot . '/config.php';
require_once $siteRoot . '/sale_lib.php';
require_once $siteRoot . '/pos_reports_lib.php';

$pdo = db();
$ids = ['devices' => [], 'sessions' => [], 'docs' => [], 'shifts' => []];
$suffix = bin2hex(random_bytes(5));

function b243_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  ✓ $message\n";
}

/** يعمل وردية بنفس مبيعات البريف ويرجع مفاتيحها. */
function b243_make_session(PDO $pdo, array $owner, int $branchId, string $suffix, string $case, float $openingCash): array
{
    $deviceUuid = 'b243-' . $case . '-' . $suffix;
    $pdo->prepare(
        'INSERT INTO sale_devices (branch_id,name,device_uuid,code,is_active,created_by) VALUES (?,?,?,?,1,?)'
    )->execute([
        $branchId,
        'اختبار بريف 243 ' . $case,
        $deviceUuid,
        'T243' . strtoupper($case) . strtoupper(substr($suffix, 0, 3)),
        (int) $owner['id'],
    ]);
    $deviceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO sale_sessions (device_id,branch_id,cashier_id,business_date,opened_at,opening_cash,status)
         VALUES (?,?,?,CURDATE(),NOW(),?,'open')"
    )->execute([$deviceId, $branchId, (int) $owner['id'], $openingCash]);
    $sessionId = (int) $pdo->lastInsertId();

    $docStmt = $pdo->prepare(
        "INSERT INTO sale_docs
            (doc_kind,status,branch_id,device_id,session_id,cashier_id,client_uuid,receipt_no,
             ref_doc_id,gross,line_discount,invoice_discount,net,paid,change_due,completed_at)
         VALUES (?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())"
    );
    $docStmt->execute(['sale', $branchId, $deviceId, $sessionId, (int) $owner['id'],
        'b243-sale-' . $case . '-' . $suffix, 'B243-S-' . $case . '-' . $suffix, null, 122, 122, 150, 28]);
    $saleDocId = (int) $pdo->lastInsertId();
    $payStmt = $pdo->prepare('INSERT INTO sale_payments (doc_id,method,amount,reference) VALUES (?,?,?,?)');
    $payStmt->execute([$saleDocId, 'cash', 100, null]);
    $payStmt->execute([$saleDocId, 'visa', 50, 'V243-' . $case]);

    $docStmt->execute(['return', $branchId, $deviceId, $sessionId, (int) $owner['id'],
        'b243-return-' . $case . '-' . $suffix, 'B243-R-' . $case . '-' . $suffix,
        $saleDocId, 12, 12, 12, 0]);
    $returnDocId = (int) $pdo->lastInsertId();
    $payStmt->execute([$returnDocId, 'cash', 12, null]);

    return [
        'uuid' => $deviceUuid,
        'device' => $deviceId,
        'session' => $sessionId,
        'docs' => [$saleDocId, $returnDocId],
    ];
}

try {
    $owner = $pdo->query(
        'SELECT u.*, r.is_owner FROM users u JOIN roles r ON r.id=u.role_id WHERE r.is_owner=1 AND u.is_active=1 ORDER BY u.id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        throw new RuntimeException('مفيش مالك نشط للاختبار.');
    }
    $branchId = (int) $pdo->query('SELECT id FROM branches WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();

    // الحالة ١: افتتاحي ٢٠٠، عد فعلي ٢٥٥، وفيزا ٥٠.
    $case1 = b243_make_session($pdo, $owner, $branchId, $suffix, 'a', 200);
    $ids['devices'][] = $case1['device'];
    $ids['sessions'][] = $case1['session'];
    array_push($ids['docs'], ...$case1['docs']);
    sale_events_insert($pdo, [[
        'branch_id' => $branchId, 'device_id' => $case1['device'], 'session_id' => $case1['session'],
        'user_id' => (int) $owner['id'], 'doc_id' => null, 'event_type' => 'line_removed',
        'payload' => ['item_id' => 123, 'code' => 'A243', 'name' => 'صنف ملغي', 'qty' => 2, 'price' => 5],
        'approved_by' => null,
    ], [
        'branch_id' => $branchId, 'device_id' => $case1['device'], 'session_id' => $case1['session'],
        'user_id' => (int) $owner['id'], 'doc_id' => null, 'event_type' => 'cart_cleared',
        'payload' => ['lines_count' => 1, 'lines' => [[
            'item_id' => 124, 'code' => 'B243', 'name' => 'صنف من سلة ملغية', 'qty' => 3, 'price' => 7,
        ]]],
        'approved_by' => null,
    ]]);

    $summary1 = sale_session_summary($pdo, $case1['uuid'], $owner);
    b243_assert((float) $summary1['expected']['cash'] === 260.0, 'الحالة ١: expected.cash = ٢٦٠ شامل الافتتاحي');
    b243_assert((float) $summary1['expected']['cash_sales'] === 60.0, 'الحالة ١: expected.cash_sales = ٦٠ من غير الافتتاحي');
    b243_assert((float) $summary1['expected']['total'] === 310.0, 'الحالة ١: expected.total فضل ٣١٠ شامل الافتتاحي');

    $closed1 = sale_session_close($pdo, $case1['uuid'], [
        'client_uuid' => 'b243-close-a-' . $suffix,
        'cash_counts' => ['200' => 1, '50' => 1, '5' => 1],
        'payment_lines' => [['method' => 'visa', 'reference' => 'V243-a', 'amount' => 50]],
    ], $owner);
    $ids['shifts'][] = (int) $closed1['shift_id'];
    $shift1 = $pdo->query('SELECT * FROM shifts WHERE id=' . (int) $closed1['shift_id'])->fetch(PDO::FETCH_ASSOC);
    $shiftCash1 = (float) $pdo->query(
        'SELECT COALESCE(SUM(subtotal),0) FROM shift_cash WHERE shift_id=' . (int) $closed1['shift_id']
    )->fetchColumn();
    $eventPayload1 = $pdo->query(
        "SELECT payload FROM sale_events WHERE session_id={$case1['session']} AND event_type='session_closed' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    $eventPayload1 = json_decode((string) $eventPayload1, true, 512, JSON_THROW_ON_ERROR);
    b243_assert((float) $shift1['cash_total'] === 55.0, 'الحالة ١: shifts.cash_total = ٥٥');
    b243_assert((float) $shift1['visa_total'] === 50.0, 'الحالة ١: shifts.visa_total = ٥٠');
    b243_assert((float) $shift1['system_total'] === 110.0, 'الحالة ١: shifts.system_total = ١١٠');
    b243_assert((float) $shift1['difference'] === -5.0, 'الحالة ١: shifts.difference = −٥');
    b243_assert($shiftCash1 === 255.0, 'الحالة ١: shift_cash فضل العد الفعلي ٢٥٥');
    b243_assert((float) $closed1['opening_cash'] === 200.0 && (float) $closed1['cash_to_deposit'] === 55.0,
        'الحالة ١: الرد فيه opening_cash = ٢٠٠ وcash_to_deposit = ٥٥');
    b243_assert((float) $eventPayload1['opening_cash'] === 200.0 && (float) $eventPayload1['cash_to_deposit'] === 55.0,
        'الحالة ١: حدث session_closed فيه الافتتاحي والتوريد');

    $duplicateBlocked = false;
    try {
        sale_session_close($pdo, $case1['uuid'], [
            'client_uuid' => 'b243-close-second-' . $suffix,
            'cash_counts' => [], 'payment_lines' => [],
        ], $owner);
    } catch (SaleApiException $e) {
        $duplicateBlocked = $e->http === 409;
    }
    b243_assert($duplicateBlocked, 'التقفيل التاني اترفض 409');

    // الحالة ٢: العد أقل من الفكة، فالتوريد يتسجل بالسالب من غير تصفير.
    $case2 = b243_make_session($pdo, $owner, $branchId, $suffix, 'b', 200);
    $ids['devices'][] = $case2['device'];
    $ids['sessions'][] = $case2['session'];
    array_push($ids['docs'], ...$case2['docs']);
    $closed2 = sale_session_close($pdo, $case2['uuid'], [
        'client_uuid' => 'b243-close-b-' . $suffix,
        'cash_counts' => ['100' => 1, '50' => 1],
        'payment_lines' => [['method' => 'visa', 'reference' => 'V243-b', 'amount' => 50]],
    ], $owner);
    $ids['shifts'][] = (int) $closed2['shift_id'];
    $shift2 = $pdo->query('SELECT * FROM shifts WHERE id=' . (int) $closed2['shift_id'])->fetch(PDO::FETCH_ASSOC);
    b243_assert((float) $shift2['cash_total'] === -50.0, 'الحالة ٢: cash_total = −٥٠ وماتصفّرش');
    b243_assert((float) $shift2['difference'] === -110.0, 'الحالة ٢: difference = −١١٠');

    // الحالة ٣: افتتاحي صفر، أرقام صف shifts تفضل نفس أرقام ٢٤٣ القديمة.
    $case3 = b243_make_session($pdo, $owner, $branchId, $suffix, 'c', 0);
    $ids['devices'][] = $case3['device'];
    $ids['sessions'][] = $case3['session'];
    array_push($ids['docs'], ...$case3['docs']);
    $closed3 = sale_session_close($pdo, $case3['uuid'], [
        'client_uuid' => 'b243-close-c-' . $suffix,
        'cash_counts' => ['200' => 1, '50' => 1, '5' => 1],
        'payment_lines' => [['method' => 'visa', 'reference' => 'V243-c', 'amount' => 50]],
    ], $owner);
    $ids['shifts'][] = (int) $closed3['shift_id'];
    $shift3 = $pdo->query('SELECT * FROM shifts WHERE id=' . (int) $closed3['shift_id'])->fetch(PDO::FETCH_ASSOC);
    b243_assert((float) $shift3['cash_total'] === 255.0, 'الحالة ٣: cash_total = ٢٥٥ زي قبل التعديل');
    b243_assert((float) $shift3['system_total'] === 110.0, 'الحالة ٣: system_total = ١١٠ زي قبل التعديل');
    b243_assert((float) $shift3['difference'] === 195.0, 'الحالة ٣: difference = ١٩٥ زي قبل التعديل');

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
    if ($ids['shifts']) {
        $shiftList = implode(',', array_map('intval', $ids['shifts']));
        $pdo->exec("DELETE FROM shift_payment_lines WHERE shift_id IN ($shiftList)");
        $pdo->exec("DELETE FROM shift_cash WHERE shift_id IN ($shiftList)");
        $pdo->exec("DELETE FROM shift_edit_log WHERE shift_id IN ($shiftList)");
        $pdo->exec("DELETE FROM shifts WHERE id IN ($shiftList)");
    }
    if ($ids['docs']) {
        $docList = implode(',', array_map('intval', $ids['docs']));
        $pdo->exec("DELETE FROM sale_events WHERE doc_id IN ($docList)");
        $pdo->exec("DELETE FROM sale_payments WHERE doc_id IN ($docList)");
        $pdo->exec("DELETE FROM sale_lines WHERE doc_id IN ($docList)");
        $pdo->exec("DELETE FROM sale_docs WHERE id IN ($docList)");
    }
    if ($ids['sessions']) {
        $sessionList = implode(',', array_map('intval', $ids['sessions']));
        $pdo->exec("DELETE FROM sale_events WHERE session_id IN ($sessionList)");
        $pdo->exec("DELETE FROM sale_sessions WHERE id IN ($sessionList)");
    }
    if ($ids['devices']) {
        $deviceList = implode(',', array_map('intval', $ids['devices']));
        $pdo->exec("DELETE FROM sale_devices WHERE id IN ($deviceList)");
    }
}
