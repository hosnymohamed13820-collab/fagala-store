<?php
/**
 * settings.php — الإعدادات العامة
 * نظام الفجالة ستور المالي
 *
 * تعديل القيم المخزّنة في جدول settings.
 */
require_once __DIR__ . '/auth.php';
require_permission('settings');

$pdo       = db();
$flash     = '';
$flashType = 'success';

// الإعدادات القابلة للتعديل من الواجهة (مفتاح => [التسمية, النوع])
$editable = [
    'store_name'                => ['اسم المحل', 'text'],
    'print_header'              => ['عنوان الطباعة (أعلى التقارير)', 'text'],
    'print_footer'              => ['تذييل الطباعة', 'text'],
    'custody_deficit_tolerance' => ['عتبة التسامح لعجز العهدة (بالجنيه)', 'number'],
    'cashier_deficit_carry'     => ['رحّل باقي عجز الكاشير لسلفة مستديمة تلقائياً', 'bool'],
    'business_day_start'        => ['بداية اليوم المحاسبي', 'time'],
    'currency'                  => ['العملة', 'text'],
    'shortage_supervisor_id'    => ['مسؤول متابعة طلبات النواقص (بيوصله إشعار مع الأونر)', 'user'],
    'purchase_supervisor_id'    => ['مسؤول المشتريات (بيوصله طلب المشتريات مع الأونر)', 'user'],
    'purchase_supervisor_2_id'  => ['مسؤول المشتريات التاني (بيوصله نفس الطلب)', 'user'],
    'agent_watcher_id'          => ['مسؤول متابعة وكلاء المزامنة (بيوصله تنبيه مع الأونر)', 'user'],
    'agent_warn_min'            => ['تنبيه أصفر لو وكيل سكت (بالدقايق)', 'number'],
    'agent_alert_min'           => ['تنبيه أحمر لو وكيل سكت (بالدقايق)', 'number'],
    'agent_watch_from'          => ['مراقبة الوكلاء تبدأ الساعة', 'time'],
    'agent_watch_to'            => ['مراقبة الوكلاء تنتهي الساعة', 'time'],
    'sales_revenue_account_id'  => ['حساب «إيراد مبيعات» اللي الورديات بتترحّل عليه (رقم الحساب)', 'number'],
    'transfer_account_id'       => ['حساب «تحويل» اللي حركات تحويل الخزائن بتترحّل عليه (رقم الحساب)', 'number'],
    'attendance_edit_user_id'   => ['موظف مسموحله يعدّل ساعات طلبات التصحيح (مع الأونر)', 'user'],
    'shift_edit_accountant_id'  => ['المحاسب المسموحله يغيّر تاريخ وفرع الوردية المستنية (مع الأونر)', 'user'],
    // فاضي = فحص تسلسل أرقام الورديات مقفول. الورديات اللي قبل التاريخ ده مابتتفحصش.
    'shift_seq_start_date'      => ['فحص تسلسل أرقام الورديات يبدأ من يوم (فاضي = مقفول)', 'date'],
    // إشعارات الموبايل: المفتاح العام بيتحقن في الصفحة عشان المتصفح يشترك بيه.
    // 🔴 المفتاح **الخاص** مكانه config.php على السيرفر بس — عمره ما يدخل الجدول ولا الريبو.
    //    لو المفتاح العام فاضي، زرار «فعّل إشعارات الموبايل» مايظهرش خالص.
    'vapid_public_key'          => ['المفتاح العام لإشعارات الموبايل (VAPID)', 'text'],
    'vapid_subject'             => ['بريد التواصل لإشعارات الموبايل (mailto:...)', 'text'],
];

