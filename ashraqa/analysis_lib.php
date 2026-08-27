<?php
/**
 * analysis_lib.php — منطق تحليل الموسم (استيراد ملفات TXT + التصنيف + التقرير)
 * نظام الفجالة ستور المالي
 *
 * ⚠️ ده **مش** موديول «طلب النواقص» (shortage.php / shortage_lib.php).
 *    الاتنين بيستخدموا كلمة «نواقص» بس شغلهم مختلف تماماً:
 *      - shortage.php      = موظف بيطلب صنف من فرع تاني (حركة يومية)
 *      - analysis.php (ده) = تحليل موسم كامل: مبيعات/أرصدة كل الفروع
 *                            وتصنيف كل صنف واقتراح التنقيلات
 *    الجداول (`shortage_batches` / `shortage_lines`) اسمها كده من
 *    `upgrade_shortage.sql` بتاع 2026-08-23 — سايبها زي ما هي عشان
 *    ماغيّرش سكيما شغّالة، بس ملفات الـPHP اسمها `analysis*` عشان
 *    الفرق يبان.
 *
 * المصدر دلوقتي: رفع ملفات TXT يدوي (تقرير الصادر والوارد من ReduxSalesPUB2).
 * بعدين: الوكلاء يملّوا نفس الجدول — التقرير مش هيتغير سطر.
 */

require_once __DIR__ . '/auth.php';

// =====================================================================
//  ثوابت
// =====================================================================

/** الحقول اللي بنقراها من ملف الـTXT. القيمة = عمود الجدول. */
const AN_FIELDS = [
    'barcode'   => 'كود الصنف',
    'item_name' => 'اسم الصنف',
    'balance'   => 'الرصيد',
    'out_qty'   => 'صافى مبيعات الكميه',
    'in_qty'    => 'صافى مشتريات الكميه',
    'in_cost'   => 'صافى مشتريات سعر-شراء',
    'in_sale'   => 'صافى مشتريات سعر-بيع',
];

/** الحقول اللي من غيرها الملف مالوش لازمة. */
const AN_REQUIRED_FIELDS = ['barcode', 'balance', 'out_qty'];

/** أقصى عدد صفوف بتتعرض في الصفحة (الباقي في التصدير). */
const AN_MAX_ROWS = 3000;

/** كل الحالات المحتملة — الترتيب ده هو ترتيب الكروت. */
const AN_STATUSES = ['ناقص', 'راكد', 'راكد على مستوى الفروع', 'يرجى النظر في الأمر', 'تمام'];

// =====================================================================
//  (١) فحص الجاهزية
// =====================================================================

/**
 * الجدولين موجودين؟ (نفس أسلوب books_table_ready — من غير ما نفترض).
 */
function an_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $n = 0;
    // استعلام خفيف بدل SHOW TABLES — أسماء الجداول ثابتة في الكود
    // فمفيش حقن، والطريقة دي بتشتغل على أي قاعدة.
    foreach (['shortage_batches', 'shortage_lines'] as $t) {
        try {
            db()->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
            $n++;
        } catch (Throwable $e) {
            // الجدول مش موجود
        }
    }
    $ok = ($n === 2);
    return $ok;
}

// =====================================================================
//  (٢) تطبيع النصوص والأرقام
// =====================================================================

/**
 * تطبيع عنوان عمود عربي عشان المقارنة تنجح مهما اختلف الشكل.
 * بيشيل التشكيل والمسافات والشرط، ويوحّد أ/إ/آ و ى/ي و ة/ه.
 *
 * «صافى مشتريات سعر-شراء» و «صافي المشتريات سعر الشراء» → نفس الناتج.
 */
function an_norm_ar(string $s): string
{
    // شيل التشكيل والتطويل
    $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = str_replace(
        ['أ', 'إ', 'آ', 'ٱ', 'ى', 'ة', 'ؤ', 'ئ', 'ء'],
        ['ا', 'ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي', ''],
        $s
    );
    $s = mb_strtolower($s, 'UTF-8');
    // سيب الحروف والأرقام بس
    $s = preg_replace('/[^\p{Arabic}\p{Latin}0-9]/u', '', $s);
    return (string) $s;
}

/**
 * قراءة رقم من خانة في الملف.
 * بيتعامل مع: أرقام عربية-هندية · فاصلة الآلاف · السالب بين قوسين ·
 * السالب في الآخر · الخانة الفاضية.
 */
function an_num($v): float
{
    $s = trim((string) $v);
    if ($s === '') return 0.0;

    // أرقام عربية-هندية وفارسية → لاتينية
    $s = strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => '',
    ]);

    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }   // (123)
    if (preg_match('/^(.*)-$/', $s, $m))    { $neg = true; $s = $m[1]; }   // 123-
    if (str_starts_with($s, '-'))           { $neg = true; $s = substr($s, 1); }

    // شيل أي حاجة مش رقم أو نقطة
    $s = preg_replace('/[^0-9.]/', '', $s);
    if ($s === '' || $s === '.') return 0.0;

    $n = (float) $s;
    return $neg ? -$n : $n;
}

