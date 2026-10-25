<?php
declare(strict_types=1);
/**
 * barcode_lib.php — مولّد باركود SVG من غير أي مكتبة خارجية (بريف ٢١٦)
 * نظام الفجالة ستور المالي
 *
 * بيدعم تلات أنواع، والاختيار تلقائي من شكل الكود:
 *   EAN-13   لو الكود ١٣ رقم و**الـcheck digit صح**
 *   EAN-8    لو الكود ٨ أرقام والـcheck digit صح
 *   Code 128 لو أي حاجة تانية (B، أو C لو كله أرقام وعدده زوجي)
 *
 * 🔴 ليه الـcheck digit بيتحسب الأول؟ لأن كود ١٣ رقم بـcheck digit غلط لو
 *    اتطبع EAN-13 السكانر بيرفضه (بيعتبره تالف). فبنطبعه Code 128 اللي
 *    بيشيل أي نص — بيمسح صح ويرجّع نفس الرقم.
 * 🔴 الأكواد القصيرة (زي «91») أكواد حقيقية مش خطأ — بتتطبع Code 128.
 *
 * الاستعمال:
 *   echo barcode_svg('5901234123457', 7.0, 0.3);   // ارتفاع ٧مم · الموديول ٠.٣مم
 *   $m = barcode_fit_module($code, 34.0);          // أوسع موديول يدخل في ٣٤مم
 *
 * الوحدات كلها بالمليمتر (مش بكسل) — الليبل بيتطبع على طابعة حرارية.
 */

/** جداول EAN: L (فردي) · G (زوجي) · R (يمين). ٧ وحدات لكل رقم. */
const BARCODE_EAN_L = [
    '0001101', '0011001', '0010011', '0111101', '0100011',
    '0110001', '0101111', '0111011', '0110111', '0001011',
];
const BARCODE_EAN_G = [
    '0100111', '0110011', '0011011', '0100001', '0011101',
    '0111001', '0000101', '0010001', '0001001', '0010111',
];
const BARCODE_EAN_R = [
    '1110010', '1100110', '1101100', '1000010', '1011100',
    '1001110', '1010000', '1000100', '1001000', '1110100',
];

/**
 * أول رقم في EAN-13 بيحدد تبادل L/G على الأرقام الستة الشمال (من المواصفة).
 */
const BARCODE_EAN13_PARITY = [
    'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
    'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
];

/**
 * أنماط Code 128 (عرض كل عنصر بالموديول) للقيم ٠–١٠٥.
 * ١٠٣/١٠٤/١٠٥ = Start A/B/C · ٩٩ = Code C · ١٠٠ = Code B · ١٠٢ = FNC1.
 * قيمة ١٠٦ (Stop) ليها ٧ عناصر فمكتوبة لوحدها في BARCODE_C128_STOP.
 * 🔴 أي تعديل هنا لازم يقابله اختبار في tests/local/test_barcode.php.
 */
const BARCODE_C128 = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232',
];
const BARCODE_C128_STOP = '2331112';

/** الهامش الأبيض حوالين الباركود (وحدات) — المواصفة بتطلب ١٠ على الأقل. */
const BARCODE_QUIET_MODULES = 10;

/** الـcheck digit بتاع EAN-13 من أول ١٢ رقم. */
function barcode_ean13_check_digit(string $digits12): int
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $digits12[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (10 - $sum % 10) % 10;
}

/** الـcheck digit بتاع EAN-8 من أول ٧ أرقام. */
function barcode_ean8_check_digit(string $digits7): int
{
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $sum += (int) $digits7[$i] * ($i % 2 === 0 ? 3 : 1);
    }
    return (10 - $sum % 10) % 10;
}

/** ١٣ رقم بالظبط والـcheck digit صح؟ */
function barcode_is_ean13(string $code): bool
{
    if (!preg_match('/^\d{13}$/', $code)) {
        return false;
    }
    return barcode_ean13_check_digit(substr($code, 0, 12)) === (int) $code[12];
}

/** ٨ أرقام بالظبط والـcheck digit صح؟ */
function barcode_is_ean8(string $code): bool
{
    if (!preg_match('/^\d{8}$/', $code)) {
        return false;
    }
    return barcode_ean8_check_digit(substr($code, 0, 7)) === (int) $code[7];
}

