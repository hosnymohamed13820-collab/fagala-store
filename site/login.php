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
            // حماية ضد تخمين الباسورد: 5 محاولات فاشلة = قفل 15 دقيقة
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --ink-900: #16233d; --ink-800: #22334f; --ink-700: #3b4e6d;
            --ink-500: #6d7f9a; --ink-400: #94a3b8; --ink-300: #c3ccd8;
            --accent:       #1e6fbf;
            --accent-hover: #185a9c;
            --accent-soft:  #e8f1fb;
            --accent-ring:  rgba(30,111,191,.20);
            --accent-text: #1e6fbf;
            --bg:        #f5f6f8;
            --surface:   #ffffff;
            --line:      #e3e7ed;
            --line-soft: #eff2f6;
            --neg:       #c0332e;
            --neg-soft:  #fdeceb;

            /* خلفية الكارت وحافته — متغيّرين عشان يقلبوا في الوضع الليلي.
               الشفافية مقصودة: الكارت عايم فوق التوهجات اللي في body::before. */
            --card-bg:   rgba(255,255,255,.97);
            --card-line: rgba(255,255,255,.55);

            color-scheme: light;

            --r-sm: 8px; --r: 10px; --r-lg: 14px;
        }

        /* الوضع الليلي — القيم دي **منسوخة من assets/fagala.css**.
           الصفحة دي مابتقراش الملف ده فمحتاجة نسختها الخاصة.
           🔴 لو غيّرت لون في fagala.css، غيّره هنا معاه أو الصفحتين هيتفرقوا.
           كل القيم اتفحص تباينها ومعدّية AA. */
        :root[data-theme="dark"] {
            --ink-900: #eef2f8;
            --ink-800: #dbe3ee;
            --ink-700: #bcc8d8;
            --ink-500: #8494ac;
            --ink-400: #6b7b95;
            --ink-300: #4a5a75;

            --bg:        #0f1826;
            --surface:   #16202f;
            --line:      #263243;
            --line-soft: #1b2635;

            --accent-soft: #14263c;
            --accent-ring: rgba(122,184,240,.22);
            --accent-text: #7ab8f0;

            --neg:      #ff8a80;
            --neg-soft: #3a1a18;

            /* الكارت بيبقى غامق وحافته خط فاتح خفيف بدل الأبيض */
            --card-bg:   rgba(22,32,47,.97);
            --card-line: rgba(255,255,255,.09);

            color-scheme: dark;
        }
        /* إيقاف الأصفر بتاع autofill */
        input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--ink-800) !important;
            -webkit-box-shadow: 0 0 0 1000px var(--surface) inset !important;
            box-shadow: 0 0 0 1000px var(--surface) inset !important;
            transition: background-color 9999s ease-out 0s;
        }
        body {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #101a2e;
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
            mask-image: radial-gradient(62% 52% at 50% 42%, #000 0%, transparent 100%);
            -webkit-mask-image: radial-gradient(62% 52% at 50% 42%, #000 0%, transparent 100%);
            pointer-events: none;
        }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
        .login-card {
            position: relative;
            z-index: 2;
            margin: auto;
            background: var(--card-bg);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid var(--card-line);
            border-radius: 18px;
            box-shadow: 0 28px 70px rgba(0,0,0,.42), 0 2px 0 rgba(255,255,255,.5) inset;
            width: 100%;
            max-width: 385px;
            padding: 36px 30px 30px;
        }
        /* شريط ذهبي رفيع أعلى الكارت */
        .login-card::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0;
            height: 3px; border-radius: 18px 18px 0 0;
            background: linear-gradient(90deg,
                transparent 0%, rgba(214,175,106,.55) 22%,
                #e2c185 50%, rgba(214,175,106,.55) 78%, transparent 100%);
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
            color: #fff;
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
        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 14px;
            color: var(--ink-700);
            margin-bottom: 7px;
            font-weight: 500;
        }
        .field input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
            background: var(--surface);
            color: var(--ink-800);
        }
        .field input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-ring);
        }
        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(180deg, #3d8ad4 0%, var(--accent) 100%);
            color: #fff;
            border: none;
            box-shadow: 0 6px 16px rgba(30,111,191,.30);
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 6px;
        }
        .btn-login:hover { background: var(--accent-hover); }
        .btn-login:active { transform: translateY(1px); }
        .error {
            background: var(--neg-soft);
            color: var(--neg);
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 18px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <div class="logo"><img src="icon-192.png?v=4" alt="" width="58" height="58"></div>
            <h1><?= e($storeName) ?></h1>
            <p>النظام المالي المتكامل</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label for="username">اسم المستخدم</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username"
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">الباسورد</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login">دخول</button>
        </form>

        <!-- زر تثبيت التطبيق (يظهر لما المتصفح يسمح) -->
        <button id="installBtn" type="button"
                style="display:none;width:100%;margin-top:14px;padding:12px;background:var(--surface);color:var(--accent-text);
                       border:1.5px solid var(--accent);border-radius:10px;font-family:inherit;font-size:15px;
                       font-weight:600;cursor:pointer;">
            <i class="ti ti-device-mobile"></i> نزّل التطبيق على جهازك
        </button>
        <p id="iosHint" style="display:none;margin-top:12px;font-size:12px;color:var(--ink-500);text-align:center;line-height:1.7;">
            لتثبيت التطبيق على الآيفون: افتح من Safari، اضغط زر المشاركة ⬆️، ثم "إضافة إلى الشاشة الرئيسية".
        </p>
    </div>

    <script>
        // تسجيل الـ Service Worker (لازم للـ PWA)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // الرقم ده لازم يبقى نفس اللي في includes/header.php وفي VERSION
                // بتاعة sw.js — شوف التعليق هناك، الكاش كان بيرجّع نسخة قديمة.
                navigator.serviceWorker.register('sw.js?v=36').catch(() => {});
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
</body>
</html>
