<?php
declare(strict_types=1);
/**
 * labels_lib.php — مشترك بين صفحة الليبلز والـAPI وصفحة الطباعة (بريف ٢١٦)
 * نظام الفجالة ستور المالي
 *
 * فيه: إعدادات الليبل · قراءة الأكواد من المرآة · تجهيز طلبات الطباعة/الإكسيل.
 *
 * 🔴 مفيش أي كتابة في القاعدة هنا غير إعدادات labels_* في settings.
 * 🔴 السعر بيتقرا من hq_codes.price (سعر الكود نفسه) — سعر الباكو مش سعر القطعة.
 */

require_once __DIR__ . '/barcode_lib.php';

/** أقصى عدد ليبلز في الطباعة الواحدة (قرار البريف). */
const LABELS_MAX = 500;

/** إعدادات الليبل الافتراضية — بتتخزّن في settings بمفاتيح labels_*. */
function labels_defaults(): array
{
    return [
        'w_mm'       => 38.0,
        'h_mm'       => 25.0,
        'cols'       => 1,
        'rows'       => 1,
        'gap_mm'     => 2.0,
        'show_price' => true,
        'shop_name'  => 'مكتبة الفجالة',
    ];
}

/**
 * الإعدادات الحالية (من settings لو موجودة، وإلا الافتراضي).
 * القيم بتتحصر في مدى معقول عشان إعداد غلط مايطلعش طباعة مقطوعة.
 */
function labels_settings(): array
{
    $d = labels_defaults();

    $w    = (float) setting('labels_w_mm', (string) $d['w_mm']);
    $h    = (float) setting('labels_h_mm', (string) $d['h_mm']);
    $cols = (int) setting('labels_cols', (string) $d['cols']);
    $rows = (int) setting('labels_rows', (string) $d['rows']);
    $gap  = (float) setting('labels_gap_mm', (string) $d['gap_mm']);
    $show = (string) setting('labels_show_price', '1') === '1';
    $shop = trim((string) setting('labels_shop_name', $d['shop_name']));

    return [
        'w_mm'       => $w >= 20 && $w <= 120 ? $w : $d['w_mm'],
        'h_mm'       => $h >= 10 && $h <= 80 ? $h : $d['h_mm'],
        'cols'       => $cols >= 1 && $cols <= 5 ? $cols : $d['cols'],
        'rows'       => $rows >= 1 && $rows <= 4 ? $rows : $d['rows'],
        'gap_mm'     => $gap >= 0 && $gap <= 10 ? $gap : $d['gap_mm'],
        'show_price' => $show,
        'shop_name'  => $shop !== '' ? mb_substr($shop, 0, 40) : $d['shop_name'],
    ];
}

/**
 * حفظ إعدادات الليبل (استعلام واحد بـ٧ صفوف — مفيش استعلام في حلقة).
 * الصلاحية (المالك) بيتأكد منها اللي بينادي.
 */
function labels_save_settings(array $in): array
{
    $d = labels_defaults();

    $w    = (float) ($in['w_mm'] ?? $d['w_mm']);
    $h    = (float) ($in['h_mm'] ?? $d['h_mm']);
    $cols = (int) ($in['cols'] ?? $d['cols']);
    $rows = (int) ($in['rows'] ?? $d['rows']);
    $gap  = (float) ($in['gap_mm'] ?? $d['gap_mm']);
    $show = !empty($in['show_price']) ? '1' : '0';
    $shop = trim((string) ($in['shop_name'] ?? ''));

    if ($w < 20 || $w > 120 || $h < 10 || $h > 80 || $cols < 1 || $cols > 5 || $rows < 1 || $rows > 4 || $gap < 0 || $gap > 10) {
        throw new InvalidArgumentException('مقاسات الليبل مش منطقية');
    }
    if ($shop === '') {
        $shop = $d['shop_name'];
    }
    $shop = mb_substr($shop, 0, 40);

    $pairs = [
        'labels_w_mm'       => labels_num($w),
        'labels_h_mm'       => labels_num($h),
        'labels_cols'       => (string) $cols,
        'labels_rows'       => (string) $rows,
        'labels_gap_mm'     => labels_num($gap),
        'labels_show_price' => $show,
        'labels_shop_name'  => $shop,
    ];

    $values = [];
    foreach ($pairs as $k => $v) {
        $values[] = $k;
        $values[] = $v;
    }
    $rows = implode(',', array_fill(0, count($pairs), '(?, ?)'));

    $st = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES {$rows}
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $st->execute($values);

    return labels_settings();
}

