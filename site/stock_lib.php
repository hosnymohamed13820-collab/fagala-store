<?php
/**
 * stock_lib.php — مكتبة حركة وأرصدة المخزن (بريف ٢١٤)
 * نظام الفجالة ستور المالي
 *
 * كل الكتابة في دفتر المخزن stock_moves وأرصدة stock_balances بتتم من هنا وبس.
 * مفيش DELETE ولا UPDATE على stock_moves. الرصيد متخزن ومتحدث بحركات عكسية.
 */
declare(strict_types=1);

require_once __DIR__ . '/item_units_lib.php';

/**
 * هل المخزن على الموقع مفعل حالياً؟
 */
function stock_enabled(): bool
{
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'erp_stock_enabled'");
        $st->execute();
        $val = $st->fetchColumn();
        if ($val !== false) {
            return (string) $val === '1';
        }
    } catch (Throwable $e) {}

    if (function_exists('setting')) {
        return (string) setting('erp_stock_enabled', '0') === '1';
    }
    return false;
}

/**
 * التحقق من تفعيل المخزن وإلا رمي استثناء مفهوم.
 *
 * @throws RuntimeException
 */
function stock_require_enabled(): void
{
    if (!stock_enabled()) {
        throw new RuntimeException('المخزن على الموقع لسه مقفول.');
    }
}

/**
 * الحصول على وضع المخزن الحالي: mirror أو live.
 */
function stock_branch_mode(int $branchId): string
{
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $st->execute(['stock_mode_' . $branchId]);
        $val = $st->fetchColumn();
        if ($val !== false) {
            return $val === 'live' ? 'live' : 'mirror';
        }
    } catch (Throwable $e) {}

    if (function_exists('setting')) {
        $val = (string) setting('stock_mode_' . $branchId, 'mirror');
        return $val === 'live' ? 'live' : 'mirror';
    }
    return 'mirror';
}

/**
 * التحقق من أن المخازن المحددة على وضع live (تستخدم في ٢١٥ و٢١٧).
 *
 * @throws RuntimeException
 */
function stock_require_live(int ...$branchIds): void
{
    foreach ($branchIds as $bId) {
        if (stock_branch_mode($bId) !== 'live') {
            $info = stock_get_branch_info($bId);
            $name = $info['name'] ?? ('مخزن ' . $bId);
            throw new RuntimeException("مخزن $name لسه على وضع المرآة — مستنداته بتيجي من True.");
        }
    }
}

/**
 * جلب بيانات الفرع للتحقق والاسم.
 */
