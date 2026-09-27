<?php
/**
 * includes/header.php — رأس الصفحة المشترك لكل المديولات
 *
 * الاستخدام في أي صفحة مديول:
 *   require_once __DIR__ . '/auth.php';
 *   require_permission('cashier');         // صلاحية المديول
 *   $page_title = 'تقفيل الكاشير';
 *   $active_module = 'cashier';            // لتمييزه في الـ Sidebar
 *   require_once __DIR__ . '/includes/header.php';
 *   ... محتوى الصفحة ...
 *   require_once __DIR__ . '/includes/footer.php';
 */

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

$u           = current_user();
$storeName   = setting('store_name', 'مكتبات الفجالة ستور');
$pageTitle   = $page_title   ?? 'النظام المالي';
$active      = $active_module ?? '';
$modules     = all_modules();
$vapidPublicKey = (string) setting('vapid_public_key', '');
require_once __DIR__ . '/nav_icons.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <script>
        // ===== سجل أخطاء الجافاسكربت =====
        // 🔴 لازم يفضل **أول سكربت في الصفحة**: الـSyntaxError في أي بلوك
        //    <script> بعده بيتمسك بس لو المستمع ده اتسجّل قبله. (صفحة
        //    المراجعة فضلت واقفة أيام بسبب غلطة زي دي ومحدش عرف.)
        // 🔴 RULES §٢: حد أقصى ٣ تقارير في الصفحة، ومن غير أي إعادة محاولة.
        //    والسيرفر عليه حد تاني (٢٠ كل ١٠ دقايق للجلسة).
        // inline عن قصد مش في assets/ — header.php مابيتكاشش، فمفيش ?v=
        //    ولا VERSION في sw.js محتاجين يتغيّروا.
        (function () {
            var sent = 0, seen = {};
            function report(m, s, l, c, k) {
                try {
                    m = String(m || '');
                    if (!m || m === 'Script error.' || sent >= 3 || !navigator.sendBeacon) return;
                    s = String(s || '');
                    if (s && s.indexOf(location.origin) !== 0) return;   // إضافات المتصفح
                    var key = m + '|' + s + '|' + l;
                    if (seen[key]) return;
                    seen[key] = 1; sent++;
                    var f = new FormData();
                    f.append('m', m.slice(0, 500));
                    f.append('s', s.slice(0, 300));
                    f.append('l', String(l || 0));
                    f.append('c', String(c || 0));
                    f.append('k', String(k || '').slice(0, 1500));
                    f.append('p', (location.pathname + location.search).slice(0, 300));
                    navigator.sendBeacon('errors.php?report=js', f);
                } catch (x) {}
            }
            window.addEventListener('error', function (e) {
                if (e.target && e.target !== window) return;          // صورة أو ملف مش لاقيه — مش غلطة كود
                report(e.message, e.filename, e.lineno, e.colno, e.error && e.error.stack);
            }, true);
            window.addEventListener('unhandledrejection', function (e) {
                var r = e.reason;
                report('Unhandled promise: ' + ((r && r.message) || r), location.href, 0, 0, r && r.stack);
            });
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($storeName) ?></title>
    <!-- PWA -->
    <link rel="manifest" href="manifest.json?v=4">
    <meta name="theme-color" content="#4d1620">
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

        // تبديل الوضع الليلي. بيتخزّن في localStorage فبيفضل مع اليوزر
        // على الجهاز ده — مش في القاعدة، عشان مايبقاش استعلام في كل صفحة.
        function toggleTheme() {
            var r = document.documentElement;
            var t = r.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            r.setAttribute('data-theme', t);
            try { localStorage.setItem('fagala-theme', t); } catch (e) {}
            var m = document.querySelector('meta[name="theme-color"]');
            if (m) m.setAttribute('content', t === 'dark' ? '#2c0c12' : '#4d1620');
        }
    </script>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="إشراقة">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=4">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=4">
    <link rel="icon" href="favicon.ico?v=4" sizes="any">
    <script>
        // 🔴 الـ?v= مش زيادة — كاش الاستضافة (LiteSpeed) كان بيمسك `sw.js`
        // من غير كويري ويرجّع نسخة قديمة، فالمتصفح يفضل يسجّل service worker
        // قديمة مفيهاش معالج push مهما تعمل Unregister. والإشعارات تتبعت،
        // FCM تقبلها وترد ٢٠١، ومحدش يعرضها — من غير أي خطأ في أي مكان.
        // 🔴 لما تزوّد VERSION في sw.js، زوّد الرقم ده معاه هنا وفي login.php.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('sw.js?v=49').catch(() => {}));
        }
    </script>
    <!-- الأيقونات: subset محلي (77 أيقونة / 15KB) بدل خط Tabler الكامل
         من jsdelivr (4595 أيقونة / 716KB كانت بتتحمّل مع كل صفحة).
         ضفت أيقونة جديدة؟ شوف الشرح في assets/tabler-subset.css -->
    <link rel="preload" href="assets/tabler-subset.woff2?v=2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/tabler-subset.css?v=2">
    <!-- الخطوط: IBM Plex Sans Arabic للنص · IBM Plex Mono للأرقام الجدولية -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/fagala.css?v=19">
    <link rel="stylesheet" href="assets/ashraqa-theme.css?v=3">
</head>
<body>
<script>
/* 🔴 `toggleSidebar` و`toggleNotif` **لازم يتعرّفوا هنا، مش في الفوتر**.
       زراير القايمة الجانبية والجرس في الترويسة دي نفسها. لما التعريف
       كان في `includes/footer.php` (آخر الصفحة)، أي حد يدوس على القايمة
       قبل ما الصفحة تخلص تحميل كان بياخد
       «Uncaught ReferenceError: toggleSidebar is not defined»
       والزرار مابيفتحش خالص. اتسجّلت ٩ مرات في سجل أخطاء السيرفر على
       ٤ صفحات (الفواتير · الجرد · الحضور · حجز الكتب) — كلها صفحات
       تقيلة بتاخد وقت لحد ما الفوتر يوصل.
       التعريف هنا لأنه **قبل** أول زرار بينده عليه. */
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('show');
    }
    function toggleNotif(e) {
        e.stopPropagation();
        const p = document.getElementById('notifPanel');
        if (p) p.classList.toggle('open');
    }
