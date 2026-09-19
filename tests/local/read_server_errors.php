<?php
/**
 * read_server_errors.php — قراية سجل أخطاء السيرفر بعد الميرورينج.
 *
 * محمد بيعمل ميرورينج لـpublic_html على جهازه، والسجل بينزل معاه في:
 *   public_html/logs/errors.log.php   (+ errors.old.log.php لو اتلف)
 *
 * الاستعمال:
 *   php tests/local/read_server_errors.php            ← نسخة السيرفر
 *   php tests/local/read_server_errors.php laragon    ← النسخة المحلية
 *   php tests/local/read_server_errors.php <dir> 50   ← أي فولدر · أول 50
 *   php tests/local/read_server_errors.php <dir> 50 "2026-09-20 00:00:00"
 *                                                     ← اللي حصل بعد التاريخ ده بس
 *
 * 🔴 الفلتر بالتاريخ ده اللي `deploy.sh` بينده بيه بعد كل رفع.
 *    قبله كان السجل بيتقرا لما حد يفتكر — وغلطة قاتلة في إشراقة فضلت
 *    شغالة ٦٧ مرة على مدى تلات أيام ومحدش واخد باله.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$repo = dirname(__DIR__, 2);
$arg  = $argv[1] ?? '';
$dir  = $arg === 'laragon' ? 'D:/laragon/www/logs'
      : ($arg !== '' && is_dir($arg) ? $arg : $repo . '/public_html/logs');
$limit = (int) ($argv[2] ?? 30);
$since = trim((string) ($argv[3] ?? ''));   // '' = كل السجل

require $repo . '/site/errlog_lib.php';   // الدوال بس — مفيش install هنا

$files = [$dir . '/errors.old.log.php', $dir . '/errors.log.php'];
$found = array_filter($files, 'is_file');
if (!$found) { echo "مفيش سجل في $dir\n"; exit(0); }

$groups = errlog_read_grouped($files);

// فلتر بالتاريخ: بنقارن بـ«آخر مرة» — الغلطة القديمة اللي **لسه بتحصل**
// لازم تبان، والقديمة اللي وقفت خلاص مالهاش لزمة.
if ($since !== '') {
    $groups = array_values(array_filter(
        $groups,
        static fn(array $g): bool => strcmp((string) $g['last'], $since) >= 0
    ));
    if (!$groups) { echo "✅ مفيش أخطاء بعد $since\n"; exit(0); }
}

$total = array_sum(array_column($groups, 'count'));
printf(
    "السجل: %s%s\nغلطات مختلفة: %d · مرات الحدوث: %d\n\n",
    $dir,
    $since !== '' ? " (بعد $since)" : '',
    count($groups),
    $total
);

foreach (array_slice($groups, 0, $limit) as $i => $g) {
    printf("%d) [%s] ×%d · آخر مرة %s (أول مرة %s)\n", $i + 1, strtoupper($g['type']), $g['count'], $g['last'], $g['first']);
    printf("   %s\n", $g['msg']);
    printf("   المكان: %s%s · الصفحة: %s\n", $g['file'], $g['line'] ? ':' . $g['line'] : '', $g['url'] ?: '—');
    if ($g['users']) printf("   المستخدمين: %s\n", implode('، ', $g['users']));
    if ($g['trace'] !== '') printf("   %s\n", str_replace("\n", "\n   ", trim($g['trace'])));
    echo "\n";
}
