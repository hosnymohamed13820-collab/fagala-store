<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/ui.php';
header('Cache-Control: no-store');

$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) {
    $step = 1;
}
$username = trim((string) ($_GET['username'] ?? ''));
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
    <link rel="stylesheet" href="assets/fagala.css?v=39">
</head>
<body>
<main class="content">
    <div class="fauth-steps" aria-label="خطوات استعادة الباسورد">
        <span class="fauth-step <?= $step === 1 ? 'is-active' : ($step > 1 ? 'is-done' : '') ?>" data-step-indicator="1">١</span>
        <span class="fauth-step-sep">·</span>
        <span class="fauth-step <?= $step === 2 ? 'is-active' : ($step > 2 ? 'is-done' : '') ?>" data-step-indicator="2">٢</span>
        <span class="fauth-step-sep">·</span>
        <span class="fauth-step <?= $step === 3 ? 'is-active' : '' ?>" data-step-indicator="3">٣</span>
    </div>

    <!-- خطوة ١: نسيت الباسورد؟ -->
    <section class="fcard fauth" id="resetStep1" data-step-card="1"<?= $step !== 1 ? ' hidden' : '' ?>>
        <div class="fcard-head"><h1 class="fcard-title">نسيت الباسورد؟</h1></div>
        <p class="fauth-desc">ابعت طلب للمدير، أو استخدم الكود اللي ادّهولك لو كنت طلبت قبل كده.</p>
        <form method="post" action="password_reset_api.php" data-reset-form data-step="1">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="request">
            <div class="ffield">
                <label for="step1_username">اسم المستخدم</label>
                <input class="finput" id="step1_username" name="username" autocomplete="username" maxlength="60" value="<?= e($username) ?>" required autofocus>
            </div>
            <div class="falert" role="status" hidden></div>
            <button class="btn btn-primary" type="submit">ابعت طلب للمدير</button>
            <div class="fauth-actions">
                <a class="btn btn-ghost" href="password_reset.php?step=3" data-step-link="3">معايا كود بالفعل</a>
                <a class="btn btn-ghost" href="login.php">ارجع للدخول</a>
            </div>
        </form>
    </section>

    <!-- خطوة ٢: الطلب اتبعت -->
    <section class="fcard fauth" id="resetStep2" data-step-card="2"<?= $step !== 2 ? ' hidden' : '' ?>>
        <div class="fcard-head"><h1 class="fcard-title">الطلب اتبعت</h1></div>
        <div class="falert falert-pos" id="step2_message" role="status">الطلب اتبعت. أول ما المالك أو المسؤول يوافق هيديك كود</div>
        <p class="fauth-desc">أول ما المدير يوافق هيديك كود من ٦ أرقام صالح نص ساعة.</p>
        <div class="fauth-actions">
            <button type="button" class="btn btn-primary" id="step2_to_step3">معايا الكود</button>
            <a class="btn btn-ghost" href="login.php">ارجع للدخول</a>
        </div>
    </section>

    <!-- خطوة ٣: اكتب الكود -->
    <section class="fcard fauth" id="resetStep3" data-step-card="3"<?= $step !== 3 ? ' hidden' : '' ?>>
        <div class="fcard-head"><h1 class="fcard-title">اكتب الكود</h1></div>
        <p class="fauth-desc">الكود صالح ٣٠ دقيقة من موافقة المدير.</p>
        <form method="post" action="password_reset_api.php" data-reset-form data-step="3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="redeem">
            <div class="ffield">
                <label for="step3_username">اسم المستخدم</label>
                <input class="finput" id="step3_username" name="username" autocomplete="username" maxlength="60" value="<?= e($username) ?>" required>
            </div>
            <div class="ffield">
                <label for="step3_otp">الكود</label>
                <input class="finput" id="step3_otp" name="reset_otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            </div>
            <div class="ffield">
                <label for="step3_newpass">الباسورد الجديد</label>
                <input class="finput" id="step3_newpass" type="password" name="new_password" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="ffield">
                <label for="step3_newpass2">تأكيد الباسورد الجديد</label>
                <input class="finput" id="step3_newpass2" type="password" name="new_password2" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="falert" role="status" hidden></div>
            <button class="btn btn-primary" type="submit">غيّر الباسورد</button>
            <div class="fauth-actions">
                <a class="btn btn-ghost" href="password_reset.php?step=1" data-step-link="1">ارجع للخطوة الأولى</a>
                <a class="btn btn-ghost" href="login.php">ارجع للدخول</a>
            </div>
        </form>
    </section>
