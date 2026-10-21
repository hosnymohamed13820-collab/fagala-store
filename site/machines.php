<?php
/**
 * machines.php — موديول عداد المكن ومطابقة استهلاك الورق بالمبيعات
 * نظام الفجالة ستور المالي (بريف ١٩٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('machines');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/machines_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

$pdo = db();
$user = current_user();
$uid = (int) ($user['id'] ?? 0);

// =========================================================================
//  معالجة طلبات POST
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // التحقق من CSRF
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        die('انتهت صلاحية الجلسة، أعد تحميل الصفحة.');
    }

    // ١) حفظ قراءة عداد (على blur)
    if ($action === 'save_reading') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $mid     = (int) ($_POST['machine_id'] ?? 0);
            $date    = trim((string) ($_POST['date'] ?? ''));
            $counter = (int) ($_POST['counter'] ?? 0);
            $notes   = trim((string) ($_POST['notes'] ?? ''));

            $res = machine_reading_save($pdo, $mid, $date, $counter, $notes !== '' ? $notes : null, $uid);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ٢) حفظ مكنة (للمالك فقط)
    if ($action === 'save_machine') {
        if (!is_owner()) {
            http_response_code(403);
            die('هذا الإجراء متاح للمالك فقط.');
        }
        $mId = (int) ($_POST['machine_id'] ?? 0);
        $data = [
            'branch_id'  => (int) ($_POST['branch_id'] ?? 0),
            'name'       => trim((string) ($_POST['name'] ?? '')),
            'kind'       => (string) ($_POST['kind'] ?? 'copy'),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
        ];
        try {
            machines_save($pdo, $data, $mId > 0 ? $mId : null);
            header('Location: machines.php?tab=machines&saved=1');
        } catch (Throwable $e) {
            die('خطأ في حفظ المكنة: ' . htmlspecialchars($e->getMessage()));
        }
        exit;
    }

    // ٣) تفعيل/تعطيل مكنة (للمالك فقط)
    if ($action === 'toggle_machine') {
        if (!is_owner()) {
            http_response_code(403);
            die('هذا الإجراء متاح للمالك فقط.');
        }
        $mId = (int) ($_POST['machine_id'] ?? 0);
        $active = !empty($_POST['active']);
        machines_toggle_active($pdo, $mId, $active);
        header('Location: machines.php?tab=machines');
        exit;
    }
}

// =========================================================================
//  تصدير إكسيل لتقرير المطابقة (SimpleXlsx)
// =========================================================================

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $expBranch = isset($_GET['branch']) && $_GET['branch'] !== 'all' ? (int) $_GET['branch'] : null;
    $expFrom   = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-14 days')));
    $expTo     = (string) ($_GET['to'] ?? date('Y-m-d'));

    $reportData = machine_reconciliation_report($pdo, $expBranch, $expFrom, $expTo);

    $x = new SimpleXlsx('مطابقة عداد المكن');
    $x->addRow(['الفرع', 'التاريخ', 'استهلاك العدادات', 'مبيعات الخدمات (ورقة)', 'الفرق (العداد - المباع)', 'الحالة']);

    foreach ($reportData as $row) {
        $statusTxt = match ($row['status']) {
            'deficit' => 'عجز (' . $row['diff'] . ')',
            'surplus' => 'زيادة (' . abs($row['diff']) . ')',
            'match'   => 'مطابق',
            default   => 'قراءة ناقصة',
        };
        $x->addRow([
            $row['branch_name'],
            $row['date'],
            $row['counter_consumption'] !== null ? $row['counter_consumption'] : '—',
            (int) $row['sold_qty'],
            $row['diff'] !== null ? $row['diff'] : '—',
            $statusTxt,
        ]);
    }

    $x->download('machine_recon_' . date('Ymd_His') . '.xlsx');
    exit;
}

// =========================================================================
//  إعداد العرض والمتغيرات
// =========================================================================

$tab = (string) ($_GET['tab'] ?? 'readings');
if (!in_array($tab, ['readings', 'recon', 'machines'], true)) {
    $tab = 'readings';
}
if ($tab === 'machines' && !is_owner()) {
    $tab = 'readings';
}

$branches = $pdo->query("SELECT id, name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// فلاتر التابات
$selectedBranch = isset($_GET['branch']) && $_GET['branch'] !== 'all' ? (int) $_GET['branch'] : null;
$dateFrom = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-13 days')));
$dateTo   = (string) ($_GET['to'] ?? date('Y-m-d'));

$pageTitle = 'عداد المكن';
$active = 'machines';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.tab-nav {
    display: flex;
    gap: 8px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 20px;
}
.tab-btn {
    padding: 10px 18px;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink-500);
    cursor: pointer;
    font-family: inherit;
    border-bottom: 2px solid transparent;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
}
.tab-btn:hover {
    color: var(--accent-text);
}
.tab-btn.active {
    color: var(--accent-text);
    border-bottom-color: var(--accent);
}

.filter-bar {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 14px 16px;
    margin-bottom: 18px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-bar select, .filter-bar input[type="date"], .filter-bar input[type="text"] {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-sm, 6px);
    padding: 6px 10px;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
}

/* جدول القراءات */
.grid-table-wrap {
    overflow-x: auto;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 1px;
}
.counter-input {
    width: 100%;
    min-width: 110px;
    border: 1px solid transparent;
    border-radius: var(--r-sm, 4px);
    padding: 6px 8px;
    text-align: center;
    font-size: 13.5px;
    font-weight: 600;
    font-family: var(--font-mono, monospace);
    background: transparent;
    color: var(--text);
    transition: all 0.15s;
}
.counter-input:focus {
    background: var(--surface);
    border-color: var(--accent);
    outline: none;
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.counter-input.is-saving {
    background: var(--warn-soft);
}
.counter-input.is-saved {
    background: var(--pos-soft);
}
.counter-diff {
    font-size: 10.5px;
    color: var(--ink-400);
    display: block;
    margin-top: 2px;
}
.counter-diff.diff-pos {
    color: var(--pos);
    font-weight: 600;
}
.counter-diff.diff-warn {
    color: var(--warn);
    font-weight: 700;
}
.empty-box {
    text-align: center;
    padding: 40px 16px;
    color: var(--ink-400);
}
</style>

<div class="content-container">

    <!-- شريط التابات -->
    <div class="tab-nav">
        <a href="machines.php?tab=readings" class="tab-btn <?= $tab === 'readings' ? 'active' : '' ?>">
            <i class="ti ti-checklist"></i> القراءات اليومية
        </a>
        <a href="machines.php?tab=recon" class="tab-btn <?= $tab === 'recon' ? 'active' : '' ?>">
            <i class="ti ti-calculator"></i> مطابقة الاستهلاك بالمبيعات
        </a>
        <?php if (is_owner()): ?>
            <a href="machines.php?tab=machines" class="tab-btn <?= $tab === 'machines' ? 'active' : '' ?>">
                <i class="ti ti-printer"></i> إدارة المكن
            </a>
        <?php endif; ?>
    </div>

    <!-- ===================================================================== -->
    <!--  تاب ١: القراءات اليومية (Readings Matrix)                            -->
    <!-- ===================================================================== -->
    <?php if ($tab === 'readings'): ?>
        <?php
        $activeMachines = machines_list($pdo, $selectedBranch, true);
        $machineIds = array_map(fn($m) => (int)$m['id'], $activeMachines);

        // مصفوفة القراءات
        $matrix = machine_readings_matrix($pdo, $machineIds, $dateFrom, $dateTo);

        // توليد الأيام تنازلياً
        $daysList = [];
        $c = strtotime($dateTo);
        $e = strtotime($dateFrom);
        while ($c >= $e) {
            $daysList[] = date('Y-m-d', $c);
            $c = strtotime('-1 day', $c);
        }
        ?>

        <form method="GET" action="machines.php" class="filter-bar">
            <input type="hidden" name="tab" value="readings">

            <div style="display:flex;align-items:center;gap:6px;">
                <label for="fBranch" style="font-size:12.5px;font-weight:600;color:var(--ink-500);">الفرع:</label>
                <select name="branch" id="fBranch" onchange="this.form.submit()">
                    <option value="all">كل الفروع</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $selectedBranch === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:12.5px;font-weight:600;color:var(--ink-500);">الفترة:</label>
                <input type="date" name="from" value="<?= e($dateFrom) ?>">
                <span style="color:var(--ink-400);">إلى</span>
                <input type="date" name="to" value="<?= e($dateTo) ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti ti-filter"></i> عرض
            </button>
            <span style="margin-right:auto;font-size:12px;color:var(--ink-400);">
                الحفظ يتم تلقائياً عند الانتقال من الخلية (blur) · التنقل بالأسهم و Enter
            </span>
        </form>

        <?php if (empty($activeMachines)): ?>
            <div class="card empty-box">
                <i class="ti ti-printer" style="font-size:36px;display:block;margin-bottom:8px;"></i>
                لا توجد مكنات نشطة مسجلة في هذا الفرع.
                <?php if (is_owner()): ?>
                    <div style="margin-top:10px;">
                        <a href="machines.php?tab=machines" class="btn btn-primary btn-sm">إضافة مكنة جديدة</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid-table-wrap">
                <table class="ftable" id="readingsGrid" style="width:100%;margin:0;">
                    <thead>
                        <tr>
                            <th style="width:120px;text-align:center;">التاريخ</th>
                            <?php foreach ($activeMachines as $idx => $m): ?>
                                <th style="text-align:center;min-width:130px;">
                                    <b><?= e($m['name']) ?></b>
                                    <div style="font-size:10.5px;color:var(--ink-400);font-weight:normal;">
                                        <?= e($m['branch_name']) ?> (<?= $m['kind'] === 'copy' ? 'تصوير' : 'طباعة' ?>)
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daysList as $rIdx => $d): ?>
                            <tr>
                                <td style="text-align:center;font-weight:600;font-size:12.5px;white-space:nowrap;" dir="ltr">
                                    <?= e($d) ?>
                                </td>
                                <?php foreach ($activeMachines as $cIdx => $m):
                                    $mid = (int) $m['id'];
                                    $prevDay = date('Y-m-d', strtotime($d . ' -1 day'));
                                    $cell = $matrix[$mid][$d] ?? null;
                                    $prevCell = $matrix[$mid][$prevDay] ?? null;
                                    $val = $cell !== null ? $cell['counter'] : '';

                                    $diff = null;
                                    $isReset = false;
                                    if ($cell !== null && $prevCell !== null) {
                                        $diff = $cell['counter'] - $prevCell['counter'];
                                        if ($cell['counter'] < $prevCell['counter']) {
                                            $isReset = true;
                                        }
                                    }
                                ?>
                                    <td style="text-align:center;padding:4px 6px;">
                                        <input type="number" 
                                               class="counter-input"
                                               data-machine="<?= $mid ?>"
                                               data-date="<?= e($d) ?>"
                                               data-row="<?= $rIdx ?>"
                                               data-col="<?= $cIdx ?>"
                                               value="<?= e((string)$val) ?>"
                                               placeholder="—"
                                               onblur="handleReadingBlur(this)">
                                        <div class="cell-status">
                                            <?php if ($isReset): ?>
                                                <span class="counter-diff diff-warn" title="تصفير عداد: أقل من اليوم السابق">
                                                    <i class="ti ti-alert-triangle"></i> تصفير (<?= $diff ?>)
                                                </span>
                                            <?php elseif ($diff !== null): ?>
                                                <span class="counter-diff diff-pos">
                                                    +<?= number_format($diff) ?> ورقة
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <!-- ===================================================================== -->
    <!--  تاب ٢: مطابقة الاستهلاك بالمبيعات (Reconciliation)                   -->
    <!-- ===================================================================== -->
    <?php elseif ($tab === 'recon'): ?>
        <?php
        $reconRows = machine_reconciliation_report($pdo, $selectedBranch, $dateFrom, $dateTo);
        ?>

        <form method="GET" action="machines.php" class="filter-bar">
            <input type="hidden" name="tab" value="recon">

            <div style="display:flex;align-items:center;gap:6px;">
                <label for="rBranch" style="font-size:12.5px;font-weight:600;color:var(--ink-500);">الفرع:</label>
                <select name="branch" id="rBranch">
                    <option value="all">كل الفروع</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $selectedBranch === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:12.5px;font-weight:600;color:var(--ink-500);">الفترة:</label>
                <input type="date" name="from" value="<?= e($dateFrom) ?>">
                <span style="color:var(--ink-400);">إلى</span>
                <input type="date" name="to" value="<?= e($dateTo) ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti ti-filter"></i> عرض
            </button>

            <a href="machines.php?export=excel&branch=<?= e((string)($selectedBranch ?: 'all')) ?>&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>"
               class="btn btn-ghost btn-sm" style="margin-right:auto;border:1px solid var(--line);">
                <i class="ti ti-file-spreadsheet"></i> تصدير إكسيل
            </a>
        </form>

        <div class="grid-table-wrap">
            <table class="ftable" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th>الفرع</th>
                        <th style="width:115px;text-align:center;">التاريخ</th>
                        <th style="text-align:center;width:150px;">استهلاك العدادات</th>
                        <th style="text-align:center;width:150px;">المباع (قسم الخدمات)</th>
                        <th style="text-align:center;width:140px;">الفرق (العداد - المباع)</th>
                        <th style="text-align:center;width:130px;">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reconRows)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-box">
                                    <i class="ti ti-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                                    لا توجد مكنات أو قراءات مسجلة في هذه الفترة.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reconRows as $row): ?>
                            <tr>
                                <td style="font-weight:600;">
                                    <?= e($row['branch_name']) ?>
                                </td>
                                <td style="text-align:center;font-size:12px;color:var(--ink-500);" dir="ltr">
                                    <?= e($row['date']) ?>
                                </td>
                                <td style="text-align:center;font-weight:700;">
                                    <?php if ($row['counter_consumption'] !== null): ?>
                                        <?= number_format($row['counter_consumption']) ?> ورقة
                                        <?php if ($row['has_unnoted_reset']): ?>
                                            <span style="color:var(--warn);font-size:11px;" title="تم استبعاد عداد تم تصفيره بدون ملاحظة">
                                                <i class="ti ti-alert-triangle"></i>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--ink-400);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;font-weight:600;">
                                    <?= number_format((int)$row['sold_qty']) ?> ورقة
                                </td>
                                <td style="text-align:center;font-weight:700;">
                                    <?php if ($row['diff'] !== null): ?>
                                        <span style="color:<?= $row['diff'] > 0 ? 'var(--neg)' : ($row['diff'] < 0 ? 'var(--pos)' : 'var(--ink-700)') ?>;">
                                            <?= ($row['diff'] > 0 ? '+' : '') . number_format($row['diff']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--ink-400);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($row['status'] === 'deficit'): ?>
                                        <span class="fchip fchip-neg" title="ورق خارج من المكن بدون فواتير بيع">
                                            عجز (<?= number_format($row['diff']) ?>)
                                        </span>
                                    <?php elseif ($row['status'] === 'surplus'): ?>
                                        <span class="fchip fchip-pos" title="فواتير بيع أكثر من استهلاك العداد">
                                            زيادة (<?= number_format(abs($row['diff'])) ?>)
                                        </span>
                                    <?php elseif ($row['status'] === 'match'): ?>
                                        <span class="fchip fchip-pos">مطابق</span>
                                    <?php else: ?>
                                        <span class="fchip fchip-warn" title="ناقص قراءة اليوم أو اليوم السابق">
                                            قراءة ناقصة
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <!-- ===================================================================== -->
    <!--  تاب ٣: إدارة المكن (Machines Management) — المالك فقط                -->
    <!-- ===================================================================== -->
    <?php elseif ($tab === 'machines' && is_owner()): ?>
        <?php
        $allMachines = machines_list($pdo, null, false);
        ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;">
            <!-- جدول المكنات الحالية -->
            <div class="card" style="padding:16px;">
                <h3 style="font-size:15px;margin:0 0 14px 0;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-printer" style="color:var(--accent-text);"></i> المكنات المسجلة
                </h3>
                <table class="ftable" style="width:100%;margin:0;">
                    <thead>
                        <tr>
                            <th>الفرع</th>
                            <th>اسم المكنة</th>
                            <th style="width:70px;text-align:center;">النوع</th>
                            <th style="width:70px;text-align:center;">الحالة</th>
                            <th style="width:60px;text-align:center;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allMachines)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:20px;color:var(--ink-400);">لا توجد مكنات مسجلة بعد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allMachines as $m): ?>
                                <tr>
                                    <td><?= e($m['branch_name']) ?></td>
                                    <td><b><?= e($m['name']) ?></b></td>
                                    <td style="text-align:center;font-size:12px;">
                                        <?= $m['kind'] === 'copy' ? 'تصوير' : 'طباعة' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="fchip <?= $m['is_active'] ? 'fchip-pos' : 'fchip-warn' ?>">
                                            <?= $m['is_active'] ? 'نشطة' : 'معطلة' ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <form method="POST" action="machines.php" style="margin:0;display:inline;">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_machine">
                                            <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                            <input type="hidden" name="active" value="<?= $m['is_active'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 6px;" title="<?= $m['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                                <i class="ti <?= $m['is_active'] ? 'ti-ban' : 'ti-check' ?>"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- فورم إضافة مكنة جديدة -->
            <div class="card" style="padding:16px;">
                <h3 style="font-size:15px;margin:0 0 14px 0;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-plus" style="color:var(--accent-text);"></i> إضافة مكنة جديدة
                </h3>
                <form method="POST" action="machines.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_machine">

                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:4px;color:var(--ink-500);">الفرع:</label>
                        <select name="branch_id" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:var(--r-sm, 6px);background:var(--surface);color:var(--text);" required>
                            <option value="">اختر الفرع...</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:4px;color:var(--ink-500);">اسم المكنة / الموديل:</label>
                        <input type="text" name="name" placeholder="مثلاً: ريكو أبيض وأسود 1" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:var(--r-sm, 6px);background:var(--surface);color:var(--text);box-sizing:border-box;" required>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:4px;color:var(--ink-500);">النوع:</label>
                        <select name="kind" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:var(--r-sm, 6px);background:var(--surface);color:var(--text);">
                            <option value="copy">تصوير (Copy)</option>
                            <option value="print">طباعة (Print)</option>
                        </select>
                    </div>

                    <div style="margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="mActive" checked>
                        <label for="mActive" style="font-size:13px;cursor:pointer;">مكنة نشطة في العمل</label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="ti ti-device-floppy"></i> حفظ المكنة
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
var CSRF = <?= json_encode(csrf_token()) ?>;

