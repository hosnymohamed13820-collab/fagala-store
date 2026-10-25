<?php
/**
 * test_stock_import.php — اختبارات عقد الاكتمال لبريف ٢١٨
 * استيراد حركات True للدفتر + المطابقة اليومية + تقارير الحركة
 *
 * يختبر الحالات الـ ١٠ في عقد الاكتمال على قاعدة Laragon
 */
declare(strict_types=1);

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds2';
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
require_once __DIR__ . '/../../site/stock_import_lib.php';

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
echo " بدء تشغيل اختبارات بريف ٢١٨ — استيراد حركات True والمطابقة والتقارير\n";
echo " Base Dir: $root\n";
echo "====================================================================\n\n";

// تنظيف آمن للبيانات التجريبية الخاصة بالاختبار فقط (> 999950)
function cleanup_b218_fixtures(PDO $pdo): void
{
    $pdo->exec("DELETE FROM stock_import_log WHERE src_key LIKE '%:9999%' OR src_key LIKE '%T218%'");
    $pdo->exec("DELETE FROM doc_events WHERE doc_kind = 'stock' AND doc_id IN (SELECT id FROM stock_docs WHERE ref_text LIKE '%:9999%' OR ref_text LIKE '%T218%' OR notes LIKE '%T218%')");
    $pdo->exec("DELETE FROM stock_moves WHERE item_id >= 999950 OR doc_id IN (SELECT id FROM stock_docs WHERE ref_text LIKE '%:9999%' OR ref_text LIKE '%T218%')");
    $pdo->exec("DELETE FROM stock_doc_lines WHERE item_id >= 999950 OR doc_id IN (SELECT id FROM stock_docs WHERE ref_text LIKE '%:9999%' OR ref_text LIKE '%T218%')");
    $pdo->exec("DELETE FROM stock_docs WHERE ref_text LIKE '%:9999%' OR ref_text LIKE '%T218%' OR notes LIKE '%T218%'");
    $pdo->exec("DELETE FROM stock_balances WHERE item_id >= 999950");
    $pdo->exec("DELETE FROM hq_branch_balances WHERE item_id >= 999950");
    $pdo->exec("DELETE FROM hq_invoice_lines WHERE item_id >= 999950 OR suffix = 'T218'");
    $pdo->exec("DELETE FROM hq_invoices WHERE sth_id >= 999950 OR suffix = 'T218'");
    $pdo->exec("DELETE FROM br_invoice_lines WHERE item_id >= 999950 OR suffix = 'T218'");
    $pdo->exec("DELETE FROM br_invoices WHERE sth_id >= 999950 OR suffix = 'T218'");
    $pdo->exec("DELETE FROM hq_codes WHERE item_id >= 999950");
}

cleanup_b218_fixtures($pdo);

// تفعيل المخزن وضبط وضع الفجالة mirror
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'erp_stock_enabled'");
$pdo->exec("UPDATE settings SET setting_value = 'mirror' WHERE setting_key = 'stock_mode_3'");
$pdo->exec("UPDATE settings SET setting_value = '' WHERE setting_key = 'stock_import_last'");

