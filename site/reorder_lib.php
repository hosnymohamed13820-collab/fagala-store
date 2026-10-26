<?php
/**
 * reorder_lib.php — منطق ودوال حد إعادة الطلب واقتراحات النواقص (بريف ٢١٣)
 * نظام الفجالة ستور المالي
 *
 * حساب متوسط الاستهلاك اليومي وحدود الطلب (أدنى/أقصى) لكل صنف في كل فرع،
 * وتوليد اقتراحات طلب النواقص لإرسالها للإدارة.
 */
declare(strict_types=1);

require_once __DIR__ . '/item_units_lib.php';

/**
 * ربط وكيل الفرع بمخزن الفرع (sto_id).
 * الفروع الأربعة: الفجالة (2) · بونجور (3) · دجلة (5) · اي بي سي (6).
 */
const REORDER_AGENT_TO_STO = [
    'zahraa'  => 2,
    'bonjour' => 3,
    'degla'   => 5,
    'abc'     => 6,
];

/**
 * جلب إعدادات حد إعادة الطلب من جدول settings أو الافتراضيات.
 *
 * @param PDO $pdo
 * @return array{reorder_window_days:int, reorder_lead_days:int, reorder_cover_days:int, reorder_min_sale_days:int}
 */
function reorder_settings(PDO $pdo): array
{
    $defaults = [
        'reorder_window_days'   => 60,
        'reorder_lead_days'     => 7,
        'reorder_cover_days'    => 21,
        'reorder_min_sale_days' => 4,
    ];

    $res = [];
    foreach ($defaults as $k => $def) {
        if (function_exists('setting')) {
            $val = setting($k, (string) $def);
        } else {
            $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $st->execute([$k]);
            $col = $st->fetchColumn();
            $val = ($col !== false && $col !== null) ? $col : (string) $def;
        }
        $res[$k] = max(1, (int) $val);
    }

    return $res;
}

/**
 * جلب الفروع الأربعة المشمولة في حد إعادة الطلب.
 *
 * @param PDO $pdo
 * @return array<int, array{id:int, name:string, sto_id:int}>
 */
function reorder_branches(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, name, sto_id
           FROM branches
          WHERE sto_id IN (2, 3, 5, 6)
          ORDER BY FIELD(sto_id, 2, 3, 5, 6)"
    );

    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'sto_id' => (int) $r['sto_id'],
        ];
    }

    return $out;
}

/**
 * خريطة من sto_id إلى بيانات الفرع.
 *
 * @param PDO $pdo
 * @return array<int, array{id:int, name:string, sto_id:int}>
 */
function reorder_branch_sto_map(PDO $pdo): array
{
    $branches = reorder_branches($pdo);
    $map = [];
    foreach ($branches as $b) {
        $map[$b['sto_id']] = $b;
    }
    return $map;
}

/**
 * حساب مجمّع لحد إعادة الطلب لكل الفروع والأصناف.
 * استعلام واحد للمبيعات والمرتجعات في آخر نافذة أيام، تجميع في PHP، ثم upsert مجمّع بدفعات 500.
 *
 * @param PDO $pdo
 * @return array{items:int, ms:float}
 */
