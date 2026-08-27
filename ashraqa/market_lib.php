<?php
/**
 * market_lib.php — أسعار السوق للكتب (سحب + مطابقة + ربط)
 * نظام الفجالة ستور المالي — 2026-08-11
 *
 * =====================================================================
 *  المصدر
 * =====================================================================
 *  متجر منافس شغال WooCommerce، وبيوفّر Store API عام من غير مفاتيح:
 *      /wp-json/wc/store/v1/products?per_page=100&page=N
 *  بيرجّع JSON فيه id + name + prices + permalink.
 *
 *  ⚠️ الأسعار في الـAPI بالوحدة الصغرى كنص:
 *      prices.price = "15900" و currency_minor_unit = 2  →  159.00
 *
 * =====================================================================
 *  ليه الربط اليدوي؟
 * =====================================================================
 *  مفيش عندهم باركود ولا SKU — الاسم بس. ومطابقة الأسماء العربية
 *  عمرها ما هتوصل ١٠٠٪. فالنظام **بيقترح** والمستخدم **بيأكّد**،
 *  والتأكيد بيتخزّن في market_links فالمقارنة بعد كده مضبوطة للأبد.
 *
 *  الربط بالـ ITEM_ID مش بالباركود — نفس قاعدة book_lib.php:
 *  الصنف الواحد ليه أكتر من باركود وكلهم بنفس الـ ITEM_ID.
 *
 * =====================================================================
 *  أدب التعامل مع سيرفر الغير
 * =====================================================================
 *  السحب **بضغطة زرار بس**، مفيش أي مؤقّت ولا كرون. وفيه مهلة
 *  إجبارية بين السحبة والتانية (MARKET_MIN_GAP). إحنا اتبهدلنا من
 *  بان هوستنجر بسبب طلبات كتير — مش هنعمل نفس الحاجة في حد تاني.
 * =====================================================================
 */
require_once __DIR__ . '/config.php';

const MARKET_BASE     = 'https://sadat.khdraa.com';
const MARKET_ENDPOINT = '/wp-json/wc/store/v1/products';
const MARKET_PER_PAGE = 100;
const MARKET_MAX_PAGE = 60;      // سقف أمان = 6000 منتج
const MARKET_MIN_GAP  = 900;     // 15 دقيقة بين السحبة والتانية
const MARKET_TIMEOUT  = 20;

// عتبات الاقتراح
const MARKET_STRONG = 0.85;      // مطابقة قوية
const MARKET_MAYBE  = 0.55;      // محتملة — محتاجة عين

// الغموض في الربط الجماعي
//
// ⚠️ كان فيه بق هنا: الشرط القديم كان `$opts[1]['score'] >= $min - 0.05`
// يعني أي اقتراح تاني فوق 0.80 بيخلّي النظام يسيب الصنف — **حتى لو
// الأول 1.0 تطابق كامل**. فكانت أصناف مظبوطة ١٠٠٪ بتتساب من غير ربط.
//
// الصح: العبرة بالـ**فجوة** بين الأول والتاني مش برقم التاني لوحده.
//   أول 1.00 وتاني 0.80 → فجوة 0.20 → واضح، اربطه
//   أول 1.00 وتاني 0.95 → فجوة 0.05 → صنفين شبه بعض، سيبه لمحمد
const MARKET_AMBIG_MIN = 0.80;   // التاني لازم يكون قوي كفاية عشان يلخبط
const MARKET_AMBIG_GAP = 0.10;   // وقريب من الأول بأقل من كده
const MARKET_EXACT     = 0.999;  // تطابق كامل (0.999 مش 1.0 بسبب الفواصل العشرية)

/**
 * الاقتراحات غامضة؟ (اتنين قويين ومتقاربين = مش واضح مين الصح)
 *
 * **التطابق الكامل بياخد الأولوية دايماً** — لو الأول 100%، بنربط عليه
 * مهما كانت درجة التاني، حتى لو التاني 100% كمان.
 *
 * القرار بالصنف مش بالسعر (محمد 2026-08-19): إحنا بنربط عشان نعرف
 * السعر، فاختلاف السعر مايصحّش يكون سبب إننا مانربطش. طول ما الاسم
 * متطابق ١٠٠٪، ده نفس الصنف.
 *
 * حالات التعادل التام بتتعدّ في `exact_ties` وبتتعرض لمحمد يراجعها،
 * بدل ما تتساب من غير ربط.
 *
 * @param array $opts مرتّبة تنازلياً بالدرجة
 */
function mk_ambiguous(array $opts, float $min = MARKET_STRONG): bool
{
    if (count($opts) < 2) return false;
    $top    = (float) $opts[0]['score'];
    $second = (float) $opts[1]['score'];

    if ($top < $min) return false;                    // مش هيتربط أصلاً
    if ($top >= MARKET_EXACT) return false;           // تطابق كامل = اربط وخلاص
    if ($second < MARKET_AMBIG_MIN) return false;     // التاني ضعيف — واضح
    return ($top - $second) < MARKET_AMBIG_GAP;       // متقاربين = غامض
}