// التنقل بالأسهم داخل جدول القراءات
document.addEventListener('keydown', function(e) {
    var target = e.target;
    if (!target || !target.classList.contains('counter-input')) return;

    var key = e.key;
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'].indexOf(key) === -1) return;

    var r = parseInt(target.getAttribute('data-row'), 10);
    var c = parseInt(target.getAttribute('data-col'), 10);
    var nextRow = r;
    var nextCol = c;

    if (key === 'ArrowUp') nextRow = r - 1;
    if (key === 'ArrowDown' || key === 'Enter') nextRow = r + 1;
    if (key === 'ArrowLeft') nextCol = c + 1; // اتجاه RTL
    if (key === 'ArrowRight') nextCol = c - 1;

    var selector = '.counter-input[data-row="' + nextRow + '"][data-col="' + nextCol + '"]';
    var nextInput = document.querySelector(selector);
    if (nextInput) {
        e.preventDefault();
        nextInput.focus();
        nextInput.select();
    }
});

function handleReadingBlur(input) {
    var val = (input.value || '').trim();
    if (val === '') return;

    var num = parseInt(val, 10);
    if (isNaN(num) || num < 0) {
        alert('القراءة يجب أن تكون رقماً موجباً.');
        input.focus();
        return;
    }

    var mid = input.getAttribute('data-machine');
    var d = input.getAttribute('data-date');
    input.classList.add('is-saving');

    var fd = new FormData();
    fd.append('action', 'save_reading');
    fd.append('csrf', CSRF);
    fd.append('machine_id', mid);
    fd.append('date', d);
    fd.append('counter', num);

    fetch('machines.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        input.classList.remove('is-saving');
        if (data.ok) {
            input.classList.add('is-saved');
            setTimeout(function() { input.classList.remove('is-saved'); }, 1200);

            var parentTd = input.closest('td');
            var statusDiv = parentTd ? parentTd.querySelector('.cell-status') : null;
            if (statusDiv) {
                if (data.is_reset) {
                    statusDiv.innerHTML = '<span class="counter-diff diff-warn" title="تصفير عداد"><i class="ti ti-alert-triangle"></i> تصفير (' + data.diff + ')</span>';
                } else if (data.diff !== null) {
                    statusDiv.innerHTML = '<span class="counter-diff diff-pos">+' + data.diff.toLocaleString() + ' ورقة</span>';
                }
            }
        } else {
            alert(data.error || 'فشل حفظ القراءة');
        }
    })
    .catch(() => {
        input.classList.remove('is-saving');
        alert('تعذر الاتصال بالسيرفر');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