/** نوع الباركود اللي هيتطبع: ean13 · ean8 · code128. */
function barcode_kind(string $code): string
{
    if (barcode_is_ean13($code)) {
        return 'ean13';
    }
    if (barcode_is_ean8($code)) {
        return 'ean8';
    }
    return 'code128';
}

/** اسم النوع بالعربي للعرض في الشاشة. */
function barcode_kind_label(string $code): string
{
    return ['ean13' => 'EAN-13', 'ean8' => 'EAN-8', 'code128' => 'Code 128'][barcode_kind($code)];
}

/**
 * الكود ينفع يتطبع كباركود؟
 * Code 128-B بيدعم ASCII من ٣٢ لـ١٢٦ بس — الأكواد اللي فيها حروف عربية
 * (موجود ٦ في المرآة، داتا قديمة) مالهاش ترميز، فبترجع false والصفحة
 * بتقول للمستخدم بدل ما تطبع باركود مابيتقراش.
 */
function barcode_printable(string $code): bool
{
    if ($code === '') {
        return false;
    }
    if (barcode_kind($code) !== 'code128') {
        return true;
    }
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $o = ord($code[$i]);
        if ($o < 32 || $o > 126) {
            return false;
        }
    }
    return true;
}

/** تحويل عرض العناصر (مثال «212222») لبِتّات: البار ١ والفراغ ٠. */
function barcode_widths_to_bits(string $widths): string
{
    $bits = '';
    $isBar = true;
    $len = strlen($widths);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_repeat($isBar ? '1' : '0', (int) $widths[$i]);
        $isBar = !$isBar;
    }
    return $bits;
}

/** بِتّات EAN-13 (٩٥ وحدة: 101 + ٦×٧ + 01010 + ٦×٧ + 101). */
function barcode_ean13_bits(string $code): string
{
    $parity = BARCODE_EAN13_PARITY[(int) $code[0]];
    $bits = '101';
    for ($i = 1; $i <= 6; $i++) {
        $d = (int) $code[$i];
        $bits .= $parity[$i - 1] === 'L' ? BARCODE_EAN_L[$d] : BARCODE_EAN_G[$d];
    }
    $bits .= '01010';
    for ($i = 7; $i <= 12; $i++) {
        $bits .= BARCODE_EAN_R[(int) $code[$i]];
    }
    return $bits . '101';
}

/** بِتّات EAN-8 (٦٧ وحدة: 101 + ٤×٧ + 01010 + ٤×٧ + 101). */
function barcode_ean8_bits(string $code): string
{
    $bits = '101';
    for ($i = 0; $i <= 3; $i++) {
        $bits .= BARCODE_EAN_L[(int) $code[$i]];
    }
    $bits .= '01010';
    for ($i = 4; $i <= 7; $i++) {
        $bits .= BARCODE_EAN_R[(int) $code[$i]];
    }
    return $bits . '101';
}

/**
 * بِتّات Code 128.
 * كله أرقام وعدده زوجي ← Code C (رقمين في الرمز = باركود أقصر).
 * غير كده ← Code B (كل ASCII من ٣٢ لـ١٢٦).
 */
function barcode_code128_bits(string $code): string
{
    $len = strlen($code);
    if ($len === 0) {
        throw new InvalidArgumentException('كود فاضي — مفيش باركود يتطبع');
    }

    $values = [];
    if (preg_match('/^\d+$/', $code) === 1 && $len % 2 === 0) {
        $start = 105;                              // Start C
        for ($i = 0; $i < $len; $i += 2) {
            $values[] = (int) substr($code, $i, 2);
        }
    } else {
        $start = 104;                              // Start B
        for ($i = 0; $i < $len; $i++) {
            $o = ord($code[$i]);
            if ($o < 32 || $o > 126) {
                throw new InvalidArgumentException('الكود فيه حرف مش ASCII — مينفعش يتطبع Code 128');
            }
            $values[] = $o - 32;
        }
    }

    // الـchecksum: قيمة البداية + كل قيمة × رقم موضعها (من ١) ← mod 103
    $sum = $start;
    foreach ($values as $i => $v) {
        $sum += $v * ($i + 1);
    }

    $bits = barcode_widths_to_bits(BARCODE_C128[$start]);
    foreach ($values as $v) {
        $bits .= barcode_widths_to_bits(BARCODE_C128[$v]);
    }
    $bits .= barcode_widths_to_bits(BARCODE_C128[$sum % 103]);
    return $bits . barcode_widths_to_bits(BARCODE_C128_STOP);
}

