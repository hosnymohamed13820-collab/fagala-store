<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/ui.php';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نسيت الباسورد؟</title>
    <script>
        try { document.documentElement.dataset.theme = localStorage.getItem('fagala-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
    </script>
    <link rel="stylesheet" href="assets/fagala.css">
</head>
<body>
<main class="content">
    <?= ui_page_head('نسيت الباسورد؟', 'ابعت طلب للمدير، أو استخدم الكود اللي ادّهولك.') ?>
    <div class="fgrid fgrid-2">
        <section class="fcard">
            <div class="fcard-head"><h2 class="fcard-title">ابعت طلب</h2></div>
            <p>المدير هيتأكد إن الحساب بتاعك ويدّيك كود مؤقت.</p>
            <form method="post" action="password_reset_api.php" class="fgrid" data-reset-form>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="request">
                <div class="ffield"><label>اسم المستخدم
                    <input class="finput" name="username" autocomplete="username" maxlength="60" required></label></div>
                <div class="falert" role="status" hidden></div>
                <button class="btn btn-primary" type="submit">ابعت طلب</button>
            </form>
        </section>
        <section class="fcard">
            <div class="fcard-head"><h2 class="fcard-title">معايا كود</h2></div>
            <p>الكود صالح ٣٠ دقيقة من موافقة المدير.</p>
            <form method="post" action="password_reset_api.php" class="fgrid" data-reset-form>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="redeem">
                <div class="ffield"><label>اسم المستخدم
                    <input class="finput" name="username" autocomplete="username" maxlength="60" required></label></div>
                <div class="ffield"><label>الكود
                    <input class="finput" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label></div>
                <div class="ffield"><label>الباسورد الجديد
                    <input class="finput" type="password" name="new_password" autocomplete="new-password" minlength="8" required></label></div>
                <div class="ffield"><label>تأكيد الباسورد الجديد
                    <input class="finput" type="password" name="new_password2" autocomplete="new-password" minlength="8" required></label></div>
                <div class="falert" role="status" hidden></div>
                <button class="btn btn-primary" type="submit">غيّر الباسورد</button>
            </form>
        </section>
    </div>
    <a class="btn btn-ghost" href="login.php">ارجع للدخول</a>
</main>
<script>
document.querySelectorAll('[data-reset-form]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[role="status"]');
        button.disabled = true;
        message.hidden = true;
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
            const result = await response.json();
            message.textContent = result.ok ? result.data.message : result.error;
            message.className = 'falert ' + (result.ok ? 'falert-pos' : 'falert-neg');
            message.hidden = false;
            if (result.ok && result.data.redirect) window.location.assign(result.data.redirect);
        } catch (error) {
            message.textContent = 'الاتصال وقف، جرّب تاني';
            message.className = 'falert falert-neg';
            message.hidden = false;
        } finally { button.disabled = false; }
    });
});
</script>
</body>
</html>
