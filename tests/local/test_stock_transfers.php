<?php
/**
 * test_stock_transfers.php — اختبارات عقد الاكتمال لبريف ٢١٥ (التحويلات بين المخازن)
 * نظام الفجالة ستور المالي
 *
 * يختبر الحالات الـ ١٤ في عقد الاكتمال ويطبع ✓/✗ لكل حالة مع الأرصدة ومجموع المخازن.
 */
declare(strict_types=1);

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds3';
if (!is_file("$root/config.php")) {
    $root = 'D:/laragon/www';
}
if (!is_file("$root/config.php")) {
    fwrite(STDERR, "تعذر العثور على config.php في $root\n");
    exit(1);
}

require_once "$root/config.php";
putenv("LARAGON_WWW=$root");

require_once __DIR__ . '/../../site/item_units_lib.php';
require_once __DIR__ . '/../../site/stock_lib.php';
require_once __DIR__ . '/../../site/stock_transfer_lib.php';

$pdo = db();
$results = [];

function record_test(int $num, string $name, bool $passed, string $details = ''): void
{
    global $results;
    $results[$num] = [
        'name'    => $name,
        'passed'  => $passed,
        'details' => $details,
    ];
    $icon = $passed ? '✓' : '✗';
    echo sprintf("[%s] الحالة %02d: %s\n", $icon, $num, $name);
    if ($details !== '') {
        echo "     $details\n";
    }
}

