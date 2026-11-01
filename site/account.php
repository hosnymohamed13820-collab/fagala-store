<?php
// بريف ٨٣٢: حسابي متاحة لأي حد عامل دخول.
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';
$page_title = 'حسابي';
$active_module = '';
require_once __DIR__ . '/includes/header.php';
if (salary_pin_read_only()) {
    echo '<div class="falert falert-neg">حسابك مشاهدة بس</div>';
} else {
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
    </section>
    <section class="fcard">
        <div class="fcard-head"><h3 class="fcard-title">الرقم السري لكشف الحساب</h3></div>
        <p><?= $pinSet ? 'غيّر الرقم السري بالرقم القديم' : 'اعمل رقم سري من ٤ لـ١٢ حرف غير باسورد الدخول' ?></p>
        <form method="post" action="account_api.php" class="fgrid" data-account-form data-reload="1">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_pin">
            <?php salary_pin_fields($pinSet); ?>
            <div class="falert" role="status" hidden></div>
            <button class="btn btn-primary" type="submit"><?= $pinSet ? 'غيّر الرقم السري' : 'اعمل رقم سري' ?></button>
        </form>
    </section>
    </div>
    <?php
    salary_pin_form_script();
}
require_once __DIR__ . '/includes/footer.php';