// =====================================================================
//  (٣) قراءة الملف — الترميز والفاصل
// =====================================================================

/**
 * تحويل نص لـUTF-8 من ترميز معيّن.
 *
 * 🔴 مطب بيئة (اتكشف 2026-08-25): **`mb_convert_encoding` مابيعرفش
 *    `CP1256` خالص** — مش في `mb_list_encodings()`، وفي PHP 8 بيرمي
 *    `ValueError` يوقّف الصفحة كلها. وده الترميز اللي بونجور بيطلع بيه.
 *    فالترتيب: `iconv` الأول (بيدعمه)، ولو مش موجود نستخدم جدول
 *    البايتات اللي تحت. `mb_convert_encoding` لـUTF-16 بس.
 */
function an_to_utf8(string $raw, string $enc): string
{
    if ($enc === 'CP1256') {
        if (function_exists('iconv')) {
            $r = @iconv('CP1256', 'UTF-8//IGNORE', $raw);
            if ($r !== false && $r !== '') return $r;
        }
        return strtr($raw, an_cp1256_table());
    }
    return (string) mb_convert_encoding($raw, 'UTF-8', $enc);
}

/**
 * جدول CP1256 → UTF-8 للبايتات 0x80–0xFF.
 * خطة بديلة لو `iconv` مش متاح على السيرفر — أحسن من «؟؟؟».
 */
function an_cp1256_table(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $t = [
        "\x80" => '€',
        "\x81" => 'پ',
        "\x82" => '‚',
        "\x83" => 'ƒ',
        "\x84" => '„',
        "\x85" => '…',
        "\x86" => '†',
        "\x87" => '‡',
        "\x88" => 'ˆ',
        "\x89" => '‰',
        "\x8A" => 'ٹ',
        "\x8B" => '‹',
        "\x8C" => 'Œ',
        "\x8D" => 'چ',
        "\x8E" => 'ژ',
        "\x8F" => 'ڈ',
        "\x90" => 'گ',
        "\x91" => '‘',
        "\x92" => '’',
        "\x93" => '“',
        "\x94" => '”',
        "\x95" => '•',
        "\x96" => '–',
        "\x97" => '—',
        "\x98" => 'ک',
        "\x99" => '™',
        "\x9A" => 'ڑ',
        "\x9B" => '›',
        "\x9C" => 'œ',
        "\x9D" => '‌',
        "\x9E" => '‍',
        "\x9F" => 'ں',
        "\xA0" => ' ',
        "\xA1" => '،',
        "\xA2" => '¢',
        "\xA3" => '£',
        "\xA4" => '¤',
        "\xA5" => '¥',
        "\xA6" => '¦',
        "\xA7" => '§',
        "\xA8" => '¨',
        "\xA9" => '©',
        "\xAA" => 'ھ',
        "\xAB" => '«',
        "\xAC" => '¬',
        "\xAD" => '­',
        "\xAE" => '®',
        "\xAF" => '¯',
        "\xB0" => '°',
        "\xB1" => '±',
        "\xB2" => '²',
        "\xB3" => '³',
        "\xB4" => '´',
        "\xB5" => 'µ',
        "\xB6" => '¶',
        "\xB7" => '·',
        "\xB8" => '¸',
        "\xB9" => '¹',
        "\xBA" => '؛',
        "\xBB" => '»',
        "\xBC" => '¼',
        "\xBD" => '½',
        "\xBE" => '¾',
        "\xBF" => '؟',
        "\xC0" => 'ہ',
        "\xC1" => 'ء',
        "\xC2" => 'آ',
        "\xC3" => 'أ',
        "\xC4" => 'ؤ',
        "\xC5" => 'إ',
        "\xC6" => 'ئ',
        "\xC7" => 'ا',
        "\xC8" => 'ب',
        "\xC9" => 'ة',
        "\xCA" => 'ت',
        "\xCB" => 'ث',
        "\xCC" => 'ج',
        "\xCD" => 'ح',
        "\xCE" => 'خ',
        "\xCF" => 'د',
        "\xD0" => 'ذ',
        "\xD1" => 'ر',
        "\xD2" => 'ز',
        "\xD3" => 'س',
        "\xD4" => 'ش',
        "\xD5" => 'ص',
        "\xD6" => 'ض',
        "\xD7" => '×',
        "\xD8" => 'ط',
        "\xD9" => 'ظ',
        "\xDA" => 'ع',
        "\xDB" => 'غ',
        "\xDC" => 'ـ',
        "\xDD" => 'ف',
        "\xDE" => 'ق',
        "\xDF" => 'ك',
        "\xE0" => 'à',
        "\xE1" => 'ل',
        "\xE2" => 'â',
        "\xE3" => 'م',
        "\xE4" => 'ن',
        "\xE5" => 'ه',
        "\xE6" => 'و',
        "\xE7" => 'ç',
        "\xE8" => 'è',
        "\xE9" => 'é',
        "\xEA" => 'ê',
        "\xEB" => 'ë',
        "\xEC" => 'ى',
        "\xED" => 'ي',
        "\xEE" => 'î',
        "\xEF" => 'ï',
        "\xF0" => 'ً',
        "\xF1" => 'ٌ',
        "\xF2" => 'ٍ',
        "\xF3" => 'َ',
        "\xF4" => 'ô',
        "\xF5" => 'ُ',
        "\xF6" => 'ِ',
        "\xF7" => '÷',
        "\xF8" => 'ّ',
        "\xF9" => 'ù',
        "\xFA" => 'ْ',
        "\xFB" => 'û',
        "\xFC" => 'ü',
        "\xFD" => '‎',
        "\xFE" => '‏',
        "\xFF" => 'ے',
    ];
    return $t;
}