/** تعادل تام: أكتر من منتج عندهم بنفس الاسم المطبّع 100% */
function mk_exact_tie(array $opts): bool
{
    return count($opts) >= 2
        && (float) $opts[0]['score'] >= MARKET_EXACT
        && (float) $opts[1]['score'] >= MARKET_EXACT;
}

/**
 * كام منتج مخزّن بتطبيع قديم؟
 *
 * ⚠️ ليه ده موجود: `name_norm` بيتحسب **مرة واحدة وقت السحب** ويتخزّن.
 * فأول ما نغيّر `mk_norm()` (زي ما حصل لما ضفنا قاموس المرادفات)،
 * الـ6000 منتج المخزّنين بيفضلوا بالصيغة القديمة — وكتبنا بتتطبّع
 * بالجديدة وقت المطابقة. النتيجة: المطابقة تبقى **أسوأ** من الأول.
 *
 * الحل من غير migration ولا عمود نسخة: نقارن المخزّن بالمحسوب.
 *
 * @return array [stale, checked]
 */
function mk_norm_stale(int $sample = 400): array
{
    if (!mk_ready()) return [0, 0];
    $stale = 0; $n = 0;
    $st = db()->prepare('SELECT name, name_norm FROM market_products LIMIT ?');
    $st->bindValue(1, $sample, PDO::PARAM_INT);
    $st->execute();
    foreach ($st as $r) {
        $n++;
        if (mk_norm_text((string) $r['name']) !== (string) $r['name_norm']) $stale++;
    }
    return [$stale, $n];
}

/**
 * إعادة حساب name_norm لكل المنتجات المخزّنة.
 * مابيلمسش الأسعار ولا الروابط المؤكّدة (market_links مربوطة
 * بالـitem_id مش بالاسم) — بيحدّث عمود التطبيع بس.
 */
