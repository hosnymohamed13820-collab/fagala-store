<?php
/**
 * purchase_group_lib.php — منطق مجموعات الشراء وتجميع الحجوزات
 * نظام الفجالة ستور المالي
 *
 * الغرض:
 *   تجميع طلبات وحجوزات الفروع على نفس الصنف في سطر شراء واحد للمشتريات
 *   (إجمالي الكمية)، مع الاحتفاظ بتفاصيل كل مصدر (الفرع + الحجز/الطلب)
 *   عشان التوزيع يرتّب بالأقدم وقت الاستلام.
 *
 * ⚠️ القواعد الصارمة:
 *   - مفيش استعلام جوه حلقة (N+1).
 *   - المفتاح item_id لو أكبر من صفر وإلا الكود — الاسم مش مفتاح أبداً.
 *   - كمية الحجز المطلوب شراؤها = qty - allocated_qty (مش qty).
 *   - متغيّرش book_reservations.status خالص (الحجز يفضل open).
 *   - متكتبش على book_reservation_lines.allocated_qty هنا (ده في الاستلام بس).
 */
require_once __DIR__ . '/auth.php';

/**
 * جلب الحجوزات والنواقص المتاحة للتجميع.
 *
 * استعلامين اتنين وبس مهما كان عدد الفروع والأصناف:
 *   ١) الحجوزات المفتوحة التي لم تُجمع بعد وباقي منها كمية لم تُخصص
 *   ٢) طلبات النواقص الموجهة للمشتريات والمفتوحة
 *
 * @return array{reservations: array, shortages: array}
 */
function purchase_group_candidates(): array
{
    $pdo = db();
    try {
        // (١) الحجوزات المتاحة
        $resStmt = $pdo->query(
            "SELECT l.id, l.item_id, l.code, l.item_name, l.qty, l.allocated_qty,
                    r.id AS res_id, r.branch_id, r.customer_name, r.created_at,
                    b.name AS branch_name
             FROM book_reservation_lines l
             JOIN book_reservations r ON r.id = l.reservation_id
             LEFT JOIN branches b ON b.id = r.branch_id
             WHERE r.status = 'open'
               AND l.stage = 'reserved'
               AND l.qty > l.allocated_qty
             ORDER BY r.created_at ASC, l.id ASC"
        );
        $reservations = $resStmt->fetchAll();

        // (٢) النواقص المتاحة (source = 'purchase' شرط أساسي)
        $shortStmt = $pdo->query(
            "SELECT l.id, l.item_id, l.code, l.item_name, l.qty,
                    r.id AS req_id, r.from_branch_id AS branch_id, r.created_at,
                    b.name AS branch_name
             FROM shortage_request_lines l
             JOIN shortage_requests r ON r.id = l.request_id
             LEFT JOIN branches b ON b.id = r.from_branch_id
             WHERE r.source = 'purchase'
               AND l.status = 'open'
             ORDER BY r.created_at ASC, l.id ASC"
        );
        $shortages = $shortStmt->fetchAll();

        return [
            'reservations' => $reservations,
            'shortages'    => $shortages,
        ];
    } catch (Throwable $ex) {
        return [
            'reservations' => [],
            'shortages'    => [],
        ];
    }
}

/**
 * إنشاء مجموعة شراء جديدة وتجميع السطور المختارة.
 *
 * الخطوات (كلها في transaction واحدة):
 *   ١) إعادة قراءة السطور من الداتابيز والتحقق من صلاحيتها (تخطي غير المتاح).
 *   ٢) حساب كمية الشراء (الحجز: qty - allocated_qty · الناقص: qty).
 *   ٣) التجميع بمفتاح item_id (أو الكود إذا كان item_id = 0).
 *   ٤) كتابة رأس المجموعة purchase_groups.
 *   ٥) كتابة سطور المجموعة purchase_group_lines.
 *   ٦) كتابة مصادر كل سطر purchase_group_sources (صف لكل سطر أصلي).
 *   ٧) تحديث مرحلة السطور الأصلية (stage = 'grouped' / status = 'grouped') بـ IN (...).
 *
 * 🔴 لا يتم تعديل book_reservations.status ولا allocated_qty أبداً.
 *
 * @param int[]       $resLineIds   معرفات سطور الحجوزات المختارة
 * @param int[]       $shortLineIds معرفات سطور النواقص المختارة
 * @param string|null $name         اسم اختياري للمجموعة
 * @param int         $userId       معرف المستخدم المنشئ
 * @param int         &$skipped     عدد السطور التي تم تخطيها لعدم صلاحيتها
 * @return int رقم مجموعة الشراء المنشأة
 */
