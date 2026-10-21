<?php
/**
 * machines_lib.php — منطق موديول عداد المكن ومطابقة الاستهلاك بالمبيعات
 * نظام الفجالة ستور المالي (بريف ١٩٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// =========================================================================
//  ١. إدارة المكن (Machines Management)
// =========================================================================

/**
 * جلب قائمة المكنات (مع اسم الفرع).
 *
 * @return array[] id · branch_id · branch_name · name · kind · is_active · sort_order
 */
function machines_list(PDO $pdo, ?int $branchId = null, bool $activeOnly = false): array
{
    $sql = "
        SELECT 
            m.id, m.branch_id, b.name AS branch_name, m.name, m.kind, m.is_active, m.sort_order, m.created_at
        FROM machines m
        LEFT JOIN branches b ON b.id = m.branch_id
        WHERE 1=1
    ";
    $params = [];
    if ($branchId !== null && $branchId > 0) {
        $sql .= " AND m.branch_id = ?";
        $params[] = $branchId;
    }
    if ($activeOnly) {
        $sql .= " AND m.is_active = 1";
    }
    $sql .= " ORDER BY m.branch_id ASC, m.sort_order ASC, m.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * حفظ مكنة (إضافة أو تعديل).
 */
function machines_save(PDO $pdo, array $data, ?int $id = null): int
{
    $branchId = (int) ($data['branch_id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $kind = in_array($data['kind'] ?? '', ['copy', 'print'], true) ? $data['kind'] : 'copy';
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $sortOrder = (int) ($data['sort_order'] ?? 0);

    if ($branchId <= 0 || $name === '') {
        throw new InvalidArgumentException('اسم المكنة والفرع مطلوبان.');
    }

    if ($id !== null && $id > 0) {
        $st = $pdo->prepare("
            UPDATE machines 
            SET branch_id = ?, name = ?, kind = ?, is_active = ?, sort_order = ?
            WHERE id = ?
        ");
        $st->execute([$branchId, $name, $kind, $isActive, $sortOrder, $id]);
        return $id;
    }

    $st = $pdo->prepare("
        INSERT INTO machines (branch_id, name, kind, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");
    $st->execute([$branchId, $name, $kind, $isActive, $sortOrder]);
    return (int) $pdo->lastInsertId();
}

/**
 * تفعيل أو تعطيل مكنة.
 */
function machines_toggle_active(PDO $pdo, int $id, bool $isActive): void
{
    if ($id <= 0) return;
    $st = $pdo->prepare("UPDATE machines SET is_active = ? WHERE id = ?");
    $st->execute([$isActive ? 1 : 0, $id]);
}

// =========================================================================
//  ٢. قراءات العدادات (Readings)
// =========================================================================

/**
 * جلب مصفوفة القراءات لفترة معينة (تشمل اليوم السابق لحساب الفرق).
 *
 * @return array<int, array<string, array>> [machine_id => [YYYY-MM-DD => reading_data]]
 */
function machine_readings_matrix(PDO $pdo, array $machineIds, string $dateFrom, string $dateTo): array
{
    if (empty($machineIds)) return [];

    // جلب من اليوم السابق للبداية حتى يتوفر رصيد البداية
    $prevDate = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
    $ph = implode(',', array_fill(0, count($machineIds), '?'));

    $sql = "
        SELECT 
            r.id, r.machine_id, r.reading_date, r.counter, r.notes, r.created_by, u.full_name AS user_name, r.updated_at
        FROM machine_readings r
        LEFT JOIN users u ON u.id = r.created_by
        WHERE r.machine_id IN ($ph)
          AND r.reading_date >= ? AND r.reading_date <= ?
        ORDER BY r.reading_date ASC
    ";

    $params = array_merge($machineIds, [$prevDate, $dateTo]);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $matrix = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int) $row['machine_id'];
        $date = (string) $row['reading_date'];
        $matrix[$mid][$date] = [
            'id'         => (int) $row['id'],
            'counter'    => (int) $row['counter'],
            'notes'      => (string) ($row['notes'] ?? ''),
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'user_name'  => (string) ($row['user_name'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $matrix;
}

/**
 * حفظ قراءة عداد (إدخال فوري على blur مع فحص التصفير).
 */
function machine_reading_save(PDO $pdo, int $machineId, string $date, int $counter, ?string $notes, int $uid): array
{
    if ($machineId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('بيانات غير صحيحة.');
    }
    if ($counter < 0) {
        throw new InvalidArgumentException('العداد لا يمكن أن يكون سالباً.');
    }

    // جلب قراءة اليوم السابق لفحص التراجع (تصفير العداد)
    $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $stPrev = $pdo->prepare("SELECT counter FROM machine_readings WHERE machine_id = ? AND reading_date = ? LIMIT 1");
    $stPrev->execute([$machineId, $prevDate]);
    $prevCounter = $stPrev->fetchColumn();

    $isReset = false;
    $diff = null;
    if ($prevCounter !== false && $prevCounter !== null) {
        $prevVal = (int) $prevCounter;
        $diff = $counter - $prevVal;
        if ($counter < $prevVal) {
            $isReset = true;
        }
    }

    $st = $pdo->prepare("
        INSERT INTO machine_readings (machine_id, reading_date, counter, notes, created_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            counter = VALUES(counter),
            notes = COALESCE(VALUES(notes), notes),
            created_by = VALUES(created_by),
            updated_at = NOW()
    ");
    $st->execute([$machineId, $date, $counter, $notes, $uid]);

    return [
        'ok'       => true,
        'counter'  => $counter,
        'diff'     => $diff,
        'is_reset' => $isReset,
        'notes'    => $notes ?: '',
    ];
}

// =========================================================================
//  ٣. مبيعات قسم الخدمات (قسم 1000034)
// =========================================================================

/**
 * جلب مبيعات قسم الخدمات مجمّعة لكل فرع ويوم (استعلام واحد بدون حلقة).
 *
 * @return array<int, array<string, float>> [branch_id => [YYYY-MM-DD => sold_qty]]
 */
function machine_services_sales_map(PDO $pdo, array $branchIds, string $dateFrom, string $dateTo): array
{
    if (empty($branchIds)) return [];

    $salesMap = [];
    foreach ($branchIds as $bid) {
        $salesMap[(int) $bid] = [];
    }

    $ph = implode(',', array_fill(0, count($branchIds), '?'));
    $startDate = $dateFrom . ' 00:00:00';
    $endDate = $dateTo . ' 23:59:59';

    // 1. مبيعات الفروع من br_invoices + br_invoice_lines
    try {
        $sql = "
            SELECT 
                h.company_branch AS branch_id,
                DATE(h.invoice_date) AS sale_date,
                SUM(CASE WHEN h.doc_type IN (6, 7, 17) THEN -l.quantity ELSE l.quantity END) AS qty
            FROM br_invoice_lines l
            JOIN br_invoices h 
              ON h.agent_key = l.agent_key 
             AND h.sth_id    = l.sth_id 
             AND h.suffix    = l.suffix
            JOIN hq_item_cats c 
              ON c.item_id = l.item_id 
             AND c.is1_id = 1000034
            WHERE h.company_branch IN ($ph)
              AND h.invoice_date >= ? AND h.invoice_date <= ?
            GROUP BY h.company_branch, DATE(h.invoice_date)
        ";
        $params = array_merge($branchIds, [$startDate, $endDate]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int) $r['branch_id'];
            $d = (string) $r['sale_date'];
            $q = (float) $r['qty'];
            $salesMap[$bid][$d] = ($salesMap[$bid][$d] ?? 0.0) + $q;
        }
    } catch (Throwable $e) { /* تجاهل */ }

    // 2. مبيعات الكاشير من pos_invoices + pos_invoice_lines (إن وُجدت)
    try {
        $sqlPos = "
            SELECT 
                p.branch_id,
                DATE(p.sold_at) AS sale_date,
                SUM(pl.qty) AS qty
            FROM pos_invoice_lines pl
            JOIN pos_invoices p ON p.id = pl.invoice_id
            JOIN hq_item_cats c ON c.item_id = pl.item_id AND c.is1_id = 1000034
            WHERE p.branch_id IN ($ph)
              AND p.sold_at >= ? AND p.sold_at <= ?
              AND p.status IN ('completed', 'migrated')
            GROUP BY p.branch_id, DATE(p.sold_at)
        ";
        $paramsPos = array_merge($branchIds, [$startDate, $endDate]);
        $stPos = $pdo->prepare($sqlPos);
        $stPos->execute($paramsPos);
        while ($r = $stPos->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int) $r['branch_id'];
            $d = (string) $r['sale_date'];
            $q = (float) $r['qty'];
            $salesMap[$bid][$d] = ($salesMap[$bid][$d] ?? 0.0) + $q;
        }
    } catch (Throwable $e) { /* تجاهل */ }

    return $salesMap;
}

// =========================================================================
//  ٤. تقرير المطابقة (Reconciliation)
// =========================================================================

/**
 * حساب تقرير المطابقة لفرع أو كل الفروع في فترة محددة.
 *
 * @return array[] كل عنصر: [branch_id, branch_name, date, counter_consumption, sold_qty, diff, status, has_missing, has_unnoted_reset]
 */
function machine_reconciliation_report(PDO $pdo, ?int $branchId, string $dateFrom, string $dateTo): array
{
    $machines = machines_list($pdo, $branchId, true);
    if (empty($machines)) return [];

    $branchNames = [];
    $machinesByBranch = [];
    $allMachineIds = [];
    foreach ($machines as $m) {
        $bid = (int) $m['branch_id'];
        $mid = (int) $m['id'];
        $branchNames[$bid] = (string) $m['branch_name'];
        $machinesByBranch[$bid][] = $m;
        $allMachineIds[] = $mid;
    }

    // استعلام القراءات المجمّع
    $readingsMatrix = machine_readings_matrix($pdo, $allMachineIds, $dateFrom, $dateTo);

    // استعلام المبيعات المجمّع
    $branchIds = array_keys($machinesByBranch);
    $salesMap = machine_services_sales_map($pdo, $branchIds, $dateFrom, $dateTo);

    // توليد الأيام في الفترة تنازلياً
    $days = [];
    $curr = strtotime($dateTo);
    $end = strtotime($dateFrom);
    while ($curr >= $end) {
        $days[] = date('Y-m-d', $curr);
        $curr = strtotime('-1 day', $curr);
    }

    $report = [];
    foreach ($branchIds as $bid) {
        $bName = $branchNames[$bid] ?? ('فرع ' . $bid);
        $bMachines = $machinesByBranch[$bid] ?? [];

        foreach ($days as $date) {
            $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
            $branchConsumption = 0;
            $hasMissing = false;
            $hasUnnotedReset = false;
            $machinesCount = count($bMachines);
            $countedMachines = 0;

            foreach ($bMachines as $m) {
                $mid = (int) $m['id'];
                $rToday = $readingsMatrix[$mid][$date] ?? null;
                $rPrev  = $readingsMatrix[$mid][$prevDate] ?? null;

                if ($rToday === null || $rPrev === null) {
                    $hasMissing = true;
                    continue;
                }

                $cntToday = (int) $rToday['counter'];
                $cntPrev  = (int) $rPrev['counter'];

                // فحص تصفير العداد
                if ($cntToday < $cntPrev) {
                    $note = trim((string) ($rToday['notes'] ?? ''));
                    if ($note === '') {
                        $hasUnnotedReset = true;
                        // لا يُحسب في الاستهلاك لحين كتابة ملاحظة
                        continue;
                    }
                    // لو فيه ملاحظة، يُحسب كقراءة جديدة من الصفر
                    $branchConsumption += $cntToday;
                    $countedMachines++;
                } else {
                    $branchConsumption += ($cntToday - $cntPrev);
                    $countedMachines++;
                }
            }

            // لو فيه مكن ناقص القراءة، الاستهلاك والفرق يظهر «—»
            $consumptionVal = $hasMissing ? null : $branchConsumption;
            $soldVal = (float) ($salesMap[$bid][$date] ?? 0.0);

            $diffVal = null;
            $status = 'missing';

            if ($consumptionVal !== null) {
                $diffVal = $consumptionVal - (int)$soldVal;
                if ($diffVal > 0) {
                    $status = 'deficit'; // عجز: ورق خرج بدون بيع
                } elseif ($diffVal < 0) {
                    $status = 'surplus'; // زيادة: بيع أكثر من العداد
                } else {
                    $status = 'match';   // مطابق
                }
            }

            $report[] = [
                'branch_id'           => $bid,
                'branch_name'         => $bName,
                'date'                => $date,
                'counter_consumption'=> $consumptionVal,
                'sold_qty'            => $soldVal,
                'diff'                => $diffVal,
                'status'              => $status,
                'has_missing'         => $hasMissing,
                'has_unnoted_reset'   => $hasUnnotedReset,
                'machines_count'      => $machinesCount,
                'counted_machines'    => $countedMachines,
            ];
        }
    }

    return $report;
}
