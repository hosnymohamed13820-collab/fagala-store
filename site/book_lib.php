<?php
/**
 * book_lib.php — منطق طلبيات الكتب وحجز الكتب
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  تابين جداد جوه موديول النواقص:
 *
 *   • طلبيات الكتب — كتالوج كتب خارجية + كمية مطلوبة لكل مورد.
 *     ٣ طلبيات تحت بعض (مورد لكل واحدة)، وزرار بينسخ نص الطلبية
 *     جاهز للواتساب. الموردين في جدول عشان الإضافة تبقى من غير كود.
 *
 *   • حجز الكتب — العميل بيحجز كتب، وكل كتاب بيتعرض جنبه حالته:
 *       نزل   = الصنف اتشاف في فاتورة شراء
 *       منزلش = عمره ما نزل
 *
 * =====================================================================
 *  ⚠️ الربط بالـ ITEM_ID مش بالكود ولا الاسم
 * =====================================================================
 *  الصنف الواحد ليه أكتر من كود (ITEM_CODES: أساسي + فرعي) وكلهم
 *  بنفس الـ ITEM_ID. لو قارنّا بالكود، كود فرعي نزل في فاتورة
 *  والأساسي لأ هيدّي نتيجة غلط. الـ ITEM_ID هو الثابت الوحيد.
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';

/**
 * شريط التابات المشترك بين الصفحات التلاتة.
 *
 * كل تاب صفحة لوحدها (زي `invoices.php?tab=` بالظبط — التابات هناك
 * روابط مش جافاسكربت). كده كل ملف يفضل مقروء بدل ملف واحد بـ٢٠٠٠ سطر،
 * والمستخدم مايحسّش بأي فرق.
 *
 * إضافة تاب جديد = سطر واحد هنا.
 *
 * @param string $active shortage | books | reserve
 */
