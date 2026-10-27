<?php
/**
 * ops.php — صفحة المراقبة: قياس سرعة وأداء الصفحات
 * نظام الفجالة ستور المالي (بريف ٩٩)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('ops');
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/ops_lib.php';
sens_page(); // من غيرها الفوتر مابيحمّلش سكربت القفل فزرار «اظهر الأرقام» مابيشتغلش

$canPerf    = can('ops:perf');
$canAudit   = can('ops:audit');
$canDevices = can('ops:devices');
$canDb      = can('ops:db');
// المدخلات فيها مرتبات ومبالغ — نفس قفل الأرقام الحساسة اللي على الأرباح والمرتبات
$auditOpen = $canAudit && sens_unlocked();

// معالجة إلغاء توكن جهاز
$deviceMsg = '';
$deviceErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_token') {
    if (!$canDevices) {
        $deviceErr = 'مالكش صلاحية إدارة الأجهزة';
    } elseif (!is_owner()) {
        $deviceErr = 'إلغاء أجهزة المستخدمين متاح للأونر بس';
    } elseif (!csrf_verify($_POST['csrf'] ?? '')) {
        $deviceErr = 'انتهت صلاحية الجلسة، حاول مرة أخرى';
    } else {
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        if ($tokenId > 0) {
            try {
                ops_revoke_token($tokenId);
                $deviceMsg = 'تم إلغاء التوكن للجهاز بنجاح';
            } catch (\Throwable $e) {
                $deviceErr = 'حصل خطأ أثناء إلغاء التوكن';
            }
        }
    }
}

$tabParam = trim((string) ($_GET['tab'] ?? ''));
if ($tabParam === '' || !in_array($tabParam, ['perf', 'audit', 'devices', 'db'], true)) {
    if ($canPerf) {
        $tabParam = 'perf';
    } elseif ($canAudit) {
        $tabParam = 'audit';
    } elseif ($canDevices) {
        $tabParam = 'devices';
    } elseif ($canDb) {
        $tabParam = 'db';
    } else {
        $tabParam = 'perf';
    }
}

$todayCairo = (new \DateTime('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');

// =====================================================================
//  تصدير إكسيل لسجل العمليات (قبل أي HTML وبنفس الحراسة)
// =====================================================================
$export = trim((string) ($_GET['export'] ?? ''));
if ($export === 'audit' && $auditOpen) {
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
$availableDays = [];
$selectedDay = trim((string) ($_GET['day'] ?? ''));
$totalRequests = 0;
$totalAllMs = 0.0;
$over1SecCount = 0;
$pagesStats = [];
$slowest20 = [];
$userNames = [];
$overallAvg = 0.0;

if ($canPerf) {
    $perfData = ops_perf_data($selectedDay !== '' ? $selectedDay : null);
    $availableDays  = $perfData['available_days'];
    $selectedDay    = $perfData['selected_day'];
    if ($tabParam === 'perf') {
        $totalRequests  = $perfData['total_requests'];
        $overallAvg     = $perfData['overall_avg'];
        $over1SecCount  = $perfData['over_1sec_count'];
        $pagesStats     = $perfData['pages_stats'];
        $slowest20      = $perfData['slowest_20'];
        $userNames      = $perfData['user_names'];
    }
}

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

if ($auditOpen && $tabParam === 'audit') {
    $auditData = ops_audit_data($fromAudit, $toAudit, $uParam, $pParam, $aParam, $auditPageNum, $auditLimit);
    $auditRows      = $auditData['rows'];
    $auditTotal     = $auditData['total'];
    $auditUsersList = $auditData['users_list'];
    $auditPagesList = $auditData['pages_list'];
}

// =====================================================================
//  تبويب «القاعدة» (بريف ١٠٨): ملفات local/migrations مقابل جدول schema_migrations
// =====================================================================
$dbRows = [];
$dbReady = true;
$dbPending = 0;
if ($canDb && $tabParam === 'db') {
    $dbData = ops_db_data();
    $dbReady   = $dbData['ready'];
    $dbPending = $dbData['pending_count'];
    $dbRows    = $dbData['rows'];
}

// =====================================================================
//  تجهيز بيانات تبويب الأجهزة (بريف ١٠٢)
// =====================================================================
$deviceRows = [];
if ($canDevices && $tabParam === 'devices') {
    $devData = ops_devices_data();
    $deviceRows = $devData['rows'];
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
.ops-filter-form {
    display: flex;
    align-items: center;
    gap: 8px;
}
.ftable td.ops-page-name {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    font-size: 13px;
    color: var(--accent-text);
    direction: ltr;
    text-align: left;
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
/* تفاصيل المودال */
.ops-modal-backdrop {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: var(--z-modal);
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
    font-variant-numeric: tabular-nums;
    font-size: 13px;
    direction: ltr;
    text-align: left;
    white-space: pre-wrap;
    word-break: break-all;
    background: var(--bg);
}
</style>

