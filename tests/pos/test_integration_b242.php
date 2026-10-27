<?php
/**
 * test_integration_b242.php — فحص تكاملي حي لعقد الاكتمال للبريف 242.
 * بيغطي الحالات 5، 6، 7، 8، 9، 10 بالاتصال الحي بقاعدة البيانات المحلية.
 * وينظف بعده تماماً كل الـ fixtures ويرجع الأرصدة والتسلسلات والباسوردات لحالتها.
 */
declare(strict_types=1);

$wwwRoot = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds2';
require_once $wwwRoot . '/config.php';
require_once $wwwRoot . '/stock_lib.php';
require_once $wwwRoot . '/sale_lib.php';

$pdo = db();

echo "=== بدء اختبار التكامل الحي للبريف 242 ===\n";

$createdSaleDocIds = [];
$createdStockDocIds = [];
$createdTokenIds = [];
$originalBalances = [];
$originalPasswordHash = null;
$testCustomerId = null;

if (!function_exists('can')) {
    function can(string $perm): bool {
        return !empty($GLOBALS['test_permissions']) && in_array($perm, $GLOBALS['test_permissions'], true);
    }
}
if (!function_exists('is_real_owner')) {
    function is_real_owner(): bool {
        return !empty($GLOBALS['test_is_owner']);
    }
}