function reorder_recalc(PDO $pdo): array
{
    $start = microtime(true);
    $settings = reorder_settings($pdo);

    $windowDays  = (int) $settings['reorder_window_days'];
    $leadDays    = (int) $settings['reorder_lead_days'];
    $coverDays   = (int) $settings['reorder_cover_days'];
    $minSaleDays = (int) $settings['reorder_min_sale_days'];

    $cutoff = date('Y-m-d 00:00:00', strtotime("-{$windowDays} days"));
    $branchStoMap = reorder_branch_sto_map($pdo);

    // ١) استعلام مجمّع واحد لمبيعات ومرتجعات آخر reorder_window_days
    $sql = "SELECT 
                inv.agent_key,
                l.item_id,
                l.item_code,
                MAX(l.unit_factor) AS line_unit_factor,
                SUM(CASE WHEN inv.doc_type = 4 THEN l.quantity ELSE 0 END) AS sale_qty,
                SUM(CASE WHEN inv.doc_type IN (7, 17) THEN l.quantity ELSE 0 END) AS return_qty,
                GROUP_CONCAT(DISTINCT CASE WHEN inv.doc_type = 4 THEN DATE(inv.invoice_date) END) AS sale_dates
            FROM br_invoices inv
            JOIN br_invoice_lines l 
              ON inv.agent_key = l.agent_key 
             AND inv.sth_id = l.sth_id 
             AND inv.suffix = l.suffix
            WHERE inv.doc_type IN (4, 7, 17)
              AND inv.invoice_date >= :cutoff
              AND l.item_id IS NOT NULL 
              AND l.item_id > 0
            GROUP BY inv.agent_key, l.item_id, l.item_code";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['cutoff' => $cutoff]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [
            'items' => 0,
            'ms'    => round((microtime(true) - $start) * 1000, 1),
        ];
    }

    // ٢) جمع الأكواد الفريدة لجلب معاملاتها في استعلام واحد
    $codesSet = [];
    foreach ($rows as $r) {
        $c = trim((string) ($r['item_code'] ?? ''));
        if ($c !== '') {
            $codesSet[$c] = true;
        }
    }
    $codeFactors = item_code_factors($pdo, array_keys($codesSet));

    // ٣) تجميع بالقطع لكل (item_id, branch_id)
    $aggregated = [];
    foreach ($rows as $r) {
        $agentKey = (string) ($r['agent_key'] ?? '');
        $stoId = REORDER_AGENT_TO_STO[$agentKey] ?? null;
        if ($stoId === null || !isset($branchStoMap[$stoId])) {
            continue;
        }

        $branchId = (int) $branchStoMap[$stoId]['id'];
        $itemId   = (int) $r['item_id'];
        $itemCode = trim((string) ($r['item_code'] ?? ''));

        $lineFactor = ($r['line_unit_factor'] !== null && (float) $r['line_unit_factor'] > 0)
            ? (float) $r['line_unit_factor']
            : null;
        $codeFactor = isset($codeFactors[$itemCode])
            ? (float) $codeFactors[$itemCode]['unit_factor']
            : null;

        $saleQty   = (float) ($r['sale_qty'] ?? 0);
        $returnQty = (float) ($r['return_qty'] ?? 0);
        $netQty    = $saleQty - $returnQty;

        // التحويل لقطع عبر الدالة المعتمدة
        $calcPieces = item_line_pieces($netQty, $lineFactor, $codeFactor);
        $pieces = (float) $calcPieces['pieces'];

        $key = $itemId . '_' . $branchId;
        if (!isset($aggregated[$key])) {
            $aggregated[$key] = [
                'item_id'    => $itemId,
                'branch_id'  => $branchId,
                'net_pieces' => 0.0,
                'dates'      => [],
            ];
        }

        $aggregated[$key]['net_pieces'] += $pieces;

        $saleDatesStr = trim((string) ($r['sale_dates'] ?? ''));
        if ($saleDatesStr !== '') {
            foreach (explode(',', $saleDatesStr) as $d) {
                $d = trim($d);
                if ($d !== '') {
                    $aggregated[$key]['dates'][$d] = true;
                }
            }
        }
    }

    // ٤) حساب المؤشرات مع شرط الحد الأدنى لأيام البيع
    $toUpsert = [];
    foreach ($aggregated as $agg) {
        $saleDays = count($agg['dates']);
        // الأصناف اللي sale_days < reorder_min_sale_days متتحسبش (بيعة واحدة مش اتجاه)
        if ($saleDays < $minSaleDays) {
            continue;
        }

        $netPieces = max(0.0, (float) $agg['net_pieces']);
        $avgDaily  = round($netPieces / $windowDays, 4);
        $minQty    = ceil($avgDaily * $leadDays);
        $maxQty    = ceil($avgDaily * ($leadDays + $coverDays));

        $toUpsert[] = [
            'item_id'   => (int) $agg['item_id'],
            'branch_id' => (int) $agg['branch_id'],
            'avg_daily' => $avgDaily,
            'sale_days' => $saleDays,
            'min_qty'   => $minQty,
            'max_qty'   => $maxQty,
        ];
    }

    if (empty($toUpsert)) {
        return [
            'items' => 0,
            'ms'    => round((microtime(true) - $start) * 1000, 1),
        ];
    }

    // ٥) upsert مجمّع بدفعات 500 — لا يدوس على min_qty/max_qty لو is_manual=1
    $batchSize = 500;
    $totalUpserted = 0;
    $chunks = array_chunk($toUpsert, $batchSize);

    foreach ($chunks as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, 0, NOW())';
            $params[] = $row['item_id'];
            $params[] = $row['branch_id'];
            $params[] = $row['avg_daily'];
            $params[] = $row['sale_days'];
            $params[] = $row['min_qty'];
            $params[] = $row['max_qty'];
        }

        $upsertSql = 'INSERT INTO item_reorder 
            (item_id, branch_id, avg_daily, sale_days, min_qty, max_qty, is_manual, calc_at)
            VALUES ' . implode(', ', $placeholders) . '
            ON DUPLICATE KEY UPDATE
                avg_daily = VALUES(avg_daily),
                sale_days = VALUES(sale_days),
                calc_at   = VALUES(calc_at),
                min_qty   = IF(is_manual = 1, min_qty, VALUES(min_qty)),
                max_qty   = IF(is_manual = 1, max_qty, VALUES(max_qty))';

        $upsertStmt = $pdo->prepare($upsertSql);
        $upsertStmt->execute($params);
        $totalUpserted += count($chunk);
    }

    return [
        'items' => $totalUpserted,
        'ms'    => round((microtime(true) - $start) * 1000, 1),
    ];
}