<div class="ops-wrap">
    <div class="ops-top">
        <h2 style="margin:0;font-size:22px;font-weight:800;color:var(--ink-900);">المراقبة</h2>
        <?php if ($tabParam === 'perf' && $canPerf): ?>
        <form method="get" action="ops.php" class="ops-filter-form">
            <input type="hidden" name="tab" value="perf">
            <label for="daySelect" style="font-size:13px;font-weight:600;color:var(--ink-600);">اليوم:</label>
            <select name="day" id="daySelect" class="fselect" onchange="this.form.submit()">
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
    <nav class="ftabs" style="margin-bottom:var(--sp-4);">
        <?php if ($canPerf): ?>
            <a href="ops.php?tab=perf&day=<?= e($selectedDay) ?>" class="ftab <?= $tabParam === 'perf' ? 'is-active' : '' ?>">
                <i class="ti ti-chart-bar"></i> السرعة
            </a>
        <?php endif; ?>
        <?php if ($canAudit): ?>
            <a href="ops.php?tab=audit" class="ftab <?= $tabParam === 'audit' ? 'is-active' : '' ?>">
                <i class="ti ti-clock"></i> سجل العمليات
            </a>
        <?php endif; ?>
        <?php if ($canDevices): ?>
            <a href="ops.php?tab=devices" class="ftab <?= $tabParam === 'devices' ? 'is-active' : '' ?>">
                <i class="ti ti-device-mobile"></i> الأجهزة
            </a>
        <?php endif; ?>
        <?php if ($canDb): ?>
            <a href="ops.php?tab=db" class="ftab <?= $tabParam === 'db' ? 'is-active' : '' ?>">
                <i class="ti ti-list-check"></i> القاعدة
            </a>
        <?php endif; ?>
    </nav>

    <?php if ($tabParam === 'perf'): ?>
        <?php if (!$canPerf): ?>
            <div class="fcard ops-empty">
                مالكش صلاحية تشوف سجل السرعة.
            </div>
        <?php elseif ($totalRequests === 0): ?>
            <div class="fcard ops-empty">
                مفيش بيانات مسجلة ليوم <?= e($selectedDay) ?> بعد.
            </div>
        <?php else: ?>
            <!-- كروت الإحصائيات -->
            <div class="fkpis" style="margin-bottom:var(--sp-6);">
                <div class="fkpi">
                    <span>إجمالي الطلبات</span>
                    <strong><?= number_format($totalRequests) ?></strong>
                </div>
                <div class="fkpi">
                    <span>المتوسط العام</span>
                    <strong style="<?= ops_ms_style($overallAvg) ?>">
                        <?= number_format($overallAvg, 1) ?> <span style="font-size:15px;font-weight:600;">ms</span>
                    </strong>
                </div>
                <div class="fkpi">
                    <span>طلبات فوق ثانية</span>
                    <strong style="<?= $over1SecCount > 0 ? 'color:var(--neg);' : 'color:var(--pos);' ?>">
                        <?= number_format($over1SecCount) ?>
                    </strong>
                </div>
            </div>

            <!-- جدول الصفحات -->
            <div class="ops-sec-title">
                <i class="ti ti-list"></i> أداء الصفحات
            </div>
            <div class="ftable-wrap">
                <table class="ftable">
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
            <div class="ftable-wrap">
                <table class="ftable">
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

    <?php elseif ($tabParam === 'audit' && !$auditOpen): ?>
        <?php sens_lock_screen('سجل العمليات'); ?>
    <?php elseif ($tabParam === 'audit'): ?>
        <?php if (!$canAudit): ?>
            <div class="fcard ops-empty">
                مالكش صلاحية تشوف سجل العمليات.
            </div>
        <?php else: ?>
            <!-- فلاتر سجل العمليات -->
            <div class="fcard" style="margin-bottom:var(--sp-4, 16px);padding:14px 18px;">
                <form method="get" action="ops.php" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                    <input type="hidden" name="tab" value="audit">
                    
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">من تاريخ:</label>
                        <input type="date" name="from" value="<?= e($fromAudit) ?>" class="finput">
                    </div>
                    
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">إلى تاريخ:</label>
                        <input type="date" name="to" value="<?= e($toAudit) ?>" class="finput">
                    </div>

                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:12px;font-weight:600;color:var(--ink-500);">المستخدم:</label>
                        <select name="user_id" class="fselect">
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
                        <select name="page" class="fselect">
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
                        <input type="text" name="action_search" value="<?= e($aParam) ?>" placeholder="اسم أو جزء من action" class="finput">
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
                <div class="fcard ops-empty">
                    مفيش عمليات مسجلة تطابق الفلاتر الحالية.
                </div>
            <?php else: ?>
                <!-- جدول سجل العمليات -->
                <div class="ftable-wrap">
                    <table class="ftable">
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
    <?php elseif ($tabParam === 'devices'): ?>
        <?php if (!$canDevices): ?>
            <div class="fcard ops-empty">
                مالكش صلاحية تشوف الأجهزة.
            </div>
        <?php else: ?>
            <?php if ($deviceMsg !== ''): ?>
                <div class="falert falert-pos" style="margin-bottom:16px;">
                    <?= e($deviceMsg) ?>
                </div>
            <?php endif; ?>
            <?php if ($deviceErr !== ''): ?>
                <div class="falert falert-neg" style="margin-bottom:16px;">
                    <?= e($deviceErr) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($deviceRows)): ?>
                <div class="fcard ops-empty">
                    مفيش أجهزة مسجلة حالياً.
                </div>
            <?php else: ?>
                <div class="ftable-wrap">
                    <table class="ftable">
                        <thead>
                            <tr>
                                <th>المستخدم</th>
                                <th>اسم الجهاز</th>
                                <th class="num">تاريخ الإنشاء</th>
                                <th class="num">آخر استخدام</th>
                                <th class="num">آخر IP</th>
                                <th style="text-align:center;">الحالة</th>
                                <?php if (is_owner()): ?>
                                    <th style="text-align:center;">إجراء</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deviceRows as $dr): ?>
                                <?php
                                    $isRevoked = !empty($dr['revoked_at']);
                                    $tokenPw = (int) ($dr['token_pw_ver'] ?? 1);
                                    $userPw  = (int) ($dr['user_pw_ver'] ?? 1);
                                    $pwChanged = ($tokenPw !== $userPw);
                                    $isUserActive = !empty($dr['is_active']);

                                    if ($isRevoked) {
                                        $statusLabel = 'ملغي';
                                        $statusClass = 'fchip fchip-neg';
                                    } elseif ($pwChanged) {
                                        $statusLabel = 'باسورد اتغيّر';
                                        $statusClass = 'fchip fchip-warn';
                                    } elseif (!$isUserActive) {
                                        $statusLabel = 'المستخدم معطّل';
                                        $statusClass = 'fchip fchip-mute';
                                    } else {
                                        $statusLabel = 'شغال';
                                        $statusClass = 'fchip fchip-pos';
                                    }

                                    $canRevoke = is_owner() && !$isRevoked;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($dr['full_name'] ?? ($dr['username'] ?? ('مستخدم #' . $dr['user_id']))) ?></strong>
                                        <div style="font-size:12px;color:var(--ink-500);"><?= e($dr['username'] ?? '') ?></div>
                                    </td>
                                    <td><?= e($dr['device_name']) ?></td>
                                    <td class="num" style="color:var(--ink-500);"><?= e($dr['created_at']) ?></td>
                                    <td class="num" style="color:var(--ink-600);"><?= e($dr['last_used_at'] ?? '—') ?></td>
                                    <td class="num" style="color:var(--ink-600);"><?= e($dr['last_ip'] ?? '—') ?></td>
                                    <td style="text-align:center;">
                                        <span class="<?= $statusClass ?>"><?= $statusLabel ?></span>
                                    </td>
                                    <?php if (is_owner()): ?>
                                        <td style="text-align:center;">
                                            <?php if ($canRevoke): ?>
                                                <form method="post" action="ops.php?tab=devices" style="display:inline;" onsubmit="return confirm('متأكد إنك عاوز تلغي الجهاز ده؟');">
                                                    <input type="hidden" name="action" value="revoke_token">
                                                    <input type="hidden" name="token_id" value="<?= (int) $dr['id'] ?>">
                                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--neg);border-color:currentColor;">
                                                        إلغاء
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:var(--ink-400);">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($tabParam === 'db' && $canDb): ?>
        <?php if (!$dbReady): ?>
            <div class="fcard ops-empty">
                جدول تعديلات القاعدة لسه ماتعملش — شغّل
                <b>local/migrations/20261004_000_schema_migrations.sql</b> الأول من phpMyAdmin على قاعدة الفجالة.
            </div>
        <?php else: ?>
            <?php if ($dbPending > 0): ?>
                <p style="margin:0 0 12px;color:var(--warn);font-weight:600;">
                    فيه <?= (int) $dbPending ?> ملف محتاج يتشغّل على قاعدة الفجالة بالترتيب ده.
                </p>
            <?php endif; ?>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th>الملف</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dbRows as $dr): ?>
                            <tr>
                                <td class="name" title="<?= e($dr['name']) ?>"><?= e($dr['name']) ?>.sql</td>
                                <td>
                                    <?php if ($dr['applied_at'] !== null): ?>
                                        <span class="fchip fchip-pos">اتشغّل <?= e(substr($dr['applied_at'], 0, 16)) ?></span>
                                    <?php else: ?>
                                        <span class="fchip fchip-warn">مستني يتشغّل</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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