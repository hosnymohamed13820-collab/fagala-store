<?php
// بريف ٨٣٢: تغيير باسورد الحساب من مكان واحد للإعدادات وحسابي.
require_once __DIR__ . '/sensitive_lib.php';

function my_account_change_password(PDO $pdo, int $userId, string $cur, string $new, string $new2): array
{
    $now = time();
    if (!empty($_SESSION['pw_block_until']) && (int) $_SESSION['pw_block_until'] > $now) {
        return ['ok' => false, 'error' => 'استنى ٥ دقايق وجرّب تاني.'];
    }
    try {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($cur, (string) $hash)) {
            $fails = ((int) ($_SESSION['pw_fails'] ?? 0)) + 1;
            if ($fails >= SENS_MAX_FAILS) {
                $_SESSION['pw_block_until'] = $now + SENS_BLOCK_SECONDS;
                $_SESSION['pw_fails'] = 0;
            } else {
                $_SESSION['pw_fails'] = $fails;
            }
            return ['ok' => false, 'error' => 'الباسورد الحالي غلط'];
        }
        if (mb_strlen($new) < 8) {
            return ['ok' => false, 'error' => 'الباسورد الجديد لازم ٨ حروف على الأقل'];
        }
        if ($new !== $new2) {
            return ['ok' => false, 'error' => 'التأكيد مش زي الباسورد الجديد'];
        }
        if ($new === $cur) {
            return ['ok' => false, 'error' => 'الباسورد الجديد زي القديم'];
        }
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $updSt = $pdo->prepare('UPDATE users SET password_hash = ?, pw_version = pw_version + 1 WHERE id = ?');
        $updSt->execute([$newHash, $userId]);
        $stVer = $pdo->prepare('SELECT pw_version FROM users WHERE id = ?');
        $stVer->execute([$userId]);
        $newPwVer = (int) $stVer->fetchColumn();
        $_SESSION['user']['pw_version'] = $newPwVer;
        session_regenerate_id(true);
        $_SESSION['pw_fails'] = 0;
        unset($_SESSION['pw_block_until']);
        return ['ok' => true, 'message' => 'اتغيّر الباسورد، وأي جهاز تاني كان داخل بحسابك هيخرج.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'حصل خطأ، جرّب تاني'];
    }
}