/**
 * فحص هل الحساب محتاج تحديث تلقائي (MAX(calc_at) أقدم من 24 ساعة أو فاضي).
 *
 * @param PDO $pdo
 * @return bool
 */
function reorder_should_recalc(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT MAX(calc_at) FROM item_reorder');
    $maxCalc = $stmt->fetchColumn();
    if (!$maxCalc) {
        return true;
    }
    return (time() - strtotime((string) $maxCalc)) > 86400;
}

/**
 * وقت آخر حساب لحد إعادة الطلب (MAX(calc_at))، أو null لو لسه ماحصلش حساب.
 *
 * بريف ٢٢٩: الاستعلام اتنقل من reorder.php بالحرف — الصفحة عرض بس.
 */
function reorder_last_calc_at(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT MAX(calc_at) FROM item_reorder');
    $val = $stmt->fetchColumn();
    return ($val === false || $val === null || $val === '') ? null : (string) $val;
}

/**
 * أقسام الأصناف للفلتر: خريطة [is1_id => الاسم] مرتبة بالاسم.
 *
 * بريف ٢٢٩: الاستعلام (والـtry/catch اللي حواليه) اتنقل من reorder.php بالحرف.
 * لو الجدول مش موجود الفلتر يبقى فاضي بدل ما الصفحة تقع.
 *
 * @return array<int, string>
 */