/**
 * قراءة ملف نصي وتحويله UTF-8 مهما كان ترميزه.
 *
 * ⚠️ بونجور بيطلع `cp1256` والباقي `utf-8` — لو افترضنا ترميز واحد
 *    أسامي أصناف بونجور هتطلع «؟؟؟». عشان كده الكشف تلقائي.
 *
 * @return array{text:string,encoding:string}
 */
function an_read_file(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('مش قادر أقرا الملف أو الملف فاضي.');
    }

    // (أ) علامة الترتيب (BOM)
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        return ['text' => substr($raw, 3), 'encoding' => 'UTF-8 (BOM)'];
    }
    if (str_starts_with($raw, "\xFF\xFE")) {
        return ['text' => an_to_utf8(substr($raw, 2), 'UTF-16LE'), 'encoding' => 'UTF-16LE'];
    }
    if (str_starts_with($raw, "\xFE\xFF")) {
        return ['text' => an_to_utf8(substr($raw, 2), 'UTF-16BE'), 'encoding' => 'UTF-16BE'];
    }

    // (ب) UTF-16 من غير BOM — بيبان من كتر بايتات الصفر
    $head  = substr($raw, 0, 400);
    $zeros = substr_count($head, "\x00");
    if ($zeros > strlen($head) / 4) {
        // بايت الصفر في المرتبة التانية = LE
        $le = (strlen($head) > 1 && $head[1] === "\x00");
        return [
            'text'     => an_to_utf8($raw, $le ? 'UTF-16LE' : 'UTF-16BE'),
            'encoding' => $le ? 'UTF-16LE' : 'UTF-16BE',
        ];
    }

    // (ج) UTF-8 سليم؟
    if (mb_check_encoding($raw, 'UTF-8')) {
        return ['text' => $raw, 'encoding' => 'UTF-8'];
    }

    // (د) الافتراضي للعربي على ويندوز
    return ['text' => an_to_utf8($raw, 'CP1256'), 'encoding' => 'CP1256'];
}

/**
 * تقسيم النص لصفوف وأعمدة، مع كشف الفاصل تلقائياً.
 *
 * @return array{delim:string,headers:array<int,string>,rows:array<int,array<int,string>>}
 */
function an_split(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
    if (!$lines) {
        throw new RuntimeException('الملف مفيهوش أي صفوف.');
    }

    // الفاصل: اللي بيتكرر أكتر في أول ٥ صفوف
    $probe = implode("\n", array_slice($lines, 0, 5));
    $best  = ''; $bestN = 0;
    foreach (["\t", ';', '|', ','] as $d) {
        $n = substr_count($probe, $d);
        if ($n > $bestN) { $bestN = $n; $best = $d; }
    }

    $split = $best !== ''
        ? static fn(string $l): array => explode($best, $l)
        : static fn(string $l): array => preg_split('/\s{2,}/u', trim($l));

    $rows = [];
    foreach ($lines as $l) {
        $rows[] = array_map(static fn($c) => trim((string) $c, " \t\"'\xC2\xA0"), $split($l));
    }

    $headers = array_shift($rows);
    return [
        'delim'   => $best === "\t" ? 'TAB' : ($best === '' ? 'مسافات' : $best),
        'headers' => $headers,
        'rows'    => $rows,
    ];
}

// =====================================================================
//  (٤) ربط الأعمدة
// =====================================================================

/**
 * المرادفات المقبولة لكل حقل (مطبّعة).
 * الترتيب مهم: الأطول والأدق الأول.
 */
