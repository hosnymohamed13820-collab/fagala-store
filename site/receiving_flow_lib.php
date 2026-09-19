<?php
/**
 * receiving_flow_lib.php — مسار تدفق استلام الفواتير في الفروع
 * نظام الفجالة ستور المالي
 *
 * المنطق:
 * - عند اكتمال توزيع الفاتورة/المجموعة (الحالة done) يتم إنشاء سجلات receipts لكل فرع له كمية.
 * - الاستعلامات مجمّعة دائمًا (ممنوع أي استعلام أو prepare جوه حلقة).
 * - الإشعار لمرة واحدة فقط عند الإنشاء الفعلي للسجل.
 */
declare(strict_types=1);

require_once __DIR__ . '/push_lib.php';
require_once __DIR__ . '/group_lib.php';

/**
 * جلب معرفات سجلات التوزيع لسياق الفاتورة أو المجموعة.
 */
function receiving_ctx_dist_ids(int $distId, int $groupId): array
{
    if ($groupId > 0) {
        $ids = group_code_dist_ids($groupId, '__any__');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
        if (!$ids) return [];

        // 🔴 الفاتورة اللي اتبعتت لوحدها (ليها كارت مفرد) بتخرج من كارت
        //    المجموعة — عشان الفرع يستلم كل صنف **مرة واحدة** مش مرتين.
        //    قرار محمد ١٩ سبتمبر ٢٠٢٦.
        $in = implode(',', $ids);
        $solo = db()->query(
            "SELECT DISTINCT distribution_id FROM receipts
             WHERE group_id = 0 AND distribution_id IN ($in)"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($solo) {
            $ids = array_values(array_diff($ids, array_map('intval', $solo)));
        }
        return $ids;
    }
    return $distId > 0 ? [$distId] : [];
}

/**
 * فتح سجلات الاستلام للفروع بعد اكتمال التوزيع.
 *
 * @param PDO      $pdo            اتصال الداتابيز
 * @param int|null $groupId        معرّف المجموعة (0 أو null للفاتورة المفردة)
 * @param int|null $distributionId معرّف الفاتورة المفردة (0 أو null للمجموعة)
 * @param int      $userId         معرّف المستخدم المنفّذ
 * @return int عدد سجلات الاستلام الجديدة التي تم إنشاؤها
 */
function receiving_open(PDO $pdo, ?int $groupId, ?int $distributionId, int $userId): int
{
    $gId = $groupId !== null && $groupId > 0 ? (int) $groupId : 0;
    $dId = $distributionId !== null && $distributionId > 0 ? (int) $distributionId : 0;

    if ($gId <= 0 && $dId <= 0) {
        return 0;
    }

    // 🔴 سجلات التوزيع بتاعة المجموعة أو الفاتورة بالدالة الرسمية
    // الترتيب: الفاتورة أولاً ثم المجموعة
    $distIds = receiving_ctx_dist_ids($dId, $gId);
    if (empty($distIds)) {
        return 0;
    }

    $distIdsClean = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));
    if (empty($distIdsClean)) {
        return 0;
    }

    $inClause = implode(',', $distIdsClean);

    // استعلام مجمّع لجلب الفروع التي لها كمية موزعة أكبر من صفر (باستثناء الإدارة 6)
    $stBranches = $pdo->query(
        "SELECT dl.branch_id, COUNT(*) AS items, SUM(dl.amount) AS qty
         FROM invoice_distribution_lines dl
         WHERE dl.distribution_id IN ($inClause) AND dl.amount > 0 AND dl.branch_id <> 6
         GROUP BY dl.branch_id"
    );
    $branchRows = $stBranches ? $stBranches->fetchAll() : [];
    if (empty($branchRows)) {
        return 0;
    }

    $branchIds = [];
    foreach ($branchRows as $r) {
        $bid = (int) $r['branch_id'];
        if ($bid > 0) {
            $branchIds[] = $bid;
        }
    }

    if (empty($branchIds)) {
        return 0;
    }

    // استعلام مجمّع لجلب مستخدمي الفروع النشطين دفعة واحدة خارج الحلقة
    $branchesIn = implode(',', array_unique($branchIds));
    $stUsers = $pdo->query(
        "SELECT id, branch_id
         FROM users
         WHERE branch_id IN ($branchesIn) AND is_active = 1"
    );
    $allUsers = $stUsers ? $stUsers->fetchAll() : [];
    $usersByBranch = [];
    foreach ($allUsers as $uRow) {
        $b = (int) $uRow['branch_id'];
        $usersByBranch[$b][] = (int) $uRow['id'];
    }

    // تجهيز استعلام الإدخال مسبقاً خارج الحلقة تماماً
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO receipts (group_id, distribution_id, branch_id, status)
         VALUES (?, ?, ?, "sent")'
    );

    $createdCount = 0;
    foreach ($branchIds as $bid) {
        $ins->execute([$gId, $dId, $bid]);
        if ($ins->rowCount() > 0) {
            $createdCount++;
            $receiptId = (int) $pdo->lastInsertId();
            $targetUsers = $usersByBranch[$bid] ?? [];
            if (!empty($targetUsers) && $receiptId > 0) {
                notify(
                    $pdo,
                    $targetUsers,
                    'receiving',
                    'فاتورة جديدة للاستلام',
                    'تم تجهيز بضاعة جديدة للاستلام في فرعك، افتح الشاشة للمراجعة.',
                    'receiving.php?r=' . $receiptId
                );
            }
        }
    }

    return $createdCount;
}

