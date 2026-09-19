<?php
/**
 * make_icon.php — توليد أيقونات موقع إشراقة (حرف A) بنفس تصميم أيقونة الفجالة
 *
 * 🔴 مكانه `local/` عن قصد — **مايترفعش على السيرفر**. ده أداة توليد بس،
 *    اللي بيترفع هو ملفات الـPNG اللي بتطلع منه.
 *
 * التصميم: مربع بحواف مدوّرة + تدرّج رأسي + حرف بخط سيرافي + خط تحته —
 * نفس لغة أيقونة الفجالة بالظبط، والفرق في **اللون والحرف** بس.
 *
 * الرسم بيتعمل على ٤ أضعاف المقاس وبعدين بيصغّر — ده اللي بيدّي حواف
 * ناعمة، لأن GD مابيعملش anti-aliasing للأشكال المصمتة.
 *
 * الاستعمال:
 *   php local/make_icon.php <المقاس> <ملف الخرج> <لون فوق> <لون تحت> <لون الحرف> [padding]
 *   مثال: php local/make_icon.php 192 out.png 0f3b34 072722 e8c88a
 *   الـpadding (نسبة من صفر لواحد) للأيقونة الـmaskable اللي محتاجة هامش أمان.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

[, $size, $out, $top, $bottom, $fg] = array_pad($argv, 6, null);
$size = (int) ($size ?: 192);
$out  = (string) ($out ?: 'icon.png');
$top  = (string) ($top ?: '0f3b34');
$bottom = (string) ($bottom ?: '072722');
$fg   = (string) ($fg ?: 'e8c88a');
/* الوضع: عادي (مربع بحواف مدوّرة) أو maskable.
   الـmaskable بتتقص بماسك بيحدده نظام الموبايل، فلازم الخلفية تملا
   الكادر كله من غير حواف مدوّرة، والحرف يفضل في **منطقة الأمان** جوه
   دايرة ٨٠٪ من العرض — عشان مايتقصّش. عشان كده الحرف بيصغّر مش الخلفية. */
$maskable = (($argv[6] ?? '') === 'maskable');
$cs   = $maskable ? 0.68 : 1.0;             // نسبة تصغير المحتوى
$pad  = 0.0;
$letter = 'A';
$font   = 'C:/Windows/Fonts/georgiab.ttf';  // سيرافي عريض — أقرب حاجة للأصل

if (!is_file($font)) { fwrite(STDERR, "مفيش الخط: $font\n"); exit(2); }

function hex2rgb(string $h): array {
    $h = ltrim($h, '#');
    return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}

$S  = $size * 4;                 // نرسم على ٤ أضعاف وبعدين نصغّر
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefilledrectangle($im, 0, 0, $S, $S, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$m  = (int) round($S * $pad);
$x0 = $m; $y0 = $m; $x1 = $S - $m - 1; $y1 = $S - $m - 1;
$w  = $x1 - $x0 + 1;
// maskable = مربع كامل من غير استدارة (نظام الموبايل بيقصّه بنفسه)
$rad = $maskable ? 0 : (int) round($w * 0.225);

[$tr,$tg,$tb] = hex2rgb($top);
[$br,$bg2,$bb] = hex2rgb($bottom);

/* الشكل: بنبني ماسك بالحواف المدوّرة وبنملاه بالتدرّج سطر بسطر */
for ($y = $y0; $y <= $y1; $y++) {
    $t = ($y - $y0) / max(1, $w - 1);
    $r = (int) round($tr + ($br  - $tr)  * $t);
    $g = (int) round($tg + ($bg2 - $tg)  * $t);
    $b = (int) round($tb + ($bb  - $tb)  * $t);
    $col = imagecolorallocate($im, $r, $g, $b);

    // عرض السطر حسب الاستدارة
    $dy = 0;
    if ($y < $y0 + $rad)      $dy = $y0 + $rad - $y;
    elseif ($y > $y1 - $rad)  $dy = $y - ($y1 - $rad);
    $inset = 0;
    if ($dy > 0) $inset = (int) round($rad - sqrt(max(0, $rad*$rad - $dy*$dy)));

    imageline($im, $x0 + $inset, $y, $x1 - $inset, $y, $col);
}

// الحرف — متوسّط أفقياً، ومرفوع شوية عشان الخط اللي تحته
[$fr,$fg2,$fb] = hex2rgb($fg);
$fcol = imagecolorallocate($im, $fr, $fg2, $fb);
$fs   = (int) round($w * 0.47 * $cs);
$bbox = imagettfbbox($fs, 0, $font, $letter);
$tw   = $bbox[2] - $bbox[0];
$th   = $bbox[1] - $bbox[7];
$tx   = $x0 + (int) round(($w - $tw) / 2) - $bbox[0];
$ty   = $y0 + (int) round(($w - $th) / 2) - $bbox[7] - (int) round($w * 0.085 * $cs);
imagettftext($im, $fs, 0, $tx, $ty, $fcol, $font, $letter);

// الخط اللي تحت الحرف — نفس فكرة أيقونة الفجالة
$lw  = (int) round($w * 0.42 * $cs);
$lh  = max(1, (int) round($w * 0.022 * $cs));
$lx  = $x0 + (int) round(($w - $lw) / 2);
$ly  = $y0 + (int) round($w * (0.5 + (0.760 - 0.5) * $cs));
imagefilledrectangle($im, $lx, $ly, $lx + $lw, $ly + $lh, $fcol);

// تصغير للمقاس المطلوب — ده اللي بيدّي الحواف الناعمة
$fin = imagescale($im, $size, $size, IMG_BICUBIC);
imagesavealpha($fin, true);
imagepng($fin, $out, 9);
echo "✅ $out ({$size}x{$size})\n";