/** رقم إعداد من غير أصفار زيادة (٣٨ مش ٣٨.٠ · ٣٨.٥ تفضل ٣٨.٥). */
function labels_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/** صف واحد للعرض في الشاشة من صف قاعدة. */
function labels_row_out(array $r): array
{
    $code = (string) $r['code'];
    return [
        'code'      => $code,
        'name'      => (string) ($r['name'] ?? ''),
        'price'     => round((float) ($r['price'] ?? 0), 2),
        'code_type' => (int) ($r['code_type'] ?? 0),
        'factor'    => round((float) ($r['unit_factor'] ?? 1), 3),
        'kind'      => barcode_kind_label($code),
        'printable' => barcode_printable($code),
    ];
}

/** كود واحد بالسكانر — «مش موجود» نتيجة عادية (null) مش خطأ. */
function labels_item_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $st = db()->prepare(
        'SELECT code, name, price, code_type, unit_factor
           FROM hq_codes WHERE code = ? LIMIT 1'
    );
    $st->execute([$code]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? labels_row_out($r) : null;
}

/** بحث بالاسم (أو جزء من الكود) — أقرب ٢٥ نتيجة. */
function labels_search(string $term, int $limit = 25): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) {
        return [];
    }
    // % و _ دول أحرف بحث في LIKE — لازم يتفلتوا عشان البحث يبقى حرفي
    $like = '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';

    $st = db()->prepare(
        'SELECT code, name, price, code_type, unit_factor
           FROM hq_codes
          WHERE name LIKE ? OR code LIKE ?
          ORDER BY code_type ASC, name ASC
          LIMIT ' . max(1, min($limit, 50))
    );
    $st->execute([$like, $like]);
    return array_map('labels_row_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * أكواد بالجملة (استعلام واحد مهما كان العدد).
 * بيرجّع خريطة: code => صف العرض.
 */
function labels_codes_map(array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static fn($c) => $c !== '')));
    if (!$codes) {
        return [];
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = db()->prepare(
        "SELECT code, name, price, code_type, unit_factor FROM hq_codes WHERE code IN ($in)"
    );
    $st->execute($codes);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['code']] = labels_row_out($r);
    }
    return $out;
}

/**
 * قراءة طلب الطباعة/التصدير: JSON على شكل [{"code":"…","copies":2}, …].
 * بترجّع [صفوف نضيفة, إجمالي الليبلز].
 * نسخة نفس الكود مرتين بتتجمع في عدد واحد.
 */
function labels_parse_rows(string $json): array
{
    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        return [[], 0];
    }

    $merged = [];
    $total = 0;
    foreach ($raw as $r) {
        if (!is_array($r)) {
            continue;
        }
        $code = trim((string) ($r['code'] ?? ''));
        if ($code === '' || strlen($code) > 64) {
            continue;
        }
        // سقف أمان رقمي بس. حد الـ٥٠٠ الحقيقي بيتحقق على **الإجمالي** في
        // labels_print/labels_api — لو قصّينا هنا الرقم في صمت، الطلب اللي
        // فيه ٥٠١ ليبل هيطبع ٥٠٠ من غير ما محمد يعرف إنه طلب أكتر.
        $copies = (int) ($r['copies'] ?? 1);
        $copies = max(1, min(9999, $copies));
        $merged[$code] = ($merged[$code] ?? 0) + $copies;
    }

    $settings = labels_settings();
    $cols = max(1, (int) ($settings['cols'] ?? 1));
    $perPage = $cols * max(1, (int) ($settings['rows'] ?? 1));

    foreach ($merged as $code => $copies) {
        $copies = max(1, min(9999, $copies));
        // تقريب عدد الليبلز لأقرب مضاعف لـ cols لفوق (بريف ٨٦٤)
        // دلوقتي بنكمّل القطعة كلها: cols × rows (بريف ٨٧١).
        if ($perPage > 1) {
            $copies = (int) (ceil($copies / $perPage) * $perPage);
        }
        $merged[$code] = $copies;
        $total += $copies;
    }
    // من غير ksort: ترتيب الليبلز في الطباعة يفضل زي ترتيب القايمة اللي محمد شافها
    $rows = [];
    foreach ($merged as $code => $copies) {
        $rows[] = ['code' => (string) $code, 'copies' => $copies];
    }
    return [$rows, $total];
}

/** سعر للعرض: ٢٢.٦٨ · ١٥.٠٠ */
function labels_price(float $price): string
{
    return number_format($price, 2, '.', ',');
}