</main>
<script>
(function () {
    'use strict';
    const cards = {
        1: document.querySelector('[data-step-card="1"]'),
        2: document.querySelector('[data-step-card="2"]'),
        3: document.querySelector('[data-step-card="3"]')
    };
    const stepIndicators = {
        1: document.querySelector('[data-step-indicator="1"]'),
        2: document.querySelector('[data-step-indicator="2"]'),
        3: document.querySelector('[data-step-indicator="3"]')
    };
    const step1UserInput = document.getElementById('step1_username');
    const step3UserInput = document.getElementById('step3_username');
    const step2Msg = document.getElementById('step2_message');
    const step2Btn = document.getElementById('step2_to_step3');

    function syncUsername(fromInput, toInput) {
        if (!toInput) return;
        const val = fromInput ? fromInput.value.trim() : '';
        if (val) toInput.value = val;
    }

    function setStep(stepNum, updateHistory) {
        const step = parseInt(stepNum, 10) || 1;
        for (let i = 1; i <= 3; i++) {
            if (cards[i]) cards[i].hidden = (i !== step);
            if (stepIndicators[i]) {
                stepIndicators[i].classList.remove('is-active', 'is-done');
                if (i === step) stepIndicators[i].classList.add('is-active');
                else if (i < step) stepIndicators[i].classList.add('is-done');
            }
        }
        if (updateHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('step', String(step));
            const currentStep = parseInt(new URLSearchParams(window.location.search).get('step') || '1', 10);
            if (currentStep !== step) window.history.pushState({ step: step }, '', url.toString());
        }
        if (step === 1 && step1UserInput) {
            step1UserInput.focus();
        } else if (step === 2 && step2Btn) {
            step2Btn.focus();
        } else if (step === 3) {
            const otpInput = document.getElementById('step3_otp');
            if (step3UserInput && !step3UserInput.value.trim()) step3UserInput.focus();
            else if (otpInput) otpInput.focus();
        }
    }

    if (step2Btn) {
        step2Btn.addEventListener('click', function (e) {
            e.preventDefault();
            syncUsername(step1UserInput, step3UserInput);
            setStep(3, true);
        });
    }

    document.querySelectorAll('[data-step-link]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const targetStep = parseInt(link.getAttribute('data-step-link'), 10) || 1;
            if (targetStep === 3) syncUsername(step1UserInput, step3UserInput);
            setStep(targetStep, true);
        });
    });

    window.addEventListener('popstate', function (event) {
        const step = (event.state && event.state.step)
            ? event.state.step
            : (parseInt(new URLSearchParams(window.location.search).get('step') || '1', 10));
        setStep(step, false);
    });

    document.querySelectorAll('[data-reset-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const step = parseInt(form.getAttribute('data-step') || '1', 10);
            const button = form.querySelector('button[type="submit"]');
            const message = form.querySelector('[role="status"]');
            if (button) button.disabled = true;
            if (message) message.hidden = true;
            try {
                const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
                const result = await response.json();
                if (result.ok) {
                    if (step === 1) {
                        syncUsername(step1UserInput, step3UserInput);
                        if (step2Msg && result.data && result.data.message) {
                            step2Msg.textContent = result.data.message;
                        }
                        setStep(2, true);
                    } else if (step === 3) {
                        if (message) {
                            message.textContent = (result.data && result.data.message) ? result.data.message : 'اتغيّر الباسورد بنجاح';
                            message.className = 'falert falert-pos';
                            message.hidden = false;
                        }
                        if (result.data && result.data.redirect) window.location.assign(result.data.redirect);
                    }
                } else {
                    if (message) {
                        message.textContent = result.error || 'حصل خطأ، جرّب تاني';
                        message.className = 'falert falert-neg';
                        message.hidden = false;
                    }
                }
            } catch (error) {
                if (message) {
                    message.textContent = 'الاتصال وقف، جرّب تاني';
                    message.className = 'falert falert-neg';
                    message.hidden = false;
                }
            } finally {
                if (button) button.disabled = false;
            }
        });
    });

    const initialParams = new URLSearchParams(window.location.search);
    const initialUser = initialParams.get('username');
    if (initialUser) {
        if (step1UserInput && !step1UserInput.value) step1UserInput.value = initialUser;
        if (step3UserInput && !step3UserInput.value) step3UserInput.value = initialUser;
    }
    const initialStep = parseInt(initialParams.get('step') || '1', 10);
    window.history.replaceState({ step: initialStep }, '', window.location.href);
})();
</script>
</body>
</html>