function mk_renorm_all(): int
{
    if (!mk_ready()) return 0;
    $pdo = db();
    $rows = $pdo->query('SELECT market_id, name, name_norm FROM market_products')->fetchAll();
    if (!$rows) return 0;

    $upd = $pdo->prepare('UPDATE market_products SET name_norm = ? WHERE market_id = ?');
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $fresh = mk_norm_text((string) $r['name']);
            if ($fresh === (string) $r['name_norm']) continue;
            $upd->execute([$fresh, (int) $r['market_id']]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $n;
}

/** الجداول اتعملت؟ */
function mk_ready(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    try { db()->query('SELECT 1 FROM market_products LIMIT 1'); $r = true; }
    catch (Throwable $ex) { $r = false; }
    return $r;
}

// =====================================================================
//  (١) توحيد الأسماء العربية
// =====================================================================

/**
 * قاموس المرادفات — اسمنا ↔ اسمهم.
 *
 * ⚠️ ده مش قاموس عام. ده تحديداً الفروق بين تسميتنا وتسمية المتجر
 * المنافس، وكلها متأكَّدة من محمد (2026-08-19).
 *
 * المبدأ: **الطرفين بيتحوّلوا لنفس الصيغة القياسية** — مش استبدال
 * في اتجاه واحد. فـ«كونكت» عندنا و«انجليزي» عندهم الاتنين بيبقوا
 * «انجليزي»، ويتقابلوا.
 *
 * الأعداد الترتيبية → أرقام: عندهم الصف بيتكتب رقم دايماً، وعندنا
 * بيتكتب «الصف الرابع الابتدائي». التوحيد لرقم بيخلي mk_features()
 * تقدر تطلّع الصف من الطرفين — وده مهم لأن الصف المختلف بيهبّط
 * الدرجة بعنف.
 *
 * ملحوظة إملائية مهمة: القيم هنا **بعد** التطبيع الحرفي، مش قبله.
 * يعني «أسئلة» بتبقى «اسيله» (لأن ئ→ي)، و«رياضة» بتبقى «رياضه».
 * لو ضفت مرادف جديد، شغّل التطبيع على الكلمة الأول واستخدم الناتج.
 */
const MK_SYN = [
    // ── مواد ──────────────────────────────────────────────
    'كونكت'   => 'انجليزي',      // كل المراحل: 1-6ب · 1-3ع · 1-3ث
    'connect' => 'انجليزي',
    'مواد'    => 'دراسات',        // مواد اجتماعية = دراسات
    'رياضه'   => 'رياضيات',       // مفيش «تربية رياضية» بينزل أصلاً
    'كمبيوتر' => 'حاسب',
    'حاسوب'   => 'حاسب',

    // ── فروق إملائية ──────────────────────────────────────
    'بلص'  => 'بلس',
    'plus' => 'بلس',

    // ── الأعداد الترتيبية → أرقام (مذكر ومؤنث) ────────────
    'اول'    => '1',  'اولي'   => '1',
    'ثاني'   => '2',  'تاني'   => '2',  'ثانيه' => '2',  'تانيه' => '2',
    'ثالث'   => '3',  'تالت'   => '3',  'ثالثه' => '3',  'تالته' => '3',
    'رابع'   => '4',  'رابعه'  => '4',
    'خامس'   => '5',  'خامسه'  => '5',
    'سادس'   => '6',  'سادسه'  => '6',

    // ── المراحل → الاختصار ────────────────────────────────
    'ابتدايي' => 'ب',            // «ابتدائي» بعد التطبيع (ئ→ي)
    'اعدادي'  => 'ع',
    'ثانوي'   => 'ث',
];

/**
 * علامات فارقة — وجودها في اسم وغيابها في التاني = **منتج مختلف**،
 * مهما كان باقي الاسم متطابق.
 *
 * ليه دي مهمة: «كونكت» و«كونكت بلس» درجة تشابههم 0.67 بالمعادلة
 * العادية — فوق عتبة الاقتراح (0.55)، فالنظام كان هيقترح ربط غلط
 * بين كتابين سعرهم مختلف. نفس الحكاية في «علوم عربي» و«علوم لغات».
 */
const MK_MARKS = ['بلس', 'لغات'];

/**
 * نوع الكتاب — «الأضواء شرح» غير «الأضواء أسئلة».
 * لو الاتنين ليهم نوع وهما مختلفين، دول كتابين.
 * (لو واحد بس فيه النوع مابنعاقبش — الاسم ممكن يكون ناقص عندهم.)
 *
 * «تاسيس» و«بوكليت» و«ملحق» اتضافوا من عيّنة حقيقية من منتجاتهم
 * (2026-08-19): «التفوق أحياء 3 ثانوي كتاب التأسيس» كان بيتطابق مع
 * «التفوق أحياء 3 ثانوي» العادي بدرجة 0.969 — ربط تلقائي غلط.
 */
const MK_KINDS = ['شرح', 'اسيله', 'مراجعه', 'ملخص', 'مذكره', 'امتحانات',
                  'تاسيس', 'بوكليت', 'ملحق'];

/**
 * الناشرين — أخطر فاصل على الإطلاق.
 *
 * ⚠️ اتكشف من عيّنة حقيقية (2026-08-19): «الأضواء علوم 5 ابتدائي» ضد
 * «المعاصر علوم 5 ابتدائي» كانت بتطلع **0.913** — فوق عتبة الربط
 * التلقائي. كتابين مختلفين بسعرين مختلفين وكانوا هيتربطوا لوحدهم،
 * لأن كل حاجة متطابقة إلا اسم الناشر، والـDice بيشوفه كلمة من كذا كلمة.
 *
 * القاعدة: لو الاتنين ليهم ناشر معروف وهما مختلفين → مش نفس الكتاب.
 * لو واحد بس فيه ناشر، مابنعاقبش (الاسم عندنا ممكن يكون من غير ناشر).
 *
 * 📝 القايمة دي **ناقصة بالتأكيد** — دي المشهورين بس. زوّدها كل ما
 * تلاقي ناشر مش موجود. الكلمات هنا **بعد** التطبيع وبعد شيل «ال»:
 * «الأضواء» بتبقى «اضواء»، و«الامتحان» بتبقى «امتحان».
 * ملحوظة: «بيت العلوم» متحطّة بـ«بيت» بس، عشان «علوم» دي مادة.
 */
const MK_PUBS = [
    'معاصر', 'امتحان', 'اضواء', 'تفوق', 'شاطر', 'مبدع', 'وافي',
    'بكار', 'اتقان', 'ماستر', 'جيم', 'رايد', 'سلاح', 'بيت',
];

/**
 * المواد — أهم فاصل على الإطلاق.
 *
 * ⚠️ اتكشف من عيّنة حقيقية (2026-08-19): «الامتحان **دراسات** 4 ابتدائي
 * ترم 1» ضد «الامتحان **عربي** 4 ابتدائي ترم 1» كانت بتطلع **0.913**
 * — ربط تلقائي. نفس الناشر ونفس الصف ونفس الترم، والفرق الوحيد المادة.
 * وسعرهم عندهم 169 و205. كتابين مختلفين تماماً.
 *
 * القاعدة بالتقاطع مش بأول مادة: لو الاتنين فيهم مواد ومفيش أي مادة
 * مشتركة → مش نفس الكتاب. (عشان «جبر وهندسة» يطابق «هندسة».)
 *
 * الأسماء هنا **بعد** التطبيع وبعد المرادفات — يعني «رياضة» بقت
 * «رياضيات»، و«كونكت» بقت «انجليزي»، و«مواد» بقت «دراسات».
 * 📝 زوّد أي مادة ناقصة.
 */
const MK_SUBJ = [
    'عربي', 'انجليزي', 'رياضيات', 'علوم', 'دراسات', 'دين', 'حاسب',
    'فرنساوي', 'فرنسي', 'الماني', 'ايطالي', 'اسباني',
    'احياء', 'كيمياء', 'فيزياء', 'جيولوجيا',
    'تاريخ', 'جغرافيا', 'فلسفه', 'منطق', 'نفس', 'اجتماع',
    'اقتصاد', 'احصاء', 'جبر', 'هندسه', 'تفاضل', 'مثلثات',
    'ميكانيكا', 'استاتيكا', 'ديناميكا', 'وطنيه', 'خط',
];

/**
 * توحيد الاسم وتقطيعه لكلمات للمطابقة.
 *
 * بيعالج: التشكيل والتطويل · أ/إ/آ→ا · ى→ي · ة→ه · ؤ→و · ئ→ي
 * الأرقام الهندية → عادية · علامات الترقيم · «ال» التعريف ·
 * توحيد صيغ الترم (ت1 / ترم اول / الترم الأول → ترم 1) ·
 * فصل الرقم الملزوق بالحرف (1ب → 1 ب) · شيل سنة النشر (2027).
 *
 * @return string[] كلمات موحّدة
 */
function mk_norm(string $s): array
{
    $s = trim($s);
    if ($s === '') return [];

    // تشكيل + تطويل
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);

    // أرقام هندية وفارسية
    $s = strtr($s, [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
        '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4',
        '۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ]);

    // توحيد الحروف
    $s = preg_replace('/[أإآٱ]/u', 'ا', $s);
    $s = strtr($s, ['ى'=>'ي', 'ة'=>'ه', 'ؤ'=>'و', 'ئ'=>'ي']);

    // أي حاجة مش حرف ولا رقم → مسافة
    $s = preg_replace('/[^0-9a-zA-Z\x{0600}-\x{06FF}]+/u', ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    $s = mb_strtolower($s, 'UTF-8');

    // صيغ الترم والمرحلة
    // «ج1» زي «ت1» بالظبط — الاتنين معناهم الترم (اتأكد من محمد 2026-08-19)
    $s = preg_replace([
        '/\bالترم\s*الاول\b/u', '/\bترم\s*الاول\b/u', '/\bترم\s*اول\b/u',
        '/\bالترم\s*الثاني\b/u', '/\bترم\s*الثاني\b/u', '/\bترم\s*(تاني|ثاني)\b/u',
        '/\b[تج]\s*(\d)\b/u',
    ], [
        'ترم 1', 'ترم 1', 'ترم 1',
        'ترم 2', 'ترم 2', 'ترم 2',
        'ترم $1',
    ], $s);

    // ── مرادفات المتجر المنافس (عبارات) ──────────────────────────
    // لازم تتعمل على النص كامل قبل التقطيع لأنها أكتر من كلمة.
    $s = preg_replace([
        '/\bتربيه\s+(دينيه|اسلاميه)\b/u',   // تربية دينية → دين
        '/\bict\b/u',                        // ICT = حاسب لغات
    ], [
        'دين',
        'حاسب لغات',
    ], $s);

    // فصل الرقم الملزوق بحرف عربي: 1ب → 1 ب
    $s = preg_replace('/(\d)([\x{0600}-\x{06FF}])/u', '$1 $2', $s);
    $s = preg_replace('/([\x{0600}-\x{06FF}])(\d)/u', '$1 $2', $s);

    // سنة النشر مش مميّزة (2027 موجودة في كل كتاب عندهم)
    $s = preg_replace('/\b(19|20)\d\d\b/u', ' ', $s);

    $noise = ['و' => 1, 'كتاب' => 1, 'كتب' => 1, 'من' => 1, 'في' => 1, 'علي' => 1, 'صف' => 1];
    $out = [];
    foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        // «ال» التعريف
        if (mb_strlen($t, 'UTF-8') > 3 && mb_substr($t, 0, 2, 'UTF-8') === 'ال') {
            $t = mb_substr($t, 2, null, 'UTF-8');
        }
        // مرادفات الكلمة الواحدة
        if (isset(MK_SYN[$t])) $t = MK_SYN[$t];
        if ($t !== '' && !isset($noise[$t])) {
            // مرادف اتفكّ لأكتر من كلمة (ict → حاسب لغات)
            if (strpos($t, ' ') !== false) {
                foreach (explode(' ', $t) as $p) { if ($p !== '') $out[] = $p; }
            } else {
                $out[] = $t;
            }
        }
    }
    return $out;
}

/** نص موحّد (للتخزين والعرض) */
function mk_norm_text(string $s): string
{
    return implode(' ', mk_norm($s));
}

/**
 * استخراج (الصف، الترم) من الكلمات الموحّدة.
 * الصف = الرقم اللي جنب ابتدائي/اعدادي/ثانوي (أو جنب ب/ع/ث المختصرة).
 * دول أهم حاجة: «عربي 4» و«عربي 1» أسماءهم متطابقة تقريباً وهما
 * كتابين مختلفين تماماً.
 *
 * @return array [grade|null, term|null]
 */
function mk_features(array $toks): array
{
    $grade = null; $term = null;
    $pre   = ['ابتداي', 'اعداد', 'ثانو'];   // احتياطي لو التوحيد ما مسكش
    $short = ['ب' => 1, 'ع' => 1, 'ث' => 1];
    $n = count($toks);

    for ($i = 0; $i < $n; $i++) {
        $w = $toks[$i];

        $isStage = false;
        foreach ($pre as $p) {
            if (mb_strpos($w, $p, 0, 'UTF-8') === 0) { $isStage = true; break; }
        }
        // بعد التوحيد المرحلة بقت حرف واحد (ب/ع/ث) والصف رقم جنبه —
        // قبله («4 ب») أو بعده («ب 4»).
        $isShort = isset($short[$w]);

        if (($isStage || $isShort) && $grade === null) {
            foreach ([$i - 1, $i + 1] as $j) {
                if ($j >= 0 && $j < $n && ctype_digit($toks[$j]) && strlen($toks[$j]) === 1) {
                    $grade = $toks[$j];
                    break;
                }
            }
        }
        if ($w === 'ترم' && $i + 1 < $n && ctype_digit($toks[$i + 1])) {
            $term = $toks[$i + 1];
        }
    }

    // عندهم الصف بيتكتب رقم لوحده من غير مرحلة: «انجليزي 1».
    // من غير ده، مطابقة صف رابع بصف أول كانت بتطلع 0.75 — فوق عتبة
    // الاقتراح — لأن عقوبة «صف مختلف» مابتشتغلش غير لما الطرفين
    // عندهم صف معروف. فبناخد أول رقم مفرد (1-6) مش تابع لـ«ترم».
    if ($grade === null) {
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (strlen($t) !== 1 || !ctype_digit($t) || $t < '1' || $t > '6') continue;
            if ($i > 0 && $toks[$i - 1] === 'ترم') continue;   // ده رقم الترم
            $grade = $t;
            break;
        }
    }

    return [$grade, $term];
}

/**
 * درجة التشابه بين اسمين (0 → 1).
 * الأساس: معامل Dice على الكلمات.
 * التعديل: الصف أو الترم المختلف بيهبّط الدرجة بعنف — لأنه فعلاً
 * كتاب تاني مهما كان باقي الاسم متطابق.
 */
function mk_score(array $A, array $B): float
{
    if (!$A || !$B) return 0.0;
    $sa = array_unique($A);
    $sb = array_unique($B);
    $inter = count(array_intersect($sa, $sb));
    if ($inter === 0) return 0.0;

    $sc = 2 * $inter / (count($sa) + count($sb));

    [$ga, $ta] = mk_features($A);
    [$gb, $tb] = mk_features($B);

    if ($ga !== null && $gb !== null) {
        if ($ga !== $gb) $sc *= 0.25;        // صف مختلف
        else             $sc = min(1.0, $sc + 0.08);
    }
    if ($ta !== null && $tb !== null && $ta !== $tb) $sc *= 0.35;   // ترم مختلف

    // العلامات الفارقة: بلس · لغات
    // موجودة في واحد ومش موجودة في التاني = منتج تاني خالص.
    foreach (MK_MARKS as $m) {
        if (in_array($m, $sa, true) !== in_array($m, $sb, true)) $sc *= 0.2;
    }

    // نوع الكتاب: شرح ≠ أسئلة ≠ مراجعة ≠ تأسيس
    $ka = null; $kb = null;
    foreach (MK_KINDS as $k) {
        if ($ka === null && in_array($k, $sa, true)) $ka = $k;
        if ($kb === null && in_array($k, $sb, true)) $kb = $k;
    }
    if ($ka !== null && $kb !== null && $ka !== $kb) $sc *= 0.25;
    // واحد بس فيه نوع: مش مؤكد إنهم مختلفين — الاسم عندنا ممكن يكون
    // مختصر. عقوبة خفيفة تنزّله من «ربط تلقائي» لـ«اقتراح» عشان
    // محمد يبص عليه بعينه. («التفوق أحياء 3 ث» ضد نفسه + «كتاب
    // التأسيس» كانت 0.969 = ربط تلقائي.)
    elseif ($ka !== $kb) $sc *= 0.8;

    // الناشر: المعاصر ≠ الامتحان ≠ الأضواء — كتب مختلفة بأسعار مختلفة
    $pa = array_values(array_intersect(MK_PUBS, $sa));
    $pb = array_values(array_intersect(MK_PUBS, $sb));
    if ($pa && $pb && !array_intersect($pa, $pb)) $sc *= 0.15;

    // المادة: أقوى فاصل. بالتقاطع مش بأول مادة، عشان «جبر وهندسة»
    // يفضل يطابق «هندسة».
    $ja = array_values(array_intersect(MK_SUBJ, $sa));
    $jb = array_values(array_intersect(MK_SUBJ, $sb));
    if ($ja && $jb && !array_intersect($ja, $jb)) $sc *= 0.12;

    return round(max(0.0, min(1.0, $sc)), 3);
}

// =====================================================================
//  (٢) سحب الأسعار من المتجر
// =====================================================================

/** آخر سحبة ناجحة — بترجّع ['fetched_at','products'] أو null */
function mk_last_fetch(): ?array
{
    if (!mk_ready()) return null;
    try {
        $r = db()->query(
            "SELECT fetched_at, products FROM market_fetch_log
             WHERE status = 'ok' ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $r ?: null;
    } catch (Throwable $ex) { return null; }
}

/** فاضل كام ثانية على السماح بسحبة جديدة (0 = مسموح دلوقتي) */
function mk_cooldown(): int
{
    $last = mk_last_fetch();
    if (!$last) return 0;
    $gap = time() - strtotime((string) $last['fetched_at']);
    return $gap >= MARKET_MIN_GAP ? 0 : (MARKET_MIN_GAP - $gap);
}

/**
 * سحب كل المنتجات وتخزينها.
 * بيرجّع ['ok'=>bool, 'products'=>n, 'pages'=>n, 'error'=>string|null]
 */
function mk_fetch_all(?int $userId = null): array
{
    if (!mk_ready()) {
        return ['ok' => false, 'products' => 0, 'pages' => 0,
                'error' => 'الجداول لسه ما اتعملتش — شغّل upgrade_market_prices.sql.'];
    }
    $wait = mk_cooldown();
    if ($wait > 0) {
        return ['ok' => false, 'products' => 0, 'pages' => 0,
                'error' => 'استنى ' . ceil($wait / 60) . ' دقيقة كمان قبل سحبة جديدة.'];
    }

    $pdo = db();
    $ins = $pdo->prepare(
        'INSERT INTO market_products (market_id, name, name_norm, price, permalink, in_stock)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name), name_norm = VALUES(name_norm),
            price = VALUES(price), permalink = VALUES(permalink),
            in_stock = VALUES(in_stock)'
    );

    $total = 0; $page = 1; $err = null;

    while ($page <= MARKET_MAX_PAGE) {
        $url = MARKET_BASE . MARKET_ENDPOINT
             . '?per_page=' . MARKET_PER_PAGE . '&page=' . $page;

        $body = mk_http_get($url, $err);
        if ($body === null) break;

        $rows = json_decode($body, true);
        if (!is_array($rows)) { $err = 'رد غير مفهوم من المتجر (صفحة ' . $page . ').'; break; }
        if (!$rows) break;   // خلصت المنتجات

        foreach ($rows as $p) {
            if (!is_array($p)) continue;
            $id   = (int) ($p['id'] ?? 0);
            $name = trim((string) ($p['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;

            $ins->execute([
                $id,
                mb_substr($name, 0, 255),
                mb_substr(mk_norm_text($name), 0, 255),
                mk_price_of($p),
                mb_substr((string) ($p['permalink'] ?? ''), 0, 500),
                !empty($p['is_in_stock']) ? 1 : 0,
            ]);
            $total++;
        }

        if (count($rows) < MARKET_PER_PAGE) break;   // آخر صفحة
        $page++;
        usleep(300000);   // 0.3 ثانية بين الصفحات — ماننططش على سيرفرهم
    }

    $ok = ($total > 0 && $err === null);
    try {
        $pdo->prepare(
            'INSERT INTO market_fetch_log (products, status, note, fetched_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$total, $ok ? 'ok' : 'error', $err, $userId]);
    } catch (Throwable $ex) { /* اللوج مش حرج */ }

    return ['ok' => $ok, 'products' => $total, 'pages' => $page,
            'error' => $err ?: ($total ? null : 'مرجعش أي منتجات.')];
}

/** السعر من رد الـStore API (بالوحدة الصغرى كنص) */
function mk_price_of(array $p): float
{
    $pr = $p['prices'] ?? [];
    $raw = $pr['price'] ?? ($pr['regular_price'] ?? '0');
    $unit = (int) ($pr['currency_minor_unit'] ?? 2);
    $v = (float) $raw;
    return round($unit > 0 ? $v / (10 ** $unit) : $v, 2);
}

/** GET بسيط بـcURL (أو fopen احتياطي) */
function mk_http_get(string $url, ?string &$err): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => MARKET_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'FagalaStore-PriceCheck/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false)  { $err = 'فشل الاتصال: ' . $cerr; return null; }
        if ($code === 400)    { return '[]'; }   // صفحة بعد الآخر — عادي
        if ($code !== 200)    { $err = 'المتجر رجّع كود ' . $code; return null; }
        return (string) $body;
    }

    if (!ini_get('allow_url_fopen')) {
        $err = 'السيرفر مقفول عليه الاتصال الخارجي (مفيش cURL ولا allow_url_fopen).';
        return null;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => MARKET_TIMEOUT]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { $err = 'فشل الاتصال بالمتجر.'; return null; }
    return $body;
}

// =====================================================================
//  (٣) كتبنا + الاقتراحات
// =====================================================================

/** عدد منتجات السوق المخزّنة */
function mk_products_count(): int
{
    if (!mk_ready()) return 0;
    return (int) db()->query('SELECT COUNT(*) FROM market_products')->fetchColumn();
}

/** كل منتجات السوق مع كلماتها الموحّدة (للمطابقة في الذاكرة) */
function mk_load_products(): array
{
    if (!mk_ready()) return [];
    $out = [];
    foreach (db()->query('SELECT market_id, name, name_norm, price, permalink FROM market_products') as $r) {
        $toks = $r['name_norm'] !== ''
            ? preg_split('/\s+/u', (string) $r['name_norm'], -1, PREG_SPLIT_NO_EMPTY)
            : mk_norm((string) $r['name']);
        $out[(int) $r['market_id']] = [
            'market_id' => (int) $r['market_id'],
            'name'      => (string) $r['name'],
            'price'     => (float) $r['price'],
            'permalink' => (string) ($r['permalink'] ?? ''),
            'toks'      => $toks,
        ];
    }
    return $out;
}

/**
 * كتبنا من المرآة (تصنيف الكتب) — مجمّعة بالـITEM_ID.
 *
 * @param string $filter all | unlinked | linked | ignored
 */
function mk_our_books(string $filter = 'unlinked', string $term = '', int $limit = 60, int $offset = 0): array
{
    if (!mk_ready()) return ['rows' => [], 'total' => 0];
    require_once __DIR__ . '/book_lib.php';

    $pdo   = db();
    $catOn = books_table_ready('hq_item_cats');
    $args  = [];
    $where = ['1=1'];

    $join = $catOn ? 'JOIN hq_item_cats c ON c.item_id = i.item_id' : '';
    if ($catOn) { $where[] = 'c.is1_id = ?'; $args[] = books_cat_id(); }

    if ($term !== '') {
        foreach (array_slice(preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY), 0, 4) as $w) {
            $where[] = '(i.name LIKE ? OR i.barcode LIKE ?)';
            $args[] = '%' . $w . '%';
            $args[] = '%' . $w . '%';
        }
    }

    $having = '';
    if     ($filter === 'linked')   $having = 'HAVING lnk IS NOT NULL';
    elseif ($filter === 'unlinked') $having = 'HAVING lnk IS NULL AND ign IS NULL';
    elseif ($filter === 'ignored')  $having = 'HAVING ign IS NOT NULL';

    $sql = "SELECT i.item_id,
                   MIN(i.barcode) AS code,
                   MIN(i.name)    AS name,
                   MAX(i.sale_price) AS sale_price,
                   MAX(l.market_id)  AS lnk,
                   MAX(l.score)      AS lnk_score,
                   MAX(l.confirmed_at) AS lnk_at,
                   MAX(g.item_id)    AS ign
            FROM hq_items i
            $join
            LEFT JOIN market_links   l ON l.item_id = i.item_id
            LEFT JOIN market_ignored g ON g.item_id = i.item_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY i.item_id
            $having
            ORDER BY " . ($filter === 'linked' ? 'lnk_at DESC, name' : 'name');

    // العدد الكلي (نفس الاستعلام كجدول فرعي)
    $cs = $pdo->prepare("SELECT COUNT(*) FROM ($sql) x");
    $cs->execute($args);
    $total = (int) $cs->fetchColumn();

    $st = $pdo->prepare($sql . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'item_id'   => (int) $r['item_id'],
            'code'      => (string) $r['code'],
            'name'      => (string) ($r['name'] ?? ''),
            'sale'      => (float) $r['sale_price'],
            'linked'    => $r['lnk'] !== null ? (int) $r['lnk'] : null,
            'lnk_score' => $r['lnk_score'] !== null ? (float) $r['lnk_score'] : null,
            'ignored'   => $r['ign'] !== null,
        ];
    }
    return ['rows' => $rows, 'total' => $total];
}

/**
 * أفضل $top اقتراحات لكل صنف — مطابقة في الذاكرة بفهرس مقلوب.
 * من غير الفهرس ده هنقارن كل صنف بكل منتج (ملايين المقارنات).
 *
 * @param array $items  صفوف من mk_our_books()
 * @param array $prods  من mk_load_products()
 */
function mk_suggest(array $items, array $prods, int $top = 3): array
{
    if (!$items || !$prods) return [];

    // فهرس مقلوب: كلمة → [market_id, ...]
    $index = [];
    foreach ($prods as $mid => $p) {
        foreach (array_unique($p['toks']) as $t) $index[$t][] = $mid;
    }

    $out = [];
    foreach ($items as $it) {
        $A = mk_norm($it['name']);
        if (!$A) { $out[$it['item_id']] = []; continue; }

        // المرشّحين = اللي بيشاركوا في كلمة على الأقل
        $cand = [];
        foreach (array_unique($A) as $t) {
            if (!isset($index[$t])) continue;
            // كلمة موجودة في نص المنتجات = مش مميّزة، بنتخطاها
            if (count($index[$t]) > count($prods) * 0.5) continue;
            foreach ($index[$t] as $mid) $cand[$mid] = true;
        }

        $scored = [];
        foreach (array_keys($cand) as $mid) {
            $s = mk_score($A, $prods[$mid]['toks']);
            if ($s > 0.2) $scored[] = ['market_id' => $mid, 'score' => $s];
        }
        usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
        $out[$it['item_id']] = array_slice($scored, 0, $top);
    }
    return $out;
}

// =====================================================================
//  (٤) الربط
// =====================================================================

/** تأكيد ربط */
function mk_link(int $itemId, int $marketId, ?float $score = null, ?int $userId = null): bool
{
    if (!mk_ready() || $itemId <= 0 || $marketId <= 0) return false;
    db()->prepare(
        'INSERT INTO market_links (item_id, market_id, score, confirmed_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            market_id = VALUES(market_id), score = VALUES(score),
            confirmed_by = VALUES(confirmed_by)'
    )->execute([$itemId, $marketId, $score, $userId]);

    db()->prepare('DELETE FROM market_ignored WHERE item_id = ?')->execute([$itemId]);
    return true;
}

/** فك ربط */
function mk_unlink(int $itemId): bool
{
    if (!mk_ready()) return false;
    db()->prepare('DELETE FROM market_links WHERE item_id = ?')->execute([$itemId]);
    return true;
}

/** استبعاد صنف (مالوش مقابل عندهم) */
function mk_ignore(int $itemId, ?int $userId = null): bool
{
    if (!mk_ready() || $itemId <= 0) return false;
    db()->prepare(
        'INSERT INTO market_ignored (item_id, ignored_by) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE ignored_by = VALUES(ignored_by)'
    )->execute([$itemId, $userId]);
    db()->prepare('DELETE FROM market_links WHERE item_id = ?')->execute([$itemId]);
    return true;
}

/** إلغاء الاستبعاد */
function mk_unignore(int $itemId): bool
{
    if (!mk_ready()) return false;
    db()->prepare('DELETE FROM market_ignored WHERE item_id = ?')->execute([$itemId]);
    return true;
}

/**
 * ربط جماعي: كل الكتب المش مربوطة اللي أعلى اقتراح ليها >= $min.
 *
 * ده مش «ربط تلقائي» — ده اختصار لضغطة الـ«أكّد» اللي كنت هتعملها
 * ٧٠٠ مرة بإيدك. عشان كده:
 *   • بيشتغل على المطابقات القوية بس (افتراضي 0.85)
 *   • بيسجّل الدرجة في market_links عشان تعرف تراجعها بعدين
 *   • فلتر «مربوط» مرتّب بالأحدث — تمشي عليه وتفك أي غلطة
 *
 * ⚠️ الأصناف اللي فيها أكتر من اقتراح قوي **بتتساب** — دي بالظبط
 *    الحالات اللي محتاجة عينك (كتاب ترم 1 وترم 2 بنفس الاسم مثلاً).
 *
 * @param bool $preview لو true بيعدّ بس من غير ما يكتب حاجة
 * @return array ['count'=>n, 'skipped_ambiguous'=>n, 'scanned'=>n]
 */
function mk_bulk_link(float $min = MARKET_STRONG, ?int $userId = null, bool $preview = false): array
{
    $res = ['count' => 0, 'skipped_ambiguous' => 0, 'scanned' => 0, 'exact_ties' => 0];
    if (!mk_ready()) return $res;

    $prods = mk_load_products();
    if (!$prods) return $res;

    $pdo  = db();
    $link = $preview ? null : $pdo->prepare(
        'INSERT INTO market_links (item_id, market_id, score, confirmed_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE market_id = VALUES(market_id), score = VALUES(score)'
    );

    // بنقرا كل المش-مربوط **الأول** ثم نكتب — لو قرينا وكتبنا بالتبادل،
    // الصفوف اللي بتتربط بتخرج من النتيجة والـoffset بيتلخبط.
    $all = mk_our_books('unlinked', '', 20000, 0);
    $rows = $all['rows'];
    $res['scanned'] = count($rows);
    if (!$rows) return $res;

    foreach (array_chunk($rows, 300) as $batch) {
        $sugg = mk_suggest($batch, $prods, 2);
        foreach ($batch as $b) {
            $opts = $sugg[$b['item_id']] ?? [];
            if (!$opts || $opts[0]['score'] < $min) continue;

            // اقتراحين قويين و**متقاربين** = مش واضح، سيبه لعينك.
            // (الفجوة هي المعيار — مش رقم التاني لوحده. شوف mk_ambiguous.)
            if (mk_ambiguous($opts, $min)) {
                $res['skipped_ambiguous']++;
                continue;
            }
            // اتربط، بس فيه أكتر من منتج بنفس الاسم — يستاهل نظرة بعدين
            if (mk_exact_tie($opts)) $res['exact_ties']++;
            $res['count']++;
            if ($link) {
                $link->execute([$b['item_id'], $opts[0]['market_id'], $opts[0]['score'], $userId]);
            }
        }
    }
    return $res;
}

/** فك كل الروابط اللي اتعملت بالربط الجماعي فوق درجة معيّنة (تراجع سريع) */
function mk_bulk_unlink_since(string $since): int
{
    if (!mk_ready()) return 0;
    $st = db()->prepare('DELETE FROM market_links WHERE confirmed_at >= ?');
    $st->execute([$since]);
    return $st->rowCount();
}

/** بحث في منتجات السوق (للربط اليدوي لما الاقتراح يبوظ) */
function mk_search_products(string $term, int $limit = 20): array
{
    if (!mk_ready()) return [];
    $term = trim($term);
    if ($term === '') return [];

    $where = []; $args = [];
    foreach (array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY), 0, 4) as $w) {
        $where[] = '(name LIKE ? OR name_norm LIKE ?)';
        $args[]  = '%' . $w . '%';
        $args[]  = '%' . str_replace(['%','_'], '', implode(' ', mk_norm($w))) . '%';
    }
    if (!$where) return [];

    $st = db()->prepare(
        'SELECT market_id, name, price, permalink FROM market_products
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY name LIMIT ' . (int) $limit
    );
    $st->execute($args);
    return $st->fetchAll();
}

/** إحصائيات سريعة للشاشة */
function mk_stats(): array
{
    $out = ['products' => 0, 'linked' => 0, 'ignored' => 0];
    if (!mk_ready()) return $out;
    try {
        $out['products'] = mk_products_count();
        $out['linked']   = (int) db()->query('SELECT COUNT(*) FROM market_links')->fetchColumn();
        $out['ignored']  = (int) db()->query('SELECT COUNT(*) FROM market_ignored')->fetchColumn();
    } catch (Throwable $ex) { /* تجاهل */ }
    return $out;
}