/**
 * جلب تفاصيل سجل استلام برقم السجل.
 */
function receipt_get(PDO $pdo, int $receiptId): ?array
{
    if ($receiptId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT r.*, b.name AS branch_name
         FROM receipts r
         JOIN branches b ON b.id = r.branch_id
         WHERE r.id = ?
         LIMIT 1'
    );
    $st->execute([$receiptId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * جلب الأصناف الموزعة لفرع هذا السجل لايف من جدول invoice_distribution_lines
 * مع الكميات الممسوحة واسم الصنف.
 */
function receipt_items_for_branch(PDO $pdo, int $receiptId): array
{
    $receipt = receipt_get($pdo, $receiptId);
    if (!$receipt) {
        return [];
    }

    $gId = (int) $receipt['group_id'];
    $dId = (int) $receipt['distribution_id'];
    $bId = (int) $receipt['branch_id'];

    $distIds = receiving_ctx_dist_ids($dId, $gId);
    if (empty($distIds)) {
        return [];
    }

    $distIdsClean = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));
    if (empty($distIdsClean)) {
        return [];
    }

    $inClause = implode(',', $distIdsClean);

    // 1. الكميات الموزعة للفرع (amount هو الكمية، notes هو كود الصنف)
    $stDist = $pdo->prepare(
        "SELECT dl.notes AS item_code, SUM(dl.amount) AS distributed_qty
         FROM invoice_distribution_lines dl
         WHERE dl.distribution_id IN ($inClause) AND dl.branch_id = ? AND dl.amount > 0
         GROUP BY dl.notes"
    );
    $stDist->execute([$bId]);
    $distRows = $stDist->fetchAll();
    if (empty($distRows)) {
        return [];
    }

    $itemsMap = [];
    $codes = [];
    foreach ($distRows as $dr) {
        $c = trim((string) $dr['item_code']);
        if ($c === '') continue;
        $codes[] = $c;
        $itemsMap[$c] = [
            'item_code'       => $c,
            'item_name'       => $c,
            'distributed_qty' => (float) $dr['distributed_qty'],
            'scanned_qty'     => 0.0,
            'diff'            => (float) $dr['distributed_qty'],
        ];
    }

    if (empty($codes)) {
        return [];
    }

    // 2. أسماء الأصناف باستعلام واحد مجمّع (hq_items مفتاحها item_id وليس id)
    $codesUnique = array_values(array_unique($codes));
    $placeholders = implode(',', array_fill(0, count($codesUnique), '?'));
    $stNames = $pdo->prepare(
        "SELECT c.code AS item_code, i.name AS item_name
         FROM hq_codes c
         JOIN hq_items i ON i.item_id = c.item_id
         WHERE c.code IN ($placeholders)"
    );
    $stNames->execute($codesUnique);
    foreach ($stNames->fetchAll() as $nr) {
        $c = (string) $nr['item_code'];
        if (isset($itemsMap[$c])) {
            $itemsMap[$c]['item_name'] = (string) ($nr['item_name'] ?: $c);
        }
    }

    // 3. الكميات التي تم مسحها فعلياً من receipt_lines
    $stScanned = $pdo->prepare(
        'SELECT item_code, scanned_qty
         FROM receipt_lines
         WHERE receipt_id = ?'
    );
    $stScanned->execute([$receiptId]);
    foreach ($stScanned->fetchAll() as $sr) {
        $c = (string) $sr['item_code'];
        if (isset($itemsMap[$c])) {
            $sq = (float) $sr['scanned_qty'];
            $itemsMap[$c]['scanned_qty'] = $sq;
            $itemsMap[$c]['diff'] = $itemsMap[$c]['distributed_qty'] - $sq;
        }
    }

    // ترتيب بالاسم
    uasort($itemsMap, fn($a, $b) => strcmp($a['item_name'], $b['item_name']));

    return array_values($itemsMap);
}

/**
 * جلب آخر أصناف تم مسحها للشاشة.
 */
function receipt_last_scanned(PDO $pdo, int $receiptId, int $limit = 20): array
{
    if ($receiptId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT item_code, item_name, scanned_qty, updated_at
         FROM receipt_lines
         WHERE receipt_id = ? AND scanned_qty > 0
         ORDER BY updated_at DESC, id DESC
         LIMIT ?'
    );
    $st->bindValue(1, $receiptId, PDO::PARAM_INT);
    $st->bindValue(2, max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll() ?: [];
}

/**
 * مطابقة الباركود الممسوح مع أصناف الفاتورة الموزعة للفرع.
 *
 * القواعد:
 * 1. إذا كان الكود خاصاً بوحدة غير القطعة (unit_factor != 1.0) يتم رفضه برسالة واضحة.
 * 2. إذا كان الكود مطابقاً مباشرة لكود الصنف بالفاتورة، يتم قبوله.
 * 3. إذا كان الكود باركوداً بديلاً (مرتبط بنفس item_id في hq_codes) يتم قبوله وإرجاع كود الصنف الأساسي بالفاتورة.
 * 4. لا يوجد أي استعلام داخل حلقة تكرارية.
 */
function receipt_resolve_scanned_code(PDO $pdo, int $receiptId, string $scannedCode): array
{
    $scannedCode = trim($scannedCode);
    if ($scannedCode === '') {
        return ['ok' => false, 'error' => 'كود الصنف مطلوب.'];
    }

    $items = receipt_items_for_branch($pdo, $receiptId);
    if (empty($items)) {
        return ['ok' => false, 'error' => 'لا توجد أصناف موزعة لهذا الفرع.'];
    }

    $invoiceItemsMap = [];
    foreach ($items as $it) {
        $c = (string) $it['item_code'];
        $invoiceItemsMap[$c] = $it;
    }
    $invoiceCodes = array_keys($invoiceItemsMap);

    // 1. فحص الكود الممسوح في hq_codes للتحقق من الوحدة ومعرّف الصنف
    $stCode = $pdo->prepare('SELECT item_id, name, unit_factor FROM hq_codes WHERE code = ? LIMIT 1');
    $stCode->execute([$scannedCode]);
    $hqRow = $stCode->fetch(PDO::FETCH_ASSOC);

    if ($hqRow) {
        // حارس الوحدة: لو وحدة تجميعية (كرتونة/باكتة) نرفض ونطلب باركود القطعة
        $unitFactor = (float)($hqRow['unit_factor'] ?? 1.0);
        if (abs($unitFactor - 1.0) > 0.001) {
            return [
                'ok'    => false,
                'error' => 'هذا الباركود خاص بـ (كرتونة/باكتة) وليس بالقطعة، يرجى مسح باركود القطعة.'
            ];
        }

        // هل الكود نفسه موجود في أصناف الفاتورة مباشرة؟
        if (isset($invoiceItemsMap[$scannedCode])) {
            $matched = $invoiceItemsMap[$scannedCode];
            return [
                'ok'              => true,
                'item_code'       => $matched['item_code'],
                'scanned_code'    => $scannedCode,
                'item_name'       => $matched['item_name'],
                'distributed_qty' => (float)$matched['distributed_qty'],
                'scanned_qty'     => (float)$matched['scanned_qty'],
            ];
        }

        // هل الكود يمثل باركوداً بديلاً لصنف موجود بالفاتورة (نفس item_id)؟
        $itemId = (int)$hqRow['item_id'];
        if ($itemId > 0 && !empty($invoiceCodes)) {
            $inClause = implode(',', array_fill(0, count($invoiceCodes), '?'));
            $stMatch = $pdo->prepare(
                "SELECT c.code
                 FROM hq_codes c
                 WHERE c.item_id = ? AND c.code IN ($inClause)
                 LIMIT 1"
            );
            $params = array_merge([$itemId], $invoiceCodes);
            $stMatch->execute($params);
            $primaryCode = $stMatch->fetchColumn();

            if ($primaryCode && isset($invoiceItemsMap[$primaryCode])) {
                $matched = $invoiceItemsMap[$primaryCode];
                return [
                    'ok'              => true,
                    'item_code'       => $matched['item_code'],
                    'scanned_code'    => $scannedCode,
                    'item_name'       => $matched['item_name'],
                    'distributed_qty' => (float)$matched['distributed_qty'],
                    'scanned_qty'     => (float)$matched['scanned_qty'],
                ];
            }
        }
    } else {
        // لو مش متسجل في hq_codes لكن موجود ككود مباشر في الفاتورة
        if (isset($invoiceItemsMap[$scannedCode])) {
            $matched = $invoiceItemsMap[$scannedCode];
            return [
                'ok'              => true,
                'item_code'       => $matched['item_code'],
                'scanned_code'    => $scannedCode,
                'item_name'       => $matched['item_name'],
                'distributed_qty' => (float)$matched['distributed_qty'],
                'scanned_qty'     => (float)$matched['scanned_qty'],
            ];
        }
    }

    return ['ok' => false, 'error' => 'الصنف ده مش في الفاتورة'];
}