/** بِتّات أي كود — النوع بيتحدد لوحده. */
function barcode_bits(string $code): string
{
    switch (barcode_kind($code)) {
        case 'ean13':
            return barcode_ean13_bits($code);
        case 'ean8':
            return barcode_ean8_bits($code);
        default:
            return barcode_code128_bits($code);
    }
}

/** عرض الباركود بالمليمتر لو الموديول الواحد بعرض $moduleMm. */
function barcode_width_mm(string $code, float $moduleMm): float
{
    return round((strlen(barcode_bits($code)) + 2 * BARCODE_QUIET_MODULES) * $moduleMm, 2);
}

/**
 * أوسع موديول يدخل في عرض معيّن (عشان الباركود يملّي الليبل من غير ما يطلع برّه).
 * سقفه ٠.٣٣مم (EAN-13 على ٣٨مم بيطلع ٠.٣١) وأرضيته ٠.١٥مم — تحت كده
 * الطابعة الحرارية بتبلّي الخطوط.
 */
function barcode_fit_module(string $code, float $availableMm, float $maxModuleMm = 0.33, float $minModuleMm = 0.15): float
{
    $modules = strlen(barcode_bits($code)) + 2 * BARCODE_QUIET_MODULES;
    if ($modules <= 0 || $availableMm <= 0) {
        return $minModuleMm;
    }
    $fit = $availableMm / $modules;
    return round(max($minModuleMm, min($maxModuleMm, $fit)), 4);
}

/**
 * SVG الباركود بالمليمتر.
 *
 * @param string $code     الكود اللي هيتطبع (نفسه بيتحط تحت الباركود في الصفحة)
 * @param float  $heightMm ارتفاع البار
 * @param float  $moduleMm عرض الموديول (٠ = الافتراضي ٠.٣مم)
 */
function barcode_svg(string $code, float $heightMm, float $moduleMm = 0.0): string
{
    $code = (string) $code;
    if ($code === '') {
        throw new InvalidArgumentException('كود فاضي — مفيش باركود يتطبع');
    }
    if ($moduleMm <= 0) {
        $moduleMm = 0.3;
    }
    if ($heightMm <= 0) {
        $heightMm = 8.0;
    }

    $bits   = barcode_bits($code);
    $total  = strlen($bits) + 2 * BARCODE_QUIET_MODULES;
    $width  = round($total * $moduleMm, 3);
    $height = round($heightMm, 3);
    $left   = BARCODE_QUIET_MODULES * $moduleMm;

    // بنجمع البارات المتلزقة في مستطيل واحد — نفس الشكل وأخف على المتصفح
    $rects = '';
    $i = 0;
    $n = strlen($bits);
    while ($i < $n) {
        if ($bits[$i] === '0') {
            $i++;
            continue;
        }
        $j = $i;
        while ($j < $n && $bits[$j] === '1') {
            $j++;
        }
        $x = round($left + $i * $moduleMm, 3);
        $w = round(($j - $i) * $moduleMm, 3);
        $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
        $i = $j;
    }

    $aria = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    // أبيض وأسود صريح: الليبل بيتطبع على ورق أبيض، والخلفية البيضاء
    // بتضمن إن السكانر يقرا حتى لو الليبل ملزوق على حاجة ملوّنة.
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . 'mm" height="' . $height . 'mm"'
        . ' viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none"'
        . ' shape-rendering="crispEdges" role="img" aria-label="' . $aria . '">'
        . '<rect width="' . $width . '" height="' . $height . '" fill="#fff"/>'
        . '<g fill="#000">' . $rects . '</g></svg>';
}
