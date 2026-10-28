<?php
/**
 * stock_query_lib.php — منطق استعلام أرصدة الأصناف
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  محلي بالكامل. كل البيانات اللي الشاشة دي محتاجاها موجودة في المرآة:
 *      hq_codes     → الكود + item_id + الاسم + نوع الكود
 *      hq_balances  → الرصيد لكل صنف في كل مخزن
 *      hq_stores    → أسماء المخازن
 *      branches     → الفروع المعرّفة عندنا
 *
 *  الجسر بيتستخدم كاحتياطي فقط لو المرآة لسه فاضية (تركيب جديد أو
 *  قبل أول مزامنة). طالما hq_codes/hq_balances فيهم داتا، الصفحة
 *  مابتلمسش الجسر خالص — يعني بتفتح وجهاز الإدارة مقفول.
 *
 *  الفلترة بتحصل في SQL نفسه (مش في PHP) عشان مانجيبش كل الأصناف
 *  الأول. قراءة بس — مفيش كتابة.
 * =====================================================================
 */
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/agent_lib.php';   // bal_table() — مصدر الأرصدة
require_once __DIR__ . '/stocktake_lib.php';   // البحث بالاسم الاحتياطي (stk_search_by_name)

/**
 * مرآة الأكواد فيها داتا؟ (عشان نعرف نرجع للجسر ولا لأ)
 */
function hq_codes_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool) db()->query('SELECT 1 FROM hq_codes LIMIT 1')->fetchColumn();
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/**
 * المرآة كلها جاهزة (أكواد + أرصدة)؟ الشرط اللي بنعتمد عليه
 * قبل ما نقرر إننا مش محتاجين الجسر.
 */
function stock_local_ready(): bool
{
    return hq_codes_ready() && hq_balances_ready();
}

/**
 * وضع «المرآة بس» — يمنع أي نداء على الجسر في الطلب ده.
 *
 * ليه: الجسر واقف (شوف RULES.md قسم ٥)، وكل نداء عليه بيستنى الاتصال
 * يفشل الأول. طلب النواقص بيبحث بالباركود عشرات المرات في الجلسة،
 * فبقى بطيء لدرجة إنه «مش راضي يشتغل».
 *
 * الشاشات اللي بتشغّله بتقبل إن الصنف اللي مش في المرآة يرجع فاضي —
 * أحسن من إنها تستنى جسر واقف.
 */
function stock_mirror_only(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) $on = $set;
    return $on;
}

/**
 * خريطة الفروع: sto_id → اسم الفرع. الإدارة أولاً ثم الفروع بالاسم.
 * @return array<int,array{sto_id:int,name:string,is_admin:bool}>
 */
function stock_branches_map(): array
{
    $rows = db()->query(
        'SELECT sto_id, name, in_receiving
         FROM branches
         WHERE is_active = 1 AND sto_id IS NOT NULL
         ORDER BY (in_receiving = 0) DESC, name'
    )->fetchAll();

    $out  = [];
    $seen = [];
    foreach ($rows as $r) {
        $sid = (int) $r['sto_id'];
        $seen[$sid] = true;
        $out[] = [
            'sto_id'   => $sid,
            'name'     => (string) $r['name'],
            'is_admin' => (int) $r['in_receiving'] === 0,
        ];
    }

    // ضمان وجود مخزن الإدارة حتى لو مش معرّف في جدول الفروع —
    // من غيره الأصناف اللي رصيدها في الإدارة بس بتبان أصفار.
    if (!isset($seen[(int) ADMIN_STO_ID])) {
        array_unshift($out, [
            'sto_id'   => (int) ADMIN_STO_ID,
            'name'     => 'الإدارة',
            'is_admin' => true,
        ]);
        $seen[(int) ADMIN_STO_ID] = true;
    }

    return $out;
}

/**
 * أي مخازن ليها رصيد ومش معرّفة عندنا — بترجّع أعمدة إضافية
 * عشان الرصيد مايختفيش. الاسم بيجي من hq_stores لو موجود،
 * وإلا "مخزن #رقم".
 * @return array<int,array{sto_id:int,name:string,is_admin:bool}>
 */