// قائمة المستخدمين لحقول نوع user
$settingsUsers = $pdo->query(
    'SELECT u.id, u.full_name, b.name AS branch_name
     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
     WHERE u.is_active = 1 ORDER BY u.full_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $upd = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($editable as $key => $meta) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                if ($meta[1] === 'bool') {
                    $val = ($val === '1' || $val === 'on') ? '1' : '0';
                }
                // التحقق من عتبة العجز إنها رقم موجب
                if ($key === 'custody_deficit_tolerance') {
                    $val = (string) max(0, (float) $val);
                }
                // مسؤول النواقص ومسؤول المشتريات ومسؤول المشتريات التاني وموظف تعديل الحضور ومسؤول الوكلاء:
                // رقم مستخدم (٠ = مفيش)
                if ($key === 'shortage_supervisor_id' || $key === 'purchase_supervisor_id'
                    || $key === 'purchase_supervisor_2_id'
                    || $key === 'agent_watcher_id' || $key === 'attendance_edit_user_id'
                    || $key === 'shift_edit_accountant_id') {
                    $val = (string) max(0, (int) $val);
                }
                // حساب إيراد المبيعات وحساب التحويل: رقم حساب موجب.
                // ٠ = بدون — الورديات/التحويلات تترحّل من غير حساب زي الأول بالظبط.
                if ($key === 'sales_revenue_account_id' || $key === 'transfer_account_id') {
                    $val = (string) max(0, (int) $val);
                }
                // تاريخ بداية التسلسل: يا فاضي يا تاريخ صحيح — أي حاجة تانية تتصفّر
                if ($key === 'shift_seq_start_date' && $val !== '') {
                    $dt  = DateTime::createFromFormat('!Y-m-d', $val);
                    $val = ($dt && $dt->format('Y-m-d') === $val) ? $val : '';
                }
                // عتبات الوكلاء: دقيقة على الأقل، والأحمر مايبقاش أقل من الأصفر
                // (لو الأحمر أقل، التنبيه الأصفر عمره ما هيظهر)
                if ($key === 'agent_warn_min') {
                    $val = (string) max(1, (int) $val);
                }
                if ($key === 'agent_alert_min') {
                    $warn = max(1, (int) ($_POST['agent_warn_min'] ?? 20));
                    $val  = (string) max($warn, (int) $val);
                }
                $upd->execute([$key, $val]);
            }
        }
        $flash = 'تم حفظ الإعدادات بنجاح.';
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// جلب القيم الحالية
$current = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

