<?php
/**
 * attendance.php — تسجيل الحضور والانصراف (واجهة الموظف)
 * نظام الفجالة ستور المالي
 *
 * - يعرض المتوقع (Expected In/Out) من بيانات الموظف.
 * - زر حضور/انصراف، وساعات العمل بالساعة والدقيقة.
 * - طلب تصحيح "نسيت أسجّل دخول/خروج" يروح للـ HR للاعتماد.
 *
 * بيشتغل على الموبايل (الموقع نفسه).
 */
require_once __DIR__ . '/auth.php';
require_permission('attendance');
require_once __DIR__ . '/finance.php';

$pdo = db();
$u   = current_user();
$uid = (int) $u['id'];
$flash = ''; $flashType = 'success';

// ربط المستخدم بموظف
$empStmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = ? AND is_active = 1');
$empStmt->execute([$uid]);
$employee = $empStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        // إحداثيات البصمة (من المتصفح)
        $lat = ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null;
        $lng = ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null;
        try {
            if ($action === 'clock_in' || $action === 'clock_out') {
                $geo = evaluate_geofence($employee, $lat, $lng);
                $geo['lat'] = $lat; $geo['lng'] = $lng;
                $act = $action === 'clock_in' ? 'in' : 'out';

                // ---------------------------------------------------------------
                //  وردية عدّت منتصف الليل:
                //  لو انصراف، وإحنا في أول ساعتين من اليوم، وفيه دخول امبارح
                //  من غير خروج → نسجّل الانصراف على تاريخ امبارح مش النهاردة.
                //
                //  من غير ده، الانصراف كان هيتسجّل على يوم جديد ملوش دخول،
                //  ويوم امبارح يفضل مفتوح للأبد.
                // ---------------------------------------------------------------
                $forceDate = null;

                if ($act === 'out' && (int)date('G') < 2) {
                    $yd = date('Y-m-d', strtotime('-1 day'));
                    $chk = $pdo->prepare(
                        'SELECT 1 FROM attendance
                         WHERE employee_id = ? AND att_date = ?
                           AND check_in IS NOT NULL AND check_out IS NULL'
                    );
                    $chk->execute([(int)$employee['id'], $yd]);
                    if ($chk->fetchColumn()) {
                        $forceDate = $yd;
                    }
                }

                $res = clock_attendance((int)$employee['id'], $act, null, $forceDate, $geo);
                $where = '';
                if (!empty($res['outside'])) {
                    $where = ' (تنبيه: كنت خارج نطاق الفرع بمسافة ' . (int)$res['dist'] . ' متر — تم إبلاغ الموارد البشرية)';
                    $flashType = 'error';
                }
                if ($action === 'clock_in') {
                    $flash = 'تم تسجيل الحضور.' . $where;
                } else {
                    $flash = 'تم تسجيل الانصراف. ساعات العمل: ' . minutes_to_hhmm($res['minutes'] ?? 0) . $where;
                }
            } elseif ($action === 'request_fix') {
                $reqDate = $_POST['req_date'] ?? date('Y-m-d');
                $in  = trim($_POST['requested_in'] ?? '');
                $out = trim($_POST['requested_out'] ?? '');
                $reason = trim($_POST['reason'] ?? '');
                if ($in === '' && $out === '') throw new RuntimeException('أدخل وقت الدخول أو الخروج.');
                $pdo->prepare(
                    'INSERT INTO attendance_requests (employee_id, req_date, requested_in, requested_out, reason, status)
                     VALUES (?, ?, ?, ?, ?, "pending")'
                )->execute([(int)$employee['id'], $reqDate, $in ?: null, $out ?: null, $reason ?: null]);
                $flash = 'تم إرسال طلب التصحيح للموارد البشرية.';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: تحويل لصفحة GET بعد الـ POST عشان الـ Refresh ما يعيدش الإرسال
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// نقرأ رسالة الفلاش من السيشن بعد التحويل
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// سجل اليوم
$today = date('Y-m-d');
$todayAtt = null;
$myMonthAtt = [];
$myMonth = $_GET['month'] ?? date('Y-m');

// ---------------------------------------------------------------------------
//  الورديات اللي بتعدّي منتصف الليل
//
//  المشكلة: الساعة 00:00 الصفحة كانت بتقلب ليوم جديد، فالموظف اللي لسه
//  شغال (مسجّل دخول امبارح ومسجلش خروج) مايلاقيش زرار الانصراف.
//
//  الحل: في أول ساعتين من اليوم الجديد، لو فيه دخول امبارح من غير خروج،
//  نفضل نعرض يوم امبارح لحد ما يسجل خروج.
//
//  GRACE_HOURS = 2  →  لحد الساعة 02:00 صباحًا.
// ---------------------------------------------------------------------------
$GRACE_HOURS = 2;
$attDate     = $today;   // اليوم اللي الصفحة شغالة عليه فعليًا
$isCarryOver = false;    // هل احنا بنكمل وردية امبارح؟

if ($employee) {
    $hourNow = (int)date('G');   // 0..23

    if ($hourNow < $GRACE_HOURS) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // فيه دخول امبارح ومفيش خروج؟
        $q = $pdo->prepare(
            'SELECT * FROM attendance
             WHERE employee_id = ? AND att_date = ?
               AND check_in IS NOT NULL AND check_out IS NULL'
        );
        $q->execute([(int)$employee['id'], $yesterday]);

        if ($q->fetch()) {
            $attDate     = $yesterday;
            $isCarryOver = true;
        }
    }

    $a = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date=?');
    $a->execute([(int)$employee['id'], $attDate]);
    $todayAtt = $a->fetch();
    // سجل الشهر
    $m = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND DATE_FORMAT(att_date,'%Y-%m')=? ORDER BY att_date DESC");
    $m->execute([(int)$employee['id'], $myMonth]);
    $myMonthAtt = $m->fetchAll();
}

$page_title    = 'تسجيل الحضور';
$active_module = 'attendance';
require_once __DIR__ . '/includes/header.php';

$expIn  = $employee && $employee['expected_in']  ? substr($employee['expected_in'], 0, 5)  : '09:00';
$expOut = $employee && $employee['expected_out'] ? substr($employee['expected_out'], 0, 5) : '17:00';
$actIn  = $todayAtt && $todayAtt['check_in']  ? substr($todayAtt['check_in'], 0, 5)  : null;
$actOut = $todayAtt && $todayAtt['check_out'] ? substr($todayAtt['check_out'], 0, 5) : null;
$workedMin = ($actIn && $actOut) ? work_minutes($todayAtt['check_in'], $todayAtt['check_out']) : null;
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .att-wrap { max-width:480px; margin:0 auto; }
    .att-card { background:#fff; border-radius:16px; padding:26px; box-shadow:0 2px 12px rgba(124,111,247,0.08); margin-bottom:18px; }
    .att-date { text-align:center; color:var(--ink-500); font-size:14px; margin-bottom:6px; }
    .att-name { text-align:center; color:var(--ink-900); font-size:20px; font-weight:700; margin-bottom:22px; }
    .exp-row { display:flex; justify-content:space-around; margin-bottom:18px; }
    .exp-col { text-align:center; }
    .exp-col .l { color:var(--ink-500); font-size:13px; margin-bottom:4px; }
    .exp-col .v { color:var(--accent); font-size:22px; font-weight:700; }
    .act-row { display:flex; justify-content:space-around; padding:18px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); margin-bottom:18px; }
    .act-col { text-align:center; }
    .act-col .l { color:var(--ink-700); font-size:13px; margin-bottom:4px; }
    .act-col .v { color:var(--ink-900); font-size:26px; font-weight:700; }
    .act-col .v.empty { color:#d0cce5; }
    .worked { text-align:center; margin-bottom:22px; }
    .worked .l { color:var(--ink-500); font-size:14px; letter-spacing:1px; }
    .worked .v { color:var(--pos); font-size:32px; font-weight:800; }
    .clock-btn { width:100%; padding:18px; border:none; border-radius:14px; font-size:18px;
        font-weight:700; font-family:inherit; cursor:pointer; color:#fff; margin-bottom:12px;
        display:flex; align-items:center; justify-content:center; gap:10px; }
    .btn-in  { background:var(--pos); } .btn-in:hover { background:#15835f; }
    .btn-out { background:var(--neg); } .btn-out:hover { background:#c93f3e; }
    .btn-done { background:var(--line-soft); color:var(--ink-500); cursor:default; }
    .btn-request { width:100%; padding:14px; border:1.5px solid var(--accent); background:#fff; color:var(--accent);
        border-radius:14px; font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; }
    .btn-request:hover { background:var(--bg); }
    .no-emp { text-align:center; color:var(--ink-500); padding:40px; }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:420px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input { width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; }
    .btn-cancel { background:var(--line-soft); color:#666; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="att-wrap">
<?php if (!$employee): ?>
    <div class="att-card">
        <div class="no-emp">
            حسابك غير مربوط بموظف.<br>
            تواصل مع الإدارة لربط حسابك ببياناتك في الموارد البشرية.
        </div>
    </div>
<?php else: ?>
    <div class="att-card">
        <div class="att-date"><?= e(date('l, j F Y', strtotime($attDate))) ?></div>
        <?php if ($isCarryOver): ?>
        <div style="background:var(--warn-soft);color:var(--warn);border-radius:8px;padding:8px 12px;
                    font-size:13px;text-align:center;margin-bottom:10px;">
            ⏰ وردية أمس لسه مفتوحة — سجّل انصرافك
        </div>
        <?php endif; ?>
        <div class="att-name"><?= e($employee['full_name']) ?></div>

        <div class="exp-row">
            <div class="exp-col"><div class="l">الحضور المتوقع</div><div class="v"><?= e($expIn) ?></div></div>
            <div class="exp-col"><div class="l">الانصراف المتوقع</div><div class="v"><?= e($expOut) ?></div></div>
        </div>

        <div class="act-row">
            <div class="act-col">
                <div class="l">الحضور الفعلي</div>
                <div class="v <?= $actIn?'':'empty' ?>"><?= e($actIn ?? '--:--') ?></div>
            </div>
            <div class="act-col">
                <div class="l">الانصراف الفعلي</div>
                <div class="v <?= $actOut?'':'empty' ?>"><?= e($actOut ?? '--:--') ?></div>
            </div>
        </div>

        <?php if ($workedMin !== null): ?>
            <div class="worked">
                <div class="l">ساعات العمل</div>
                <div class="v"><?= e(minutes_to_hhmm($workedMin)) ?></div>
            </div>
        <?php endif; ?>

        <!-- زر الحضور/الانصراف حسب الحالة -->
        <?php if (!$actIn): ?>
            <form method="post" id="clockForm" class="clock-form" data-label="تسجيل الحضور">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clock_in">
                <input type="hidden" name="lat" class="geo-lat">
                <input type="hidden" name="lng" class="geo-lng">
                <button type="submit" class="clock-btn btn-in"><i class="ti ti-login"></i> تسجيل الحضور</button>
            </form>
        <?php elseif (!$actOut): ?>
            <form method="post" id="clockForm" class="clock-form" data-label="تسجيل الانصراف">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clock_out">
                <input type="hidden" name="lat" class="geo-lat">
                <input type="hidden" name="lng" class="geo-lng">
                <button type="submit" class="clock-btn btn-out"><i class="ti ti-logout"></i> تسجيل الانصراف</button>
            </form>
        <?php else: ?>
            <button class="clock-btn btn-done" disabled><i class="ti ti-check"></i> اكتمل اليوم</button>
        <?php endif; ?>
        <div id="geoHint" style="text-align:center;font-size:12px;color:var(--ink-500);margin-top:-4px;margin-bottom:8px;display:none;"></div>

        <button class="btn-request" onclick="document.getElementById('fixModal').classList.add('open')">
            <i class="ti ti-edit"></i> طلب تصحيح (نسيت أسجّل دخول/خروج)
        </button>
    </div>
<?php endif; ?>
</div>

<!-- Modal: طلب تصحيح -->
<div class="modal-bg" id="fixModal">
    <div class="modal">
        <h3>طلب تصحيح حضور</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_fix">
            <div class="form-row"><label>اليوم</label><input type="date" name="req_date" value="<?= $today ?>" required></div>
            <div class="form-row"><label>وقت الحضور الصحيح</label><input type="time" name="requested_in"></div>
            <div class="form-row"><label>وقت الانصراف الصحيح</label><input type="time" name="requested_out"></div>
            <div class="form-row"><label>السبب</label><input type="text" name="reason" placeholder="نسيت أسجّل..." aria-label="السبب"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">إرسال الطلب</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('fixModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    // ===== التقاط الموقع قبل تسجيل البصمة =====
    (function(){
        const form = document.getElementById('clockForm');
        if (!form) return;
        const btn  = form.querySelector('.clock-btn');
        const hint = document.getElementById('geoHint');
        const latEl = form.querySelector('.geo-lat');
        const lngEl = form.querySelector('.geo-lng');
        let submitting = false;

        function showHint(txt, color) {
            if (!hint) return;
            hint.style.display = 'block';
            hint.style.color = color || 'var(--ink-500)';
            hint.textContent = txt;
        }

        form.addEventListener('submit', function(e){
            if (submitting) return;               // الإرسال الفعلي (بعد ما جبنا الموقع)
            e.preventDefault();

            if (!navigator.geolocation) {
                // المتصفح مبيدعمش الموقع → نرسل من غير إحداثيات
                submitting = true; form.submit(); return;
            }

            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="ti ti-loader"></i> جاري تحديد موقعك...';
            showHint('برجاء السماح بالوصول للموقع', 'var(--warn)');

            navigator.geolocation.getCurrentPosition(
                function(pos){
                    latEl.value = pos.coords.latitude.toFixed(7);
                    lngEl.value = pos.coords.longitude.toFixed(7);
                    submitting = true;
                    form.submit();
                },
                function(err){
                    // فشل/رفض تحديد الموقع → نرسل من غير إحداثيات (السيرفر بيسجّل بدون تقييم)
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    showHint('تعذّر تحديد الموقع — سيتم التسجيل بدون تحديد المكان', 'var(--neg)');
                    latEl.value = ''; lngEl.value = '';
                    submitting = true;
                    form.submit();
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    })();
</script>

<?php if ($employee): ?>
<?php
    $monthTotalHours = 0.0;
    foreach ($myMonthAtt as $a) {
        $monthTotalHours += (float) ($a['work_hours'] ?? 0);
    }
    $monthTotalDays = (int) floor($monthTotalHours / 6);
?>
<div class="card" style="background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 10px rgba(124,111,247,0.06);margin-top:18px;">
    <div style="font-size:16px;color:var(--ink-900);font-weight:600;margin-bottom:14px;">سجل حضوري</div>
    <form method="get" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
        <label style="font-size:13px;color:var(--ink-500);">الشهر:</label>
        <input type="month" name="month" value="<?= e($myMonth) ?>" onchange="this.form.submit()"
               style="padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
    </form>

    <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:140px;background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي ساعات العمل</div>
            <div style="font-size:18px;font-weight:700;color:var(--accent);"><?= number_format($monthTotalHours, 2) ?> <span style="font-size:13px;font-weight:500;">ساعة</span></div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي أيام العمل</div>
            <div style="font-size:18px;font-weight:700;color:var(--pos);"><?= $monthTotalDays ?> <span style="font-size:13px;font-weight:500;">يوم</span></div>
        </div>
    </div>

    <?php if (empty($myMonthAtt)): ?>
        <div style="text-align:center;color:#bbb;padding:24px;">لا يوجد سجل لهذا الشهر.</div>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
            <?php foreach (['التاريخ','الحضور','الانصراف','ساعات العمل','الحالة'] as $h): ?>
                <th style="padding:10px 12px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);"><?= $h ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php
            $sm = ['present'=>['حاضر','var(--pos)','var(--pos-soft)'],'absent'=>['غائب','var(--neg)','var(--neg-soft)'],
                   'leave'=>['إجازة','var(--warn)','var(--warn-soft)'],'holiday'=>['عطلة','#666','#eee']];
            foreach ($myMonthAtt as $a): $sb = $sm[$a['status']] ?? ['—','#666','#eee'];
        ?>
            <?php
                $inOutside  = !empty($a['check_in_outside']);
                $outOutside = !empty($a['check_out_outside']);
            ?>
            <tr>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);"><?= e($a['att_date']) ?></td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <?= e($a['check_in'] ? substr($a['check_in'],0,5) : '—') ?>
                    <?php if ($inOutside): ?><span title="خارج النطاق (<?= (int)$a['check_in_dist'] ?>م)" style="color:var(--neg);font-size:11px;">⚠</span><?php endif; ?>
                </td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <?= e($a['check_out'] ? substr($a['check_out'],0,5) : '—') ?>
                    <?php if ($outOutside): ?><span title="خارج النطاق (<?= (int)$a['check_out_dist'] ?>م)" style="color:var(--neg);font-size:11px;">⚠</span><?php endif; ?>
                </td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);"><?= number_format((float)$a['work_hours'],2) ?></td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <span style="padding:3px 11px;border-radius:20px;font-size:12px;color:<?=$sb[1]?>;background:<?=$sb[2]?>;"><?=e($sb[0])?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
