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
        $acc = ($_POST['acc'] ?? '') !== '' ? (float)$_POST['acc'] : null;
        $geoErr = trim((string)($_POST['geo_err'] ?? ''));
        $geoUa  = trim((string)($_POST['geo_ua'] ?? ''));
        if ($geoUa === '') {
            $geoUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        }
        try {
            if ($action === 'clock_in' || $action === 'clock_out') {
                $geo = evaluate_geofence($employee, $lat, $lng, $acc);
                if ($reason = geofence_block_reason($employee, $lat, $lng, $geo)) {
                    $accTxt = $acc !== null ? (int)$acc : '—';
                    $errTxt = $geoErr !== '' ? $geoErr : '—';
                    $uaTxt  = mb_substr($geoUa, 0, 150);
                    $distTxt = isset($geo['dist']) ? (int)$geo['dist'] . ' م من الفرع (المسموح ' . (int)($geo['radius'] ?? 0) . ')' : 'المسافة مش معروفة';
                    errlog_record('geo', "رفض بصمة: موظف #{$employee['id']} · {$distTxt} · دقة ±{$accTxt} م · خطأ الموقع: {$errTxt} · {$uaTxt}", __FILE__, __LINE__);
                    throw new RuntimeException($reason);
                }
                $geo['lat'] = $lat; $geo['lng'] = $lng; $geo['acc'] = $acc;
                $act = $action === 'clock_in' ? 'in' : 'out';

                // ---------------------------------------------------------------
                //  وردية عدّت منتصف الليل:
                //  لو انصراف، وإحنا قبل بداية اليوم المحاسبي، وفيه دخول امبارح
                //  من غير خروج → نسجّل الانصراف على تاريخ امبارح مش النهاردة.
                //
                //  من غير ده، الانصراف كان هيتسجّل على يوم جديد ملوش دخول،
                //  ويوم امبارح يفضل مفتوح للأبد.
                // ---------------------------------------------------------------
                $forceDate = null;

                if ($act === 'out' && (int)date('G') < business_day_start_hour()) {
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
                // بقت مستحيلة من بريف ٣ (١٥ سبتمبر) — البرّه بيترفض قبل ما يوصل هنا
                if (!empty($res['outside'])) {
                    $where = ' (تنبيه: كنت خارج نطاق الفرع بمسافة ' . (int)$res['dist'] . ' متر — تم إبلاغ الموارد البشرية)';
                    $flashType = 'error';
                } elseif (!empty($res['uncertain'])) {
                    $accTxt  = $acc !== null ? (int)$acc : '—';
                    $errTxt  = $geoErr !== '' ? $geoErr : '—';
                    $uaTxt   = mb_substr($geoUa, 0, 150);
                    $distTxt = isset($geo['dist']) ? (int)$geo['dist'] . ' م من الفرع (المسموح ' . (int)($geo['radius'] ?? 0) . ')' : 'المسافة مش معروفة';
                    errlog_record('geo', "موقع مش دقيق: موظف #{$employee['id']} · {$distTxt} · دقة ±{$accTxt} م · خطأ الموقع: {$errTxt} · {$uaTxt}", __FILE__, __LINE__);
                    $grace = (int)setting('geofence_uncertain_grace', '3');
                    $prev = geofence_consecutive_uncertain((int)$employee['id'], (int)($geo['radius'] ?: 50));
                    $rem = max(0, $grace - ($prev + 1));
                    $where = ' (اتسجّل، بس موقعك مش دقيق (±' . (int)$acc . ' م). فاضل ' . $rem . ' مرة وبعدها مش هيتسجّل — افتح إعدادات المتصفح وخلّي الموقع «دقيق».)';
                    $flashType = 'warning';
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
    // PRG: تحويل لصفحة GET بعد الـ POST عشان الـ Refresh ما يعيدش الإرسال والحفاظ على الفلاتر
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
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
$myPeriodAtt = [];

// فترة سجل الحضور للموظف (افتراضياً الشهر الحالي)
$attFrom = $_GET['from'] ?? null;
$attTo   = $_GET['to']   ?? null;
if ($attFrom !== null && $attTo !== null) {
    if (!strtotime($attFrom)) $attFrom = date('Y-m-01');
    if (!strtotime($attTo))   $attTo   = date('Y-m-t');
} elseif (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month'])) {
    $attFrom = $_GET['month'] . '-01';
    $attTo   = date('Y-m-t', strtotime($attFrom));
} else {
    // نفس فترة المرتب: من ٢٦ الشهر اللي فات لـ٢٥ الشهر ده — عشان اللي
    // الموظف بيشوفه هنا يبقى هو اللي مرتبه هيتحسب عليه، مش شهر تقويمي.
    //
    // 🔴 بتقلب على الفترة الجديدة **يوم ١** من غير أي مهلة — عكس شاشة
    // الرواتب اللي ليها مهلة ٥ أيام. ده مقصود: الموظف عايز يتابع فترته
    // الجارية، والأونر عايز يخلّص صرف الفترة اللي فاتت.
    $attFrom = date('Y-m-26', strtotime('first day of last month'));
    $attTo   = date('Y-m-25');
}

// ---------------------------------------------------------------------------
//  الورديات اللي بتعدّي منتصف الليل
//
//  المشكلة: الساعة 00:00 الصفحة كانت بتقلب ليوم جديد، فالموظف اللي لسه
//  شغال (مسجّل دخول امبارح ومسجلش خروج) مايلاقيش زرار الانصراف.
//
//  الحل: قبل بداية اليوم المحاسبي (business_day_start من الإعدادات)،
//  لو فيه دخول امبارح من غير خروج، نفضل نعرض يوم امبارح لحد ما يسجل خروج.
//  والتغيير بيتم من صفحة الإعدادات.
// ---------------------------------------------------------------------------
$GRACE_HOURS = business_day_start_hour();
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
    // سجل الفترة
    $m = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? ORDER BY att_date DESC");
    $m->execute([(int)$employee['id'], $attFrom, $attTo]);
    $myPeriodAtt = $m->fetchAll();
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
    .att-card { background:var(--surface); border-radius:16px; padding:26px; box-shadow:0 2px 12px rgba(124,111,247,0.08); margin-bottom:18px; }
    .att-date { text-align:center; color:var(--ink-500); font-size:14px; margin-bottom:6px; }
    .att-name { text-align:center; color:var(--ink-900); font-size:20px; font-weight:700; margin-bottom:22px; }
    .exp-row { display:flex; justify-content:space-around; margin-bottom:18px; }
    .exp-col { text-align:center; }
    .exp-col .l { color:var(--ink-500); font-size:13px; margin-bottom:4px; }
    .exp-col .v { color:var(--accent-text); font-size:22px; font-weight:700; }
    .act-row { display:flex; justify-content:space-around; padding:18px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); margin-bottom:18px; }
    .act-col { text-align:center; }
    .act-col .l { color:var(--ink-700); font-size:13px; margin-bottom:4px; }
    .act-col .v { color:var(--ink-900); font-size:26px; font-weight:700; }
    .act-col .v.empty { color:var(--accent-text); }
    .worked { text-align:center; margin-bottom:22px; }
    .worked .l { color:var(--ink-500); font-size:14px; letter-spacing:1px; }
    .worked .v { color:var(--pos); font-size:32px; font-weight:800; }
    .clock-btn { width:100%; padding:18px; border:none; border-radius:14px; font-size:18px;
        font-weight:700; font-family:inherit; cursor:pointer; color:#fff; margin-bottom:12px;
        display:flex; align-items:center; justify-content:center; gap:10px; }
    .btn-in  { background:var(--pos-fill); } .btn-in:hover { background:var(--pos-fill); }
    .btn-out { background:var(--neg-fill); } .btn-out:hover { background:var(--neg-fill); }
    .btn-done { background:var(--line-soft); color:var(--ink-500); cursor:default; }
    .btn-request { width:100%; padding:14px; border:1.5px solid var(--accent); background:var(--surface); color:var(--accent-text);
        border-radius:14px; font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; }
    .btn-request:hover { background:var(--bg); }
    .no-emp { text-align:center; color:var(--ink-500); padding:40px; }
    /* Modal */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:420px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input { width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
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
            <i class="ti ti-clock"></i> وردية أمس لسه مفتوحة — سجّل انصرافك
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
                <input type="hidden" name="acc" class="geo-acc">
                <input type="hidden" name="geo_err" class="geo-err">
                <input type="hidden" name="geo_ua" class="geo-ua">
                <button type="submit" class="clock-btn btn-in"><i class="ti ti-login"></i> تسجيل الحضور</button>
            </form>
        <?php elseif (!$actOut): ?>
            <form method="post" id="clockForm" class="clock-form" data-label="تسجيل الانصراف">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clock_out">
                <input type="hidden" name="lat" class="geo-lat">
                <input type="hidden" name="lng" class="geo-lng">
                <input type="hidden" name="acc" class="geo-acc">
                <input type="hidden" name="geo_err" class="geo-err">
                <input type="hidden" name="geo_ua" class="geo-ua">
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
        const btn   = form.querySelector('.clock-btn');
        const hint  = document.getElementById('geoHint');
        const latEl = form.querySelector('.geo-lat');
        const lngEl = form.querySelector('.geo-lng');
        const accEl = form.querySelector('.geo-acc');
        const errEl = form.querySelector('.geo-err');
        const uaEl  = form.querySelector('.geo-ua');
        let submitting = false;

        function showHint(txt, color) {
            if (!hint) return;
            hint.style.display = 'block';
            hint.style.color = color || 'var(--ink-500)';
            hint.innerHTML = txt;
        }

        form.addEventListener('submit', function(e){
            if (submitting) return;               // الإرسال الفعلي (بعد ما جبنا الموقع)
            e.preventDefault();

            if (uaEl) {
                const isPwa = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
                const modePrefix = isPwa ? 'PWA · ' : 'BROWSER · ';
                uaEl.value = (modePrefix + (navigator.userAgent || '')).slice(0, 200);
            }

            if (!navigator.geolocation) {
                // المتصفح مبيدعمش الموقع → نرسل من غير إحداثيات
                if (errEl) errEl.value = 'unsupported';
                submitting = true; form.submit(); return;
            }

            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="ti ti-loader"></i> جاري تحديد موقعك...';
            showHint('بندوّر على موقعك بدقة...', 'var(--warn)');

            let watchId = null;
            let timeoutId = null;
            let bestPos = null;

            function doSubmit(errCode) {
                if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                if (timeoutId !== null) clearTimeout(timeoutId);
                if (submitting) return;
                
                if (errEl && errCode !== undefined && errCode !== null) {
                    errEl.value = String(errCode);
                }

                if (bestPos) {
                    latEl.value = bestPos.coords.latitude.toFixed(7);
                    lngEl.value = bestPos.coords.longitude.toFixed(7);
                    if (accEl) accEl.value = bestPos.coords.accuracy.toFixed(2);
                    if (bestPos.coords.accuracy > 500) {
                        const isIos = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                        const msg = isIos
                            ? '<i class="ti ti-alert-triangle"></i> موقعك غير دقيق (±' + Math.round(bestPos.coords.accuracy) + ' م). شغّل <strong>الموقع الدقيق</strong> من:<br>الإعدادات ← الخصوصية والأمان ← خدمات الموقع ← مواقع Safari ← أثناء الاستخدام وشغّل الموقع الدقيق.'
                            : '<i class="ti ti-alert-triangle"></i> موقعك غير دقيق (±' + Math.round(bestPos.coords.accuracy) + ' م). شغّل <strong>الموقع الدقيق</strong> في إعدادات الموقع بالمتصفح.';
                        showHint(msg, 'var(--warn)');
                    }
                } else {
                    latEl.value = ''; lngEl.value = '';
                    if (accEl) accEl.value = '';
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    showHint('تعذّر تحديد الموقع — بنبعت عشان نتأكد', 'var(--neg)');
                }
                
                submitting = true;
                form.submit();
            }

            timeoutId = setTimeout(function(){
                doSubmit('T15'); // T15 = مؤقتنا إحنا مش خطأ من الموبايل
            }, 15000);

            watchId = navigator.geolocation.watchPosition(
                function(pos){
                    if (!bestPos || pos.coords.accuracy < bestPos.coords.accuracy) {
                        bestPos = pos;
                        showHint('بندوّر على موقعك بدقة...<br>الدقة: ±' + Math.round(pos.coords.accuracy) + ' م', 'var(--warn)');
                    }
                    if (pos.coords.accuracy <= 50) {
                        doSubmit();
                    }
                },
                function(err){
                    if (err.code === 1) {
                        // إذن الموقع مرفوض (PERMISSION_DENIED)
                        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                        if (timeoutId !== null) clearTimeout(timeoutId);
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        const isIos = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                        const guide = isIos
                            ? '<strong>تم رفض إذن الموقع!</strong> لتسجيل البصمة على آيفون:<br>الإعدادات ← الخصوصية والأمان ← خدمات الموقع ← مواقع Safari ← أثناء الاستخدام · وشغّل <strong>الموقع الدقيق</strong>.'
                            : '<strong>تم رفض إذن الموقع!</strong> لتسجيل البصمة على أندرويد:<br>اسمح للموقع من القفل اللي جنب العنوان في المتصفح.';
                        showHint(guide, 'var(--neg)');
                        return;
                    }
                    // كود 2 (POSITION_UNAVAILABLE) أو 3 (TIMEOUT)
                    if (!bestPos) {
                        doSubmit(err.code);
                    }
                },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
            );
        });
    })();
</script>

<?php if ($employee): ?>
<?php
    $periodTotalHours = 0.0;
    $completeDays     = 0;   // أيام فيها حضور وانصراف
    $missingDays      = 0;   // أيام تسجيلها ناقص (واحد بس من الاتنين)
    $noneDays         = 0;   // مفيش دخول ولا خروج (باستثناء الإجازات والعطلات)
    $seenDates        = [];  // الأيام اللي ليها صف في الجدول
    foreach ($myPeriodAtt as $a) {
        $periodTotalHours += (float) ($a['work_hours'] ?? 0);
        $seenDates[(string) $a['att_date']] = true;
        $st     = $a['status'] ?? '';
        $hasIn  = !empty($a['check_in']);
        $hasOut = !empty($a['check_out']);
        if ($hasIn && $hasOut)      { $completeDays++; }
        elseif ($hasIn || $hasOut)  { $missingDays++;  }
        elseif ($st !== 'leave' && $st !== 'holiday') { $noneDays++; }
    }

    // -----------------------------------------------------------------
    //  الأيام اللي **مالهاش صف خالص** في الجدول بتتعد كأيام بدون تسجيل.
    //
    //  مابنعملش صفوف فاضية في `attendance` لكل يوم في الشهر — ده الجدول
    //  اللي المرتبات بتتحسب منه، وكتابة يومية فيه مقابل حاجة نقدر نحسبها
    //  هنا مخاطرة مالهاش داعي. الحساب بيدّي نفس النتيجة بالظبط.
    //
    //  🔴 وبنعد لحد النهاردة بس — أيام الفترة اللي لسه ما جاتش ماتتحسبش،
    //  وإلا الموظف يوم ١ هيلاقي ٣٠ يوم «مفيش فيها تسجيل».
    // -----------------------------------------------------------------
    $countUntil = min($attTo, $today);
    for ($d = strtotime($attFrom); $d <= strtotime($countUntil); $d = strtotime('+1 day', $d)) {
        if (!isset($seenDates[date('Y-m-d', $d)])) {
            $noneDays++;
        }
    }
?>
<div class="card" style="background:var(--surface);border-radius:14px;padding:20px;box-shadow:0 2px 10px rgba(124,111,247,0.06);margin-top:18px;">
    <div style="font-size:16px;color:var(--ink-900);font-weight:600;margin-bottom:14px;">سجل الحضور وملخص الفترة</div>
    <form method="get" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label style="font-size:13px;color:var(--ink-700);font-weight:500;">من:</label>
        <input type="date" name="from" value="<?= e($attFrom) ?>" onchange="formSubmit(this.form)"
               style="padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;">
        <label style="font-size:13px;color:var(--ink-700);font-weight:500;">إلى:</label>
        <input type="date" name="to" value="<?= e($attTo) ?>" onchange="formSubmit(this.form)"
               style="padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;">
        <button type="submit" class="btn btn-sm btn-ghost" style="padding:6px 14px;"><i class="ti ti-refresh"></i> تحديث</button>
    </form>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:18px;">
        <div style="background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي ساعات العمل</div>
            <div style="font-size:18px;font-weight:700;color:var(--accent-text);"><?= number_format($periodTotalHours, 2) ?> <span style="font-size:13px;font-weight:500;">ساعة</span></div>
        </div>
        <div style="background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">أيام سجّلت فيها حضور وانصراف</div>
            <div style="font-size:18px;font-weight:700;color:var(--pos);"><?= $completeDays ?> <span style="font-size:13px;font-weight:500;">يوم</span></div>
        </div>
        <div style="background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;"
             title="دول الأيام اللي نسيت فيها تسجّل دخول أو خروج — اطلب تصحيح من الزرار اللي فوق">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">أيام تسجيلها ناقص</div>
            <div style="font-size:18px;font-weight:700;color:var(--warn);"><?= $missingDays ?> <span style="font-size:13px;font-weight:500;">يوم</span></div>
        </div>
        <div style="background:var(--line-soft);border-radius:10px;padding:12px 14px;text-align:center;"
             title="أيام مالكش فيها تسجيل خالص — لو فيها شغل اطلب تصحيح">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">أيام مفيش تسجيل خالص</div>
            <div style="font-size:18px;font-weight:700;color:var(--neg);"><?= $noneDays ?> <span style="font-size:13px;font-weight:500;">يوم</span></div>
        </div>
    </div>

    <?php if (empty($myPeriodAtt)): ?>
        <div style="text-align:center;color:var(--ink-400);padding:24px;">لا يوجد سجل لهذه الفترة.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
            <?php foreach (['التاريخ','الحضور','الانصراف','ساعات العمل','الحالة'] as $h): ?>
                <th style="padding:10px 12px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);"><?= $h ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($myPeriodAtt as $a): ?>
            <?php
                $st         = $a['status'] ?? '';
                $inOutside  = !empty($a['check_in_outside']);
                $outOutside = !empty($a['check_out_outside']);
                $rowHasIn   = !empty($a['check_in']);
                $rowHasOut  = !empty($a['check_out']);
                $rowMissing = ($rowHasIn || $rowHasOut) && !($rowHasIn && $rowHasOut);

                // تحديد الحالة المعروضة والشارة بالترتيب: الإجازة والعطلة ليهم الأولوية
                if ($st === 'leave') {
                    $sb = ['إجازة', 'var(--warn)', 'var(--warn-soft)'];
                } elseif ($st === 'holiday') {
                    $sb = ['عطلة', 'var(--ink-500)', 'var(--line-soft)'];
                } elseif ($rowHasIn && $rowHasOut) {
                    $sb = ['حاضر', 'var(--pos)', 'var(--pos-soft)'];
                } elseif ($rowHasIn || $rowHasOut) {
                    $sb = ['تسجيل ناقص', 'var(--warn)', 'var(--warn-soft)'];
                } else {
                    $sb = ['مفيش تسجيل', 'var(--neg)', 'var(--neg-soft)'];
                }
            ?>
            <tr<?= $rowMissing ? ' style="background:var(--warn-soft);"' : '' ?>>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);"><?= e($a['att_date']) ?></td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <?php if ($rowHasIn): ?>
                        <?= e(substr($a['check_in'],0,5)) ?>
                        <?php if ($inOutside): ?><span title="خارج النطاق (<?= (int)$a['check_in_dist'] ?>م)" style="color:var(--neg);font-size:11px;"><i class="ti ti-alert-triangle"></i></span><?php endif; ?>
                    <?php elseif ($rowMissing): ?>
                        <span style="color:var(--warn);font-weight:600;">ناقص</span>
                    <?php else: ?>
                        <?= e('—') ?>
                    <?php endif; ?>
                </td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <?php if ($rowHasOut): ?>
                        <?= e(substr($a['check_out'],0,5)) ?>
                        <?php if ($outOutside): ?><span title="خارج النطاق (<?= (int)$a['check_out_dist'] ?>م)" style="color:var(--neg);font-size:11px;"><i class="ti ti-alert-triangle"></i></span><?php endif; ?>
                    <?php elseif ($rowMissing): ?>
                        <span style="color:var(--warn);font-weight:600;">ناقص</span>
                    <?php else: ?>
                        <?= e('—') ?>
                    <?php endif; ?>
                </td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);"><?= number_format((float)$a['work_hours'],2) ?></td>
                <td style="padding:10px 12px;text-align:right;font-size:13px;border-bottom:1px solid var(--line);">
                    <span style="padding:3px 11px;border-radius:20px;font-size:12px;color:<?=$sb[1]?>;background:<?=$sb[2]?>;"><?=e($sb[0])?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