</script>
<?php /* 🔴 شريط الأرشيف — بيبان على النسخة المحلية **بس**.
         السبب: بعد التصفير، النسخة المحلية بتفضل بالبيانات القديمة
         كأرشيف. والتابين شكلهم واحد، فسهل جداً تفتكر إنك على الموقع
         الحقيقي وانت بتتفرج على داتا مأرشفة — أو العكس وهو الأخطر.
         `APP_ENV` معرّفة في `config.php` وقيمتها 'hosting' على السيرفر،
         فالشريط ده عمره ما هيبان لأي مستخدم. */ ?>
<?php if (defined('APP_ENV') && APP_ENV === 'local'): ?>
    <div style="background:#946105;color:#fff;text-align:center;padding:5px 10px;
                font-size:13px;font-weight:600;letter-spacing:.2px;">
        نسخة محلية — أرشيف. أي تعديل هنا مابيوصلش للموقع الحقيقي.
    </div>
<?php endif; ?>
<div class="layout">
    <!-- ===== Sidebar ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="dot"><img src="icon-192.png?v=4" alt=""></span>
            <span><?= e($storeName) ?></span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($modules as $key => $mod): ?>
                <?php if (can_module($key)): // يظهر بس اللي ليه صلاحية ?>
                    <a href="<?= e($mod['url']) ?>"
                       class="nav-item <?= $active === $key ? 'active' : '' ?>">
                        <?= nav_icon($mod['icon']) ?>
                        <span><?= e($mod['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-box">
                <div class="user-avatar"><?= e(mb_substr($u['full_name'], 0, 1)) ?></div>
                <div class="user-info">
                    <div class="name"><?= e($u['full_name']) ?></div>
                    <div class="role"><?= is_owner() ? 'أونر' : e($u['username']) ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="ti ti-logout"></i> تسجيل الخروج
            </a>
        </div>
    </aside>

    <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

    <!-- ===== المحتوى ===== -->
    <div class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
            <h2><?= e($pageTitle) ?></h2>
            <?php
                // الإشعارات (الجرس) — استعلام مستقل محمي لو الجداول لسه مش موجودة
                $notifItems = []; $notifUnread = 0;
                try {
                    $nuid = (int) $u['id'];
                    $nc = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
                    $nc->execute([$nuid]);
                    $notifUnread = (int) $nc->fetchColumn();
                    $nl = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 15');
                    $nl->execute([$nuid]);
                    $notifItems = $nl->fetchAll();
                } catch (Throwable $e) { /* الجداول لسه مش موجودة */ }
            ?>
            <button class="theme-toggle" onclick="toggleTheme()"
                    title="الوضع الليلي" aria-label="تبديل الوضع الليلي">
                <span class="tt-track"><span class="tt-knob">
                    <svg class="tt-sm" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <mask id="tt-sm-mask">
                            <rect width="24" height="24" fill="#fff"/>
                            <circle class="tt-sm-bite" cx="17" cy="7" r="6" fill="#000"/>
                        </mask>
                        <circle class="tt-sm-core" cx="12" cy="12" r="8" fill="currentColor" mask="url(#tt-sm-mask)"/>
                        <g class="tt-sm-rays" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <line x1="12" y1="1.5" x2="12" y2="3.5"/>  <line x1="12" y1="20.5" x2="12" y2="22.5"/>
                            <line x1="1.5" y1="12" x2="3.5" y2="12"/>  <line x1="20.5" y1="12" x2="22.5" y2="12"/>
                            <line x1="4.6" y1="4.6" x2="6" y2="6"/>    <line x1="18" y1="18" x2="19.4" y2="19.4"/>
                            <line x1="4.6" y1="19.4" x2="6" y2="18"/>  <line x1="18" y1="6" x2="19.4" y2="4.6"/>
                        </g>
                    </svg>
                </span></span>
            </button>
            <div class="notif-wrap">
                <button class="notif-bell" onclick="toggleNotif(event)" title="الإشعارات">
                    <i class="ti ti-bell"></i>
                    <?php if ($notifUnread > 0): ?><span class="notif-badge"><?= $notifUnread > 9 ? '9+' : $notifUnread ?></span><?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-head">
                        <span>الإشعارات</span>
                        <div class="notif-actions">
                            <?php if ($notifUnread > 0): ?>
                                <a href="notifications_read.php?back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php') ?>" class="notif-mark">تعليم الكل كمقروء</a>
                            <?php endif; ?>
                            <?php if ($vapidPublicKey !== ''): ?>
                                <button id="pushPromptBtn" class="notif-push-btn" style="display:none;" onclick="enablePushNotifications()">
                                    <i class="ti ti-bell"></i> فعّل إشعارات الموبايل
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($vapidPublicKey !== ''): ?>
                        <div id="iosPushTip" class="notif-ios-tip" style="display:none;">
                            عشان الإشعارات توصلك على الآيفون، افتح قايمة المشاركة تحت واختار (إضافة إلى الشاشة الرئيسية)، وبعدين افتح الموقع من الأيقونة وفعّل الإشعارات من هنا.
                        </div>
                    <?php endif; ?>
                    <div class="notif-list">
                        <?php if (empty($notifItems)): ?>
                            <div class="notif-empty">مفيش إشعارات</div>
                        <?php else: foreach ($notifItems as $n):
                            // الجرس بيبعت رقم الإشعار — أدق من المطابقة باللينك،
                            // وبيشتغل كمان مع الإشعارات اللي ملهاش لينك.
                            $notifHref = $n['is_read']
                                ? ($n['link'] ?: '#')
                                : 'notifications_read.php?id=' . (int) $n['id'];
                        ?>
                            <a href="<?= e($notifHref) ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                                <div class="ni-title"><?= e($n['title']) ?></div>
                                <?php if ($n['body']): ?><div class="ni-body"><?= e($n['body']) ?></div><?php endif; ?>
                                <div class="ni-time"><?= e(date('Y-m-d H:i', strtotime($n['created_at']))) ?></div>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            <style>
                .notif-actions { display: flex; align-items: center; gap: 8px; }
                .notif-push-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    background: var(--accent-soft);
                    color: var(--accent);
                    border: 1px solid var(--accent);
                    border-radius: var(--r-sm, 6px);
                    padding: 3px 8px;
                    font-size: 11px;
                    font-weight: 600;
                    cursor: pointer;
                    text-decoration: none;
                    font-family: inherit;
                }
                .notif-push-btn:hover {
                    background: var(--line);
                    color: var(--accent);
                }
                .notif-push-btn:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
                .notif-ios-tip {
                    padding: 10px 14px;
                    font-size: 12px;
                    color: var(--warn);
                    background: var(--surface);
                    border-bottom: 1px solid var(--line);
                    line-height: 1.5;
                }
            </style>
            <?php if ($vapidPublicKey !== ''): ?>
            <script>
            window.__VAPID_PUBKEY__ = <?= json_encode($vapidPublicKey) ?>;
            function urlBase64ToUint8Array(base64String) {
                var padding = '='.repeat((4 - base64String.length % 4) % 4);
                var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                var rawData = window.atob(base64);
                var outputArray = new Uint8Array(rawData.length);
                for (var i = 0; i < rawData.length; ++i) {
                    outputArray[i] = rawData.charCodeAt(i);
                }
                return outputArray;
            }
            function enablePushNotifications() {
                var btn = document.getElementById('pushPromptBtn');
                if (btn) btn.disabled = true;
                if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
                    return;
                }
                Notification.requestPermission().then(function(permission) {
                    if (permission !== 'granted') {
                        if (btn) { btn.disabled = false; btn.style.display = 'none'; }
                        return;
                    }
                    return navigator.serviceWorker.ready.then(function(reg) {
                        var key = window.__VAPID_PUBKEY__;
                        if (!key) return;
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(key)
                        });
                    }).then(function(subscription) {
                        if (!subscription) return;
                        var subJson = subscription.toJSON();
                        return fetch('push_subscribe.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'subscribe',
                                endpoint: subscription.endpoint,
                                keys: {
                                    p256dh: subJson.keys?.p256dh || '',
                                    auth: subJson.keys?.auth || ''
                                }
                            })
                        }).then(function(res) { return res.json(); })
                          .then(function(data) {
                              if (data && data.ok) {
                                  if (btn) btn.style.display = 'none';
                              } else {
                                  if (btn) btn.disabled = false;
                              }
                          });
                    });
                }).catch(function(err) {
                    console.error(err);
                    if (btn) btn.disabled = false;
                });
            }
            (function() {
                var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

                if (!('PushManager' in window)) {
                    if (isIos && !isStandalone) {
                        var tip = document.getElementById('iosPushTip');
                        if (tip) tip.style.display = 'block';
                    }
                    return;
                }

                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.ready.then(function(reg) {
                        return reg.pushManager.getSubscription();
                    }).then(function(sub) {
                        if (!sub && Notification.permission !== 'denied') {
                            var btn = document.getElementById('pushPromptBtn');
                            if (btn) btn.style.display = 'inline-flex';
                        }
                    }).catch(function() {});
                }
            })();
            </script>
            <?php endif; ?>
        </div>
        <div class="content">
