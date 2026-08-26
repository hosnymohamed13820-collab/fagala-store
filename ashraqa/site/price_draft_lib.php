<?php
/**
 * price_draft_lib.php — تقرير «أصناف بدون سعر بيع»
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  الفكرة
 * =====================================================================
 *  جدول item_price_drafts هو **جدول التقرير نفسه**: كل سطر = صنف
 *  مضاف للتقرير + السعر المقترح ليه. مفيش «سلة» منفصلة.
 *
 *  مصدر البيانات: hq_codes (المرآة المحلية) — فيها:
 *      code · item_id · name · code_type · unit_factor
 *      price (سعر البيع الحالي في نظام الشركة)
 *      cost  (متوسط التكلفة CI_AVR_COST)
 *  فمفيش أي نداء للجسر ولا لـSQL Server من الشاشة دي — كله محلي.
 *
 *  ⚠️ زي مودويل الاستلامات بالظبط (شوف التعليق في receiving_lib.php
 *     عند «أسعار البيع لأصناف الفاتورة»): **قاعدة الشركة مابتتكتبش
 *     أبدًا**. السعر بيتخزّن عندنا، وبيوصل الشركة لما ترفع ملف الـTXT.
 *
 *  الأرقام: سعر البيع بخانتين — نظام الشركة بيخزّن CIP_PRICE بخانتين
 *  (شوف agent_hq.ps1: CAST(... AS DECIMAL(14,2))). النسبة بتتحسب على
 *  **سعر التكلفة** مش على السعر القديم: 100 + 3% = 103.
 * =====================================================================
 */
require_once __DIR__ . '/config.php';

const PDFT_SALE_DP   = 2;    // خانات سعر البيع (زي CIP_PRICE عند الشركة)
const PDFT_PAGE      = 100;  // صفوف نافذة «سعرها صفر» في الصفحة
const PDFT_MAX_ADD   = 2000; // أقصى عدد أصناف تتضاف في مرة واحدة

/** الجدول اتعمل ولا لسه؟ (بيتسأل مرة واحدة في الريكوست) */
function pdft_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->query('SELECT 1 FROM item_price_drafts LIMIT 1');
        $ready = true;
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/** مرآة الأكواد فيها داتا؟ */
function pdft_codes_ready(): bool
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

/** تقريب سعر البيع للدقة المعتمدة */
function pdft_round(float $v): float
{
    return round($v, PDFT_SALE_DP);
}

/**
 * حساب السعر من التكلفة والنسبة: التكلفة + نسبة% من التكلفة.
 *   (100, 3) → 103
 * بترجّع null لو التكلفة صفر (مفيش أساس نحسب عليه).
 */
function pdft_price_from_markup(float $cost, float $pct): ?float
{
    if ($cost <= 0) return null;
    return pdft_round($cost * (1 + $pct / 100));
}

// =====================================================================
//  قراءة صفوف التقرير
// =====================================================================

/**
 * صفوف التقرير كلها — مع تحديث الاسم/التكلفة/السعر الحالي من المرآة.
 *
 * بترجّع لكل صنف:
 *   code · item_id · name · cost · sale (المقترح عندنا)
 *   live (سعر البيع الحالي عند الشركة) · pct · changed
 *
 * استعلام واحد (LEFT JOIN) — مش صف-بصف.
 */
function pdft_rows(): array
{
    if (!pdft_ready()) return [];

    $sql = 'SELECT d.item_code, d.item_id, d.sale_price, d.markup_pct,
                   d.item_name  AS snap_name,
                   d.cost_price AS snap_cost,
                   c.name       AS live_name,
                   c.cost       AS live_cost,
                   c.price      AS live_price
            FROM item_price_drafts d
            LEFT JOIN hq_codes c ON c.code = d.item_code
            ORDER BY d.created_at, d.item_code';

    $out = [];
    foreach (db()->query($sql) as $r) {
        // المرآة هي المرجع؛ اللقطة احتياطي لو الكود اتشال من المرآة
        $cost = $r['live_cost'] !== null ? (float) $r['live_cost'] : (float) $r['snap_cost'];
        $live = $r['live_price'] !== null ? (float) $r['live_price'] : null;
        $sale = (float) $r['sale_price'];
        $out[] = [
            'code'    => (string) $r['item_code'],
            'item_id' => (int) $r['item_id'],
            'name'    => (string) ($r['live_name'] ?? $r['snap_name'] ?? ''),
            'cost'    => $cost,
            'sale'    => $sale,
            'live'    => $live,
            'pct'     => $r['markup_pct'] !== null ? (float) $r['markup_pct'] : null,
            // اتغيّر عن اللي عند الشركة؟ (لتلوين السطر)
            'changed' => $live === null || abs($sale - $live) > 0.004,
        ];
    }
    return $out;
}

