<?php
/**
 * remote_scan.php — الموبايل كسكانر لاسلكي للكمبيوتر
 * نظام الفجالة ستور المالي
 *
 * صفحة مستقلة (من غير القائمة الجانبية) عشان تفتح نضيفة على الموبايل —
 * القائمة كانت بتبوظ التنسيق على الشاشات الصغيرة، ودي صفحة "أداة" بتتفتح
 * وتتقفل بسرعة. موجودة في القائمة الرئيسية باسم "سكانر الكمبيوتر".
 *
 * المدخلات: ?s=كود_الاقتران (اختياري — بييجي من QR صفحة pc_scan.php)
 */
require_once __DIR__ . '/auth.php';
require_permission('remote_scan');

$session = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['s'] ?? ''));
$uid = (int) (current_user()['id'] ?? 0);

// ===== قايمة الأجهزة: أونلاين فوق وأوفلاين تحت (AJAX) =====
if (($_GET['action'] ?? '') === 'devices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = db()->query(
            'SELECT session_code, hostname,
                    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS ago
             FROM scan_agents
             ORDER BY (TIMESTAMPDIFF(SECOND, last_seen, NOW()) > 15), last_seen DESC'
        )->fetchAll();
        echo json_encode(['ok' => true, 'devices' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== إيقاظ الوكيل (AJAX) =====
// الموبايل بينده هنا أول ما يفتح الكاميرا، وكل دقيقة وهي مفتوحة.
// بيسيب علامة على الجلسة صلاحيتها ٣ دقايق، ووكيل الكمبيوتر بيشوفها
// في رد scan_pull العادي فيسرّع من ١٠ ثواني لنص ثانية.
//
// من غير ده الوكيل كان لازم يفضل بيسأل كل ٨٠٠ جزء من الثانية طول
// اليوم (~١٠٠ ألف نداء) عشان أول كود يوصل بسرعة. دلوقتي بيهدّى وهو
// ساكن ويصحى قبل ما تمسك الكاميرا أصلاً.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wake') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) throw new RuntimeException('انتهت الجلسة، اعمل تحديث.');
        $s = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['session'] ?? ''));
        if ($s === '' || strlen($s) < 2) throw new RuntimeException('كود الاقتران غلط.');
        // الجهاز لازم يكون مسجّل نفسه قبل كده (INSERT من scan_pull)
        db()->prepare(
            'UPDATE scan_agents SET wake_until = NOW() + INTERVAL 3 MINUTE
             WHERE session_code = ?'
        )->execute([$s]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== استقبال الكود من الموبايل (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) throw new RuntimeException('انتهت الجلسة، اعمل تحديث.');
        $s = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['session'] ?? ''));
        $b = trim((string) ($_POST['barcode'] ?? ''));
        if ($s === '' || strlen($s) < 2)  throw new RuntimeException('كود الاقتران غلط.');
        if ($b === '' || strlen($b) > 64) throw new RuntimeException('باركود غير صالح.');
        db()->prepare('INSERT INTO scan_queue (session_code, barcode, created_by) VALUES (?, ?, ?)')
            ->execute([$s, $b, $uid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>سكانر الكمبيوتر — الفجالة ستور</title>
<link rel="icon" href="favicon-32.png?v=3">
<meta name="theme-color" content="#4d1620">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/fagala.css?v=32">
<link rel="stylesheet" href="assets/ashraqa-theme.css?v=3">
<!-- 🔴 الصفحة دي مستقلة عن includes/header.php عن قصد، فسكربت الثيم
     مكرّر هنا. لولاه data-theme مابتتحطّش والوضع الليلي مابيشتغلش. -->
<script>
    // 🔴 لازم يفضل هنا — فوق كل الاستايلات وتحت ميتا theme-color.
    // لو نزل تحت الـCSS، كل صفحة هتومض أبيض ربع ثانية قبل ما تسود.
    // وبيتنفّذ متزامن بقصد (مفيش defer/async) عشان يخلّص قبل أول رسم.
    (function () {
        var t;
        try { t = localStorage.getItem('fagala-theme'); } catch (e) {}
        if (t !== 'dark' && t !== 'light') {
            // أول مرة: امشي ورا إعداد الجهاز نفسه
            t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)
                ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
        // شريط المتصفح في الموبايل لازم يقلب هو كمان
        var m = document.querySelector('meta[name="theme-color"]');
        if (m) m.setAttribute('content', t === 'dark' ? '#2c0c12' : '#4d1620');
    })();
</script>
<style>
    /* 🔴 `--pos` و`--neg` **كانوا هنا** كـ`--pos:var(--pos)` — متغيّر بيشاور
       على نفسه. ده «دورة» في CSS ونتيجتها إن المتغيّر يبقى **باطل** خالص،
       فرقم العدّاد الكبير تحت (`.count b`) كان بيفقد لونه. ماكانش باين قبل
       كده لأن الصفحة ماكانتش بتحمّل `fagala.css` أصلاً فكل حاجة كانت باطلة.
       اتشالوا — `fagala.css` بيعرّفهم صح والصفحة بترثهم منه. */
    :root { --navy:var(--ink-900); --steel:var(--accent-text); --gold:var(--warn); --line:var(--ink-400);
            --ink:var(--accent-text); --mut:var(--accent-text); }
    * { box-sizing:border-box; margin:0; padding:0; font-family:'IBM Plex Sans Arabic',sans-serif; }
    html, body { overflow-x:hidden; }
    body { background:var(--line-soft); min-height:100vh; padding:16px; }
    .top { display:flex; align-items:center; justify-content:space-between; max-width:480px;
           margin:0 auto 14px; }
    .top a { color:var(--steel); text-decoration:none; font-size:14px; }
    .top b { color:var(--navy); font-size:17px; }
    .card { background:var(--surface); border-radius:16px; padding:20px; max-width:480px; margin:0 auto;
            box-shadow:0 4px 18px rgba(22,35,61,0.08); }
    .hint { color:var(--mut); font-size:13px; line-height:1.8; margin-bottom:16px; }
    .sess { display:flex; gap:10px; align-items:center; margin-bottom:14px; }
    .sess label { font-size:13px; color:var(--mut); white-space:nowrap; }
    .sess input { flex:1; padding:12px; border:1px solid var(--line); border-radius:10px;
                  font-size:20px; text-align:center; letter-spacing:3px; font-weight:700;
                  color:var(--navy); min-width:0; }
    .go { width:100%; padding:16px; font-size:17px; border:none; border-radius:12px;
          background:var(--navy); color:var(--surface); cursor:pointer; font-weight:700; }
    .go:active { background:var(--steel); }
    #devList { display:flex; flex-direction:column; gap:10px; margin-bottom:12px; }
    .dev { display:flex; align-items:center; gap:12px; padding:14px; border:1px solid var(--line);
           border-radius:12px; cursor:pointer; background:var(--surface); }
    .dev:active { background:var(--line-soft); }
    .dev.off { opacity:0.55; }
    .dev .dot { width:12px; height:12px; border-radius:50%; background:var(--accent-soft); flex-shrink:0; }
    .dev.on .dot { background:var(--pos-fill); box-shadow:0 0 0 4px rgba(26,158,92,0.15); }
    .dev .dinfo { flex:1; min-width:0; }
    .dev .dname { font-weight:700; color:var(--navy); font-size:16px; }
    .dev .dsub { font-size:12px; color:var(--mut); }
    .dev .dgo { font-size:22px; }
    .devEmpty { text-align:center; color:var(--mut); font-size:13px; padding:14px; }
    .manualLink { display:block; text-align:center; font-size:13px; color:var(--steel);
                  margin-bottom:12px; }
    .count { text-align:center; margin-top:18px; font-size:14px; color:var(--mut); }
    .count b { font-size:34px; color:var(--pos); display:block; line-height:1.3; }
    .last { text-align:center; font-size:15px; color:var(--ink); margin-top:4px; direction:ltr;
            font-variant-numeric:tabular-nums; }
</style>
</head>
<body>
    <div class="top">
        <b><i class="ti ti-antenna-bars-5"></i> سكانر الكمبيوتر</b>
        <a href="<?= e(first_allowed_module()) ?>">← رجوع للنظام</a>
    </div>
    <div class="card">
        <p class="hint">
            كل باركود تمسحه هنا بيتكتب فورًا في البرنامج المفتوح على الكمبيوتر المقترن —
            أي برنامج. بس اتأكد إن مؤشر الكتابة واقف في الخانة الصح هناك.
        </p>
        <div id="devList" aria-label="قايمة الأجهزة">
            <div class="devEmpty" id="devEmpty">جاري تحميل الأجهزة…</div>
        </div>
        <a href="#" class="manualLink" id="manualLink"
           onclick="document.getElementById('manualBox').style.display='flex';this.style.display='none';return false;">
           جهاز جديد؟ اكتب الكود يدويًا</a>
        <div class="sess" id="manualBox" style="display:none;">
            <label for="rsSession">كود الجهاز:</label>
            <input type="text" id="rsSession" value="<?= e($session) ?>" placeholder="PC1"
                   aria-label="كود الاقتران" autocomplete="off" enterkeyhint="go">
            <button type="button" class="go" style="width:auto;padding:12px 18px;" onclick="startRemote()"><i class="ti ti-camera"></i></button>
        </div>
        <!-- خانة مخفية بيصب فيها المكون المشترك -->
        <input type="text" id="rsBarcode" aria-label="باركود" oninput="pushCode(this)"
               style="position:fixed;top:0;right:0;width:1px;height:1px;opacity:0;border:0;padding:0;pointer-events:none;">
        <div class="count">أكواد اتبعتت<b id="rsCount">0</b></div>
        <div class="last" id="rsLast"></div>
    </div>
<script src="assets/barcode_scanner.js?v=9"></script>
<script>
    const CSRF = '<?= e(csrf_token()) ?>';
    let sent = 0, busy = false;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    // آخر كود جهاز استخدمه — عشان "افتح التاب وامسح على طول"
    const savedS = localStorage.getItem('rs_session');
    const sInp = document.getElementById('rsSession');
    if (!sInp.value && savedS) sInp.value = savedS;

    // ===== قايمة الأجهزة =====
    function agoText(s) {
        if (s < 60) return 'من ثواني';
        if (s < 3600) return 'من ' + Math.floor(s/60) + ' دقيقة';
        if (s < 86400) return 'من ' + Math.floor(s/3600) + ' ساعة';
        return 'من ' + Math.floor(s/86400) + ' يوم';
    }
    function loadDevices() {
        fetch('remote_scan.php?action=devices')
        .then(r => r.json())
        .then(j => {
            if (!j.ok) return;
            const box = document.getElementById('devList');
            if (!j.devices.length) {
                box.innerHTML = '<div class="devEmpty">مفيش أجهزة لسه — شغّل الوكيل على الكمبيوتر الأول وهيظهر هنا لوحده.</div>';
                return;
            }
            box.innerHTML = j.devices.map(d => {
                const on = d.ago <= 15;
                const name = d.hostname ? d.hostname : d.session_code;
                const sub = on ? 'متصل الآن' : 'آخر ظهور ' + agoText(d.ago);
                return '<div class="dev ' + (on ? 'on' : 'off') + '" role="button" tabindex="0" ' +
                       'onclick="pickDevice(\'' + escapeHtml(d.session_code) + '\',' + (on?1:0) + ')">' +
                       '<span class="dot"></span>' +
                       '<span class="dinfo"><span class="dname">' + escapeHtml(name) + '</span><br>' +
                       '<span class="dsub">' + escapeHtml(d.session_code) + ' · ' + sub + '</span></span>' +
                       '<span class="dgo"><i class="ti ' + (on ? 'ti-camera' : 'ti-zzz') + '"></i></span></div>';
            }).join('');
        }).catch(() => {});
    }
    function pickDevice(code, online) {
        if (!online) { alert('الجهاز ده مش شغال دلوقتي — شغّل الوكيل عليه الأول.'); return; }
        sInp.value = code;
        startRemote();
    }
    // =================================================================
    //  قايمة الأجهزة بتتحدّث كل ١٥ ثانية، وبس لما التاب يكون ظاهر.
    //
    //  كانت كل ٥ ثواني على طول. الصفحة دي بتفضل مفتوحة على الموبايل
    //  طول اليوم وهي بتسكن، فده كان ١٧٬٢٨٠ ريكوست في اليوم من الجهاز
    //  الواحد — أكتر من وكيل المزامنة كله.
    //
    //  بعد حظر 2026-08-08 من فايرول هوستنجر، الأرقام دي بقت خطر
    //  حقيقي مش رفاهية. ١٥ ثانية وقت ظهور الشاشة أكتر من كفاية:
    //  الوكيل بيسجّل نبضة حياة كل ثانية على أي حال، والقايمة دي
    //  بتتقري بس عشان تعرف الجهاز شغال ولا لأ.
    // =================================================================
    var devTimer = null;

    function startDevPoll() {
        clearInterval(devTimer);
        devTimer = setInterval(function () {
            if (document.visibilityState === 'visible') loadDevices();
        }, 15000);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') loadDevices();
    });

    loadDevices();
    startDevPoll();

    // =================================================================
    //  إيقاظ وكيل الكمبيوتر.
    //
    //  بننده أول ما الكاميرا تفتح، وبعدها كل دقيقة طول ما هي مفتوحة.
    //  العلامة على السيرفر صلاحيتها ٣ دقايق، فحتى لو نداء اتأخر
    //  الوكيل يفضل سريع.
    //
    //  بنبعت الإيقاظ **قبل** ما نفتح الكاميرا: المستخدم بياخد ثواني
    //  عشان يوجّه الكاميرا ويركّز، والوكيل بيكون صحي خلاص قبل أول كود.
    // =================================================================
    var wakeTimer = null;

    function pingWake() {
        var s = sInp.value.trim().toUpperCase();
        if (!s) return;
        fetch('remote_scan.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'wake', csrf: CSRF, session: s })
        }).catch(() => {});
    }

    function keepAwake() {
        pingWake();
        clearInterval(wakeTimer);
        wakeTimer = setInterval(function () {
            // التاب اتخفي؟ بطّل إيقاظ — الوكيل يهدّى لوحده بعد ٣ دقايق
            if (document.visibilityState === 'visible') pingWake();
        }, 60000);
    }

    function startRemote() {
        const s = sInp.value.trim().toUpperCase();
        if (!s) { alert('اكتب كود الجهاز الأول (اللي ظاهر على شاشة الكمبيوتر).'); return; }
        sInp.value = s;
        localStorage.setItem('rs_session', s);
        history.replaceState(null, '', 'remote_scan.php?s=' + encodeURIComponent(s));
        keepAwake();                 // صحّي الوكيل قبل ما الكاميرا تفتح
        openBarcodeScanner('rsBarcode', { keepOpen: true });
    }

    function pushCode(inp) {
        const code = inp.value.trim();
        inp.value = '';
        if (!code || busy) return;
        busy = true;
        fetch('remote_scan.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'push', csrf: CSRF,
                session: sInp.value.trim().toUpperCase(),
                barcode: code
            }).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (j.ok) {
                sent++;
                document.getElementById('rsCount').textContent = sent;
                document.getElementById('rsLast').textContent = code;
                if (navigator.vibrate) navigator.vibrate(60);
            } else {
                alert(j.error || 'فشل الإرسال');
            }
        })
        .catch(() => alert('مفيش اتصال بالسيرفر.'))
        .finally(() => { busy = false; });
    }

    // لو جاي من QR بكود جاهز — نبدأ على طول بضغطة واحدة أقل
    <?php if ($session !== ''): ?>
    localStorage.setItem('rs_session', '<?= e($session) ?>');
    <?php endif; ?>
</script>
</body>
</html>
