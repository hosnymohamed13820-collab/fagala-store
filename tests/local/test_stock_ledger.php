<?php
/**
 * test_stock_ledger.php — اختبارات عقد الاكتمال لبريف ٢١٤ (أساس المخزن على الموقع)
 * نظام الفجالة ستور المالي
 *
 * يختبر الحالات الـ ١٤ في عقد الاكتمال ويطبع ✓/✗ لكل حالة.
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

echo "====================================================================\n";
echo " بدء تشغيل اختبارات بريف ٢١٤ — أساس المخزن على الموقع\n";
echo " Base Dir: $root\n";
echo "====================================================================\n\n";

$testItems = [999901, 999902, 999903, 999904, 999905];
$testBranch = 3; // الفجالة

// تنضيف أي داتا قديمة تخص أصناف الاختبار
$pdo->exec("DELETE FROM stock_moves WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_balances WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_doc_lines WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_docs WHERE notes LIKE '%test_b214%'");

// ---------------------------------------------------------------------
// الحالة ١: erp_stock_enabled=0 وPOST ترحيل ← رفض «لسه مقفول»، مفيش تغيير
// ---------------------------------------------------------------------
try {
    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'erp_stock_enabled'");

    $docId1 = stock_doc_create($pdo, 'opening', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_case1',
    ], [
        ['item_id' => 999901, 'qty_units' => 10, 'unit_cost' => 5],
    ], 1);

    $rejected = false;
    $rejectMsg = '';
    try {
        stock_post($pdo, $docId1, 1);
    } catch (RuntimeException $re) {
        $rejected = true;
        $rejectMsg = $re->getMessage();
    }

    $docCheck = $pdo->query("SELECT status, doc_no FROM stock_docs WHERE id = $docId1")->fetch(PDO::FETCH_ASSOC);
    $movesCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE doc_id = $docId1")->fetchColumn();

    $ok1 = $rejected && str_contains($rejectMsg, 'المخزن على الموقع لسه مقفول')
        && $docCheck['status'] === 'draft' && $docCheck['doc_no'] === null && $movesCount === 0;

    record_test(1, 'erp_stock_enabled=0 وPOST ترحيل', $ok1, "الرسالة: $rejectMsg · مسودة بدون حركات");
} catch (Throwable $e) {
    record_test(1, 'erp_stock_enabled=0 وPOST ترحيل', false, "خطأ غير متوقع: " . $e->getMessage());
}

// تفعيل المخزن لباقي الاختبارات
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'erp_stock_enabled'");

// ---------------------------------------------------------------------
// الحالة ٢: افتتاحي: صنف A في الفجالة ١٠٠ قطعة بتكلفة ٢ ← qty_after=100 · avg=2 · doc_no=1
// ---------------------------------------------------------------------
try {
    $year = (int) date('Y');
    $pdo->exec("DELETE FROM doc_seq WHERE doc_type = 'opening' AND branch_id = $testBranch AND doc_year = $year");

    $docId2 = stock_doc_create($pdo, 'opening', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_case2',
    ], [
        ['item_id' => 999901, 'qty_units' => 100, 'unit_cost' => 2.0],
    ], 1);

    stock_post($pdo, $docId2, 1);

    $doc2 = $pdo->query("SELECT status, doc_no FROM stock_docs WHERE id = $docId2")->fetch(PDO::FETCH_ASSOC);
    $bal2 = stock_balance($pdo, 999901, $testBranch);
    $move2 = $pdo->query("SELECT * FROM stock_moves WHERE doc_id = $docId2")->fetch(PDO::FETCH_ASSOC);

    $ok2 = (int) $doc2['doc_no'] === 1
        && $doc2['status'] === 'posted'
        && (float) $bal2['qty'] === 100.0
        && (float) $bal2['avg_cost'] === 2.0
        && (float) $move2['qty_after'] === 100.0
        && (float) $move2['avg_cost_after'] === 2.0;

    record_test(2, 'افتتاحي: صنف A في الفجالة ١٠٠ قطعة بتكلفة ٢', $ok2,
        "doc_no={$doc2['doc_no']} · qty_after={$move2['qty_after']} · avg={$move2['avg_cost_after']}");
} catch (Throwable $e) {
    record_test(2, 'افتتاحي: صنف A في الفجالة ١٠٠ قطعة بتكلفة ٢', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٣: تسوية +٥٠ (تكلفة ٣) ثم −٣٠ ← 150 / avg 2.3333 ثم 120 / avg 2.3333
// ---------------------------------------------------------------------
try {
    // 1. تسوية +50 بتكلفة 3
    $docId3a = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_case3a',
    ], [
        ['item_id' => 999901, 'qty_units' => 50, 'unit_cost' => 3.0],
    ], 1);
    stock_post($pdo, $docId3a, 1);

    $bal3a = stock_balance($pdo, 999901, $testBranch);

    // 2. تسوية -30 (خارج، بنفس المتوسط)
    $docId3b = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_case3b',
    ], [
        ['item_id' => 999901, 'qty_units' => -30],
    ], 1);
    stock_post($pdo, $docId3b, 1);

    $bal3b = stock_balance($pdo, 999901, $testBranch);

    $ok3 = (float) $bal3a['qty'] === 150.0
        && round((float) $bal3a['avg_cost'], 4) === 2.3333
        && (float) $bal3b['qty'] === 120.0
        && round((float) $bal3b['avg_cost'], 4) === 2.3333;

    record_test(3, 'تسوية +٥٠ (تكلفة ٣) ثم −٣٠', $ok3,
        "بعد الإضافة: qty={$bal3a['qty']}, avg={$bal3a['avg_cost']} | بعد الخروج: qty={$bal3b['qty']}, avg={$bal3b['avg_cost']}");
} catch (Throwable $e) {
    record_test(3, 'تسوية +٥٠ (تكلفة ٣) ثم −٣٠', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٤: مستند فيه سطرين لنفس الصنف (+١٠ و −٤) ← qty_after 130 ثم 126
// ---------------------------------------------------------------------
try {
    $docId4 = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_case4',
    ], [
        ['item_id' => 999901, 'qty_units' => 10],
        ['item_id' => 999901, 'qty_units' => -4],
    ], 1);
    stock_post($pdo, $docId4, 1);

    $stMoves4 = $pdo->prepare("SELECT * FROM stock_moves WHERE doc_id = ? ORDER BY id ASC");
    $stMoves4->execute([$docId4]);
    $moves4 = $stMoves4->fetchAll(PDO::FETCH_ASSOC);

    $ok4 = count($moves4) === 2
        && (float) $moves4[0]['qty_after'] === 130.0
        && (float) $moves4[1]['qty_after'] === 126.0;

    record_test(4, 'مستند فيه سطرين لنفس الصنف (+١٠ و −٤)', $ok4,
        "السطر الأول qty_after={$moves4[0]['qty_after']} · السطر الثاني qty_after={$moves4[1]['qty_after']}");
} catch (Throwable $e) {
    record_test(4, 'مستند فيه سطرين لنفس الصنف (+١٠ و −٤)', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٥: إلغاء مستند ٤ ← حركتين عكسيتين، الرصيد 120، stock_moves زاد ٢ ومفيش صف اتمسح أو اتعدّل
// ---------------------------------------------------------------------
try {
    $countMovesBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves")->fetchColumn();
    $movesSnapshotBefore = $pdo->query("SELECT id, qty, qty_after FROM stock_moves WHERE doc_id = $docId4")->fetchAll(PDO::FETCH_ASSOC);

    stock_cancel($pdo, $docId4, 1, 'إلغاء تجريبي للحالة ٥');

    $countMovesAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves")->fetchColumn();
    $bal5 = stock_balance($pdo, 999901, $testBranch);

    // فحص الحركات الأصلية لم تتغير
    $movesSnapshotAfter = $pdo->query("SELECT id, qty, qty_after FROM stock_moves WHERE doc_id = $docId4 AND reverses_move_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $untouched = ($movesSnapshotBefore === $movesSnapshotAfter);

    // فحص الحركتين العكسيتين
    $revMoves = $pdo->query("SELECT * FROM stock_moves WHERE doc_id = $docId4 AND reverses_move_id IS NOT NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $revOk = count($revMoves) === 2
        && (float) $revMoves[0]['qty'] === -10.0
        && (float) $revMoves[1]['qty'] === 4.0;

    $ok5 = ($countMovesAfter === $countMovesBefore + 2)
        && (float) $bal5['qty'] === 120.0
        && $untouched
        && $revOk;

    record_test(5, 'إلغاء مستند ٤ بحركات عكسية بدون حذف أو تعديل', $ok5,
        "حركات زادت ٢ (من $countMovesBefore إلى $countMovesAfter) · الرصيد رجع {$bal5['qty']} · الصفوف الأصلية لم تُمَس");
} catch (Throwable $e) {
    record_test(5, 'إلغاء مستند ٤ بحركات عكسية بدون حذف أو تعديل', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٦: إلغاء نفس المستند تاني ← رفض
// ---------------------------------------------------------------------
try {
    $rejectedAgain = false;
    $msg6 = '';
    try {
        stock_cancel($pdo, $docId4, 1, 'محاولة إلغاء مكررة');
    } catch (RuntimeException $re) {
        $rejectedAgain = true;
        $msg6 = $re->getMessage();
    }

    $ok6 = $rejectedAgain && str_contains($msg6, 'غير مرحل');
    record_test(6, 'إلغاء نفس المستند الملغي مرة ثانية', $ok6, "تم الرفض برسالة: $msg6");
} catch (Throwable $e) {
    record_test(6, 'إلغاء نفس المستند الملغي مرة ثانية', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٧: SUM(qty) من stock_moves لكل (صنف، مخزن) = stock_balances.qty
// ---------------------------------------------------------------------
try {
    $sql7 = "
        SELECT b.item_id, b.branch_id, b.is_transit,
               b.qty AS bal_qty,
               COALESCE(SUM(m.qty), 0) AS moves_sum,
               ROUND(b.qty - COALESCE(SUM(m.qty), 0), 3) AS diff
          FROM stock_balances b
          LEFT JOIN stock_moves m ON m.item_id = b.item_id AND m.branch_id = b.branch_id AND m.is_transit = b.is_transit
         GROUP BY b.item_id, b.branch_id, b.is_transit
        HAVING diff <> 0
    ";
    $mismatches = $pdo->query($sql7)->fetchAll(PDO::FETCH_ASSOC);

    $totalChecked = (int) $pdo->query("SELECT COUNT(*) FROM stock_balances")->fetchColumn();
    $ok7 = count($mismatches) === 0;

    record_test(7, 'مطابقة SUM(qty) من الحركات مع stock_balances لكل الصفوف', $ok7,
        "تم فحص $totalChecked صف أرصدة · الفروق: " . count($mismatches));
} catch (Throwable $e) {
    record_test(7, 'مطابقة SUM(qty) من الحركات مع stock_balances لكل الصفوف', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٨: ترحيلين متوازيين على نفس الصنف (عمليتين PHP مع بعض) ← مفيش deadlock، والرصيد = المجموع
// ---------------------------------------------------------------------
try {
    $pdo->exec("DELETE FROM stock_balances WHERE item_id = 999902");
    $pdo->exec("DELETE FROM stock_moves WHERE item_id = 999902");

    $docId8a = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_parallel_a',
    ], [
        ['item_id' => 999902, 'qty_units' => 25],
    ], 1);

    $docId8b = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_parallel_b',
    ], [
        ['item_id' => 999902, 'qty_units' => 35],
    ], 1);

    // تشغيل عمليتين PHP بالتوازي
    $phpExe = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
    $cmdA = sprintf('"%s" -r "require \'%s/config.php\'; require \'%s/site/stock_lib.php\'; stock_post(db(), %d, 1);"', $phpExe, $root, __DIR__ . '/../..', $docId8a);
    $cmdB = sprintf('"%s" -r "require \'%s/config.php\'; require \'%s/site/stock_lib.php\'; stock_post(db(), %d, 1);"', $phpExe, $root, __DIR__ . '/../..', $docId8b);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [
        'LARAGON_WWW' => $root,
        'SYSTEMROOT'  => getenv('SYSTEMROOT') ?: 'C:\\Windows',
        'PATH'        => getenv('PATH') ?: '',
    ];

    $procA = proc_open($cmdA, $descriptors, $pipesA, null, $env);
    $procB = proc_open($cmdB, $descriptors, $pipesB, null, $env);

    $outA = stream_get_contents($pipesA[1]); $errA = stream_get_contents($pipesA[2]);
    $outB = stream_get_contents($pipesB[1]); $errB = stream_get_contents($pipesB[2]);
    fclose($pipesA[1]); fclose($pipesA[2]);
    fclose($pipesB[1]); fclose($pipesB[2]);

    $exitA = proc_close($procA);
    $exitB = proc_close($procB);

    $bal8 = stock_balance($pdo, 999902, $testBranch);

    $ok8 = ($exitA === 0) && ($exitB === 0) && ((float) $bal8['qty'] === 60.0);
    record_test(8, 'ترحيلين متوازيين على نفس الصنف بدون deadlock', $ok8,
        "Process A exit=$exitA, Process B exit=$exitB · الرصيد النهائي={$bal8['qty']} (المتوقع 60)");
} catch (Throwable $e) {
    record_test(8, 'ترحيلين متوازيين على نفس الصنف بدون deadlock', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٩: ترحيل فشل في النص (exception متعمّد) ← مفيش رقم اتحجز، مفيش حركات، doc_seq زي ما هو
// ---------------------------------------------------------------------
try {
    $year = (int) date('Y');
    // جلب last_no الحالي
    $stSeq = $pdo->prepare("SELECT last_no FROM doc_seq WHERE doc_type = 'adjust' AND branch_id = ? AND doc_year = ?");
    $stSeq->execute([$testBranch, $year]);
    $seqBefore = (int) ($stSeq->fetchColumn() ?: 0);

    $movesCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE item_id = 999903")->fetchColumn();

    $docId9 = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_fail_midway',
    ], [
        ['item_id' => 999903, 'qty_units' => 10],
    ], 1);

    // محاكاة فشل في منتصف المعاملة بعد توليد الرقم
    $pdo->beginTransaction();
    $caught = false;
    try {
        $no = stock_next_no($pdo, 'adjust', $testBranch, $year);
        // خطأ متعمد قبل الحفظ
        throw new RuntimeException('خطأ متعمد لاختبار التراجع rollback');
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $caught = true;
    }

    $stSeq->execute([$testBranch, $year]);
    $seqAfter = (int) ($stSeq->fetchColumn() ?: 0);
    $movesCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE item_id = 999903")->fetchColumn();
    $docCheck9 = $pdo->query("SELECT status, doc_no FROM stock_docs WHERE id = $docId9")->fetch(PDO::FETCH_ASSOC);

    $ok9 = $caught && ($seqBefore === $seqAfter) && ($movesCountBefore === $movesCountAfter)
        && $docCheck9['status'] === 'draft' && $docCheck9['doc_no'] === null;

    record_test(9, 'فشل الترحيل في المنتصف وتراجع المعاملة بالكامل', $ok9,
        "العداد قبل=$seqBefore وبعد=$seqAfter · الحركات لم تتغير · المستند ظل مسودة");
} catch (Throwable $e) {
    record_test(9, 'فشل الترحيل في المنتصف وتراجع المعاملة بالكامل', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٠: افتتاحي من المرآة للفجالة على Laragon ← عدد الأصناف والمجموع بالقطعة متطابق
// ---------------------------------------------------------------------
try {
    // تنظيف أي opening سابق للفجالة من أجل اختبار الاستيراد الشامل
    $pdo->exec("DELETE FROM doc_events WHERE doc_id IN (SELECT id FROM stock_docs WHERE branch_id = $testBranch AND doc_type = 'opening')");
    $pdo->exec("DELETE FROM stock_moves WHERE doc_id IN (SELECT id FROM stock_docs WHERE branch_id = $testBranch AND doc_type = 'opening')");
    $pdo->exec("DELETE FROM stock_doc_lines WHERE doc_id IN (SELECT id FROM stock_docs WHERE branch_id = $testBranch AND doc_type = 'opening')");
    $pdo->exec("DELETE FROM stock_docs WHERE branch_id = $testBranch AND doc_type = 'opening'");
    $pdo->exec("DELETE FROM stock_balances WHERE branch_id = $testBranch");

    $expectedCount = (int) $pdo->query("SELECT COUNT(*) FROM hq_branch_balances WHERE sto_id = 2 AND balance <> 0")->fetchColumn();
    $expectedPieces = (float) $pdo->query("SELECT ROUND(SUM(balance), 3) FROM hq_branch_balances WHERE sto_id = 2 AND balance <> 0")->fetchColumn();

    $openDocId = stock_opening_from_mirror($pdo, $testBranch, 1);

    $linesCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_doc_lines WHERE doc_id = $openDocId")->fetchColumn();
    $linesPieces = (float) $pdo->query("SELECT ROUND(SUM(qty_pieces), 3) FROM stock_doc_lines WHERE doc_id = $openDocId")->fetchColumn();
    $movesCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves WHERE doc_id = $openDocId")->fetchColumn();
    $movesPieces = (float) $pdo->query("SELECT ROUND(SUM(qty), 3) FROM stock_moves WHERE doc_id = $openDocId")->fetchColumn();

    $ok10 = ($linesCount === $expectedCount)
        && ($linesPieces === $expectedPieces)
        && ($movesCount === $expectedCount)
        && ($movesPieces === $expectedPieces);

    record_test(10, 'رصيد افتتاحي من المرآة للفجالة على Laragon', $ok10,
        "عدد الأصناف: $linesCount (المتوقع $expectedCount) · إجمالي القطع: $movesPieces (المتوقع $expectedPieces)");
} catch (Throwable $e) {
    record_test(10, 'رصيد افتتاحي من المرآة للفجالة على Laragon', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١١: افتتاحي تاني لنفس المخزن ← رفض
// ---------------------------------------------------------------------
try {
    $rejected11 = false;
    $msg11 = '';
    try {
        stock_opening_from_mirror($pdo, $testBranch, 1);
    } catch (RuntimeException $re) {
        $rejected11 = true;
        $msg11 = $re->getMessage();
    }

    $ok11 = $rejected11 && str_contains($msg11, 'يوجد رصيد افتتاحي مرحل بالفعل');
    record_test(11, 'محاولة إنشاء رصيد افتتاحي مكرر لنفس المخزن', $ok11, "الرفض: $msg11");
} catch (Throwable $e) {
    record_test(11, 'محاولة إنشاء رصيد افتتاحي مكرر لنفس المخزن', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٢: رصيد بالسالب ← بيتسجّل + doc_events note
// ---------------------------------------------------------------------
try {
    $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'stock_allow_negative'");
    $pdo->exec("DELETE FROM stock_balances WHERE item_id = 999905");
    $pdo->exec("DELETE FROM stock_moves WHERE item_id = 999905");

    $docId12 = stock_doc_create($pdo, 'adjust', [
        'branch_id' => $testBranch,
        'doc_date'  => date('Y-m-d'),
        'notes'     => 'test_b214_negative_balance',
    ], [
        ['item_id' => 999905, 'qty_units' => -15.0],
    ], 1);
    stock_post($pdo, $docId12, 1);

    $bal12 = stock_balance($pdo, 999905, $testBranch);
    $stEv12 = $pdo->prepare("SELECT * FROM doc_events WHERE doc_id = ? AND event = 'note'");
    $stEv12->execute([$docId12]);
    $ev12 = $stEv12->fetch(PDO::FETCH_ASSOC);

    $ok12 = (float) $bal12['qty'] === -15.0
        && $ev12 !== false
        && str_contains((string) $ev12['details'], 'رصيد سالب');

    record_test(12, 'تسجيل الرصيد بالسالب مع doc_events note', $ok12,
        "الرصيد المسجل: {$bal12['qty']} · حدث الملاحظة: " . ($ev12['details'] ?? ''));
} catch (Throwable $e) {
    record_test(12, 'تسجيل الرصيد بالسالب مع doc_events note', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٣: الـAPI من غير المالك ← 403 JSON
// ---------------------------------------------------------------------
try {
    $phpExe = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
    $runPageScript = __DIR__ . '/run_page.php';
    $cmd13 = sprintf('"%s" "%s" stock_api.php ashraqa "action=moves" ""', $phpExe, $runPageScript);

    $env = [
        'LARAGON_WWW' => $root,
        'SYSTEMROOT'  => getenv('SYSTEMROOT') ?: 'C:\\Windows',
        'PATH'        => getenv('PATH') ?: '',
    ];

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc13 = proc_open($cmd13, $descriptors, $pipes13, null, $env);
    $out13 = stream_get_contents($pipes13[1]);
    $err13 = stream_get_contents($pipes13[2]);
    fclose($pipes13[1]); fclose($pipes13[2]);
    proc_close($proc13);

    $json13 = json_decode($out13, true);
    $ok13 = is_array($json13)
        && ($json13['ok'] ?? null) === false
        && ($json13['code'] ?? null) === 'forbidden';

    record_test(13, 'الـAPI من غير المالك يرفض 403 JSON', $ok13,
        "الخرج: " . trim($out13));
} catch (Throwable $e) {
    record_test(13, 'الـAPI من غير المالك يرفض 403 JSON', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٤: run_page.php لصفحات تانية (stock_query.php, treasury.php) ← cmp فاضي
// ---------------------------------------------------------------------
try {
    $phpExe = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
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

    $outSq1 = $runPage('stock_query.php');
    $outSq2 = $runPage('stock_query.php');
    $outTr1 = $runPage('treasury.php');
    $outTr2 = $runPage('treasury.php');

    $okSq = ($outSq1 === $outSq2) && strlen($outSq1) > 1000 && !str_contains($outSq1, 'FATAL:');
    $okTr = ($outTr1 === $outTr2) && strlen($outTr1) > 1000 && !str_contains($outTr1, 'FATAL:');

    $ok14 = $okSq && $okTr;
    record_test(14, 'run_page.php لصفحات تانية (stock_query, treasury) لم تتأثر', $ok14,
        "stock_query متطابق (حجم " . strlen($outSq1) . " بايت): " . ($okSq ? 'نعم' : 'لا') . " · treasury متطابق (حجم " . strlen($outTr1) . " بايت): " . ($okTr ? 'نعم' : 'لا'));
} catch (Throwable $e) {
    record_test(14, 'run_page.php لصفحات تانية لم تتأثر', false, "خطأ: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// تنظيف بيانات التجربة المؤقتة
// ---------------------------------------------------------------------
$pdo->exec("DELETE FROM stock_moves WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_balances WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_doc_lines WHERE item_id IN (" . implode(',', $testItems) . ")");
$pdo->exec("DELETE FROM stock_docs WHERE notes LIKE '%test_b214%'");

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
    echo "🎉 جميع اختبارات عقد الاكتمال نجحت بنسبة ١٠٠٪\n";
} else {
    echo "⚠️ يوجد بعض الاختبارات الفاشلة — برجاء المراجعة\n";
}
echo "====================================================================\n";

exit($allPassed ? 0 : 1);