function reorder_categories(PDO $pdo): array
{
    $map = [];
    try {
        $st = $pdo->query('SELECT is1_id, name FROM hq_cat_names ORDER BY name ASC');
        while ($cr = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $cr['is1_id']] = (string) $cr['name'];
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

/**
 * تشغيل الحساب بقفل GET_LOCK لتفادي التزامن بين مستخدمين.
 *
 * @param PDO $pdo
 * @return array{items:int, ms:float}|null null لو فيه حساب شغال بالفعل
 */
function reorder_recalc_locked(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT GET_LOCK('reorder_recalc', 0)");
    $locked = (int) $stmt->fetchColumn();
    if ($locked !== 1) {
        return null;
    }

    try {
        return reorder_recalc($pdo);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('reorder_recalc')");
    }
}

/**
 * جلب أصناف النواقص لفرع محدد (رصيد الفرع < min_qty و max_qty > 0).
 * الترتيب: الأقل تغطية أولاً (الرصيد ÷ avg_daily).
 *
 * @param PDO $pdo
 * @param int $branchId
 * @param int|null $categoryId فلتر اختياري بالقسم
 * @param string|null $search بحث اختياري بالكود أو الاسم
 * @return array<int, array<string, mixed>>
 */
function reorder_branch_items(
    PDO $pdo,
    int $branchId,
    ?int $categoryId = null,
    ?string $search = null
): array {
    // استخراج sto_id للفرع المطلوب
    $bst = $pdo->prepare('SELECT sto_id FROM branches WHERE id = ?');
    $bst->execute([$branchId]);
    $stoId = $bst->fetchColumn();
    if ($stoId === false || $stoId === null) {
        return [];
    }
    $branchStoId = (int) $stoId;

    $where = [
        'r.branch_id = :branch_id',
        'r.max_qty > 0',
        'COALESCE(b.balance, 0) < r.min_qty',
    ];
    $params = [
        'branch_id'     => $branchId,
        'branch_sto_id' => $branchStoId,
    ];

    if ($categoryId !== null && $categoryId > 0) {
        $where[] = 'cn.is1_id = :cat_id';
        $params['cat_id'] = $categoryId;
    }

    $search = trim((string) $search);
    if ($search !== '') {
        // بريف ٢٢٩ب: :q مرة واحدة كانت بتكسر الاستعلام — PDO بـEMULATE_PREPARES=false
        // مابيسمحش بتكرار نفس الـnamed placeholder (HY093). فبنستخدم :q_code و :q_name
        // بنفس القيمة، والنتيجة زي ما المفروض: بحث في الكود أو الاسم.
        $where[] = '(c.code LIKE :q_code OR c.name LIKE :q_name)';
        $params['q_code'] = '%' . $search . '%';
        $params['q_name'] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT 
                r.item_id,
                r.branch_id,
                r.avg_daily,
                r.sale_days,
                r.min_qty,
                r.max_qty,
                r.is_manual,
                r.calc_at,
                COALESCE(b.balance, 0) AS branch_balance,
                COALESCE(hq.balance, 0) AS hq_balance,
                COALESCE(c.code, '') AS code,
                COALESCE(c.name, '') AS name,
                COALESCE(cn.name, 'بدون قسم') AS cat_name,
                cn.is1_id AS cat_id,
                ROUND(r.max_qty - COALESCE(b.balance, 0), 3) AS proposed_qty,
                CASE 
                    WHEN r.avg_daily > 0 THEN (COALESCE(b.balance, 0) / r.avg_daily)
                    ELSE 999999 
                END AS coverage_days
            FROM item_reorder r
            LEFT JOIN hq_branch_balances b 
              ON b.item_id = r.item_id AND b.sto_id = :branch_sto_id
            LEFT JOIN hq_balances hq 
              ON hq.item_id = r.item_id AND hq.sto_id = 1
            LEFT JOIN (
                SELECT item_id, 
                       SUBSTRING_INDEX(GROUP_CONCAT(code ORDER BY code_type ASC, code ASC), ',', 1) AS code,
                       SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY code_type ASC, code ASC), ',', 1) AS name
                FROM hq_codes
                GROUP BY item_id
            ) c ON c.item_id = r.item_id
            LEFT JOIN hq_item_cats ic ON ic.item_id = r.item_id
            LEFT JOIN hq_cat_names cn ON cn.is1_id = ic.is1_id
            WHERE $whereSql
            ORDER BY 
                CASE WHEN COALESCE(b.balance, 0) <= 0 THEN 0 ELSE 1 END ASC,
                coverage_days ASC,
                (r.max_qty - COALESCE(b.balance, 0)) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'item_id'        => (int) $r['item_id'],
            'branch_id'      => (int) $r['branch_id'],
            'code'           => (string) $r['code'],
            'name'           => (string) $r['name'],
            'cat_name'       => (string) $r['cat_name'],
            'cat_id'         => $r['cat_id'] !== null ? (int) $r['cat_id'] : null,
            'branch_balance' => (float) $r['branch_balance'],
            'min_qty'        => (float) $r['min_qty'],
            'max_qty'        => (float) $r['max_qty'],
            'proposed_qty'   => max(0.0, (float) $r['proposed_qty']),
            'hq_balance'     => (float) $r['hq_balance'],
            'avg_daily'      => (float) $r['avg_daily'],
            'sale_days'      => (int) $r['sale_days'],
            'is_manual'      => (int) $r['is_manual'] === 1,
            'coverage_days'  => round((float) $r['coverage_days'], 1),
            'calc_at'        => $r['calc_at'],
        ];
    }

    return $items;
}

/**
 * تحديث يدوي لحد أدنى/أقصى لصنف (يضع is_manual=1).
 *
 * @param PDO $pdo
 * @param int $itemId
 * @param int $branchId
 * @param float $minQty
 * @param float $maxQty
 * @param int $userId
 * @return array<string, mixed>
 */
