<?php
/**
 * aggregate.php — تجميع وترتيب كافة نتائج الفحص وتوليد findings.json و summary.md
 */
declare(strict_types=1);

$outDir = $argv[1] ?? '';
if ($outDir === '' || !is_dir($outDir)) {
    fwrite(STDERR, "Invalid outDir\n");
    exit(1);
}

$crawlFindings   = file_exists("$outDir/crawl_findings.json")   ? json_decode(file_get_contents("$outDir/crawl_findings.json"), true)   : [];
$browserFindings = file_exists("$outDir/browser_findings.json") ? json_decode(file_get_contents("$outDir/browser_findings.json"), true) : [];
$staticFindings  = file_exists("$outDir/static_findings.json")  ? json_decode(file_get_contents("$outDir/static_findings.json"), true)  : [];
$crawlSummary    = file_exists("$outDir/crawl_summary.json")    ? json_decode(file_get_contents("$outDir/crawl_summary.json"), true)    : [];

$allFindings = array_merge($crawlFindings ?: [], $browserFindings ?: [], $staticFindings ?: []);

// ترتيب حسب الخطورة: red ثم orange ثم yellow
$severityOrder = ['red' => 1, 'orange' => 2, 'yellow' => 3];
usort($allFindings, function($a, $b) use ($severityOrder) {
    $sa = $severityOrder[$a['severity'] ?? 'yellow'] ?? 4;
    $sb = $severityOrder[$b['severity'] ?? 'yellow'] ?? 4;
    if ($sa !== $sb) return $sa <=> $sb;
    return strcmp($a['file'] ?? '', $b['file'] ?? '');
});

// حفظ findings.json الموحد
file_put_contents("$outDir/findings.json", json_encode($allFindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// إحصائيات الخطورة
$bySeverity = ['red' => 0, 'orange' => 0, 'yellow' => 0];
$byKind = [];
foreach ($allFindings as $f) {
    $sev = $f['severity'] ?? 'yellow';
    $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
    $kind = $f['kind'] ?? 'unknown';
    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
}

// توليد summary.md
$md = "# ملخص المراجعة العميقة (Deep Audit Summary)\n\n";
$md .= "- **تاريخ وساعة الفحص:** " . date('Y-m-d H:i:s') . "\n";
$md .= "- **إجمالي الملاحظات المكتشفة:** " . count($allFindings) . "\n";
$md .= "  - 🔴 **حرجة (Red):** {$bySeverity['red']}\n";
$md .= "  - 🟠 **متوسطة (Orange):** {$bySeverity['orange']}\n";
$md .= "  - 🟡 **منخفضة/تنسيقية (Yellow):** {$bySeverity['yellow']}\n\n";

$md .= "## ١. تصنيف الملاحظات حسب النوع (Kind)\n\n";
$md .= "| النوع | الخطورة | العدد |\n";
$md .= "|---|:---:|:---:|\n";
arsort($byKind);
foreach ($byKind as $kind => $cnt) {
    $exSev = 'yellow';
    foreach ($allFindings as $f) {
        if (($f['kind'] ?? '') === $kind) {
            $exSev = $f['severity'] ?? 'yellow';
            break;
        }
    }
    $sevBadge = match($exSev) { 'red' => '🔴 Red', 'orange' => '🟠 Orange', default => '🟡 Yellow' };
    $md .= "| `$kind` | $sevBadge | $cnt |\n";
}
$md .= "\n---\n\n";

$md .= "## ٢. أبطأ ٢٠ صفحة (Slowest 20 Pages)\n\n";
$md .= "| # | الصفحة / الرابط | المستخدم | وقت الرندر (ms) | الحجم (Bytes) |\n";
$md .= "|---|---|---|:---:|:---:|\n";
$slowList = $crawlSummary['stats']['slow_pages'] ?? [];
foreach (array_slice($slowList, 0, 20) as $i => $s) {
    $idx = $i + 1;
    $md .= "| $idx | `{$s['url']}` | `{$s['user']}` | {$s['time_ms']} ms | " . number_format((float)($s['bytes'] ?? 0)) . " |\n";
}
$md .= "\n---\n\n";

$md .= "## ٣. أكثر ٢٠ صفحة استعلامات (Top 20 Query-Heavy Pages)\n\n";
$md .= "| # | الصفحة / الرابط | المستخدم | عدد الاستعلامات (Questions) |\n";
$md .= "|---|---|---|:---:|\n";
$heavyList = $crawlSummary['stats']['heavy_queries'] ?? [];
foreach (array_slice($heavyList, 0, 20) as $i => $h) {
    $idx = $i + 1;
    $md .= "| $idx | `{$h['url']}` | `{$h['user']}` | {$h['questions']} |\n";
}
$md .= "\n---\n\n";

$md .= "## ٤. أخطر ٣٠ ملاحظة (Top 30 Critical Findings)\n\n";
$md .= "| # | الخطورة | النوع | الملف والسطر | الرابط / المستخدم | التفاصيل |\n";
$md .= "|---|:---:|---|---|---|---|\n";
foreach (array_slice($allFindings, 0, 30) as $i => $f) {
    $idx = $i + 1;
    $sevBadge = match($f['severity'] ?? 'yellow') { 'red' => '🔴 Red', 'orange' => '🟠 Orange', default => '🟡 Yellow' };
    $fileLine = "`{$f['file']}:{$f['line']}`";
    $urlUser = "`{$f['url']}` ({$f['user']})";
    $detail = htmlspecialchars((string)($f['detail'] ?? ''));
    $md .= "| $idx | $sevBadge | `{$f['kind']}` | $fileLine | $urlUser | $detail |\n";
}

file_put_contents("$outDir/summary.md", $md);
echo "✅ تم تجميع النتائج وتوليد summary.md و findings.json في: $outDir\n";