/** عدد صفوف التقرير */
function pdft_count(): int
{
    if (!pdft_ready()) return 0;
    return (int) db()->query('SELECT COUNT(*) FROM item_price_drafts')->fetchColumn();
}

// =====================================================================
//  إضافة / شيل
// =====================================================================

/**
 * إضافة أكواد للتقرير (دفعة واحدة).
 * السعر المبدئي = سعر البيع الحالي عند الشركة (أو 0 لو مفيش).
 * الكود الموجود قبل كده مابيتغيّرش — عشان ما نمسحش تعديل شغال.
 *
 * بترجّع عدد اللي اتضافوا فعلاً.
 */
function pdft_add(array $codes, ?int $userId = null): int
{
    if (!pdft_ready()) return 0;

    $codes = array_values(array_unique(array_filter(
        array_map(fn($c) => trim((string) $c), $codes),
        fn($c) => $c !== ''
    )));
    if (!$codes) return 0;
    if (count($codes) > PDFT_MAX_ADD) $codes = array_slice($codes, 0, PDFT_MAX_ADD);

    $pdo = db();
    $added = 0;

    // INSERT ... SELECT من المرآة: دفعة واحدة لكل 500 كود، من غير
    // أي استعلام لكل صنف. IGNORE بيحمي الموجود من الاستبدال.
    foreach (array_chunk($codes, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare(
            "INSERT IGNORE INTO item_price_drafts
                (item_code, item_id, item_name, cost_price, sale_price, added_by, updated_by)
             SELECT c.code, c.item_id, c.name,
                    COALESCE(c.cost, 0), COALESCE(c.price, 0), ?, ?
             FROM hq_codes c
             WHERE c.code IN ($ph)"
        );
        $st->execute(array_merge([$userId, $userId], $chunk));
        $added += $st->rowCount();
    }
    return $added;
}

/** شيل أكواد من التقرير */
function pdft_remove(array $codes): int
{
    if (!pdft_ready()) return 0;
    $codes = array_values(array_filter(array_map(fn($c) => trim((string) $c), $codes)));
    if (!$codes) return 0;

    $removed = 0;
    foreach (array_chunk($codes, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("DELETE FROM item_price_drafts WHERE item_code IN ($ph)");
        $st->execute($chunk);
        $removed += $st->rowCount();
    }
    return $removed;
}

/** تفريغ التقرير كله */
function pdft_clear(): int
{
    if (!pdft_ready()) return 0;
    $st = db()->query('DELETE FROM item_price_drafts');
    return $st->rowCount();
}

// =====================================================================
//  تعديل الأسعار
// =====================================================================

/**
 * حفظ سعر صنف واحد (تعديل يدوي من عمود سعر البيع).
 * $pct = النسبة المكتوبة (لو السعر اتحسب منها) أو null.
 */
function pdft_set_price(string $code, float $price, ?float $pct = null, ?int $userId = null): bool
{
    if (!pdft_ready()) return false;
    $code = trim($code);
    if ($code === '' || $price < 0) return false;

    $st = db()->prepare(
        'UPDATE item_price_drafts
            SET sale_price = ?, markup_pct = ?, updated_by = ?
          WHERE item_code = ?'
    );
    $st->execute([pdft_round($price), $pct, $userId, $code]);
    return true;
}

/**
 * نسبة موحّدة على كل صفوف التقرير — **استعلام واحد**.
 *
 * السعر = التكلفة + نسبة% من التكلفة. الأصناف اللي تكلفتها صفر
 * بتتساب زي ما هي (مفيش أساس نحسب عليه) وبترجّع في العدد المتخطّى.
 *
 * بترجّع ['updated' => n, 'skipped' => n]
 */
function pdft_apply_markup(float $pct, ?int $userId = null): array
{
    if (!pdft_ready()) return ['updated' => 0, 'skipped' => 0];

    $pdo = db();
    $factor = 1 + $pct / 100;
    $dp     = (int) PDFT_SALE_DP;   // ثابت كود مش مدخل مستخدم — بيتحط في النص مباشرة

    // العدّ الأول: rowCount() في MySQL بيرجّع الصفوف اللي **اتغيّرت**
    // فعلاً، فلو طبّقت نفس النسبة تاني هيرجّع صفر ويبان إن مفيش حاجة
    // حصلت. فبنعدّ المطابق بنفسنا قبل التحديث.
    $matched = (int) $pdo->query(
        'SELECT COUNT(*) FROM item_price_drafts d
         LEFT JOIN hq_codes c ON c.code = d.item_code
         WHERE COALESCE(c.cost, d.cost_price) > 0'
    )->fetchColumn();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM item_price_drafts')->fetchColumn();

    // التكلفة من المرآة، وبترجع للقطة لو الكود اتشال منها
    $st = $pdo->prepare(
        "UPDATE item_price_drafts d
         LEFT JOIN hq_codes c ON c.code = d.item_code
            SET d.sale_price = ROUND(COALESCE(c.cost, d.cost_price) * ?, $dp),
                d.markup_pct = ?,
                d.updated_by = ?
          WHERE COALESCE(c.cost, d.cost_price) > 0"
    );
    $st->execute([$factor, $pct, $userId]);

    return ['updated' => $matched, 'skipped' => $total - $matched];
}

// =====================================================================
//  البحث ونافذة «سعرها صفر»
// =====================================================================

/**
 * بحث بالكود أو الاسم في المرآة — لإضافة صنف مش سعره صفر.
 * بترجّع code · item_id · name · cost · price
 */
function pdft_search(string $term, int $limit = 25): array
{
    $term = trim($term);
    if ($term === '' || !pdft_codes_ready()) return [];
    if ($limit < 1)  $limit = 25;
    if ($limit > 100) $limit = 100;

    // الكود المطابق بالظبط الأول، بعدين البادئة، بعدين أي مكان
    $like = '%' . pdft_esc_like($term) . '%';
    $st = db()->prepare(
        "SELECT code, item_id, name, cost, price
         FROM hq_codes
         WHERE code = ? OR code LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!'
         ORDER BY (code = ?) DESC, name
         LIMIT $limit"
    );
    $st->execute([$term, $like, $like, $term]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = pdft_map_code_row($r);
    }
    return $out;
}

/**
 * الأصناف اللي سعر بيعها صفر — بصفحات + بحث اختياري.
 * بترجّع ['rows' => [...], 'total' => n, 'page' => n, 'pages' => n]
 */
function pdft_zero_price(string $term = '', int $page = 1): array
{
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    if (!pdft_codes_ready()) return $empty;

    $term = trim($term);
    $where  = 'COALESCE(c.price, 0) <= 0';
    $params = [];
    if ($term !== '') {
        $like = '%' . pdft_esc_like($term) . '%';
        $where .= " AND (c.code LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!')";
        $params = [$like, $like];
    }

    $pdo = db();
    $cs  = $pdo->prepare("SELECT COUNT(*) FROM hq_codes c WHERE $where");
    $cs->execute($params);
    $total = (int) $cs->fetchColumn();

    $pages = max(1, (int) ceil($total / PDFT_PAGE));
    if ($page < 1) $page = 1;
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * PDFT_PAGE;
    $limit  = PDFT_PAGE;

    // الموجود في التقرير بالفعل بيتعلّم عليه عشان ما يتضافش مرتين
    $st = $pdo->prepare(
        "SELECT c.code, c.item_id, c.name, c.cost, c.price,
                (d.item_code IS NOT NULL) AS in_report
         FROM hq_codes c
         LEFT JOIN item_price_drafts d ON d.item_code = c.code
         WHERE $where
         ORDER BY c.name, c.code
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $row = pdft_map_code_row($r);
        $row['in_report'] = !empty($r['in_report']);
        $rows[] = $row;
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * كل أكواد «سعرها صفر» المطابقة للفلتر — للزرار «تحديد الكل».
 * بترجّع أكواد بس (مش صفوف كاملة) عشان تفضل خفيفة.
 */
function pdft_zero_price_codes(string $term = ''): array
{
    if (!pdft_codes_ready()) return [];

    $where  = 'COALESCE(price, 0) <= 0';
    $params = [];
    $term = trim($term);
    if ($term !== '') {
        $like = '%' . pdft_esc_like($term) . '%';
        $where .= " AND (code LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!')";
        $params = [$like, $like];
    }
    $lim = PDFT_MAX_ADD;
    $st = db()->prepare("SELECT code FROM hq_codes WHERE $where ORDER BY name, code LIMIT $lim");
    $st->execute($params);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// =====================================================================
//  مساعدات
// =====================================================================

/** تهريب محارف LIKE (% و _ و !) عشان بحث الكود ما يتلخبطش */
function pdft_esc_like(string $s): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
}

/** توحيد شكل صف جاي من hq_codes */
function pdft_map_code_row(array $r): array
{
    return [
        'code'    => (string) $r['code'],
        'item_id' => (int) $r['item_id'],
        'name'    => (string) ($r['name'] ?? ''),
        'cost'    => $r['cost'] !== null ? (float) $r['cost'] : 0.0,
        'price'   => $r['price'] !== null ? (float) $r['price'] : 0.0,
    ];
}