function an_synonyms(): array
{
    static $m = null;
    if ($m !== null) return $m;

    $raw = [
        'barcode'   => ['كود الصنف', 'الكود', 'كود', 'باركود', 'رقم الصنف', 'item code', 'code', 'barcode'],
        'item_name' => ['اسم الصنف', 'الاسم', 'اسم', 'البيان', 'item name', 'name'],
        'balance'   => ['الرصيد', 'رصيد', 'الرصيد الحالى', 'رصيد اخر المده', 'الرصيد كميه', 'balance'],
        'out_qty'   => ['صافى مبيعات الكميه', 'صافى المبيعات الكميه', 'صافى مبيعات', 'صافى الصادر',
                        'مبيعات الكميه', 'المبيعات الكميه', 'الصادر', 'المبيعات'],
        'in_qty'    => ['صافى مشتريات الكميه', 'صافى المشتريات الكميه', 'صافى مشتريات', 'صافى الوارد',
                        'مشتريات الكميه', 'المشتريات الكميه', 'الوارد', 'المشتريات'],
        'in_cost'   => ['صافى مشتريات سعر-شراء', 'صافى مشتريات سعر شراء', 'صافى المشتريات سعر الشراء',
                        'مشتريات سعر شراء', 'قيمه المشتريات شراء'],
        'in_sale'   => ['صافى مشتريات سعر-بيع', 'صافى مشتريات سعر بيع', 'صافى المشتريات سعر البيع',
                        'مشتريات سعر بيع', 'قيمه المشتريات بيع'],
    ];

    $m = [];
    foreach ($raw as $f => $list) {
        $m[$f] = array_map('an_norm_ar', $list);
    }
    return $m;
}

/**
 * ربط تلقائي بين عناوين الملف وحقولنا.
 * مطابقة تامة الأول، وبعدين احتواء — والعمود مايتاخدش مرتين.
 *
 * @return array<string,int> field => column index
 */
function an_auto_map(array $headers): array
{
    $norm = [];
    foreach ($headers as $i => $h) $norm[$i] = an_norm_ar((string) $h);

    $syn  = an_synonyms();
    $map  = [];
    $used = [];

    // جولة ١ — مطابقة تامة
    //
    // ⚠️ الترتيب هنا **مرادف-الأول مش عمود-الأول**، وده مقصود:
    //    لو الملف فيه «المشتريات الكميه» في عمود ٢ و«صافى مشتريات
    //    الكميه» في عمود ٣، لازم ناخد الصافي. لو لفّينا على الأعمدة
    //    الأول، عمود ٢ هيخطف الحقل لأنه جه بدري — وده غلط في الأرقام
    //    كلها (المشتريات الخام مش الصافي).
    foreach ($syn as $field => $words) {
        foreach ($words as $w) {
            if ($w === '') continue;
            foreach ($norm as $i => $h) {
                if ($h === '' || isset($used[$i])) continue;
                if ($h === $w) {
                    $map[$field] = $i; $used[$i] = true;
                    continue 3;
                }
            }
        }
    }

    // جولة ٢ — احتواء (أطول مرادف الأول عشان «صافى مشتريات الكميه»
    //           ماتتخطفش بـ«المشتريات»)
    foreach ($syn as $field => $words) {
        if (isset($map[$field])) continue;
        usort($words, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) continue;   // كلمة قصيرة = احتواء خطر
            foreach ($norm as $i => $h) {
                if ($h === '' || isset($used[$i])) continue;
                if (str_contains($h, $w)) {
                    $map[$field] = $i; $used[$i] = true;
                    break 2;
                }
            }
        }
    }

    return $map;
}

/** الربط المحفوظ لمخزن معيّن (بيتخزن في settings — من غير جدول جديد). */
function an_map_get(int $stoId): array
{
    $raw = setting('shortage_map_' . $stoId, '');
    if (!$raw) return [];
    $j = json_decode((string) $raw, true);
    return is_array($j) ? array_map('intval', $j) : [];
}

/** حفظ الربط لمخزن معيّن. */
function an_map_set(int $stoId, array $map): void
{
    $clean = [];
    foreach ($map as $f => $i) {
        if (isset(AN_FIELDS[$f]) && $i !== '' && $i >= 0) $clean[$f] = (int) $i;
    }
    db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute(['shortage_map_' . $stoId, json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}

// =====================================================================
//  (٥) المخازن
// =====================================================================

/** رقم مخزن الإدارة (مشتريات بس — مش داخل في التصنيف). */
function an_hq_store(): int
{
    return (int) setting('shortage_hq_store', '1');
}

/** أرقام مخازن الفروع اللي بتدخل التصنيف. */
function an_branch_stores(): array
{
    $raw = (string) setting('shortage_branch_ids', '2,3,5,6');
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw)), static fn($v) => $v > 0));
    return $ids ?: [2, 3, 5, 6];
}

