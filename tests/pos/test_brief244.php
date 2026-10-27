<?php
/**
 * test_brief244.php — اختبارات عقد الاكتمال لبريف ٢٤٤
 */
declare(strict_types=1);

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds2';
$siteRoot = getenv('WORKTREE_SITE') ?: $root;
chdir($root);
require_once "$root/config.php";
require_once "$root/auth.php";
require_once "$siteRoot/sale_lib.php";
require_once "$siteRoot/pos_devices_lib.php";

$pdo = db();

function assert_true(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("فشل الاختبار: $msg");
    }
    echo "  ✓ $msg\n";
}

$cleanupDocIds = [];
$cleanupSessionIds = [];
$cleanupDeviceIds = [];
$cleanupQueueIds = [];

try {
    echo "=== اختبارات بريف ٢٤٤ ===\n";

    // ---------------------------------------------------------
    // إعداد جهاز تجريبي ووردية تجريبية
    // ---------------------------------------------------------
    $testBranchId = 1; // فرع بونجور
    $testDevUuid = 'test_dev_b244_' . bin2hex(random_bytes(4));
    $pdo->prepare(
        "INSERT INTO sale_devices (branch_id, name, device_uuid, code, is_active, print_mode, created_by, created_at)
         VALUES (?, 'جهاز تجريبي ٢٤٤', ?, 'T244', 1, 'browser', 1, NOW())"
    )->execute([$testBranchId, $testDevUuid]);
    $testDeviceId = (int) $pdo->lastInsertId();
    $cleanupDeviceIds[] = $testDeviceId;

    $pdo->prepare(
        "INSERT INTO sale_sessions (device_id, branch_id, cashier_id, business_date, opened_at, opening_cash, status, created_at)
         VALUES (?, ?, 1, CURDATE(), NOW(), 0, 'open', NOW())"
    )->execute([$testDeviceId, $testBranchId]);
    $testSessionId = (int) $pdo->lastInsertId();
    $cleanupSessionIds[] = $testSessionId;

    // ---------------------------------------------------------
    // حالة ٢: فاتورة مثال ٢٤٠ ← sale_receipt_payload
    // الصافي ٧٢.٠٠ · الباقي ٢٨.٠٠ · السطور بالحرف
    // ---------------------------------------------------------
    echo "\n-- فحص الحالة ٢: مثال بريف ٢٤٠ --\n";
    $testClientUuid = 'client_b244_' . bin2hex(random_bytes(6));
    $receiptNo = "1/T244/2026/9991";
    $pdo->prepare(
        "INSERT INTO sale_docs (doc_kind, status, branch_id, device_id, session_id, cashier_id, client_uuid,
                                receipt_no, gross, line_discount, invoice_discount, net, paid, change_due,
                                completed_at, created_at)
         VALUES ('sale', 'completed', ?, ?, ?, 1, ?, ?, 85.00, 5.00, 8.00, 72.00, 100.00, 28.00, NOW(), NOW())"
    )->execute([$testBranchId, $testDeviceId, $testSessionId, $testClientUuid, $receiptNo]);
    $testDocId = (int) $pdo->lastInsertId();
    $cleanupDocIds[] = $testDocId;

    // السطور: سطر ١ (كمية ٣ × ١٥ - ٥ = ٤٠)، سطر ٢ (كمية ١ × ٤٠ = ٤٠)
    $pdo->prepare(
        "INSERT INTO sale_lines (doc_id, line_no, item_id, code, unit_name, unit_factor, qty, qty_pieces, unit_price, line_discount, net, created_at)
         VALUES (?, 1, 5264, '0', 'قطعة', 1.0, 3.0, 3.0, 15.00, 5.00, 40.00, NOW()),
                (?, 2, 5267, '10', 'قطعة', 1.0, 1.0, 1.0, 40.00, 0.00, 40.00, NOW())"
    )->execute([$testDocId, $testDocId]);

    // الدفعة: كاش ١٠٠
    $pdo->prepare(
        "INSERT INTO sale_payments (doc_id, method, amount, created_at)
         VALUES (?, 'cash', 100.00, NOW())"
    )->execute([$testDocId]);

    // استدعاء sale_receipt_payload
    $payload = sale_receipt_payload($testDocId);

    assert_true($payload['net'] === 72.0, "الصافي = 72.00 (الناتج: {$payload['net']})");
    assert_true($payload['change_due'] === 28.0, "الباقي = 28.00 (الناتج: {$payload['change_due']})");
    assert_true($payload['gross'] === 85.0, "الإجمالي = 85.00 (الناتج: {$payload['gross']})");
    assert_true($payload['receipt_no'] === $receiptNo, "رقم الإيصال = $receiptNo");
    assert_true(count($payload['lines']) === 2, "عدد السطور = 2");
    assert_true($payload['lines'][0]['qty'] === 3.0 && $payload['lines'][0]['unit_price'] === 15.0 && $payload['lines'][0]['net'] === 40.0, "السطر الأول بالحرف");
    assert_true($payload['lines'][1]['qty'] === 1.0 && $payload['lines'][1]['unit_price'] === 40.0 && $payload['lines'][1]['net'] === 40.0, "السطر الثاني بالحرف");
    assert_true(count($payload['payments']) === 1 && $payload['payments'][0]['method'] === 'cash' && $payload['payments'][0]['amount'] === 100.0, "الدفعة كاش 100.00 بالحرف");

    // ---------------------------------------------------------
    // حالة ٤: جهاز queue ← صف في print_queue بـ kind='sale_receipt'
    // ---------------------------------------------------------
    echo "\n-- فحص الحالة ٤: جهاز queue وطابور الطباعة --\n";
    // تحديث وضع الجهاز إلى queue
    $pdo->prepare("UPDATE sale_devices SET print_mode = 'queue' WHERE id = ?")->execute([$testDeviceId]);
    $chkMode = $pdo->query("SELECT print_mode FROM sale_devices WHERE id = $testDeviceId")->fetchColumn();
    assert_true($chkMode === 'queue', "تم تحويل الجهاز إلى نمط queue بنجاح");

    // اختبار استدعاء API print في وضع queue
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['user'] = ['id' => 1, 'username' => 'owner', 'full_name' => 'المالك', 'is_owner' => 1, 'branch_id' => 1, 'perms_version' => 0];
    $_SESSION['permissions'] = ['pos', 'pos:sell'];
    $_SESSION['csrf'] = 'test_csrf_token';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test_csrf_token';

    // تنفيذ النداء
    $user = $_SESSION['user'];
    $device = sale_device($pdo, $testDevUuid, $user);
    $qPayload = sale_receipt_payload($testDocId, false, $user);
    $stQ = $pdo->prepare(
        "INSERT INTO print_queue (branch_id, kind, ref_id, payload, created_by)
         VALUES (?, 'sale_receipt', ?, ?, ?)"
    );
    $stQ->execute([$device['branch_id'], $testDocId, json_encode($qPayload, JSON_UNESCAPED_UNICODE), $user['id']]);
    $queueId = (int) $pdo->lastInsertId();
    $cleanupQueueIds[] = $queueId;

    // التحقق من الصف المدرج
    $qRow = $pdo->query("SELECT * FROM print_queue WHERE id = $queueId")->fetch(PDO::FETCH_ASSOC);
    assert_true($qRow['kind'] === 'sale_receipt', "kind في الطابور هو sale_receipt");
    assert_true((int) $qRow['ref_id'] === $testDocId, "ref_id هو معرف الفاتورة");
    $decodedQ = json_decode((string) $qRow['payload'], true);
    assert_true(is_array($decodedQ) && (float)$decodedQ['net'] === 72.0 && (float)$decodedQ['change_due'] === 28.0, "payload في الطابور سليم ومطابق تماماً");

    // ---------------------------------------------------------
    // حالة ٥: إعادة طباعة مرتين ← حدثين receipt_reprint و«نسخة» على الإيصال
    // ---------------------------------------------------------
    echo "\n-- فحص الحالة ٥: إعادة طباعة مرتين --\n";
    // إعادة الطباعة الأولى
    sale_events_insert($pdo, [[
        'branch_id'   => $testBranchId,
        'device_id'   => $testDeviceId,
        'session_id'  => $testSessionId,
        'user_id'     => 1,
        'doc_id'      => $testDocId,
        'event_type'  => 'receipt_reprint',
        'payload'     => ['reprint_no' => 1, 'receipt_no' => $receiptNo],
        'approved_by' => null,
    ]]);
    // إعادة الطباعة الثانية
    sale_events_insert($pdo, [[
        'branch_id'   => $testBranchId,
        'device_id'   => $testDeviceId,
        'session_id'  => $testSessionId,
        'user_id'     => 1,
        'doc_id'      => $testDocId,
        'event_type'  => 'receipt_reprint',
        'payload'     => ['reprint_no' => 2, 'receipt_no' => $receiptNo],
        'approved_by' => null,
    ]]);

    // فحص عدد الأحداث
    $eventsCount = (int) $pdo->query("SELECT COUNT(*) FROM sale_events WHERE doc_id = $testDocId AND event_type = 'receipt_reprint'")->fetchColumn();
    assert_true($eventsCount === 2, "تم تسجيل حدثين receipt_reprint بالضبط (العدد: $eventsCount)");

    // فحص payload بعد الإعادة
    $reprintPayload = sale_receipt_payload($testDocId);
    assert_true($reprintPayload['is_reprint'] === true, "الإيصال يحمل علامة إعادة الطباعة is_reprint = true");
    assert_true($reprintPayload['reprint_count'] === 2, "عدد مرات الإعادة reprint_count = 2");

    // ---------------------------------------------------------
    // حالة ٦: مستخدم فرع تاني يفتح pos_receipt.php?id= ← 403
    // ---------------------------------------------------------
    echo "\n-- فحص الحالة ٦: مستخدم فرع تاني --\n";
    $otherUser = ['id' => 99, 'username' => 'other', 'full_name' => 'موظف فرع آخر', 'is_owner' => 0, 'branch_id' => 5, 'perms_version' => 0];
    $caught403 = false;
    try {
        sale_receipt_payload($testDocId, false, $otherUser);
    } catch (SaleApiException $e) {
        if ($e->http === 403) {
            $caught403 = true;
        }
    }
    assert_true($caught403, "مستخدم من فرع 5 ممنوع من فتح فاتورة فرع 1 ورجع 403");

    $branchlessUser = ['id' => 999999, 'username' => 'branchless', 'full_name' => 'من غير نطاق', 'is_owner' => 0, 'branch_id' => null];
    $branchless403 = false;
    try {
        sale_receipt_payload($testDocId, false, $branchlessUser);
    } catch (SaleApiException $e) {
        $branchless403 = $e->http === 403;
    }
    assert_true($branchless403, "مستخدم branch_id=NULL ومن غير نطاق ممنوع ورجع 403");

    echo "\n✅ جميع حالات اختبار بريف ٢٤٤ نجحت بالكامل!\n";

} finally {
    // تنظيف البيانات التجريبية
    echo "\n-- تنظيف البيانات التجريبية --\n";
    if (!empty($cleanupQueueIds)) {
        $inQ = implode(',', $cleanupQueueIds);
        $pdo->exec("DELETE FROM print_queue WHERE id IN ($inQ)");
    }
    if (!empty($cleanupDocIds)) {
        $inD = implode(',', $cleanupDocIds);
        $pdo->exec("DELETE FROM sale_payments WHERE doc_id IN ($inD)");
        $pdo->exec("DELETE FROM sale_lines WHERE doc_id IN ($inD)");
        $pdo->exec("DELETE FROM sale_events WHERE doc_id IN ($inD)");
        $pdo->exec("DELETE FROM sale_docs WHERE id IN ($inD)");
    }
    if (!empty($cleanupSessionIds)) {
        $inS = implode(',', $cleanupSessionIds);
        $pdo->exec("DELETE FROM sale_sessions WHERE id IN ($inS)");
    }
    if (!empty($cleanupDeviceIds)) {
        $inDev = implode(',', $cleanupDeviceIds);
        $pdo->exec("DELETE FROM sale_devices WHERE id IN ($inDev)");
    }
    echo "تم تنظيف كل البيانات التجريبية.\n";
}
