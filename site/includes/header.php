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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($storeName) ?></title>
    <!-- PWA -->
    <link rel="manifest" href="manifest.json?v=3">
    <meta name="theme-color" content="#16233d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="الفجالة">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=3">
    <link rel="icon" href="favicon.ico?v=3" sizes="any">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
        }
    </script>
    <!-- الأيقونات: subset محلي (77 أيقونة / 15KB) بدل خط Tabler الكامل
         من jsdelivr (4595 أيقونة / 716KB كانت بتتحمّل مع كل صفحة).
         ضفت أيقونة جديدة؟ شوف الشرح في assets/tabler-subset.css -->
    <link rel="preload" href="assets/tabler-subset.woff2?v=1" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/tabler-subset.css?v=1">
    <!-- الخطوط: IBM Plex Sans Arabic للنص · IBM Plex Mono للأرقام الجدولية -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/fagala.css?v=1">
</head>
<body>
<div class="layout">
    <!-- ===== Sidebar ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="dot"><img src="icon-192.png?v=3" alt=""></span>
            <span><?= e($storeName) ?></span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($modules as $key => $mod): ?>
                <?php if (can($key)): // يظهر بس اللي ليه صلاحية ?>
                    <a href="<?= e($mod['url']) ?>"
                       class="nav-item <?= $active === $key ? 'active' : '' ?>">
                        <i class="ti <?= e($mod['icon']) ?>"></i>
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
            <div class="notif-wrap">
                <button class="notif-bell" onclick="toggleNotif(event)" title="الإشعارات">
                    <i class="ti ti-bell"></i>
                    <?php if ($notifUnread > 0): ?><span class="notif-badge"><?= $notifUnread > 9 ? '9+' : $notifUnread ?></span><?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-head">
                        <span>الإشعارات</span>
                        <?php if ($notifUnread > 0): ?>
                            <a href="notifications_read.php?back=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php') ?>" class="notif-mark">تعليم الكل كمقروء</a>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">
                        <?php if (empty($notifItems)): ?>
                            <div class="notif-empty">مفيش إشعارات</div>
                        <?php else: foreach ($notifItems as $n): ?>
                            <a href="<?= e($n['link'] ?: '#') ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                                <div class="ni-title"><?= e($n['title']) ?></div>
                                <?php if ($n['body']): ?><div class="ni-body"><?= e($n['body']) ?></div><?php endif; ?>
                                <div class="ni-time"><?= e(date('Y-m-d H:i', strtotime($n['created_at']))) ?></div>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
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

<div id="shrNag" style="<?= $shortagePending > 0 ? '' : 'display:none;' ?>margin-bottom:16px;">
    <a href="tasks.php" style="display:flex;align-items:center;gap:12px;padding:14px 18px;
        background:var(--warn-soft,#fff7e6);border:1.5px solid var(--warn,#d97706);border-radius:12px;
        text-decoration:none;color:var(--warn,#d97706);font-size:14px;font-weight:600;">
        <i class="ti ti-shopping-cart" style="font-size:22px;animation:shrPulse 1.2s ease-in-out infinite;"></i>
        <span style="flex:1;">🛒 عندك <span id="shrNagCount"><?= $shortagePending ?></span> طلب نواقص مستنيك —
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
                if (n > 0) {
                    // زنّة: اهتزاز على الموبايل + نبض الجرس
                    if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
                    var bell = document.querySelector('.notif-bell i');
                    if (bell) {
                        bell.style.animation = 'shrPulse 0.5s ease-in-out 4';
                        setTimeout(function () { bell.style.animation = ''; }, 2200);
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
