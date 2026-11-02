<?php
/**
 * stock_transfer_lib.php — مكتبة التحويلات بين المخازن (بريف ٢١٥)
 * نظام الفجالة ستور المالي
 *
 * كل الحركات المخزنية تتم عبر stock_lib.php (stock_apply_moves, stock_doc_create, stock_next_no).
 * مفيش كتابة مباشرة في stock_moves أو stock_balances من هنا.
 */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/item_units_lib.php';

/**
 * هل المستخدم الحالي مسموح له يفك فروق الزيادة؟
 *
 * بريف ٢١٥ر: المالك الحقيقي أو اللي معاه الجزء 'transfers:resolve' بالتحديد.
 * ⚠️ مقصود إننا **مش** بنستخدم can('transfers:resolve') — لأن can() بترجّع
 * true لأي حد معاه موديول 'transfers' كامل (wildcard)، وفك الزيادة بيحرّك
 * بضاعة بين الفروع فلازم صلاحية صريحة.
 */
function transfers_can_resolve(): bool
{
    $realOwner = function_exists('is_real_owner')
        ? is_real_owner()
        : !empty($_SESSION['user']['is_owner']);
    if ($realOwner) {
        return true;
    }

    return in_array('transfers:resolve', $_SESSION['permissions'] ?? [], true);
}

/**
 * إنشاء طلب تحويل جديد كمسودة في حالة 'requested' (بدون حركات مخزنية).
 *
 * @param PDO $pdo
 * @param int $fromBranchId المخزن الراسل
 * @param int $toBranchId المخزن المستلم
 * @param array $lines سطور التحويل
 * @param int $uid معرّف المستخدم
 * @param string|null $notes ملاحظات
 * @return int معرّف مستند التحويل
 */