function snapshotBalance(PDO $pdo, int $branchId, int $itemId): array {
    $st = $pdo->prepare("SELECT qty, avg_cost FROM stock_balances WHERE branch_id = ? AND item_id = ? AND is_transit = 0");
    $st->execute([$branchId, $itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['qty' => (float)$row['qty'], 'avg_cost' => (float)$row['avg_cost']] : ['qty' => 0.0, 'avg_cost' => 0.0];
}

function snapshotSeq(PDO $pdo, int $deviceId, int $docYear): ?int {
    $st = $pdo->prepare("SELECT last_no FROM sale_doc_seq WHERE device_id = ? AND doc_year = ?");
    $st->execute([$deviceId, $docYear]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['last_no'] : null;
}

// المستخدمين
$owner = $pdo->query("SELECT u.id, u.username, u.password_hash FROM users u JOIN roles r ON u.role_id = r.id WHERE r.is_owner = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashier = $pdo->query("SELECT u.id, u.username, u.password_hash FROM users u JOIN roles r ON u.role_id = r.id WHERE r.is_owner = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$ownerId = (int)$owner['id'];
$cashierId = (int)$cashier['id'];
$originalPasswordHash = $owner['password_hash'];

// تحديث مؤقت لباسورد owner للاختبار
$testPassword = 'TestPassword123!';
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
    ->execute([password_hash($testPassword, PASSWORD_DEFAULT), $ownerId]);

// إنشاء عميل تجريبي للاختبار
$pdo->exec("INSERT INTO customers (branch_id, code, name, phone, is_active, created_by, created_at) VALUES (1, 'TEST_B242', 'عميل اختبار ب242', '01000000000', 1, 1, NOW())");
$testCustomerId = (int)$pdo->lastInsertId();

$branch = $pdo->query("SELECT id, name FROM branches WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$branchId = (int)$branch['id'];
$devUuid = 'a21b6bbbacdbf968e4d9dc2ac2898072';

// جلب صنفين للاختبار
$items = $pdo->query("SELECT code, item_id as id, name as item_name FROM hq_codes WHERE item_id > 0 AND LENGTH(code) > 2 LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($items) < 2) {
    exit("❌ لا يوجد أصناف كافية في قاعدة البيانات للاختبار.\n");
}
$itemA = $items[0];
$itemB = $items[1];
$customerId = $testCustomerId;

$docYear = (int)date('Y');
$originalBalances[$itemA['id']] = snapshotBalance($pdo, $branchId, (int)$itemA['id']);
$originalBalances[$itemB['id']] = snapshotBalance($pdo, $branchId, (int)$itemB['id']);
$seqBefore = snapshotSeq($pdo, 1, $docYear);

$userOwner = ['id' => $ownerId, 'username' => (string)$owner['username'], 'full_name' => 'المالك', 'is_owner' => 1, 'branch_id' => null];
$userCashier = ['id' => $cashierId, 'username' => (string)$cashier['username'], 'full_name' => 'كاشير', 'is_owner' => 0, 'branch_id' => $branchId];

echo "الفرع: {$branch['name']} (ID: $branchId)\n";
echo "العميل: عميل اختبار ب242 (ID: $customerId)\n";
echo "الصنف أ: {$itemA['item_name']} (ID: {$itemA['id']}, Code: {$itemA['code']})\n";
echo "الصنف ب: {$itemB['item_name']} (ID: {$itemB['id']}, Code: {$itemB['code']})\n";

try {
    // -------------------------------------------------------------
    // اختبار Case 5: فاتورة عمرها 15 يوم تحتاج موافقة return_late
    // -------------------------------------------------------------
    echo "\n--- فحص Case 5: فاتورة مر عليها 15 يوم ---\n";
    $uuid5Sale = 'test-b242-case5-sale-' . bin2hex(random_bytes(4));
    $sale5 = sale_finalize($pdo, [
        'branch_id' => $branchId,
        'customer_id' => $customerId,
        'user_id' => $ownerId,
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid5Sale,
        'lines' => [
            [
                'code' => (string)$itemA['code'],
                'item_id' => (int)$itemA['id'],
                'qty' => 2,
                'unit_price' => 6.00,
                'discount_percent' => 0,
            ]
        ],
        'discount_total' => 0,
        'payments' => [
            ['method' => 'cash', 'amount' => 12.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $sale5['doc_id'];

    // الحصول على بيانات سطر الفاتورة
    $sale5Line = $pdo->query("SELECT * FROM sale_lines WHERE doc_id = {$sale5['doc_id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sale5LineId = (int)$sale5Line['id'];
    $ret5Amount = round((float)$sale5Line['net'] / (float)$sale5Line['qty'], 2);

    // نعدل تاريخ الفاتورة في القاعدة ليكون قبل 15 يوماً
    $pdo->prepare("UPDATE sale_docs SET completed_at = DATE_SUB(NOW(), INTERVAL 15 DAY), created_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE id = ?")
        ->execute([$sale5['doc_id']]);

    // doc_find للتأكد من is_late
    $found = sale_doc_find($pdo, ['device_uuid' => $devUuid, 'doc_id' => $sale5['doc_id']], $userOwner);
    if (empty($found['doc']['is_late'])) {
        throw new Exception("فشل: doc_find لم ترجع is_late = true لفاتورة عمرها 15 يوم.");
    }
    echo "  ✓ doc_find كشفت أن الفاتورة متأخرة (is_late = true, days_since = {$found['doc']['days_since']}).\n";

    // محاولة return_ref بدون توكن -> نتوقع 409
    $failedLate = false;
    try {
        sale_return_ref($pdo, [
            'device_uuid' => $devUuid,
            'client_uuid' => 'test-b242-case5-ret-fail-' . bin2hex(random_bytes(4)),
            'doc_id' => $sale5['doc_id'],
            'lines' => [
                ['ref_line_id' => $sale5LineId, 'qty' => 1]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => $ret5Amount]
            ],
        ], $userOwner);
    } catch (SaleApiException $e) {
        if ($e->http === 409 && (str_contains($e->getMessage(), 'مرتجع متأخر') || str_contains($e->getMessage(), 'أقدم من'))) {
            $failedLate = true;
            echo "  ✓ رفض المرتجع المتأخر بدون موافقة (409): {$e->getMessage()}\n";
        } else {
            throw $e;
        }
    }
    if (!$failedLate) {
        throw new Exception("فشل: المرتجع المتأخر مر بدون موافقة!");
    }

    // إصدار موافقة مشرف بنوع return_late
    $apprLate = sale_approve($pdo, [
        'device_uuid' => $devUuid,
        'approval_type' => 'return_late',
        'value' => 0,
        'username' => (string)$owner['username'],
        'password' => $testPassword,
    ], $userOwner);
    $tokLateRow = $pdo->query("SELECT id FROM sale_approval_tokens WHERE approval_type = 'return_late' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $tokLateId = (int)$tokLateRow['id'];
    $createdTokenIds[] = $tokLateId;
    $tokenLate = (string)$apprLate['approval_token'];
    echo "  ✓ تم إصدار توكن موافقة مرتجع متأخر بنجاح (Token: " . substr($tokenLate, 0, 10) . "...).\n";

    // تنفيذ المرتجع بالتوكن
    $uuid5Ret = 'test-b242-case5-ret-ok-' . bin2hex(random_bytes(4));
    $ret5 = sale_return_ref($pdo, [
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid5Ret,
        'doc_id' => $sale5['doc_id'],
        'approval_token' => $tokenLate,
        'lines' => [
            ['ref_line_id' => $sale5LineId, 'qty' => 1]
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => $ret5Amount]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $ret5['doc_id'];
    echo "  ✓ تم تنفيذ المرتجع المتأخر بالتوكن بنجاح (doc_id: {$ret5['doc_id']}).\n";

    // التحقق من استهلاك التوكن
    $tokRow = $pdo->prepare("SELECT used_at, used_doc_id FROM sale_approval_tokens WHERE id = ?");
    $tokRow->execute([$tokLateId]);
    $tokData = $tokRow->fetch(PDO::FETCH_ASSOC);
    if (empty($tokData['used_at']) || (int)$tokData['used_doc_id'] !== (int)$ret5['doc_id']) {
        throw new Exception("فشل: التوكن لم يتم تعليمه كمستهلك بالفاتورة.");
    }
    // التحقق من تسجيل الإيفنت
    $evCount = $pdo->prepare("SELECT COUNT(*) FROM sale_events WHERE doc_id = ? AND event_type = 'return_completed' AND approved_by IS NOT NULL");
    $evCount->execute([$ret5['doc_id']]);
    if ($evCount->fetchColumn() < 1) {
        throw new Exception("فشل: لم يتم تسجيل إيفنت return_completed مع الاعتماد.");
    }
    echo "  ✓ استهلاك التوكن وتسجيل إيفنت return_completed بنجاح.\n";

    // -------------------------------------------------------------
    // اختبار Case 6: فاتورة فيزا وترجع كاش تحتاج موافقة return_method
    // -------------------------------------------------------------
    echo "\n--- فحص Case 6: فاتورة فيزا واسترداد كاش ---\n";
    $uuid6Sale = 'test-b242-case6-sale-' . bin2hex(random_bytes(4));
    $sale6 = sale_finalize($pdo, [
        'branch_id' => $branchId,
        'customer_id' => $customerId,
        'user_id' => $ownerId,
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid6Sale,
        'lines' => [
            [
                'code' => (string)$itemA['code'],
                'item_id' => (int)$itemA['id'],
                'qty' => 1,
                'unit_price' => 6.00,
                'discount_percent' => 0,
            ]
        ],
        'discount_total' => 0,
        'payments' => [
            ['method' => 'visa', 'amount' => 6.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $sale6['doc_id'];

    $sale6Line = $pdo->query("SELECT id FROM sale_lines WHERE doc_id = {$sale6['doc_id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sale6LineId = (int)$sale6Line['id'];

    // محاولة return كاش بدون توكن -> نتوقع 403
    $failedMethod = false;
    try {
        sale_return_ref($pdo, [
            'device_uuid' => $devUuid,
            'client_uuid' => 'test-b242-case6-ret-fail-' . bin2hex(random_bytes(4)),
            'doc_id' => $sale6['doc_id'],
            'lines' => [
                ['ref_line_id' => $sale6LineId, 'qty' => 1]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 6.00]
            ],
        ], $userOwner);
    } catch (SaleApiException $e) {
        if ($e->http === 403 && (str_contains($e->getMessage(), 'طريقة الرد مختلفة') || str_contains($e->getMessage(), 'طريقة رد الفلوس مختلفة'))) {
            $failedMethod = true;
            echo "  ✓ رفض تغيير طريقة الدفع بدون موافقة (403): {$e->getMessage()}\n";
        } else {
            throw $e;
        }
    }
    if (!$failedMethod) {
        throw new Exception("فشل: المرتجع بطريقة مختلفة مر بدون موافقة!");
    }

    // إصدار موافقة مشرف بنوع return_method
    $apprMethod = sale_approve($pdo, [
        'device_uuid' => $devUuid,
        'approval_type' => 'return_method',
        'value' => 6.00,
        'username' => (string)$owner['username'],
        'password' => $testPassword,
    ], $userOwner);
    $tokMethodRow = $pdo->query("SELECT id FROM sale_approval_tokens WHERE approval_type = 'return_method' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $tokMethodId = (int)$tokMethodRow['id'];
    $createdTokenIds[] = $tokMethodId;
    $tokenMethod = (string)$apprMethod['approval_token'];
    echo "  ✓ تم إصدار توكن موافقة تغيير طريقة الرد بنجاح (Token: " . substr($tokenMethod, 0, 10) . "...).\n";

    // تنفيذ المرتجع بالتوكن
    $uuid6Ret = 'test-b242-case6-ret-ok-' . bin2hex(random_bytes(4));
    $ret6 = sale_return_ref($pdo, [
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid6Ret,
        'doc_id' => $sale6['doc_id'],
        'approval_token' => $tokenMethod,
        'lines' => [
            ['ref_line_id' => $sale6LineId, 'qty' => 1]
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 6.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $ret6['doc_id'];
    echo "  ✓ تم تنفيذ المرتجع بالكاش بالتوكن بنجاح (doc_id: {$ret6['doc_id']}).\n";

    // التحقق من استهلاك التوكن وتسجيل الإيفنت
    $tokRow = $pdo->prepare("SELECT used_at, used_doc_id FROM sale_approval_tokens WHERE id = ?");
    $tokRow->execute([$tokMethodId]);
    $tokData = $tokRow->fetch(PDO::FETCH_ASSOC);
    if (empty($tokData['used_at']) || (int)$tokData['used_doc_id'] !== (int)$ret6['doc_id']) {
        throw new Exception("فشل: توكن تغيير الطريقة لم يتم تعليمه كمستهلك بالفاتورة.");
    }
    $evCount = $pdo->prepare("SELECT COUNT(*) FROM sale_events WHERE doc_id = ? AND event_type = 'return_completed' AND approved_by IS NOT NULL");
    $evCount->execute([$ret6['doc_id']]);
    if ($evCount->fetchColumn() < 1) {
        throw new Exception("فشل: لم يتم تسجيل إيفنت return_completed مع الاعتماد.");
    }
    echo "  ✓ استهلاك توكن الطريقة وتسجيل إيفنت return_completed بنجاح.\n";

    // -------------------------------------------------------------
    // اختبار Case 7: صلاحية return_general
    // -------------------------------------------------------------
    echo "\n--- فحص Case 7: صلاحية return_general ---\n";
    // أولاً: بدون الصلاحية -> نتوقع 403
    $GLOBALS['test_is_owner'] = false;
    $GLOBALS['test_permissions'] = [];
    $failedGenPerm = false;
    try {
        sale_return_general($pdo, [
            'customer_id' => $customerId,
            'device_uuid' => $devUuid,
            'client_uuid' => 'test-b242-case7-ret-fail-' . bin2hex(random_bytes(4)),
            'reason' => 'مرتجع عام بدون صلاحية',
            'lines' => [
                ['code' => (string)$itemA['code'], 'item_id' => (int)$itemA['id'], 'qty' => 1, 'unit_price' => 6.00]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 6.00]
            ],
        ], $userCashier);
    } catch (SaleApiException $e) {
        if ($e->http === 403 && (str_contains($e->getMessage(), 'صلاحية') || str_contains($e->getMessage(), 'المرتجع العام'))) {
            $failedGenPerm = true;
            echo "  ✓ رفض المرتجع العام لمستخدم بدون الصلاحية (403): {$e->getMessage()}\n";
        } else {
            throw $e;
        }
    }
    if (!$failedGenPerm) {
        throw new Exception("فشل: المرتجع العام مر بدون صلاحية!");
    }

    // ثانياً: مع الصلاحية pos:return_general -> ينجح
    $GLOBALS['test_permissions'] = ['pos:return_general'];
    $uuid7Ret = 'test-b242-case7-ret-gen-' . bin2hex(random_bytes(4));
    $ret7 = sale_return_general($pdo, [
        'customer_id' => $customerId,
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid7Ret,
        'reason' => 'مرتجع عام بدون فاتورة تجريبي',
        'lines' => [
            ['code' => (string)$itemA['code'], 'item_id' => (int)$itemA['id'], 'qty' => 1, 'unit_price' => 6.00]
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 6.00]
        ],
    ], $userCashier);
    $createdSaleDocIds[] = $ret7['doc_id'];
    echo "  ✓ تنفيذ sale_return_general مع الصلاحية بنجاح (doc_id: {$ret7['doc_id']}).\n";

    // إعادة وضع الأونر لبقية الاختبارات
    $GLOBALS['test_is_owner'] = true;
    $GLOBALS['test_permissions'] = [];

    // -------------------------------------------------------------
    // اختبار Case 8: الاستبدال (Exchange) - مرتجع 12 + بيع 66.25 -> دفع 54.25
    // -------------------------------------------------------------
    echo "\n--- فحص Case 8: الاستبدال (Exchange) ---\n";
    $uuid8Orig = 'test-b242-case8-orig-' . bin2hex(random_bytes(4));
    $sale8Orig = sale_finalize($pdo, [
        'branch_id' => $branchId,
        'customer_id' => $customerId,
        'user_id' => $ownerId,
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid8Orig,
        'lines' => [
            [
                'code' => (string)$itemA['code'],
                'item_id' => (int)$itemA['id'],
                'qty' => 2,
                'unit_price' => 6.00,
                'discount_percent' => 0,
            ]
        ],
        'discount_total' => 0,
        'payments' => [
            ['method' => 'cash', 'amount' => 12.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $sale8Orig['doc_id'];

    $sale8Line = $pdo->query("SELECT id FROM sale_lines WHERE doc_id = {$sale8Orig['doc_id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sale8LineId = (int)$sale8Line['id'];

    // تنفيذ عملية الاستبدال:
    $expectedDiff = round(66.25 - 12.00, 2);
    $uuid8Ex = 'test-b242-case8-ex-' . bin2hex(random_bytes(4));
    $exResult = sale_exchange($pdo, [
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid8Ex,
        'return' => [
            'doc_id' => $sale8Orig['doc_id'],
            'lines' => [
                ['ref_line_id' => $sale8LineId, 'qty' => 2]
            ],
        ],
        'sale' => [
            'customer_id' => $customerId,
            'lines' => [
                [
                    'code' => (string)$itemB['code'],
                    'qty' => 1,
                    'unit_price' => 66.25,
                    'discount_percent' => 0,
                ]
            ],
            'discount_total' => 0,
            'payments' => [
                ['method' => 'cash', 'amount' => $expectedDiff]
            ],
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $exResult['return_doc_id'];
    $createdSaleDocIds[] = $exResult['sale_doc_id'];

    if ($exResult['difference'] != $expectedDiff) {
        throw new Exception("فشل: فرق الاستبدال المحسوب ليس {$expectedDiff} ج (الناتج: {$exResult['difference']}).");
    }
    // التحقق من ربط المستندين: مستند البيع يشير لمستند المرتجع في ref_doc_id
    $saleDocRef = (int)$pdo->query("SELECT ref_doc_id FROM sale_docs WHERE id = {$exResult['sale_doc_id']}")->fetchColumn();
    if ($saleDocRef !== (int)$exResult['return_doc_id']) {
        throw new Exception("فشل: مستند البيع لا يشير لمستند المرتجع في ref_doc_id.");
    }
    echo "  ✓ الاستبدال أنشأ 2 docs متقابلين (مرتجع #{$exResult['return_doc_id']} وبيع #{$exResult['sale_doc_id']}) بفرق {$expectedDiff} ج مسدد نقداً.\n";

    // -------------------------------------------------------------
    // اختبار Case 9: تكرار client_uuid (Idempotency)
    // -------------------------------------------------------------
    echo "\n--- فحص Case 9: تكرار الـ client_uuid (Idempotency) ---\n";
    $linesBeforeCount = (int)$pdo->query("SELECT COUNT(*) FROM sale_lines")->fetchColumn();
    $exRepeat = sale_exchange($pdo, [
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid8Ex,
        'return' => [
            'doc_id' => $sale8Orig['doc_id'],
            'lines' => [
                ['ref_line_id' => $sale8LineId, 'qty' => 2]
            ],
        ],
        'sale' => [
            'customer_id' => $customerId,
            'lines' => [
                [
                    'code' => (string)$itemB['code'],
                    'qty' => 1,
                    'unit_price' => 66.25,
                    'discount_percent' => 0,
                ]
            ],
            'discount_total' => 0,
            'payments' => [
                ['method' => 'cash', 'amount' => $expectedDiff]
            ],
        ],
    ], $userOwner);
    $linesAfterCount = (int)$pdo->query("SELECT COUNT(*) FROM sale_lines")->fetchColumn();
    if ($exRepeat['sale_doc_id'] !== $exResult['sale_doc_id'] || $exRepeat['return_doc_id'] !== $exResult['return_doc_id']) {
        throw new Exception("فشل: تكرار UUID رجع doc_id مختلف.");
    }
    if ($linesAfterCount !== $linesBeforeCount) {
        throw new Exception("فشل: تكرار UUID أضاف سطور جديدة في sale_lines!");
    }
    echo "  ✓ تكرار client_uuid رجع نفس المستندات بدون إضافة أي سطور مكررة.\n";

    // -------------------------------------------------------------
    // اختبار Case 10: رصيد المخزن ومتوسط التكلفة بعد مرتجع 3 قطع
    // -------------------------------------------------------------
    echo "\n--- فحص Case 10: رصيد المخزن ومتوسط التكلفة ---\n";
    $uuid10Sale = 'test-b242-case10-sale-' . bin2hex(random_bytes(4));
    $sale10 = sale_finalize($pdo, [
        'branch_id' => $branchId,
        'customer_id' => $customerId,
        'user_id' => $ownerId,
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid10Sale,
        'lines' => [
            [
                'code' => (string)$itemA['code'],
                'item_id' => (int)$itemA['id'],
                'qty' => 3,
                'unit_price' => 6.00,
                'discount_percent' => 0,
            ]
        ],
        'discount_total' => 0,
        'payments' => [
            ['method' => 'cash', 'amount' => 18.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $sale10['doc_id'];

    $sale10Line = $pdo->query("SELECT * FROM sale_lines WHERE doc_id = {$sale10['doc_id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sale10LineId = (int)$sale10Line['id'];

    $balAfterSale = snapshotBalance($pdo, $branchId, (int)$itemA['id']);
    // الآن إرجاع الـ 3 قطع بالكامل
    $uuid10Ret = 'test-b242-case10-ret-' . bin2hex(random_bytes(4));
    $ret10 = sale_return_ref($pdo, [
        'device_uuid' => $devUuid,
        'client_uuid' => $uuid10Ret,
        'doc_id' => $sale10['doc_id'],
        'lines' => [
            ['ref_line_id' => $sale10LineId, 'qty' => 3]
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 18.00]
        ],
    ], $userOwner);
    $createdSaleDocIds[] = $ret10['doc_id'];

    $balAfterReturn = snapshotBalance($pdo, $branchId, (int)$itemA['id']);
    $unitFactor = (float)($sale10Line['unit_factor'] ?? 1.0);
    $expectedStockDiff = round(3 * $unitFactor, 4);
    $diffQty = round((float)$balAfterReturn['qty'] - (float)$balAfterSale['qty'], 4);
    if ($diffQty != $expectedStockDiff) {
        throw new Exception("فشل: رصيد المخزن لم يزد بـ {$expectedStockDiff} قطعة بالضبط بعد المرتجع (الزيادة: $diffQty).");
    }
    echo "  ✓ رصيد المخزن بعد مرتجع 3 وحدات (معامل تحويل {$unitFactor}) زاد بـ {$diffQty} قطعة تماماً (من {$balAfterSale['qty']} إلى {$balAfterReturn['qty']}).\n";

    echo "\n============================================\n";
    echo "🎉 نجحت جميع اختبارات التكامل الحية (حالات 5، 6، 7، 8، 9، 10)!\n";
    echo "============================================\n";

} catch (Throwable $e) {
    echo "\n❌ حدث خطأ أثناء الاختبار: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    // -------------------------------------------------------------
    // تنظيف كل الفيكستشرز واستعادة الحالة
    // -------------------------------------------------------------
    echo "\n--- جاري تنظيف جميع البيانات التجريبية والاستعادة ---\n";

    // 1. استخراج كل stock_docs المرتبطة بمستندات البيع التي تم إنشاؤها
    if (!empty($createdSaleDocIds)) {
        $inSaleIds = implode(',', array_map('intval', $createdSaleDocIds));
        $stkIds = $pdo->query("SELECT DISTINCT stock_doc_id FROM sale_docs WHERE id IN ($inSaleIds) AND stock_doc_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($stkIds as $sid) {
            if ($sid) $createdStockDocIds[] = (int)$sid;
        }

        $pdo->exec("DELETE FROM sale_events WHERE doc_id IN ($inSaleIds)");
        $pdo->exec("DELETE FROM sale_payments WHERE doc_id IN ($inSaleIds)");
        $pdo->exec("DELETE FROM sale_lines WHERE doc_id IN ($inSaleIds)");
        $pdo->exec("DELETE FROM sale_docs WHERE id IN ($inSaleIds)");
        echo "  ✓ تم حذف مستندات البيع والمرتجع وسطورها ودفعاتها وأحداثها: [$inSaleIds]\n";
    }

    if (!empty($createdTokenIds)) {
        $inTokIds = implode(',', array_map('intval', $createdTokenIds));
        $pdo->exec("DELETE FROM sale_approval_tokens WHERE id IN ($inTokIds)");
        echo "  ✓ تم حذف توكنات الموافقات: [$inTokIds]\n";
    }

    // 2. حذف مستندات وحركات المخزن
    if (!empty($createdStockDocIds)) {
        $inStockIds = implode(',', array_map('intval', array_unique($createdStockDocIds)));
        $pdo->exec("DELETE FROM stock_moves WHERE doc_id IN ($inStockIds)");
        $pdo->exec("DELETE FROM stock_doc_lines WHERE doc_id IN ($inStockIds)");
        $pdo->exec("DELETE FROM stock_docs WHERE id IN ($inStockIds)");
        echo "  ✓ تم حذف مستندات وحركات المخزن: [$inStockIds]\n";
    }

    // 3. استعادة أرصدة المخزن الأصلية بدقة
    foreach ($originalBalances as $itmId => $ob) {
        $stUp = $pdo->prepare("INSERT INTO stock_balances (branch_id, item_id, is_transit, qty, avg_cost, updated_at) 
            VALUES (?, ?, 0, ?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), avg_cost = VALUES(avg_cost), updated_at = NOW()");
        $stUp->execute([$branchId, $itmId, $ob['qty'], $ob['avg_cost']]);
    }
    echo "  ✓ تم استعادة أرصدة الأصناف بدقة للأصل: " . json_encode($originalBalances) . "\n";

    // 4. استعادة تسلسل sale_doc_seq
    if ($seqBefore !== null) {
        $pdo->prepare("UPDATE sale_doc_seq SET last_no = ? WHERE device_id = 1 AND doc_year = ?")
            ->execute([$seqBefore, $docYear]);
        echo "  ✓ تم استعادة أرقام التسلسل (device_id: 1, last_no: $seqBefore).\n";
    }

    // 5. استعادة باسورد المستخدم المشرف
    if ($originalPasswordHash !== null) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$originalPasswordHash, $ownerId]);
        echo "  ✓ تم استعادة كلمة سر المستخدم المشرف للأصل.\n";
    }

    // 6. حذف العميل التجريبي
    if ($testCustomerId) {
        $pdo->exec("DELETE FROM customers WHERE id = $testCustomerId");
        echo "  ✓ تم حذف العميل التجريبي (ID: $testCustomerId).\n";
    }

    echo "✅ تم التنظيف بالكامل بنجاح وأصبحت القاعدة نظيفة تماماً.\n";
}