function stock_get_branch_info(int $branchId): ?array
{
    static $cache = [];
    if (isset($cache[$branchId])) {
        return $cache[$branchId];
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id, name, sto_id FROM branches WHERE id = ?');
    $st->execute([$branchId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cache[$branchId] = $row;
        return $row;
    }
    return null;
}

/**
 * إنشاء مستند مخزني جديد كمسودة مع سطوره وسجله (من غير حركات).
 *
 * @param PDO $pdo
 * @param string $type opening · adjust · transfer · purchase · purchase_return
 * @param array $head بيانات الرأس (branch_id, counter_branch_id, supplier_id, doc_date, ref_text, notes)
 * @param array $lines مصفوفة السطور
 * @param int $uid معرّف المستخدم المنشئ
 * @return int معرّف المستند المنشأ
 */
function stock_doc_create(PDO $pdo, string $type, array $head, array $lines, int $uid): int
{
    $branchId = (int) ($head['branch_id'] ?? 0);
    if ($branchId <= 0 || $branchId === 5) { // إشراقة (٥) برّه
        throw new RuntimeException('المخزن غير مدعوم أو غير صالح.');
    }

    $bInfo = stock_get_branch_info($branchId);
    if (!$bInfo) {
        throw new RuntimeException("المخزن غير موجود (id=$branchId).");
    }

    $counterBranchId = isset($head['counter_branch_id']) && $head['counter_branch_id'] !== null && $head['counter_branch_id'] !== ''
        ? (int) $head['counter_branch_id'] : null;
    $supplierId = isset($head['supplier_id']) && $head['supplier_id'] !== null && $head['supplier_id'] !== ''
        ? (int) $head['supplier_id'] : null;
    $docDate = !empty($head['doc_date']) ? (string) $head['doc_date'] : date('Y-m-d');
    $refText = isset($head['ref_text']) && $head['ref_text'] !== '' ? (string) $head['ref_text'] : null;
    $notes = isset($head['notes']) && $head['notes'] !== '' ? (string) $head['notes'] : null;

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare(
            'INSERT INTO stock_docs
               (doc_type, doc_no, doc_year, branch_id, counter_branch_id, supplier_id, status, doc_date, ref_text, notes, created_by, created_at)
             VALUES
               (?, NULL, NULL, ?, ?, ?, \'draft\', ?, ?, ?, ?, NOW())'
        );
        $st->execute([
            $type,
            $branchId,
            $counterBranchId,
            $supplierId,
            $docDate,
            $refText,
            $notes,
            $uid,
        ]);
        $docId = (int) $pdo->lastInsertId();

        // فحص معاملات الأكواد للسطور اللي ملهاش معامل سطر
        $missingCodes = [];
        foreach ($lines as $line) {
            $lf = isset($line['unit_factor']) && $line['unit_factor'] !== null && (float) $line['unit_factor'] > 0
                ? (float) $line['unit_factor'] : null;
            if ($lf === null && !empty($line['code'])) {
                $missingCodes[] = (string) $line['code'];
            }
        }
        $codeFactors = !empty($missingCodes) ? item_code_factors($pdo, $missingCodes) : [];

        $insLine = $pdo->prepare(
            'INSERT INTO stock_doc_lines
               (doc_id, line_no, item_id, code, unit_factor, qty_units, qty_pieces, unit_cost, notes)
             VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $lineNo = 1;
        foreach ($lines as $l) {
            $itemId = (int) ($l['item_id'] ?? 0);
            $code = (string) ($l['code'] ?? ('ITEM-' . $itemId));
            $qtyUnits = (float) ($l['qty_units'] ?? 0.0);
            $lineFactor = isset($l['unit_factor']) && $l['unit_factor'] !== null && (float) $l['unit_factor'] > 0
                ? (float) $l['unit_factor'] : null;
            $codeFactor = isset($codeFactors[$code]['unit_factor']) ? (float) $codeFactors[$code]['unit_factor'] : null;

            $piecesCalc = item_line_pieces($qtyUnits, $lineFactor, $codeFactor);
            $qtyPieces = $piecesCalc['pieces'];
            $effectiveFactor = $piecesCalc['factor'];
            $unitCost = isset($l['unit_cost']) && $l['unit_cost'] !== null && $l['unit_cost'] !== ''
                ? round((float) $l['unit_cost'], 4) : null;
            $lineNotes = isset($l['notes']) && $l['notes'] !== '' ? (string) $l['notes'] : null;

            $insLine->execute([
                $docId,
                $lineNo++,
                $itemId,
                $code,
                $effectiveFactor,
                round($qtyUnits, 3),
                $qtyPieces,
                $unitCost,
                $lineNotes,
            ]);
        }

        // تسجيل حدث الإنشاء
        $ev = $pdo->prepare('INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES (\'stock\', ?, \'created\', ?, NOW(), ?)');
        $ev->execute([
            $docId,
            $uid,
            json_encode([
                'doc_type'    => $type,
                'branch_id'   => $branchId,
                'lines_count' => count($lines),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }

        return $docId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * تحديث مستند مسودة فقط — بعد الترحيل يرفض التعديل.
 */
function stock_doc_update_draft(PDO $pdo, int $docId, array $head, array $lines, int $uid): void
{
    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare('SELECT status, branch_id FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $curr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            throw new RuntimeException("المستند غير موجود (id=$docId).");
        }
        if ($curr['status'] !== 'draft') {
            throw new RuntimeException('لا يمكن تعديل مستند غير مسودة.');
        }

        $branchId = isset($head['branch_id']) ? (int) $head['branch_id'] : (int) $curr['branch_id'];
        if ($branchId <= 0 || $branchId === 5) {
            throw new RuntimeException('المخزن غير مدعوم أو غير صالح.');
        }
        $counterBranchId = isset($head['counter_branch_id']) && $head['counter_branch_id'] !== null && $head['counter_branch_id'] !== ''
            ? (int) $head['counter_branch_id'] : null;
        $supplierId = isset($head['supplier_id']) && $head['supplier_id'] !== null && $head['supplier_id'] !== ''
            ? (int) $head['supplier_id'] : null;
        $docDate = !empty($head['doc_date']) ? (string) $head['doc_date'] : date('Y-m-d');
        $refText = isset($head['ref_text']) && $head['ref_text'] !== '' ? (string) $head['ref_text'] : null;
        $notes = isset($head['notes']) && $head['notes'] !== '' ? (string) $head['notes'] : null;

        $up = $pdo->prepare(
            'UPDATE stock_docs
                SET branch_id = ?, counter_branch_id = ?, supplier_id = ?, doc_date = ?, ref_text = ?, notes = ?
              WHERE id = ?'
        );
        $up->execute([$branchId, $counterBranchId, $supplierId, $docDate, $refText, $notes, $docId]);

        // حذف السطور القديمة وإعادة إدخال الجديدة
        $pdo->prepare('DELETE FROM stock_doc_lines WHERE doc_id = ?')->execute([$docId]);

        $missingCodes = [];
        foreach ($lines as $line) {
            $lf = isset($line['unit_factor']) && $line['unit_factor'] !== null && (float) $line['unit_factor'] > 0
                ? (float) $line['unit_factor'] : null;
            if ($lf === null && !empty($line['code'])) {
                $missingCodes[] = (string) $line['code'];
            }
        }
        $codeFactors = !empty($missingCodes) ? item_code_factors($pdo, $missingCodes) : [];

        $insLine = $pdo->prepare(
            'INSERT INTO stock_doc_lines
               (doc_id, line_no, item_id, code, unit_factor, qty_units, qty_pieces, unit_cost, notes)
             VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $lineNo = 1;
        foreach ($lines as $l) {
            $itemId = (int) ($l['item_id'] ?? 0);
            $code = (string) ($l['code'] ?? ('ITEM-' . $itemId));
            $qtyUnits = (float) ($l['qty_units'] ?? 0.0);
            $lineFactor = isset($l['unit_factor']) && $l['unit_factor'] !== null && (float) $l['unit_factor'] > 0
                ? (float) $l['unit_factor'] : null;
            $codeFactor = isset($codeFactors[$code]['unit_factor']) ? (float) $codeFactors[$code]['unit_factor'] : null;

            $piecesCalc = item_line_pieces($qtyUnits, $lineFactor, $codeFactor);
            $qtyPieces = $piecesCalc['pieces'];
            $effectiveFactor = $piecesCalc['factor'];
            $unitCost = isset($l['unit_cost']) && $l['unit_cost'] !== null && $l['unit_cost'] !== ''
                ? round((float) $l['unit_cost'], 4) : null;
            $lineNotes = isset($l['notes']) && $l['notes'] !== '' ? (string) $l['notes'] : null;

            $insLine->execute([
                $docId,
                $lineNo++,
                $itemId,
                $code,
                $effectiveFactor,
                round($qtyUnits, 3),
                $qtyPieces,
                $unitCost,
                $lineNotes,
            ]);
        }

        $ev = $pdo->prepare('INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES (\'stock\', ?, \'edited\', ?, NOW(), ?)');
        $ev->execute([
            $docId,
            $uid,
            json_encode(['lines_count' => count($lines)], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * توليد الرقم التالي للمستند بشكل مقفول ومحمي ضد التكرار والضياع.
 * 🔴 يجب استدعاؤها داخل نفس الـ transaction بتاع الترحيل.
 */
function stock_next_no(PDO $pdo, string $type, int $branchId, int $year): int
{
    // التأكد من وجود الصف
    $ins = $pdo->prepare(
        'INSERT INTO doc_seq (doc_type, branch_id, doc_year, last_no)
         VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE doc_type = VALUES(doc_type)'
    );
    $ins->execute([$type, $branchId, $year]);

    // قفل الصف بالـ FOR UPDATE
    $sel = $pdo->prepare(
        'SELECT last_no FROM doc_seq
          WHERE doc_type = ? AND branch_id = ? AND doc_year = ?
            FOR UPDATE'
    );
    $sel->execute([$type, $branchId, $year]);
    $curr = (int) $sel->fetchColumn();
    $next = $curr + 1;

    $up = $pdo->prepare(
        'UPDATE doc_seq SET last_no = ?
          WHERE doc_type = ? AND branch_id = ? AND doc_year = ?'
    );
    $up->execute([$next, $type, $branchId, $year]);

    return $next;
}

/**
 * تحويل سطور المستند إلى حركات مخزنية حسب نوعه.
 * الشراء والمرتجع اتضافوا في بريف ٢١٧؛ التحويلات هتتدمج لوحدها مع ٢١٥.
 */
function stock_doc_moves(array $doc, array $lines): array
{
    $moves = [];
    $type = (string) $doc['doc_type'];
    $branchId = (int) $doc['branch_id'];

    foreach ($lines as $l) {
        $itemId = (int) $l['item_id'];
        $lineId = isset($l['id']) ? (int) $l['id'] : null;
        $qtyPieces = (float) $l['qty_pieces'];
        $unitCost = isset($l['unit_cost']) && $l['unit_cost'] !== null && $l['unit_cost'] !== ''
            ? (float) $l['unit_cost'] : null;

        // سطر اتمسح من مسودة شراء بيتصفّر بدل الحذف؛ ماينتجش حركة صفرية وقت الترحيل.
        if (in_array($type, ['purchase', 'purchase_return'], true) && abs($qtyPieces) < 0.0005) {
            continue;
        }

        match ($type) {
            'opening' => $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $branchId,
                'is_transit'  => 0,
                'qty'         => $qtyPieces, // +qty_pieces
                'unit_cost'   => $unitCost,
                'doc_line_id' => $lineId,
            ],
            'adjust' => $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $branchId,
                'is_transit'  => 0,
                'qty'         => $qtyPieces, // + أو -
                'unit_cost'   => $unitCost,
                'doc_line_id' => $lineId,
            ],
            'purchase' => $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $branchId,
                'is_transit'  => 0,
                'qty'         => $qtyPieces,
                'unit_cost'   => $unitCost,
                'doc_line_id' => $lineId,
            ],
            'purchase_return' => $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $branchId,
                'is_transit'  => 0,
                'qty'         => -abs($qtyPieces),
                // الخروج بيتكلف بمتوسط المخزن الحالي، مش بسعر فاتورة المورد.
                'unit_cost'   => null,
                'doc_line_id' => $lineId,
            ],
            default => throw new RuntimeException("نوع مستند غير مدعوم حالياً: $type"),
        };
    }

    return $moves;
}

/**
 * قلب نظام المخزن — تطبيق الحركات المخزنية، حساب المتوسط، وقفل الأرصدة.
 * 🔴 يجب استدعاؤها داخل transaction صريح.
 *
 * @param PDO $pdo
 * @param int $docId
 * @param array $moves قائمة الحركات [item_id, branch_id, is_transit, qty, unit_cost|null, doc_line_id, reverses_move_id]
 * @param int $uid
 * @return array قائمة معرّفات الحركات المُنشأة
 */
function stock_apply_moves(PDO $pdo, int $docId, array $moves, int $uid): array
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('stock_apply_moves يجب أن يعمل داخل transaction.');
    }

    if (empty($moves)) {
        return [];
    }

    // 1. تجميع المفاتيح الفريدة للأرصدة المتأثرة
    $keys = [];
    foreach ($moves as $m) {
        $iId = (int) $m['item_id'];
        $bId = (int) $m['branch_id'];
        $tr  = !empty($m['is_transit']) ? 1 : 0;
        $k   = "$iId:$bId:$tr";
        $keys[$k] = [$iId, $bId, $tr];
    }

    // 2. ترتيب ثابت للمفاتيح لمنع أي Deadlock بين المعاملات المتوازية
    uasort($keys, function ($a, $b) {
        if ($a[0] !== $b[0]) return $a[0] <=> $b[0]; // item_id
        if ($a[1] !== $b[1]) return $a[1] <=> $b[1]; // branch_id
        return $a[2] <=> $b[2];                      // is_transit
    });

    // 3. قفل صفوف الأرصدة بـ FOR UPDATE بالترتيب الثابت
    $insBal = $pdo->prepare(
        'INSERT INTO stock_balances (item_id, branch_id, is_transit, qty, avg_cost)
         VALUES (?, ?, ?, 0.000, 0.0000)
         ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)'
    );
    $selBal = $pdo->prepare(
        'SELECT qty, avg_cost FROM stock_balances
          WHERE item_id = ? AND branch_id = ? AND is_transit = ?
            FOR UPDATE'
    );

    $balances = [];
    foreach ($keys as $k => [$iId, $bId, $tr]) {
        $insBal->execute([$iId, $bId, $tr]);
        $selBal->execute([$iId, $bId, $tr]);
        $row = $selBal->fetch(PDO::FETCH_ASSOC);
        $balances[$k] = [
            'item_id'      => $iId,
            'branch_id'    => $bId,
            'is_transit'   => $tr,
            'qty'          => round((float) ($row['qty'] ?? 0.0), 3),
            'avg_cost'     => round((float) ($row['avg_cost'] ?? 0.0), 4),
            'last_move_id' => null,
        ];
    }

    // 4. تطبيق الحركات سطر بسطر مع تحديث الرصيد والمتوسط في الذاكرة
    $insMove = $pdo->prepare(
        'INSERT INTO stock_moves
           (doc_id, doc_line_id, item_id, branch_id, is_transit, qty, unit_cost, qty_after, avg_cost_after, move_at, created_by, reverses_move_id)
         VALUES
           (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
    );

    $allowNegative = true;
    try {
        $stNeg = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'stock_allow_negative'");
        $stNeg->execute();
        $valNeg = $stNeg->fetchColumn();
        if ($valNeg !== false) {
            $allowNegative = ((string) $valNeg === '1');
        } elseif (function_exists('setting')) {
            $allowNegative = (string) setting('stock_allow_negative', '1') === '1';
        }
    } catch (Throwable $e) {}

    $createdMoves = [];
    $negativeNotes = [];

    foreach ($moves as $m) {
        $itemId    = (int) $m['item_id'];
        $branchId  = (int) $m['branch_id'];
        $isTransit = !empty($m['is_transit']) ? 1 : 0;
        $qty       = round((float) $m['qty'], 3);
        $docLineId = isset($m['doc_line_id']) && $m['doc_line_id'] !== null ? (int) $m['doc_line_id'] : null;
        $revId     = isset($m['reverses_move_id']) && $m['reverses_move_id'] !== null ? (int) $m['reverses_move_id'] : null;

        $k = "$itemId:$branchId:$isTransit";
        $qtyBefore = $balances[$k]['qty'];
        $avgBefore = $balances[$k]['avg_cost'];

        $unitCostInput = isset($m['unit_cost']) && $m['unit_cost'] !== null ? (float) $m['unit_cost'] : null;

        // حساب متوسط التكلفة المتحرك:
        // - وارد بتكلفة (unit_cost مش null) والكمية > 0:
        //   avg = (max(qty_before,0) × avg_before + qty × unit_cost) ÷ (max(qty_before,0) + qty)
        // - خارج أو وارد من غير تكلفة:
        //   avg زي ما هو، و unit_cost الحركة = avg_before.
        if ($unitCostInput !== null && $qty > 0.0) {
            $costVal = round($unitCostInput, 4);
            $baseQty = max($qtyBefore, 0.0);
            $sumQty  = $baseQty + $qty;
            if ($sumQty > 0.0) {
                $newAvg = round((($baseQty * $avgBefore) + ($qty * $costVal)) / $sumQty, 4);
            } else {
                $newAvg = $costVal;
            }
            $moveUnitCost = $costVal;
        } else {
            $moveUnitCost = $avgBefore;
            $newAvg       = $avgBefore;
        }

        $qtyAfter = round($qtyBefore + $qty, 3);

        // التحقق من الرصيد السالب
        if ($qtyAfter < 0.0) {
            if (!$allowNegative) {
                throw new RuntimeException("الرصيد لا يسمح بالسالب للصنف $itemId في المخزن $branchId.");
            }
            $negativeNotes[] = [
                'item_id'   => $itemId,
                'branch_id' => $branchId,
                'qty_after' => $qtyAfter,
            ];
        }

        // حفظ الرصيد الجديد في الذاكرة للسطور التالية لنفس الصنف
        $balances[$k]['qty']      = $qtyAfter;
        $balances[$k]['avg_cost'] = $newAvg;

        $insMove->execute([
            $docId,
            $docLineId,
            $itemId,
            $branchId,
            $isTransit,
            $qty,
            $moveUnitCost,
            $qtyAfter,
            $newAvg,
            $uid,
            $revId,
        ]);
        $moveId = (int) $pdo->lastInsertId();
        $balances[$k]['last_move_id'] = $moveId;
        $createdMoves[] = $moveId;
    }

    // 5. حفظ الأرصدة النهائية المقفولة في stock_balances
    $upBal = $pdo->prepare(
        'UPDATE stock_balances
            SET qty = ?, avg_cost = ?, last_move_id = ?, updated_at = NOW()
          WHERE item_id = ? AND branch_id = ? AND is_transit = ?'
    );
    foreach ($balances as $b) {
        $upBal->execute([
            $b['qty'],
            $b['avg_cost'],
            $b['last_move_id'],
            $b['item_id'],
            $b['branch_id'],
            $b['is_transit'],
        ]);
    }

    // 6. تسجيل ملاحظات الرصيد السالب في doc_events
    if (!empty($negativeNotes)) {
        $evNote = $pdo->prepare(
            'INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
             VALUES (\'stock\', ?, \'note\', ?, NOW(), ?)'
        );
        foreach ($negativeNotes as $nn) {
            $evNote->execute([
                $docId,
                $uid,
                json_encode([
                    'note'      => "رصيد سالب: صنف {$nn['item_id']} مخزن {$nn['branch_id']} رصيد {$nn['qty_after']}",
                    'item_id'   => $nn['item_id'],
                    'branch_id' => $nn['branch_id'],
                    'qty_after' => $nn['qty_after'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    return $createdMoves;
}

/**
 * ترحيل المستند المخزني وتوليد الرقم والحركات.
 */
function stock_post(PDO $pdo, int $docId, int $uid): void
{
    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        stock_require_enabled();

        // قفل المستند
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("المستند غير موجود (id=$docId).");
        }
        if ($doc['status'] !== 'draft') {
            throw new RuntimeException("المستند ليس مسودة (الحالة: {$doc['status']}).");
        }

        // جلب السطور
        $stLines = $pdo->prepare('SELECT * FROM stock_doc_lines WHERE doc_id = ? ORDER BY line_no ASC');
        $stLines->execute([$docId]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            throw new RuntimeException('لا توجد سطور في المستند للترحيل.');
        }

        // توليد الرقم داخل الـ transaction
        $docYear = (int) date('Y', strtotime((string) $doc['doc_date']));
        $docNo   = stock_next_no($pdo, (string) $doc['doc_type'], (int) $doc['branch_id'], $docYear);

        // تحديث الرقم في المستند
        $pdo->prepare('UPDATE stock_docs SET doc_no = ?, doc_year = ? WHERE id = ?')
            ->execute([$docNo, $docYear, $docId]);
        $doc['doc_no'] = $docNo;
        $doc['doc_year'] = $docYear;

        // تحويل السطور إلى حركات
        $moves = stock_doc_moves($doc, $lines);

        // تطبيق الحركات
        stock_apply_moves($pdo, $docId, $moves, $uid);

        // تحديث حالة المستند إلى posted
        $pdo->prepare('UPDATE stock_docs SET status = \'posted\', posted_by = ?, posted_at = NOW() WHERE id = ?')
            ->execute([$uid, $docId]);

        // تسجيل حدث الترحيل
        $ev = $pdo->prepare('INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES (\'stock\', ?, \'posted\', ?, NOW(), ?)');
        $ev->execute([
            $docId,
            $uid,
            json_encode([
                'doc_no'      => $docNo,
                'doc_year'    => $docYear,
                'moves_count' => count($moves),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * إلغاء مستند مرحل بحركات عكسية — مفيش DELETE ولا UPDATE على stock_moves.
 */
function stock_cancel(PDO $pdo, int $docId, int $uid, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('سبب الإلغاء مطلوب.');
    }

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        stock_require_enabled();

        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("المستند غير موجود (id=$docId).");
        }
        if ($doc['status'] !== 'posted') {
            throw new RuntimeException("لا يمكن إلغاء مستند غير مرحل (الحالة: {$doc['status']}).");
        }

        // جلب كل الحركات الأصلية التي لم يتم عكسها بالفعل
        $stMoves = $pdo->prepare(
            'SELECT m.*
               FROM stock_moves m
              WHERE m.doc_id = ?
                AND m.id NOT IN (
                    SELECT r.reverses_move_id FROM stock_moves r WHERE r.reverses_move_id IS NOT NULL
                )
              ORDER BY m.id ASC'
        );
        $stMoves->execute([$docId]);
        $originalMoves = $stMoves->fetchAll(PDO::FETCH_ASSOC);

        $reversingMoves = [];
        foreach ($originalMoves as $om) {
            $reversingMoves[] = [
                'item_id'          => (int) $om['item_id'],
                'branch_id'        => (int) $om['branch_id'],
                'is_transit'       => (int) $om['is_transit'],
                'qty'              => -((float) $om['qty']),
                'unit_cost'        => (float) $om['unit_cost'],
                'doc_line_id'      => $om['doc_line_id'] !== null ? (int) $om['doc_line_id'] : null,
                'reverses_move_id' => (int) $om['id'],
            ];
        }

        if (!empty($reversingMoves)) {
            stock_apply_moves($pdo, $docId, $reversingMoves, $uid);
        }

        // تحديث المستند لـ cancelled
        $up = $pdo->prepare(
            'UPDATE stock_docs
                SET status = \'cancelled\',
                    cancelled_by = ?,
                    cancelled_at = NOW(),
                    cancel_reason = ?
              WHERE id = ?'
        );
        $up->execute([$uid, $reason, $docId]);

        // تسجيل حدث الإلغاء
        $ev = $pdo->prepare('INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES (\'stock\', ?, \'cancelled\', ?, NOW(), ?)');
        $ev->execute([
            $docId,
            $uid,
            json_encode([
                'reason'         => $reason,
                'reversed_count' => count($reversingMoves),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * قراءة رصيد صنف ومخزن محدد.
 */
function stock_balance(PDO $pdo, int $itemId, int $branchId, int $isTransit = 0): array
{
    $st = $pdo->prepare(
        'SELECT item_id, branch_id, is_transit, qty, avg_cost, last_move_id, updated_at
           FROM stock_balances
          WHERE item_id = ? AND branch_id = ? AND is_transit = ?'
    );
    $st->execute([$itemId, $branchId, $isTransit]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return [
            'item_id'      => (int) $row['item_id'],
            'branch_id'    => (int) $row['branch_id'],
            'is_transit'   => (int) $row['is_transit'],
            'qty'          => (float) $row['qty'],
            'avg_cost'     => (float) $row['avg_cost'],
            'last_move_id' => $row['last_move_id'] !== null ? (int) $row['last_move_id'] : null,
            'updated_at'   => (string) $row['updated_at'],
        ];
    }
    return [
        'item_id'      => $itemId,
        'branch_id'    => $branchId,
        'is_transit'   => $isTransit,
        'qty'          => 0.0,
        'avg_cost'     => 0.0,
        'last_move_id' => null,
        'updated_at'   => null,
    ];
}

/**
 * جلب قائمة الحركات المخزنية مع الفلاتر.
 */
function stock_moves_list(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    if (!empty($filters['item_id'])) {
        $where[] = 'm.item_id = ?';
        $params[] = (int) $filters['item_id'];
    }
    if (!empty($filters['branch_id'])) {
        $where[] = 'm.branch_id = ?';
        $params[] = (int) $filters['branch_id'];
    }
    if (isset($filters['is_transit']) && $filters['is_transit'] !== '') {
        $where[] = 'm.is_transit = ?';
        $params[] = !empty($filters['is_transit']) ? 1 : 0;
    }
    if (!empty($filters['doc_id'])) {
        $where[] = 'm.doc_id = ?';
        $params[] = (int) $filters['doc_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 'm.move_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 'm.move_at <= ?';
        $params[] = $filters['to'] . ' 23:59:59';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 100;
    $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

    $sql = "
        SELECT m.*, d.doc_type, d.doc_no, d.doc_year, d.doc_date, d.ref_text,
               b.name AS branch_name
          FROM stock_moves m
          JOIN stock_docs d ON d.id = m.doc_id
          LEFT JOIN branches b ON b.id = m.branch_id
         $whereSql
         ORDER BY m.id DESC
         LIMIT $limit OFFSET $offset
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function ($r) {
        return [
            'id'               => (int) $r['id'],
            'doc_id'           => (int) $r['doc_id'],
            'doc_line_id'      => $r['doc_line_id'] !== null ? (int) $r['doc_line_id'] : null,
            'item_id'          => (int) $r['item_id'],
            'branch_id'        => (int) $r['branch_id'],
            'branch_name'      => (string) ($r['branch_name'] ?? ''),
            'is_transit'       => (int) $r['is_transit'],
            'qty'              => (float) $r['qty'],
            'unit_cost'        => (float) $r['unit_cost'],
            'qty_after'        => (float) $r['qty_after'],
            'avg_cost_after'   => (float) $r['avg_cost_after'],
            'move_at'          => (string) $r['move_at'],
            'created_by'       => (int) $r['created_by'],
            'reverses_move_id' => $r['reverses_move_id'] !== null ? (int) $r['reverses_move_id'] : null,
            'doc_type'         => (string) $r['doc_type'],
            'doc_no'           => $r['doc_no'] !== null ? (int) $r['doc_no'] : null,
            'doc_year'         => $r['doc_year'] !== null ? (int) $r['doc_year'] : null,
            'doc_date'         => (string) $r['doc_date'],
            'ref_text'         => (string) ($r['ref_text'] ?? ''),
        ];
    }, $rows);
}

/**
 * إنشاء وترحيل رصيد افتتاحي لمخزن من المرآة.
 * مرة واحدة فقط لكل مخزن: لو فيه opening مرحل مسبقاً يرفض.
 *
 * @param PDO $pdo
 * @param int $branchId رقم المخزن (1 بونجور · 2 اي بي سي · 3 الفجالة · 4 دجلة · 6 الإدارة)
 * @param int $uid معرّف المستخدم
 * @return int معرّف المستند المنشأ والمرحل
 */
function stock_opening_from_mirror(PDO $pdo, int $branchId, int $uid): int
{
    if ($branchId <= 0 || $branchId === 5) { // إشراقة (٥) برّه
        throw new RuntimeException('المخزن غير مدعوم للرصيد الافتتاحي.');
    }

    $bInfo = stock_get_branch_info($branchId);
    if (!$bInfo) {
        throw new RuntimeException("المخزن غير موجود (id=$branchId).");
    }

    // التحقق من عدم وجود رصيد افتتاحي مرحل بالفعل لهذا المخزن
    $chk = $pdo->prepare("SELECT id FROM stock_docs WHERE branch_id = ? AND doc_type = 'opening' AND status = 'posted' LIMIT 1");
    $chk->execute([$branchId]);
    if ($chk->fetch()) {
        throw new RuntimeException("يوجد رصيد افتتاحي مرحل بالفعل لهذا المخزن ({$bInfo['name']}).");
    }

    // قراءة البيانات من المرآة:
    // الإدارة (6) من hq_balances sto_id=1
    // الفروع (1,2,3,4) من hq_branch_balances b.sto_id = ? (مع fallback لـ hq_balances لو جدول الفروع فاضي)
    if ($branchId === 6) { // الإدارة
        $sql = "
            SELECT b.item_id, b.balance,
                   COALESCE(
                       (SELECT code FROM hq_codes c WHERE c.item_id = b.item_id ORDER BY c.code_type ASC, c.code ASC LIMIT 1),
                       CONCAT('ITEM-', b.item_id)
                   ) AS code,
                   COALESCE(
                       (SELECT cost FROM hq_codes c WHERE c.item_id = b.item_id AND c.cost IS NOT NULL ORDER BY c.code_type ASC LIMIT 1),
                       0.0000
                   ) AS cost
              FROM hq_balances b
             WHERE b.sto_id = 1 AND b.balance <> 0
             ORDER BY b.item_id ASC
        ";
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stoId = (int) $bInfo['sto_id'];
        if ($stoId <= 0) {
            throw new RuntimeException("المخزن ليس له sto_id مربوط (id=$branchId).");
        }

        // فحص وجود صفوف في hq_branch_balances
        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM hq_branch_balances WHERE sto_id = ? AND balance <> 0');
        $cntSt->execute([$stoId]);
        $hasBranchTable = (int) $cntSt->fetchColumn() > 0;

        $sourceTable = $hasBranchTable ? 'hq_branch_balances' : 'hq_balances';

        $sql = "
            SELECT b.item_id, b.balance,
                   COALESCE(
                       (SELECT code FROM hq_codes c WHERE c.item_id = b.item_id ORDER BY c.code_type ASC, c.code ASC LIMIT 1),
                       CONCAT('ITEM-', b.item_id)
                   ) AS code,
                   COALESCE(
                       (SELECT cost FROM hq_codes c WHERE c.item_id = b.item_id AND c.cost IS NOT NULL ORDER BY c.code_type ASC LIMIT 1),
                       0.0000
                   ) AS cost
              FROM $sourceTable b
             WHERE b.sto_id = ? AND b.balance <> 0
             ORDER BY b.item_id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$stoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($rows)) {
        throw new RuntimeException("لا توجد أرصدة للمخزن {$bInfo['name']} في المرآة.");
    }

    $lines = [];
    $lineNo = 1;
    foreach ($rows as $r) {
        $bal = round((float) $r['balance'], 3);
        if ($bal == 0.0) {
            continue;
        }
        $lines[] = [
            'line_no'     => $lineNo++,
            'item_id'     => (int) $r['item_id'],
            'code'        => (string) $r['code'],
            'unit_factor' => 1.0,
            'qty_units'   => $bal,
            'qty_pieces'  => $bal,
            'unit_cost'   => round((float) $r['cost'], 4),
            'notes'       => 'رصيد افتتاحي من المرآة',
        ];
    }

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $docId = stock_doc_create($pdo, 'opening', [
            'branch_id' => $branchId,
            'doc_date'  => date('Y-m-d'),
            'notes'     => 'رصيد افتتاحي من المرآة لمخزن ' . $bInfo['name'],
        ], $lines, $uid);

        stock_post($pdo, $docId, $uid);

        if ($ownTx) {
            $pdo->commit();
        }

        return $docId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