function stock_extra_stores(array $known): array
{
    // ١) المحلي — المخازن اللي ليها رصيد فعلي في hq_balances
    if (stock_local_ready()) {
        try {
            $rows = db()->query(
                // ⚠️ النص ده كان بعلامات **مفردة**، فـ" . bal_table() . "
                //    كان بينزل في الـSQL كنص حرفي بدل اسم الجدول —
                //    الاستعلام كان بيرمي استثناء كل مرة والـcatch تحت
                //    بيبلعه في صمت، فالمخازن الزيادة عمرها ما ظهرت.
                "SELECT b.sto_id, s.name
                 FROM " . bal_table() . " b
                 LEFT JOIN hq_stores s ON s.sto_id = b.sto_id
                 GROUP BY b.sto_id, s.name
                 HAVING SUM(b.balance) > 0.0001
                 ORDER BY b.sto_id"
            )->fetchAll();

            $out = [];
            foreach ($rows as $r) {
                $sid = (int) $r['sto_id'];
                if (in_array($sid, $known, true)) continue;
                $nm  = trim((string) ($r['name'] ?? ''));
                $out[] = [
                    'sto_id'   => $sid,
                    'name'     => $nm !== '' ? $nm : 'مخزن #' . $sid,
                    'is_admin' => false,
                ];
            }
            return $out;
        } catch (Throwable $ex) {
            // نكمّل على الجسر
        }
    }

    // 🔴 وضع المرآة بس: مفيش رجوع للجسر.
    if (stock_mirror_only()) return [];

    // ٢) الجسر (احتياطي)
    try {
        $sql = "SELECT STO_ID FROM " . SQLSRV_PREFIX . "COM_STO_ITEMS
                GROUP BY STO_ID HAVING SUM(CSI_CURRENT_BALANCE) > 0.0001
                ORDER BY STO_ID";
        $rows = sqlsrv_query($sql, []);
    } catch (Throwable $ex) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $sid = (int) $r['STO_ID'];
        if (in_array($sid, $known, true)) continue;
        $out[] = ['sto_id' => $sid, 'name' => 'مخزن #' . $sid, 'is_admin' => false];
    }
    return $out;
}

/** صف جاهز للعرض من item_id + أرصدة + اسم */
function stock_build_row(int $itemId, array $byStore, string $code, string $name): array
{
    $total = 0.0;
    foreach ($byStore as $q) $total += (float) $q;
    return [
        'code'     => $code,
        'name'     => $name,
        'item_id'  => $itemId,
        'balances' => $byStore,
        'total'    => $total,
    ];
}

/**
 * صنف واحد بالكود (للمسح المتتابع / الإضافة اليدوية).
 */
function stock_item_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;

    // ١) المحلي
    if (hq_codes_ready()) {
        try {
            $st = db()->prepare(
                'SELECT code, item_id, name FROM hq_codes WHERE code = ? LIMIT 1'
            );
            $st->execute([$code]);
            $r = $st->fetch();
            if ($r) {
                $iid = (int) $r['item_id'];
                if (stock_mirror_only()) {
                    // وضع المرآة: قراءة الأرصدة من الجدول المحلي مباشرة
                    $stBal = db()->prepare(
                        "SELECT sto_id, balance FROM " . bal_table() . " WHERE item_id = ?"
                    );
                    $stBal->execute([$iid]);
                    $byStore = [];
                    foreach ($stBal->fetchAll() as $bRow) {
                        $byStore[(int) $bRow['sto_id']] = (float) $bRow['balance'];
                    }
                    return stock_build_row(
                        $iid,
                        $byStore,
                        (string) $r['code'],
                        (string) ($r['name'] ?? '')
                    );
                }
                $bal = sqlsrv_fetch_item_balances_all([$iid]);
                return stock_build_row(
                    $iid,
                    $bal[$iid] ?? [],
                    (string) $r['code'],
                    (string) ($r['name'] ?? '')
                );
            }
            // المرآة جاهزة والكود مش موجود → مش موجود فعلاً
            return null;
        } catch (Throwable $ex) {
            // نكمّل على الجسر
        }
    }

    // 🔴 وضع المرآة بس: مفيش رجوع للجسر.
    if (stock_mirror_only()) return null;

    // ٢) الجسر (احتياطي)
    $sql = "SELECT TOP 1 LTRIM(RTRIM(c." . STK_IC_CODE . ")) AS code,
                   c." . STK_ITEM_ID . " AS item_id,
                   LTRIM(RTRIM(c." . STK_NAME . ")) AS name
            FROM " . STK_CODES_TABLE . " c
            WHERE c." . STK_IC_CODE . " = ?";
    try {
        $rows = sqlsrv_query($sql, [$code]);
    } catch (Throwable $ex) {
        error_log('stock_query: فشل البحث بالكود — ' . $ex->getMessage());
        return null;
    }
    if (!$rows) return null;

    $r   = $rows[0];
    $iid = (int) $r['item_id'];
    $bal = sqlsrv_fetch_item_balances_all([$iid]);

    return stock_build_row(
        $iid,
        $bal[$iid] ?? [],
        (string) $r['code'],
        sqlsrv_fix_encoding((string) ($r['name'] ?? ''))
    );
}

