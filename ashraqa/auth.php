<?php
/**
 * auth.php — نظام الحماية والصلاحيات
 * نظام الفجالة ستور المالي
 *
 * بيوفّر:
 *  - بدء الجلسة الآمنة
 *  - تسجيل الدخول والخروج
 *  - التحقق من تسجيل الدخول (require_login)
 *  - التحقق من صلاحية مديول (can / require_permission)
 *
 * الأونر (role is_owner = 1) بياخد كل الصلاحيات تلقائياً.
 */

require_once __DIR__ . '/config.php';

// ===== بدء الجلسة بإعدادات آمنة =====
if (session_status() === PHP_SESSION_NONE) {
    // secure: الكوكي ماتتبعتش إلا على HTTPS.
    // بنكتشف البروتوكول تلقائي عشان اللوكال (XAMPP على http) يفضل شغال.
    $isHttps =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * كل المديولات الـ 13 المتاحة في النظام.
 * المفتاح = module_key | القيمة = الاسم العربي + الأيقونة + الرابط
 */
function all_modules(): array
{
    return [
        'dashboard'     => ['label' => 'الرئيسية',          'icon' => 'ti-layout-dashboard', 'url' => 'dashboard.php'],
        'price_inquiry' => ['label' => 'استعلام الأسعار',   'icon' => 'ti-scan',          'url' => 'price_inquiry.php'],
        'remote_scan'   => ['label' => 'سكانر الكمبيوتر',   'icon' => 'ti-device-mobile', 'url' => 'remote_scan.php'],
        'cashier'       => ['label' => 'تقفيل الكاشير',     'icon' => 'ti-lock-dollar',    'url' => 'cashier.php'],
        'custody'       => ['label' => 'عهدة الفرع',        'icon' => 'ti-cash',           'url' => 'custody.php'],
        'review'        => ['label' => 'مراجعة المحاسب',    'icon' => 'ti-user-check',        'url' => 'review.php'],
        'treasury'      => ['label' => 'الخزينة وكشف الحساب','icon' => 'ti-building-bank',    'url' => 'treasury.php'],
        'hr'            => ['label' => 'الموارد البشرية',   'icon' => 'ti-users',            'url' => 'hr.php'],
        'attendance'    => ['label' => 'تسجيل الحضور',      'icon' => 'ti-clock-hour-4',     'url' => 'attendance.php'],
        'tasks'         => ['label' => 'المهام',            'icon' => 'ti-inbox',         'url' => 'tasks.php'],
        'tasks_issue'   => ['label' => 'إصدار المهام',       'icon' => 'ti-send',             'url' => 'tasks_issue.php'],
        'suppliers'     => ['label' => 'الموردون',          'icon' => 'ti-building-store',            'url' => 'suppliers.php'],
        'invoices'      => ['label' => 'الاستلامات',        'icon' => 'ti-package-import',     'url' => 'invoices.php'],
        'stocktake'     => ['label' => 'الجرد',             'icon' => 'ti-packages',   'url' => 'stocktake.php'],
        'shortage'      => ['label' => 'طلب نواقص',         'icon' => 'ti-shopping-cart', 'url' => 'shortage.php'],
        'analysis'      => ['label' => 'تحليل الموسم',     'icon' => 'ti-calculator'   , 'url' => 'analysis.php'],
        'reports'       => ['label' => 'التقارير',          'icon' => 'ti-chart-bar',        'url' => 'reports.php'],
        'definitions'   => ['label' => 'التعريفات',         'icon' => 'ti-adjustments',     'url' => 'definitions.php'],
        'settings'      => ['label' => 'الإعدادات',         'icon' => 'ti-settings',         'url' => 'settings.php'],
    ];
}

/**
 * كام محاولة فاشلة مسموح بيها قبل القفل المؤقت.
 * الحساب بيتقفل لو تخطّى العدد ده من نفس اليوزرنيم **أو** من نفس الـIP
 * خلال آخر LOGIN_LOCK_MINUTES دقيقة.
 */
const LOGIN_MAX_FAILS     = 5;
const LOGIN_LOCK_MINUTES  = 15;

/**
 * الدخول مقفول مؤقتًا؟ بترجّع عدد الدقايق الفاضلة (0 = مفتوح).
 *
 * بيعتمد على جدول login_log الموجود أصلًا — مفيش جداول جديدة.
 */
function login_lock_minutes(string $username, ?string $ip): int
{
    try {
        $st = db()->prepare(
            'SELECT MAX(created_at) AS last_try, COUNT(*) AS fails
             FROM login_log
             WHERE success = 0
               AND created_at > NOW() - INTERVAL ? MINUTE
               AND (username = ? OR (ip_address IS NOT NULL AND ip_address = ?))'
        );
        $st->execute([LOGIN_LOCK_MINUTES, $username, $ip]);
        $row = $st->fetch();

        if (!$row || (int) $row['fails'] < LOGIN_MAX_FAILS) return 0;

        $last = strtotime((string) $row['last_try']);
        $left = (int) ceil((($last + LOGIN_LOCK_MINUTES * 60) - time()) / 60);
        return max($left, 1);
    } catch (Throwable $ex) {
        // الجدول ناقص أو فيه مشكلة — مانقفلش الدخول في وش الناس
        return 0;
    }
}

/**
 * محاولة تسجيل الدخول.
 * بترجّع true عند النجاح، false عند الفشل.
 */
function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.full_name, u.password_hash, u.role_id,
                u.branch_id, u.is_active, r.is_owner
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.username = ?'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    // فشل: مستخدم غير موجود أو غير نشط أو باسورد غلط
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        log_login(null, $username, $ip, false);
        return false;
    }

    // نجاح: تخزين بيانات الجلسة
    session_regenerate_id(true); // حماية ضد session fixation
    $_SESSION['user'] = [
        'id'        => (int) $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role_id'   => (int) $user['role_id'],
        'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
        'is_owner'  => (int) $user['is_owner'] === 1,
    ];

    // تحميل صلاحيات المستخدم في الجلسة (مرة واحدة)
    load_permissions((int) $user['id']);

    // تحديث آخر دخول
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([(int) $user['id']]);

    log_login((int) $user['id'], $username, $ip, true);
    return true;
}