$page_title    = 'الإعدادات';
$active_module = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .settings-form { max-width:560px; }
    .form-row { margin-bottom:18px; }
    .form-row label { display:block; font-size:14px; color:var(--ink-700); margin-bottom:7px; font-weight:500; }
    .form-row input {
        width:100%; padding:11px 13px; border:1.5px solid var(--line); border-radius:10px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .form-row select {
        width:100%; padding:11px 13px; border:1.5px solid var(--line); border-radius:10px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }

    /* الحقول مقفولة افتراضياً — تعديل بالغلط في إعداد زي حساب الإيراد أو
       مفتاح الإشعارات ممكن يوقّع حاجات، فالقفل هو الوضع الطبيعي. */
    .fld-line { display:flex; gap:8px; align-items:center; }
    .fld-line input, .fld-line select { flex:1; min-width:0; }
    .form-row.is-locked input, .form-row.is-locked select {
        background:var(--line-soft); color:var(--ink-500); cursor:default; }
    .fld-edit {
        flex:none; padding:9px 14px; border:1.5px solid var(--line); border-radius:9px;
        background:var(--surface); color:var(--accent-text); font-family:inherit; font-size:13px;
        font-weight:600; cursor:pointer; }
    .fld-edit:hover { background:var(--accent-soft); color:var(--accent-text); }
    .form-row.is-open .fld-edit { border-color:var(--neg); color:var(--neg); }
    .form-row.is-open .fld-edit:hover { background:var(--neg-soft); color:var(--neg); }
    /* override محلي (بريف ٤٣): الزرار الموحّد في fagala.css، والصفحة دي مختلفة في دول بس */
    .btn { padding: 12px 28px; font-size: 15px; font-weight: 600; }
    .nav-links { margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap; }
    .nav-link { padding:9px 16px; background:var(--surface); border-radius:9px; text-decoration:none;
        color:var(--accent-text); font-size:14px; }
    .nav-link:hover { background:var(--bg); }

    /* سلايدر نوع bool (مبني على تصميم الوضع الليلي في fagala.css) */
    .toggle-wrap { display:inline-flex; align-items:center; gap:10px; }
    .toggle-wrap input[type="checkbox"] { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
    /* 🔴 الأسماء دي متفردة بقصد. سلايدر الوضع الليلي في fagala.css بيستعمل
       `.tt-track`/`.tt-knob`، وفيه قاعدة `:root[data-theme="dark"] .tt-track`
       بتلوّن أي عنصر بالاسم ده أزرق **من غير أي علاقة بالشيك بوكس**.
       فلو رجّعت الأسماء دي هنا، السلايدر هيتجمّد على «مفتوح» في الوضع
       الليلي تاني. */
    .toggle-wrap .st-track {
        display:inline-block; position:relative;
        width:46px; height:26px; border-radius:99px;
        background:var(--line-soft); border:1.5px solid var(--line);
        transition:background .2s, border-color .2s;
        cursor:pointer;
    }
    .toggle-wrap .st-knob {
        position:absolute; top:2px; right:2px;
        width:18px; height:18px; border-radius:50%;
        background:var(--surface); box-shadow:var(--shadow-sm);
        transition:transform .2s;
    }
    .toggle-wrap input[type="checkbox"]:checked + .st-track {
        background:var(--accent); border-color:var(--accent);
    }
    .toggle-wrap input[type="checkbox"]:checked + .st-track .st-knob {
        transform:translateX(-20px);
    }
    .toggle-status { font-size:13px; color:var(--ink-700); user-select:none; }
    .toggle-notice { font-size:12px; color:var(--neg); margin-top:6px; line-height:1.5; }
    .fld-hint { font-size:12px; color:var(--ink-500); margin-top:6px; line-height:1.5; }
</style>

<?php if (is_owner()): ?>
<div class="nav-links">
    <a href="users.php" class="nav-link"><i class="ti ti-users"></i> إدارة المستخدمين</a>
    <a href="definitions.php" class="nav-link"><i class="ti ti-list-details"></i> التعريفات</a>
</div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post" class="settings-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php foreach ($editable as $key => $meta): ?>
            <?php [$label, $type] = $meta; ?>
            <div class="form-row<?= $type !== 'bool' ? ' is-locked' : '' ?>">
                <label for="fld_<?= e($key) ?>"><?= e($label) ?></label>
                <div class="fld-line">
                    <?php if ($type === 'user'): ?>
                        <select id="fld_<?= e($key) ?>" name="<?= e($key) ?>" disabled>
                            <option value="0">— مفيش (الأونر بس) —</option>
                            <?php foreach ($settingsUsers as $su): ?>
                                <option value="<?= (int)$su['id'] ?>" <?= (int)($current[$key] ?? 0) === (int)$su['id'] ? 'selected' : '' ?>>
                                    <?= e($su['full_name']) ?><?= $su['branch_name'] ? ' — ' . e($su['branch_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'bool'): ?>
                        <?php $isBoolOn = ($current[$key] ?? '1') === '1'; ?>
                        <div class="toggle-wrap">
                            <input type="hidden" name="<?= e($key) ?>" value="0">
                            <input type="checkbox"
                                   id="fld_<?= e($key) ?>"
                                   name="<?= e($key) ?>"
                                   value="1"
                                   <?= $isBoolOn ? 'checked' : '' ?>>
                            <label for="fld_<?= e($key) ?>" class="st-track">
                                <span class="st-knob"></span>
                            </label>
                            <span class="toggle-status">
                                <?= $isBoolOn ? 'شغّال — الباقي بينزل سلفة' : 'مقفول — الباقي بيتساب' ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <input type="<?= e($type) ?>"
                               id="fld_<?= e($key) ?>"
                               <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                               name="<?= e($key) ?>"
                               value="<?= e($current[$key] ?? '') ?>"
                               readonly>
                    <?php endif; ?>
                    <?php if ($type !== 'bool'): ?>
                        <button type="button" class="fld-edit" title="اضغط عشان تعدّل القيمة دي">تعديل</button>
                    <?php endif; ?>
                </div>
                <?php if ($type === 'bool'): ?>
                    <div class="toggle-notice" style="display:none;">
                        اضغط «حفظ الإعدادات» تحت عشان يتطبّق.
                    </div>
                <?php endif; ?>
                <?php if ($key === 'cashier_deficit_carry'): ?>
                    <div class="fld-hint">
                        مقفول = الباقي من عجز الكاشير مابينزلش سلفة على الموظف، وبيتساب من غير تحصيل. شغّله تاني بعد ما بيانات الورديات تتراجع وتتظبط.
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn">حفظ الإعدادات</button>
    </form>
</div>

<script>
// حقول **الكتابة** مقفولة افتراضياً وكل واحد جنبه زرار «تعديل».
// السبب: تعديل بالغلط في إعداد زي «حساب إيراد المبيعات» أو مفتاح الإشعارات
// ممكن يوقّع حاجات من غير ما حد ياخد باله. فالقفل هو الوضع الطبيعي.
//
// 🔴 السلايدرات (نوع `bool`) **مستثناة** — بتشتغل بدوسة واحدة.
//    السبب: الدوس على سلايدر قرار مقصود بطبيعته، مفيش حد بيقلبه بالغلط.
//    ولما كان مقفول، الدوسة مكانتش بتعمل حاجة والمستخدم يفتكر الموقع باظ
//    (`<label for>` على عنصر disabled بيتجاهله المتصفح خالص).
//    فصف الـbool مالوش زرار «تعديل»، والحلقة تحت بتتخطاه لوحدها
//    لأن `btn` بيطلع null.
(function () {
    var form = document.querySelector('.settings-form');
    if (!form) return;

    form.querySelectorAll('.form-row').forEach(function (row) {
        var fld = row.querySelector('input:not([type=hidden]), select');
        var btn = row.querySelector('.fld-edit');
        if (!fld || !btn) return;

        var isCheckbox = (fld.type === 'checkbox');
        var original = isCheckbox ? fld.checked : fld.value;

        btn.addEventListener('click', function () {
            if (row.classList.contains('is-open')) {
                // إلغاء: رجّع القيمة الأصلية واقفل تاني
                if (isCheckbox) {
                    fld.checked = original;
                } else {
                    fld.value = original;
                }
                lock();
            } else {
                row.classList.remove('is-locked');
                row.classList.add('is-open');
                if (fld.tagName === 'SELECT' || isCheckbox) {
                    fld.disabled = false;
                } else {
                    fld.removeAttribute('readonly');
                }
                btn.textContent = 'إلغاء';
                btn.title = 'رجّع القيمة زي ما كانت';
                fld.focus();
            }
        });

        function lock() {
            row.classList.remove('is-open');
            row.classList.add('is-locked');
            if (fld.tagName === 'SELECT' || isCheckbox) {
                fld.disabled = true;
            } else {
                fld.setAttribute('readonly', 'readonly');
            }
            btn.textContent = 'تعديل';
            btn.title = 'اضغط عشان تعدّل القيمة دي';
        }
    });

    // تحديث نص السلايدر وإظهار تنبيه الحفظ عند التغيير
    form.querySelectorAll('.toggle-wrap input[type="checkbox"]').forEach(function (chk) {
        var row = chk.closest('.form-row');
        var statusSpan = row ? row.querySelector('.toggle-status') : null;
        var notice = row ? row.querySelector('.toggle-notice') : null;

        chk.addEventListener('change', function () {
            if (statusSpan) {
                statusSpan.textContent = chk.checked ? 'شغّال — الباقي بينزل سلفة' : 'مقفول — الباقي بيتساب';
            }
            if (notice) {
                notice.style.display = 'block';
            }
        });
    });

    // 🔴 الـselect والـcheckbox المقفولين (disabled) مابيتبعتوش مع الفورم — فبنفك القفل عن
    // كل الحقول لحظة الإرسال عشان القيم كلها توصل زي ما هي.
    // 🔴 الحقل المخفي (hidden) عمره ما بياخد disabled عشان يتبعت دايماً.
    form.addEventListener('submit', function () {
        form.querySelectorAll('input:not([type=hidden]), select').forEach(function (f) {
            f.disabled = false;
            f.removeAttribute('readonly');
        });
    });
})();
</script>



<!-- =====================================================================
     حالة وكلاء المزامنة — بديل فحص الجسر
     الجسر اتشال: النظام بقى بيقرا من نسخة محلية بيغذّيها الوكلاء،
     فالسؤال المهم بقى "الوكلاء شغالين؟" مش "الجسر رادّ؟".
     ===================================================================== -->
<?php
require_once __DIR__ . '/agent_lib.php';
$agents   = agent_list();
$inWindow = agent_in_window();
$wMin     = agent_cfg_int('agent_warn_min', 20);
$aMin     = agent_cfg_int('agent_alert_min', 60);
?>
<div class="card" id="agents">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:4px;">وكلاء المزامنة</h3>
    <p style="font-size:12.5px;color:var(--ink-500);margin-bottom:14px;">
        كل وكيل بيبعت نبضة كل دقيقتين حتى لو مفيش بيع جديد — فالسكوت معناه
        إن الجهاز أو الوكيل واقف، مش إن الشغل هادي.
        <?php if (!$inWindow): ?>
            <br><b style="color:var(--ink-600);">دلوقتي برّه وقت المراقبة
            (<?= e(setting('agent_watch_from', '10:00')) ?> –
             <?= e(setting('agent_watch_to', '00:30')) ?>) — مفيش تنبيهات.</b>
        <?php else: ?>
            <br>أصفر بعد <?= $wMin ?> دقيقة · أحمر بعد <?= $aMin ?> دقيقة.
        <?php endif; ?>
    </p>

    <?php if (!$agents): ?>
        <div style="padding:16px;background:var(--warn-soft);border-radius:10px;font-size:13px;">
            جدول الوكلاء لسه ما اتعملش. شغّل <code>upgrade_agents.sql</code> الأول.
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($agents as $a):
            $st = $a['status'];
            $col = $st === AGENT_ALERT ? 'var(--neg,var(--neg))'
                 : ($st === AGENT_WARN ? 'var(--warn,var(--warn))'
                 : ($st === AGENT_OFF  ? 'var(--ink-400,var(--ink-400))' : 'var(--pos,var(--pos))'));
            $bg  = $st === AGENT_ALERT ? 'var(--neg-soft,var(--ink-400))'
                 : ($st === AGENT_WARN ? 'var(--warn-soft,var(--warn))'
                 : ($st === AGENT_OFF  ? 'var(--line-soft,var(--ink-400))' : 'var(--pos-soft,var(--ink-400))'));
            $dot = $st === AGENT_ALERT ? 'ti-ban' : ($st === AGENT_WARN ? 'ti-alert-triangle'
                 : ($st === AGENT_OFF ? 'ti-moon' : 'ti-circle-check'));
        ?>
            <div style="display:flex;align-items:center;gap:12px;padding:11px 14px;
                 border-radius:10px;background:<?= $bg ?>;flex-wrap:wrap;">
                <span style="font-size:17px;color:<?= $col ?>;"><i class="ti <?= $dot ?>"></i></span>
                <b style="min-width:70px;color:<?= $col ?>;"><?= e($a['label']) ?></b>

                <span style="font-size:12.5px;color:var(--ink-600);flex:1;min-width:170px;">
                    <?php if ($a['last_beat_at'] === null): ?>
                        <b style="color:<?= $col ?>;">عمره ما بعت</b> — لسه ما اتركّبش
                    <?php else: ?>
                        آخر نبضة: <b style="color:<?= $col ?>;"><?= e(agent_ago($a['quiet_min'])) ?></b>
                        <?php if ($a['data_min'] !== null): ?>
                            · آخر داتا: <?= e(agent_ago($a['data_min'])) ?>
                            (<?= (int) $a['last_rows'] ?> صف)
                        <?php else: ?>
                            · <span style="color:var(--ink-400);">لسه ما بعتش داتا</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>

                <?php if (!empty($a['host_name'])): ?>
                    <code style="font-size:11px;color:var(--ink-400);"><?= e($a['host_name']) ?></code>
                <?php endif; ?>
                <?php if (!empty($a['agent_version'])): ?>
                    <span style="font-size:11px;color:var(--ink-400);">v<?= e($a['agent_version']) ?></span>
                <?php endif; ?>

                <?php if (!empty($a['last_error'])):
                    $errTs  = !empty($a['last_error_at']) ? strtotime((string) $a['last_error_at']) : false;
                    $dataTs = !empty($a['last_data_at'])  ? strtotime((string) $a['last_data_at'])  : false;
                    $isHist = ($errTs !== false && $dataTs !== false && $dataTs > $errTs);
                ?>
                    <?php if ($isHist): ?>
                        <div style="flex:1 1 100%;font-size:11.5px;color:var(--ink-500);
                             padding-top:4px;border-top:1px dashed rgba(0,0,0,.08);margin-top:2px;">
                            آخر خطأ (عدّى) — <?= e((string) $a['last_error_at']) ?>: <?= e($a['last_error']) ?>
                        </div>
                    <?php else: ?>
                        <div style="flex:1 1 100%;font-size:11.5px;color:var(--neg);
                             padding-top:4px;border-top:1px dashed rgba(0,0,0,.08);margin-top:2px;">
                            آخر خطأ<?= !empty($a['last_error_at']) ? ' (' . e((string) $a['last_error_at']) . ')' : '' ?>: <?= e($a['last_error']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $balSrc  = setting('balance_source', 'hq');
    $balNow  = bal_table();
    $onBr    = ($balNow !== 'hq_balances');
    ?>
    <div style="margin-top:14px;padding:12px 14px;border-radius:10px;
         background:<?= $onBr ? 'var(--pos-soft,var(--line-soft))' : 'var(--line-soft,var(--line-soft))' ?>;
         font-size:13px;">
        <b>مصدر الأرصدة:</b>
        <?php if ($onBr): ?>
            <span style="color:var(--pos);font-weight:700;">الفروع مباشرة</span> —
            الرصيد بيوصل لحظة البيع من غير ما يستنى الترحيل.
        <?php else: ?>
            <span style="color:var(--ink-600);font-weight:700;">الإدارة</span> —
            الرصيد بيستنى الترحيل، فممكن يكون متأخر.
            <?php if ($balSrc === 'branch'): ?>
                <br><b style="color:var(--warn);">الإعداد على «الفروع» بس الجدول لسه فاضي —
                رجعنا للإدارة تلقائياً لحد ما أول وكيل فرع يبعت.</b>
            <?php endif; ?>
        <?php endif; ?>
        <br>
        <span style="font-size:12px;color:var(--ink-500);">
            للتبديل: غيّر <code>balance_source</code> في جدول <code>settings</code>
            لـ <code>branch</code> أو <code>hq</code>. الرجوع فوري ومفيش داتا بتضيع.
        </span>
    </div>

    <a href="settings.php#agents" class="btn"
       style="margin-top:14px;display:inline-block;text-decoration:none;">
        <i class="ti ti-refresh"></i> تحديث
    </a>
</div>



<?php require_once __DIR__ . '/includes/footer.php'; ?>