/**
 * بحث بالاسم + أرصدة.
 */
function stock_search_by_name(string $term, int $limit = 50): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) return [];

    // ١) المحلي — بحث متعدد الكلمات في hq_codes:
    //    "كشكول ٨٠" بيلاقي "كشكول سلك ٨٠ ورقة" — كل كلمة بتتدور
    //    لوحدها (AND) بدل ما الجملة كلها لازم تكون ورا بعض.
    if (stock_local_ready()) {
        try {
            $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
            $where  = [];
            $params = [];
            foreach ($words as $w) {
                $where[]  = 'name LIKE ?';
                $params[] = '%' . $w . '%';
            }
            $st = db()->prepare(
                'SELECT code, item_id, name FROM hq_codes
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY code_type ASC, name
                 LIMIT ' . (int) $limit
            );
            $st->execute($params);
            $rows = $st->fetchAll();

            $out = [];
            $seenItem = [];
            foreach ($rows as $r) {
                $iid = (int) $r['item_id'];
                if ($iid <= 0 || isset($seenItem[$iid])) continue;   // صنف واحد مرة واحدة
                $seenItem[$iid] = true;
                $out[$iid] = [
                    'code' => (string) $r['code'],
                    'name' => (string) ($r['name'] ?? ''),
                ];
            }
            if (!$out) return [];

            $balances = sqlsrv_fetch_item_balances_all(array_keys($out));

            $res = [];
            foreach ($out as $iid => $info) {
                $res[] = stock_build_row($iid, $balances[$iid] ?? [], $info['code'], $info['name']);
            }
            return $res;
        } catch (Throwable $ex) {
            // نكمّل على الجسر
        }
    }

    // 🔴 وضع المرآة بس: مفيش رجوع للجسر.
    if (stock_mirror_only()) return [];

    // ٢) الجسر (احتياطي)
    $found = stk_search_by_name($term, $limit);
    if (!$found) return [];

    $codes = array_column($found, 'code');
    $ids   = stock_codes_to_ids($codes);
    if (!$ids) return [];

    $balances = sqlsrv_fetch_item_balances_all(array_values($ids));

    $out = [];
    foreach ($found as $f) {
        $code = (string) $f['code'];
        $iid  = $ids[$code] ?? 0;
        if (!$iid) continue;
        $out[] = stock_build_row($iid, $balances[$iid] ?? [], $code, (string) $f['name']);
    }
    return $out;
}

