<?php
// بريف ٨٣٢: حسابي متاحة لأي حد عامل دخول.
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
$canResetSelf = !is_real_owner();
$resetOpen = $canResetSelf ? pwreset_open_request(db(), (int) current_user()['id']) : null;
$page_title = 'حسابي';
$active_module = '';
require_once __DIR__ . '/includes/header.php';
$pinSet = salary_pin_is_set(db(), (int) current_user()['id']);
?>
<div class="fgrid">
<section class="fcard">
    <div class="fcard-head"><h3 class="fcard-title">تغيير الباسورد</h3></div>
    <form method="post" action="account_api.php" class="fgrid" data-account-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="ffield"><label>الباسورد الحالي
            <input class="finput" type="password" name="current_password" autocomplete="current-password" required></label></div>
        <div class="ffield"><label>الباسورد الجديد
            <input class="finput" type="password" name="new_password" minlength="8" autocomplete="new-password" required></label></div>
        <div class="ffield"><label>تأكيد الباسورد الجديد
            <input class="finput" type="password" name="new_password2" minlength="8" autocomplete="new-password" required></label></div>
        <div class="falert" role="status" hidden></div>
        <button class="btn btn-primary" type="submit">غيّر الباسورد</button>
    </form>
    <?php if ($canResetSelf): ?>
    <form method="post" action="password_reset_api.php" class="fgrid" data-self-reset-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="request_self">
        <button class="btn btn-ghost" type="submit">نسيت الباسورد الحالي؟</button>
        <div class="falert" role="status" hidden></div>
    </form>
    <div id="selfResetRedeem" <?= $resetOpen === null ? 'hidden' : '' ?>>
    <form method="post" action="password_reset_api.php" class="fgrid" data-self-reset-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="redeem_self">
        <div class="ffield"><label>الكود
            <input class="finput" type="text" name="reset_otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label></div>
        <div class="ffield"><label>الباسورد الجديد
            <input class="finput" type="password" name="new_password" minlength="8" autocomplete="new-password" required></label></div>
        <div class="ffield"><label>التأكيد
            <input class="finput" type="password" name="new_password2" minlength="8" autocomplete="new-password" required></label></div>
        <div class="falert" role="status" hidden></div>
        <button class="btn btn-primary" type="submit">غيّر الباسورد بالكود</button>
    </form>
    </div>
    <?php endif; ?>
</section>
<section class="fcard">
    <div class="fcard-head"><h3 class="fcard-title">الرقم السري لكشف الحساب</h3></div>
    <p><?= $pinSet ? 'غيّر الرقم السري بالرقم القديم' : 'أول مرة: اكتب باسورد الدخول بتاعك، وبعدين اختار رقم سري جديد من ٤ لـ١٢ حرف غيره' ?></p>
    <form method="post" action="account_api.php" class="fgrid" data-account-form data-reload="1">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="set_pin">
        <?php salary_pin_fields($pinSet); ?>
        <div class="falert" role="status" hidden></div>
        <button class="btn btn-primary" type="submit"><?= $pinSet ? 'غيّر الرقم السري' : 'اعمل رقم سري' ?></button>
    </form>
</section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-self-reset-form]').forEach(function (form) {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const message = form.querySelector('[role="status"]');
            const body = new FormData(form);
            button.disabled = true;
            message.hidden = true;
            try {
                const response = await apiFetch('password_reset_api.php', {method: 'POST', body: body});
                if (!response.ok) throw new Error(response.error || 'حصل خطأ، جرّب تاني');
                message.className = 'falert falert-pos';
                message.textContent = response.data.message;
                if (body.get('action') === 'request_self') {
                    document.getElementById('selfResetRedeem').hidden = false;
                }
                form.reset();
            } catch (err) {
                message.className = 'falert falert-neg';
                message.textContent = err.message || 'حصل خطأ، جرّب تاني';
            } finally {
                message.hidden = false;
                button.disabled = false;
            }
        });
    });
});
</script>
<?php
salary_pin_form_script();
require_once __DIR__ . '/includes/footer.php';