/**
 * كل المخازن اللي بنرفعلها ملفات: الفروع + الإدارة.
 *
 * @return array<int,array{sto_id:int,name:string,is_hq:bool}>
 */
function an_stores(): array
{
    $names = [];
    try {
        $st = db()->query('SELECT sto_id, name FROM branches WHERE sto_id IS NOT NULL');
        foreach ($st as $r) $names[(int) $r['sto_id']] = (string) $r['name'];
    } catch (Throwable $e) {
        // الجدول مش موجود؟ نكمّل بأسامي افتراضية
    }

    $hq  = an_hq_store();
    $out = [];
    foreach (an_branch_stores() as $id) {
        $out[$id] = ['sto_id' => $id, 'name' => $names[$id] ?? ('مخزن ' . $id), 'is_hq' => false];
    }
    // الإدارة مش في جدول branches عند محمد — فبنضيفها بإيدنا
    $out[$hq] = ['sto_id' => $hq, 'name' => $names[$hq] ?? 'الإدارة', 'is_hq' => true];

    return $out;
}

// =====================================================================
//  (٦) الرفعات
// =====================================================================

function an_batches(int $limit = 60): array
{
    $st = db()->prepare(
        'SELECT b.*,
                (SELECT COUNT(DISTINCT store_id) FROM shortage_lines WHERE batch_id = b.id) AS stores_in
         FROM shortage_batches b
         ORDER BY b.id DESC
         LIMIT ' . max(1, $limit)
    );
    $st->execute();
    return $st->fetchAll();
}