/** ترجمة أكواد → item_id  @return array<string,int> */
function stock_codes_to_ids(array $codes): array
{
    $codes = array_values(array_filter(array_map('trim', $codes), fn($c) => $c !== ''));
    if (!$codes) return [];

    // ١) المحلي
    if (hq_codes_ready()) {
        try {
            $out = [];
            foreach (array_chunk($codes, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = db()->prepare(
                    "SELECT code, item_id FROM hq_codes WHERE code IN ($ph)"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() as $r) {
                    $c = (string) $r['code'];
                    if (!isset($out[$c])) $out[$c] = (int) $r['item_id'];
                }
            }
            return $out;
        } catch (Throwable $ex) {
            // نكمّل على الجسر
        }
    }

    // ٢) الجسر (احتياطي)
    $out = [];
    foreach (array_chunk($codes, 300) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT LTRIM(RTRIM(" . STK_IC_CODE . ")) AS code, " . STK_ITEM_ID . " AS item_id
                FROM " . STK_CODES_TABLE . "
                WHERE " . STK_IC_CODE . " IN ($ph)";
        try {
            foreach (sqlsrv_query($sql, $chunk) as $r) {
                $c = (string) $r['code'];
                if (!isset($out[$c])) $out[$c] = (int) $r['item_id'];
            }
        } catch (Throwable $ex) {
            error_log('stock_query: فشل ترجمة الأكواد — ' . $ex->getMessage());
        }
    }
    return $out;
}

/**
 * مجموعة أكواد جاهزة للتصدير من غير استعلام لكل كود.
 * @return array<int,array{code:string,name:string,item_id:int,balances:array,total:float}>
 */
function stock_items_by_codes(array $codes): array
{
    $codes = array_values(array_unique(array_filter(
        array_map(fn($code) => trim((string) $code), $codes),
        fn($code) => $code !== ''
    )));
    if (!$codes) return [];

    $idsByCode = stock_codes_to_ids($codes);
    $itemIds = array_values(array_unique(array_filter(array_values($idsByCode))));
    if (!$itemIds) return [];

    $balances = sqlsrv_fetch_item_balances_all($itemIds);
    $names = stock_item_names($itemIds);
    $rows = [];
    foreach ($codes as $code) {
        $itemId = (int) ($idsByCode[$code] ?? 0);
        if ($itemId <= 0) continue;
        $info = $names[$itemId] ?? ['name' => '—'];
        $rows[] = stock_build_row(
            $itemId,
            $balances[$itemId] ?? [],
            $code,
            (string) ($info['name'] ?? '—')
        );
    }
    return $rows;
}

/**
 * الفلترة على مستوى SQL — بترجّع الأصناف المطابقة لشرط الرصيد
 * من غير ما نجيب كل الأصناف الأول.
 *
 * $mode: admin_only | branch_only | has_stock | zero | below
 *
 * ملاحظة: hq_balances فيها الأرصدة غير الصفرية بس (الوكيل بيفلترها).
 * عشان كده فلاتر "zero" و"below" بتبدأ من hq_codes بـ LEFT JOIN،
 * وباقي الفلاتر (اللي بتدوّر على رصيد موجب) بتشتغل على hq_balances مباشرة.
 */
function stock_filter_items(string $mode, int $stoId = 0, float $threshold = 0, int $limit = 300): array
{
    $adminSto = (int) ADMIN_STO_ID;

    // =============== ١) المحلي ===============
    if (stock_local_ready()) {
        // ⚠️ مهم: الوكيل بيبعت الأرصدة غير الصفرية بس
        //    (agent_hq.ps1: WHERE CSI_CURRENT_BALANCE <> 0).
        //    يعني الصنف اللي رصيده صفر مالوش صف في hq_balances خالص.
        //    فالفلاتر اللي بتدوّر على الصفر أو الأقل من حد معيّن لازم
        //    تبدأ من hq_codes وتعمل LEFT JOIN — مش من hq_balances،
        //    وإلا هترجّع فاضي على طول.
        if ($mode === 'zero' || $mode === 'below') {
            $params = [];
            if ($mode === 'zero') {
                $cmp = '<= 0.0001';
            } else {
                $cmp      = '< ?';
                $params[] = $threshold;
            }
            try {
                $st = db()->prepare(
                    "SELECT c.item_id
                     FROM (SELECT DISTINCT item_id FROM hq_codes WHERE item_id > 0) c
                     LEFT JOIN (SELECT item_id, SUM(balance) AS t
                                FROM " . bal_table() . " GROUP BY item_id) b
                            ON b.item_id = c.item_id
                     WHERE COALESCE(b.t, 0) $cmp
                     ORDER BY c.item_id
                     LIMIT " . (int) $limit
                );
                $st->execute($params);
                $itemIds = array_map(fn($r) => (int) $r['item_id'], $st->fetchAll());
                if (!$itemIds) return [];

                $balances = sqlsrv_fetch_item_balances_all($itemIds);
                $names    = stock_item_names($itemIds);

                $out = [];
                foreach ($itemIds as $iid) {
                    $info = $names[$iid] ?? ['code' => (string) $iid, 'name' => '—'];
                    $out[] = stock_build_row($iid, $balances[$iid] ?? [], $info['code'], $info['name']);
                }
                usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
                return $out;
            } catch (Throwable $ex) {
                error_log('stock_query: فشل فلترة الصفر/الأقل محلياً — ' . $ex->getMessage());
                // نكمّل على الجسر
            }
        }

        $params = [];
        switch ($mode) {
            case 'admin_only':
                $having = "SUM(CASE WHEN sto_id = $adminSto THEN balance ELSE 0 END) > 0.0001
                           AND SUM(CASE WHEN sto_id <> $adminSto THEN balance ELSE 0 END) <= 0.0001";
                break;

            case 'branch_only':
                if ($stoId <= 0) return [];
                $having   = "SUM(CASE WHEN sto_id = ? THEN balance ELSE 0 END) > 0.0001";
                $params[] = $stoId;
                break;

            case 'has_stock':
                $having = "SUM(balance) > 0.0001";
                break;

            default:
                return [];
        }

        try {
            $st = db()->prepare(
                "SELECT item_id FROM " . bal_table() . "
                 GROUP BY item_id
                 HAVING $having
                 ORDER BY item_id
                 LIMIT " . (int) $limit
            );
            $st->execute($params);
            $itemIds = array_map(fn($r) => (int) $r['item_id'], $st->fetchAll());
            if (!$itemIds) return [];

            $balances = sqlsrv_fetch_item_balances_all($itemIds);
            $names    = stock_item_names($itemIds);

            $out = [];
            foreach ($itemIds as $iid) {
                $info = $names[$iid] ?? ['code' => (string) $iid, 'name' => '—'];
                $out[] = stock_build_row($iid, $balances[$iid] ?? [], $info['code'], $info['name']);
            }
            usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
            return $out;
        } catch (Throwable $ex) {
            error_log('stock_query: فشل الفلترة المحلية — ' . $ex->getMessage());
            // نكمّل على الجسر
        }
    }

    // 🔴 وضع المرآة بس: مفيش رجوع للجسر.
    if (stock_mirror_only()) return [];

    // =============== ٢) الجسر (احتياطي) ===============
    $tbl    = SQLSRV_PREFIX . 'COM_STO_ITEMS';
    $params = [];

    switch ($mode) {
        case 'admin_only':
            $having = "SUM(CASE WHEN STO_ID = $adminSto THEN CSI_CURRENT_BALANCE ELSE 0 END) > 0.0001
                       AND SUM(CASE WHEN STO_ID <> $adminSto THEN CSI_CURRENT_BALANCE ELSE 0 END) <= 0.0001";
            break;

        case 'branch_only':
            if ($stoId <= 0) return [];
            $having   = "SUM(CASE WHEN STO_ID = ? THEN CSI_CURRENT_BALANCE ELSE 0 END) > 0.0001";
            $params[] = $stoId;
            break;

        case 'has_stock':
            $having = "SUM(CSI_CURRENT_BALANCE) > 0.0001";
            break;

        case 'zero':
            $having = "SUM(CSI_CURRENT_BALANCE) <= 0.0001";
            break;

        case 'below':
            $having   = "SUM(CSI_CURRENT_BALANCE) < ?";
            $params[] = $threshold;
            break;

        default:
            return [];
    }

    $sql = "SELECT TOP $limit ITEM_ID
            FROM $tbl
            GROUP BY ITEM_ID
            HAVING $having
            ORDER BY ITEM_ID";
    try {
        $rows = sqlsrv_query($sql, $params);
    } catch (Throwable $ex) {
        error_log('stock_query: فشل الفلترة — ' . $ex->getMessage());
        $GLOBALS['recv_errors'][] = 'فلترة الأرصدة: ' . $ex->getMessage();
        return [];
    }
    $itemIds = array_map(fn($r) => (int) $r['ITEM_ID'], $rows);
    if (!$itemIds) return [];

    $balances = sqlsrv_fetch_item_balances_all($itemIds);
    $names    = stock_item_names($itemIds);

    $out = [];
    foreach ($itemIds as $iid) {
        $info = $names[$iid] ?? ['code' => (string) $iid, 'name' => '—'];
        $out[] = stock_build_row($iid, $balances[$iid] ?? [], $info['code'], $info['name']);
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/**
 * أسماء وأكواد الأصناف بالـ item_id (الكود الأساسي code_type=0 أولاً).
 * @return array<int,array{code:string,name:string}>
 */
function stock_item_names(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($i) => $i > 0)));
    if (!$ids) return [];

    // ١) المحلي
    if (hq_codes_ready()) {
        try {
            $out = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = db()->prepare(
                    "SELECT item_id, code, name FROM hq_codes
                     WHERE item_id IN ($ph)
                     ORDER BY item_id, code_type ASC"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() as $r) {
                    $iid = (int) $r['item_id'];
                    if (isset($out[$iid])) continue;   // الأساسي أولاً
                    $out[$iid] = [
                        'code' => (string) $r['code'],
                        'name' => (string) ($r['name'] ?? ''),
                    ];
                }
            }
            return $out;
        } catch (Throwable $ex) {
            // نكمّل على الجسر
        }
    }

    // ٢) الجسر (احتياطي)
    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT " . STK_ITEM_ID . " AS item_id,
                       LTRIM(RTRIM(" . STK_IC_CODE . ")) AS code,
                       LTRIM(RTRIM(" . STK_NAME . ")) AS name
                FROM " . STK_CODES_TABLE . "
                WHERE " . STK_ITEM_ID . " IN ($ph)
                ORDER BY " . STK_ITEM_ID . ", " . STK_CODE_TYPE . " ASC";
        try {
            foreach (sqlsrv_query($sql, $chunk) as $r) {
                $iid = (int) $r['item_id'];
                if (isset($out[$iid])) continue;   // الأساسي أولاً
                $out[$iid] = [
                    'code' => (string) $r['code'],
                    'name' => sqlsrv_fix_encoding((string) ($r['name'] ?? '')),
                ];
            }
        } catch (Throwable $ex) {
            error_log('stock_query: فشل جلب الأسماء — ' . $ex->getMessage());
        }
    }
    return $out;
}
