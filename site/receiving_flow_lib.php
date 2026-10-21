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
 * الفواتير اللي ليها كارت استلام **مفرد** من وسط قايمة سجلات توزيع.
 *
 * 🔴 ده المصدر الوحيد لقاعدة الاستبعاد. بيتستعمل في مكانين لازم يتفقوا:
 *    - receiving_ctx_dist_ids()  → أصناف كارت المجموعة وفروقاتها
 *    - group_export.php          → محتوى الملف المجمّع
 *    لو الاتنين اختلفوا، الفرع هيستلم حاجة والملف فيه حاجة تانية.
 *
 * @param  array $distIds سجلات التوزيع اللي عايزين نفحصها
 * @return array [dist_id => true] — اللي ليه كارت مفرد
 */
function receiving_solo_dist_ids(array $distIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));
    if (!$ids) return [];

    $in  = implode(',', $ids);
    $out = [];
    foreach (
        db()->query(
            "SELECT DISTINCT distribution_id FROM receipts
             WHERE group_id = 0 AND distribution_id IN ($in)"
        )->fetchAll(PDO::FETCH_COLUMN) as $d
    ) {
        $out[(int) $d] = true;
    }
    return $out;
}

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
        //    قرار محمد ١٩ سبتمبر ٢٠٢٦. نفس القاعدة في الملف المجمّع.
        $solo = receiving_solo_dist_ids($ids);
        if ($solo) {
            $ids = array_values(array_filter($ids, fn($x) => empty($solo[$x])));
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

/**
 * حارس النطاق — مين يقدر يعمل عملية على محضر استلام معيّن (بريف ١٥٢).
 *
 * النسخة اللي كانت مكتوبة في receiving.php **بالحرف**: نفس الرسالتين ونفس
 * الترتيب. الصفحة بترجّع الرسالة في JSON والـAPI بيحوّلها لـ403.
 *
 * @return string|null رسالة الرفض، أو null لو العملية مسموحة
 */
function receiving_scope_error(array $receipt, bool $canManageAll, ?int $userBranchId): ?string
{
    // مين مالوش فرع ومش أونر/مسؤول استلامات مايعملش أي حاجة.
    if (!$canManageAll && $userBranchId === null) {
        return 'حسابك مش مربوط بفرع — كلّم الأونر.';
    }
    // موظف الفرع ممنوع ينفذ أي عملية على فرع آخر
    if (!$canManageAll && $userBranchId !== null && (int) $receipt['branch_id'] !== $userBranchId) {
        return 'غير مصرح لك بتنفيذ عمليات على استلام فرع آخر.';
    }
    return null;
}

/**
 * قايمة الاستلامات + تجميع الكروت (بريف ١٥٢).
 *
 * الاستعلامات والاستبعادات دي كانت مكتوبة جوّه receiving.php — اتقلت هنا
 * **بالحرف** (نفس الجمل، نفس الترتيب، نفس الشروط) عشان الصفحة والـAPI
 * ياخدوا نفس الداتا من نفس المكان.
 *
 * @param bool     $canManageAll  أونر أو مشرف استلامات (يشوف كل الفروع)
 * @param int|null $userBranchId  فرع الموظف (null = مالوش فرع)
 * @return array{all_receipts: array, solo_group_name: array, headers_map: array}
 */
function receiving_list_data(PDO $pdo, bool $canManageAll, ?int $userBranchId): array
{
    // جلب كل سجلات الاستلام المتاحة
    $where = [];
    $params = [];
    if (!$canManageAll) {
        // مالوش فرع ومش أونر/مسؤول ← مايشوفش أي استلام خالص (فرع 0 مالوش سجلات)
        $where[] = 'r.branch_id = ?';
        $params[] = $userBranchId !== null ? $userBranchId : 0;
    }
    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stList = $pdo->prepare(
        "SELECT r.id AS receipt_id, r.group_id, r.distribution_id, r.branch_id, r.status, r.created_at,
                b.name AS branch_name,
                g.name AS group_name,
                d.invoice_no
         FROM receipts r
         JOIN branches b ON b.id = r.branch_id
         LEFT JOIN invoice_groups g ON g.id = r.group_id AND r.group_id > 0
         LEFT JOIN invoice_distribution d ON d.id = r.distribution_id AND r.distribution_id > 0
         $whereSql
         ORDER BY r.id DESC"
    );
    $stList->execute($params);
    $allReceipts = $stList->fetchAll();

    $out = [
        'all_receipts'    => $allReceipts,
        'solo_group_name' => [],
        'headers_map'     => [],
    ];
    if (empty($allReceipts)) {
        return $out;
    }

    // جلب معرفات المجموعات والفواتير المفردة لحساب الكميات الموزعة خارج الحلقات
    $gIds = [];
    $singleDistIds = [];
    foreach ($allReceipts as $rc) {
        $gid = (int) $rc['group_id'];
        $did = (int) $rc['distribution_id'];
        if ($gid > 0) {
            $gIds[$gid] = true;
        } elseif ($did > 0) {
            $singleDistIds[$did] = true;
        }
    }
    $gIds = array_keys($gIds);
    $singleDistIds = array_keys($singleDistIds);

    // اسم المجموعة للفواتير المفردة (لو كانت أصلاً من مجموعة) — للعرض بس
    $soloGroupName = [];   // [dist_id => group_name]
    if ($singleDistIds) {
        $sPh = implode(',', array_fill(0, count($singleDistIds), '?'));
        $stSG = $pdo->prepare(
            "SELECT d.id AS dist_id, MAX(g.name) AS gname
             FROM invoice_distribution d
             JOIN invoice_group_items gi ON gi.invoice_no = d.invoice_no AND " . dist_key_sql('d', 'gi.company_branch', 'gi.doc_type') . "
             JOIN invoice_groups g ON g.id = gi.group_id
             WHERE d.id IN ($sPh)
             GROUP BY d.id"
        );
        $stSG->execute($singleDistIds);
        foreach ($stSG->fetchAll() as $sg) {
            $soloGroupName[(int) $sg['dist_id']] = (string) $sg['gname'];
        }
    }

    // ربط المجموعات بسجلات التوزيع الخاصة بها باستعلام واحد مجمّع
    $groupDistsMap = []; // [group_id => [dist_id, ...]]
    $allDistIdsToQuery = $singleDistIds;
    if (!empty($gIds)) {
        $gPh = implode(',', array_fill(0, count($gIds), '?'));
        $stGDist = $pdo->prepare(
            "SELECT DISTINCT gi.group_id, d.id AS dist_id
             FROM invoice_group_items gi
             JOIN invoice_distribution d ON d.invoice_no = gi.invoice_no AND " . dist_key_sql('d', 'gi.company_branch', 'gi.doc_type') . "
             WHERE gi.group_id IN ($gPh)"
        );
        $stGDist->execute($gIds);
        foreach ($stGDist->fetchAll() as $gRow) {
            $gid = (int) $gRow['group_id'];
            $did = (int) $gRow['dist_id'];
            if ($did > 0) {
                $groupDistsMap[$gid][] = $did;
                $allDistIdsToQuery[] = $did;
            }
        }
    }

    $allDistIdsToQuery = array_values(array_unique($allDistIdsToQuery));

    // جلب إجمالي الكميات الموزعة لكل فرع باستعلام واحد مجمّع خارج أي حلقة
    $distBranchQty = [];
    if (!empty($allDistIdsToQuery)) {
        $dPh = implode(',', array_fill(0, count($allDistIdsToQuery), '?'));
        $stBranchQty = $pdo->prepare(
            "SELECT dl.distribution_id, dl.branch_id, SUM(dl.amount) AS total_qty
             FROM invoice_distribution_lines dl
             WHERE dl.distribution_id IN ($dPh) AND dl.amount > 0 AND dl.branch_id <> 6
             GROUP BY dl.distribution_id, dl.branch_id"
        );
        $stBranchQty->execute($allDistIdsToQuery);
        foreach ($stBranchQty->fetchAll() as $qRow) {
            $did = (int) $qRow['distribution_id'];
            $bid = (int) $qRow['branch_id'];
            $qty = (float) $qRow['total_qty'];
            $distBranchQty[$did][$bid] = ($distBranchQty[$did][$bid] ?? 0.0) + $qty;
        }
    }

    // تجميع السجلات حسب الهيدر مع إخفاء أي فرع ليس له كمية موزعة (> 0)
    $headersMap = [];
    foreach ($allReceipts as $rc) {
        $gid = (int) $rc['group_id'];
        $did = (int) $rc['distribution_id'];
        $bid = (int) $rc['branch_id'];

        // حساب الكمية الموزعة لهذا الفرع في هذا السياق
        $branchDistributedQty = 0.0;
        if ($gid > 0) {
            $contextDists = $groupDistsMap[$gid] ?? [];
            foreach ($contextDists as $cDid) {
                $branchDistributedQty += ($distBranchQty[$cDid][$bid] ?? 0.0);
            }
        } else {
            $branchDistributedQty = ($distBranchQty[$did][$bid] ?? 0.0);
        }

        // الشرط ٥: لو الفرع ليس له أي كميات موزعة، لا يظهر نهائياً في الكارت
        if ($branchDistributedQty <= 0.0001) {
            continue;
        }

        $key = $gid > 0 ? ('g_' . $gid) : ('d_' . $did);
        if (!isset($headersMap[$key])) {
            $title = $gid > 0
                ? ($rc['group_name'] ? ('مجموعة فواتير #' . $gid . ' — ' . $rc['group_name']) : ('مجموعة فواتير #' . $gid))
                : ('فاتورة #' . ($rc['invoice_no'] ?: $did));
            $headersMap[$key] = [
                'group_id'        => $gid,
                'distribution_id' => $did,
                'title'           => $title,
                'group_note'      => ($gid === 0 && !empty($soloGroupName[$did])) ? $soloGroupName[$did] : '',
                'created_at'      => $rc['created_at'],
                'branches'        => [],
            ];
        }
        $headersMap[$key]['branches'][] = $rc;
    }

    $out['solo_group_name'] = $soloGroupName;
    $out['headers_map']     = $headersMap;
    return $out;
}

/**
 * داتا شاشة تفاصيل محضر استلام (بريف ١٥٢).
 *
 * نفس اللي receiving.php بتحسبه قبل ما ترسم: الأصناف · الحالة · هل نعرض
 * الفروقات · اسم اللي رفض المحضر · آخر الأصناف الممسوحة.
 *
 * @param array $receipt المحضر زي ما receipt_get() رجّعته
 * @param bool  $withDiff مكافئ `?diff=1` في الصفحة
 */
function receiving_detail_data(PDO $pdo, array $receipt, bool $withDiff = false): array
{
    $receiptId = (int) $receipt['id'];
    $items     = receipt_items_for_branch($pdo, $receiptId);
    $status    = (string) $receipt['status'];
    $isGroup   = (int) $receipt['group_id'] > 0;
    $showDiffView = ($status === 'received' || $status === 'approved' || $withDiff);

    $rejecterName = null;
    if (!empty($receipt['reject_reason']) && $status === 'receiving') {
        $rejecterName = 'الإدارة';
        if (!empty($receipt['rejected_by'])) {
            $stRej = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
            $stRej->execute([(int) $receipt['rejected_by']]);
            $rejecterName = $stRej->fetchColumn() ?: 'الإدارة';
        }
    }

    return [
        'receipt'       => $receipt,
        'items'         => $items,
        'status'        => $status,
        'is_group'      => $isGroup,
        'show_diff'     => $showDiffView,
        'rejecter_name' => $rejecterName,
        'last_scanned'  => ($status === 'receiving' && !$showDiffView)
            ? receipt_last_scanned($pdo, $receiptId, 20)
            : [],
    ];
}

/**
 * بدء الاستلام (كان `action=start` جوّه receiving.php — بريف ١٥٢).
 */
function receipt_start(PDO $pdo, array $receipt, int $uid): void
{
    if ($receipt['status'] !== 'sent') {
        throw new RuntimeException('الاستلام قد بدأ بالفعل أو مكتمل.');
    }
    $st = $pdo->prepare("UPDATE receipts SET status='receiving', started_at=NOW(), started_by=? WHERE id=? AND status='sent'");
    $st->execute([$uid, (int) $receipt['id']]);
}

/**
 * استعلام فوري عن بيانات الصنف قبل الإضافة (كان `action=lookup` — بريف ١٥٢).
 */
function receipt_lookup(PDO $pdo, array $receipt, string $code): array
{
    if ($receipt['status'] !== 'receiving') {
        throw new RuntimeException('لا يمكن البحث إلا في حالة جاري الاستلام.');
    }
    $res = receipt_resolve_scanned_code($pdo, (int) $receipt['id'], trim($code));
    if (empty($res['ok'])) {
        throw new RuntimeException((string) ($res['error'] ?? 'الصنف ده مش في الفاتورة'));
    }
    // لا نرسل كمية الموزع لشاشة المسح للحفاظ على مبدأ الاستلام الأعمى
    unset($res['distributed_qty'], $res['ok']);
    return $res;
}

/**
 * مسح باركود وزيادة الكمية (كان `action=scan` — بريف ١٥٢).
 *
 * 🔴 الزيادة تراكمية: `scanned_qty = scanned_qty + ?` — مش استبدال.
 *    الاستبدال في receipt_set_qty() بس.
 */
function receipt_scan(PDO $pdo, array $receipt, string $code, float $qty, int $uid): array
{
    $receiptId = (int) $receipt['id'];
    if ($receipt['status'] !== 'receiving') {
        throw new RuntimeException('لا يمكن المسح إلا في حالة جاري الاستلام.');
    }

    $code = trim($code);
    if ($qty <= 0) $qty = 1.0;   // نفس اللي الصفحة كانت بتعمله بالحرف

    if ($code === '') {
        throw new RuntimeException('كود الصنف مطلوب.');
    }

    // مطابقة الكود والتحقق من الوحدة والباركود البديل
    $res = receipt_resolve_scanned_code($pdo, $receiptId, $code);
    if (!$res['ok']) {
        throw new RuntimeException((string) ($res['error'] ?? 'الصنف ده مش في الفاتورة'));
    }

    $targetCode = (string) $res['item_code'];
    $targetName = (string) $res['item_name'];

    $ins = $pdo->prepare(
        'INSERT INTO receipt_lines (receipt_id, item_code, item_name, scanned_qty, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             scanned_qty = scanned_qty + VALUES(scanned_qty),
             updated_by  = VALUES(updated_by)'
    );
    $ins->execute([$receiptId, $targetCode, $targetName, $qty, $uid]);

    $stScanned = $pdo->prepare('SELECT scanned_qty FROM receipt_lines WHERE receipt_id=? AND item_code=?');
    $stScanned->execute([$receiptId, $targetCode]);
    $newQty = (float) $stScanned->fetchColumn();

    return [
        'item_code'    => $targetCode,
        'scanned_code' => $code,
        'item_name'    => $targetName,
        'scanned_qty'  => $newQty,
        'added_qty'    => $qty,
    ];
}

/**
 * تعديل الكمية المستلمة لصنف مباشرة (كان `action=update_qty` — بريف ١٥٢).
 *
 * 🔴 ده بيحط الكمية (`scanned_qty = ?`) — مش بيزوّد زي receipt_scan().
 */
function receipt_set_qty(PDO $pdo, array $receipt, string $code, float $qty, int $uid): array
{
    $receiptId = (int) $receipt['id'];
    if ($receipt['status'] === 'approved') {
        throw new RuntimeException('لا يمكن التعديل بعد اعتماد الاستلام النهائي.');
    }

    $code = trim($code);
    if ($qty < 0) $qty = 0;

    if ($code === '') {
        throw new RuntimeException('كود الصنف مطلوب.');
    }

    // جلب أصناف الفاتورة للفرع للتحقق وجلب الاسم الصحيح
    $items = receipt_items_for_branch($pdo, $receiptId);
    $foundItem = null;
    foreach ($items as $it) {
        if ((string) $it['item_code'] === $code) {
            $foundItem = $it;
            break;
        }
    }
    if (!$foundItem) {
        throw new RuntimeException('الصنف غير موجود في فاتورة هذا الفرع.');
    }

    $ins = $pdo->prepare(
        'INSERT INTO receipt_lines (receipt_id, item_code, item_name, scanned_qty, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             scanned_qty = VALUES(scanned_qty),
             updated_by  = VALUES(updated_by)'
    );
    $ins->execute([$receiptId, $code, $foundItem['item_name'], $qty, $uid]);

    return [
        'item_code'   => $code,
        'scanned_qty' => $qty,
    ];
}

/**
 * إنهاء مراجعة الاستلام (كان `action=review` جوّه receiving.php — بريف ١٥٥).
 *
 * receiving ← received + إشعار للأونر ولمسؤول الاستلامات.
 * 🔴 `notify()` بيبعت إشعار موبايل حقيقي — على Laragon امسح صفوف الطابور
 *    اللي بتتكتب في الاختبار، و`push_worker.php` ممنوع يتشغّل.
 */
function receipt_review(PDO $pdo, array $receipt, int $uid, int $supervisorId): void
{
    $receiptId = (int) $receipt['id'];
    if ($receipt['status'] !== 'receiving') {
        throw new RuntimeException('لا يمكن إنهاء المراجعة في هذه الحالة.');
    }
    $st = $pdo->prepare("UPDATE receipts SET status='received', received_at=NOW(), received_by=? WHERE id=? AND status='receiving'");
    $st->execute([$uid, $receiptId]);

    // إشعار للأونر ولمسؤول الاستلامات
    $notifUsers = [];
    $stOwner = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1');
    foreach ($stOwner->fetchAll() as $ow) {
        $notifUsers[] = (int) $ow['id'];
    }
    if ($supervisorId > 0 && !in_array($supervisorId, $notifUsers, true)) {
        $notifUsers[] = $supervisorId;
    }
    if (!empty($notifUsers)) {
        notify(
            $pdo,
            $notifUsers,
            'receiving',
            'تمت مراجعة استلام بضاعة فرع ' . $receipt['branch_name'],
            'قام الفرع بمراجعة الاستلام والمحضر في انتظار الاعتماد النهائي.',
            'receiving.php?r=' . $receiptId
        );
    }

    try {
        require_once __DIR__ . '/invoice_problems_lib.php';
        if (!empty($receipt['distribution_id'])) {
            sync_invoice_problems_for_dist($pdo, (int) $receipt['distribution_id']);
        }
    } catch (Throwable $e) {
        error_log('sync_invoice_problems_for_dist failed: ' . $e->getMessage());
    }
}

/**
 * رفض الاستلام وإعادة فتحه للفرع (كان `action=reject_review` — بريف ١٥٥).
 *
 * received ← receiving + سبب الرفض + إشعار لموظفي الفرع واللي استلم.
 * صلاحية `$canManageAll` بتتحرس بره الدالة زي الصفحة (الصفحة بترجّع JSON
 * والـAPI بيرجّع 403 — مش 422).
 */
function receipt_reject_review(PDO $pdo, array $receipt, int $uid, string $reason, string $senderName): void
{
    $receiptId = (int) $receipt['id'];
    if ($receipt['status'] !== 'received') {
        throw new RuntimeException('لا يمكن رفض الاستلام إلا في حالة بانتظار الاعتماد (تم الاستلام).');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('يرجى كتابة سبب رفض الاستلام للتوضيح للفرع.');
    }

    $st = $pdo->prepare("UPDATE receipts SET status='receiving', reject_reason=?, rejected_at=NOW(), rejected_by=? WHERE id=? AND status='received'");
    $st->execute([$reason, $uid, $receiptId]);

    // إشعار لمستخدمي الفرع والقائم بالاستلام
    $notifUsers = [];
    $bId = (int) $receipt['branch_id'];
    $stBranchUsers = $pdo->prepare('SELECT id FROM users WHERE branch_id = ? AND is_active = 1');
    $stBranchUsers->execute([$bId]);
    foreach ($stBranchUsers->fetchAll() as $bu) {
        $notifUsers[] = (int) $bu['id'];
    }
    if (!empty($receipt['received_by']) && !in_array((int) $receipt['received_by'], $notifUsers, true)) {
        $notifUsers[] = (int) $receipt['received_by'];
    }

    if (!empty($notifUsers)) {
        notify(
            $pdo,
            $notifUsers,
            'receiving',
            'تم رفض استلام بضاعة فرع ' . $receipt['branch_name'],
            'تم رفض الاستلام بواسطة ' . $senderName . ' — السبب: ' . $reason,
            'receiving.php?r=' . $receiptId
        );
    }
}

/**
 * اعتماد الاستلام نهائياً (كان `action=approve` — بريف ١٥٥).
 *
 * received ← approved. صلاحية الأونر بس بتتحرس بره الدالة.
 */
function receipt_approve(PDO $pdo, array $receipt, int $uid): void
{
    if ($receipt['status'] !== 'received') {
        throw new RuntimeException('يجب أن يكون الاستلام مراجعاً (تم الاستلام) قبل الاعتماد.');
    }
    $st = $pdo->prepare("UPDATE receipts SET status='approved', approved_at=NOW(), approved_by=? WHERE id=? AND status='received'");
    $st->execute([$uid, (int) $receipt['id']]);

    try {
        require_once __DIR__ . '/invoice_problems_lib.php';
        if (!empty($receipt['distribution_id'])) {
            sync_invoice_problems_for_dist($pdo, (int) $receipt['distribution_id']);
        }
    } catch (Throwable $e) {
        error_log('sync_invoice_problems_for_dist failed: ' . $e->getMessage());
    }
}

/**
 * حذف محضر استلام فرع واحد + سطوره وإشعاراته (كان `action=delete_receipt` — بريف ١٥٥).
 *
 * 🔴 نفس ترتيب الصفحة بالحرف: notifications ← receipt_lines ← receipts،
 *    ومن غير transaction — الصفحة مش عاملاه (بريف ١٥٥ بند الحذف).
 */
function receipt_delete(PDO $pdo, int $receiptId): void
{
    $pdo->prepare("DELETE FROM notifications WHERE type = 'receiving' AND link = ?")->execute(['receiving.php?r=' . $receiptId]);
    $pdo->prepare('DELETE FROM receipt_lines WHERE receipt_id = ?')->execute([$receiptId]);
    $pdo->prepare('DELETE FROM receipts WHERE id = ?')->execute([$receiptId]);
}

/**
 * حذف كل محاضر فاتورة/مجموعة من الاستلامات (كان `action=delete_invoice_receipts` — بريف ١٥٥).
 *
 * 🔴 ترتيب الصفحة بالحرف هنا مختلف عن الحذف المفرد: receipt_lines ← receipts
 *    ← notifications (بعد ما بيتبني رابط كل محضر). ومن غير transaction كمان.
 *
 * @param int $groupId معرّف المجموعة (0 = مش مستخدم)
 * @param int $distId  معرّف التوزيع (بيتقرا بس لو group_id = 0)
 */
function receipt_delete_by_invoice(PDO $pdo, int $groupId, int $distId): void
{
    if ($groupId > 0) {
        $stIds = $pdo->prepare('SELECT id FROM receipts WHERE group_id = ?');
        $stIds->execute([$groupId]);
    } else {
        $stIds = $pdo->prepare('SELECT id FROM receipts WHERE distribution_id = ?');
        $stIds->execute([$distId]);
    }
    $rcIds = $stIds->fetchAll(PDO::FETCH_COLUMN);
    if (empty($rcIds)) {
        return;
    }

    $ph = implode(',', array_fill(0, count($rcIds), '?'));
    $pdo->prepare("DELETE FROM receipt_lines WHERE receipt_id IN ($ph)")->execute($rcIds);
    $pdo->prepare("DELETE FROM receipts WHERE id IN ($ph)")->execute($rcIds);
    $links = array_map(fn($id) => 'receiving.php?r=' . $id, $rcIds);
    $lph = implode(',', array_fill(0, count($links), '?'));
    $pdo->prepare("DELETE FROM notifications WHERE type = 'receiving' AND link IN ($lph)")->execute($links);
}

/**
 * داتا صفحة «فروقات الاستلام بين الفروع» (بريف ١٢٦).
 *
 * 🔴 استعلامات receiving_diffs.php اتنقلت هنا **بالحرف** — من غير أي تصليح —
 *    عشان receiving_diffs_api.php ينادي نفس الدالة ويرجّع نفس الشكل،
 *    والـHTML في الصفحة يفضل زي ما هو بيقرا نفس أسماء المفاتيح دي.
 *
 * @param array $ctx     سياق الصلاحية زي ما الصفحة بتحسبه:
 *                       uid · user_branch_id · is_owner · is_supervisor · can_view
 * @param array $filters الفلاتر من الرابط: group_id (g) · distribution_id (d)
 * @return array {
 *     group_id, distribution_id, is_group, title,
 *     available_list, branches, receipts_by_branch, items
 * }
 */
function receiving_diffs_data(array $ctx, array $filters): array
{
    $groupId = (int) ($filters['group_id'] ?? 0);
    $distId  = (int) ($filters['distribution_id'] ?? 0);

    $out = [
        'group_id'           => $groupId,
        'distribution_id'    => $distId,
        'is_group'           => false,
        'title'              => '',
        'available_list'     => [],
        'branches'           => [],
        'receipts_by_branch' => [],
        'items'              => [],
    ];

    // نفس شرط الصلاحية اللي الصفحة بتفحصه قبل أي استعلام — الصفحة والـapi
    // بيحسبوا $ctx بنفس الطريقة وبيعدّوه هنا، فالدالة ماتستنتجش حاجة من الجلسة.
    if (empty($ctx['can_view'])) {
        return $out;
    }

    $pdo = db();

    // موظف الفرع بيشوف فرعه بس (قرار محمد ١٩/١٠/٢٠٢٦ — قبلها كان بيشوف الخمس فروع).
    // الكل: المالك · مشرف الاستلام · صلاحية receiving:diffs. null = كل الفروع.
    // ومين مالوش فرع أو فرعه الإدارة (6) بيشوف الكل برضه.
    $ub     = (int) ($ctx['user_branch_id'] ?? 0);
    $seeAll = !empty($ctx['is_owner']) || !empty($ctx['is_supervisor']) || !empty($ctx['can_diffs'])
              || $ub <= 0 || $ub === 6;
    $scope  = $seeAll ? null : $ub;

    // الحالة الأولى: مفيش فاتورة ولا مجموعة مختارة ← قايمة اللي ليه استلام مفتوح
    if ($groupId <= 0 && $distId <= 0) {
        $stAvailable = $pdo->prepare(
            "SELECT r.group_id, r.distribution_id,
                    MAX(r.created_at) AS last_date,
                    g.name AS group_name,
                    d.invoice_no,
                    COUNT(DISTINCT r.branch_id) AS branches_count
             FROM receipts r
             LEFT JOIN invoice_groups g ON g.id = r.group_id AND r.group_id > 0
             LEFT JOIN invoice_distribution d ON d.id = r.distribution_id AND r.distribution_id > 0"
            . ($scope !== null ? " WHERE r.branch_id = ?" : "") . "
             GROUP BY r.group_id, r.distribution_id
             ORDER BY last_date DESC"
        );
        $stAvailable->execute($scope !== null ? [$scope] : []);
        $out['available_list'] = $stAvailable->fetchAll();
        return $out;
    }

    // الحالة التانية: فيه سياق مختار ← فروقات الاستلام

    // جلب سياق المجموعة أو الفاتورة
    $isGrp = $groupId > 0;
    $out['is_group'] = $isGrp;

    $title = '';
    if ($isGrp) {
        $stG = $pdo->prepare('SELECT name FROM invoice_groups WHERE id = ?');
        $stG->execute([$groupId]);
        $gName = $stG->fetchColumn();
        $title = 'مجموعة فواتير #' . $groupId . ($gName ? ' — ' . $gName : '');
    } else {
        $stD = $pdo->prepare('SELECT invoice_no FROM invoice_distribution WHERE id = ?');
        $stD->execute([$distId]);
        $invNo = $stD->fetchColumn();
        $title = 'فاتورة مفردة #' . ($invNo ?: $distId);
    }
    $out['title'] = $title;

    // جلب الفروع الخمسة (باستثناء الإدارة 6)
    $stBr = $pdo->prepare('SELECT id, name FROM branches WHERE id <> 6 AND is_active = 1'
        . ($scope !== null ? ' AND id = ?' : '') . ' ORDER BY id');
    $stBr->execute($scope !== null ? [$scope] : []);
    $branches = $stBr->fetchAll();
    $out['branches'] = $branches;

    // جلب سجلات receipts المفتوحة لهذا السياق
    $stReceipts = $pdo->prepare(
        'SELECT id, branch_id, status FROM receipts
         WHERE ((group_id = ? AND distribution_id = 0) OR (group_id = 0 AND distribution_id = ?))'
        . ($scope !== null ? ' AND branch_id = ?' : '')
    );
    $stReceipts->execute($scope !== null ? [$groupId, $distId, $scope] : [$groupId, $distId]);
    $receiptsByBranch = [];
    $receiptIds = [];
    foreach ($stReceipts->fetchAll() as $rc) {
        $b = (int) $rc['branch_id'];
        $receiptsByBranch[$b] = $rc;
        $receiptIds[] = (int) $rc['id'];
    }
    $out['receipts_by_branch'] = $receiptsByBranch;

    // جلب معرفات التوزيع
    $distIds = receiving_ctx_dist_ids($distId, $groupId);
    $distIdsClean = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));

    $itemsMap = [];
    $codes = [];

    if (!empty($distIdsClean)) {
        $inClause = implode(',', $distIdsClean);

        // جلب الكميات الموزعة لكل صنف وكل فرع
        $stDistLines = $pdo->query(
            "SELECT dl.notes AS item_code, dl.branch_id, SUM(dl.amount) AS distributed_qty
             FROM invoice_distribution_lines dl
             WHERE dl.distribution_id IN ($inClause) AND dl.amount > 0 AND dl.branch_id <> 6" . ($scope !== null ? ' AND dl.branch_id = ' . $scope : '') . "
             GROUP BY dl.notes, dl.branch_id"
        );
        $distRows = $stDistLines ? $stDistLines->fetchAll() : [];

        foreach ($distRows as $dr) {
            $c   = trim((string)$dr['item_code']);
            $bid = (int)$dr['branch_id'];
            $qty = (float)$dr['distributed_qty'];
            if ($c === '') continue;

            $codes[] = $c;
            if (!isset($itemsMap[$c])) {
                $itemsMap[$c] = [
                    'item_code'   => $c,
                    'item_name'   => $c,
                    'by_branch'   => [],
                ];
            }
            $itemsMap[$c]['by_branch'][$bid]['dist'] = $qty;
        }

        // جلب الكميات الممسوحة لكل صنف وكل فرع
        if (!empty($receiptIds)) {
            $rcIn = implode(',', array_unique($receiptIds));
            $stScanned = $pdo->query(
                "SELECT r.branch_id, rl.item_code, SUM(rl.scanned_qty) AS scanned_qty
                 FROM receipt_lines rl
                 JOIN receipts r ON r.id = rl.receipt_id
                 WHERE r.id IN ($rcIn)
                 GROUP BY r.branch_id, rl.item_code"
            );
            $scannedRows = $stScanned ? $stScanned->fetchAll() : [];

            foreach ($scannedRows as $sr) {
                $c   = trim((string)$sr['item_code']);
                $bid = (int)$sr['branch_id'];
                $sq  = (float)$sr['scanned_qty'];
                if (isset($itemsMap[$c])) {
                    $itemsMap[$c]['by_branch'][$bid]['scan'] = $sq;
                }
            }
        }

        // جلب أسماء الأصناف باستعلام واحد مجمّع
        if (!empty($codes)) {
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
                $c = (string)$nr['item_code'];
                if (isset($itemsMap[$c])) {
                    $itemsMap[$c]['item_name'] = (string)($nr['item_name'] ?: $c);
                }
            }
        }
    }

    uasort($itemsMap, fn($a, $b) => strcmp($a['item_name'], $b['item_name']));
    $out['items'] = array_values($itemsMap);

    return $out;
}

