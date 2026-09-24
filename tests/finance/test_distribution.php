<?php
/**
 * test_distribution.php — اختبارات آلية لمنطق التوزيع وحارس رصيد الإدارة (بريف ٥٥)
 *
 * بيشتغل على قاعدة Laragon المحلية.
 * بيوثّق السلوك الفعلي لدوال التوزيع:
 *   - set_branch_distribution() في receiving_lib.php
 *   - group_set_distribution() في group_lib.php
 *
 * كل الداتا المدخلة بـ id >= 990000 وأكواد TEST55-… وجوّه transaction بيترجع في الآخر في finally.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$laragonWww = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
if (!file_exists($laragonWww . '/config.php')) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/config.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/receiving_lib.php';
require_once $laragonWww . '/group_lib.php';

$passCount = 0;
$failCount = 0;

function num_eq(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

function assert_case(string $name, bool $cond, string $details = ''): void
{
    global $passCount, $failCount;
    if ($cond) {
        echo "✅ {$name}\n";
        $passCount++;
    } else {
        echo "❌ {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
        $failCount++;
    }
}

$pdo->beginTransaction();

try {
    // -----------------------------------------------------------------
    // تجهيز البيانات الأساسية للاختبار
    // -----------------------------------------------------------------
    $distId1 = 990001;
    $distId2 = 990002;
    $itemId1 = 990001;
    $code1   = 'TEST55-CODE1';

    // فاتورة وهمية 1 (كمية الصنف فيها 12)
    $pdo->prepare(
        "INSERT INTO invoice_distribution (id, invoice_no, supplier_id, dist_date, total_amount, notes, created_by, company_branch, doc_type, hq_guard_off)
         VALUES (?, 'INV-TEST-5501', 1, '2026-09-24', 1200.0, 'فاتورة اختبار توزيع 1', 1, 1, 1, 0)"
    )->execute([$distId1]);

    // صنف في hq_codes
    $pdo->prepare(
        "INSERT INTO hq_codes (code, item_id, name) VALUES (?, ?, 'صنف اختبار ٥٥-١')"
    )->execute([$code1, $itemId1]);

    // رصيد الإدارة في hq_balances = 10
    $pdo->prepare(
        "INSERT INTO hq_balances (item_id, sto_id, balance) VALUES (?, " . ADMIN_STO_ID . ", 10.0)"
    )->execute([$itemId1]);

    // التحقق من أن hq_admin_balance_for_code قرأت الرصيد 10
    $balCheck = hq_admin_balance_for_code($code1);
    assert_case('٥٥٠١ — قراءة رصيد الإدارة الأولي للصنف', $balCheck !== null && num_eq($balCheck, 10.0), "الرصيد المقروء: " . var_export($balCheck, true));

    // -----------------------------------------------------------------
    // 1) رصيد 10: فرع 3 طلب 6 ✅
    // -----------------------------------------------------------------
    $res1 = set_branch_distribution($distId1, $code1, 12.0, 3, 6.0, 1);
    assert_case('٥٥٠٢ — توزيع 6 لفرع 3 مع رصيد إدارة 10 وكمية فاتورة 12', num_eq((float)$res1['branch_total'], 6.0) && num_eq((float)$res1['remaining'], 6.0));

    // -----------------------------------------------------------------
    // 2) فرع 4 طلب 5 (المجموع 11 > 10) ❌ مع رسالة «رصيد الإدارة»
    // -----------------------------------------------------------------
    $exThrown = false;
    $exMsg = '';
    try {
        set_branch_distribution($distId1, $code1, 12.0, 4, 5.0, 1);
    } catch (Throwable $ex) {
        $exThrown = true;
        $exMsg = $ex->getMessage();
    }
    assert_case(
        '٥٥٠٣ — رفض زيادة تتجاوز رصيد الإدارة (11 > 10) والرسالة فيها رصيد الإدارة',
        $exThrown && str_contains($exMsg, 'رصيد الإدارة'),
        "الرسالة: {$exMsg}"
    );

    // -----------------------------------------------------------------
    // 3) فرع 4 طلب 4 (المجموع 6 + 4 = 10 <= 10) ✅
    // -----------------------------------------------------------------
    $res2 = set_branch_distribution($distId1, $code1, 12.0, 4, 4.0, 1);
    assert_case('٥٥٠٤ — توزيع 4 لفرع 4 (المجموع 10 = رصيد الإدارة)', num_eq((float)$res2['branch_total'], 4.0) && num_eq((float)$res2['remaining'], 2.0));

    // -----------------------------------------------------------------
    // 4) idempotent: نفس النداء مرتين بنفس الكمية
    // -----------------------------------------------------------------
    $res3 = set_branch_distribution($distId1, $code1, 12.0, 4, 4.0, 1);
    $stLines = $pdo->prepare("SELECT COUNT(*), SUM(amount) FROM invoice_distribution_lines WHERE distribution_id = ? AND notes = ? AND branch_id = ?");
    $stLines->execute([$distId1, $code1, 4]);
    [$lineCount, $lineSum] = $stLines->fetch(PDO::FETCH_NUM);

    $stAll = $pdo->prepare("SELECT SUM(amount) FROM invoice_distribution_lines WHERE distribution_id = ? AND notes = ?");
    $stAll->execute([$distId1, $code1]);
    $totalDistributed = (float) $stAll->fetchColumn();

    $okIdempotent = ((int)$lineCount === 1) && num_eq((float)$lineSum, 4.0) && num_eq($totalDistributed, 10.0);
    assert_case('٥٥٠٥ — idempotent: تكرار نفس النداء لا يضاعف السطور أو المجموع', $okIdempotent, "عدد السطور: {$lineCount}، مجموع الفرع: {$lineSum}، إجمالي الموزع: {$totalDistributed}");

    // -----------------------------------------------------------------
    // 5) تقليل لفرع (فرع 3 من 6 إلى 2) ✅
    // -----------------------------------------------------------------
    $resDown = set_branch_distribution($distId1, $code1, 12.0, 3, 2.0, 1);
    $stAll->execute([$distId1, $code1]);
    $totalAfterDown = (float) $stAll->fetchColumn();
    assert_case('٥٥٠٦ — تقليل كمية فرع 3 من 6 إلى 2', num_eq((float)$resDown['branch_total'], 2.0) && num_eq($totalAfterDown, 6.0));

    // -----------------------------------------------------------------
    // 6) تصفير فرع (فرع 4 من 4 إلى 0) ✅
    // -----------------------------------------------------------------
    $resZero = set_branch_distribution($distId1, $code1, 12.0, 4, 0.0, 1);
    $stAll->execute([$distId1, $code1]);
    $totalAfterZero = (float) $stAll->fetchColumn();
    assert_case('٥٥٠٧ — تصفير كمية فرع 4 (من 4 إلى 0)', num_eq((float)$resZero['branch_total'], 0.0) && num_eq($totalAfterZero, 2.0));

    // -----------------------------------------------------------------
    // 7) رصيد مش واصل (null): زيادة فوق المحفوظ ❌ · تقليل ✅
    // -----------------------------------------------------------------
    $codeNull = 'TEST55-NULL1';
    // لا ندخل الصنف في hq_codes فيرجع hq_admin_balance_for_code null
    assert_case('٥٥٠٨ — تحقق أن الصنف غير المسجل رصيده null', hq_admin_balance_for_code($codeNull) === null);

    // نضع سطراً محفوظاً برصيد 3 لفرع 3
    $pdo->prepare(
        "INSERT INTO invoice_distribution_lines (distribution_id, branch_id, amount, notes) VALUES (?, 3, 3.0, ?)"
    )->execute([$distId1, $codeNull]);

    // زيادة فوق المحفوظ (من 3 إلى 4) ❌
    $nullUpFailed = false;
    $nullUpMsg = '';
    try {
        set_branch_distribution($distId1, $codeNull, 12.0, 3, 4.0, 1);
    } catch (Throwable $ex) {
        $nullUpFailed = true;
        $nullUpMsg = $ex->getMessage();
    }
    assert_case('٥٥٠٩ — رصيد مش واصل: زيادة فوق المحفوظ مرفوضة', $nullUpFailed && str_contains($nullUpMsg, 'مش واصل'), "الرسالة: {$nullUpMsg}");

    // تقليل (من 3 إلى 1) ✅
    $resNullDown = set_branch_distribution($distId1, $codeNull, 12.0, 3, 1.0, 1);
    assert_case('٥٥١٠ — رصيد مش واصل: تقليل الكمية مسموح (3 إلى 1)', num_eq((float)$resNullDown['branch_total'], 1.0));

    // تصفير (من 1 إلى 0) ✅
    $resNullZero = set_branch_distribution($distId1, $codeNull, 12.0, 3, 0.0, 1);
    assert_case('٥٥١١ — رصيد مش واصل: تصفير الكمية مسموح', num_eq((float)$resNullZero['branch_total'], 0.0));

    // -----------------------------------------------------------------
    // 8) رصيد سالب (-3): أي زيادة ❌ (السالب = صفر)
    // -----------------------------------------------------------------
    $codeNeg = 'TEST55-NEG1';
    $itemIdNeg = 990003;
    $pdo->prepare("INSERT INTO hq_codes (code, item_id, name) VALUES (?, ?, 'صنف سالب ٥٥')")->execute([$codeNeg, $itemIdNeg]);
    $pdo->prepare("INSERT INTO hq_balances (item_id, sto_id, balance) VALUES (?, " . ADMIN_STO_ID . ", -3.0)")->execute([$itemIdNeg]);

    $negFailed = false;
    $negMsg = '';
    try {
        set_branch_distribution($distId1, $codeNeg, 12.0, 3, 1.0, 1);
    } catch (Throwable $ex) {
        $negFailed = true;
        $negMsg = $ex->getMessage();
    }
    assert_case('٥٥١٢ — رصيد سالب (-3): زيادة لـ 1 مرفوضة (السالب يعامل كصفر)', $negFailed, "الرسالة: {$negMsg}");

    // -----------------------------------------------------------------
    // 9) فك الحارس (hq_guard_off = 1):
    //    - رصيد 0: توزيع 12 ✅ · 13 (أكبر من الفاتورة) ❌
    //    - رصيد null: زيادة لحد 12 ✅
    //    - رجّع الحارس (0) ← نفس النداء اللي نجح يرفض تاني
    // -----------------------------------------------------------------
    $codeGuard = 'TEST55-GUARD1';
    $itemIdGuard = 990004;
    $pdo->prepare("INSERT INTO hq_codes (code, item_id, name) VALUES (?, ?, 'صنف حارس ٥٥')")->execute([$codeGuard, $itemIdGuard]);
    $pdo->prepare("INSERT INTO hq_balances (item_id, sto_id, balance) VALUES (?, " . ADMIN_STO_ID . ", 0.0)")->execute([$itemIdGuard]);

    // والحارس شغال: توزيع 12 يرفض لأن الرصيد 0
    $guardOnBlocked = false;
    try {
        set_branch_distribution($distId1, $codeGuard, 12.0, 3, 12.0, 1);
    } catch (Throwable) {
        $guardOnBlocked = true;
    }
    assert_case('٥٥١٣ — الحارس شغال ورصيد الإدارة 0: توزيع 12 مرفوض', $guardOnBlocked);

    // فك الحارس
    $pdo->prepare("UPDATE invoice_distribution SET hq_guard_off = 1 WHERE id = ?")->execute([$distId1]);

    // رصيد 0 مع فك الحارس: توزيع 12 ✅
    $resGuardOff12 = set_branch_distribution($distId1, $codeGuard, 12.0, 3, 12.0, 1);
    assert_case('٥٥١٤ — فك الحارس: توزيع كمية الفاتورة كاملة (12) ينجح حتى مع رصيد إدارة 0', num_eq((float)$resGuardOff12['branch_total'], 12.0));

    // توزيع 13 (أكبر من كمية الفاتورة 12) ❌
    $guardOff13Blocked = false;
    $guardOff13Msg = '';
    try {
        set_branch_distribution($distId1, $codeGuard, 12.0, 3, 13.0, 1);
    } catch (Throwable $ex) {
        $guardOff13Blocked = true;
        $guardOff13Msg = $ex->getMessage();
    }
    assert_case('٥٥١٥ — فك الحارس: توزيع 13 يتجاوز كمية الفاتورة مرفوض', $guardOff13Blocked, "الرسالة: {$guardOff13Msg}");

    // رصيد null مع فك الحارس: زيادة لحد 12 ✅
    $codeGuardNull = 'TEST55-GNULL1';
    $resGNull = set_branch_distribution($distId1, $codeGuardNull, 12.0, 4, 12.0, 1);
    assert_case('٥٥١٦ — فك الحارس مع رصيد null: زيادة لحد كمية الفاتورة 12 تنجح', num_eq((float)$resGNull['branch_total'], 12.0));

    // تصفير الصنف قبل إرجاع الحارس
    set_branch_distribution($distId1, $codeGuard, 12.0, 3, 0.0, 1);

    // رجّع الحارس (0)
    $pdo->prepare("UPDATE invoice_distribution SET hq_guard_off = 0 WHERE id = ?")->execute([$distId1]);

    // إعادة نفس النداء (توزيع 12) يرفض ثانية ❌
    $guardRestoreBlocked = false;
    try {
        set_branch_distribution($distId1, $codeGuard, 12.0, 3, 12.0, 1);
    } catch (Throwable) {
        $guardRestoreBlocked = true;
    }
    assert_case('٥٥١٧ — إرجاع الحارس: إعادة توزيع 12 مع رصيد 0 ترفض ثانية', $guardRestoreBlocked);

    // -----------------------------------------------------------------
    // 10) المجموعة (group_set_distribution):
    //     فاتورتين فيهم نفس الصنف (كل فاتورة 6 = إجمالي المجموعة 12).
    //     رصيد الإدارة 10.
    // -----------------------------------------------------------------
    $pdo->prepare(
        "INSERT INTO invoice_distribution (id, invoice_no, supplier_id, dist_date, total_amount, notes, created_by, company_branch, doc_type, hq_guard_off)
         VALUES (?, 'INV-TEST-5502', 1, '2026-09-24', 600.0, 'فاتورة اختبار توزيع 2', 1, 1, 1, 0)"
    )->execute([$distId2]);

    $codeGrp = 'TEST55-GRP1';
    $itemIdGrp = 990005;
    $pdo->prepare("INSERT INTO hq_codes (code, item_id, name) VALUES (?, ?, 'صنف مجموعة ٥٥')")->execute([$codeGrp, $itemIdGrp]);
    $pdo->prepare("INSERT INTO hq_balances (item_id, sto_id, balance) VALUES (?, " . ADMIN_STO_ID . ", 10.0)")->execute([$itemIdGrp]);

    $grpSources = [
        ['dist_id' => $distId1, 'qty' => 6.0, 'invoice_no' => 'INV-TEST-5501'],
        ['dist_id' => $distId2, 'qty' => 6.0, 'invoice_no' => 'INV-TEST-5502'],
    ];

    // طلب 11 > 10 (رصيد الإدارة) ❌
    $grpExThrown = false;
    $grpExMsg = '';
    try {
        group_set_distribution($grpSources, $codeGrp, 3, 11.0, 1);
    } catch (Throwable $ex) {
        $grpExThrown = true;
        $grpExMsg = $ex->getMessage();
    }
    assert_case('٥٥١٨ — مجموعة: طلب 11 مع رصيد إدارة 10 مرفوض والرسالة فيها رصيد الإدارة', $grpExThrown && str_contains($grpExMsg, 'رصيد الإدارة'), "الرسالة: {$grpExMsg}");

    // طلب 10 <= 10 ✅
    $resGrp10 = group_set_distribution($grpSources, $codeGrp, 3, 10.0, 1);
    assert_case('٥٥١٩ — مجموعة: توزيع 10 لفرع 3 ينجح بنظام الشلال', num_eq((float)$resGrp10['branch_total'], 10.0) && num_eq((float)$resGrp10['remaining'], 2.0));

    // فك الحارس عن فاتورة واحدة من الفاتورتين: distId1 مفكوكة و distId2 مربوطة
    // القاعدة: «فاتورة واحدة متفكوكة = الصنف متفكوك»
    $pdo->prepare("UPDATE invoice_distribution SET hq_guard_off = 1 WHERE id = ?")->execute([$distId1]);
    $pdo->prepare("UPDATE invoice_distribution SET hq_guard_off = 0 WHERE id = ?")->execute([$distId2]);

    // طلب 12 (إجمالي كمية المجموعة) ✅
    $resGrpGuardOff12 = group_set_distribution($grpSources, $codeGrp, 3, 12.0, 1);
    assert_case('٥٥٢٠ — مجموعة: فك حارس فاتورة واحدة يسمح بتوزيع كمية المجموعة كاملة (12)', num_eq((float)$resGrpGuardOff12['branch_total'], 12.0));

    // طلب 13 (أكبر من إجمالي كمية المجموعة 12) ❌
    $grp13Blocked = false;
    try {
        group_set_distribution($grpSources, $codeGrp, 3, 13.0, 1);
    } catch (Throwable) {
        $grp13Blocked = true;
    }
    assert_case('٥٥٢١ — مجموعة: توزيع 13 أكبر من إجمالي كمية المجموعة مرفوض حتى مع فك الحارس', $grp13Blocked);

    // تصفير الصنف بالمجموعة
    group_set_distribution($grpSources, $codeGrp, 3, 0.0, 1);

    // رجّع الحارس للفاتورة الأولى
    $pdo->prepare("UPDATE invoice_distribution SET hq_guard_off = 0 WHERE id = ?")->execute([$distId1]);

    // طلب 12 يرفض ثانية ❌
    $grpRestoreBlocked = false;
    try {
        group_set_distribution($grpSources, $codeGrp, 3, 12.0, 1);
    } catch (Throwable) {
        $grpRestoreBlocked = true;
    }
    assert_case('٥٥٢٢ — مجموعة: إرجاع الحارس يرفض توزيع 12 مع رصيد 10', $grpRestoreBlocked);

} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n";
if ($failCount === 0) {
    echo "✅ كل اختبارات التوزيع وحارس الرصيد نجحت ({$passCount})\n";
    exit(0);
} else {
    echo "❌ فشل {$failCount} من أصل " . ($passCount + $failCount) . " اختبار\n";
    exit(1);
}