/**
 * تحميل صلاحيات المستخدم وتخزينها في الجلسة.
 */
function load_permissions(int $userId): void
{
    $stmt = db()->prepare('SELECT module_key FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * تسجيل محاولة دخول في login_log.
 */
function log_login(?int $userId, string $username, ?string $ip, bool $success): void
{
    try {
        db()->prepare(
            'INSERT INTO login_log (user_id, username, ip_address, success) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $username, $ip, $success ? 1 : 0]);
    } catch (PDOException $e) {
        // تجاهل أخطاء التسجيل عشان متوقفش الدخول
    }
}

/**
 * تسجيل الخروج.
 */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * هل المستخدم مسجّل دخول؟
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * بيانات المستخدم الحالي (أو null).
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * هل المستخدم الحالي أونر؟
 */
function is_owner(): bool
{
    return is_logged_in() && !empty($_SESSION['user']['is_owner']);
}

/**
 * هل المستخدم الحالي ليه صلاحية المديول ده؟
 * الأونر بياخد true دايماً.
 */
function can(string $moduleKey): bool
{
    // سكانر الكمبيوتر بيتبع صلاحية استعلام الأسعار (نفس الجمهور — أي موظف)
    if ($moduleKey === 'remote_scan') $moduleKey = 'price_inquiry';

    if (!is_logged_in()) {
        return false;
    }
    if (is_owner()) {
        return true;
    }
    return in_array($moduleKey, $_SESSION['permissions'] ?? [], true);
}

/**
 * يفرض تسجيل الدخول — لو مش داخل يحوّله لصفحة الدخول.
 * يتحط في أول كل صفحة محمية.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * يفرض صلاحية مديول — لو مالوش صلاحية يمنعه تماماً.
 * بيحمي حتى لو حد كتب الرابط مباشرةً.
 */
function require_permission(string $moduleKey): void
{
    require_login();
    if (!can($moduleKey)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
            . '<h2>🚫 غير مصرّح</h2>'
            . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
            . '<a href="logout.php">تسجيل الخروج</a></div>');
    }
}

/**
 * أول مديول متاح للمستخدم (للتوجيه بعد الدخول).
 * لو مفيش، بيرجّع dashboard.
 */
function first_allowed_module(): string
{
    foreach (all_modules() as $key => $mod) {
        if (can($key)) {
            return $mod['url'];
        }
    }
    return 'dashboard.php';
}

/**
 * حماية بسيطة ضد CSRF — توليد توكن.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * التحقق من توكن CSRF.
 */
function csrf_verify(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/**
 * تنظيف المخرجات ضد XSS.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