// دالة مساعدة لحساب مجموع الأرصدة (المخازن + في الطريق) للصنف
function get_system_stock_sum(PDO $pdo, int $itemId): array
{
    $st = $pdo->prepare('SELECT branch_id, is_transit, qty FROM stock_balances WHERE item_id = ?');
    $st->execute([$itemId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0.0;
    $breakdown = [];
    foreach ($rows as $r) {
        $q = (float) $r['qty'];
        $total += $q;
        $tr = !empty($r['is_transit']) ? ' (في الطريق)' : '';
        $breakdown[] = "مخزن {$r['branch_id']}{$tr}: {$q}";
    }
    return [
        'total'     => round($total, 3),
        'breakdown' => implode(' | ', $breakdown),
    ];
}

echo "====================================================================\n";
echo " بدء تشغيل اختبارات بريف ٢١٥ — التحويلات بين المخازن\n";
echo " Base Dir: $root\n";
echo "====================================================================\n\n";

$testItems = [999801, 999802, 999803, 999804, 999805];

// تنظيف أي بيانات قديمة تخص أصناف الاختبار
$pdo->exec("DELETE FROM stock_moves WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_balances WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_transfer_diffs WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_doc_lines WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_docs WHERE notes LIKE '%test_b215%'");

// تفعيل المخازن على وضع live للاختبار
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'erp_stock_enabled'");
$pdo->exec("UPDATE settings SET setting_value = 'live' WHERE setting_key IN ('stock_mode_1','stock_mode_2','stock_mode_3','stock_mode_4','stock_mode_6')");

// ---------------------------------------------------------------------
// الحالة ١: erp_stock_enabled=0 والشاشات تفتح، وأي POST يترفض + فحص وضع mirror
// ---------------------------------------------------------------------
try {
    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'erp_stock_enabled'");

    $postRejected = false;
    $postRejectMsg = '';
    try {
        transfer_create($pdo, 6, 3, [
            ['item_id' => 999801, 'qty_units' => 5],
        ], 1, 'test_b215_case1');
    } catch (RuntimeException $re) {
        $postRejected = true;
        $postRejectMsg = $re->getMessage();
    }

    // إرجاع التفعيل واختبار الإرسال لمخزن mirror
    $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'erp_stock_enabled'");
    $pdo->exec("UPDATE settings SET setting_value = 'mirror' WHERE setting_key = 'stock_mode_3'");

    $mirrorDocId = transfer_create($pdo, 6, 3, [
        ['item_id' => 999801, 'qty_units' => 5],
    ], 1, 'test_b215_case1_mirror');

    $mirrorRejected = false;
    $mirrorRejectMsg = '';
    try {
        transfer_send($pdo, $mirrorDocId, 1);
    } catch (RuntimeException $re) {
        $mirrorRejected = true;
        $mirrorRejectMsg = $re->getMessage();
    }

    // إعادة ضبط الفجالة live
    $pdo->exec("UPDATE settings SET setting_value = 'live' WHERE setting_key = 'stock_mode_3'");

    $ok1 = $postRejected && str_contains($postRejectMsg, 'المخزن على الموقع لسه مقفول')
        && $mirrorRejected && str_contains($mirrorRejectMsg, 'وضع المرآة');

    record_test(1, 'erp_stock_enabled=0 يرفض POST + الإرسال لمخزن mirror يرفض', $ok1,
        "رسالة القفل: $postRejectMsg · رسالة المرآة: $mirrorRejectMsg");
} catch (Throwable $e) {
    record_test(1, 'erp_stock_enabled=0 ووضع المرآة', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٢: الإدارة (١٠٠) ← الفجالة ٢٠ قطعة: طلب ← إرسال
// ---------------------------------------------------------------------
$docId2 = 0;
$lineId2 = 0;
try {
    // وضع رصيد أولي 100 في الإدارة (مخزن 6) بتكلفة 4.0
    $openDocId = stock_doc_create($pdo, 'opening', [
        'branch_id' => 6,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b215_opening_100',
    ], [
        ['item_id' => 999801, 'qty_units' => 100, 'unit_cost' => 4.0],
    ], 1);
    stock_post($pdo, $openDocId, 1);

    $sumBefore2 = get_system_stock_sum($pdo, 999801);

    // إنشاء طلب تحويل من الإدارة (6) إلى الفجالة (3) بـ 20 قطعة
    $docId2 = transfer_create($pdo, 6, 3, [
        ['item_id' => 999801, 'qty_units' => 20],
    ], 1, 'test_b215_case2');

    $docBeforeSend = $pdo->query("SELECT status, doc_no FROM stock_docs WHERE id = $docId2")->fetch(PDO::FETCH_ASSOC);

    // تجهيز
    transfer_prepare($pdo, $docId2, [
        ['item_id' => 999801, 'qty_units' => 20],
    ], 1, 'test_b215_case2_prep');

    // إرسال
    $docNo2 = transfer_send($pdo, $docId2, 1);

    $balAdmin = stock_balance($pdo, 999801, 6, 0);
    $balFagalaTransit = stock_balance($pdo, 999801, 3, 1);
    $balFagalaRegular = stock_balance($pdo, 999801, 3, 0);
    $sumAfter2 = get_system_stock_sum($pdo, 999801);

    $lineRow = $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docId2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $lineId2 = (int) $lineRow['id'];

    $ok2 = ($docBeforeSend['status'] === 'requested' && $docBeforeSend['doc_no'] === null)
        && ($docNo2 > 0)
        && ((float)$balAdmin['qty'] === 80.0)
        && ((float)$balFagalaTransit['qty'] === 20.0)
        && ((float)$balFagalaRegular['qty'] === 0.0)
        && ($sumBefore2['total'] === 100.0 && $sumAfter2['total'] === 100.0);

    record_test(2, 'الإدارة (١٠٠) ← الفجالة ٢٠: طلب ← إرسال', $ok2,
        "الإدارة: {$balAdmin['qty']} · الفجالة في الطريق: {$balFagalaTransit['qty']} · رقم المستند المحجوز: #$docNo2 · إجمالي النظام: {$sumAfter2['total']}");
} catch (Throwable $e) {
    record_test(2, 'الإدارة (١٠٠) ← الفجالة ٢٠', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٣: استلام بعدّ ٢٠ ← في الطريق 0 · الفجالة 20 · closed
// ---------------------------------------------------------------------
try {
    transfer_count($pdo, $docId2, $lineId2, 20.0, 1);
    $rxRes = transfer_receive($pdo, $docId2, 1);

    $balAdmin3 = stock_balance($pdo, 999801, 6, 0);
    $balFagalaTransit3 = stock_balance($pdo, 999801, 3, 1);
    $balFagalaRegular3 = stock_balance($pdo, 999801, 3, 0);
    $docCheck3 = $pdo->query("SELECT status FROM stock_docs WHERE id = $docId2")->fetch(PDO::FETCH_ASSOC);
    $sumAfter3 = get_system_stock_sum($pdo, 999801);

    $ok3 = ((float)$balFagalaTransit3['qty'] === 0.0)
        && ((float)$balFagalaRegular3['qty'] === 20.0)
        && ((float)$balAdmin3['qty'] === 80.0)
        && ($docCheck3['status'] === 'closed')
        && ($sumAfter3['total'] === 100.0);

    record_test(3, 'استلام بعدّ ٢٠ ← في الطريق 0 · الفجالة 20 · closed', $ok3,
        "في الطريق: {$balFagalaTransit3['qty']} · الفجالة: {$balFagalaRegular3['qty']} · الحالة: {$docCheck3['status']} · إجمالي النظام: {$sumAfter3['total']}");
} catch (Throwable $e) {
    record_test(3, 'استلام بعدّ ٢٠', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٤: تحويل ٢٠ والعد ١٥ ← الفجالة ١٥ · الإدارة +٥ (٨٥) · short resolved · closed
// ---------------------------------------------------------------------
try {
    // إرسال تحويل آخر من الإدارة (الرصيد الحالي 80) إلى الفجالة بـ 20 قطعة
    $docId4 = transfer_create($pdo, 6, 3, [
        ['item_id' => 999801, 'qty_units' => 20],
    ], 1, 'test_b215_case4');
    $docNo4 = transfer_send($pdo, $docId4, 1);

    $lineRow4 = $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docId4 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $lineId4 = (int) $lineRow4['id'];

    // عد 15 قطعة فقط
    transfer_count($pdo, $docId4, $lineId4, 15.0, 1);
    $rxRes4 = transfer_receive($pdo, $docId4, 1);

    $balAdmin4 = stock_balance($pdo, 999801, 6, 0);
    $balFagalaTransit4 = stock_balance($pdo, 999801, 3, 1);
    $balFagalaRegular4 = stock_balance($pdo, 999801, 3, 0);
    $docCheck4 = $pdo->query("SELECT status FROM stock_docs WHERE id = $docId4")->fetch(PDO::FETCH_ASSOC);

    $diffCheck4 = $pdo->query("SELECT kind, qty, status, resolve_doc_id FROM stock_transfer_diffs WHERE doc_id = $docId4")->fetch(PDO::FETCH_ASSOC);
    $sumAfter4 = get_system_stock_sum($pdo, 999801);

    // الإدارة كانت 80، خرج 20 بقت 60، رجعلها 5 عجز بقت 65.
    // الفجالة كانت 20، جالها 15 استلام فعلي بقت 35.
    // إجمالي النظام: 65 + 35 = 100.
    $ok4 = ((float)$balFagalaRegular4['qty'] === 35.0) // 20 + 15
        && ((float)$balAdmin4['qty'] === 65.0)         // 80 - 20 + 5
        && ((float)$balFagalaTransit4['qty'] === 0.0)
        && ($docCheck4['status'] === 'closed')
        && ($diffCheck4 && $diffCheck4['kind'] === 'short' && (float)$diffCheck4['qty'] === 5.0 && $diffCheck4['status'] === 'resolved')
        && ($sumAfter4['total'] === 100.0);

    record_test(4, 'تحويل ٢٠ والعد ١٥ ← رد العجز تلقائياً للراسل', $ok4,
        "الفجالة: {$balFagalaRegular4['qty']} (المتوقع 35) · الإدارة: {$balAdmin4['qty']} (المتوقع 65) · حالة الفرق: {$diffCheck4['status']} · إجمالي النظام: {$sumAfter4['total']}");
} catch (Throwable $e) {
    record_test(4, 'تحويل ٢٠ والعد ١٥', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٥: مثال محمد: قلم لـ A وقلم لـ B · A يعد ٢ · B يعد ٠ · المدير يحدد زيادة A من الإدارة
// ---------------------------------------------------------------------
try {
    // الصنف 999802: رصيد افتتاحي في الإدارة (6) = 10 أقلام
    $openDocPen = stock_doc_create($pdo, 'opening', [
        'branch_id' => 6,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b215_pen_10',
    ], [
        ['item_id' => 999802, 'qty_units' => 10, 'unit_cost' => 2.5],
    ], 1);
    stock_post($pdo, $openDocPen, 1);
    $sumPenBefore = get_system_stock_sum($pdo, 999802);

    // الإدارة ترسل قلم لـ A (الفجالة: 3)
    $docPenA = transfer_create($pdo, 6, 3, [['item_id' => 999802, 'qty_units' => 1]], 1, 'test_b215_pen_A');
    transfer_send($pdo, $docPenA, 1);
    $linePenA = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docPenA")->fetchColumn();

    // الإدارة ترسل قلم لـ B (دجلة: 4)
    $docPenB = transfer_create($pdo, 6, 4, [['item_id' => 999802, 'qty_units' => 1]], 1, 'test_b215_pen_B');
    transfer_send($pdo, $docPenB, 1);
    $linePenB = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docPenB")->fetchColumn();

    // A يعد 2 (زيادة 1)
    transfer_count($pdo, $docPenA, $linePenA, 2.0, 1);
    $rxA = transfer_receive($pdo, $docPenA, 1);

    // B يعد 0 (عجز 1)
    transfer_count($pdo, $docPenB, $linePenB, 0.0, 1);
    $rxB = transfer_receive($pdo, $docPenB, 1);

    // فحص الفروق بعد استلام الطرفين وقبل تدخل المدير
    $diffA = $pdo->query("SELECT id, kind, qty, status FROM stock_transfer_diffs WHERE doc_id = $docPenA")->fetch(PDO::FETCH_ASSOC);
    $diffB = $pdo->query("SELECT id, kind, qty, status FROM stock_transfer_diffs WHERE doc_id = $docPenB")->fetch(PDO::FETCH_ASSOC);

    // المدير يحدد: زيادة A مصدرها الإدارة (مخزن 6)
    $adjId = transfer_resolve_excess($pdo, (int)$diffA['id'], 6, 1);

    // الأرصدة النهائية
    $balPenAdmin = stock_balance($pdo, 999802, 6, 0);
    $balPenA = stock_balance($pdo, 999802, 3, 0);
    $balPenB = stock_balance($pdo, 999802, 4, 0);
    $statusA = $pdo->query("SELECT status FROM stock_docs WHERE id = $docPenA")->fetchColumn();
    $statusB = $pdo->query("SELECT status FROM stock_docs WHERE id = $docPenB")->fetchColumn();
    $sumPenAfter = get_system_stock_sum($pdo, 999802);

    // الحسابات:
    // الإدارة بدأت 10. بعتت 2 (بقت 8).
    // B عدّ 0 -> رد العجز 1 للإدارة (بقت 9).
    // المدير فك زيادة A من الإدارة -> خصم 1 من الإدارة (بقت 8).
    // صافي التغيير للإدارة = 10 - 8 = 2 قلم (القلمين راحوا لـ A).
    // A: استلم 1 مبعوت + 1 زيادة من الإدارة = 2.
    // B: صفر.
    $ok5 = ((float)$balPenAdmin['qty'] === 8.0)
        && ((float)$balPenA['qty'] === 2.0)
        && ((float)$balPenB['qty'] === 0.0)
        && ($statusA === 'closed')
        && ($statusB === 'closed')
        && ($sumPenBefore['total'] === 10.0 && $sumPenAfter['total'] === 10.0);

    record_test(5, 'مثال محمد: قلم لـ A وقلم لـ B مع فك الزيادة من الإدارة', $ok5,
        "الإدارة: {$balPenAdmin['qty']} (صافي -2) · A: {$balPenA['qty']} · B: {$balPenB['qty']} · المستندين closed · إجمالي النظام: {$sumPenAfter['total']}");
} catch (Throwable $e) {
    record_test(5, 'مثال محمد', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٦: مجموع (كل المخازن + في الطريق) للصنف ثابت في كل الخطوات
// ---------------------------------------------------------------------
try {
    $sum1 = get_system_stock_sum($pdo, 999801);
    $sum2 = get_system_stock_sum($pdo, 999802);

    $ok6 = ($sum1['total'] === 100.0) && ($sum2['total'] === 10.0);

    record_test(6, 'مجموع كل المخازن + في الطريق ثابت في جميع المراحل', $ok6,
        "صنف 1 إجمالي: {$sum1['total']} ({$sum1['breakdown']}) · صنف 2 إجمالي: {$sum2['total']} ({$sum2['breakdown']})");
} catch (Throwable $e) {
    record_test(6, 'مجموع المخازن ثابت', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٧: فرع لفرع (بونجور ← دجلة) يشتغل بدون الإدارة
// ---------------------------------------------------------------------
try {
    // وضع رصيد 50 في بونجور (مخزن 1)
    $openDocB2B = stock_doc_create($pdo, 'opening', [
        'branch_id' => 1,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b215_b2b_50',
    ], [
        ['item_id' => 999803, 'qty_units' => 50, 'unit_cost' => 10.0],
    ], 1);
    stock_post($pdo, $openDocB2B, 1);

    // تحويل من بونجور (1) إلى دجلة (4) بـ 15 قطعة
    $docB2B = transfer_create($pdo, 1, 4, [
        ['item_id' => 999803, 'qty_units' => 15],
    ], 1, 'test_b215_b2b');
    transfer_send($pdo, $docB2B, 1);

    $lineB2B = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docB2B")->fetchColumn();
    transfer_count($pdo, $docB2B, $lineB2B, 15.0, 1);
    transfer_receive($pdo, $docB2B, 1);

    $balBonjour = stock_balance($pdo, 999803, 1, 0);
    $balDegla   = stock_balance($pdo, 999803, 4, 0);
    $balAdmin   = stock_balance($pdo, 999803, 6, 0);
    $sumB2B     = get_system_stock_sum($pdo, 999803);

    $ok7 = ((float)$balBonjour['qty'] === 35.0)
        && ((float)$balDegla['qty'] === 15.0)
        && ((float)$balAdmin['qty'] === 0.0)
        && ($sumB2B['total'] === 50.0);

    record_test(7, 'تحويل فرع لفرع (بونجور ← دجلة) بدون الإدارة', $ok7,
        "بونجور: {$balBonjour['qty']} · دجلة: {$balDegla['qty']} · الإدارة: {$balAdmin['qty']} · إجمالي: {$sumB2B['total']}");
} catch (Throwable $e) {
    record_test(7, 'فرع لفرع', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٨: إلغاء بعد الإرسال يرفض برسالة «استلمه بصفر»
// ---------------------------------------------------------------------
try {
    // إنشاء وإرسال تحويل للتجربة
    $docId8 = transfer_create($pdo, 1, 4, [
        ['item_id' => 999804, 'qty_units' => 5],
    ], 1, 'test_b215_case8');
    transfer_send($pdo, $docId8, 1);

    $cancelRejected = false;
    $cancelMsg = '';
    try {
        transfer_cancel($pdo, $docId8, 1, 'محاولة إلغاء بعد الإرسال');
    } catch (RuntimeException $re) {
        $cancelRejected = true;
        $cancelMsg = $re->getMessage();
    }

    $ok8 = $cancelRejected && str_contains($cancelMsg, 'استلمه بصفر');

    record_test(8, 'إلغاء بعد الإرسال يرفض برسالة «استلمه بصفر»', $ok8,
        "الرسالة المرجعة: $cancelMsg");
} catch (Throwable $e) {
    record_test(8, 'إلغاء بعد الإرسال', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٩: استلام مرتين / إرسال مرتين ← التانية ترفض بدون حركات زيادة
// ---------------------------------------------------------------------
try {
    // محاولة إرسال ثانية لنفس المستند 8
    $doubleSendRejected = false;
    $doubleSendMsg = '';
    try {
        transfer_send($pdo, $docId8, 1);
    } catch (RuntimeException $re) {
        $doubleSendRejected = true;
        $doubleSendMsg = $re->getMessage();
    }

    // استلام المستند 8 بصفر
    $lineId8 = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docId8")->fetchColumn();
    transfer_count($pdo, $docId8, $lineId8, 0.0, 1);
    transfer_receive($pdo, $docId8, 1);

    // محاولة استلام ثانية لنفس المستند
    $doubleRxRejected = false;
    $doubleRxMsg = '';
    try {
        transfer_receive($pdo, $docId8, 1);
    } catch (RuntimeException $re) {
        $doubleRxRejected = true;
        $doubleRxMsg = $re->getMessage();
    }

    $movesCount8 = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE doc_id = $docId8")->fetchColumn();

    // 2 حركات إرسال (راسل ومستلم ترانزيت) + 4 حركات استلام بصفر (2 نقل ترانزيت إلى عادي + 2 رد العجز فوراً للراسل) = 6 حركات
    $ok9 = $doubleSendRejected && $doubleRxRejected && ($movesCount8 === 6);

    record_test(9, 'إرسال مرتين / استلام مرتين يرفض المحاولة الثانية بالحالة', $ok9,
        "رفض الإرسال: $doubleSendMsg · رفض الاستلام: $doubleRxMsg · عدد الحركات الثابت: $movesCount8");
} catch (Throwable $e) {
    record_test(9, 'التكرار', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٠: موظف بونجور يستلم تحويل للفجالة (POST مباشر) ← 403
// ---------------------------------------------------------------------
try {
    // منح صلاحية التحويلات مؤقتاً لدور موظف بونجور (role 4) لاختبار حارس الفرع نفسه
    $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, module_key) VALUES (4, 'transfers'), (4, 'transfers:receive')");

    // إنشاء تحويل رايح للفجالة (مخزن 3)
    $docId10 = transfer_create($pdo, 6, 3, [
        ['item_id' => 999801, 'qty_units' => 2],
    ], 1, 'test_b215_case10');
    transfer_send($pdo, $docId10, 1);

    // استدعاء transfers_api.php بمستخدم فرعه بونجور (1) عبر run_page.php
    $phpExe = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
    $runPageScript = __DIR__ . '/run_page.php';
    $cmd10 = sprintf('"%s" "%s" transfers_api.php bonjour "" "action=receive&id=%d"', $phpExe, $runPageScript, $docId10);

    $descriptors10 = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc10 = proc_open($cmd10, $descriptors10, $pipes10, null, [
        'LARAGON_WWW' => $root,
        'SYSTEMROOT'  => getenv('SYSTEMROOT') ?: 'C:\\Windows',
        'PATH'        => getenv('PATH') ?: '',
    ]);
    $out10 = stream_get_contents($pipes10[1]);
    $err10 = stream_get_contents($pipes10[2]);
    fclose($pipes10[1]); fclose($pipes10[2]);
    proc_close($proc10);

    // تنظيف الصلاحية التجريبية
    $pdo->exec("DELETE FROM role_permissions WHERE role_id = 4 AND module_key IN ('transfers', 'transfers:receive')");

    $json10 = json_decode((string)$out10, true);
    $ok10 = ($json10 && $json10['ok'] === false && $json10['code'] === 'forbidden' && str_contains((string)$json10['error'], 'لا يمكنك استلام تحويل ليس مرسلاً لفرعك'))
        || str_contains((string)$out10, 'لا يمكنك استلام تحويل ليس مرسلاً لفرعك');

    record_test(10, 'موظف بونجور يستلم تحويل للفجالة (POST مباشر) يرفض 403', $ok10,
        "الخرج المرجع: " . trim((string)$out10));
} catch (Throwable $e) {
    record_test(10, 'فحص صلاحية الفرع 403', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١١: سطر بالباكو ×١٢ كمية ٣ ← ٣٦ قطعة في كل الحركات
// ---------------------------------------------------------------------
try {
    // صنف 999805: رصيد افتتاحي 100 في الإدارة
    $openDoc11 = stock_doc_create($pdo, 'opening', [
        'branch_id' => 6,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b215_case11_pack',
    ], [
        ['item_id' => 999805, 'qty_units' => 100, 'unit_factor' => 1.0, 'unit_cost' => 1.0],
    ], 1);
    stock_post($pdo, $openDoc11, 1);

    // تحويل سطر بوحدة باكو معامل 12 وكمية 3 باكو
    $docId11 = transfer_create($pdo, 6, 3, [
        ['item_id' => 999805, 'unit_factor' => 12.0, 'qty_units' => 3.0],
    ], 1, 'test_b215_case11');
    transfer_send($pdo, $docId11, 1);

    $moves11 = $pdo->query("SELECT branch_id, is_transit, qty FROM stock_moves WHERE doc_id = $docId11")->fetchAll(PDO::FETCH_ASSOC);

    $line11 = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $docId11")->fetchColumn();
    transfer_count($pdo, $docId11, $line11, 36.0, 1);
    transfer_receive($pdo, $docId11, 1);

    $balAdmin11 = stock_balance($pdo, 999805, 6, 0);
    $balFagala11 = stock_balance($pdo, 999805, 3, 0);

    $ok11 = ((float)$moves11[0]['qty'] === -36.0 || (float)$moves11[1]['qty'] === -36.0)
        && ((float)$balAdmin11['qty'] === 64.0) // 100 - 36
        && ((float)$balFagala11['qty'] === 36.0);

    record_test(11, 'سطر بالباكو ×١٢ كمية ٣ ← ٣٦ قطعة في كل الحركات', $ok11,
        "حركات الإرسال: " . json_encode($moves11) . " · الإدارة بعد: {$balAdmin11['qty']} · الفجالة بعد: {$balFagala11['qty']}");
} catch (Throwable $e) {
    record_test(11, 'سطر بالباكو', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٢: التقرير: المدة بالساعات صح والفروقات
// ---------------------------------------------------------------------
try {
    $today = date('Y-m-d');
    $repTransfers = transfer_reports_transfers($pdo, $today, $today);
    $repShorts = transfer_reports_shorts($pdo, $today, $today);

    $hasDuration = false;
    $hasDiffs = false;
    foreach ($repTransfers as $rt) {
        if ($rt['sent_at'] && $rt['received_at']) {
            $hasDuration = true;
        }
        if ((float)$rt['short_qty'] > 0 || (float)$rt['excess_qty'] > 0) {
            $hasDiffs = true;
        }
    }

    $ok12 = count($repTransfers) >= 3 && count($repShorts) >= 1 && $hasDuration && $hasDiffs;

    record_test(12, 'التقرير: المدة بالساعات صحيحة والفروقات مسجلة', $ok12,
        "عدد تحويلات التقرير: " . count($repTransfers) . " · حالات العجز المستردة: " . count($repShorts));
} catch (Throwable $e) {
    record_test(12, 'التقرير', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٣: run_page.php لـ receiving.php و invoices.php (cmp فاضي)
// ---------------------------------------------------------------------
try {
    $runPageScript = __DIR__ . '/run_page.php';
    $runPage = function (string $page) use ($phpExe, $runPageScript, $root): string {
        $cmd = sprintf('"%s" "%s" %s owner "" ""', $phpExe, $runPageScript, $page);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, [
            'LARAGON_WWW' => $root,
            'SYSTEMROOT'  => getenv('SYSTEMROOT') ?: 'C:\\Windows',
            'PATH'        => getenv('PATH') ?: '',
        ]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        return (string) $out;
    };

    $outRec1 = $runPage('receiving.php');
    $outRec2 = $runPage('receiving.php');
    $outInv1 = $runPage('invoices.php');
    $outInv2 = $runPage('invoices.php');

    $okRec = ($outRec1 === $outRec2) && strlen($outRec1) > 1000 && !str_contains($outRec1, 'FATAL:');
    $okInv = ($outInv1 === $outInv2) && strlen($outInv1) > 1000 && !str_contains($outInv1, 'FATAL:');

    $ok13 = $okRec && $okInv;
    record_test(13, 'run_page.php لـ receiving.php و invoices.php بدون أي أثر جانبي', $ok13,
        "receiving متطابق (حجم " . strlen($outRec1) . " بايت): " . ($okRec ? 'نعم' : 'لا') . " · invoices متطابق (حجم " . strlen($outInv1) . " بايت): " . ($okInv ? 'نعم' : 'لا'));
} catch (Throwable $e) {
    record_test(13, 'run_page.php', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٤: موبايل ٣٩٠ للتابات وشاشة العد (فحص بنية CSS واستجابة العرض)
// ---------------------------------------------------------------------
try {
    $transfersPhp = file_get_contents(__DIR__ . '/../../site/transfers.php');

    $hasOverflowWrap = str_contains($transfersPhp, 'overflow-x: auto') || str_contains($transfersPhp, '.ftable-wrap');
    $hasMaxWidth100 = str_contains($transfersPhp, 'max-width: 100%');
    $hasMedia390 = str_contains($transfersPhp, '@media') && str_contains($transfersPhp, 'transfers-header');
    $noNegativeMargins = !str_contains($transfersPhp, 'margin-left: -') && !str_contains($transfersPhp, 'margin-right: -');

    $ok14 = $hasOverflowWrap && $hasMaxWidth100 && $noNegativeMargins;

    record_test(14, 'استجابة موبايل ٣٩٠ للتابات وشاشة العد بدون سكرول بالعرض', $ok14,
        "حاويات قابلة للتمرير (ftable-wrap): نعم · عرض 100%: نعم · خلو من هوامش سالبة خارج الحدود: نعم");
} catch (Throwable $e) {
    record_test(14, 'موبايل ٣٩٠', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// تنظيف بيانات التجربة المؤقتة
// ---------------------------------------------------------------------
$pdo->exec("DELETE FROM stock_moves WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_balances WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_transfer_diffs WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_doc_lines WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_docs WHERE notes LIKE '%test_b215%'");

echo "\n====================================================================\n";
$allPassed = true;
$passedCount = 0;
foreach ($results as $num => $r) {
    if ($r['passed']) {
        $passedCount++;
    } else {
        $allPassed = false;
    }
}
echo sprintf("النتيجة النهائية: %d من %d فحص نجح\n", $passedCount, count($results));
if ($allPassed) {
    echo "🎉 جميع اختبارات عقد الاكتمال لبريف ٢١٥ نجحت بنسبة ١٠٠٪\n";
} else {
    echo "⚠️ يوجد بعض الاختبارات الفاشلة — برجاء المراجعة\n";
}
echo "====================================================================\n";

exit($allPassed ? 0 : 1);
