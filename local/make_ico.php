<?php
/**
 * make_ico.php — تجميع favicon.ico من ملفات PNG
 *
 * 🔴 مكانه `local/` — **مايترفعش**. أداة توليد بس.
 *
 * صيغة ICO بسيطة: هيدر + فهرس + الصور. من ويندوز فيستا وطالع الصورة
 * ممكن تكون PNG جوّه الـICO زي ما هي — مش محتاجين BMP.
 *
 * الاستعمال: php local/make_ico.php <الخرج.ico> <16.png> <32.png> <48.png> ...
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }

$out = $argv[1] ?? null;
$pngs = array_slice($argv, 2);
if (!$out || !$pngs) { fwrite(STDERR, "الاستعمال: php make_ico.php out.ico a.png b.png\n"); exit(2); }

$entries = [];
foreach ($pngs as $p) {
    if (!is_file($p)) { fwrite(STDERR, "مفيش: $p\n"); exit(3); }
    $sz = getimagesize($p);
    $entries[] = ['data' => file_get_contents($p), 'w' => $sz[0], 'h' => $sz[1]];
}

$n      = count($entries);
$offset = 6 + 16 * $n;                        // بعد الهيدر والفهرس
$dir    = pack('vvv', 0, 1, $n);              // reserved · type=icon · count
$blob   = '';

foreach ($entries as $e) {
    $len = strlen($e['data']);
    $dir .= pack(
        'CCCCvvVV',
        $e['w'] >= 256 ? 0 : $e['w'],         // 0 معناها ٢٥٦
        $e['h'] >= 256 ? 0 : $e['h'],
        0,                                     // عدد الألوان (0 = أكتر من ٢٥٦)
        0,                                     // محجوز
        1,                                     // planes
        32,                                    // bits
        $len,
        $offset
    );
    $blob   .= $e['data'];
    $offset += $len;
}

file_put_contents($out, $dir . $blob);
printf("✅ %s (%d صور · %d بايت)\n", $out, $n, filesize($out));