function reorder_update_item(
    PDO $pdo,
    int $itemId,
    int $branchId,
    float $minQty,
    float $maxQty,
    int $userId
): array {
    $minQty = max(0.0, round($minQty, 3));
    $maxQty = max($minQty, round($maxQty, 3));

    $stmt = $pdo->prepare(
        'UPDATE item_reorder
            SET min_qty = :min_qty,
                max_qty = :max_qty,
                is_manual = 1,
                updated_by = :uid,
                updated_at = NOW()
          WHERE item_id = :item_id AND branch_id = :branch_id'
    );
    $stmt->execute([
        'min_qty'   => $minQty,
        'max_qty'   => $maxQty,
        'uid'       => $userId,
        'item_id'   => $itemId,
        'branch_id' => $branchId,
    ]);

    // جلب الرصيد لحساب المقترح الجديد
    $bst = $pdo->prepare('SELECT sto_id FROM branches WHERE id = ?');
    $bst->execute([$branchId]);
    $stoId = (int) $bst->fetchColumn();

    $balStmt = $pdo->prepare('SELECT balance FROM hq_branch_balances WHERE item_id = ? AND sto_id = ?');
    $balStmt->execute([$itemId, $stoId]);
    $balance = (float) ($balStmt->fetchColumn() ?: 0.0);

    return [
        'item_id'      => $itemId,
        'branch_id'    => $branchId,
        'min_qty'      => $minQty,
        'max_qty'      => $maxQty,
        'is_manual'    => true,
        'proposed_qty' => max(0.0, round($maxQty - $balance, 3)),
    ];
}

/**
 * استعادة الحساب التلقائي لصنف (is_manual=0).
 *
 * @param PDO $pdo
 * @param int $itemId
 * @param int $branchId
 * @param int $userId
 * @return array<string, mixed>
 */
function reorder_reset_manual(PDO $pdo, int $itemId, int $branchId, int $userId): array
{
    $st = $pdo->prepare('SELECT avg_daily FROM item_reorder WHERE item_id = ? AND branch_id = ?');
    $st->execute([$itemId, $branchId]);
    $avgDaily = (float) ($st->fetchColumn() ?: 0.0);

    $settings = reorder_settings($pdo);
    $minQty = ceil($avgDaily * $settings['reorder_lead_days']);
    $maxQty = ceil($avgDaily * ($settings['reorder_lead_days'] + $settings['reorder_cover_days']));

    $up = $pdo->prepare(
        'UPDATE item_reorder
            SET min_qty = :min_qty,
                max_qty = :max_qty,
                is_manual = 0,
                updated_by = :uid,
                updated_at = NOW()
          WHERE item_id = :item_id AND branch_id = :branch_id'
    );
    $up->execute([
        'min_qty'   => $minQty,
        'max_qty'   => $maxQty,
        'uid'       => $userId,
        'item_id'   => $itemId,
        'branch_id' => $branchId,
    ]);

    // جلب الرصيد
    $bst = $pdo->prepare('SELECT sto_id FROM branches WHERE id = ?');
    $bst->execute([$branchId]);
    $stoId = (int) $bst->fetchColumn();

    $balStmt = $pdo->prepare('SELECT balance FROM hq_branch_balances WHERE item_id = ? AND sto_id = ?');
    $balStmt->execute([$itemId, $stoId]);
    $balance = (float) ($balStmt->fetchColumn() ?: 0.0);

    return [
        'item_id'      => $itemId,
        'branch_id'    => $branchId,
        'min_qty'      => $minQty,
        'max_qty'      => $maxQty,
        'is_manual'    => false,
        'proposed_qty' => max(0.0, round($maxQty - $balance, 3)),
    ];
}

/**
 * التحقق من صلاحية المستخدم لفرع معيّن.
 * المالك له صلاحية كل الفروع؛ غير المالك مقيّد بفرعه فقط.
 *
 * @param int $targetBranchId
 * @param array|null $user بيانات المستخدم الحالية من السيشن
 * @return bool
 */
function reorder_can_access_branch(int $targetBranchId, ?array $user): bool
{
    if (empty($user)) {
        return false;
    }
    if (!empty($user['is_owner'])) {
        return true;
    }
    $userBranchId = (int) ($user['branch_id'] ?? 0);
    return $userBranchId > 0 && $userBranchId === $targetBranchId;
}