<?php
// ===== زنّان النواقص =====
// لو المستخدم عنده طلب نواقص مفتوح مستنيه: بانر ثابت فوق كل صفحة
// + فحص دوري كل دقيقتين بيهزّ الموبايل ويرجّع يفكّره — لحد ما يقفلها.
$shortagePending = 0;
try {
    if (function_exists('shortage_pending_count')) {
        $shortagePending = shortage_pending_count((int) $u['id']);
    } else {
        $sp = db()->prepare(
            "SELECT COUNT(*) FROM task_targets tt JOIN tasks t ON t.id = tt.task_id
             WHERE tt.user_id = ? AND tt.status = 'pending'
               AND t.status = 'open' AND t.ref_type = 'shortage'"
        );
        $sp->execute([(int) $u['id']]);
        $shortagePending = (int) $sp->fetchColumn();
    }
} catch (Throwable $e) { /* الجداول لسه مش موجودة */ }
?>
<?php
// ===== بانر حالة وكلاء المزامنة (للأونر والمراقب المحدد) =====
// بيظهر مخفي ويتملّى من nag_check.php كل دورة — نفس آلية بانر النواقص.
$agtShow = false;
try {
    require_once __DIR__ . '/../agent_lib.php';
    $agtUser = current_user();
    $agtShow = $agtUser && agent_is_watcher($agtUser);
} catch (Throwable $e) { /* الجداول لسه مش موجودة */ }
?>
<?php if ($agtShow): ?>
<div id="agtNag" style="display:none;margin-bottom:16px;">
    <a href="settings.php#agents" id="agtNagBox" style="display:flex;align-items:center;gap:12px;
        padding:14px 18px;border-radius:12px;text-decoration:none;font-size:14px;font-weight:600;
        border:1.5px solid var(--warn,#d97706);background:var(--warn-soft,#fff7e6);color:var(--warn,#d97706);">
        <i class="ti ti-alert-triangle" id="agtNagIcon"
           style="font-size:22px;animation:shrPulse 1.2s ease-in-out infinite;"></i>
        <span style="flex:1;" id="agtNagText"></span>
        <i class="ti ti-chevron-left"></i>
    </a>
</div>
<?php endif; ?>

<?php if ($vapidPublicKey !== ''): ?>
<!-- ===== دعوة تفعيل إشعارات الموبايل =====
     🔴 المتصفحات بترفض Notification.requestPermission() لو مانداهاش ضغطة من
        المستخدم. عشان كده الشريط ده بيظهر لوحده، لكن نافذة الإذن نفسها
        مابتفتحش غير لما يدوس «فعّل دلوقتي» — ده نفس اللي واتساب ويب بيعمله.
     بيظهر بس لو: الجهاز بيدعم البوش · الإذن لسه مترفضش · مفيش اشتراك ·
     وماقفلهوش من أقل من ٣ أيام. -->
<style>
    .push-nag-btn { background:var(--accent); color:#fff; border:none; padding:8px 16px;
        border-radius:8px; font-size:13px; font-weight:600; font-family:inherit; cursor:pointer;
        white-space:nowrap; }
    .push-nag-btn:hover { background:var(--accent-hover); color:#fff; }
    .push-nag-x { background:none; border:none; color:var(--accent); cursor:pointer;
        font-size:18px; line-height:1; padding:4px; font-family:inherit; }
    .push-nag-x:hover { color:var(--accent-hover); }
</style>
<div id="pushNag" style="display:none;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;
        background:var(--accent-soft);border:1.5px solid var(--accent);border-radius:12px;
        color:var(--accent);font-size:14px;font-weight:600;">
        <i class="ti ti-bell" style="font-size:22px;animation:shrPulse 1.2s ease-in-out infinite;"></i>
        <span style="flex:1;" id="pushNagText">فعّل إشعارات الموبايل عشان أول ما حاجة تخصّك تنزل توصلك على طول — من غير ما تفضل تفتح الموقع تشوف.</span>
        <button type="button" class="push-nag-btn" id="pushNagBtn">فعّل دلوقتي</button>
        <button type="button" class="push-nag-x" id="pushNagClose" title="مش دلوقتي"><i class="ti ti-x"></i></button>
    </div>
</div>
<script>
(function () {
    var nag = document.getElementById('pushNag');
    if (!nag) return;
    var txt = document.getElementById('pushNagText');
    var btn = document.getElementById('pushNagBtn');
    var KEY = 'fgl_push_nag_until';
    var now = Date.now();

    // اتقفل من قريّب؟ مانزنّش تاني دلوقتي
    try { if (+localStorage.getItem(KEY) > now) return; } catch (e) {}

    document.getElementById('pushNagClose').addEventListener('click', function () {
        nag.style.display = 'none';
        // بيرجع تاني بعد ٣ أيام — دي دعوة مش إنذار
        try { localStorage.setItem(KEY, String(now + 3 * 24 * 60 * 60 * 1000)); } catch (e) {}
    });

    var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent)
             || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;

    // الآيفون في سفاري العادي: PushManager مش موجود خالص، والحل مش زرار
    if (!('PushManager' in window) || !('serviceWorker' in navigator)) {
        if (isIos && !isStandalone) {
            txt.textContent = 'عشان الإشعارات توصلك على الآيفون: افتح قايمة المشاركة تحت، '
                            + 'اختار «إضافة إلى الشاشة الرئيسية»، وبعدين افتح الموقع من الأيقونة.';
            btn.style.display = 'none';
            nag.style.display = 'block';
        }
        return;
    }

    // رفض قبل كده؟ مانزنّش — لازم يفتحها من إعدادات المتصفح بنفسه
    if (Notification.permission === 'denied') return;

    navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription();
    }).then(function (sub) {
        if (!sub) nag.style.display = 'block';
    }).catch(function () {});

    btn.addEventListener('click', function () {
        nag.style.display = 'none';
        if (typeof enablePushNotifications === 'function') enablePushNotifications();
    });
})();
</script>
<?php endif; ?>

