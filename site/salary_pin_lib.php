<?php
// بريف ٨٣٢: رقم كشف الحساب منفصل عن باسورد الدخول وقفل الداشبورد.
const SALARY_PIN_IDLE_SECONDS = 600;

function salary_pin_reset(PDO $pdo, int $uid): void
{
    if (!is_real_owner()) {
        throw new RuntimeException('للأونر بس');
    }
    $pdo->prepare('UPDATE users SET salary_pin_hash=NULL, salary_pin_fails=0, salary_pin_block_until=NULL WHERE id = ?')->execute([$uid]);
}

function salary_pin_read_only(): bool
{
    return !empty($GLOBALS['VIEW_ALL_PAGE'])
        || (!is_real_owner() && in_array('view_all', $_SESSION['permissions'] ?? [], true));
}

function salary_pin_is_set(PDO $pdo, int $uid): bool
{
    $st = $pdo->prepare('SELECT salary_pin_hash FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$uid]);
    return (bool) $st->fetchColumn();
}

function salary_pin_unlocked(): bool
{
    if (salary_pin_read_only() || (int) ($_SESSION['salary_pin_until'] ?? 0) <= time()
        || (int) ($_SESSION['salary_pin_uid'] ?? 0) !== (int) (current_user()['id'] ?? 0)) {
        return false;
    }
    // لو الأونر مسح الرقم أو الموظف غيّره، أي جلسة مفتوحة بالرقم القديم تتقفل.
    $st = db()->prepare('SELECT salary_pin_hash FROM users WHERE id = ? AND is_active = 1');
    $st->execute([(int) current_user()['id']]);
    $hash = $st->fetchColumn();
    if (!$hash || !hash_equals((string) $hash, (string) ($_SESSION['salary_pin_hash'] ?? ''))) {
        unset($_SESSION['salary_pin_until'], $_SESSION['salary_pin_hash'], $_SESSION['salary_pin_uid']);
        return false;
    }
    $_SESSION['salary_pin_until'] = time() + SALARY_PIN_IDLE_SECONDS;
    return true;
}

// نفس تحديد صاحب الكشف في employee_statement_data، قبل قراية الحركات.
function salary_pin_statement_required(PDO $pdo, int $uid, bool $isManager, ?int $requestedEmpId): bool
{
    $st = $pdo->prepare('SELECT id FROM employees WHERE user_id = ?');
    $st->execute([$uid]);
    $ownEmpId = (int) $st->fetchColumn();
    return $ownEmpId > 0 && (!$isManager || $requestedEmpId === null || $requestedEmpId === $ownEmpId);
}

// الصف بيتقفل جوه المعاملة عشان المحاولات المتزامنة ماتضيّعش عدّاد الغلط.
function salary_pin_check_secret(PDO $pdo, int $uid, array $user, string $secret, bool $allowLogin): array
{
    $until = $user['salary_pin_block_until'];
    if ($until !== null && strtotime((string) $until) > time()) {
        return ['ok' => false, 'error' => 'استنى ٥ دقايق وجرّب تاني.', 'status' => 423, 'code' => 'locked'];
    }
    $hash = $user['salary_pin_hash'] ?: ($allowLogin ? $user['password_hash'] : null);
    if (!$hash) {
        return ['ok' => false, 'error' => 'اعمل رقم سري لكشف الحساب الأول'];
    }
    if (!password_verify($secret, (string) $hash)) {
        $fails = ($until !== null ? 0 : (int) $user['salary_pin_fails']) + 1;
        $st = $pdo->prepare('UPDATE users SET salary_pin_fails = ?, salary_pin_block_until = CASE WHEN ? >= 5 THEN DATE_ADD(NOW(), INTERVAL 5 MINUTE) ELSE NULL END WHERE id = ?');
        $st->execute([$fails, $fails, $uid]);
        return ['ok' => false, 'error' => $user['salary_pin_hash'] ? 'الرقم السري غلط' : 'الباسورد الحالي غلط'];
    }
    $pdo->prepare('UPDATE users SET salary_pin_fails = 0, salary_pin_block_until = NULL WHERE id = ?')->execute([$uid]);
    return ['ok' => true];
}

function salary_pin_verify(PDO $pdo, int $uid, string $pin): array
{
    if (salary_pin_read_only()) {
        return ['ok' => false, 'error' => 'حسابك مشاهدة بس', 'status' => 403, 'code' => 'forbidden'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT password_hash, salary_pin_hash, salary_pin_fails, salary_pin_block_until FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
        $st->execute([$uid]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        $result = $user ? salary_pin_check_secret($pdo, $uid, $user, $pin, false)
            : ['ok' => false, 'error' => 'الحساب مش موجود'];
        $pdo->commit();
        if ($result['ok']) {
            $_SESSION['salary_pin_until'] = time() + SALARY_PIN_IDLE_SECONDS;
            $_SESSION['salary_pin_hash'] = $user['salary_pin_hash'];
            $_SESSION['salary_pin_uid'] = $uid;
        }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function salary_pin_set(PDO $pdo, int $uid, string $loginPwdOrOldPin, string $new, string $new2): array
{
    if (salary_pin_read_only()) {
        return ['ok' => false, 'error' => 'حسابك مشاهدة بس', 'status' => 403, 'code' => 'forbidden'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT password_hash, salary_pin_hash, salary_pin_fails, salary_pin_block_until FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
        $st->execute([$uid]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        $result = $user ? salary_pin_check_secret($pdo, $uid, $user, $loginPwdOrOldPin, true)
            : ['ok' => false, 'error' => 'الحساب مش موجود'];
        if ($result['ok']) {
            if (mb_strlen($new) < 4 || mb_strlen($new) > 12) {
                $result = ['ok' => false, 'error' => 'الرقم السري لازم يبقى من ٤ لـ١٢ حرف'];
            } elseif ($new !== $new2) {
                $result = ['ok' => false, 'error' => 'التأكيد مش زي الرقم السري الجديد'];
            } elseif (password_verify($new, (string) $user['password_hash'])) {
                $result = ['ok' => false, 'error' => 'الرقم السري لازم يبقى غير باسورد الدخول'];
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET salary_pin_hash = ?, salary_pin_fails = 0, salary_pin_block_until = NULL, salary_pin_set_at = NOW() WHERE id = ?')->execute([$hash, $uid]);
            }
        }
        $pdo->commit();
        if ($result['ok']) {
            $_SESSION['salary_pin_until'] = time() + SALARY_PIN_IDLE_SECONDS;
            $_SESSION['salary_pin_hash'] = $hash;
            $_SESSION['salary_pin_uid'] = $uid;
            $result['message'] = 'اتحفظ الرقم السري لكشف الحساب';
        }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function salary_pin_form_script(): void
{
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-account-form]').forEach(function (form) {
            form.addEventListener('submit', async function (ev) {
                ev.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                const message = form.querySelector('[role="status"]');
                button.disabled = true;
                message.hidden = true;
                try {
                    const response = await apiFetch('account_api.php', {method: 'POST', body: new FormData(form)});
                    if (!response.ok) throw new Error(response.error || 'حصل خطأ، جرّب تاني');
                    if (form.dataset.reload === '1') { location.reload(); return; }
                    message.className = 'falert falert-pos';
                    message.textContent = response.data.message;
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
}

function salary_pin_fields(bool $isSet): void
{
    ?>
    <div class="ffield"><label>مطلوب <?= $isSet ? 'الرقم القديم' : 'الباسورد الحالي' ?>
        <input class="finput" type="password" name="current_secret" autocomplete="current-password" required></label></div>
    <div class="ffield"><label>الرقم الجديد
        <input class="finput" type="password" name="new_pin" minlength="4" maxlength="12" autocomplete="new-password" required></label></div>
    <div class="ffield"><label>تأكيد الرقم الجديد
        <input class="finput" type="password" name="new_pin2" minlength="4" maxlength="12" autocomplete="new-password" required></label></div>
    <?php
}

function salary_pin_lock_screen(string $what): void
{
    $safeWhat = htmlspecialchars($what, ENT_QUOTES, 'UTF-8');
    echo '<div class="sens-lock-card fcard"><div class="sens-lock-icon"><i class="ti ti-lock"></i></div>';
    echo '<h2 class="sens-lock-title">' . $safeWhat . ' مقفولة</h2>';
    if (salary_pin_read_only()) {
        echo '<p class="sens-lock-msg">الأرقام مقفولة — حسابك مشاهدة بس</p></div>';
        return;
    }
    $isSet = salary_pin_is_set(db(), (int) current_user()['id']);
    ?>
    <p class="sens-lock-msg"><?= $isSet ? 'دخّل الرقم السري لكشف الحساب عشان تشوفها' : 'اعمل رقم سري لكشف الحساب غير باسورد الدخول' ?></p>
    <form method="post" action="account_api.php" class="fgrid" data-account-form data-reload="1">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="<?= $isSet ? 'unlock_pin' : 'set_pin' ?>">
        <?php if ($isSet): ?>
            <div class="ffield"><label>الرقم السري لكشف الحساب
                <input class="finput" type="password" name="pin" autocomplete="current-password" required></label></div>
        <?php else: salary_pin_fields(false); endif; ?>
        <div class="falert" role="status" hidden></div>
        <button class="btn btn-primary" type="submit"><?= $isSet ? 'افتح' : 'اعمل رقم سري' ?></button>
    </form></div>
    <?php
    salary_pin_form_script();
}

function salary_pin_lock_button(): void
{
    $left = max(0, (int) ($_SESSION['salary_pin_until'] ?? 0) - time());
    ?>
    <script>setTimeout(function () { location.reload(); }, <?= $left * 1000 ?>);</script>
    <form method="post" action="account_api.php" class="fgrid" data-account-form data-reload="1">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="lock_pin">
        <div class="falert" role="status" hidden></div>
        <button class="btn btn-ghost" type="submit"><i class="ti ti-lock"></i> اقفل كشف الحساب والمرتب</button>
    </form>
    <?php
    salary_pin_form_script();
}
