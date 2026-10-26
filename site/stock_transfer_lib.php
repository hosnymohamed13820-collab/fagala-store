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
 */
function transfer_count(PDO $pdo, int $docId, int $lineId, float $pieces, int $uid): void
{
    $st = $pdo->prepare('SELECT status, doc_type FROM stock_docs WHERE id = ?');
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc || $doc['doc_type'] !== 'transfer') {
        throw new RuntimeException("التحويل غير موجود (id=$docId).");
    }
    if ($doc['status'] !== 'sent') {
        throw new RuntimeException("العد متاح فقط للتحويلات المرسلة (في الطريق) — الحالة: {$doc['status']}.");
    }

    $stLine = $pdo->prepare('SELECT id FROM stock_doc_lines WHERE id = ? AND doc_id = ?');
    $stLine->execute([$lineId, $docId]);
    if (!$stLine->fetch()) {
        throw new RuntimeException("السطر $lineId لا ينتمي للتحويل $docId.");
    }

    $insRx = $pdo->prepare(
        'INSERT INTO stock_transfer_rx (doc_line_id, received_pieces, counted_by, counted_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             received_pieces = VALUES(received_pieces),
             counted_by = VALUES(counted_by),
             counted_at = VALUES(counted_at)'
    );
    $insRx->execute([$lineId, round($pieces, 3), $uid]);
}

/**
 * تأكيد استلام التحويل (sent -> received أو diff_open).
 *
 * 🔴 تحريك الكمية المبعوتة كاملة من في الطريق إلى رصيد المستلم.
 * ثم معالجة الفروق:
 * - الناقص: حالة short resolved وحركة فورية ترجع للراسل وإشعار للفرعين.
 * - الزيادة: حالة excess open تنتظر تحديد المصدر من الإدارة.
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

        $insDiff = $pdo->prepare(
            'INSERT INTO stock_transfer_diffs
               (doc_id, doc_line_id, item_id, kind, qty, status, source_branch_id, resolve_doc_id, resolved_by, resolved_at, note)
             VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $moves = [];
        $shortDiffCount = 0;
        $excessDiffCount = 0;

        foreach ($lines as $l) {
            $lineId    = (int) $l['id'];
            $itemId    = (int) $l['item_id'];
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

            // 2. فحص العد والمقارنة
            if (isset($rxMap[$lineId])) {
                $countedPieces = round((float) $rxMap[$lineId], 3);
            } else {
                // سطر ما اتعدش = اتعد صفر (كله ناقص)
                $countedPieces = 0.000;
                $insRxMissing->execute([$lineId, $uid]);
            }

            $diff = round($countedPieces - $sentPieces, 3);

            if ($diff < -0.0001) {
                // عجز (ناقص): يرجع للراسل تلقائياً وفوراً
                $shortQty = abs($diff);
                $shortDiffCount++;

                // تسجيل الفرق بحالة resolved فوراً
                $insDiff->execute([
                    $docId,
                    $lineId,
                    $itemId,
                    'short',
                    $shortQty,
                    'resolved',
                    null,
                    $docId, // resolve_doc_id = نفس المستند
                    $uid,
                    date('Y-m-d H:i:s'),
                    'عجز استلام تم رده تلقائياً لمخزن الراسل',
                ]);

                // حركة فورية: خصم من المستلم ورد للراسل
                $moves[] = [
                    'item_id'     => $itemId,
                    'branch_id'   => $toBranchId,
                    'is_transit'  => 0,
                    'qty'         => -$shortQty,
                    'unit_cost'   => null,
                    'doc_line_id' => $lineId,
                ];
                $moves[] = [
                    'item_id'     => $itemId,
                    'branch_id'   => $fromBranchId,
                    'is_transit'  => 0,
                    'qty'         => +$shortQty,
                    'unit_cost'   => null,
                    'doc_line_id' => $lineId,
                ];
            } elseif ($diff > 0.0001) {
                // زيادة: تظل مفتوحة حتى يحدد المدير مصدرها
                $excessQty = $diff;
                $excessDiffCount++;

                $insDiff->execute([
                    $docId,
                    $lineId,
                    $itemId,
                    'excess',
                    $excessQty,
                    'open',
                    null,
                    null,
                    null,
                    null,
                    'زيادة استلام معلقة لحين تحديد المخزن المصدر',
                ]);
            }
        }

        // تطبيق كل الحركات المخزنية المجمعة
        stock_apply_moves($pdo, $docId, $moves, $uid);

        // تحديد الحالة النهائية للمستند:
        // لو فيه فروق مفتوحة (excess) -> diff_open، غير كده -> closed
        $hasOpenExcess = ($excessDiffCount > 0);
        $finalStatus = $hasOpenExcess ? 'diff_open' : 'closed';

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
            $evCl->execute([$docId, $uid, json_encode(['reason' => 'تم الاستلام وإغلاق المستند'], JSON_UNESCAPED_UNICODE)]);
        } elseif ($finalStatus === 'diff_open') {
            $evDiff = $pdo->prepare("INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'status:diff_open', ?, NOW(), ?)");
            $evDiff->execute([$docId, $uid, json_encode(['open_excess' => $excessDiffCount], JSON_UNESCAPED_UNICODE)]);
        }

        // إشعار للفرعين لو فيه عجز رجع
        if ($shortDiffCount > 0) {
            try {
                if (is_file(__DIR__ . '/push_lib.php')) {
                    require_once __DIR__ . '/push_lib.php';
                    if (function_exists('notify')) {
                        $stUsers = $pdo->prepare('SELECT id FROM users WHERE branch_id IN (?, ?) AND is_active = 1');
                        $stUsers->execute([$fromBranchId, $toBranchId]);
                        $notifUids = array_map(fn($r) => (int) $r['id'], $stUsers->fetchAll(PDO::FETCH_ASSOC));
                        if (!empty($notifUids)) {
                            notify(
                                $pdo,
                                $notifUids,
                                'transfer_short',
                                "عجز في استلام تحويل #{$doc['doc_no']}",
                                "تم رد كمية العجز ($shortDiffCount أصناف) تلقائياً إلى مخزن الراسل.",
                                "transfers.php?tab=archive&id=$docId"
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
        if ($diff['status'] !== 'open') {
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
        $stRemain = $pdo->prepare("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = ? AND status = 'open'");
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
 * جلب حالات الفروق المفتوحة (الزيادات المعلقة).
 */
function transfer_open_diffs(PDO $pdo, ?int $branchId = null): array
{
    $where = ["df.status = 'open'"];
    $params = [];
    if ($branchId !== null) {
        $where[] = "(d.branch_id = ? OR d.counter_branch_id = ?)";
        $params[] = $branchId;
        $params[] = $branchId;
    }

    $sql = 'SELECT df.*, c.name AS item_name, l.code,
                   d.doc_no, d.doc_year, d.branch_id, d.counter_branch_id,
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
    return (int) $pdo->query("SELECT COUNT(*) FROM stock_transfer_diffs WHERE status = 'open'")->fetchColumn();
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