function purchase_group_create(array $resLineIds, array $shortLineIds, ?string $name, int $userId, int &$skipped = 0): int
{
    $skipped = 0;

    // تنظيف المدخلات
    $cleanResIds = array_values(array_unique(array_filter(
        array_map(fn($id) => (int)$id, $resLineIds),
        fn($id) => $id > 0
    )));

    $cleanShortIds = array_values(array_unique(array_filter(
        array_map(fn($id) => (int)$id, $shortLineIds),
        fn($id) => $id > 0
    )));

    if (empty($cleanResIds) && empty($cleanShortIds)) {
        throw new RuntimeException('اختار سطر واحد على الأقل.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // ١) قراءة سطور الحجوزات المختارة والتأكد من توافرها
        $fetchedRes = [];
        if (!empty($cleanResIds)) {
            $phRes = implode(',', array_fill(0, count($cleanResIds), '?'));
            $stRes = $pdo->prepare(
                "SELECT l.id, l.item_id, l.code, l.item_name, l.qty, l.allocated_qty,
                        r.id AS res_id, r.branch_id
                 FROM book_reservation_lines l
                 JOIN book_reservations r ON r.id = l.reservation_id
                 WHERE l.id IN ($phRes)
                   AND r.status = 'open'
                   AND l.stage = 'reserved'
                   AND l.qty > l.allocated_qty
                 ORDER BY r.created_at ASC, l.id ASC"
            );
            $stRes->execute($cleanResIds);
            $fetchedRes = $stRes->fetchAll();
        }

        // حساب المتخطي من الحجوزات
        $skippedRes = count($cleanResIds) - count($fetchedRes);

        // ٢) قراءة سطور النواقص المختارة والتأكد من توافرها
        $fetchedShort = [];
        if (!empty($cleanShortIds)) {
            $phShort = implode(',', array_fill(0, count($cleanShortIds), '?'));
            $stShort = $pdo->prepare(
                "SELECT l.id, l.item_id, l.code, l.item_name, l.qty,
                        r.id AS req_id, r.from_branch_id AS branch_id
                 FROM shortage_request_lines l
                 JOIN shortage_requests r ON r.id = l.request_id
                 WHERE l.id IN ($phShort)
                   AND r.source = 'purchase'
                   AND l.status = 'open'
                 ORDER BY r.created_at ASC, l.id ASC"
            );
            $stShort->execute($cleanShortIds);
            $fetchedShort = $stShort->fetchAll();
        }

        // حساب المتخطي من النواقص
        $skippedShort = count($cleanShortIds) - count($fetchedShort);
        $skipped = $skippedRes + $skippedShort;

        if (empty($fetchedRes) && empty($fetchedShort)) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('السطور المختارة لم تعد متاحة للتجميع.');
        }

        // ٣) تجميع السطور والمصادر في الذاكرة
        $grouped = [];

        // معالجة الحجوزات: الكمية = qty - allocated_qty
        foreach ($fetchedRes as $row) {
            $qtyNeeded = (float)$row['qty'] - (float)$row['allocated_qty'];
            if ($qtyNeeded <= 0) {
                continue;
            }
            $itemId = (int)($row['item_id'] ?? 0);
            $code   = trim((string)($row['code'] ?? ''));
            $key    = ($itemId > 0) ? ('i' . $itemId) : ('c' . $code);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_id'   => $itemId,
                    'code'      => $code,
                    'item_name' => trim((string)($row['item_name'] ?? '')),
                    'total_qty' => 0.0,
                    'sources'   => [],
                ];
            } elseif ($grouped[$key]['item_name'] === '' && !empty($row['item_name'])) {
                $grouped[$key]['item_name'] = trim((string)$row['item_name']);
            }

            $grouped[$key]['total_qty'] += $qtyNeeded;
            $branchId = (int)($row['branch_id'] ?? 0);
            $grouped[$key]['sources'][] = [
                'src_type'    => 'reservation',
                'src_line_id' => (int)$row['id'],
                'src_head_id' => (int)$row['res_id'],
                'branch_id'   => $branchId > 0 ? $branchId : null,
                'qty'         => $qtyNeeded,
            ];
        }

        // معالجة النواقص: الكمية = qty
        foreach ($fetchedShort as $row) {
            $qtyNeeded = (float)$row['qty'];
            if ($qtyNeeded <= 0) {
                continue;
            }
            $itemId = (int)($row['item_id'] ?? 0);
            $code   = trim((string)($row['code'] ?? ''));
            $key    = ($itemId > 0) ? ('i' . $itemId) : ('c' . $code);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_id'   => $itemId,
                    'code'      => $code,
                    'item_name' => trim((string)($row['item_name'] ?? '')),
                    'total_qty' => 0.0,
                    'sources'   => [],
                ];
            } elseif ($grouped[$key]['item_name'] === '' && !empty($row['item_name'])) {
                $grouped[$key]['item_name'] = trim((string)$row['item_name']);
            }

            $grouped[$key]['total_qty'] += $qtyNeeded;
            $branchId = (int)($row['branch_id'] ?? 0);
            $grouped[$key]['sources'][] = [
                'src_type'    => 'shortage',
                'src_line_id' => (int)$row['id'],
                'src_head_id' => (int)$row['req_id'],
                'branch_id'   => $branchId > 0 ? $branchId : null,
                'qty'         => $qtyNeeded,
            ];
        }

        if (empty($grouped)) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('لا توجد كميات متاحة للتجميع.');
        }

        // ٤) إدراج رأس المجموعة
        $cleanName = trim((string)$name);
        $groupName = $cleanName !== '' ? mb_substr($cleanName, 0, 150) : null;

        $insGroup = $pdo->prepare(
            'INSERT INTO purchase_groups (name, status, created_by)
             VALUES (?, ?, ?)'
        );
        $insGroup->execute([$groupName, 'open', $userId > 0 ? $userId : null]);
        $groupId = (int)$pdo->lastInsertId();

        // ٥) تحضير استعلامات إدراج السطور والمصادر خارج الحلقة
        $insLine = $pdo->prepare(
            'INSERT INTO purchase_group_lines (group_id, item_id, code, item_name, total_qty, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        // ٦ أعمدة: line_id, src_type, src_line_id, src_head_id, branch_id, qty
        $insSource = $pdo->prepare(
            'INSERT INTO purchase_group_sources (line_id, src_type, src_line_id, src_head_id, branch_id, qty)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $sortOrder = 0;
        foreach ($grouped as $gItem) {
            $sortOrder++;
            $insLine->execute([
                $groupId,
                $gItem['item_id'],
                $gItem['code'],
                $gItem['item_name'] !== '' ? $gItem['item_name'] : null,
                $gItem['total_qty'],
                $sortOrder,
            ]);
            $lineId = (int)$pdo->lastInsertId();

            foreach ($gItem['sources'] as $src) {
                $insSource->execute([
                    $lineId,
                    $src['src_type'],
                    $src['src_line_id'],
                    $src['src_head_id'],
                    $src['branch_id'],
                    $src['qty'],
                ]);
            }
        }

        // ٦) تعليم المصادر بأنها جُمعت (استعلام واحد لكل جدول بـ IN)
        if (!empty($fetchedRes)) {
            $validResIds = array_map(fn($r) => (int)$r['id'], $fetchedRes);
            $phResUp = implode(',', array_fill(0, count($validResIds), '?'));
            $upRes = $pdo->prepare(
                "UPDATE book_reservation_lines SET stage = 'grouped' WHERE id IN ($phResUp)"
            );
            $upRes->execute($validResIds);
        }

        if (!empty($fetchedShort)) {
            $validShortIds = array_map(fn($r) => (int)$r['id'], $fetchedShort);
            $phShortUp = implode(',', array_fill(0, count($validShortIds), '?'));
            $upShort = $pdo->prepare(
                "UPDATE shortage_request_lines SET status = 'grouped' WHERE id IN ($phShortUp)"
            );
            $upShort->execute($validShortIds);
        }

        $pdo->commit();
        return $groupId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * قائمة مجموعات الشراء مع ملخص لكل مجموعة — استعلام واحد مجمّع.
 *
 * @param string $status 'open' | 'closed' | 'all'
 * @return array
 */
function purchase_groups_list(string $status = 'open'): array
{
    $pdo = db();
    try {
        $sql = 'SELECT g.id, g.name, g.status, g.created_by, g.created_at,
                       u.full_name AS creator,
                       COUNT(l.id) AS items_count,
                       COALESCE(SUM(l.total_qty), 0) AS total_qty,
                       COALESCE(SUM(CASE WHEN l.is_checked = 1 THEN 1 ELSE 0 END), 0) AS checked_count
                FROM purchase_groups g
                LEFT JOIN users u ON u.id = g.created_by
                LEFT JOIN purchase_group_lines l ON l.group_id = g.id';

        $args = [];
        if ($status !== '' && $status !== 'all') {
            $sql .= ' WHERE g.status = ?';
            $args[] = $status;
        }

        $sql .= ' GROUP BY g.id, g.name, g.status, g.created_by, g.created_at, u.full_name
                  ORDER BY g.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['items_count']   = (int)$r['items_count'];
            $r['total_qty']     = (float)$r['total_qty'];
            $r['checked_count'] = (int)$r['checked_count'];
            $r['pct']           = $r['items_count'] > 0
                ? (int)round(($r['checked_count'] / $r['items_count']) * 100)
                : 0;
        }
        unset($r);

        return $rows;
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * جلب تفاصيل مجموعة شراء واحدة مع بنودها.
 *
 * @param int $groupId
 * @return array{group: array, lines: array}|null
 */
function purchase_group_get(int $groupId): ?array
{
    if ($groupId <= 0) {
        return null;
    }
    $pdo = db();
    try {
        $stGroup = $pdo->prepare(
            'SELECT g.*, u.full_name AS creator
             FROM purchase_groups g
             LEFT JOIN users u ON u.id = g.created_by
             WHERE g.id = ? LIMIT 1'
        );
        $stGroup->execute([$groupId]);
        $group = $stGroup->fetch();
        if (!$group) {
            return null;
        }

        $stLines = $pdo->prepare(
            'SELECT l.*
             FROM purchase_group_lines l
             WHERE l.group_id = ?
             ORDER BY l.sort_order ASC, l.id ASC'
        );
        $stLines->execute([$groupId]);
        $lines = $stLines->fetchAll();

        return [
            'group' => $group,
            'lines' => $lines,
        ];
    } catch (Throwable $ex) {
        return null;
    }
}