function transfer_create(PDO $pdo, int $fromBranchId, int $toBranchId, array $lines, int $uid, ?string $notes = null): int
{
    stock_require_enabled();

    if ($fromBranchId <= 0 || $toBranchId <= 0) {
        throw new RuntimeException('المخزن الراسل والمستلم مطلوبان.');
    }
    if ($fromBranchId === $toBranchId) {
        throw new RuntimeException('لا يمكن التحويل لنفس المخزن.');
    }
    if ($fromBranchId === 5 || $toBranchId === 5) {
        throw new RuntimeException('مخزن إشراقة غير مدعوم في تحويلات المخزن.');
    }
    if (empty($lines)) {
        throw new RuntimeException('يجب إضافة صنف واحد على الأقل للتحويل.');
    }

    $head = [
        'branch_id'         => $fromBranchId,
        'counter_branch_id' => $toBranchId,
        'doc_date'          => date('Y-m-d'),
        'notes'             => $notes,
    ];

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $docId = stock_doc_create($pdo, 'transfer', $head, $lines, $uid);

        // تعديل الحالة إلى requested وتسجيل الحدث
        $st = $pdo->prepare("UPDATE stock_docs SET status = 'requested' WHERE id = ?");
        $st->execute([$docId]);

        $ev = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'status:requested', ?, NOW(), ?)");
        $ev->execute([
            $docId,
            $uid,
            json_encode([
                'from_branch_id' => $fromBranchId,
                'to_branch_id'   => $toBranchId,
                'lines_count'    => count($lines),
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
 * تجهيز التحويل (requested -> preparing) مع إمكانية تعديل السطور.
 */
function transfer_prepare(PDO $pdo, int $docId, array $lines, int $uid, ?string $notes = null): void
{
    stock_require_enabled();

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("التحويل غير موجود (id=$docId).");
        }
        if ($doc['doc_type'] !== 'transfer') {
            throw new RuntimeException('نوع المستند ليس تحويلاً.');
        }
        if (!in_array($doc['status'], ['requested', 'preparing'], true)) {
            throw new RuntimeException("لا يمكن تجهيز التحويل في حالته الحالية ({$doc['status']}).");
        }

        if (!empty($lines)) {
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
        }

        $upParams = ['preparing'];
        $upSql = "UPDATE stock_docs SET status = ?";
        if ($notes !== null) {
            $upSql .= ", notes = ?";
            $upParams[] = $notes;
        }
        $upSql .= " WHERE id = ?";
        $upParams[] = $docId;
        $pdo->prepare($upSql)->execute($upParams);

        $ev = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'status:preparing', ?, NOW(), ?)");
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
 * إرسال التحويل (requested/preparing -> sent).
 * يخصم من الراسل (is_transit=0) ويضيف للمستلم في الطريق (is_transit=1).
 * يتم حجز رقم المستند وتوليد الحركات وإرسال إشعار للمستلم.
 */
function transfer_send(PDO $pdo, int $docId, int $uid): int
{
    stock_require_enabled();

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("التحويل غير موجود (id=$docId).");
        }
        if ($doc['doc_type'] !== 'transfer') {
            throw new RuntimeException('نوع المستند ليس تحويلاً.');
        }
        if (!in_array($doc['status'], ['requested', 'preparing'], true)) {
            throw new RuntimeException("لا يمكن إرسال التحويل — حالته الحالية: {$doc['status']}.");
        }

        $fromBranchId = (int) $doc['branch_id'];
        $toBranchId   = (int) $doc['counter_branch_id'];

        // شرط live للمخزنين
        stock_require_live($fromBranchId, $toBranchId);

        // جلب السطور
        $stLines = $pdo->prepare('SELECT * FROM stock_doc_lines WHERE doc_id = ? ORDER BY line_no ASC');
        $stLines->execute([$docId]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            throw new RuntimeException('لا توجد سطور في التحويل للإرسال.');
        }

        // توليد رقم المستند
        $docYear = (int) date('Y', strtotime((string) $doc['doc_date']));
        $docNo   = stock_next_no($pdo, 'transfer', $fromBranchId, $docYear);

        // تجهيز الحركات المخزنية:
        // لكل سطر: الراسل -qty (is_transit=0) والمستلم +qty (is_transit=1)
        $moves = [];
        foreach ($lines as $l) {
            $itemId = (int) $l['item_id'];
            $lineId = (int) $l['id'];
            $qtyPieces = (float) $l['qty_pieces'];

            if ($qtyPieces <= 0.0) {
                continue;
            }

            // حركة خروج من الراسل
            $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $fromBranchId,
                'is_transit'  => 0,
                'qty'         => -$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => $lineId,
            ];

            // حركة دخول في طريق المستلم
            $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $toBranchId,
                'is_transit'  => 1,
                'qty'         => +$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => $lineId,
            ];
        }

        // تطبيق الحركات
        stock_apply_moves($pdo, $docId, $moves, $uid);

        // تحديث المستند
        $up = $pdo->prepare('UPDATE stock_docs SET doc_no = ?, doc_year = ?, status = \'sent\' WHERE id = ?');
        $up->execute([$docNo, $docYear, $docId]);

        // تسجيل حدث الإرسال
        $ev = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'status:sent', ?, NOW(), ?)");
        $ev->execute([
            $docId,
            $uid,
            json_encode([
                'doc_no'   => $docNo,
                'doc_year' => $docYear,
                'moves'    => count($moves),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // إشعار للمستلم
        try {
            if (is_file(__DIR__ . '/push_lib.php')) {
                require_once __DIR__ . '/push_lib.php';
                if (function_exists('notify')) {
                    $stUsers = $pdo->prepare('SELECT id FROM users WHERE branch_id = ? AND is_active = 1');
                    $stUsers->execute([$toBranchId]);
                    $receiverUids = array_map(fn($r) => (int) $r['id'], $stUsers->fetchAll(PDO::FETCH_ASSOC));
                    if (!empty($receiverUids)) {
                        $fromInfo = stock_get_branch_info($fromBranchId);
                        $fromName = $fromInfo['name'] ?? ('مخزن ' . $fromBranchId);
                        notify(
                            $pdo,
                            $receiverUids,
                            'transfer',
                            "تحويل وارد جديد #$docNo",
                            "تم إرسال بضاعة من مخزن $fromName إلى فرعكم.",
                            "transfers.php?tab=in&id=$docId"
                        );
                    }
                }
            }
        } catch (Throwable) {}

        if ($ownTx) {
            $pdo->commit();
        }

        return $docNo;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * تسجيل عد الصنف عند الاستلام (حفظ على blur بدون حركات مخزنية).
 * يدعم التمرير عبر lineId مباشرة أو عبر كود الصنف (للأكواد المربوطة).
 */
function transfer_count(
    PDO $pdo,
    int $docId,
    int $lineId,
    float $pieces,
    int $uid,
    ?string $code = null,
    ?float $units = null,
    string $mode = 'set'
): array {
    $st = $pdo->prepare('SELECT status, doc_type FROM stock_docs WHERE id = ?');
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc || $doc['doc_type'] !== 'transfer') {
        throw new RuntimeException("التحويل غير موجود (id=$docId).");
    }
    if ($doc['status'] !== 'sent') {
        throw new RuntimeException("العد متاح فقط للتحويلات المرسلة (في الطريق) — الحالة: {$doc['status']}.");
    }

    // إذا تم تمرير كود بدلاً من السطر أو مع السطر للتحقق (كود مربوط)
    $resolvedItemId = null;
    if ($code !== null && trim($code) !== '') {
        $stCode = $pdo->prepare('SELECT item_id, unit_factor FROM hq_codes WHERE code = ? LIMIT 1');
        $stCode->execute([trim($code)]);
        $hqItem = $stCode->fetch(PDO::FETCH_ASSOC);
        if ($hqItem) {
            $resolvedItemId = (int) $hqItem['item_id'];
            $factor = (float) ($hqItem['unit_factor'] ?? 1.0);
            if ($factor <= 0) $factor = 1.0;
            if ($units !== null) {
                $pieces = round($units * $factor, 3);
            }

            if ($lineId <= 0) {
                $stLineSearch = $pdo->prepare('SELECT id FROM stock_doc_lines WHERE doc_id = ? AND item_id = ? ORDER BY id ASC LIMIT 1');
                $stLineSearch->execute([$docId, $resolvedItemId]);
                $matchedLine = $stLineSearch->fetch(PDO::FETCH_ASSOC);
                if ($matchedLine) {
                    $lineId = (int) $matchedLine['id'];
                } else {
                    throw new RuntimeException("الصنف المربوط بالكود $code غير موجود في هذا التحويل.");
                }
            }
        }
    }

    if ($lineId <= 0) {
        throw new RuntimeException('معرّف السطر غير صالح.');
    }

    $stLine = $pdo->prepare('SELECT id, item_id FROM stock_doc_lines WHERE id = ? AND doc_id = ?');
    $stLine->execute([$lineId, $docId]);
    $lineRow = $stLine->fetch(PDO::FETCH_ASSOC);
    if (!$lineRow) {
        throw new RuntimeException("السطر $lineId لا ينتمي للتحويل $docId.");
    }

    $finalPieces = round($pieces, 3);
    if ($mode === 'add') {
        $insRx = $pdo->prepare(
            'INSERT INTO stock_transfer_rx (doc_line_id, received_pieces, counted_by, counted_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 received_pieces = received_pieces + VALUES(received_pieces),
                 counted_by = VALUES(counted_by),
                 counted_at = VALUES(counted_at)'
        );
        $insRx->execute([$lineId, $finalPieces, $uid]);

        $stCur = $pdo->prepare('SELECT received_pieces FROM stock_transfer_rx WHERE doc_line_id = ?');
        $stCur->execute([$lineId]);
        $finalPieces = (float) $stCur->fetchColumn();
    } else {
        $insRx = $pdo->prepare(
            'INSERT INTO stock_transfer_rx (doc_line_id, received_pieces, counted_by, counted_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 received_pieces = VALUES(received_pieces),
                 counted_by = VALUES(counted_by),
                 counted_at = VALUES(counted_at)'
        );
        $insRx->execute([$lineId, $finalPieces, $uid]);
    }

    return [
        'line_id'         => $lineId,
        'received_pieces' => $finalPieces,
        'item_id'         => (int) ($lineRow['item_id'] ?? $resolvedItemId),
    ];
}

/**
 * تأكيد استلام التحويل (sent -> received أو diff_open).
 *
 * 🔴 تحريك الكمية المبعوتة كاملة من في الطريق إلى رصيد المستلم.
 * ثم معالجة الفروق (بريف ٨٥٣):
 * - المقارنة بالقطعة على مستوى item_id في المستند كله (الأكواد المربوطة لا تُنشئ فرقاً).
 * - مفيش أي حركة فروق وقت الاستلام — كل الفرق (ناقص أو زيادة) يتسجل pending في انتظار الاعتماد.
 * - استدعاء المقاصة الذكية transfer_diffs_match() فور تسجيل الفروق.
 * لما كل الفروق تبقى resolved -> المستند closed.
 */
function transfer_receive(PDO $pdo, int $docId, int $uid): array
{
    stock_require_enabled();

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("التحويل غير موجود (id=$docId).");
        }
        if ($doc['doc_type'] !== 'transfer') {
            throw new RuntimeException('نوع المستند ليس تحويلاً.');
        }
        if ($doc['status'] !== 'sent') {
            throw new RuntimeException("لا يمكن استلام التحويل — حالته الحالية: {$doc['status']}.");
        }

        $fromBranchId = (int) $doc['branch_id'];
        $toBranchId   = (int) $doc['counter_branch_id'];

        stock_require_live($fromBranchId, $toBranchId);

        // جلب السطور
        $stLines = $pdo->prepare('SELECT * FROM stock_doc_lines WHERE doc_id = ? ORDER BY line_no ASC');
        $stLines->execute([$docId]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            throw new RuntimeException('لا توجد سطور في التحويل للاستلام.');
        }

        // جلب العد المسجل في stock_transfer_rx
        $stRx = $pdo->prepare(
            'SELECT rx.* FROM stock_transfer_rx rx
               JOIN stock_doc_lines l ON l.id = rx.doc_line_id
              WHERE l.doc_id = ?'
        );
        $stRx->execute([$docId]);
        $rxRows = $stRx->fetchAll(PDO::FETCH_ASSOC);
        $rxMap = [];
        foreach ($rxRows as $r) {
            $rxMap[(int) $r['doc_line_id']] = (float) $r['received_pieces'];
        }

        $insRxMissing = $pdo->prepare(
            'INSERT INTO stock_transfer_rx (doc_line_id, received_pieces, counted_by, counted_at)
             VALUES (?, 0.000, ?, NOW())
             ON DUPLICATE KEY UPDATE received_pieces = VALUES(received_pieces)'
        );

        $moves = [];
        $sentByItem = [];
        $countedByItem = [];
        $firstLineByItem = [];

        foreach ($lines as $l) {
            $lineId     = (int) $l['id'];
            $itemId     = (int) $l['item_id'];
            $sentPieces = (float) $l['qty_pieces'];

            // 1. حركة المستلم الأساسية: خصم من في الطريق + إضافة للرصيد الفعلي (بالكمية المبعوتة كاملة)
            $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $toBranchId,
                'is_transit'  => 1,
                'qty'         => -$sentPieces,
                'unit_cost'   => null,
                'doc_line_id' => $lineId,
            ];
            $moves[] = [
                'item_id'     => $itemId,
                'branch_id'   => $toBranchId,
                'is_transit'  => 0,
                'qty'         => +$sentPieces,
                'unit_cost'   => null,
                'doc_line_id' => $lineId,
            ];

            // 2. تجميع المبعوت والمعدود بالقطعة على مستوى item_id (بريف ٨٥٣: الأكواد المربوطة)
            $sentByItem[$itemId] = ($sentByItem[$itemId] ?? 0.0) + $sentPieces;
            if (!isset($firstLineByItem[$itemId])) {
                $firstLineByItem[$itemId] = $lineId;
            }

            if (isset($rxMap[$lineId])) {
                $countedPieces = round((float) $rxMap[$lineId], 3);
            } else {
                $countedPieces = 0.000;
                $insRxMissing->execute([$lineId, $uid]);
            }
            $countedByItem[$itemId] = ($countedByItem[$itemId] ?? 0.0) + $countedPieces;
        }

        // تطبيق حركات المستلم الأساسية
        stock_apply_moves($pdo, $docId, $moves, $uid);

        // 3. مقارنة الفروق على مستوى item_id وتسجيلها بحالة pending دون حركات فورية
        $insDiff = $pdo->prepare(
            'INSERT INTO stock_transfer_diffs
               (doc_id, doc_line_id, item_id, kind, qty, status, source_branch_id, resolve_doc_id, resolved_by, resolved_at, note)
             VALUES
               (?, ?, ?, ?, ?, \'pending\', NULL, NULL, NULL, NULL, ?)'
        );

        $shortDiffCount = 0;
        $excessDiffCount = 0;

        foreach ($sentByItem as $itemId => $totSent) {
            $totCounted = $countedByItem[$itemId] ?? 0.0;
            $diff = round($totCounted - $totSent, 3);
            $firstLineId = $firstLineByItem[$itemId];

            if ($diff < -0.0001) {
                $shortQty = abs($diff);
                $shortDiffCount++;
                $insDiff->execute([
                    $docId,
                    $firstLineId,
                    $itemId,
                    'short',
                    $shortQty,
                    'فرق مجمّع للصنف (عجز بانتظار الاعتماد)',
                ]);
            } elseif ($diff > 0.0001) {
                $excessQty = $diff;
                $excessDiffCount++;
                $insDiff->execute([
                    $docId,
                    $firstLineId,
                    $itemId,
                    'excess',
                    $excessQty,
                    'فرق مجمّع للصنف (زيادة بانتظار الاعتماد)',
                ]);
            }
        }

        // تحديد الحالة النهائية للمستند:
        // لو فيه أي فرق مفتوح أو معلق -> diff_open، غير كده -> closed
        $hasDiffs = ($shortDiffCount + $excessDiffCount > 0);
        $finalStatus = $hasDiffs ? 'diff_open' : 'closed';

        $pdo->prepare('UPDATE stock_docs SET status = ? WHERE id = ?')->execute([$finalStatus, $docId]);

        // تسجيل الأحداث
        $evRx = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'status:received', ?, NOW(), ?)");
        $evRx->execute([
            $docId,
            $uid,
            json_encode([
                'shorts'   => $shortDiffCount,
                'excesses' => $excessDiffCount,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($finalStatus === 'closed') {
            $evCl = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'status:closed', ?, NOW(), ?)");
            $evCl->execute([$docId, $uid, json_encode(['reason' => 'تم الاستلام وإغلاق المستند بدون فروق'], JSON_UNESCAPED_UNICODE)]);
        } elseif ($finalStatus === 'diff_open') {
            $evDiff = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'status:diff_open', ?, NOW(), ?)");
            $evDiff->execute([$docId, $uid, json_encode(['shorts' => $shortDiffCount, 'excesses' => $excessDiffCount], JSON_UNESCAPED_UNICODE)]);
        }

        // تشغيل المقاصة الذكية فور تسجيل الفروق
        if ($hasDiffs) {
            transfer_diffs_match($pdo);
        }

        if ($ownTx) {
            $pdo->commit();
        }

        return [
            'doc_id'       => $docId,
            'status'       => $finalStatus,
            'short_diffs'  => $shortDiffCount,
            'excess_diffs' => $excessDiffCount,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * فك فرق الزيادة بتحديد المخزن المصدر (open -> resolved).
 * يخصم من المخزن المصدر المحدد ويضيف للمستلم عبر مستند تسوية مرتبط.
 *
 * @param PDO $pdo
 * @param int $diffId معرّف الفرق في stock_transfer_diffs
 * @param int $sourceBranchId المخزن المصدر المحدد
 * @param int $uid معرّف المستخدم
 * @return int معرّف مستند التسوية المُنشأ
 */
function transfer_resolve_excess(PDO $pdo, int $diffId, int $sourceBranchId, int $uid): int
{
    stock_require_enabled();

    if ($sourceBranchId <= 0 || $sourceBranchId === 5) {
        throw new RuntimeException('المخزن المصدر غير صالح أو غير مدعوم.');
    }

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $stDiff = $pdo->prepare('SELECT * FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
        $stDiff->execute([$diffId]);
        $diff = $stDiff->fetch(PDO::FETCH_ASSOC);
        if (!$diff) {
            throw new RuntimeException("حالة الفرق غير موجودة (id=$diffId).");
        }
        if ($diff['kind'] !== 'excess') {
            throw new RuntimeException("الفرق ده مش زيادة — نوعه: {$diff['kind']}. فك الزيادة للزيادات بس.");
        }
        if ($diff['status'] !== 'open' && $diff['status'] !== 'approved') {
            throw new RuntimeException("الزيادة دي اتحلت قبل كده — حالتها الحالية: {$diff['status']}.");
        }

        $parentDocId = (int) $diff['doc_id'];
        $stDoc = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $stDoc->execute([$parentDocId]);
        $parentDoc = $stDoc->fetch(PDO::FETCH_ASSOC);
        if (!$parentDoc) {
            throw new RuntimeException("المستند الأصلي غير موجود (id=$parentDocId).");
        }
        if ($parentDoc['doc_type'] !== 'transfer') {
            throw new RuntimeException("مستند الفرق مش تحويل — نوعه: {$parentDoc['doc_type']}.");
        }
        if ($parentDoc['status'] !== 'diff_open') {
            throw new RuntimeException("مستند التحويل مش مفتوح على فروق — حالته الحالية: {$parentDoc['status']}.");
        }

        $destBranchId = (int) $parentDoc['counter_branch_id'];

        // المخزن المصدر لازم يكون موجود ونشط ومش هو المستلم نفسه
        if ($sourceBranchId === $destBranchId) {
            throw new RuntimeException('المخزن المصدر مايصحّش يكون هو المخزن المستلم نفسه.');
        }
        $stSrcBranch = $pdo->prepare('SELECT id, name, is_active FROM branches WHERE id = ?');
        $stSrcBranch->execute([$sourceBranchId]);
        $srcBranch = $stSrcBranch->fetch(PDO::FETCH_ASSOC);
        if (!$srcBranch) {
            throw new RuntimeException("المخزن المصدر ($sourceBranchId) مش موجود.");
        }
        if ((int) $srcBranch['is_active'] !== 1) {
            throw new RuntimeException("المخزن المصدر ({$srcBranch['name']}) مش نشط.");
        }

        // شرط live للمصدر وللمستلم
        stock_require_live($sourceBranchId, $destBranchId);

        // جلب بيانات الصنف من السطر
        $stLine = $pdo->prepare('SELECT * FROM stock_doc_lines WHERE id = ?');
        $stLine->execute([(int) $diff['doc_line_id']]);
        $parentLine = $stLine->fetch(PDO::FETCH_ASSOC);

        $itemId    = (int) $diff['item_id'];
        $code      = (string) ($parentLine['code'] ?? ('ITEM-' . $itemId));
        $factor    = isset($parentLine['unit_factor']) && (float) $parentLine['unit_factor'] > 0
            ? (float) $parentLine['unit_factor'] : 1.0;
        $qtyPieces = round((float) $diff['qty'], 3);
        $qtyUnits  = round($qtyPieces / $factor, 3);

        // إنشاء مستند التسوية المرتبط
        $adjustLines = [
            [
                'item_id'     => $itemId,
                'code'        => $code,
                'unit_factor' => $factor,
                'qty_units'   => $qtyUnits,
                'qty_pieces'  => $qtyPieces,
                'unit_cost'   => null,
                'notes'       => "تسوية زيادة تحويل #$parentDocId",
            ],
        ];

        $adjDocId = stock_doc_create($pdo, 'adjust', [
            'branch_id'         => $sourceBranchId,
            'counter_branch_id' => $destBranchId,
            'doc_date'          => date('Y-m-d'),
            'ref_text'          => 'transfer:' . $parentDocId,
            'notes'             => "تسوية زيادة تحويل #$parentDocId — المصدر: مخزن $sourceBranchId",
        ], $adjustLines, $uid);

        // ترقيم وترحيل مستند التسوية
        $adjYear = (int) date('Y');
        $adjNo   = stock_next_no($pdo, 'adjust', $sourceBranchId, $adjYear);

        $pdo->prepare("UPDATE stock_docs SET doc_no = ?, doc_year = ?, status = 'posted', posted_by = ?, posted_at = NOW() WHERE id = ?")
            ->execute([$adjNo, $adjYear, $uid, $adjDocId]);

        // الحركات: خصم من المصدر + إضافة للمستلم
        $adjMoves = [
            [
                'item_id'     => $itemId,
                'branch_id'   => $sourceBranchId,
                'is_transit'  => 0,
                'qty'         => -$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => null,
            ],
            [
                'item_id'     => $itemId,
                'branch_id'   => $destBranchId,
                'is_transit'  => 0,
                'qty'         => +$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => null,
            ],
        ];

        stock_apply_moves($pdo, $adjDocId, $adjMoves, $uid);

        // تسجيل حدث الترحيل لمستند التسوية
        $evAdj = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'posted', ?, NOW(), ?)");
        $evAdj->execute([
            $adjDocId,
            $uid,
            json_encode(['doc_no' => $adjNo, 'doc_year' => $adjYear, 'ref_text' => 'transfer:' . $parentDocId], JSON_UNESCAPED_UNICODE),
        ]);

        // تحديث حالة الفرق في stock_transfer_diffs إلى resolved
        $upDiff = $pdo->prepare(
            'UPDATE stock_transfer_diffs
                SET status = \'resolved\',
                    source_branch_id = ?,
                    resolve_doc_id = ?,
                    resolved_by = ?,
                    resolved_at = NOW(),
                    note = ?
              WHERE id = ?'
        );
        $upDiff->execute([
            $sourceBranchId,
            $adjDocId,
            $uid,
            "تم تحديد المصدر: مخزن $sourceBranchId بمستند تسوية #$adjNo",
            $diffId,
        ]);

        // فحص هل ما زال هناك فروق مفتوحة في نفس مستند التحويل
        $stRemain = $pdo->prepare("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = ? AND status != 'resolved'");
        $stRemain->execute([$parentDocId]);
        $remainingOpen = (int) $stRemain->fetchColumn();

        if ($remainingOpen === 0) {
            $pdo->prepare("UPDATE stock_docs SET status = 'closed' WHERE id = ?")->execute([$parentDocId]);
            $evCl = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'status:closed', ?, NOW(), ?)");
            $evCl->execute([$parentDocId, $uid, json_encode(['reason' => 'تم فك جميع فروق الزيادة وإغلاق المستند'], JSON_UNESCAPED_UNICODE)]);
        }

        $evNote = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'note', ?, NOW(), ?)");
        $evNote->execute([
            $parentDocId,
            $uid,
            json_encode([
                'note'             => "تم تسوية زيادة الصنف $itemId ($qtyPieces قطعة) من مخزن $sourceBranchId بمستند تسوية #$adjNo",
                'diff_id'          => $diffId,
                'source_branch_id' => $sourceBranchId,
                'adjust_doc_id'    => $adjDocId,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }

        return $adjDocId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * تسوية فرق العجز برد الكمية لمخزن الراسل (to_sender).
 * يخصم من المخزن المستلم ويضيف للمخزن الراسل عبر مستند تسوية مرتبط (بريف ٨٥٣).
 *
 * @param PDO $pdo
 * @param int $diffId معرّف الفرق في stock_transfer_diffs
 * @param int $uid معرّف المستخدم
 * @return int معرّف مستند التسوية المُنشأ
 */
function transfer_resolve_short(PDO $pdo, int $diffId, int $uid): int
{
    stock_require_enabled();

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $stDiff = $pdo->prepare('SELECT * FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
        $stDiff->execute([$diffId]);
        $diff = $stDiff->fetch(PDO::FETCH_ASSOC);
        if (!$diff) {
            throw new RuntimeException("حالة الفرق غير موجودة (id=$diffId).");
        }
        if ($diff['kind'] !== 'short') {
            throw new RuntimeException("الفرق ده مش عجز — نوعه: {$diff['kind']}.");
        }
        if ($diff['status'] !== 'open' && $diff['status'] !== 'approved') {
            throw new RuntimeException("العجز ده اتحل قبل كده — حالته الحالية: {$diff['status']}.");
        }

        $parentDocId = (int) $diff['doc_id'];
        $stDoc = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $stDoc->execute([$parentDocId]);
        $parentDoc = $stDoc->fetch(PDO::FETCH_ASSOC);
        if (!$parentDoc) {
            throw new RuntimeException("المستند الأصلي غير موجود (id=$parentDocId).");
        }
        if ($parentDoc['doc_type'] !== 'transfer') {
            throw new RuntimeException("مستند الفرق مش تحويل — نوعه: {$parentDoc['doc_type']}.");
        }

        $fromBranchId = (int) $parentDoc['branch_id'];        // الراسل (سيسترد العجز)
        $toBranchId   = (int) $parentDoc['counter_branch_id']; // المستلم (سيخصم منه العجز)

        // شرط live للمخزنين
        stock_require_live($toBranchId, $fromBranchId);

        // بيانات الصنف من السطر
        $stLine = $pdo->prepare('SELECT * FROM stock_doc_lines WHERE id = ?');
        $stLine->execute([(int) $diff['doc_line_id']]);
        $parentLine = $stLine->fetch(PDO::FETCH_ASSOC);

        $itemId    = (int) $diff['item_id'];
        $code      = (string) ($parentLine['code'] ?? ('ITEM-' . $itemId));
        $factor    = isset($parentLine['unit_factor']) && (float) $parentLine['unit_factor'] > 0
            ? (float) $parentLine['unit_factor'] : 1.0;
        $qtyPieces = round((float) $diff['qty'], 3);
        $qtyUnits  = round($qtyPieces / $factor, 3);

        // إنشاء مستند التسوية المرتبط: من المستلم إلى الراسل
        $adjustLines = [
            [
                'item_id'     => $itemId,
                'code'        => $code,
                'unit_factor' => $factor,
                'qty_units'   => $qtyUnits,
                'qty_pieces'  => $qtyPieces,
                'unit_cost'   => null,
                'notes'       => "تسوية عجز تحويل #$parentDocId",
            ],
        ];

        $adjDocId = stock_doc_create($pdo, 'adjust', [
            'branch_id'         => $toBranchId,
            'counter_branch_id' => $fromBranchId,
            'doc_date'          => date('Y-m-d'),
            'ref_text'          => 'transfer:' . $parentDocId,
            'notes'             => "رد عجز تحويل #$parentDocId — رد لمخزن الراسل $fromBranchId",
        ], $adjustLines, $uid);

        // ترقيم وترحيل مستند التسوية
        $adjYear = (int) date('Y');
        $adjNo   = stock_next_no($pdo, 'adjust', $toBranchId, $adjYear);

        $pdo->prepare("UPDATE stock_docs SET doc_no = ?, doc_year = ?, status = 'posted', posted_by = ?, posted_at = NOW() WHERE id = ?")
            ->execute([$adjNo, $adjYear, $uid, $adjDocId]);

        // الحركات: خصم من المستلم + إضافة للراسل
        $adjMoves = [
            [
                'item_id'     => $itemId,
                'branch_id'   => $toBranchId,
                'is_transit'  => 0,
                'qty'         => -$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => null,
            ],
            [
                'item_id'     => $itemId,
                'branch_id'   => $fromBranchId,
                'is_transit'  => 0,
                'qty'         => +$qtyPieces,
                'unit_cost'   => null,
                'doc_line_id' => null,
            ],
        ];

        stock_apply_moves($pdo, $adjDocId, $adjMoves, $uid);

        // تسجيل حدث الترحيل لمستند التسوية
        $evAdj = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'posted', ?, NOW(), ?)");
        $evAdj->execute([
            $adjDocId,
            $uid,
            json_encode(['doc_no' => $adjNo, 'doc_year' => $adjYear, 'ref_text' => 'transfer:' . $parentDocId], JSON_UNESCAPED_UNICODE),
        ]);

        // تحديث حالة الفرق في stock_transfer_diffs إلى resolved
        $upDiff = $pdo->prepare(
            'UPDATE stock_transfer_diffs
                SET status = \'resolved\',
                    source_branch_id = ?,
                    counter_branch_id = ?,
                    resolve_doc_id = ?,
                    resolved_by = ?,
                    resolved_at = NOW(),
                    note = ?
              WHERE id = ?'
        );
        $upDiff->execute([
            $toBranchId,
            $fromBranchId,
            $adjDocId,
            $uid,
            "تم رد العجز لمخزن الراسل $fromBranchId بمستند تسوية #$adjNo",
            $diffId,
        ]);

        // فحص هل ما زال هناك فروق مفتوحة في نفس مستند التحويل
        $stRemain = $pdo->prepare("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = ? AND status != 'resolved'");
        $stRemain->execute([$parentDocId]);
        $remainingOpen = (int) $stRemain->fetchColumn();

        if ($remainingOpen === 0) {
            $pdo->prepare("UPDATE stock_docs SET status = 'closed' WHERE id = ?")->execute([$parentDocId]);
            $evCl = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'status:closed', ?, NOW(), ?)");
            $evCl->execute([$parentDocId, $uid, json_encode(['reason' => 'تم رد العجز وإغلاق المستند'], JSON_UNESCAPED_UNICODE)]);
        }

        if ($ownTx) {
            $pdo->commit();
        }

        return $adjDocId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * المستخدمون أصحاب صلاحية اعتماد الفروق أو المالك لإرسال الإشعارات.
 *
 * @param PDO $pdo
 * @return int[]
 */
function transfer_resolve_notification_uids(PDO $pdo): array
{
    try {
        $st = $pdo->query(
            "SELECT DISTINCT u.id
               FROM users u
               LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND (
                    r.is_owner = 1
                    OR u.id IN (SELECT user_id FROM user_permissions WHERE module_key = 'transfers:resolve')
                    OR u.role_id IN (SELECT role_id FROM role_permissions WHERE module_key = 'transfers:resolve')
                )"
        );
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [1];
    }
}

/**
 * أسماء المخازن لتنسيق نصوص الإشعارات.
 *
 * @param PDO $pdo
 * @return array<int, string>
 */
function transfer_branch_names(PDO $pdo): array
{
    try {
        $st = $pdo->query('SELECT id, name FROM branches');
        return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable) {
        return [];
    }
}

/**
 * إرسال إشعار لمستخدمي فرعين بعد اعتماد وتنفيذ الفرق.
 */
function transfer_notify_branches(PDO $pdo, int $branchA, int $branchB, string $title, string $body, string $link): void
{
    try {
        if (!is_file(__DIR__ . '/push_lib.php')) return;
        require_once __DIR__ . '/push_lib.php';
        if (!function_exists('notify')) return;

        $stUsers = $pdo->prepare('SELECT id FROM users WHERE branch_id IN (?, ?) AND is_active = 1');
        $stUsers->execute([$branchA, $branchB]);
        $uids = array_map(fn($r) => (int) $r['id'], $stUsers->fetchAll(PDO::FETCH_ASSOC));
        if (!empty($uids)) {
            notify($pdo, $uids, 'transfer_diff_approved', $title, $body, $link);
        }
    } catch (Throwable) {}
}

/**
 * المقاصة الذكية لفروق التحويلات المعلقة (بريف ٨٥٣).
 *
 * تبحث عن تطابق عجز في فرع مع زيادة في فرع آخر لنفس الصنف وبنفس الكمية.
 * الأولوية: نفس مستند التحويل التلقائي (نفس distribution_id في stock_doc_dists)، ثم الأقدم.
 * الفروق الفردية التي لا تجد مقابلاً:
 * - الناقص: to_sender (يرجع للراسل).
 * - الزيادة: from_sender (تتخصم من الراسل).
 * 🔴 مفيش مقاصة جزئية. الفروق التي اعتُمدت من أحد الطرفين لا يتغير اقتراحها.
 * استعلام واحد يجيب كل الفروق المفتوحة، والمطابقة في الذاكرة، وUPDATE مجمّع.
 *
 * @param PDO $pdo
 * @return array تفاصيل المطابقة والإحصائيات
 */
function transfer_diffs_match(PDO $pdo): array
{
    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        // استعلام واحد لجلب كل الفروق المؤهلة للمطابقة
        $sql = "SELECT df.id, df.doc_id, df.doc_line_id, df.item_id, df.kind, df.qty,
                       df.status, df.resolution, df.pair_diff_id, df.counter_branch_id,
                       df.recv_approved_at, df.owner_approved_at,
                       d.branch_id AS sender_branch_id, d.counter_branch_id AS receiver_branch_id,
                       d.created_at AS doc_created_at,
                       c.name AS item_name,
                       (SELECT sdd.distribution_id FROM stock_doc_dists sdd WHERE sdd.doc_id = df.doc_id LIMIT 1) AS dist_id
                  FROM stock_transfer_diffs df
                  JOIN stock_docs d ON d.id = df.doc_id
                  LEFT JOIN stock_doc_lines l ON l.id = df.doc_line_id
                  LEFT JOIN hq_codes c ON c.code = l.code
                 WHERE df.status = 'pending'
                   AND df.recv_approved_at IS NULL
                   AND df.owner_approved_at IS NULL
                   AND (df.resolution IS NULL OR df.resolution IN ('to_sender', 'from_sender'))
                 ORDER BY df.id ASC";

        $stCandidates = $pdo->query($sql);
        $candidates = $stCandidates->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'updated_count' => 0,
                'pairs_count'   => 0,
                'partial_count' => 0,
                'diffs'         => [],
            ];
        }

        // تجميع الفروق حسب item_id
        $byItem = [];
        foreach ($candidates as $c) {
            $itemId = (int) $c['item_id'];
            $byItem[$itemId][] = $c;
        }

        $proposals = [];      // diff_id => ['resolution' => ..., 'pair_diff_id' => ..., 'counter_branch_id' => ...]
        $matchedPairs = [];
        $partialCount = 0;

        foreach ($byItem as $itemId => $rows) {
            $shorts = [];
            $excesses = [];

            foreach ($rows as $r) {
                if ($r['kind'] === 'short') {
                    $shorts[] = $r;
                } elseif ($r['kind'] === 'excess') {
                    $excesses[] = $r;
                }
            }

            // مطابقة العجز مع الزيادة
            foreach ($shorts as $s) {
                $sId       = (int) $s['id'];
                $sQty      = (float) $s['qty'];
                $sBranch   = (int) $s['receiver_branch_id'];
                $sDistId   = $s['dist_id'] !== null ? (int) $s['dist_id'] : null;

                $bestIdx = null;
                $bestScore = -1; // 2: نفس التحويل التلقائي، 1: أقدم

                foreach ($excesses as $idx => $ex) {
                    $exBranch = (int) $ex['receiver_branch_id'];
                    if ($exBranch === $sBranch) {
                        continue; // نفس الفرع ما يصحش
                    }

                    $exQty = (float) $ex['qty'];
                    if (abs($exQty - $sQty) > 0.0001) {
                        // كميات مختلفة (حالة جزئية تُسجل للإحصاء فقط ولا تطابق)
                        $partialCount++;
                        continue;
                    }

                    // تطابق تام في الكمية
                    $exDistId = $ex['dist_id'] !== null ? (int) $ex['dist_id'] : null;
                    $score = ($sDistId !== null && $sDistId === $exDistId) ? 2 : 1;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIdx = $idx;
                    }
                }

                if ($bestIdx !== null) {
                    $matchedEx = $excesses[$bestIdx];
                    unset($excesses[$bestIdx]);
                    $exId     = (int) $matchedEx['id'];
                    $exBranch = (int) $matchedEx['receiver_branch_id'];

                    $proposals[$sId] = [
                        'resolution'        => 'swap',
                        'pair_diff_id'      => $exId,
                        'counter_branch_id' => $exBranch,
                    ];
                    $proposals[$exId] = [
                        'resolution'        => 'swap',
                        'pair_diff_id'      => $sId,
                        'counter_branch_id' => $sBranch,
                    ];
                    $matchedPairs[] = ['short_id' => $sId, 'excess_id' => $exId];
                } else {
                    // لم يجد مقابلاً بنفس الكمية
                    $proposals[$sId] = [
                        'resolution'        => 'to_sender',
                        'pair_diff_id'      => null,
                        'counter_branch_id' => null,
                    ];
                }
            }

            // الزيادات المتبقية التي لم تطابق
            foreach ($excesses as $remEx) {
                $remId = (int) $remEx['id'];
                $proposals[$remId] = [
                    'resolution'        => 'from_sender',
                    'pair_diff_id'      => null,
                    'counter_branch_id' => null,
                ];
            }
        }

        // تحديد الفروق التي تغير اقتراحها فعلياً لتحديثها بـ UPDATE مجمّع
        $diffsToUpdate = [];
        foreach ($candidates as $c) {
            $id = (int) $c['id'];
            if (!isset($proposals[$id])) {
                continue;
            }
            $p = $proposals[$id];
            $curRes     = $c['resolution'];
            $curPair    = $c['pair_diff_id'] !== null ? (int) $c['pair_diff_id'] : null;
            $curCounter = $c['counter_branch_id'] !== null ? (int) $c['counter_branch_id'] : null;

            $changed = ($curRes !== $p['resolution'])
                || ($curPair !== $p['pair_diff_id'])
                || ($curCounter !== $p['counter_branch_id']);

            if ($changed) {
                $diffsToUpdate[$id] = [
                    'id'                => $id,
                    'doc_id'            => (int) $c['doc_id'],
                    'item_id'           => (int) $c['item_id'],
                    'item_name'         => $c['item_name'] ?? ('صنف ' . $c['item_id']),
                    'qty'               => (float) $c['qty'],
                    'kind'              => $c['kind'],
                    'from_branch'       => (int) $c['receiver_branch_id'],
                    'to_branch'         => (int) ($p['counter_branch_id'] ?? $c['sender_branch_id']),
                    'resolution'        => $p['resolution'],
                    'pair_diff_id'      => $p['pair_diff_id'],
                    'counter_branch_id' => $p['counter_branch_id'],
                ];
            }
        }

        // تنفيذ التحديث المجمّع وقفل السطور بـ FOR UPDATE
        if (!empty($diffsToUpdate)) {
            $updateIds = array_keys($diffsToUpdate);
            $in = implode(',', array_fill(0, count($updateIds), '?'));

            // 1. قفل الفروق المتأثرة
            $stLock = $pdo->prepare("SELECT id FROM stock_transfer_diffs WHERE id IN ($in) FOR UPDATE");
            $stLock->execute($updateIds);

            // 2. تحديث مجمّع باستعلام واحد
            $resCases = [];
            $pairCases = [];
            $counterCases = [];
            $resParams = [];
            $pairParams = [];
            $counterParams = [];

            foreach ($diffsToUpdate as $id => $u) {
                $resCases[] = "WHEN ? THEN ?";
                $resParams[] = $id;
                $resParams[] = $u['resolution'];

                $pairCases[] = "WHEN ? THEN ?";
                $pairParams[] = $id;
                $pairParams[] = $u['pair_diff_id'];

                $counterCases[] = "WHEN ? THEN ?";
                $counterParams[] = $id;
                $counterParams[] = $u['counter_branch_id'];
            }

            $allParams = array_merge($resParams, $pairParams, $counterParams, $updateIds);
            $sqlUp = "UPDATE stock_transfer_diffs SET
                resolution = CASE id " . implode(' ', $resCases) . " END,
                pair_diff_id = CASE id " . implode(' ', $pairCases) . " END,
                counter_branch_id = CASE id " . implode(' ', $counterCases) . " END
                WHERE id IN ($in)";
            $pdo->prepare($sqlUp)->execute($allParams);

            // 3. إرسال إشعار واحد مجمّع لأصحاب الصلاحية
            try {
                if (is_file(__DIR__ . '/push_lib.php')) {
                    require_once __DIR__ . '/push_lib.php';
                    if (function_exists('notify')) {
                        $targetUids = transfer_resolve_notification_uids($pdo);
                        if (!empty($targetUids)) {
                            $brNames = transfer_branch_names($pdo);
                            $first = reset($diffsToUpdate);
                            $fName = $brNames[$first['from_branch']] ?? ('فرع ' . $first['from_branch']);
                            $tName = $brNames[$first['to_branch']] ?? ('فرع ' . $first['to_branch']);
                            $qtyFormatted = number_format($first['qty'], 3);

                            if (count($diffsToUpdate) === 1) {
                                $body = "فرق تحويل محتاج موافقتك — هيأثر على الرصيد: {$first['item_name']} $qtyFormatted من $fName ← $tName";
                            } else {
                                $nOthers = count($diffsToUpdate) - 1;
                                $body = "فرق تحويل محتاج موافقتك — هيأثر على الرصيد: {$first['item_name']} $qtyFormatted من $fName ← $tName (وعدد $nOthers فروق أخرى)";
                            }

                            notify(
                                $pdo,
                                $targetUids,
                                'transfer_diff',
                                'فروق تحويل بانتظار الاعتماد',
                                $body,
                                'transfers.php?tab=diffs'
                            );
                        }
                    }
                }
            } catch (Throwable) {}
        }

        if ($ownTx) {
            $pdo->commit();
        }

        return [
            'updated_count' => count($diffsToUpdate),
            'pairs_count'   => count($matchedPairs),
            'partial_count' => $partialCount,
            'diffs'         => $diffsToUpdate,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * اعتماد فرق تحويل (دورة اعتماد مزدوجة: استلامات + مالك) وتنفيذ تلقائي (بريف ٨٥٣).
 *
 * @param PDO $pdo
 * @param int $diffId معرّف الفرق
 * @param int $uid معرّف المستخدم المعتمد
 * @return array تفاصيل الاعتماد والنتيجة
 */
function transfer_diff_approve(PDO $pdo, int $diffId, int $uid): array
{
    stock_require_enabled();

    $isRealOwner = is_real_owner();
    $hasResolvePerm = in_array('transfers:resolve', $_SESSION['permissions'] ?? [], true)
        || in_array('transfers:resolve', user_effective_permissions($uid), true);

    if (!$isRealOwner && !$hasResolvePerm) {
        throw new RuntimeException('غير مصرح لك باعتماد هذا الفرق — يتطلب صلاحية الاستلامات أو المالك.');
    }

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $stDiff = $pdo->prepare('SELECT * FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
        $stDiff->execute([$diffId]);
        $diff = $stDiff->fetch(PDO::FETCH_ASSOC);
        if (!$diff) {
            throw new RuntimeException("الفرق غير موجود (id=$diffId).");
        }
        if ($diff['status'] === 'resolved') {
            throw new RuntimeException('تم اعتماد وحل هذا الفرق مسبقاً.');
        }
        if ($diff['status'] === 'rejected') {
            throw new RuntimeException('هذا الفرق تم رفضه.');
        }

        // الفرق المقابل في حالة swap
        $pairId = !empty($diff['pair_diff_id']) ? (int) $diff['pair_diff_id'] : null;
        $pairDiff = null;
        if ($diff['resolution'] === 'swap' && $pairId) {
            $stPair = $pdo->prepare('SELECT * FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
            $stPair->execute([$pairId]);
            $pairDiff = $stPair->fetch(PDO::FETCH_ASSOC);
        }

        $targetDiffs = [$diff];
        if ($pairDiff) {
            $targetDiffs[] = $pairDiff;
        }
        $targetIds = array_map(fn($d) => (int) $d['id'], $targetDiffs);

        $hasExistingRecv  = !empty($diff['recv_approved_at']);
        $hasExistingOwner = !empty($diff['owner_approved_at']);

        $nowRecv  = $hasResolvePerm || $hasExistingRecv;
        $nowOwner = $isRealOwner || $hasExistingOwner;
        $isFullyApproved = $nowRecv && $nowOwner;

        // تجهيز جملة التحديث
        $setClauses = [];
        $setParams  = [];

        if ($hasResolvePerm && !$hasExistingRecv) {
            $setClauses[] = 'recv_approved_by = ?, recv_approved_at = NOW()';
            $setParams[]  = $uid;
        }
        if ($isRealOwner && !$hasExistingOwner) {
            $setClauses[] = 'owner_approved_by = ?, owner_approved_at = NOW()';
            $setParams[]  = $uid;
        }

        if ($isFullyApproved) {
            $setClauses[] = "status = 'approved'";
        } else {
            $setClauses[] = "status = 'pending'";
        }

        if (!empty($setClauses)) {
            $in = implode(',', array_fill(0, count($targetIds), '?'));
            $allParams = array_merge($setParams, $targetIds);
            $sqlUp = "UPDATE stock_transfer_diffs SET " . implode(', ', $setClauses) . " WHERE id IN ($in)";
            $pdo->prepare($sqlUp)->execute($allParams);
        }

        $resolveDocId = null;

        // إذا اكتمل الاعتمادان -> التنفيذ الفوري في نفس المعاملة
        if ($isFullyApproved) {
            $resType = $diff['resolution'];

            if ($resType === 'swap') {
                // مقاصة ذكية بين فرعين: حركة واحدة للزوج
                $excessDiff = ($diff['kind'] === 'excess') ? $diff : $pairDiff;
                $shortDiff  = ($diff['kind'] === 'short')  ? $diff : $pairDiff;

                // فرع العجز المستلم الذي سيتم الخصم منه بمستند التسوية
                $stShortDoc = $pdo->prepare('SELECT counter_branch_id FROM stock_docs WHERE id = ?');
                $stShortDoc->execute([(int) $shortDiff['doc_id']]);
                $shortBranchId = (int) $stShortDoc->fetchColumn();

                // فرع الزيادة المستلم
                $stExcessDoc = $pdo->prepare('SELECT counter_branch_id FROM stock_docs WHERE id = ?');
                $stExcessDoc->execute([(int) $excessDiff['doc_id']]);
                $excessBranchId = (int) $stExcessDoc->fetchColumn();

                // تنفيذ تسوية الزيادة بالخصم من فرع العجز والإضافة لفرع الزيادة
                $resolveDocId = transfer_resolve_excess($pdo, (int) $excessDiff['id'], $shortBranchId, $uid);

                // تحديث فرق العجز المقابل بنفس مستند التسوية
                $pdo->prepare(
                    "UPDATE stock_transfer_diffs
                        SET status = 'resolved',
                            source_branch_id = ?,
                            resolve_doc_id = ?,
                            resolved_by = ?,
                            resolved_at = NOW(),
                            note = ?
                      WHERE id = ?"
                )->execute([
                    $shortBranchId,
                    $resolveDocId,
                    $uid,
                    "تمت المقاصة مع مخزن $excessBranchId بمستند تسوية #$resolveDocId",
                    (int) $shortDiff['id']
                ]);

                // فحص إغلاق مستند العجز
                $stRemShort = $pdo->prepare("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = ? AND status != 'resolved'");
                $stRemShort->execute([(int) $shortDiff['doc_id']]);
                if ((int) $stRemShort->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE stock_docs SET status = 'closed' WHERE id = ?")->execute([(int) $shortDiff['doc_id']]);
                    $evCl = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                        VALUES ('stock', ?, 'status:closed', ?, NOW(), ?)");
                    $evCl->execute([(int) $shortDiff['doc_id'], $uid, json_encode(['reason' => 'تمت المقاصة وإغلاق المستند'], JSON_UNESCAPED_UNICODE)]);
                }

                // إشعار بعد التنفيذ للفرعين
                $brNames = transfer_branch_names($pdo);
                $sName = $brNames[$shortBranchId] ?? ('فرع ' . $shortBranchId);
                $eName = $brNames[$excessBranchId] ?? ('فرع ' . $excessBranchId);
                $stItem = $pdo->prepare('SELECT name FROM hq_codes WHERE item_id = ? LIMIT 1');
                $stItem->execute([(int) $diff['item_id']]);
                $itemName = $stItem->fetchColumn() ?: ('صنف ' . $diff['item_id']);
                $qtyFmt = number_format((float) $diff['qty'], 3);

                transfer_notify_branches(
                    $pdo,
                    $shortBranchId,
                    $excessBranchId,
                    'اعتماد فرق تحويل (مقاصة)',
                    "اتعتمد فرق $itemName $qtyFmt — اتحوّل من $sName لـ$eName",
                    'transfers.php?tab=diffs'
                );

            } elseif ($resType === 'from_sender') {
                // الزيادة تُخصم من مخزن الراسل
                $stDoc = $pdo->prepare('SELECT branch_id, counter_branch_id FROM stock_docs WHERE id = ?');
                $stDoc->execute([(int) $diff['doc_id']]);
                $docRow = $stDoc->fetch(PDO::FETCH_ASSOC);
                $senderBranchId = (int) $docRow['branch_id'];
                $recvBranchId   = (int) $docRow['counter_branch_id'];

                $resolveDocId = transfer_resolve_excess($pdo, $diffId, $senderBranchId, $uid);

                $brNames = transfer_branch_names($pdo);
                $sName = $brNames[$senderBranchId] ?? ('فرع ' . $senderBranchId);
                $rName = $brNames[$recvBranchId] ?? ('فرع ' . $recvBranchId);
                $stItem = $pdo->prepare('SELECT name FROM hq_codes WHERE item_id = ? LIMIT 1');
                $stItem->execute([(int) $diff['item_id']]);
                $itemName = $stItem->fetchColumn() ?: ('صنف ' . $diff['item_id']);
                $qtyFmt = number_format((float) $diff['qty'], 3);

                transfer_notify_branches(
                    $pdo,
                    $senderBranchId,
                    $recvBranchId,
                    'اعتماد فرق تحويل (زيادة من الراسل)',
                    "اتعتمد فرق $itemName $qtyFmt — اتحوّل من $sName لـ$rName",
                    'transfers.php?tab=diffs'
                );

            } elseif ($resType === 'to_sender') {
                // العجز يُرد لمخزن الراسل
                $stDoc = $pdo->prepare('SELECT branch_id, counter_branch_id FROM stock_docs WHERE id = ?');
                $stDoc->execute([(int) $diff['doc_id']]);
                $docRow = $stDoc->fetch(PDO::FETCH_ASSOC);
                $senderBranchId = (int) $docRow['branch_id'];
                $recvBranchId   = (int) $docRow['counter_branch_id'];

                $resolveDocId = transfer_resolve_short($pdo, $diffId, $uid);

                $brNames = transfer_branch_names($pdo);
                $sName = $brNames[$senderBranchId] ?? ('فرع ' . $senderBranchId);
                $rName = $brNames[$recvBranchId] ?? ('فرع ' . $recvBranchId);
                $stItem = $pdo->prepare('SELECT name FROM hq_codes WHERE item_id = ? LIMIT 1');
                $stItem->execute([(int) $diff['item_id']]);
                $itemName = $stItem->fetchColumn() ?: ('صنف ' . $diff['item_id']);
                $qtyFmt = number_format((float) $diff['qty'], 3);

                transfer_notify_branches(
                    $pdo,
                    $recvBranchId,
                    $senderBranchId,
                    'اعتماد فرق تحويل (رد عجز للراسل)',
                    "اتعتمد فرق $itemName $qtyFmt — اتحوّل من $rName لـ$sName",
                    'transfers.php?tab=diffs'
                );
            }
        }

        if ($ownTx) {
            $pdo->commit();
        }

        return [
            'diff_id'           => $diffId,
            'status'            => $isFullyApproved ? 'resolved' : 'pending',
            'is_fully_approved' => $isFullyApproved,
            'recv_approved'     => $nowRecv,
            'owner_approved'    => $nowOwner,
            'resolve_doc_id'    => $resolveDocId,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * رفض فرق تحويل (بريف ٨٥٣).
 *
 * @param PDO $pdo
 * @param int $diffId معرّف الفرق
 * @param int $uid معرّف المستخدم الرافض
 * @param string $reason سبب الرفض
 * @return array
 */
function transfer_diff_reject(PDO $pdo, int $diffId, int $uid, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('سبب الرفض إجباري.');
    }

    $isRealOwner = is_real_owner();
    $hasResolvePerm = in_array('transfers:resolve', $_SESSION['permissions'] ?? [], true)
        || in_array('transfers:resolve', user_effective_permissions($uid), true);

    if (!$isRealOwner && !$hasResolvePerm) {
        throw new RuntimeException('غير مصرح لك برفض هذا الفرق — يتطلب صلاحية الاستلامات أو المالك.');
    }

    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $stDiff = $pdo->prepare('SELECT * FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
        $stDiff->execute([$diffId]);
        $diff = $stDiff->fetch(PDO::FETCH_ASSOC);
        if (!$diff) {
            throw new RuntimeException("الفرق غير موجود (id=$diffId).");
        }
        if ($diff['status'] === 'resolved') {
            throw new RuntimeException('لا يمكن رفض فرق تم اعتماده وتسويته بالفعل.');
        }

        $idsToReject = [$diffId];
        if (!empty($diff['pair_diff_id'])) {
            $pairId = (int) $diff['pair_diff_id'];
            $stPair = $pdo->prepare('SELECT id, status FROM stock_transfer_diffs WHERE id = ? FOR UPDATE');
            $stPair->execute([$pairId]);
            $pairDiff = $stPair->fetch(PDO::FETCH_ASSOC);
            if ($pairDiff && $pairDiff['status'] !== 'resolved') {
                $idsToReject[] = $pairId;
            }
        }

        $in = implode(',', array_fill(0, count($idsToReject), '?'));
        $params = array_merge([$uid, $reason], $idsToReject);
        $stUp = $pdo->prepare(
            "UPDATE stock_transfer_diffs
                SET status = 'rejected',
                    rejected_by = ?,
                    rejected_at = NOW(),
                    reject_reason = ?
              WHERE id IN ($in)"
        );
        $stUp->execute($params);

        // تسجيل حدث الرفض في المستندات
        $evSt = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'diff:rejected', ?, NOW(), ?)");
        $evSt->execute([
            (int) $diff['doc_id'],
            $uid,
            json_encode(['diff_id' => $diffId, 'reason' => $reason], JSON_UNESCAPED_UNICODE),
        ]);

        if ($ownTx) {
            $pdo->commit();
        }

        return [
            'rejected_ids' => $idsToReject,
            'status'       => 'rejected',
            'reason'       => $reason,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * إلغاء التحويل (requested/preparing -> cancelled).
 * بعد الإرسال يرفض ويطلب استلامه بصفر.
 */
function transfer_cancel(PDO $pdo, int $docId, int $uid, string $reason): void
{
    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    try {
        $st = $pdo->prepare('SELECT * FROM stock_docs WHERE id = ? FOR UPDATE');
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("التحويل غير موجود (id=$docId).");
        }
        if ($doc['doc_type'] !== 'transfer') {
            throw new RuntimeException('نوع المستند ليس تحويلاً.');
        }

        if ($doc['status'] === 'sent') {
            throw new RuntimeException('لا يمكن إلغاء التحويل بعد الإرسال — استلمه بصفر لرد الكمية بالكامل.');
        }

        if (!in_array($doc['status'], ['requested', 'preparing'], true)) {
            throw new RuntimeException("لا يمكن إلغاء التحويل في هذه المرحلة (الحالة: {$doc['status']}).");
        }

        $up = $pdo->prepare(
            'UPDATE stock_docs
                SET status = \'cancelled\', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
              WHERE id = ?'
        );
        $up->execute([$uid, $reason, $docId]);

        $ev = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
            VALUES ('stock', ?, 'status:cancelled', ?, NOW(), ?)");
        $ev->execute([
            $docId,
            $uid,
            json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
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
 * جلب تفاصيل تحويل كاملة مع سطوره وعدوده وفروقه وسجله.
 */
function transfer_get(PDO $pdo, int $docId): ?array
{
    $st = $pdo->prepare(
        'SELECT d.*, b.name AS from_branch_name, cb.name AS to_branch_name,
                u.full_name AS created_by_name,
                cu.full_name AS cancelled_by_name
           FROM stock_docs d
           LEFT JOIN branches b ON b.id = d.branch_id
           LEFT JOIN branches cb ON cb.id = d.counter_branch_id
           LEFT JOIN users u ON u.id = d.created_by
           LEFT JOIN users cu ON cu.id = d.cancelled_by
          WHERE d.id = ? AND d.doc_type = \'transfer\''
    );
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        return null;
    }

    // جلب السطور مع أسماء الأصناف والعد
    $stLines = $pdo->prepare(
        'SELECT l.*, c.name AS item_name,
                rx.received_pieces, rx.counted_at, rx.counted_by,
                ru.full_name AS counted_by_name
           FROM stock_doc_lines l
           LEFT JOIN hq_codes c ON c.code = l.code
           LEFT JOIN stock_transfer_rx rx ON rx.doc_line_id = l.id
           LEFT JOIN users ru ON ru.id = rx.counted_by
          WHERE l.doc_id = ?
          ORDER BY l.line_no ASC'
    );
    $stLines->execute([$docId]);
    $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    // جلب الفروقات
    $stDiffs = $pdo->prepare(
        'SELECT df.*, c.name AS item_name, l.code,
                sb.name AS source_branch_name,
                ru.full_name AS resolved_by_name
           FROM stock_transfer_diffs df
           LEFT JOIN stock_doc_lines l ON l.id = df.doc_line_id
           LEFT JOIN hq_codes c ON c.code = l.code
           LEFT JOIN branches sb ON sb.id = df.source_branch_id
           LEFT JOIN users ru ON ru.id = df.resolved_by
          WHERE df.doc_id = ?
          ORDER BY df.id ASC'
    );
    $stDiffs->execute([$docId]);
    $diffs = $stDiffs->fetchAll(PDO::FETCH_ASSOC);

    // جلب الأحداث
    $stEvents = $pdo->prepare(
        'SELECT e.*, u.full_name AS user_name
           FROM doc_events e
           LEFT JOIN users u ON u.id = e.user_id
          WHERE e.doc_kind = \'stock\' AND e.doc_id = ?
          ORDER BY e.id ASC'
    );
    $stEvents->execute([$docId]);
    $events = $stEvents->fetchAll(PDO::FETCH_ASSOC);

    // استخراج sent_at و received_at من doc_events
    $sentAt = null;
    $receivedAt = null;
    foreach ($events as $ev) {
        if ($ev['event'] === 'status:sent' && $sentAt === null) {
            $sentAt = (string) $ev['at'];
        }
        if ($ev['event'] === 'status:received' && $receivedAt === null) {
            $receivedAt = (string) $ev['at'];
        }
    }
    $doc['sent_at'] = $sentAt;
    $doc['received_at'] = $receivedAt;

    return [
        'doc'    => $doc,
        'lines'  => $lines,
        'diffs'  => $diffs,
        'events' => $events,
    ];
}

/**
 * قائمة التحويلات حسب التاب والفرع والفلترة.
 *
 * @param PDO $pdo
 * @param array $filters [tab => 'out'|'in'|'diffs'|'archive', branch_id => int|null, from => date, to => date, limit => int]
 * @return array
 */
function transfer_list(PDO $pdo, array $filters): array
{
    $tab      = (string) ($filters['tab'] ?? 'out');
    $branchId = isset($filters['branch_id']) && $filters['branch_id'] > 0 ? (int) $filters['branch_id'] : null;
    $from     = !empty($filters['from']) ? (string) $filters['from'] : null;
    $to       = !empty($filters['to']) ? (string) $filters['to'] : null;
    $limit    = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 100;

    $where = ["d.doc_type = 'transfer'"];
    $params = [];

    match ($tab) {
        'out' => (function () use (&$where, &$params, $branchId) {
            $where[] = "d.status IN ('requested', 'preparing', 'sent', 'diff_open', 'closed')";
            if ($branchId !== null) {
                $where[] = "d.branch_id = ?";
                $params[] = $branchId;
            }
        })(),
        'in' => (function () use (&$where, &$params, $branchId) {
            $where[] = "d.status IN ('sent', 'diff_open', 'closed')";
            if ($branchId !== null) {
                $where[] = "d.counter_branch_id = ?";
                $params[] = $branchId;
            }
        })(),
        'diffs' => (function () use (&$where, &$params, $branchId) {
            $where[] = "d.status = 'diff_open'";
            if ($branchId !== null) {
                $where[] = "(d.branch_id = ? OR d.counter_branch_id = ?)";
                $params[] = $branchId;
                $params[] = $branchId;
            }
        })(),
        'archive' => (function () use (&$where, &$params, $branchId) {
            $where[] = "d.status IN ('closed', 'cancelled')";
            if ($branchId !== null) {
                $where[] = "(d.branch_id = ? OR d.counter_branch_id = ?)";
                $params[] = $branchId;
                $params[] = $branchId;
            }
        })(),
        default => null,
    };

    if ($from !== null) {
        $where[] = "d.doc_date >= ?";
        $params[] = $from;
    }
    if ($to !== null) {
        $where[] = "d.doc_date <= ?";
        $params[] = $to;
    }

    $sql = 'SELECT d.*, b.name AS from_branch_name, cb.name AS to_branch_name,
                   u.full_name AS created_by_name
              FROM stock_docs d
              LEFT JOIN branches b ON b.id = d.branch_id
              LEFT JOIN branches cb ON cb.id = d.counter_branch_id
              LEFT JOIN users u ON u.id = d.created_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY d.id DESC
             LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($docs)) {
        return [];
    }

    $docIds = array_map(fn($d) => (int) $d['id'], $docs);
    $inDocs = implode(',', $docIds);

    // تواريخ الإرسال والاستلام من doc_events في استعلام واحد مجمّع (القاعدة ١)
    $stEv = $pdo->query(
        "SELECT doc_id, event, MAX(at) AS at
           FROM doc_events
          WHERE doc_kind = 'stock' AND doc_id IN ($inDocs) AND event IN ('status:sent', 'status:received')
          GROUP BY doc_id, event"
    );
    $evMap = [];
    foreach ($stEv->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $evMap[(int) $er['doc_id']][$er['event']] = (string) $er['at'];
    }

    // إجماليات السطور في استعلام واحد مجمّع
    $stTot = $pdo->query(
        "SELECT doc_id, COUNT(*) AS lines_count, SUM(qty_pieces) AS total_pieces
           FROM stock_doc_lines
          WHERE doc_id IN ($inDocs)
          GROUP BY doc_id"
    );
    $totMap = [];
    foreach ($stTot->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $totMap[(int) $tr['doc_id']] = [
            'lines_count'  => (int) $tr['lines_count'],
            'total_pieces' => (float) $tr['total_pieces'],
        ];
    }

    // إجماليات الفروق في استعلام واحد مجمّع
    $stDiff = $pdo->query(
        "SELECT doc_id, kind, SUM(qty) AS diff_qty, COUNT(*) AS diff_count
           FROM stock_transfer_diffs
          WHERE doc_id IN ($inDocs)
          GROUP BY doc_id, kind"
    );
    $diffMap = [];
    foreach ($stDiff->fetchAll(PDO::FETCH_ASSOC) as $dr) {
        $diffMap[(int) $dr['doc_id']][$dr['kind']] = (float) $dr['diff_qty'];
    }

    foreach ($docs as &$d) {
        $did = (int) $d['id'];
        $d['sent_at']     = $evMap[$did]['status:sent'] ?? null;
        $d['received_at'] = $evMap[$did]['status:received'] ?? null;
        $d['lines_count'] = $totMap[$did]['lines_count'] ?? 0;
        $d['total_pieces'] = $totMap[$did]['total_pieces'] ?? 0.0;
        $d['short_qty']   = $diffMap[$did]['short'] ?? 0.0;
        $d['excess_qty']  = $diffMap[$did]['excess'] ?? 0.0;
        $d['duration_hours'] = ($d['sent_at'] && $d['received_at'])
            ? round((strtotime($d['received_at']) - strtotime($d['sent_at'])) / 3600, 2)
            : null;
    }
    unset($d);

    return $docs;
}

/**
 * جلب حالات الفروق المفتوحة والمعلقة (بريف ٨٥٣: تشمل pending, approved, rejected بالإضافة لـ open القديم).
 */
function transfer_open_diffs(PDO $pdo, ?int $branchId = null): array
{
    $where = ["df.status IN ('open', 'pending', 'approved', 'rejected')"];
    $params = [];
    if ($branchId !== null) {
        $where[] = "(d.branch_id = ? OR d.counter_branch_id = ? OR df.counter_branch_id = ?)";
        $params[] = $branchId;
        $params[] = $branchId;
        $params[] = $branchId;
    }

    $sql = 'SELECT df.*, c.name AS item_name, l.code,
                   d.doc_no, d.doc_year, d.branch_id, d.counter_branch_id,
                   b.name AS from_branch_name, cb.name AS to_branch_name,
                   counter_b.name AS counter_branch_name
              FROM stock_transfer_diffs df
              JOIN stock_docs d ON d.id = df.doc_id
              LEFT JOIN stock_doc_lines l ON l.id = df.doc_line_id
              LEFT JOIN hq_codes c ON c.code = l.code
              LEFT JOIN branches b ON b.id = d.branch_id
              LEFT JOIN branches cb ON cb.id = d.counter_branch_id
              LEFT JOIN branches counter_b ON counter_b.id = df.counter_branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY df.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * المخازن المدعومة للتحويل (١ بونجور · ٢ اي بي سي · ٣ الفجالة · ٤ دجلة · ٦ الإدارة).
 *
 * بريف ٢٢٩: الاستعلام اتنقل من transfers.php بالحرف — الصفحة عرض بس.
 */
function transfer_supported_branches(PDO $pdo): array
{
    $st = $pdo->query('SELECT id, name FROM branches WHERE id IN (1, 2, 3, 4, 6) ORDER BY id ASC');
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * عدد فروق التحويل المفتوحة (الشارة الحمرا في تاب «فروق مفتوحة»).
 *
 * بريف ٢٢٩: الاستعلام اتنقل من transfers.php بالحرف.
 */
function transfer_open_diffs_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM stock_transfer_diffs WHERE status IN ('open', 'pending', 'approved')")->fetchColumn();
}

/**
 * استعلام تقرير التحويلات الشامل (type=transfers).
 */
function transfer_reports_transfers(PDO $pdo, string $from, string $to, ?int $branchId = null): array
{
    $where = ["d.doc_type = 'transfer'", "d.doc_date >= ?", "d.doc_date <= ?"];
    $params = [$from, $to];
    if ($branchId !== null && $branchId > 0) {
        $where[] = "(d.branch_id = ? OR d.counter_branch_id = ?)";
        $params[] = $branchId;
        $params[] = $branchId;
    }

    $sql = 'SELECT d.*, b.name AS from_branch_name, cb.name AS to_branch_name
              FROM stock_docs d
              LEFT JOIN branches b ON b.id = d.branch_id
              LEFT JOIN branches cb ON cb.id = d.counter_branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY d.doc_date DESC, d.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($docs)) {
        return [];
    }

    $docIds = array_map(fn($d) => (int) $d['id'], $docs);
    $inDocs = implode(',', $docIds);

    // تواريخ الأحداث
    $stEv = $pdo->query(
        "SELECT doc_id, event, MAX(at) AS at
           FROM doc_events
          WHERE doc_kind = 'stock' AND doc_id IN ($inDocs) AND event IN ('status:sent', 'status:received')
          GROUP BY doc_id, event"
    );
    $evMap = [];
    foreach ($stEv->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $evMap[(int) $er['doc_id']][$er['event']] = (string) $er['at'];
    }

    // سطور
    $stTot = $pdo->query(
        "SELECT doc_id, COUNT(*) AS lines_count, SUM(qty_pieces) AS total_pieces
           FROM stock_doc_lines
          WHERE doc_id IN ($inDocs)
          GROUP BY doc_id"
    );
    $totMap = [];
    foreach ($stTot->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $totMap[(int) $tr['doc_id']] = [
            'lines_count'  => (int) $tr['lines_count'],
            'total_pieces' => (float) $tr['total_pieces'],
        ];
    }

    // فروق
    $stDiff = $pdo->query(
        "SELECT doc_id, kind, SUM(qty) AS diff_qty
           FROM stock_transfer_diffs
          WHERE doc_id IN ($inDocs)
          GROUP BY doc_id, kind"
    );
    $diffMap = [];
    foreach ($stDiff->fetchAll(PDO::FETCH_ASSOC) as $dr) {
        $diffMap[(int) $dr['doc_id']][$dr['kind']] = (float) $dr['diff_qty'];
    }

    foreach ($docs as &$d) {
        $did = (int) $d['id'];
        $d['sent_at']        = $evMap[$did]['status:sent'] ?? null;
        $d['received_at']    = $evMap[$did]['status:received'] ?? null;
        $d['lines_count']    = $totMap[$did]['lines_count'] ?? 0;
        $d['total_pieces']   = $totMap[$did]['total_pieces'] ?? 0.0;
        $d['short_qty']      = $diffMap[$did]['short'] ?? 0.0;
        $d['excess_qty']     = $diffMap[$did]['excess'] ?? 0.0;
        $d['duration_hours'] = ($d['sent_at'] && $d['received_at'])
            ? round((strtotime($d['received_at']) - strtotime($d['sent_at'])) / 3600, 2)
            : null;
    }
    unset($d);

    return $docs;
}

/**
 * استعلام تقرير عجز التحويلات المسترد (type=transfer_shorts).
 */
function transfer_reports_shorts(PDO $pdo, string $from, string $to, ?int $branchId = null): array
{
    $where = ["df.kind = 'short'", "d.doc_date >= ?", "d.doc_date <= ?"];
    $params = [$from, $to];
    if ($branchId !== null && $branchId > 0) {
        $where[] = "(d.branch_id = ? OR d.counter_branch_id = ?)";
        $params[] = $branchId;
        $params[] = $branchId;
    }

    $sql = 'SELECT df.*, c.name AS item_name, l.code,
                   d.doc_no, d.doc_year, d.doc_date, d.branch_id, d.counter_branch_id,
                   b.name AS from_branch_name, cb.name AS to_branch_name
              FROM stock_transfer_diffs df
              JOIN stock_docs d ON d.id = df.doc_id
              LEFT JOIN stock_doc_lines l ON l.id = df.doc_line_id
              LEFT JOIN hq_codes c ON c.code = l.code
              LEFT JOIN branches b ON b.id = d.branch_id
              LEFT JOIN branches cb ON cb.id = d.counter_branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY df.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
