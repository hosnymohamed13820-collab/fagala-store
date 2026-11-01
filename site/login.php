<?php
/**
 * login.php — صفحة تسجيل الدخول
 * نظام الفجالة ستور المالي
 */
require_once __DIR__ . '/auth.php';

// لو داخل بالفعل، حوّله للرئيسية
if (is_logged_in()) {
    header('Location: ' . first_allowed_module());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'من فضلك أدخل اسم المستخدم والباسورد.';
        } elseif (($lockLeft = login_lock_minutes($username, $_SERVER['REMOTE_ADDR'] ?? null)) > 0) {
            // حماية ضد تخمين الباسورد: 5 محاولات فاشلة = قفل 5 دقايق
            $error = 'محاولات كتير غلط. استنى ' . $lockLeft . ' دقيقة وحاول تاني.';
        } elseif (attempt_login($username, $password)) {
            header('Location: ' . first_allowed_module());
            exit;
        } else {
            $error = 'اسم المستخدم أو الباسورد غير صحيح.';
        }
    }
}

$storeName = setting('store_name', 'مكتبات الفجالة ستور');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول — <?= e($storeName) ?></title>
    <!-- PWA -->
    <link rel="manifest" href="manifest.json?v=4">
    <meta name="theme-color" content="#16233d">
    <script>
        // 🔴 لازم يفضل هنا — فوق كل الاستايلات وتحت ميتا theme-color.
        // لو نزل تحت، الصفحة هتومض أبيض قبل ما تسود.
        // نسخة طبق الأصل من includes/header.php — لو غيّرت واحد غيّر التاني.
        (function () {
            var t;
            try { t = localStorage.getItem('fagala-theme'); } catch (e) {}
            if (t !== 'dark' && t !== 'light') {
                t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)
                    ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
            var m = document.querySelector('meta[name="theme-color"]');
            if (m) m.setAttribute('content', t === 'dark' ? '#0a111c' : '#16233d');
        })();
    </script>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="الفجالة">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=4">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=4">
    <link rel="icon" href="favicon.ico?v=4" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/login_fx.css?v=10">
    <link rel="stylesheet" href="assets/tabler-subset.css?v=2">
    <link rel="stylesheet" href="assets/fagala.css?v=38">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', Tahoma, Arial, sans-serif;
            background: var(--sidebar);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
            position: relative;
        }
        /* طبقة 1: توهجات لونية ناعمة */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background:
                radial-gradient(48% 42% at 22% 18%, rgba(30,111,191,.45) 0%, transparent 62%),
                radial-gradient(42% 38% at 82% 78%, rgba(214,175,106,.16) 0%, transparent 60%),
                radial-gradient(60% 55% at 60% 8%,  rgba(60,110,160,.28) 0%, transparent 65%);
            pointer-events: none;
        }
        /* طبقة 2: شبكة هندسية خفيفة (نمط معماري عربي مبسّط) */
        body::after {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
            background-size: 54px 54px, 54px 54px;
            mask-image: radial-gradient(62% 52% at 50% 42%, var(--sidebar) 0%, transparent 100%);
            -webkit-mask-image: radial-gradient(62% 52% at 50% 42%, var(--sidebar) 0%, transparent 100%);
            pointer-events: none;
        }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
        #loginCard {
            position: relative;
            z-index: 2;
            margin: auto;
            width: 100%;
            max-width: 385px;
        }
        .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand .logo {
            width: 58px;
            height: 58px;
            box-shadow: 0 8px 20px rgba(22,35,61,.28);
            background: transparent;
            border-radius: 15px;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin-bottom: 14px;
        }
        .brand .logo img { display:block; width:100%; height:100%; object-fit:cover; }
        .brand h1 {
            font-size: 20px;
            letter-spacing: -.02em;
            color: var(--ink-900);
            font-weight: 600;
        }
        .brand p {
            font-size: 13px;
            color: var(--ink-500);
            margin-top: 4px;
        }
        .login-submit {
            width: 100%;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <div class="lfx" aria-hidden="true"></div>
    <div class="fcard" id="loginCard">
        <div class="brand">
            <div class="logo"><img src="icon-192.png?v=4" alt="" width="58" height="58"></div>
            <h1><?= e($storeName) ?></h1>
            <p>النظام المالي المتكامل</p>
        </div>

        <?php if ($error): ?>
            <div class="falert falert-neg"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['pwreset_success'])): unset($_SESSION['pwreset_success']); ?>
            <div class="falert falert-pos">اتغيّر، ادخل بالباسورد الجديد</div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="ffield field">
                <label for="username">اسم المستخدم</label>
                <input type="text" class="finput" id="username" name="username" required autofocus autocomplete="username"
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="ffield field">
                <label for="password">الباسورد</label>
                <input type="password" class="finput" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary login-submit">دخول</button>
            <a class="btn btn-ghost" href="password_reset.php">نسيت الباسورد؟</a>
        </form>

        <!-- زر تثبيت التطبيق (يظهر لما المتصفح يسمح) -->
        <button id="installBtn" type="button" class="btn btn-outline"
                style="display:none;width:100%;margin-top:14px;">
            <i class="ti ti-device-mobile"></i> نزّل التطبيق على جهازك
        </button>
        <p id="iosHint" style="display:none;margin-top:12px;font-size:12px;color:var(--ink-500);text-align:center;line-height:1.7;">
            لتثبيت التطبيق على الآيفون: افتح من Safari، اضغط زر المشاركة، ثم "إضافة إلى الشاشة الرئيسية".
        </p>
    </div>

    <script>
        // تسجيل الـ Service Worker (لازم للـ PWA)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // الرقم ده لازم يبقى نفس اللي في includes/header.php وفي VERSION
                // بتاعة sw.js — شوف التعليق هناك، الكاش كان بيرجّع نسخة قديمة.
                navigator.serviceWorker.register('sw.js?v=80').catch(() => {});
            });
        }

        // زر التثبيت (Android / Chrome / Edge)
        let deferredPrompt = null;
        const installBtn = document.getElementById('installBtn');
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            installBtn.style.display = 'block';
        });
        installBtn.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            installBtn.style.display = 'none';
        });
        window.addEventListener('appinstalled', () => {
            installBtn.style.display = 'none';
        });

        // لو المستخدم على iOS (Safari) — نعرض تعليمات التثبيت اليدوي
        (function(){
            const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
            const isStandalone = window.navigator.standalone === true ||
                                 window.matchMedia('(display-mode: standalone)').matches;
            if (isIOS && !isStandalone) {
                document.getElementById('iosHint').style.display = 'block';
            }
        })();
    </script>
    <script src="assets/login_fx.js?v=11" defer></script>
</body>
</html>
