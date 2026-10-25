<?php
declare(strict_types=1);
/**
 * test_barcode.php — اختبار مولّد الباركود (بريف ٢١٦)
 *
 * php tests/local/test_barcode.php
 *
 * الأمثلة الأربعة (EAN-13 · EAN-8 · Code 128-B · Code 128-C) مقارنة
 * **بِتّة بِتّة** بالقيم المرجعية المكتوبة تحت. المرجع اتأخد من تطبيق
 * مستقل للمواصفة (python-barcode 0.16.1) — يعني مش من نفس الكود اللي
 * بنختبره، ولو الجداول في barcode_lib.php غلط الاختبار بيمسكها.
 *
 * 🔴 اللي بيفشل هنا غالباً: check digit غلط (بيتطبع EAN والسكانر يرفضه)
 *    أو جدول Code 128 منقول غلط (باركود بيتقرا أرقام تانية).
 */

require_once __DIR__ . '/../../site/barcode_lib.php';

$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$name}\n";
    } else {
        $fail++;
        echo "  ✗ {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

function same(string $name, string $expected, string $got): void
{
    ok($name, $expected === $got, 'المتوقع ' . strlen($expected) . ' وحدة · الناتج ' . strlen($got) . ' وحدة');
}

echo "١) جداول الترميز\n";
ok('جدول Code 128 فيه ١٠٦ نمط (٠–١٠٥)', count(BARCODE_C128) === 106, 'الموجود ' . count(BARCODE_C128));
$badLen = [];
$badSum = [];
foreach (BARCODE_C128 as $i => $w) {
    if (strlen($w) !== 6) {
        $badLen[] = $i;
        continue;
    }
    $sum = 0;
    for ($k = 0; $k < 6; $k++) {
        $sum += (int) $w[$k];
    }
    if ($sum !== 11) {
        $badSum[] = $i;
    }
}
ok('كل نمط ٦ عناصر', !$badLen, 'أرقام غلط: ' . implode(',', $badLen));
ok('مجموع كل نمط = ١١ وحدة', !$badSum, 'أرقام غلط: ' . implode(',', $badSum));
ok('Stop = ٢٣٣١١١٢ (١٣ وحدة)', BARCODE_C128_STOP === '2331112' && array_sum(array_map('intval', str_split(BARCODE_C128_STOP))) === 13);
ok('Start B = ٢١١٢١٤', BARCODE_C128[104] === '211214');
ok('Start C = ٢١١٢٣٢', BARCODE_C128[105] === '211232');
ok('Code B = ١١٤١٣١ · Code C = ١١٣١٤١', BARCODE_C128[100] === '114131' && BARCODE_C128[99] === '113141');
ok('جداول EAN كلها ٧ وحدات', count(BARCODE_EAN_L) === 10 && count(BARCODE_EAN_G) === 10 && count(BARCODE_EAN_R) === 10);

echo "\n٢) الـcheck digit\n";
ok('EAN-13 5901234123457 صح', barcode_ean13_check_digit('590123412345') === 7);
ok('EAN-13 5901234123456 غلط', barcode_ean13_check_digit('590123412345') !== 6);
ok('EAN-8 96385074 صح', barcode_ean8_check_digit('9638507') === 4);
ok('EAN-8 96385073 غلط', barcode_ean8_check_digit('9638507') !== 3);

echo "\n٣) اختيار النوع\n";
ok('٥٩٠١٢٣٤١٢٣٤٥٧ ← EAN-13', barcode_kind('5901234123457') === 'ean13');
ok('٥٩٠١٢٣٤١٢٣٤٥٦ (check غلط) ← Code 128', barcode_kind('5901234123456') === 'code128');
ok('٩٦٣٨٥٠٧٤ ← EAN-8', barcode_kind('96385074') === 'ean8');
ok('٩٦٣٨٥٠٧٣ (check غلط) ← Code 128', barcode_kind('96385073') === 'code128');
ok('٩١ (كود إدارة قصير) ← Code 128 مش رفض', barcode_kind('91') === 'code128');
ok('٠٢١٢ (أرقام زوجية) ← Code 128-C', barcode_kind('0212') === 'code128');

echo "\n٤) الأمثلة المرجعية (بِتّة بِتّة)\n";

// EAN-13: 101 + 9L 0G 1G 2L 3L 4G + 01010 + 1R 2R 3R 4R 5R 7R + 101
same(
    'EAN-13  5901234123457',
    '10100010110100111011001100100110111101001110101010110011011011001000010101110010011101000100101',
    barcode_bits('5901234123457')
);

// EAN-8: 101 + 9L 6L 3L 8L + 01010 + 5R 0R 7R 4R + 101
same(
    'EAN-8   96385074',
    '1010001011010111101111010110111010101001110111001010001001011100101',
    barcode_bits('96385074')
);

// Code 128-B: Start B + A B C - 1 2 3 + checksum + Stop
same(
    'Code128-B  ABC-123',
    '1101001000010100011000100010110001000100011010011011100100111001101100111001011001011100101100001001100011101011',
    barcode_bits('ABC-123')
);

// Code 128-C: Start C + 02 12 + checksum + Stop
same(
    'Code128-C  0212',
    '110100111001100110011010110011100111001101001100011101011',
    barcode_bits('0212')
);

//checksum بتاع الأمثلة — اتأكدنا إنه صح من البِتّات فوق؛ هنا بنقفل الرقم نفسه
echo "\n٥) الـchecksum (mod 103)\n";
$c128Check = static function (array $values, int $start): int {
    $sum = $start;
    foreach ($values as $i => $v) {
        $sum += $v * ($i + 1);
    }
    return $sum % 103;
};
ok(
    'ABC-123 ← checksum ٦٨٨ mod ١٠٣ = ٧٠',
    $c128Check([33, 34, 35, 13, 17, 18, 19], 104) === 70
);
ok('0212 ← checksum ١٠٥+٢+٢٤', $c128Check([2, 12], 105) === 28);

echo "\n٦) الـSVG\n";
$svg = barcode_svg('5901234123457', 7.0, 0.3);
ok('بيرجّع SVG', str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>'));
ok('العرض والارتفاع بالمليمتر (١١٥ وحدة × ٠.٣ = ٣٤.٥مم)', str_contains($svg, 'width="34.5mm"') && str_contains($svg, 'height="7mm"'));
ok('فيه بارات', substr_count($svg, '<rect') > 20);
ok('أسود وأبيض بس', str_contains($svg, 'fill="#000"') && str_contains($svg, 'fill="#fff"'));
ok('الكود في aria-label', str_contains($svg, 'aria-label="5901234123457"'));
ok('عرض Code128 لـ«ABC-123» = ١٣٢ × ٠.٣مم', abs(barcode_width_mm('ABC-123', 0.3) - 39.6) < 0.001, (string) barcode_width_mm('ABC-123', 0.3));
ok('مفيش باركود لكود فيه عربي (٦ أكواد في المرآة)', barcode_printable('سقاتستاس') === false);
ok('«91» بينفع يتطبع', barcode_printable('91') === true);
$threw = false;
try { barcode_svg('', 7.0, 0.3); } catch (InvalidArgumentException) { $threw = true; }
ok('كود فاضي بيرمي استثناء (مش باركود صامت)', $threw);

echo "\n" . ($fail === 0 ? "✅ كله تمام — {$pass} اختبار عدّى" : "❌ {$fail} فشل من " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