// ---------------------------------------------------------------------
// الحالة ٠١: migration مرتين — صفر أخطاء
// ---------------------------------------------------------------------
try {
    $migFile = __DIR__ . '/../../local/migrations/20261025_012_stock_import_log.sql';
    $sqlMig = file_get_contents($migFile);
    $pdo->exec($sqlMig);
    $pdo->exec($sqlMig);
    record_test(1, 'migration مرتين — صفر أخطاء', true, 'تم تشغيل 20261025_012_stock_import_log.sql مرتين متتاليتين بدون أخطاء.');
} catch (Throwable $e) {
    record_test(1, 'migration مرتين — صفر أخطاء', false, 'خطأ في التكرار: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٢: افتتاح للفجالة + بيع وشراء وتحديث hq_branch_balances
// ---------------------------------------------------------------------
try {
    $branchId = 3; // الفجالة
    $stoId = 2;    // sto_id للفجالة
    $agentKey = 'zahraa';

    $itemA = 999961;
    $codeA = 'TEST-ITEM-999961';

    // 1. تعريف صنف A في hq_codes
    $pdo->prepare("
        INSERT INTO hq_codes (code, item_id, name, code_type, unit_factor, price, cost)
        VALUES (?, ?, 'صنف تجربة A', 0, 1.000, 50.00, 20.00)
        ON DUPLICATE KEY UPDATE name = VALUES(name), unit_factor = VALUES(unit_factor)
    ")->execute([$codeA, $itemA]);

    // 2. التحقق من وجود رصيد افتتاحي مرحل لمخزن الفجالة وتثبيت رصيد أولي لـ A = 100
    // إذا لم يكن هناك opening مرحل، نقوم بإنشائه
    $stCheckOp = $pdo->query("SELECT id, posted_at FROM stock_docs WHERE branch_id = 3 AND doc_type = 'opening' AND status = 'posted' LIMIT 1");
    $opRow = $stCheckOp->fetch(PDO::FETCH_ASSOC);
    if (!$opRow) {
        $opDocId = stock_doc_create($pdo, 'opening', [
            'branch_id' => 3,
            'doc_date'  => date('Y-m-d', strtotime('-1 day')),
            'notes'     => 'افتتاحي تجريبي T218',
        ], [
            ['item_id' => $itemA, 'code' => $codeA, 'qty_units' => 100.0, 'unit_factor' => 1.0, 'unit_cost' => 20.0]
        ], 1);
        stock_post($pdo, $opDocId, 1);
        $postedAt = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $pdo->prepare("UPDATE stock_docs SET posted_at = ? WHERE id = ?")->execute([$postedAt, $opDocId]);
    } else {
        $postedAt = $opRow['posted_at'] ?: date('Y-m-d H:i:s', strtotime('-1 day'));
        // إضافة رصيد أولي لصنف A في stock_balances بقيمة 100
        $pdo->prepare("
            INSERT INTO stock_balances (item_id, branch_id, is_transit, qty, avg_cost)
            VALUES (?, 3, 0, 100.000, 20.0000)
            ON DUPLICATE KEY UPDATE qty = 100.000, avg_cost = 20.0000
        ")->execute([$itemA]);
    }

    $invTime = date('Y-m-d H:i:s', strtotime('+5 seconds', strtotime($postedAt)));
    $syncTime = date('Y-m-d H:i:s', strtotime('+10 seconds', strtotime($postedAt)));

    // 3. فاتورة بيع الفجالة (صنف A ٣ قطع): doc_type=4 في br_invoices
    $sthSale = 999951;
    $suf = 'T218';
    $pdo->prepare("
        INSERT INTO br_invoices (agent_key, sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, ?, ?, 'BR-SALE-1', 4, NULL, ?, ?, ?)
    ")->execute([$agentKey, $sthSale, $suf, $invTime, $stoId, $syncTime]);

    $pdo->prepare("
        INSERT INTO br_invoice_lines (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, ?, ?, 1, ?, ?, 'صنف تجربة A', 3.000, 50.00, 20.00, 1.0000)
    ")->execute([$agentKey, $sthSale, $suf, $itemA, $codeA]);

    // 4. فاتورة شراء الفجالة من «الرئيسية» (1000182) A ٢٠ قطعة: doc_type=2 في hq_invoices
    $sthPur = 999952;
    $pdo->prepare("
        INSERT INTO hq_invoices (sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, ?, 'HQ-PUR-1', 2, 1000182, ?, ?, ?)
    ")->execute([$sthPur, $suf, $invTime, $stoId, $syncTime]);

    $pdo->prepare("
        INSERT INTO hq_invoice_lines (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, ?, 1, ?, ?, 'صنف تجربة A', 20.000, 20.00, 20.00, 1.0000)
    ")->execute([$sthPur, $suf, $itemA, $codeA]);

    // 5. تحديث hq_branch_balances بنفس الأثر: 100 - 3 + 20 = 117
    $expectedFinalQty = 117.000;
    $pdo->prepare("
        INSERT INTO hq_branch_balances (agent_key, item_id, sto_id, balance, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE balance = VALUES(balance), updated_at = NOW()
    ")->execute([$agentKey, $itemA, $stoId, $expectedFinalQty]);

    // 6. تشغيل الاستيراد
    $impRes = stock_import_run($pdo, 1, 3);

    // 7. التحقق من الرصيد والمطابقة
    $bal = stock_balance($pdo, $itemA, 3, 0);
    $recon = stock_recon($pdo, 3);

    // فحص ما إذا كان صنف A ضمن الفروق
    $diffForA = null;
    foreach ($recon['differences'] as $df) {
        if ($df['item_id'] === $itemA) {
            $diffForA = $df;
            break;
        }
    }

    $pass2 = (abs($bal['qty'] - $expectedFinalQty) < 0.001) && ($diffForA === null);
    $details2 = sprintf(
        "رصيد الدفتر لـ A = %.3f | رصيد True = %.3f | استيراد جديد: %d | فروق A: %s",
        $bal['qty'],
        $expectedFinalQty,
        $impRes['imported'],
        $diffForA === null ? 'صفر فروق ✓' : 'يوجد فرق'
    );
    record_test(2, 'افتتاحي + بيع (٣) + شراء الرئيسية (٢٠) والمطابقة صفر فروق', $pass2, $details2);
} catch (Throwable $e) {
    record_test(2, 'افتتاحي + بيع (٣) + شراء الرئيسية (٢٠) والمطابقة صفر فروق', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٣: تشغيل الاستيراد تاني ← skipped بس، مفيش مستندات جديدة
// ---------------------------------------------------------------------
try {
    $impRes2 = stock_import_run($pdo, 1, 3);
    $pass3 = ($impRes2['imported'] === 0) && ($impRes2['updated'] === 0) && ($impRes2['skipped'] >= 2) && ($impRes2['failed'] === 0);
    $details3 = sprintf(
        "مستورد: %d | محدث: %d | تم تخطيه (skipped): %d | فشل: %d",
        $impRes2['imported'],
        $impRes2['updated'],
        $impRes2['skipped'],
        $impRes2['failed']
    );
    record_test(3, 'تشغيل الاستيراد مرة ثانية ينتج عنه skipped فقط بدون مستندات جديدة', $pass3, $details3);
} catch (Throwable $e) {
    record_test(3, 'تشغيل الاستيراد مرة ثانية ينتج عنه skipped فقط بدون مستندات جديدة', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٤: تعديل فاتورة الشراء (الكمية ٢٥) + استيراد ← القديم cancelled والجديد صح
// ---------------------------------------------------------------------
try {
    // جلب معرف المستند القديم قبل التعديل
    $stOld = $pdo->prepare("SELECT doc_id FROM stock_import_log WHERE source = 'hq' AND src_key = 'hq:999952:T218'");
    $stOld->execute();
    $oldDocId = (int) $stOld->fetchColumn();

    // تعديل الفاتورة في True: الكمية أصبحت 25 بدلاً من 20
    $pdo->prepare("UPDATE hq_invoice_lines SET quantity = 25.000 WHERE sth_id = 999952 AND suffix = 'T218'")->execute();
    $pdo->prepare("UPDATE hq_invoices SET synced_at = NOW() WHERE sth_id = 999952 AND suffix = 'T218'")->execute();

    // تحديث رصيد True للفجالة: 100 - 3 + 25 = 122
    $expectedQtyAfterUpdate = 122.000;
    $pdo->prepare("UPDATE hq_branch_balances SET balance = ? WHERE item_id = ? AND sto_id = ?")->execute([$expectedQtyAfterUpdate, $itemA, $stoId]);

    // تشغيل الاستيراد
    $impRes3 = stock_import_run($pdo, 1, 3);

    // فحص حالة المستند القديم
    $stOldStat = $pdo->prepare("SELECT status FROM stock_docs WHERE id = ?");
    $stOldStat->execute([$oldDocId]);
    $oldStatus = (string) $stOldStat->fetchColumn();

    // فحص الرصيد الجديد
    $balAfter = stock_balance($pdo, $itemA, 3, 0);
    $reconAfter = stock_recon($pdo, 3);

    $diffForA_after = null;
    foreach ($reconAfter['differences'] as $df) {
        if ($df['item_id'] === $itemA) {
            $diffForA_after = $df;
            break;
        }
    }

    $pass4 = ($impRes3['updated'] >= 1) && ($oldStatus === 'cancelled') && (abs($balAfter['qty'] - $expectedQtyAfterUpdate) < 0.001) && ($diffForA_after === null);
    $details4 = sprintf(
        "محدث: %d | حالة القديم #%d: %s | الرصيد الجديد: %.3f (المتوقع %.3f) | المطابقة: %s",
        $impRes3['updated'],
        $oldDocId,
        $oldStatus,
        $balAfter['qty'],
        $expectedQtyAfterUpdate,
        $diffForA_after === null ? 'صفر فروق ✓' : 'يوجد فرق'
    );
    record_test(4, 'تعديل فاتورة الشراء إلى ٢٥ قطعة يلغي القديم ويستورد الجديد بدقة', $pass4, $details4);
} catch (Throwable $e) {
    record_test(4, 'تعديل فاتورة الشراء إلى ٢٥ قطعة يلغي القديم ويستورد الجديد بدقة', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٥: الفجالة live + فاتورة شراء True جديدة (متستوردتش) + بيع جديدة (اتستوردت)
// ---------------------------------------------------------------------
try {
    // تحويل وضع مخزن الفجالة إلى live
    $pdo->exec("UPDATE settings SET setting_value = 'live' WHERE setting_key = 'stock_mode_3'");

    // إضافة فاتورة شراء جديدة في True (يجب ألا تستورد لأن المخزن live)
    $sthPurLive = 999953;
    $pdo->prepare("
        INSERT INTO hq_invoices (sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, 'T218', 'HQ-PUR-LIVE', 2, 1000182, NOW(), ?, NOW())
    ")->execute([$sthPurLive, $stoId]);
    $pdo->prepare("
        INSERT INTO hq_invoice_lines (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, 'T218', 1, ?, ?, 'صنف A شراء لايف', 5.000, 20.00, 20.00, 1.0000)
    ")->execute([$sthPurLive, $itemA, $codeA]);

    // إضافة فاتورة بيع جديدة في True (يجب أن تستورد دائماً حتى في live)
    $sthSaleLive = 999954;
    $pdo->prepare("
        INSERT INTO br_invoices (agent_key, sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, ?, 'T218', 'BR-SALE-LIVE', 4, NULL, NOW(), ?, NOW())
    ")->execute([$agentKey, $sthSaleLive, $stoId]);
    $pdo->prepare("
        INSERT INTO br_invoice_lines (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, ?, 'T218', 1, ?, ?, 'صنف A بيع لايف', 2.000, 50.00, 20.00, 1.0000)
    ")->execute([$agentKey, $sthSaleLive, $itemA, $codeA]);

    // تشغيل الاستيراد
    $impResLive = stock_import_run($pdo, 1, 3);

    // فحص ما تم استيراده في stock_import_log
    $stCheckPur = $pdo->prepare("SELECT COUNT(*) FROM stock_import_log WHERE source = 'hq' AND src_key = 'hq:999953:T218'");
    $stCheckPur->execute();
    $purImported = (int) $stCheckPur->fetchColumn();

    $stCheckSale = $pdo->prepare("SELECT COUNT(*) FROM stock_import_log WHERE source = 'br' AND src_key = 'br:zahraa:999954:T218'");
    $stCheckSale->execute();
    $saleImported = (int) $stCheckSale->fetchColumn();

    // إعادة الوضع إلى mirror
    $pdo->exec("UPDATE settings SET setting_value = 'mirror' WHERE setting_key = 'stock_mode_3'");

    $pass5 = ($purImported === 0) && ($saleImported === 1);
    $details5 = sprintf(
        "شراء True في وضع live: %s | بيع True في وضع live: %s",
        $purImported === 0 ? 'لم يستورد ✓' : 'تم استيراده خطأ ✗',
        $saleImported === 1 ? 'تم استيراده بنجاح ✓' : 'لم يستورد ✗'
    );
    record_test(5, 'الفجالة live: منع استيراد الشراء من True واستيراد البيع دائماً', $pass5, $details5);
} catch (Throwable $e) {
    record_test(5, 'الفجالة live: منع استيراد الشراء من True واستيراد البيع دائماً', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٦: سطر بكود باكو بدون unit_factor يطبق معامل الكود، ولو مجهول unit:unknown
// ---------------------------------------------------------------------
try {
    $itemB = 999962;
    $codePack = 'PACK-999962';
    // تعريف باكو بمعامل 12 في hq_codes
    $pdo->prepare("
        INSERT INTO hq_codes (code, item_id, name, code_type, unit_factor, price, cost)
        VALUES (?, ?, 'صنف B باكو ١٢', 1, 12.000, 120.00, 60.00)
        ON DUPLICATE KEY UPDATE unit_factor = 12.000
    ")->execute([$codePack, $itemB]);

    // صنف C بمعامل غير معروف (unit_factor = 0 أو null)
    $itemC = 999963;
    $codeUnk = 'UNK-999963';
    $pdo->prepare("
        INSERT INTO hq_codes (code, item_id, name, code_type, unit_factor, price, cost)
        VALUES (?, ?, 'صنف C وحدة مجهولة', 0, 0.000, 10.00, 5.00)
        ON DUPLICATE KEY UPDATE unit_factor = 0.000
    ")->execute([$codeUnk, $itemC]);

    // فاتورة بيع تحتوي على باكو (كمية 2 باكو بدون unit_factor في السطر) وصنف مجهول (كمية 5 بدون معامل)
    $sthPack = 999957;
    $pdo->prepare("
        INSERT INTO br_invoices (agent_key, sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, ?, 'T218', 'BR-PACK-TEST', 4, NULL, NOW(), ?, NOW())
    ")->execute([$agentKey, $sthPack, $stoId]);

    $pdo->prepare("
        INSERT INTO br_invoice_lines (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, ?, 'T218', 1, ?, ?, 'صنف B باكو', 2.000, 120.00, 60.00, NULL)
    ")->execute([$agentKey, $sthPack, $itemB, $codePack]);

    $pdo->prepare("
        INSERT INTO br_invoice_lines (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, ?, 'T218', 2, ?, ?, 'صنف C مجهول', 5.000, 10.00, 5.00, NULL)
    ")->execute([$agentKey, $sthPack, $itemC, $codeUnk]);

    stock_import_run($pdo, 1, 3);

    // فحص سطور المستند المنشأ
    $stDocId = $pdo->prepare("SELECT doc_id FROM stock_import_log WHERE source = 'br' AND src_key = 'br:zahraa:999957:T218'");
    $stDocId->execute();
    $packDocId = (int) $stDocId->fetchColumn();

    $stLines = $pdo->prepare("SELECT item_id, code, unit_factor, qty_units, qty_pieces, notes FROM stock_doc_lines WHERE doc_id = ? ORDER BY line_no ASC");
    $stLines->execute([$packDocId]);
    $createdLines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    // السطر الأول: 2 باكو × 12 = -24 قطعة
    $line1 = $createdLines[0] ?? [];
    $line2 = $createdLines[1] ?? [];

    $line1Pass = ((float)$line1['unit_factor'] === 12.0) && (abs((float)$line1['qty_pieces'] - (-24.0)) < 0.001);
    $line2Pass = ((float)$line2['unit_factor'] === 1.0) && (abs((float)$line2['qty_pieces'] - (-5.0)) < 0.001) && ($line2['notes'] === 'unit:unknown');

    // فحص المطابقة لمعرفة هل صنف C يظهر كـ has_unknown_unit
    $reconC = stock_recon($pdo, 3);
    $cInRecon = null;
    foreach ($reconC['differences'] as $df) {
        if ($df['item_id'] === $itemC) {
            $cInRecon = $df;
            break;
        }
    }
    $hasUnkInRecon = ($cInRecon !== null && !empty($cInRecon['has_unknown_unit']));

    $pass6 = $line1Pass && $line2Pass && $hasUnkInRecon;
    $details6 = sprintf(
        "باكو (معامل الكود=%.1f, قطع=%.1f) | مجهول (معامل=%.1f, ملاحظة=%s, ظاهر في الفروق=%s)",
        (float)($line1['unit_factor'] ?? 0),
        (float)($line1['qty_pieces'] ?? 0),
        (float)($line2['unit_factor'] ?? 0),
        $line2['notes'] ?? '',
        $hasUnkInRecon ? 'نعم ✓' : 'لا'
    );
    record_test(6, 'سطر باكو يطبق معامل الكود (١٢) والوحدة المجهولة تسجل unit:unknown', $pass6, $details6);
} catch (Throwable $e) {
    record_test(6, 'سطر باكو يطبق معامل الكود (١٢) والوحدة المجهولة تسجل unit:unknown', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٧: تقرير stock_movement لفترة: أول المدة + الحركات = آخر المدة
// ---------------------------------------------------------------------
try {
    $fromD = date('Y-m-d', strtotime('-2 days'));
    $toD   = date('Y-m-d', strtotime('+1 day'));
    $rep = stock_movement_report($pdo, $fromD, $toD, 3);

    $rows = $rep['rows'];
    $allBalanced = true;
    $samples = [];

    foreach ($rows as $idx => $r) {
        $op   = (float) $r['opening_qty'];
        $pur  = (float) $r['purchase_in'];
        $trIn = (float) $r['transfer_in'];
        $sale = (float) $r['sale_out'];
        $trOut= (float) $r['transfer_out'];
        $adj  = (float) $r['adjust_qty'];
        $cl   = (float) $r['closing_qty'];

        $calculatedClosing = round($op + $pur + $trIn - $sale - $trOut + $adj, 3);
        $diff = abs($calculatedClosing - $cl);

        if ($diff > 0.001) {
            $allBalanced = false;
        }

        if (count($samples) < 3) {
            $samples[] = sprintf(
                "صنف %s: أول (%.3f) + وارد شراء (%.3f) + وارد تحويل (%.3f) - بيع (%.3f) - منصرف (%.3f) + تسوية (%.3f) = آخر (%.3f) [مطابق: %s]",
                $r['code'], $op, $pur, $trIn, $sale, $trOut, $adj, $cl, $diff <= 0.001 ? '✓' : '✗'
            );
        }
    }

    $pass7 = (!empty($rows)) && $allBalanced;
    $details7 = "تم فحص " . count($rows) . " صنف:\n     " . implode("\n     ", $samples);
    record_test(7, 'تقرير حركة المخزن: أول المدة + الحركات = آخر المدة لجميع السطور', $pass7, $details7);
} catch (Throwable $e) {
    record_test(7, 'تقرير حركة المخزن: أول المدة + الحركات = آخر المدة لجميع السطور', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٨: استيراد فاتورتين، الثانية بصنف غير موجود في hq_codes
// ---------------------------------------------------------------------
try {
    $sthOk = 999958;
    $sthBad = 999959;

    // فاتورة سليمة
    $pdo->prepare("
        INSERT INTO hq_invoices (sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, 'T218', 'HQ-OK-1', 2, 1000182, NOW(), ?, NOW())
    ")->execute([$sthOk, $stoId]);
    $pdo->prepare("
        INSERT INTO hq_invoice_lines (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, 'T218', 1, ?, ?, 'صنف A سليم', 1.000, 20.00, 20.00, 1.0000)
    ")->execute([$sthOk, $itemA, $codeA]);

    // فاتورة بها صنف غير مسجل في hq_codes
    $pdo->prepare("
        INSERT INTO hq_invoices (sth_id, suffix, doc_no, doc_type, supplier_id, invoice_date, company_branch, synced_at)
        VALUES (?, 'T218', 'HQ-BAD-2', 2, 1000182, NOW(), ?, NOW())
    ")->execute([$sthBad, $stoId]);
    $pdo->prepare("
        INSERT INTO hq_invoice_lines (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor)
        VALUES (?, 'T218', 1, 999999, 'NON-EXISTENT-CODE', 'صنف غير معرف', 1.000, 20.00, 20.00, 1.0000)
    ")->execute([$sthBad]);

    $impRes8 = stock_import_run($pdo, 1, 3);

    // فحص نجاح الأولى وفشل الثانية
    $stCheck1 = $pdo->prepare("SELECT COUNT(*) FROM stock_import_log WHERE source = 'hq' AND src_key = 'hq:999958:T218'");
    $stCheck1->execute();
    $ok1 = (int) $stCheck1->fetchColumn();

    $stCheck2 = $pdo->prepare("SELECT COUNT(*) FROM stock_import_log WHERE source = 'hq' AND src_key = 'hq:999959:T218'");
    $stCheck2->execute();
    $bad2 = (int) $stCheck2->fetchColumn();

    $hasFailedReason = false;
    foreach ($impRes8['errors'] as $err) {
        if (str_contains($err['src_key'], '999959') && str_contains($err['error'], 'hq_codes')) {
            $hasFailedReason = true;
            break;
        }
    }

    $pass8 = ($ok1 === 1) && ($bad2 === 0) && ($impRes8['failed'] >= 1) && $hasFailedReason;
    $details8 = sprintf(
        "الفاتورة السليمة مستوردة: %s | الفاتورة البايظة غير مستوردة: %s | عداد failed: %d | سبب الخطأ: %s",
        $ok1 === 1 ? 'نعم ✓' : 'لا ✗',
        $bad2 === 0 ? 'نعم (لم تدخل الدفتر) ✓' : 'دخلت خطأ ✗',
        $impRes8['failed'],
        $hasFailedReason ? 'كود الصنف غير موجود في hq_codes ✓' : 'لم يسجل'
    );
    record_test(8, 'استيراد فاتورتين: الأولى تستورد والثانية تفشل مع تسجيل السبب', $pass8, $details8);
} catch (Throwable $e) {
    record_test(8, 'استيراد فاتورتين: الأولى تستورد والثانية تفشل مع تسجيل السبب', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ٠٩: الوقت لاستيراد ١٠٠٠ فاتورة على Laragon + عدد الاستعلامات
// ---------------------------------------------------------------------
try {
    // إنشاء محاكاة سريعة لـ ١٠٠٠ فاتورة جديدة
    $sthBase = 991000;
    $nowBench = date('Y-m-d H:i:s');

    // إدراج ١٠٠٠ رأس فاتورة و١٠٠٠ سطر بدفعات
    $invValues = [];
    $invParams = [];
    $lineValues = [];
    $lineParams = [];

    for ($i = 1; $i <= 1000; $i++) {
        $sid = $sthBase + $i;
        $invValues[] = "(?, 'T218B', ?, 4, NULL, ?, ?, ?)";
        $invParams[] = $agentKey;
        $invParams[] = $sid;
        $invParams[] = $nowBench;
        $invParams[] = $stoId;
        $invParams[] = $nowBench;

        $lineValues[] = "(?, ?, 'T218B', 1, ?, ?, 'صنف A تجربة أداء', 1.000, 50.00, 20.00, 1.0000)";
        $lineParams[] = $agentKey;
        $lineParams[] = $sid;
        $lineParams[] = $itemA;
        $lineParams[] = $codeA;
    }

    // إدراج الدفعة في br_invoices و br_invoice_lines
    $pdo->beginTransaction();
    foreach (array_chunk($invValues, 200) as $chunkIdx => $chunkVals) {
        $offset = $chunkIdx * 200 * 5;
        $chunkParams = array_slice($invParams, $offset, count($chunkVals) * 5);
        $sql = "INSERT INTO br_invoices (agent_key, suffix, sth_id, doc_type, supplier_id, invoice_date, company_branch, synced_at) VALUES " . implode(',', $chunkVals);
        $pdo->prepare($sql)->execute($chunkParams);
    }
    foreach (array_chunk($lineValues, 200) as $chunkIdx => $chunkVals) {
        $offset = $chunkIdx * 200 * 4;
        $chunkParams = array_slice($lineParams, $offset, count($chunkVals) * 4);
        $sql = "INSERT INTO br_invoice_lines (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price, unit_factor) VALUES " . implode(',', $chunkVals);
        $pdo->prepare($sql)->execute($chunkParams);
    }
    $pdo->commit();

    // قياس وقت استيراد الـ ١٠٠٠ فاتورة
    $t0 = microtime(true);
    $benchRes = stock_import_run($pdo, 1, 3);
    $benchDuration = round(microtime(true) - $t0, 2);

    $pass9 = ($benchRes['imported'] >= 1000);
    $details9 = sprintf(
        "تم استيراد %d فاتورة في %.2f ثانية (متوسط %.2f مللي ثانية لكل فاتورة) | عدد الاستعلامات: %d",
        $benchRes['imported'],
        $benchDuration,
        ($benchDuration / max(1, $benchRes['imported'])) * 1000,
        $benchRes['queries']
    );
    record_test(9, 'قياس سرعة استيراد ١٠٠٠ فاتورة مجمعة بالدفعات وعدد الاستعلامات', $pass9, $details9);

    // تنظيف فواتير الاختبار
    $pdo->exec("DELETE FROM br_invoice_lines WHERE suffix = 'T218B'");
    $pdo->exec("DELETE FROM br_invoices WHERE suffix = 'T218B'");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    record_test(9, 'قياس سرعة استيراد ١٠٠٠ فاتورة مجمعة بالدفعات وعدد الاستعلامات', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// الحالة ١٠: erp_stock_enabled = 0 يمنع الاستيراد ويعطي رسالة مفهومة
// ---------------------------------------------------------------------
try {
    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'erp_stock_enabled'");

    $blocked = false;
    $msg = '';
    try {
        stock_import_run($pdo, 1, 3);
    } catch (RuntimeException $re) {
        $blocked = true;
        $msg = $re->getMessage();
    }

    $lazyRes = stock_import_maybe_run($pdo, 1);

    // إعادة التفعيل
    $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'erp_stock_enabled'");

    $pass10 = $blocked && str_contains($msg, 'مقفول') && ($lazyRes === null);
    $details10 = sprintf(
        "تم المنع: %s | نص الرسالة: %s | التشغيل الكسول أعاد: %s",
        $blocked ? 'نعم ✓' : 'لا ✗',
        $msg,
        $lazyRes === null ? 'null ✓' : 'قيمة'
    );
    record_test(10, 'erp_stock_enabled=0 يمنع الاستيراد المباشر والكسول بالكامل', $pass10, $details10);
} catch (Throwable $e) {
    record_test(10, 'erp_stock_enabled=0 يمنع الاستيراد المباشر والكسول بالكامل', false, 'استثناء: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// تنظيف نهائي آمن لبيانات الاختبار الخاصة بـ b218 (> 999950)
// ---------------------------------------------------------------------
cleanup_b218_fixtures($pdo);

echo "\n====================================================================\n";
$totalPassed = count(array_filter($results, fn($r) => $r['passed']));
$totalCount = count($results);
echo sprintf("النتيجة النهائية: %d من %d فحص نجح\n", $totalPassed, $totalCount);
if ($totalPassed === $totalCount) {
    echo "🎉 جميع اختبارات عقد الاكتمال لبريف ٢١٨ نجحت بنسبة ١٠٠٪\n";
} else {
    echo "⚠️ يوجد فحوصات لم تنجح، يرجى مراجعة التفاصيل أعلاه.\n";
}
echo "====================================================================\n";
