<?php
/**
 * ops.php — صفحة المراقبة: قياس سرعة وأداء الصفحات
 * نظام الفجالة ستور المالي (بريف ٩٩)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('ops');

$canPerf = can('ops:perf');

// الأيام المتاحة التي لها ملفات أداء
$logsDir = __DIR__ . '/logs';
$availableDays = [];
if (is_dir($logsDir)) {
    $files = @glob($logsDir . '/perf-*.log.php');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (preg_match('/perf-(\d{4}-\d{2}-\d{2})\.log\.php$/', $f, $m)) {
                $availableDays[] = $m[1];
            }
        }
    }
}
rsort($availableDays);

$todayCairo = (new \DateTime('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');
$selectedDay = trim((string) ($_GET['day'] ?? ''));
if ($selectedDay === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
    $selectedDay = in_array($todayCairo, $availableDays, true) ? $todayCairo : ($availableDays[0] ?? $todayCairo);
}

// قراءة ملف اليوم المختار سطر سطر
$logFilePath = $logsDir . '/perf-' . $selectedDay . '.log.php';

$totalRequests = 0;
$totalAllMs = 0.0;
$over1SecCount = 0;

$pagesMap = [];
$slowestRequests = [];

if ($canPerf && is_file($logFilePath)) {
    $fh = @fopen($logFilePath, 'r');
    if ($fh) {
        while (($raw = fgets($fh)) !== false) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '<?php')) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) < 6) {
                continue;
            }

            $time   = $parts[0];
            $page   = $parts[1];
            $method = $parts[2];
            $ms     = (float) $parts[3];
            $mb     = (float) $parts[4];
            $status = (int) $parts[5];
            $uid    = (isset($parts[6]) && $parts[6] !== '') ? (int) $parts[6] : null;

            $totalRequests++;
            $totalAllMs += $ms;
            if ($ms >= 1000) {
                $over1SecCount++;
            }

            if (!isset($pagesMap[$page])) {
                $pagesMap[$page] = [
                    'page'     => $page,
                    'count'    => 0,
                    'total_ms' => 0.0,
                    'times'    => [],
                    'total_mb' => 0.0,
                    'errors'   => 0,
                ];
            }
            $pagesMap[$page]['count']++;
            $pagesMap[$page]['total_ms'] += $ms;
            $pagesMap[$page]['times'][] = $ms;
            $pagesMap[$page]['total_mb'] += $mb;
            if ($status >= 500) {
                $pagesMap[$page]['errors']++;
            }

            $slowestRequests[] = [
                'time'   => $time,
                'page'   => $page,
                'method' => $method,
                'ms'     => $ms,
                'mb'     => $mb,
                'status' => $status,
                'uid'    => $uid,
            ];
        }
        fclose($fh);
    }
}

// حساب مؤشرات كل صفحة
$pagesStats = [];
foreach ($pagesMap as $page => $d) {
    $count = $d['count'];
    $times = $d['times'];
    sort($times);
    $n = count($times);

    // p95 = القيمة عند 95% بعد الترتيب
    $p95Index = min($n - 1, max(0, (int) ceil(0.95 * $n) - 1));
    $p95 = $times[$p95Index];

    $avgMs = round($d['total_ms'] / $count, 1);
    $maxMs = round(max($times), 1);
    $avgMb = round($d['total_mb'] / $count, 2);

    $pagesStats[] = [
        'page'    => $page,
        'count'   => $count,
        'avg_ms'  => $avgMs,
        'p95_ms'  => $p95,
        'max_ms'  => $maxMs,
        'avg_mb'  => $avgMb,
        'errors'  => $d['errors'],
    ];
}

// الترتيب بالمتوسط تنازلي
usort($pagesStats, fn($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);

// أبطأ ٢٠ طلب
usort($slowestRequests, fn($a, $b) => $b['ms'] <=> $a['ms']);
$slowest20 = array_slice($slowestRequests, 0, 20);

// استعلام أسماء المستخدمين باستعلام واحد فقط
$uids = [];
foreach ($slowest20 as $sr) {
    if ($sr['uid'] !== null && $sr['uid'] > 0) {
        $uids[] = (int) $sr['uid'];
    }
}
$uids = array_values(array_unique($uids));
$userNames = [];
if (!empty($uids)) {
    try {
        $inPh = implode(',', array_fill(0, count($uids), '?'));
        $st = db()->prepare("SELECT id, full_name FROM users WHERE id IN ($inPh)");
        $st->execute($uids);
        $userNames = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        // صامت
    }
}

$overallAvg = $totalRequests > 0 ? round($totalAllMs / $totalRequests, 1) : 0.0;

function ops_ms_style(float $ms): string {
    if ($ms >= 1000) return 'color:var(--neg);font-weight:700;';
    if ($ms >= 400) return 'color:var(--warn);font-weight:600;';
    return 'color:var(--ink-800);';
}

$pageTitle = 'المراقبة';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.ops-wrap {
    padding: var(--sp-4, 16px) 0;
}
.ops-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: var(--sp-4, 16px);
}
.ops-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--line-soft, #e2e8f0);
    margin-bottom: var(--sp-4, 16px);
    padding-bottom: 4px;
}
.ops-tab {
    padding: 8px 18px;
    border-radius: var(--r-md, 8px) var(--r-md, 8px) 0 0;
    font-weight: 600;
    font-size: var(--fs-sm, 14px);
    color: var(--ink-500);
    text-decoration: none;
    transition: all .15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.ops-tab:hover {
    color: var(--ink-800);
    background: var(--bg);
}
.ops-tab.active {
    color: var(--fill-strong-fg, #fff);
    background: var(--fill-strong, #1e293b);
}
.ops-filter-form {
    display: flex;
    align-items: center;
    gap: 8px;
}
.ops-select {
    padding: 7px 12px;
    border: 1px solid var(--line);
    border-radius: var(--r-md, 8px);
    background: var(--surface);
    color: var(--ink-800);
    font-family: inherit;
    font-size: var(--fs-sm, 13.5px);
}
/* كروت الإحصائيات العلوية */
.ops-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--sp-4, 16px);
    margin-bottom: var(--sp-6, 24px);
}
.ops-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg, 12px);
    padding: 16px 20px;
}
.ops-card-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-500);
    margin-bottom: 6px;
}
.ops-card-val {
    font-size: 26px;
    font-weight: 800;
    color: var(--ink-900);
    font-variant-numeric: tabular-nums;
}
.ops-card-sub {
    font-size: 12px;
    color: var(--ink-400);
    margin-top: 4px;
}
/* تصميم الجدول مطابق لحالة الرصيد في item_sales.php */
.ops-table-container {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg, 12px);
    overflow-x: auto;
    margin-bottom: var(--sp-6, 24px);
}
.ops-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--fs-sm, 13.5px);
}
.ops-table th {
    background: var(--bg);
    color: var(--ink-600);
    font-weight: 600;
    padding: 10px 14px;
    text-align: right;
    border-bottom: 1px solid var(--line);
    white-space: nowrap;
}
.ops-table td {
    padding: 9px 14px;
    border-bottom: 1px solid var(--line-soft, #f1f5f9);
    color: var(--ink-800);
}
.ops-table tr:hover {
    background: var(--line-soft);
}
.ops-table .num,
.ops-table th.num {
    font-variant-numeric: tabular-nums;
    direction: ltr;
    text-align: center;
    white-space: nowrap;
}
.ops-table td.ops-page-name {
    font-weight: 600;
    font-family: monospace;
    font-size: 13px;
    color: var(--accent, #0f766e);
}
.ops-sec-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--ink-800);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ops-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--ink-500);
    font-size: 14px;
}
</style>

