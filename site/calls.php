<?php
/**
 * calls.php — صفحة متابعة مكالمات الفروع (بريف ٢٠٩)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calls_lib.php';

// التحقق من الصلاحية
require_permission('calls');

$pdo = db();
$isOwner = is_owner();
$userBranchId = (int)($_SESSION['user']['branch_id'] ?? 0);
$branchScope = calls_resolve_branch_scope($pdo, $isOwner, $userBranchId, $_GET);
$branches = $branchScope['branches'];
$selectedBranch = $branchScope['selected_branch'];

// التحقق من ربط حساب غير المالك بفرع
if ($branchScope['branch_missing']) {
    $active_module = 'calls';
    $page_title = 'متابعة المكالمات';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="fcard" style="text-align:center;padding:40px;color:var(--ink-500);margin:20px auto;max-width:500px;">
            <h3>حسابك مش مربوط بفرع</h3>
            <p>برجاء مراجعة الإدارة لربط حسابك بالفرع التابع له.</p>
          </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// فلتر الفترة
$periodFilter = calls_resolve_period($_GET);
$period = $periodFilter['period'];
$dateFrom = $periodFilter['date_from'];
$dateTo = $periodFilter['date_to'];

// التابات الثلاثة
$view = (string)($_GET['view'] ?? 'missed');
if (!in_array($view, ['missed', 'log', 'summary'], true)) {
    $view = 'missed';
}

$followupMinutes = calls_get_followup_minutes($pdo);

// جلب بيانات التاب المختار فقط
$missedData = null;
$logData = null;
$summaryData = null;

if ($view === 'missed') {
    $missedData = calls_get_missed_data($pdo, $selectedBranch, $dateFrom, $dateTo, $followupMinutes);
} elseif ($view === 'log') {
    $logData = calls_get_log_data($pdo, $selectedBranch, $dateFrom, $dateTo, 500);
} elseif ($view === 'summary') {
    $summaryData = calls_get_summary_data($pdo, $selectedBranch, $dateFrom, $dateTo, $followupMinutes);
}

// دالة مساعدة لبناء الروابط
$buildUrl = function (array $overrides) use ($view, $period, $selectedBranch, $dateFrom, $dateTo, $isOwner): string {
    return calls_build_url($overrides, $view, $period, $selectedBranch, $dateFrom, $dateTo, $isOwner);
};

$active_module = 'calls';
$page_title = 'متابعة المكالمات';
require_once __DIR__ . '/includes/header.php';
?>

<style>
  .calls-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
  .calls-head h2 { margin:0; font-size:1.25rem; color:var(--ink-900); }
  .followup-box { display:flex; align-items:center; gap:8px; background:var(--surface); padding:6px 12px; border-radius:8px; border:1px solid var(--line); font-size:0.875rem; }
  .followup-box .finput { width:65px; text-align:center; }

  .period-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; font-size:13px; }
  .period-bar .custom-form { display:inline-flex; align-items:center; gap:6px; margin-inline-start:6px; }

  .table-responsive { width:100%; overflow-x:auto; }
  .tel-link { color:var(--accent-text); text-decoration:none; font-weight:600; }
  .tel-link:hover { text-decoration:underline; }
</style>

<div class="calls-head">
    <h2><i class="ti ti-device-mobile"></i> متابعة المكالمات</h2>

    <?php if ($isOwner): ?>
    <div class="followup-box">
        <label for="followup_input">مهلة المتابعة:</label>
        <input class="finput finput-sm num" type="number" id="followup_input" min="5" max="240" value="<?= $followupMinutes ?>"
               data-csrf="<?= csrf_token() ?>" title="تعديل المهلة بالدقائق (يتم الحفظ تلقائياً عند الخروج)">
        <span>دقيقة</span>
        <span id="followup_status" style="font-size:12px;display:none;"></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($isOwner): ?>
<div class="ftabs">
    <a href="<?= e($buildUrl(['branch' => null])) ?>" class="ftab <?= $selectedBranch === null ? 'is-active' : '' ?>">
        الكل
    </a>
    <?php foreach ($branches as $bId => $bName): ?>
    <a href="<?= e($buildUrl(['branch' => $bId])) ?>" class="ftab <?= $selectedBranch === $bId ? 'is-active' : '' ?>">
        <?= e($bName) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- فلتر الفترة -->
<div class="ftabs period-bar">
    <a href="<?= e($buildUrl(['period' => 'today'])) ?>" class="ftab <?= $period === 'today' ? 'is-active' : '' ?>">النهارده</a>
    <a href="<?= e($buildUrl(['period' => 'yesterday'])) ?>" class="ftab <?= $period === 'yesterday' ? 'is-active' : '' ?>">امبارح</a>
    <a href="<?= e($buildUrl(['period' => '7days'])) ?>" class="ftab <?= $period === '7days' ? 'is-active' : '' ?>">٧ أيام</a>
    <a href="<?= e($buildUrl(['period' => '30days'])) ?>" class="ftab <?= $period === '30days' ? 'is-active' : '' ?>">٣٠ يوم</a>
    <a href="<?= e($buildUrl(['period' => 'custom'])) ?>" class="ftab <?= $period === 'custom' ? 'is-active' : '' ?>">مخصص</a>

    <?php if ($period === 'custom'): ?>
    <form method="GET" action="calls.php" class="custom-form">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="hidden" name="period" value="custom">
        <?php if ($isOwner && $selectedBranch !== null): ?>
        <input type="hidden" name="branch" value="<?= $selectedBranch ?>">
        <?php endif; ?>
        <input class="finput finput-sm" type="date" name="from" value="<?= e($dateFrom) ?>" required>
        <span>إلى</span>
        <input class="finput finput-sm" type="date" name="to" value="<?= e($dateTo) ?>" required>
        <button class="btn btn-sm" type="submit">تطبيق</button>
    </form>
    <?php endif; ?>
</div>

<!-- تابات العرض الثلاثة -->
<div class="ftabs">
    <a href="<?= e($buildUrl(['view' => 'missed'])) ?>" class="ftab <?= $view === 'missed' ? 'is-active' : '' ?>">
        فايتة من غير رد
    </a>
    <a href="<?= e($buildUrl(['view' => 'log'])) ?>" class="ftab <?= $view === 'log' ? 'is-active' : '' ?>">
        السجل
    </a>
    <a href="<?= e($buildUrl(['view' => 'summary'])) ?>" class="ftab <?= $view === 'summary' ? 'is-active' : '' ?>">
        ملخص الفروع
    </a>
</div>

<!-- محتوى التاب المختار -->
<?php if ($view === 'missed' && $missedData !== null): ?>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead>
                <tr>
                    <th>الوقت</th>
                    <?php if ($isOwner && $selectedBranch === null): ?>
                    <th>الفرع</th>
                    <?php endif; ?>
                    <th>الرقم</th>
                    <th>النوع</th>
                    <th class="num">مرات الاتصال اليوم</th>
                    <th>الحالة</th>
                    <th class="num">وقت الرد (دقايق)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($missedData['rows'])): ?>
                <tr>
                    <td colspan="<?= ($isOwner && $selectedBranch === null) ? 7 : 6 ?>" style="text-align:center;padding:30px;color:var(--ink-500);">
                        مفيش مكالمات فايتة في الفترة المحددة.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($missedData['rows'] as $r): ?>
                    <tr>
                        <td><?= e(date('Y-m-d H:i', strtotime($r['call_at']))) ?></td>
                        <?php if ($isOwner && $selectedBranch === null): ?>
                        <td><?= e($r['branch_name']) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($r['number_norm']): ?>
                            <a href="tel:<?= e($r['number_norm']) ?>" class="tel-link" dir="ltr"><?= e($r['number']) ?></a>
                            <?php else: ?>
                            <span style="color:var(--ink-500);">مخفي</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fchip <?= e($r['type_badge']) ?>"><?= e($r['type_label']) ?></span>
                        </td>
                        <td class="num"><?= (int)$r['daily_count'] ?></td>
                        <td>
                            <span class="fchip <?= e($r['chip_class']) ?>"><?= e($r['status']) ?></span>
                        </td>
                        <td class="num">
                            <?= $r['after_min'] !== null ? (int)$r['after_min'] : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($missedData['hidden_count'] > 0): ?>
    <div class="fcard" style="margin-top:12px;padding:12px 18px;color:var(--ink-500);text-align:center;font-size:14px;">
        <?= (int)$missedData['hidden_count'] ?> مكالمة فايتة من رقم مخفي
    </div>
    <?php endif; ?>

<?php elseif ($view === 'log' && $logData !== null): ?>
    <div style="margin-bottom:10px;font-size:13px;color:var(--ink-500);">
        ظاهر أول <?= count($logData['rows']) ?> من <?= (int)$logData['total_count'] ?>
    </div>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead>
                <tr>
                    <th>الوقت</th>
                    <?php if ($isOwner && $selectedBranch === null): ?>
                    <th>الفرع</th>
                    <?php endif; ?>
                    <th>الرقم</th>
                    <th>النوع</th>
                    <th class="num">المدة (دقايق:ثواني)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logData['rows'])): ?>
                <tr>
                    <td colspan="<?= ($isOwner && $selectedBranch === null) ? 5 : 4 ?>" style="text-align:center;padding:30px;color:var(--ink-500);">
                        مفيش مكالمات مسجلة في الفترة المحددة.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logData['rows'] as $r): ?>
                    <tr>
                        <td><?= e(date('Y-m-d H:i', strtotime($r['call_at']))) ?></td>
                        <?php if ($isOwner && $selectedBranch === null): ?>
                        <td><?= e($r['branch_name']) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($r['number_norm']): ?>
                            <a href="tel:<?= e($r['number_norm']) ?>" class="tel-link" dir="ltr"><?= e($r['number']) ?></a>
                            <?php else: ?>
                            <span style="color:var(--ink-500);"><?= e($r['number'] ?: 'مخفي') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fchip <?= e($r['type_badge']) ?>"><?= e($r['type_label']) ?></span>
                        </td>
                        <td class="num"><?= e($r['duration_str']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($view === 'summary' && $summaryData !== null): ?>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead>
                <tr>
                    <th>الفرع</th>
                    <th class="num">الواردة</th>
                    <th class="num">اترد عليها</th>
                    <th class="num">الفايتة</th>
                    <th class="num">اتكلّموا في المهلة</th>
                    <th class="num">نسبة المتابعة</th>
                    <th class="num">وسيط وقت المعاودة</th>
                    <th class="num">الصادرة</th>
                    <th>آخر مزامنة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryData as $sr): ?>
                <tr>
                    <td><b><?= e($sr['branch_name']) ?></b></td>
                    <td class="num"><?= (int)$sr['incoming_count'] ?></td>
                    <td class="num"><?= (int)$sr['answered_count'] ?></td>
                    <td class="num"><?= (int)$sr['missed_count'] ?></td>
                    <td class="num"><?= (int)$sr['in_time_count'] ?></td>
                    <td class="num"><b><?= e($sr['followup_rate']) ?></b></td>
                    <td class="num"><?= e($sr['median_delay']) ?></td>
                    <td class="num"><?= (int)$sr['outgoing_count'] ?></td>
                    <td>
                        <?php if ($sr['sync_status'] === 'stale'): ?>
                        <span class="fchip fchip-neg"><?= e($sr['sync_label']) ?></span>
                        <?php else: ?>
                        <span class="fchip <?= $sr['last_sync'] ? 'fchip-pos' : 'fchip-mute' ?>"><?= e($sr['sync_label']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($isOwner): ?>
<script>
(function() {
    var inp = document.getElementById('followup_input');
    var st = document.getElementById('followup_status');
    if (!inp) return;

    var lastVal = inp.value;

    inp.addEventListener('blur', function() {
        var val = parseInt(inp.value, 10);
        if (isNaN(val) || val < 5 || val > 240) {
            alert('المهلة لازم تكون بين ٥ و ٢٤٠ دقيقة.');
            inp.value = lastVal;
            return;
        }
        if (val === parseInt(lastVal, 10)) {
            return; // لم تتغير
        }

        if (st) {
            st.style.display = 'inline';
            st.style.color = 'var(--ink-500)';
            st.textContent = 'جاري الحفظ...';
        }

        var fd = new FormData();
        fd.append('action', 'save_followup_minutes');
        fd.append('minutes', val);
        fd.append('csrf', inp.getAttribute('data-csrf') || '');

        apiFetch('calls_api.php', {
            method: 'POST',
            body: fd
        })
        .then(function(data) {
            if (data.ok) {
                lastVal = val;
                if (st) {
                    st.style.color = 'var(--pos)';
                    st.textContent = 'تم الحفظ';
                    setTimeout(function() { st.style.display = 'none'; }, 2000);
                }
            } else {
                throw new Error(data.error || 'فشل الحفظ');
            }
        })
        .catch(function(err) {
            alert(err.message || 'خطأ أثناء حفظ المهلة');
            inp.value = lastVal;
            if (st) st.style.display = 'none';
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