function an_batch(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM shortage_batches WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function an_batch_create(string $label, string $from, string $to, ?int $uid): int
{
    db()->prepare(
        'INSERT INTO shortage_batches (source, period_from, period_to, label, created_by)
         VALUES (\'manual\', ?, ?, ?, ?)'
    )->execute([$from, $to, mb_substr($label, 0, 120), $uid]);
    return (int) db()->lastInsertId();
}

/** المسح بيشيل البنود معاه (ON DELETE CASCADE). */
function an_batch_delete(int $id): void
{
    db()->prepare('DELETE FROM shortage_batches WHERE id = ?')->execute([$id]);
}

/** تحديث العدادات بعد أي رفع أو مسح — استعلام واحد، مش حلقة. */
function an_batch_recount(int $batchId): void
{
    db()->prepare(
        'UPDATE shortage_batches
            SET rows_count   = (SELECT COUNT(*)                 FROM shortage_lines
                                 WHERE batch_id = shortage_batches.id),
                stores_count = (SELECT COUNT(DISTINCT store_id) FROM shortage_lines
                                 WHERE batch_id = shortage_batches.id)
          WHERE id = ?'
    )->execute([$batchId]);
}

/** ملخّص المرفوع في رفعة: كل مخزن وعدد صفوفه. */
function an_batch_stores(int $batchId): array
{
    $st = db()->prepare(
        'SELECT store_id, COUNT(*) AS n,
                SUM(out_qty) AS sales, SUM(balance) AS bal
         FROM shortage_lines WHERE batch_id = ? GROUP BY store_id ORDER BY store_id'
    );
    $st->execute([$batchId]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['store_id']] = $r;
    return $out;
}

// =====================================================================
//  (٧) الاستيراد
// =====================================================================

/**
 * تخزين صفوف ملف في رفعة.
 * الرفع بيستبدل مخزنه بالكامل (مش بيتراكم) — رفعت دجلة تاني؟ القديم يروح.
 *
 * @param array<int,array<int,string>> $rows   صفوف الملف (من غير العناوين)
 * @param array<string,int>            $map    field => column index
 * @return array{stored:int,skipped:int,dupes:int}
 */
function an_import(int $batchId, int $stoId, array $rows, array $map): array
{
    foreach (AN_REQUIRED_FIELDS as $f) {
        if (!isset($map[$f])) {
            throw new RuntimeException('العمود «' . AN_FIELDS[$f] . '» مش متحدد.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // استبدال: امسح المخزن ده من الرفعة دي بالأول
        $pdo->prepare('DELETE FROM shortage_lines WHERE batch_id = ? AND store_id = ?')
            ->execute([$batchId, $stoId]);

        $get = static function (array $row, array $map, string $f) {
            return isset($map[$f]) ? ($row[$map[$f]] ?? '') : '';
        };

        $stored = 0; $skipped = 0; $seen = []; $dupes = 0;
        $buf = [];

        // ⚠️ مفيش استعلام جوه حلقة — بنجمّع ٥٠٠ صف وندخّلهم مرة واحدة
        $flush = static function (array &$buf) use ($pdo) {
            if (!$buf) return;
            $ph  = implode(',', array_fill(0, count($buf), '(?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO shortage_lines
                        (batch_id, store_id, barcode, item_name, balance, in_qty, in_cost, in_sale, out_qty)
                    VALUES ' . $ph . '
                    ON DUPLICATE KEY UPDATE
                        item_name = VALUES(item_name), balance = VALUES(balance),
                        in_qty = VALUES(in_qty), in_cost = VALUES(in_cost),
                        in_sale = VALUES(in_sale), out_qty = VALUES(out_qty)';
            $flat = [];
            foreach ($buf as $r) foreach ($r as $v) $flat[] = $v;
            $pdo->prepare($sql)->execute($flat);
            $buf = [];
        };

        foreach ($rows as $row) {
            $code = trim((string) $get($row, $map, 'barcode'));
            // ⚠️ مفيش فلتر شكل على الكود — الموقع عنده أكواد بحروف
            //    (easy · PRIMA · أكواد بنجمة). الفلتر القديم رفض ٩٤٤ كود حقيقي.
            if ($code === '') { $skipped++; continue; }

            if (isset($seen[$code])) { $dupes++; }
            $seen[$code] = true;

            $buf[] = [
                $batchId,
                $stoId,
                mb_substr($code, 0, 64),
                mb_substr(trim((string) $get($row, $map, 'item_name')), 0, 255) ?: null,
                an_num($get($row, $map, 'balance')),
                an_num($get($row, $map, 'in_qty')),
                an_num($get($row, $map, 'in_cost')),
                an_num($get($row, $map, 'in_sale')),
                an_num($get($row, $map, 'out_qty')),
            ];
            $stored++;

            if (count($buf) >= 500) $flush($buf);
        }
        $flush($buf);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    an_batch_recount($batchId);
    return ['stored' => $stored, 'skipped' => $skipped, 'dupes' => $dupes];
}

// =====================================================================
//  (٨) التصنيف — ترجمة حرفية لماكرو «تحليل_الاصناف»
// =====================================================================

/**
 * المطلوب لفرع واحد.
 *
 * 🔴 القاعدة دي محتاجة تأكيد من محمد. المتّبع في شيت التحليل:
 *        المطلوب = المبيعات − الرصيد
 *    (بعت ١٠ وعندك ٣ → محتاج ٧ · بعت ٢ وعندك ٢٠ → −١٨ يعني راكد)
 *    لو الصيغة عندك مختلفة، السطر ده هو المكان الوحيد اللي يتغيّر.
 */
function an_required(float $sales, float $balance): float
{
    return $sales - $balance;
}

/**
 * تصنيف صنف + اقتراح التنقيلات بين الفروع.
 *
 * ⚠️ ترجمة حرفية للماكرو (اتحققت ٩٨.٦٪ على ٥١١٠ صف مصنّفة بإيد محمد).
 *    قصدياً **من غير** التعديلات اللي كنت اقترحتها:
 *      - التنقيلات الصغيرة (قطعتين) سايبينها — التحليل موسمي مش يومي،
 *        فنقل قطعتين ده تصفية مخزون بايت وله معنى.
 *      - «يرجى النظر» سايبينها زي ما هي — دي بتمسك الصنف اللي قاعد
 *        سنة ومادخلش الفرع، وده تنبيه حقيقي.
 *    التعديل الوحيد المضاف: عمود «الأولوية» (كمية × تكلفة) للفرز بالفلوس
 *    — بيتحسب بره الدالة دي فمابيأثرش على التصنيف.
 *
 * @param array<int,float> $mat المطلوب لكل فرع
 * @param array<int,float> $mab المبيعات لكل فرع
 * @param array<int,float> $ras الرصيد لكل فرع
 * @param array<int,string> $names أسامي الفروع (لنص التنقيلة)
 * @return array{status:string,transfers:array<int,string>}
 */
function an_classify(array $mat, array $mab, array $ras, array $names, float $dosta = 12.0): array
{
    $n = count($mat);
    $transfers = array_fill(0, $n, '');

    $allNeg  = true; $allZero = true; $allPos = true;
    for ($j = 0; $j < $n; $j++) {
        if ($mat[$j] >= 0) $allNeg  = false;
        if ($mat[$j] != 0) $allZero = false;
        if ($mat[$j] <= 0) $allPos  = false;
    }

    if ($n === 0)  return ['status' => '', 'transfers' => $transfers];
    if ($allNeg)   return ['status' => 'راكد على مستوى الفروع', 'transfers' => $transfers];

    if ($allZero) {
        for ($j = 0; $j < $n; $j++) {
            if ($mab[$j] == 0 && $ras[$j] == 0) {
                return ['status' => 'يرجى النظر في الأمر', 'transfers' => $transfers];
            }
        }
        return ['status' => 'تمام', 'transfers' => $transfers];
    }

    if ($allPos) return ['status' => 'ناقص', 'transfers' => $transfers];

    // ===== مختلط: فيه فايض وفيه محتاج =====
    $surplus = []; $surIdx = []; $needed = []; $needIdx = [];
    for ($j = 0; $j < $n; $j++) {
        if ($mat[$j] < 0) {
            $surplus[] = abs($mat[$j]); $surIdx[] = $j;
        } elseif ($mat[$j] > 0) {
            $needed[] = $mat[$j];  $needIdx[] = $j;
        } elseif ($mab[$j] == 0 && $ras[$j] == 0) {
            // الصنف مادخلش الفرع ده خالص → دستة تجربة
            $needed[] = $dosta; $needIdx[] = $j;
        }
    }

    // ترتيب تنازلي (نفس الترتيب اللي في الماكرو)
    array_multisort($surplus, SORT_DESC, SORT_NUMERIC, $surIdx);
    array_multisort($needed,  SORT_DESC, SORT_NUMERIC, $needIdx);

    $rs = $surplus; $rn = $needed;
    $si = 0; $ni = 0;
    $guard = 0;
    while ($si < count($rs) && $ni < count($rn) && $guard++ < 1000) {
        $amt = min($rs[$si], $rn[$ni]);
        if ($amt > 0) {
            $f = $surIdx[$si]; $t = $needIdx[$ni];
            $piece = an_fmt_qty($amt) . 'ق لـ' . ($names[$t] ?? ('فرع ' . $t));
            $transfers[$f] = $transfers[$f] === '' ? $piece : $transfers[$f] . '، ' . $piece;
            $rs[$si] -= $amt; $rn[$ni] -= $amt;
        }
        if ($rs[$si] == 0) $si++;
        if ($ni < count($rn) && $rn[$ni] == 0) $ni++;
    }

    // المستهلك بقى صفر، فمجموع المصفوفة كلها = المتبقي (زي الماكرو بالظبط)
    $totS = array_sum($rs);
    $totN = array_sum($rn);

    if ($totS == 0 && $totN == 0) return ['status' => 'تمام', 'transfers' => $transfers];
    if ($totN > 0)  return ['status' => ($totN < $dosta ? 'تمام' : 'ناقص'), 'transfers' => $transfers];
    if ($totS > 0)  return ['status' => ($totS < $dosta ? 'تمام' : 'راكد'), 'transfers' => $transfers];

    return ['status' => '', 'transfers' => $transfers];
}

/** رقم من غير أصفار زايدة — ١٢ مش ١٢.٠٠٠ */
function an_fmt_qty(float $v): string
{
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
}

// =====================================================================
//  (٩) الأقسام
// =====================================================================

/**
 * أسامي الأقسام اللي فيها أصناف فعلاً في الرفعة دي.
 * @return array<int,string> is1_id => name
 */
function an_cats(int $batchId): array
{
    $sql = 'SELECT cn.is1_id, cn.name, COUNT(DISTINCT l.barcode) AS n
              FROM shortage_lines l
              JOIN hq_codes c      ON c.code = l.barcode
              JOIN hq_item_cats ic ON ic.item_id = c.item_id
              JOIN hq_cat_names cn ON cn.is1_id = ic.is1_id
             WHERE l.batch_id = ?
             GROUP BY cn.is1_id, cn.name
             ORDER BY cn.name';
    try {
        $st = db()->prepare($sql);
        $st->execute([$batchId]);
    } catch (Throwable $e) {
        // تصادم ترتيب المقارنة بين الجدولين — نجرّب بـCOLLATE صريح
        if (!str_contains($e->getMessage(), 'collation')) return [];
        $st = db()->prepare(str_replace(
            'c.code = l.barcode',
            'c.code = l.barcode COLLATE utf8mb4_unicode_ci',
            $sql
        ));
        $st->execute([$batchId]);
    }
    $out = [];
    foreach ($st as $r) $out[(int) $r['is1_id']] = $r['name'] . ' (' . (int) $r['n'] . ')';
    return $out;
}

// =====================================================================
//  (١٠) بناء التقرير
// =====================================================================

/**
 * الجدول المجمّع: صف لكل صنف، وجنبه كل الفروع.
 *
 * ⚠️ استعلام واحد مجمّع — مفيش استعلام لكل صنف.
 *    ١٢ ألف صنف × ٥ فروع = ٦٠ ألف صف بيترجعوا في GROUP BY واحد.
 *
 * @return array{rows:array,stores:array,truncated:bool,total:int}
 */
function an_report(int $batchId, int $catId = 0, bool $withStatus = true, int $limit = 0): array
{
    $stores  = an_stores();
    $brIds   = an_branch_stores();
    $hqId    = an_hq_store();
    $dosta   = (float) setting('shortage_dosta', '12');

    // أعمدة المبيعات/الرصيد لكل مخزن — بتتبني من قائمة المخازن
    $sel = []; $args = [];
    foreach (array_keys($stores) as $sid) {
        $sel[] = 'SUM(CASE WHEN store_id = ? THEN out_qty ELSE 0 END) AS s_' . $sid;
        $sel[] = 'SUM(CASE WHEN store_id = ? THEN balance ELSE 0 END) AS b_' . $sid;
        $sel[] = 'SUM(CASE WHEN store_id = ? THEN in_qty  ELSE 0 END) AS p_' . $sid;
        $args[] = $sid; $args[] = $sid; $args[] = $sid;
    }
    $args[] = $batchId;

    $inner = 'SELECT barcode, MAX(item_name) AS item_name, ' . implode(', ', $sel) . '
                FROM shortage_lines WHERE batch_id = ? GROUP BY barcode';

    $where = $catId > 0 ? ' WHERE ic.is1_id = ?' : '';
    if ($catId > 0) $args[] = $catId;

    $sql = 'SELECT t.*, c.item_id, c.cost AS cost, c.name AS hq_name, cn.name AS cat_name
              FROM (' . $inner . ') t
              LEFT JOIN hq_codes c      ON c.code = t.barcode
              LEFT JOIN hq_item_cats ic ON ic.item_id = c.item_id
              LEFT JOIN hq_cat_names cn ON cn.is1_id = ic.is1_id'
            . $where . ' ORDER BY t.barcode';

    try {
        $st = db()->prepare($sql);
        $st->execute($args);
    } catch (Throwable $e) {
        if (!str_contains(strtolower($e->getMessage()), 'collation')) throw $e;
        $st = db()->prepare(str_replace(
            'c.code = t.barcode',
            'c.code = t.barcode COLLATE utf8mb4_unicode_ci',
            $sql
        ));
        $st->execute($args);
    }

    $names = [];
    foreach ($brIds as $sid) $names[] = $stores[$sid]['name'] ?? ('فرع ' . $sid);

    $rows = [];
    $tot  = 0;
    $cap  = $limit > 0 ? $limit : AN_MAX_ROWS;

    foreach ($st as $r) {
        $tot++;
        if (count($rows) >= $cap) continue;   // بنعدّ الكل بس بنعرض المسموح

        $mab = []; $ras = []; $mat = [];
        foreach ($brIds as $sid) {
            $s = (float) ($r['s_' . $sid] ?? 0);
            $b = (float) ($r['b_' . $sid] ?? 0);
            $mab[] = $s; $ras[] = $b; $mat[] = an_required($s, $b);
        }

        $status = ''; $tr = array_fill(0, count($brIds), '');
        if ($withStatus) {
            $c = an_classify($mat, $mab, $ras, $names, $dosta);
            $status = $c['status']; $tr = $c['transfers'];
        }

        $sumSales = array_sum($mab);
        $sumBal   = array_sum($ras);
        $sumReq   = an_required($sumSales, $sumBal);
        $cost     = (float) ($r['cost'] ?? 0);

        $rows[] = [
            'barcode'   => $r['barcode'],
            'name'      => $r['item_name'] ?: ($r['hq_name'] ?? ''),
            'cat'       => $r['cat_name'] ?? '',
            'sales'     => $mab,
            'bal'       => $ras,
            'req'       => $mat,
            'hq_in'     => (float) ($r['p_' . $hqId] ?? 0),
            'sum_sales' => $sumSales,
            'sum_bal'   => $sumBal,
            'sum_req'   => $sumReq,
            'cost'      => $cost,
            'status'    => $status,
            'transfers' => $tr,
            // الأولوية بالفلوس مش بالعدد — صنف محتاج ٥ قطع بـ٢٠٠ج
            // أهم من صنف محتاج ٥٠ قطعة بـ٢ج
            'priority'  => round(abs($sumReq) * $cost, 2),
        ];
    }

    return [
        'rows'      => $rows,
        'stores'    => $brIds,
        'names'     => $names,
        'hq'        => $hqId,
        'hq_name'   => $stores[$hqId]['name'] ?? 'الإدارة',
        'total'     => $tot,
        'truncated' => $tot > count($rows),
    ];
}

/** إحصائية سريعة للكروت. */
function an_summary(array $rows): array
{
    $out = ['count' => count($rows)];
    foreach (AN_STATUSES as $s) $out[$s] = ['n' => 0, 'val' => 0.0];
    $out['buy_val'] = 0.0;
    $out['dead_val'] = 0.0;

    foreach ($rows as $r) {
        $s = $r['status'];
        if ($s !== '' && isset($out[$s])) {
            $out[$s]['n']++;
            $out[$s]['val'] += $r['priority'];
        }
        if ($s === 'ناقص')  $out['buy_val']  += max($r['sum_req'], 0) * $r['cost'];
        if ($s === 'راكد' || $s === 'راكد على مستوى الفروع') {
            $out['dead_val'] += $r['sum_bal'] * $r['cost'];
        }
    }
    return $out;
}