<div id="shrNag" style="<?= $shortagePending > 0 ? '' : 'display:none;' ?>margin-bottom:16px;">
    <a href="tasks.php" style="display:flex;align-items:center;gap:12px;padding:14px 18px;
        background:var(--warn-soft,#fff7e6);border:1.5px solid var(--warn,#d97706);border-radius:12px;
        text-decoration:none;color:var(--warn,#d97706);font-size:14px;font-weight:600;">
        <i class="ti ti-shopping-cart" style="font-size:22px;animation:shrPulse 1.2s ease-in-out infinite;"></i>
        <span style="flex:1;"><i class="ti ti-shopping-cart"></i> عندك <span id="shrNagCount"><?= $shortagePending ?></span> طلب نواقص مستنيك —
            جهّز الطلب وارفع إثبات الفاتورة وعلّم المهمة تمت.</span>
        <i class="ti ti-chevron-left"></i>
    </a>
</div>
<style>@keyframes shrPulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.18);} }</style>
<script>
(function () {
    var last = <?= $shortagePending ?>;
    function check() {
        fetch('nag_check.php', {cache: 'no-store'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                fails = 0;                      // رد سليم — ارجع للمعدل العادي
                if (!j || !j.ok) return;
                var n = +j.shortage || 0;
                var nag = document.getElementById('shrNag');
                var cnt = document.getElementById('shrNagCount');
                if (cnt) cnt.textContent = n;
                if (nag) nag.style.display = n > 0 ? '' : 'none';

                // ---- حالة وكلاء المزامنة ----
                var ag = document.getElementById('agtNag');
                if (ag) {
                    var lvl = j.agent_level || 'ok';
                    if (lvl === 'ok' || !j.agent_text) {
                        ag.style.display = 'none';
                    } else {
                        var red = (lvl === 'alert');
                        var box = document.getElementById('agtNagBox');
                        var ico = document.getElementById('agtNagIcon');
                        var col = red ? 'var(--neg,#9d3b38)' : 'var(--warn,#d97706)';
                        var bg  = red ? 'var(--neg-soft,#fbeceb)' : 'var(--warn-soft,#fff7e6)';
                        if (box) {
                            box.style.borderColor = col;
                            box.style.background  = bg;
                            box.style.color       = col;
                        }
                        if (ico) ico.style.animation = red
                            ? 'shrPulse 0.9s ease-in-out infinite' : 'shrPulse 1.6s ease-in-out infinite';
                        document.getElementById('agtNagText').textContent = j.agent_text;
                        ag.style.display = '';
                    }
                }
                // زنّة: اهتزاز على الموبايل + نبض أيقونة النواقص.
                //
                // كانت `n > 0` — يعني ما دام فيه ولو طلب نواقص واحد معلّق،
                // الموبايل بيهتز **كل دورة استطلاع** مش لما حاجة جديدة تيجي.
                // و`last` كانت بتتحفظ تحت ومحدش بيستعملها.
                // والنبض كان على `.notif-bell` (جرس الإشعارات) والعدّاد ده
                // بتاع النواقص — فكان بيبان كأنه إشعار وصل وهو مالوش علاقة.
                if (n > last) {
                    if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
                    var shrIcon = document.querySelector('#shrNag > a > i');
                    if (shrIcon) {
                        shrIcon.style.animation = 'shrPulse 0.5s ease-in-out 4';
                        setTimeout(function () { shrIcon.style.animation = 'shrPulse 1.2s ease-in-out infinite'; }, 2200);
                    }
                }
                last = n;
            })
            .catch(function () { fails++; });
    }

    // =================================================================
    //  السؤال بيقف لما التاب مايكونش ظاهر.
    //
    //  قبل كده كان بيسأل كل دقيقتين طول ما التاب مفتوح، حتى لو الشاشة
    //  مقفولة أو الموبايل في الجيب. الواحد اللي سايب النظام مفتوح
    //  ٨ ساعات كان بيعمل ٢٤٠ ريكوست من غير ما حد يبص على الشاشة،
    //  واضربها في عدد المستخدمين والتابات المفتوحة.
    //
    //  ده مهم بعد حظر 2026-08-08: فايرول هوستنجر بيحسب الريكوستات
    //  على مستوى الـIP، فكل أجهزة الفرع الواحد بتتجمع في رقم واحد.
    //
    //  التاب المخفي = صفر ريكوست. أول ما يرجع ظاهر بيسأل فورًا،
    //  فالمستخدم مايحسّش بأي تأخير.
    //
    //  وكمان: لو السيرفر مش راضي يرد، بنباعد بين المحاولات بدل ما
    //  نفضل نخبط عليه كل دقيقتين ونطوّل أي حظر.
    // =================================================================
    var fails = 0;
    var BASE  = 120000;   // دقيقتين
    var timer = null;

    function delay() {
        if (fails === 0) return BASE;
        // تراجع تدريجي لحد ٣٠ دقيقة
        return Math.min(BASE * Math.pow(2, fails), 1800000);
    }

    function tick() {
        if (document.visibilityState === 'visible') check();
        schedule();
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(tick, delay());
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            fails = 0;      // رجع للشاشة — ابدأ من الأول
            check();
            schedule();
        } else {
            clearTimeout(timer);
        }
    });

    schedule();
})();
</script>
