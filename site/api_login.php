<?php
/**
 * api_login.php — تسجيل الدخول عبر الـAPI والحصول على توكن جهاز
 * نظام الفجالة ستور المالي (بريف ١٠٢)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';

api_begin();
api_method('POST');

$input = api_input();
$username   = trim((string) ($input['username'] ?? ''));
$password   = (string) ($input['password'] ?? '');
$deviceName = trim((string) ($input['device_name'] ?? ''));

$ip = $_SERVER['REMOTE_ADDR'] ?? null;

if ($username === '' || $password === '') {
    api_fail('من فضلك أدخل اسم المستخدم والباسورد', 400, 'bad_request');
}

if ($deviceName === '') {
    api_fail('اسم الجهاز مطلوب', 400, 'bad_request');
}

if (mb_strlen($deviceName) > 80) {
    api_fail('اسم الجهاز لا يجب أن يتعدى 80 حرف', 400, 'bad_request');
}

// فحص قفل الدخول لتفادي التخمين
$lockLeft = login_lock_minutes($username, $ip);
if ($lockLeft > 0) {
    http_response_code(429);
    echo json_encode([
        'ok'      => false,
        'error'   => 'محاولات كتير غلط. استنى ' . $lockLeft . ' دقيقة وحاول تاني.',
        'code'    => 'locked_out',
        'minutes' => $lockLeft,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// البحث عن المستخدم
$stmt = db()->prepare(
    'SELECT u.id, u.username, u.full_name, u.password_hash, u.role_id,
            u.branch_id, u.is_active, u.pw_version, r.is_owner
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.username = ?'
);
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// فشل: مستخدم غير موجود أو غير نشط أو باسورد غلط
if (!$user || empty($user['is_active']) || !password_verify($password, $user['password_hash'])) {
    log_login(null, $username, $ip, false);
    api_fail('اسم المستخدم أو الباسورد غلط', 401, 'auth');
}

$userId = (int) $user['id'];
$pwVer  = (int) ($user['pw_version'] ?? 1);

// توليد التوكن الصريح والهاش
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// حفظ التوكن في القاعدة
$ins = db()->prepare(
    'INSERT INTO api_tokens (user_id, token_hash, device_name, pw_version, created_at, last_used_at, last_ip)
     VALUES (?, ?, ?, ?, NOW(), NOW(), ?)'
);
$ins->execute([$userId, $tokenHash, $deviceName, $pwVer, $ip]);

// تسجيل نجاح الدخول وتحديث آخر دخول
db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$userId]);
log_login($userId, $username, $ip, true);

// جلب الصلاحيات
$stPerms = db()->prepare('SELECT module_key FROM user_permissions WHERE user_id = ?');
$stPerms->execute([$userId]);
$perms = $stPerms->fetchAll(PDO::FETCH_COLUMN);

api_ok([
    'token' => $rawToken,
    'user'  => [
        'id'        => $userId,
        'full_name' => $user['full_name'],
        'is_owner'  => (int) $user['is_owner'] === 1,
        'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
    ],
    'permissions' => $perms,
]);