<div class="ops-wrap">
    <div class="ops-top">
        <h2 style="margin:0;font-size:22px;font-weight:800;color:var(--ink-900);">المراقبة</h2>
        <?php if ($canPerf): ?>
        <form method="get" action="ops.php" class="ops-filter-form">
            <input type="hidden" name="tab" value="perf">
            <label for="daySelect" style="font-size:13px;font-weight:600;color:var(--ink-600);">اليوم:</label>
            <select name="day" id="daySelect" class="ops-select" onchange="this.form.submit()">
                <?php if (!empty($availableDays)): ?>
                    <?php foreach ($availableDays as $d): ?>
                        <option value="<?= e($d) ?>" <?= $d === $selectedDay ? 'selected' : '' ?>>
                            <?= e($d) ?><?= $d === $todayCairo ? ' (النهاردة)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="<?= e($todayCairo) ?>"><?= e($todayCairo) ?> (النهاردة)</option>
                <?php endif; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <!-- التبويبات -->
    <div class="ops-tabs">
        <?php if ($canPerf): ?>
            <a href="ops.php?tab=perf&day=<?= e($selectedDay) ?>" class="ops-tab active">
                <i class="ti ti-chart-bar"></i> السرعة
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$canPerf): ?>
        <div class="ops-card ops-empty">
            مالكش صلاحية تشوف سجل السرعة.
        </div>
    <?php elseif ($totalRequests === 0): ?>
        <div class="ops-card ops-empty">
            مفيش بيانات مسجلة ليوم <?= e($selectedDay) ?> بعد.
        </div>
    <?php else: ?>
        <!-- كروت الإحصائيات -->
        <div class="ops-cards">
            <div class="ops-card">
                <div class="ops-card-label">إجمالي الطلبات</div>
                <div class="ops-card-val"><?= number_format($totalRequests) ?></div>
                <div class="ops-card-sub">كل الزيارات والعمليات المسجلة</div>
            </div>
            <div class="ops-card">
                <div class="ops-card-label">المتوسط العام</div>
                <div class="ops-card-val" style="<?= ops_ms_style($overallAvg) ?>">
                    <?= number_format($overallAvg, 1) ?> <span style="font-size:15px;font-weight:600;">ms</span>
                </div>
                <div class="ops-card-sub">زمن استجابة الصفحة بالمتوسط</div>
            </div>
            <div class="ops-card">
                <div class="ops-card-label">طلبات فوق ثانية</div>
                <div class="ops-card-val" style="<?= $over1SecCount > 0 ? 'color:var(--neg);' : 'color:var(--pos);' ?>">
                    <?= number_format($over1SecCount) ?>
                </div>
                <div class="ops-card-sub">أكبر من ١٠٠٠ مللي ثانية</div>
            </div>
        </div>

        <!-- جدول الصفحات -->
        <div class="ops-sec-title">
            <i class="ti ti-list"></i> أداء الصفحات
        </div>
        <div class="ops-table-container">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>الصفحة</th>
                        <th class="num">عدد الطلبات</th>
                        <th class="num">المتوسط (ms)</th>
                        <th class="num">p95 (ms)</th>
                        <th class="num">الأقصى (ms)</th>
                        <th class="num">متوسط الذاكرة (MB)</th>
                        <th class="num">أخطاء (≥ 500)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagesStats as $p): ?>
                        <tr>
                            <td class="ops-page-name"><?= e($p['page']) ?></td>
                            <td class="num"><?= number_format($p['count']) ?></td>
                            <td class="num" style="<?= ops_ms_style($p['avg_ms']) ?>"><?= number_format($p['avg_ms'], 1) ?></td>
                            <td class="num" style="<?= ops_ms_style($p['p95_ms']) ?>"><?= number_format($p['p95_ms'], 1) ?></td>
                            <td class="num" style="<?= ops_ms_style($p['max_ms']) ?>"><?= number_format($p['max_ms'], 1) ?></td>
                            <td class="num"><?= number_format($p['avg_mb'], 2) ?></td>
                            <td class="num" style="<?= $p['errors'] > 0 ? 'color:var(--neg);font-weight:700;' : 'color:var(--ink-400);' ?>">
                                <?= number_format($p['errors']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- أبطأ ٢٠ طلب -->
        <div class="ops-sec-title">
            <i class="ti ti-clock"></i> أبطأ ٢٠ طلب
        </div>
        <div class="ops-table-container">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th class="num">الوقت</th>
                        <th>الصفحة</th>
                        <th class="num">الوقت (ms)</th>
                        <th class="num">الذاكرة (MB)</th>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slowest20 as $sr): ?>
                        <?php
                            $uName = '—';
                            if ($sr['uid'] !== null && $sr['uid'] > 0) {
                                $uName = $userNames[$sr['uid']] ?? ('مستخدم #' . $sr['uid']);
                            }
                        ?>
                        <tr>
                            <td class="num" style="color:var(--ink-500);"><?= e($sr['time']) ?></td>
                            <td class="ops-page-name"><?= e($sr['page']) ?></td>
                            <td class="num" style="<?= ops_ms_style($sr['ms']) ?>"><?= number_format($sr['ms'], 1) ?></td>
                            <td class="num"><?= number_format($sr['mb'], 2) ?></td>
                            <td><?= e($uName) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>