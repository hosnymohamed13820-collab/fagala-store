<?php
/**
 * ops.php — صفحة المراقبة: قياس سرعة وأداء الصفحات
 * نظام الفجالة ستور المالي (بريف ٩٩)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('ops');

$canPerf  = can('ops:perf');
$canAudit = can('ops:audit');

$tabParam = trim((string) ($_GET['tab'] ?? ''));
if ($tabParam === '' || !in_array($tabParam, ['perf', 'audit'], true)) {
    if ($canPerf) {
        $tabParam = 'perf';
    } elseif ($canAudit) {
        $tabParam = 'audit';
    } else {
        $tabParam = 'perf';
    }
}

$todayCairo = (new \DateTime('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');

// =====================================================================
//  تصدير إكسيل لسجل العمليات (قبل أي HTML وبنفس الحراسة)
// =====================================================================
$export = trim((string) ($_GET['export'] ?? ''));
if ($export === 'audit' && $canAudit) {
    require_once __DIR__ . '/xlsx_lib.php';

    $fromAudit = trim((string) ($_GET['from'] ?? ''));
    if ($fromAudit === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromAudit)) {
        $fromAudit = $todayCairo;
    }
    $toAudit = trim((string) ($_GET['to'] ?? ''));
    if ($toAudit === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toAudit)) {
        $toAudit = $todayCairo;
    }

    $uParam = isset($_GET['user_id']) && trim((string)$_GET['user_id']) !== '' ? (int)$_GET['user_id'] : null;
    $pParam = trim((string)($_GET['page'] ?? ''));
    $aParam = trim((string)($_GET['action_search'] ?? ''));

    $where = ["created_at >= ? AND created_at <= ?"];
    $bindings = [$fromAudit . ' 00:00:00', $toAudit . ' 23:59:59'];

    if ($uParam !== null && $uParam > 0) {
        $where[] = "user_id = ?";
        $bindings[] = $uParam;
    }
    if ($pParam !== '') {
        $where[] = "page = ?";
        $bindings[] = $pParam;
    }
    if ($aParam !== '') {
        $where[] = "action LIKE ?";
        $bindings[] = '%' . $aParam . '%';
    }

    $sql = "SELECT created_at, username, page, action, status, ms, params FROM audit_requests WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 5000";

    $auditExportRows = [];
    try {
        if (function_exists('db')) {
            $st = db()->prepare($sql);
            $st->execute($bindings);
            $auditExportRows = $st->fetchAll(\PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        $auditExportRows = [];
    }

    $x = new SimpleXlsx('سجل العمليات');
    $x->setColumnTypes(['s', 's', 's', 's', 'n', 'n', 's']);
    $x->addRow(['الوقت', 'المستخدم', 'الصفحة', 'العملية', 'الحالة', 'المدة (ms)', 'المدخلات'], true);

    foreach ($auditExportRows as $ar) {
        $x->addRow([
            $ar['created_at'],
            (string) ($ar['username'] ?? ''),
            $ar['page'],
            (string) ($ar['action'] ?? ''),
            (int) ($ar['status'] ?? 0),
            (int) ($ar['ms'] ?? 0),
            (string) ($ar['params'] ?? ''),
        ]);
    }
    $x->download('audit_requests_' . $fromAudit . '_' . $toAudit . '.xlsx');
    exit;
}

// =====================================================================
//  تجهيز بيانات تبويب السرعة
// =====================================================================
$logsDir = __DIR__ . '/logs';
$availableDays = [];
if ($canPerf && is_dir($logsDir)) {
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

if ($canPerf && $tabParam === 'perf' && is_file($logFilePath)) {
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

// =====================================================================
//  تجهيز بيانات تبويب سجل العمليات (بريف ١٠٠)
// =====================================================================
$auditRows = [];
$auditUsersList = [];
$auditPagesList = [];
$auditTotal = 0;
$auditLimit = 200;
$auditPageNum = max(1, (int) ($_GET['p'] ?? 1));
$auditOffset = ($auditPageNum - 1) * $auditLimit;

$fromAudit = trim((string) ($_GET['from'] ?? ''));
if ($fromAudit === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromAudit)) {
    $fromAudit = $todayCairo;
}
$toAudit = trim((string) ($_GET['to'] ?? ''));
if ($toAudit === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toAudit)) {
    $toAudit = $todayCairo;
}

$uParam = isset($_GET['user_id']) && trim((string)$_GET['user_id']) !== '' ? (int)$_GET['user_id'] : null;
$pParam = trim((string)($_GET['page'] ?? ''));
$aParam = trim((string)($_GET['action_search'] ?? ''));

if ($canAudit && $tabParam === 'audit') {
    try {
        if (function_exists('db')) {
            $pdo = db();
            // قائمة المستخدمين والصفحات للفلاتر
            $uSt = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC");
            if ($uSt) {
                $auditUsersList = $uSt->fetchAll(\PDO::FETCH_ASSOC);
            }
            $pSt = $pdo->query("SELECT DISTINCT page FROM audit_requests ORDER BY page ASC");
            if ($pSt) {
                $auditPagesList = $pSt->fetchAll(\PDO::FETCH_COLUMN);
            }

            $where = ["created_at >= ? AND created_at <= ?"];
            $bindings = [$fromAudit . ' 00:00:00', $toAudit . ' 23:59:59'];

            if ($uParam !== null && $uParam > 0) {
                $where[] = "user_id = ?";
                $bindings[] = $uParam;
            }
            if ($pParam !== '') {
                $where[] = "page = ?";
                $bindings[] = $pParam;
            }
            if ($aParam !== '') {
                $where[] = "action LIKE ?";
                $bindings[] = '%' . $aParam . '%';
            }

            $whereSql = implode(' AND ', $where);

            // إجمالي العدد للصفحات
            $cntSt = $pdo->prepare("SELECT COUNT(*) FROM audit_requests WHERE $whereSql");
            $cntSt->execute($bindings);
            $auditTotal = (int) $cntSt->fetchColumn();

            // جلب الصفوف بالصفحة الحالية
            $sql = "SELECT id, created_at, user_id, username, ip, method, page, action, params, status, ms " .
                   "FROM audit_requests WHERE $whereSql ORDER BY id DESC LIMIT $auditLimit OFFSET $auditOffset";
            $dataSt = $pdo->prepare($sql);
            $dataSt->execute($bindings);
            $auditRows = $dataSt->fetchAll(\PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        // لو الجدول مش موجود أو حصل خطأ ← سكوت تام
        $auditRows = [];
        $auditTotal = 0;
    }
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
/* سجل العمليات */
.ops-input {
    padding: 7px 12px;
    border: 1px solid var(--line);
    border-radius: var(--r-md, 8px);
    background: var(--surface);
    color: var(--ink-800);
    font-family: inherit;
    font-size: var(--fs-sm, 13.5px);
}
/* تفاصيل المودال */
.ops-modal-backdrop {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.ops-modal {
    background: var(--surface);
    border-radius: var(--r-lg, 12px);
    max-width: 700px;
    width: 100%;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    overflow: hidden;
}
.ops-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
    font-weight: 700;
    font-size: 15px;
}
.ops-modal-body {
    padding: 16px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 13px;
    direction: ltr;
    text-align: left;
    white-space: pre-wrap;
    word-break: break-all;
    background: var(--bg);
}
.ops-badge-status {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
</style>

<div class="ops-wrap">
    <div class="ops-top">
        <h2 style="margin:0;font-size:22px;font-weight:800;color:var(--ink-900);">المراقبة</h2>
        <?php if ($tabParam === 'perf' && $canPerf): ?>
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
            <a href="ops.php?tab=perf&day=<?= e($selectedDay) ?>" class="ops-tab <?= $tabParam === 'perf' ? 'active' : '' ?>">
                <i class="ti ti-chart-bar"></i> السرعة
            </a>
        <?php endif; ?>
        <?php if ($canAudit): ?>
            <a href="ops.php?tab=audit" class="ops-tab <?= $tabParam === 'audit' ? 'active' : '' ?>">
                <i class="ti ti-clock"></i> سجل العمليات
            </a>
        <?php endif; ?>
    </div>

    <?php if ($tabParam === 'perf'): ?>
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

    <?php elseif ($tabParam === 'audit'): ?>
        <?php if (!$canAudit): ?>
            <div class="ops-card ops-empty">
                مالكش صلاحية تشوف سجل العمليات.
            </div>
        <?php else: ?>
            <!-- فلاتر سجل العمليات -->
            <div class="ops-card" style="margin-bottom:var(--sp-4, 16px);padding:14px 18px;">
                <form method="get" action="ops.php" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                    <input type="hidden" name="tab" value="audit">
                    
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">من تاريخ:</label>
                        <input type="date" name="from" value="<?= e($fromAudit) ?>" class="ops-input">
                    </div>
                    
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">إلى تاريخ:</label>
                        <input type="date" name="to" value="<?= e($toAudit) ?>" class="ops-input">
                    </div>

                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">المستخدم:</label>
                        <select name="user_id" class="ops-select">
                            <option value="">كل المستخدمين</option>
                            <?php foreach ($auditUsersList as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $uParam === (int)$u['id'] ? 'selected' : '' ?>>
                                    <?= e($u['full_name'] ?: $u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">الصفحة:</label>
                        <select name="page" class="ops-select">
                            <option value="">كل الصفحات</option>
                            <?php foreach ($auditPagesList as $p): ?>
                                <option value="<?= e($p) ?>" <?= $pParam === $p ? 'selected' : '' ?>>
                                    <?= e($p) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">بحث في العملية:</label>
                        <input type="text" name="action_search" value="<?= e($aParam) ?>" placeholder="اسم أو جزء من action" class="ops-input">
                    </div>

                    <div style="display:flex;gap:8px;margin-top:auto;">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-filter"></i> تصفية
                        </button>
                        <a href="ops.php?tab=audit" class="btn btn-ghost">
                            إلغاء
                        </a>
                        <a href="ops.php?tab=audit&export=audit&from=<?= urlencode($fromAudit) ?>&to=<?= urlencode($toAudit) ?>&user_id=<?= urlencode((string)($uParam ?? '')) ?>&page=<?= urlencode($pParam) ?>&action_search=<?= urlencode($aParam) ?>" class="btn btn-ghost" style="color:var(--pos);border-color:currentColor;">
                            <i class="ti ti-file-spreadsheet"></i> تصدير إكسيل
                        </a>
                    </div>
                </form>
            </div>

            <?php if (empty($auditRows)): ?>
                <div class="ops-card ops-empty">
                    مفيش عمليات مسجلة تطابق الفلاتر الحالية.
                </div>
            <?php else: ?>
                <!-- جدول سجل العمليات -->
                <div class="ops-table-container">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th class="num">الوقت</th>
                                <th>المستخدم</th>
                                <th>الصفحة</th>
                                <th>العملية</th>
                                <th class="num">الحالة</th>
                                <th class="num">المدة (ms)</th>
                                <th style="text-align:center;">التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditRows as $ar): ?>
                                <tr>
                                    <td class="num" style="color:var(--ink-500);"><?= e($ar['created_at']) ?></td>
                                    <td>
                                        <strong><?= e($ar['username'] ?? ('مستخدم #' . ($ar['user_id'] ?? '—'))) ?></strong>
                                    </td>
                                    <td class="ops-page-name"><?= e($ar['page']) ?></td>
                                    <td>
                                        <?php if (!empty($ar['action'])): ?>
                                            <code><?= e($ar['action']) ?></code>
                                        <?php else: ?>
                                            <span style="color:var(--ink-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php
                                            $st = (int) ($ar['status'] ?? 200);
                                            $stStyle = ($st >= 400) ? 'color:var(--neg);font-weight:700;' : 'color:var(--pos);font-weight:600;';
                                        ?>
                                        <span style="<?= $stStyle ?>"><?= $st ?></span>
                                    </td>
                                    <td class="num" style="<?= ops_ms_style((float)($ar['ms'] ?? 0)) ?>">
                                        <?= number_format((int)($ar['ms'] ?? 0)) ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (!empty($ar['params'])): ?>
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="showAuditModal(<?= (int)$ar['id'] ?>)">
                                                <i class="ti ti-eye"></i> التفاصيل
                                            </button>
                                            <template id="audit-params-<?= (int)$ar['id'] ?>"><?= e((string)$ar['params']) ?></template>
                                        <?php else: ?>
                                            <span style="color:var(--ink-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ترقيم الصفحات -->
                <?php
                    $totalPages = (int) ceil($auditTotal / $auditLimit);
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 4px;margin-bottom:24px;">
                    <div style="font-size:13px;color:var(--ink-500);">
                        إجمالي العمليات: <strong><?= number_format($auditTotal) ?></strong> (صفحة <?= $auditPageNum ?> من <?= max(1, $totalPages) ?>)
                    </div>
                    <div style="display:flex;gap:8px;">
                        <?php if ($auditPageNum > 1): ?>
                            <a href="ops.php?tab=audit&from=<?= urlencode($fromAudit) ?>&to=<?= urlencode($toAudit) ?>&user_id=<?= urlencode((string)($uParam ?? '')) ?>&page=<?= urlencode($pParam) ?>&action_search=<?= urlencode($aParam) ?>&p=<?= $auditPageNum - 1 ?>" class="btn btn-ghost btn-sm">
                                <i class="ti ti-chevron-left" style="transform:rotate(180deg)"></i> الأحدث
                            </a>
                        <?php endif; ?>
                        <?php if ($auditPageNum < $totalPages): ?>
                            <a href="ops.php?tab=audit&from=<?= urlencode($fromAudit) ?>&to=<?= urlencode($toAudit) ?>&user_id=<?= urlencode((string)($uParam ?? '')) ?>&page=<?= urlencode($pParam) ?>&action_search=<?= urlencode($aParam) ?>&p=<?= $auditPageNum + 1 ?>" class="btn btn-ghost btn-sm">
                                الأقدم <i class="ti ti-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- نافذة عرض التفاصيل (Modal) -->
<div id="auditModalBackdrop" class="ops-modal-backdrop" onclick="if(event.target === this) closeAuditModal();">
    <div class="ops-modal">
        <div class="ops-modal-header">
            <span><i class="ti ti-list-details"></i> تفاصيل مدخلات العملية</span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeAuditModal()" style="border:none;">✕</button>
        </div>
        <pre id="auditModalContent" class="ops-modal-body"></pre>
        <div style="padding:10px 16px;border-top:1px solid var(--line);text-align:left;background:var(--surface);">
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeAuditModal()">إغلاق</button>
        </div>
    </div>
</div>

<script>
function showAuditModal(id) {
    const tpl = document.getElementById('audit-params-' + id);
    if (!tpl) return;
    const content = tpl.textContent || '';
    let formatted = content;
    try {
        const parsed = JSON.parse(content);
        formatted = JSON.stringify(parsed, null, 2);
    } catch (e) {
        // لو النص مقصوص بعلامة … أو مش JSON صالح، يتعرض كنص عادي
        formatted = content;
    }
    const pre = document.getElementById('auditModalContent');
    if (pre) {
        pre.textContent = formatted;
    }
    const modal = document.getElementById('auditModalBackdrop');
    if (modal) {
        modal.style.display = 'flex';
    }
}
function closeAuditModal() {
    const modal = document.getElementById('auditModalBackdrop');
    if (modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>