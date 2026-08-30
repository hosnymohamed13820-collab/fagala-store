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
    'business_day_start'        => ['بداية اليوم المحاسبي', 'time'],
    'currency'                  => ['العملة', 'text'],
    'shortage_supervisor_id'    => ['مسؤول متابعة طلبات النواقص (بيوصله إشعار مع الأونر)', 'user'],
    'purchase_supervisor_id'    => ['مسؤول المشتريات (بيوصله طلب المشتريات مع الأونر)', 'user'],
    'agent_watcher_id'          => ['مسؤول متابعة وكلاء المزامنة (بيوصله تنبيه مع الأونر)', 'user'],
    'agent_warn_min'            => ['تنبيه أصفر لو وكيل سكت (بالدقايق)', 'number'],
    'agent_alert_min'           => ['تنبيه أحمر لو وكيل سكت (بالدقايق)', 'number'],
    'agent_watch_from'          => ['مراقبة الوكلاء تبدأ الساعة', 'time'],
    'agent_watch_to'            => ['مراقبة الوكلاء تنتهي الساعة', 'time'],
    'sales_revenue_account_id'  => ['حساب «إيراد مبيعات» اللي الورديات بتترحّل عليه (رقم الحساب)', 'number'],
    'transfer_account_id'       => ['حساب «تحويل» اللي حركات تحويل الخزائن بتترحّل عليه (رقم الحساب)', 'number'],
    'attendance_edit_user_id'   => ['موظف مسموحله يعدّل ساعات طلبات التصحيح (مع الأونر)', 'user'],
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
    } elseif (isset($_POST['send_test_push'])) {
        // فحص الأونر في الـ POST صراحةً
        if (!is_owner()) {
            $flash = 'مالكش صلاحية — الإشعار التجريبي للأونر بس.';
            $flashType = 'error';
        } else {
            $currentUserId = (int) current_user()['id'];
            $st = $pdo->prepare('SELECT id, endpoint, p256dh, auth, user_agent FROM push_subscriptions WHERE user_id = ?');
            $st->execute([$currentUserId]);
            $mySubs = $st->fetchAll(PDO::FETCH_ASSOC);

            if (empty($mySubs)) {
                $flash = 'مفيش أجهزة مشتركة — فعّل الإشعارات من الجرس الأول.';
                $flashType = 'error';
            } else {
                require_once __DIR__ . '/push_lib.php';
                $testPayload = [
                    'title' => 'الفجالة ستور — إشعار تجريبي',
                    'body'  => 'الإشعار وصل. يبقى التشفير والتوقيع شغالين تمام.',
                    'link'  => '/settings.php',
                ];
                $results = [];
                $hasErr = false;
                foreach ($mySubs as $s) {
                    $res = push_send_one($s, $testPayload);
                    $ua = !empty($s['user_agent']) ? mb_substr((string) $s['user_agent'], 0, 80) : 'جهاز غير معروف';
                    $errSnippet = !empty($res['error']) ? mb_substr((string) $res['error'], 0, 80) : '';
                    $isOk = !empty($res['ok']);
                    if (!$isOk) {
                        $hasErr = true;
                    }
                    $results[] = [
                        'code' => (int) ($res['code'] ?? 0),
                        'ok'   => $isOk,
                        'ua'   => $ua,
                        'err'  => $errSnippet,
                    ];
                }
                $_SESSION['test_push_results'] = $results;
                $flash = $hasErr
                    ? 'الإرسال اتحاول بس فيه أجهزة مانجحتش — التفاصيل تحت.'
                    : 'الإشعار التجريبي اتبعت لكل أجهزتك.';
                $flashType = $hasErr ? 'error' : 'success';
            }
        }
    } else {
        $upd = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($editable as $key => $meta) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                // التحقق من عتبة العجز إنها رقم موجب
                if ($key === 'custody_deficit_tolerance') {
                    $val = (string) max(0, (float) $val);
                }
                // مسؤول النواقص ومسؤول المشتريات وموظف تعديل الحضور ومسؤول الوكلاء:
                // رقم مستخدم (٠ = مفيش)
                if ($key === 'shortage_supervisor_id' || $key === 'purchase_supervisor_id'
                    || $key === 'agent_watcher_id' || $key === 'attendance_edit_user_id') {
                    $val = (string) max(0, (int) $val);
                }
                // حساب إيراد المبيعات وحساب التحويل: رقم حساب موجب.
                // ٠ = بدون — الورديات/التحويلات تترحّل من غير حساب زي الأول بالظبط.
                if ($key === 'sales_revenue_account_id' || $key === 'transfer_account_id') {
                    $val = (string) max(0, (int) $val);
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
$testPushResults = $_SESSION['test_push_results'] ?? [];
unset($_SESSION['test_push_results']);

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
    .form-row input:focus { outline:none; border-color:var(--accent); background:#fff; }
    .btn { background:var(--accent); color:#fff; border:none; padding:12px 28px; border-radius:10px;
        font-size:15px; font-family:inherit; cursor:pointer; font-weight:600; }
    .btn:hover { background:var(--accent-hover); }
    .nav-links { margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap; }
    .nav-link { padding:9px 16px; background:#fff; border-radius:9px; text-decoration:none;
        color:var(--accent-hover); font-size:14px; }
    .nav-link:hover { background:var(--bg); }
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
            <div class="form-row">
                <label><?= e($label) ?></label>
                <?php if ($type === 'user'): ?>
                    <select name="<?= e($key) ?>" style="width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;font-size:14px;font-family:inherit;background:var(--line-soft);">
                        <option value="0">— مفيش (الأونر بس) —</option>
                        <?php foreach ($settingsUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>" <?= (int)($current[$key] ?? 0) === (int)$su['id'] ? 'selected' : '' ?>>
                                <?= e($su['full_name']) ?><?= $su['branch_name'] ? ' — ' . e($su['branch_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= e($type) ?>"
                           <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                           name="<?= e($key) ?>"
                           value="<?= e($current[$key] ?? '') ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn">حفظ الإعدادات</button>
    </form>
</div>

<?php if (is_owner()): ?>
<div class="card" id="test-push">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:4px;">إشعار تجريبي (Web Push)</h3>
    <p style="font-size:12.5px;color:var(--ink-500);margin-bottom:14px;">
        إرسال فوري من غير الطابور لكل أجهزتك المشتركة للتأكد من سلامة التشفير وتوقيع VAPID.
    </p>

    <?php if (!empty($testPushResults)): ?>
        <div style="margin-bottom:14px;display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($testPushResults as $tRes):
                $isOk = !empty($tRes['ok']);
                $cBg  = $isOk ? 'var(--pos-soft)' : 'var(--neg-soft)';
                $cCol = $isOk ? 'var(--pos)' : 'var(--neg)';
                $cIcon = $isOk ? 'ti-circle-check' : 'ti-alert-circle';
            ?>
                <div style="padding:10px 14px;border-radius:10px;background:<?= $cBg ?>;font-size:13px;">
                    <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:<?= $cCol ?>;">
                        <i class="ti <?= $cIcon ?>"></i>
                        <span>كود الاستجابة: <?= (int)$tRes['code'] ?></span>
                        <span><?= $isOk ? '— نجح الإرسال' : '— فشل الإرسال' ?></span>
                    </div>
                    <div style="font-size:12px;color:var(--ink-600);margin-top:4px;">
                        <b>الجهاز:</b> <?= e($tRes['ua']) ?>
                    </div>
                    <?php if (!empty($tRes['err'])): ?>
                        <div style="font-size:12px;color:var(--neg);margin-top:2px;">
                            <b>الرد:</b> <?= e($tRes['err']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="send_test_push" value="1">
        <button type="submit" class="btn">
            <i class="ti ti-send"></i> ابعت إشعار تجريبي لنفسي
        </button>
    </form>
</div>
<?php endif; ?>

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
            $col = $st === AGENT_ALERT ? 'var(--neg,#9d3b38)'
                 : ($st === AGENT_WARN ? 'var(--warn,#8a6524)'
                 : ($st === AGENT_OFF  ? 'var(--ink-400,#94a3b8)' : 'var(--pos,#2f6b4f)'));
            $bg  = $st === AGENT_ALERT ? 'var(--neg-soft,#fbeceb)'
                 : ($st === AGENT_WARN ? 'var(--warn-soft,#f7f0e2)'
                 : ($st === AGENT_OFF  ? 'var(--line-soft,#eff2f6)' : 'var(--pos-soft,#e8f3ec)'));
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
         background:<?= $onBr ? 'var(--pos-soft,#e8f3ec)' : 'var(--line-soft,#eff2f6)' ?>;
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
