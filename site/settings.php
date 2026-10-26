<?php
/**
 * settings.php — الإعدادات العامة
 * نظام الفجالة ستور المالي
 *
 * تعديل القيم المخزّنة في جدول settings.
 */
require_once __DIR__ . '/auth.php';
require_permission('settings');
require_once __DIR__ . '/settings_lib.php';

$pdo       = db();
$flash     = '';
$flashType = 'success';

// الإعدادات القابلة للتعديل من الواجهة (مفتاح => [التسمية, النوع])
$editable = settings_schema();

// قائمة المستخدمين لحقول نوع user
$settingsUsers = settings_users($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'invoice_guard') {
        header('Content-Type: application/json; charset=utf-8');
        if (!is_owner()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'غير مصرح لك (للأونر فقط).']);
            exit;
        }
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'انتهت صلاحية الجلسة، حاول مرة أخرى.']);
            exit;
        }
        $distId = (int)($_POST['dist_id'] ?? 0);
        $off = !empty($_POST['off']) ? 1 : 0;
        if ($distId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'رقم الفاتورة غير صحيح.']);
            exit;
        }
        try {
            $userId = (int) current_user()['id'];
            $res = settings_set_invoice_guard($pdo, $distId, $off, $userId);
            echo json_encode(['success' => true] + $res);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // ── الملخص اليومي للإدارة (بريف ١١١) — بيتحفظ لوحده على change، مفيش زرار حفظ ──
    if (($_POST['action'] ?? '') === 'daily_summary_save') {
        header('Content-Type: application/json; charset=utf-8');
        if (!is_owner()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'غير مصرح لك (للأونر فقط).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'انتهت صلاحية الجلسة، حاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            settings_save_daily_summary(
                $pdo,
                (string) ($_POST['on'] ?? '0'),
                (int) ($_POST['hour'] ?? 24),
                (string) ($_POST['users'] ?? '')
            );
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'حصل خطأ، جرّب تاني'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ── تغيير باسورد الأونر (بريف ٩٢) ──
    if (($_POST['action'] ?? '') === 'change_password') {
        if (!is_owner()) {
            $_SESSION['flash'] = 'للأونر بس';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
            exit;
        }

        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $_SESSION['flash'] = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
            exit;
        }

        require_once __DIR__ . '/sensitive_lib.php';
        $now = time();
        if (!empty($_SESSION['pw_block_until']) && (int) $_SESSION['pw_block_until'] > $now) {
            $_SESSION['flash'] = 'استنى ٥ دقايق وجرّب تاني.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
            exit;
        }

        try {
            $currentPass = (string) ($_POST['current_password'] ?? '');
            $newPass     = (string) ($_POST['new_password'] ?? '');
            $newPass2    = (string) ($_POST['new_password2'] ?? '');

            $userId = (int) (current_user()['id'] ?? 0);
            $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$userId]);
            $hash = $st->fetchColumn();

            if (!$hash || !password_verify($currentPass, (string) $hash)) {
                $fails = ((int) ($_SESSION['pw_fails'] ?? 0)) + 1;
                if ($fails >= SENS_MAX_FAILS) {
                    $_SESSION['pw_block_until'] = $now + SENS_BLOCK_SECONDS;
                    $_SESSION['pw_fails'] = 0;
                } else {
                    $_SESSION['pw_fails'] = $fails;
                }
                $_SESSION['flash'] = 'الباسورد الحالي غلط';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
                exit;
            }

            if (mb_strlen($newPass) < 8) {
                $_SESSION['flash'] = 'الباسورد الجديد لازم ٨ حروف على الأقل';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
                exit;
            }

            if ($newPass !== $newPass2) {
                $_SESSION['flash'] = 'التأكيد مش زي الباسورد الجديد';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
                exit;
            }

            if ($newPass === $currentPass) {
                $_SESSION['flash'] = 'الباسورد الجديد زي القديم';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
                exit;
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $updSt = $pdo->prepare('UPDATE users SET password_hash = ?, pw_version = pw_version + 1 WHERE id = ?');
            $updSt->execute([$newHash, $userId]);

            $stVer = $pdo->prepare('SELECT pw_version FROM users WHERE id = ?');
            $stVer->execute([$userId]);
            $newPwVer = (int) $stVer->fetchColumn();
            $_SESSION['user']['pw_version'] = $newPwVer;

            session_regenerate_id(true);
            $_SESSION['pw_fails'] = 0;
            unset($_SESSION['pw_block_until']);

            $_SESSION['flash'] = 'اتغيّر الباسورد، وأي جهاز تاني كان داخل بحسابك هيخرج.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'حصل خطأ، جرّب تاني';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
            exit;
        }
    }

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
                // طابور الربط (بريف ٥٠): ربع ساعة على الأقل — أقل من كده الربط العادي نفسه هيبان «واقف»
                if ($key === 'agent_repl_alert_min') {
                    $val = (string) max(15, (int) $val);
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

    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
                backdrop-filter:blur(3px); z-index:999; align-items:center; justify-content:center; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:760px; max-height:85vh; padding:22px; }
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

<?php if (is_owner()): ?>
<div class="card" style="margin-bottom:18px;">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:16px;color:var(--ink-900);">تغيير الباسورد</h3>
    <form method="post" style="max-width:560px;">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-row">
            <label for="fld_current_password">الباسورد الحالي</label>
            <input type="password" id="fld_current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="form-row">
            <label for="fld_new_password">الباسورد الجديد</label>
            <input type="password" id="fld_new_password" name="new_password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="form-row">
            <label for="fld_new_password2">تأكيد الباسورد الجديد</label>
            <input type="password" id="fld_new_password2" name="new_password2" autocomplete="new-password" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary">غيّر الباسورد</button>
    </form>
</div>

<?php
// ── كارت الملخص اليومي (بريف ١١١) ──
$dsOn    = (string) setting('daily_summary_on', '0') === '1';
$dsHour  = (int) setting('daily_summary_hour', '23');
$dsSel   = array_map('intval', explode(',', (string) setting('daily_summary_users', '')));
$dsUsers = settings_ds_users($pdo);
?>
<div class="card ds-locked" style="margin-bottom:18px;" id="dailySummaryCard">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">
        <h3 style="margin:0;font-size:16px;color:var(--ink-900);">الملخص اليومي</h3>
        <button type="button" class="fld-edit" id="dsEdit" title="مقفول عشان محدش يغيّر حاجة بالغلط — اضغط عشان تعدّل">تعديل</button>
    </div>
    <div class="fld-hint" style="margin-bottom:14px;">
        إشعار كل ليلة: كل فرع باع كام مقارنة بنفس اليوم الأسبوع اللي فات، والأكتر بيعاً، والأصناف اللي خلصت. أي تغيير هنا بيتحفظ لوحده.
        · <a href="daily_summary.php">شوف ملخص النهاردة</a>
    </div>
    <div class="form-row">
        <div class="toggle-wrap">
            <input type="checkbox" id="dsOn" <?= $dsOn ? 'checked' : '' ?> disabled>
            <label for="dsOn" class="st-track"><span class="st-knob"></span></label>
            <span class="toggle-status" id="dsOnTxt"><?= $dsOn ? 'شغّال' : 'مقفول' ?></span>
        </div>
    </div>
    <div class="form-row">
        <label for="dsHour">يتبعت الساعة</label>
        <select id="dsHour" style="max-width:160px;" disabled>
            <?php for ($h = 18; $h <= 24; $h++): ?>
                <option value="<?= $h ?>" <?= $h === $dsHour ? 'selected' : '' ?>><?= $h - 12 ?> بالليل (<?= $h === 24 ? '00' : $h ?>:00)</option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="ds-users-lbl">يوصل لمين</div>
    <div class="ds-users">
        <?php foreach ($dsUsers as $u): ?>
            <?php $dsChecked = in_array((int) $u['id'], $dsSel, true); ?>
            <label class="ds-u<?= $dsChecked ? '' : ' ds-off' ?>">
                <input type="checkbox" class="ds-user" value="<?= (int) $u['id'] ?>" <?= $dsChecked ? 'checked' : '' ?> disabled>
                <span class="ds-n"><?= e($u['full_name']) ?></span>
                <span class="ds-r"><?= e((string) $u['role_name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <style>
        .ds-users-lbl { font-size:14px; color:var(--ink-700); margin-bottom:8px; font-weight:500; }
        .ds-users { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:4px 16px; }
        .ds-u { display:flex; align-items:center; gap:8px; padding:5px 8px; border-radius:8px; cursor:pointer; min-width:0; }
        .ds-u:hover { background:var(--line-soft); }
        .ds-u input { width:16px; height:16px; margin:0; flex:none; accent-color:var(--accent-text); }
        .ds-n { font-size:14px; color:var(--ink-900); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ds-r { font-size:12px; color:var(--ink-500); flex:none; }
        /* مقفول: بيظهر اللي بيوصلهم بس، ومفيش حاجة تتداس */
        .ds-locked .ds-u.ds-off { display:none; }
        .ds-locked .ds-u { cursor:default; }
        .ds-locked .ds-u:hover { background:none; }
        .ds-locked .st-track { opacity:.6; cursor:default; }
    </style>
</div>
<script>
(function () {
    var card = document.getElementById('dailySummaryCard');
    if (!card) return;
    function save() {
        var users = [];
        card.querySelectorAll('.ds-user:checked').forEach(function (c) { users.push(c.value); });
        var body = new URLSearchParams();
        body.append('action', 'daily_summary_save');
        body.append('csrf', <?= json_encode(csrf_token()) ?>);
        body.append('on', document.getElementById('dsOn').checked ? '1' : '0');
        body.append('hour', document.getElementById('dsHour').value);
        body.append('users', users.join(','));
        fetch('settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (typeof fgToast !== 'function') return;
            if (d.success) fgToast('اتحفظ', 'ok');
            else fgToast(d.error || 'ماتحفظش', 'err');
        })
        .catch(function () {
            if (typeof fgToast === 'function') fgToast('تعذّر الاتصال بالسيرفر', 'err');
        });
    }
    card.addEventListener('change', function (ev) {
        if (card.classList.contains('ds-locked')) return;
        document.getElementById('dsOnTxt').textContent = document.getElementById('dsOn').checked ? 'شغّال' : 'مقفول';
        if (ev.target.classList.contains('ds-user')) {
            ev.target.closest('.ds-u').classList.toggle('ds-off', !ev.target.checked);
        }
        save();
    });
    // مقفول افتراضياً — «تعديل» يفتح، و«قفل» يرجّعه (اللي اتغيّر اتحفظ خلاص)
    var btn = document.getElementById('dsEdit');
    btn.addEventListener('click', function () {
        var open = card.classList.toggle('ds-locked') === false;
        card.querySelectorAll('#dsOn, #dsHour, .ds-user').forEach(function (f) { f.disabled = !open; });
        btn.textContent = open ? 'قفل' : 'تعديل';
    });
})();
</script>
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
                                <?php if ($key === 'cashier_deficit_carry'): ?>
                                    <?= $isBoolOn ? 'شغّال — الباقي بينزل سلفة' : 'مقفول — الباقي بيتساب' ?>
                                <?php else: ?>
                                    <?= $isBoolOn ? 'شغّال' : 'مقفول' ?>
                                <?php endif; ?>
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
            $availableUpdate = agent_dist_update((string) $a['agent_key'], (string) ($a['agent_version'] ?? ''));
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

                <?php /* طابور وتصدير واستلام ربط سيستم الفروع (بريف ٥٠ و ٥٢) — null = الوكيل لسه على نسخة قديمة */
                $rs = $a['repl_status'] ?? null;
                if ($rs !== null):
                    $rw = (int) ($a['repl_waiting'] ?? 0); ?>
                    <span style="font-size:12px;color:<?= in_array($rs, ['stuck', 'behind', 'not_exporting'], true) ? 'var(--neg)' : 'var(--ink-500)' ?>;">
                        <?php if ($rs === 'stuck'): ?>
                            <b><i class="ti ti-alert-circle"></i> الربط واقف:</b> <?= $rw ?> أمر من <?= e(agent_ago((int) $a['repl_age_min'])) ?>
                        <?php elseif ($rs === 'not_exporting'): ?>
                            <b><i class="ti ti-alert-circle"></i> الإدارة مابتطلّعش ملفات:</b> <?= (int) ($a['repl_send_pending'] ?? 0) ?> أمر مستني تصدير من <?= e(agent_ago((int) ($a['repl_send_age_min'] ?? 0))) ?>
                        <?php elseif ($rs === 'behind'): ?>
                            <b><i class="ti ti-alert-circle"></i> مابيستلمش:</b> آخر أمر <?= e((string) ($a['repl_last_recv'] ?? '؟')) ?> (الإدارة وصلت <?= e((string) ($a['hq_last_sent'] ?? '؟')) ?>)
                        <?php elseif ($rw === 0): ?>
                            الربط: <i class="ti ti-circle-check"></i> مفيش حاجة مستنية<?php
                                if ($a['agent_key'] === 'hq' && !empty($a['repl_last_sent'])) {
                                    echo ' · آخر تصدير: ' . e((string) $a['repl_last_sent']);
                                } elseif (!empty($a['repl_last_recv'])) {
                                    echo ' · آخر أمر: ' . e((string) $a['repl_last_recv']);
                                }
                            ?>
                        <?php else: ?>
                            الربط: <?= $rw ?> أمر بيتنفّذ (من <?= e(agent_ago((int) $a['repl_age_min'])) ?>)
                        <?php endif; ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($a['host_name'])): ?>
                    <code style="font-size:11px;color:var(--ink-400);"><?= e($a['host_name']) ?></code>
                <?php endif; ?>
                <?php if (!empty($a['agent_version'])): ?>
                    <span style="font-size:11px;color:var(--ink-400);">v<?= e($a['agent_version']) ?></span>
                <?php endif; ?>
                <?php if ($availableUpdate !== null): ?>
                    <span class="fchip fchip-warn">متاح <?= e($availableUpdate['version']) ?></span>
                <?php endif; ?>

                <?php if (($a['repl_status'] ?? null) === 'stuck'): ?>
                    <div style="flex:1 1 100%;font-size:11.5px;color:var(--neg);
                         padding-top:4px;border-top:1px dashed rgba(0,0,0,.08);margin-top:2px;">
                        أول أمر واقف في طابور الربط (ابعته للمهندس) — رقم <code><?= e((string) $a['repl_first_id']) ?></code>:
                        <code style="display:block;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;margin-top:3px;"><?= e((string) $a['repl_first_text']) ?></code>
                    </div>
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
    <?php if (is_owner()): ?>
    <a href="rabt_guard.php" class="btn" style="margin-top:14px;margin-right:8px;display:inline-block;text-decoration:none;"><i class="ti ti-alert-triangle"></i> حارس الربط</a>
    <?php endif; ?>
</div>

<?php if (is_owner()): ?>
    <?php
        $guardInvoices = settings_guard_invoices($pdo);
    ?>
    <div class="card" style="margin-top:20px;">
        <h3 style="margin-bottom:6px;">فك حارس رصيد الإدارة عن فاتورة</h3>
        <p style="font-size:13px;color:var(--ink-500);margin-bottom:14px;">
            السماح بتوزيع فاتورة شراء على الفروع حتى كمية الفاتورة دون التقيد برصيد الإدارة الحالي (حتى لو رصيد الإدارة صفر أو مش واصل).
        </p>
        <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('invGuardModal').classList.add('open')">
            اختار فاتورة…
        </button>
    </div>

    <!-- بوب أب فك حارس الفاتورة -->
    <div class="modal-bg" id="invGuardModal" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="modal" style="max-width:760px;width:95%;max-height:85vh;display:flex;flex-direction:column;padding:22px;background:var(--surface);border-radius:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3 style="margin:0;font-size:17px;color:var(--ink-900);">فك حارس رصيد الإدارة عن فاتورة</h3>
                <button type="button" onclick="document.getElementById('invGuardModal').classList.remove('open')" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink-500);">&times;</button>
            </div>
            <div style="margin-bottom:12px;">
                <input type="search" id="guardSearchInput" placeholder="بحث برقم الفاتورة أو اسم المورد..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-size:13.5px;background:var(--surface);color:var(--ink-900);">
            </div>
            <div style="overflow-y:auto;flex:1;max-height:55vh;border:1px solid var(--line);border-radius:10px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;" id="guardTable">
                    <thead>
                        <tr style="background:var(--line-soft);text-align:right;position:sticky;top:0;z-index:2;">
                            <th style="padding:10px 12px;border-bottom:1px solid var(--line);">رقم الفاتورة</th>
                            <th style="padding:10px 12px;border-bottom:1px solid var(--line);">المورد</th>
                            <th style="padding:10px 12px;border-bottom:1px solid var(--line);">التاريخ</th>
                            <th style="padding:10px 12px;border-bottom:1px solid var(--line);">الحالة</th>
                            <th style="padding:10px 12px;border-bottom:1px solid var(--line);text-align:center;">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody id="guardTbody">
                    <?php if (empty($guardInvoices)): ?>
                        <tr><td colspan="5" style="padding:20px;text-align:center;color:var(--ink-500);">لا توجد فواتير توزيع.</td></tr>
                    <?php else: ?>
                        <?php
                            $stMap = [
                                'new'   => ['جديدة', 'var(--ink-700)', 'var(--line-soft)'],
                                'split' => ['موزعة جزئياً', 'var(--warn)', 'var(--warn-soft)'],
                                'done'  => ['مكتملة', 'var(--pos)', 'var(--pos-soft)'],
                            ];
                        ?>
                        <?php foreach ($guardInvoices as $gi): ?>
                            <?php
                                $isOff = !empty($gi['hq_guard_off']);
                                $stInfo = $stMap[$gi['status']] ?? [$gi['status'], 'var(--ink-700)', 'var(--line-soft)'];
                            ?>
                            <tr data-inv="<?= e(mb_strtolower($gi['invoice_no'] ?? '')) ?>"
                                data-supp="<?= e(mb_strtolower($gi['supplier_name'] ?? '')) ?>"
                                data-id="<?= (int)$gi['id'] ?>"
                                style="border-bottom:1px solid var(--line-soft);<?= $isOff ? 'background:var(--warn-soft);' : '' ?>">
                                <td style="padding:9px 12px;font-weight:600;">
                                    <?= e($gi['invoice_no'] ?: '#' . $gi['id']) ?>
                                    <span class="guard-badge" style="display:<?= $isOff ? 'inline-block' : 'none' ?>;margin-right:6px;font-size:11px;padding:1px 6px;border-radius:4px;background:var(--warn-fill);color:#fff;">الحارس مفكوك</span>
                                </td>
                                <td style="padding:9px 12px;"><?= e($gi['supplier_name']) ?></td>
                                <td style="padding:9px 12px;color:var(--ink-500);"><?= e($gi['dist_date']) ?></td>
                                <td style="padding:9px 12px;">
                                    <span style="font-size:11.5px;padding:2px 8px;border-radius:6px;color:<?= $stInfo[1] ?>;background:<?= $stInfo[2] ?>;font-weight:600;">
                                        <?= e($stInfo[0]) ?>
                                    </span>
                                </td>
                                <td style="padding:9px 12px;text-align:center;">
                                    <button type="button" class="btn btn-sm <?= $isOff ? 'btn-ghost' : 'btn-primary' ?> btn-guard-toggle"
                                            data-id="<?= (int)$gi['id'] ?>"
                                            data-off="<?= $isOff ? '0' : '1' ?>"
                                            onclick="toggleInvoiceGuard(this)"
                                            style="padding:4px 10px;font-size:12px;">
                                        <?= $isOff ? 'رجّع الحارس' : 'فك' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noGuardMatch" style="display:none;"><td colspan="5" style="padding:20px;text-align:center;color:var(--ink-500);">لا توجد نتائج مطابقة للبحث.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function toggleInvoiceGuard(btn) {
        var distId = btn.getAttribute('data-id');
        var newOff = btn.getAttribute('data-off');
        btn.disabled = true;

        var body = new URLSearchParams();
        body.append('action', 'invoice_guard');
        body.append('dist_id', distId);
        body.append('off', newOff);
        body.append('csrf', <?= json_encode(csrf_token()) ?>);

        fetch('settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (!data.success) {
                if (typeof fgToast === 'function') {
                    fgToast(data.error || 'تعذّر تحديث حالة الحارس', 'err');
                } else {
                    alert(data.error || 'تعذّر تحديث حالة الحارس');
                }
                return;
            }
            var row = btn.closest('tr');
            var isOff = String(data.off) === '1';
            btn.setAttribute('data-off', isOff ? '0' : '1');
            btn.textContent = isOff ? 'رجّع الحارس' : 'فك';
            btn.className = 'btn btn-sm ' + (isOff ? 'btn-ghost' : 'btn-primary') + ' btn-guard-toggle';
            row.style.background = isOff ? 'var(--warn-soft)' : '';
            var badge = row.querySelector('.guard-badge');
            if (badge) {
                badge.style.display = isOff ? 'inline-block' : 'none';
            }
            if (typeof fgToast === 'function') {
                fgToast(isOff ? 'تم فك حارس رصيد الإدارة عن الفاتورة' : 'تم تفعيل حارس رصيد الإدارة للفاتورة', 'ok');
            }
        })
        .catch(function() {
            btn.disabled = false;
            if (typeof fgToast === 'function') {
                fgToast('تعذّر الاتصال بالسيرفر', 'err');
            } else {
                alert('تعذّر الاتصال بالسيرفر');
            }
        });
    }

    (function() {
        var s = document.getElementById('guardSearchInput');
        if (!s) return;
        var tbody = document.getElementById('guardTbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr[data-inv]'));
        var noMatch = document.getElementById('noGuardMatch');

        s.addEventListener('input', function() {
            var q = s.value.trim().toLowerCase();
            var visible = 0;
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var inv = r.getAttribute('data-inv') || '';
                var supp = r.getAttribute('data-supp') || '';
                var matches = !q || inv.indexOf(q) !== -1 || supp.indexOf(q) !== -1;
                r.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }
            if (noMatch) noMatch.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
        });
    })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