function book_tabs_bar(string $active): string
{
    $tabs = [
        'shortage' => ['طلب نواقص',   'ti-shopping-cart', 'shortage.php'],
        'breq'     => ['طلبات الفروع', 'ti-list-details',  'branch_requests.php'],
        'pgroup'   => ['تجميع الحجوزات', 'ti-stack-2',     'purchase_groups.php'],
        'books'    => ['طلبيات الكتب', 'ti-list',          'book_orders.php'],
        'reserve'  => ['حجز الكتب',    'ti-user-check',    'book_reserve.php'],
        'market'   => ['أسعار السوق',  'ti-discount',      'market_prices.php'],
    ];

    $css = '<style>
        .bk-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .bk-tab { display:inline-flex; align-items:center; gap:7px; padding:11px 20px;
                  border:1.5px solid var(--line); border-radius:11px; background:var(--surface);
                  color:var(--ink-700,#4a4a68); font-size:14px; font-weight:600;
                  text-decoration:none; transition:all .15s; }
        .bk-tab:hover { border-color:var(--accent-text); color:var(--accent-text); }
        .bk-tab.active { background:var(--accent); border-color:var(--accent-text); color:#fff; }
        .bk-tab i { font-size:17px; }
    </style>';

    $html = '<div class="bk-tabs">';
    foreach ($tabs as $key => [$label, $icon, $url]) {
        $cls = $key === $active ? 'bk-tab active' : 'bk-tab';
        $html .= '<a class="' . $cls . '" href="' . e($url) . '">'
               . '<i class="ti ' . e($icon) . '"></i> ' . e($label) . '</a>';
    }
    return $css . $html . '</div>';
}

/** قسم "كتب خارجية" في ITEM_SPEC1 (بيتظبط من settings) */
function books_cat_id(): int
{
    return (int) setting('books_cat_is1_id', 1000032);
}

/** المستخدم ده يقدر يعدّل "كمية المورد"؟ (الإدارة بس) */
function books_can_edit_supplier_qty(): bool
{
    return is_owner();
}

/** جدول موجود وفيه صفوف؟ (نفس أسلوب hq_*_ready في receiving_lib) */
function books_table_ready(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $cache[$table] = (bool) db()->query("SELECT 1 FROM `$table` LIMIT 1")->fetchColumn();
    } catch (Throwable $ex) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

// =====================================================================
//  (١) طلبيات الكتب
// =====================================================================

/** موردو الكتب النشطين بالترتيب @return array */
function book_suppliers(): array
{
    try {
        return db()->query(
            'SELECT id, name FROM book_suppliers WHERE is_active = 1
             ORDER BY sort_order, id'
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * كل الكتب مع كميات كل الموردين — **استعلامين بس** مهما كان عدد الموردين.
 *
 * بنجيب الكتالوج مرة، والبنود مرة، وبندمجهم في PHP. البديل (استعلام
 * لكل مورد) بيبقى N+1 وبيتقل مع كل مورد جديد.
 *
 * @param string $term بحث بالاسم (فاضي = الكل)
 * @return array [ ['id','code','name','publisher','term','cost','needed',
 *                  'sup' => [supplier_id => ['req'=>float,'sup'=>?float]]], ... ]
 */
function book_catalog_with_orders(string $term = '', int $limit = 500): array
{
    $term = trim($term);
    $pdo  = db();

    try {
        if ($term !== '') {
            // بحث بكل الكلمات (كل كلمة لازم تكون موجودة) — نفس أسلوب
            // stock_search_by_name عشان "معاصر ماس" تلاقي "المعاصر ماس 5ب"
            $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
            $words = array_slice($words, 0, 5);
            $where = []; $args = [];
            foreach ($words as $w) {
                $where[] = '(name LIKE ? OR code LIKE ?)';
                $args[]  = '%' . $w . '%';
                $args[]  = '%' . $w . '%';
            }
            $st = $pdo->prepare(
                'SELECT id, code, name, publisher, term, cost, needed
                 FROM book_catalog
                 WHERE is_active = 1 AND ' . implode(' AND ', $where) . '
                 ORDER BY name LIMIT ' . (int) $limit
            );
            $st->execute($args);
            $books = $st->fetchAll();
        } else {
            $books = $pdo->query(
                'SELECT id, code, name, publisher, term, cost, needed
                 FROM book_catalog WHERE is_active = 1
                 ORDER BY name LIMIT ' . (int) $limit
            )->fetchAll();
        }
    } catch (Throwable $ex) {
        return [];
    }
    if (!$books) return [];

    // البنود لكل الكتب اللي طلعت — استعلام واحد
    $ids = array_map(fn($b) => (int) $b['id'], $books);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $bySupplier = [];
    try {
        $st = $pdo->prepare(
            "SELECT book_id, supplier_id, req_qty, supplier_qty
             FROM book_order_lines WHERE book_id IN ($ph)"
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $bySupplier[(int) $r['book_id']][(int) $r['supplier_id']] = [
                'req' => (float) $r['req_qty'],
                'sup' => $r['supplier_qty'] === null ? null : (float) $r['supplier_qty'],
            ];
        }
    } catch (Throwable $ex) { /* الجدول لسه مش موجود */ }

    foreach ($books as &$b) {
        $b['sup'] = $bySupplier[(int) $b['id']] ?? [];
    }
    unset($b);

    return $books;
}

/**
 * حفظ خانة واحدة (كمية مطلوبة أو كمية مورد).
 *
 * @param string $field 'req' أو 'sup'
 */
function book_save_qty(int $bookId, int $supplierId, string $field, ?float $qty, ?int $userId = null): void
{
    if ($bookId <= 0 || $supplierId <= 0) {
        throw new RuntimeException('بيانات ناقصة.');
    }
    if ($qty !== null && $qty < 0) {
        throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
    }

    if ($field === 'sup') {
        // "كمية المورد" للإدارة بس — الحماية هنا مش في الواجهة بس،
        // عشان حد يبعت الطلب مباشرة مايعدّيش.
        if (!books_can_edit_supplier_qty()) {
            throw new RuntimeException('كمية المورد للإدارة بس.');
        }
        db()->prepare(
            'INSERT INTO book_order_lines (book_id, supplier_id, req_qty, supplier_qty, updated_by)
             VALUES (?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE supplier_qty = VALUES(supplier_qty), updated_by = VALUES(updated_by)'
        )->execute([$bookId, $supplierId, $qty, $userId]);
        return;
    }

    if ($field !== 'req') throw new RuntimeException('حقل غير معروف.');

    db()->prepare(
        'INSERT INTO book_order_lines (book_id, supplier_id, req_qty, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE req_qty = VALUES(req_qty), updated_by = VALUES(updated_by)'
    )->execute([$bookId, $supplierId, (float) ($qty ?? 0), $userId]);
}

/*
 * ملحوظة: كان فيه هنا book_order_text() و book_order_counts() —
 * الاتنين كانوا بيقروا الطلبية **كاملة من الداتابيز**.
 *
 * اتشالوا لأن زرار النسخ بقى بينسخ **اللي على الشاشة بس**: الكتب
 * اللي المستخدم ضافها في الجلسة دي بكمياتها، مش كل الـ312 كتاب
 * المتراكمين في الجدول. النسخ كله بقى في المتصفح من غير أي نداء
 * للسيرفر — أسرع، وبيطابق اللي قدام عين المستخدم بالظبط.
 *
 * لو احتجنا بعدين زرار "انسخ الطلبية الكاملة من الداتابيز"،
 * الدالتين سهل رجوعهم.
 */

// =====================================================================
//  (٢) حالة الكتاب: نزل / منزلش
// =====================================================================

/**
 * الأصناف اللي نزلت في أي فاتورة شراء — بالـ ITEM_ID.
 *
 * المصدر الأساسي: hq_purchased_items (الوكيل بيملاه من **كل** جداول
 * الشهور، فبيغطي عمر النظام كله).
 *
 * لو الجدول لسه فاضي (الوكيل ما اتحدّثش)، بنرجع للمرآة العادية:
 * hq_invoice_lines + hq_unposted_lines — دي شغالة فورًا بس بتغطي
 * من 26_06 وطالع بس، فممكن كتاب قديم يبان "منزلش" وهو نزل.
 *
 * @param  array $itemIds
 * @return array [item_id => true] للأصناف اللي نزلت
 */
function books_purchased_map(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map(fn($i) => (int) $i, $itemIds),
        fn($i) => $i > 0
    )));
    if (!$ids) return [];

    $pdo = db();
    $out = [];

    // (١) الجدول المخصّص — استعلام واحد، مفتاح أساسي، أسرع حاجة
    if (books_table_ready('hq_purchased_items')) {
        foreach (array_chunk($ids, 800) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT item_id FROM hq_purchased_items WHERE item_id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) $out[(int) $iid] = true;
        }
        return $out;
    }

    // (٢) احتياطي: المرآة العادية (من 26_06 وطالع)
    foreach (array_chunk($ids, 800) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (['hq_invoice_lines', 'hq_unposted_lines'] as $t) {
            try {
                $st = $pdo->prepare("SELECT DISTINCT item_id FROM `$t` WHERE item_id IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) $out[(int) $iid] = true;
            } catch (Throwable $ex) { /* الجدول مش موجود */ }
        }
    }
    return $out;
}

/** مصدر بيانات حالة "نزل" — للعرض في الواجهة */
function books_status_source(): string
{
    return books_table_ready('hq_purchased_items')
        ? 'كل فواتير الشراء'
        : 'فواتير من 26_06 وطالع (شغّل الوكيل الجديد للتغطية الكاملة)';
}

// =====================================================================
//  (٣) بحث الكتب الخارجية (لشاشة الحجز) — بالكود أو الاسم
// =====================================================================

/**
 * بحث في الكتب الخارجية بس (IS1_ID = قسم كتب خارجية).
 *
 * المصدر: hq_items (باركود + اسم + item_id) JOIN hq_item_cats (التصنيف).
 * لو hq_item_cats لسه فاضي، بنبحث في كل الأصناف ونعلّم إن الفلتر واقف —
 * أحسن من شاشة فاضية.
 *
 * الصنف ليه أكتر من باركود، فبنجمّع بالـ item_id ونعرض كود واحد.
 *
 * @return array [ ['item_id','code','name','purchased'=>bool], ... ]
 */
function books_search(string $term, int $limit = 40): array
{
    $term = trim($term);
    if ($term === '') return [];

    $pdo    = db();
    $catOn  = books_table_ready('hq_item_cats');
    $catId  = books_cat_id();

    $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_slice($words, 0, 5);

    $where = []; $args = [];

    // كود كامل بالظبط = أولوية (مسح باركود)، وإلا بحث بالكلمات في الاسم
    if (count($words) === 1 && preg_match('/^[0-9A-Za-z\-]{3,}$/', $words[0])) {
        $where[] = '(i.barcode = ? OR i.name LIKE ?)';
        $args[]  = $words[0];
        $args[]  = '%' . $words[0] . '%';
    } else {
        foreach ($words as $w) {
            $where[] = '(i.name LIKE ? OR i.barcode LIKE ?)';
            $args[]  = '%' . $w . '%';
            $args[]  = '%' . $w . '%';
        }
    }

    $join = $catOn ? 'JOIN hq_item_cats c ON c.item_id = i.item_id' : '';
    if ($catOn) { $where[] = 'c.is1_id = ?'; $args[] = $catId; }

    try {
        // MIN(barcode) = كود واحد للصنف بدل تكرار كل الباركودات
        $st = $pdo->prepare(
            "SELECT i.item_id, MIN(i.barcode) AS code, MIN(i.name) AS name
             FROM hq_items i
             $join
             WHERE " . implode(' AND ', $where) . "
             GROUP BY i.item_id
             ORDER BY name
             LIMIT " . (int) $limit
        );
        $st->execute($args);
        $rows = $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
    if (!$rows) return [];

    $purchased = books_purchased_map(array_column($rows, 'item_id'));
    foreach ($rows as &$r) {
        $r['item_id']   = (int) $r['item_id'];
        $r['purchased'] = isset($purchased[$r['item_id']]);
    }
    unset($r);

    return $rows;
}

/** فلتر التصنيف شغال؟ (عشان الواجهة تحذّر لو لأ) */
function books_cat_ready(): bool
{
    return books_table_ready('hq_item_cats');
}

// =====================================================================
//  (٤) الحجوزات
// =====================================================================

/**
 * حفظ حجز جديد.
 *
 * ⚠️ الـ deposit بيتخزّن كرقم على الحجز بس — **مفيش أي قيد ولا حركة
 *    خزينة**. ربطه بـ finance قرار منفصل لسه ما اتحسمش.
 *
 * @param array $lines كل عنصر: ['item_id','code','name','qty']
 * @return int رقم الحجز
 */
function book_reservation_create(array $data, array $lines, int $userId): int
{
    $customer = trim((string) ($data['customer_name'] ?? ''));
    if ($customer === '') throw new RuntimeException('اكتب اسم العميل.');

    $clean = [];
    foreach ($lines as $l) {
        if (!is_array($l)) continue;
        $qty  = (float) ($l['qty'] ?? 0);
        $code = trim((string) ($l['code'] ?? ''));
        if ($qty <= 0 || $code === '') continue;
        $clean[] = [
            'item_id' => (int) ($l['item_id'] ?? 0),
            'code'    => mb_substr($code, 0, 64),
            'name'    => mb_substr(trim((string) ($l['name'] ?? '')), 0, 255),
            'qty'     => $qty,
        ];
    }
    if (!$clean) throw new RuntimeException('اكتب كمية حجز لكتاب واحد على الأقل.');

    $deposit = (float) ($data['deposit'] ?? 0);
    if ($deposit < 0) throw new InvalidArgumentException('المقدم لا يمكن أن يكون سالبًا.');

    $branchId = (int) ($data['branch_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO book_reservations
                (branch_id, customer_name, customer_phone, reserved_by, deposit, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $branchId > 0 ? $branchId : null,
            mb_substr($customer, 0, 150),
            mb_substr(trim((string) ($data['customer_phone'] ?? '')), 0, 40) ?: null,
            mb_substr(trim((string) ($data['reserved_by'] ?? '')), 0, 150) ?: null,
            $deposit,
            mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
            $userId,
        ]);
        $resId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO book_reservation_lines (reservation_id, item_id, code, item_name, qty)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($clean as $l) {
            $ins->execute([$resId, $l['item_id'], $l['code'], $l['name'], $l['qty']]);
        }

        $pdo->commit();
        return $resId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * قائمة الحجوزات (الأحدث أولاً) مع عدد الكتب — استعلام واحد.
 */
function book_reservations_list(string $status = 'open', int $limit = 100): array
{
    try {
        $sql = 'SELECT r.*, b.name AS branch_name, u.full_name AS creator,
                       (SELECT COUNT(*) FROM book_reservation_lines l WHERE l.reservation_id = r.id) AS n_books
                FROM book_reservations r
                LEFT JOIN branches b ON b.id = r.branch_id
                LEFT JOIN users u ON u.id = r.created_by';
        $args = [];
        if ($status !== '' && $status !== 'all') {
            $sql .= ' WHERE r.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT ' . (int) $limit;
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/** حجز واحد + بنوده + حالة كل كتاب @return array{res:array,lines:array}|null */
function book_reservation_get(int $resId): ?array
{
    if ($resId <= 0) return null;
    try {
        $st = db()->prepare(
            'SELECT r.*, b.name AS branch_name, u.full_name AS creator
             FROM book_reservations r
             LEFT JOIN branches b ON b.id = r.branch_id
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.id = ? LIMIT 1'
        );
        $st->execute([$resId]);
        $res = $st->fetch();
        if (!$res) return null;

        $ls = db()->prepare(
            'SELECT * FROM book_reservation_lines WHERE reservation_id = ? ORDER BY id'
        );
        $ls->execute([$resId]);
        $lines = $ls->fetchAll();

        $purchased = books_purchased_map(array_column($lines, 'item_id'));
        foreach ($lines as &$l) {
            $l['purchased'] = isset($purchased[(int) $l['item_id']]);
        }
        unset($l);

        return ['res' => $res, 'lines' => $lines];
    } catch (Throwable $ex) {
        return null;
    }
}

/** تغيير حالة حجز (تم التسليم / إلغاء) */
function book_reservation_set_status(int $resId, string $status): bool
{
    if (!in_array($status, ['open', 'delivered', 'cancelled'], true)) return false;
    db()->prepare('UPDATE book_reservations SET status = ? WHERE id = ?')
        ->execute([$status, $resId]);
    return true;
}
