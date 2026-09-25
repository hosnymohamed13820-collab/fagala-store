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

// سجل الأخطاء — **قبل** config.php عشان يمسك أخطاء config نفسه.
// بيتفرّج بس ومابيغيّرش سلوك أي صفحة (شوف القواعد في errlog_lib.php).
require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/config.php';

// ===== بدء الجلسة بإعدادات آمنة =====
if (session_status() === PHP_SESSION_NONE) {
    // secure: الكوكي ماتتبعتش إلا على HTTPS.
    // بنكتشف البروتوكول تلقائي عشان اللوكال (XAMPP على http) يفضل شغال.
    $isHttps =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // ---------------------------------------------------------------
    //  مدة الجلسة
    //
    //  كانت 'lifetime' => 0 يعني الكوكي بتموت بقفل المتصفح، وPHP بيمسح
    //  السيشن من السيرفر بعد ٢٤ دقيقة خمول (الافتراضي). فأي وقت بعيد عن
    //  الجهاز أو قفلة نت = خروج تلقائي.
    //
    //  🔴 ده مالوش علاقة بإشعارات الموبايل — الاشتراك متخزّن في المتصفح
    //  و logout() مابيمسحهوش، فالإشعار بيوصل حتى والمتصفح مقفول وحتى لو
    //  المستخدم عامل خروج.
    // ---------------------------------------------------------------
    $sessLifetime = 30 * 24 * 60 * 60;   // ٣٠ يوم
    @ini_set('session.gc_maxlifetime', (string) $sessLifetime);

    session_set_cookie_params([
        'lifetime' => $sessLifetime,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    // 🔴 اسم جلسة خاص بالفجالة — إشراقة متركّبة جوّه public_html ومفتوحة على
    //    hosnimanagement.com/ashraqa/ بنفس الكوكي ونفس مكان التخزين، فالجلسة كانت
    //    بتعدّي بين الموقعين (مراجعة ٢٢ سبتمبر ٢٠٢٦). اسم مختلف = كوكي مختلف.
    session_name('FGSESS');
    session_start();

    // تجديد متدحرج: كل طلب من مستخدم داخل بيمدّد الكوكي من جديد، فاللي
    // بيستعمل النظام عمره ما يتقفل عليه.
    if (!empty($_SESSION['user']['id']) && !headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessLifetime,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Lax',
        ]);
    }
}

// هيدرز الحماية — مراجعة ٢٢ سبتمبر ٢٠٢٦ (clickjacking · MIME sniffing · إخفاء نسخة PHP)
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $fgHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($fgHttps) header('Strict-Transport-Security: max-age=31536000');
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
        'custody'       => ['label' => 'العهد',             'icon' => 'ti-cash',           'url' => 'custody.php'],
        'review'        => ['label' => 'مراجعة المحاسب',    'icon' => 'ti-user-check',        'url' => 'review.php'],
        'treasury'      => ['label' => 'الخزينة وكشف الحساب','icon' => 'ti-building-bank',    'url' => 'treasury.php'],
        'hr'            => ['label' => 'الموارد البشرية',   'icon' => 'ti-users',            'url' => 'hr.php'],
        'attendance'        => ['label' => 'تسجيل الحضور',      'icon' => 'ti-clock-hour-4',     'url' => 'attendance.php'],
        'tasks'         => ['label' => 'المهام',            'icon' => 'ti-inbox',         'url' => 'tasks.php'],
        'tasks_issue'   => ['label' => 'إصدار المهام',       'icon' => 'ti-send',             'url' => 'tasks_issue.php'],
        'suppliers'     => ['label' => 'الموردون',          'icon' => 'ti-building-store',            'url' => 'suppliers.php'],
        'branch_recon'  => ['label' => 'مطابقة الفروع',     'icon' => 'ti-transfer',                  'url' => 'branch_recon.php'],
        'invoices'      => ['label' => 'الاستلامات',        'icon' => 'ti-package-import',     'url' => 'invoices.php'],
        'receiving'     => ['label' => 'استلام الفواتير',   'icon' => 'ti-truck',              'url' => 'receiving.php'],
        'branch_invoices' => ['label' => 'فواتير الفروع',   'icon' => 'ti-file-invoice',       'url' => 'branch_invoices.php'],
        'stocktake'     => ['label' => 'الجرد',             'icon' => 'ti-packages',   'url' => 'stocktake.php'],
        'shortage'      => ['label' => 'طلب نواقص',         'icon' => 'ti-shopping-cart', 'url' => 'shortage.php'],
        'analysis'      => ['label' => 'تحليل الموسم',     'icon' => 'ti-calculator'   , 'url' => 'analysis.php'],
        'reports'       => ['label' => 'التقارير',          'icon' => 'ti-chart-bar',        'url' => 'reports.php'],
        'definitions'   => ['label' => 'التعريفات',         'icon' => 'ti-adjustments',     'url' => 'definitions.php'],
        'settings'      => ['label' => 'الإعدادات',         'icon' => 'ti-settings',         'url' => 'settings.php'],
        'errors'        => ['label' => 'سجل الأخطاء',       'icon' => 'ti-alert-triangle',   'url' => 'errors.php'],
    ];
}

/**
 * سجل أجزاء المديولات المركّبة (module:part).
 *
 * ملاحظة: مديول «طلب النواقص» (shortage) لسه مش متجزّأ في المرحلة دي عشان ما يدّيش إحساس كاذب بالتحكم.
 * الأسماء المحجوزة ليه مستقبلاً:
 *   - shortage:orders     (طلبات الفروع)
 *   - shortage:purchases  (مشتريات الإدارة)
 * وإضافتها هنا هتكون سطر واحد بس لما يتم تقسيم الشاشة في مرحلتها.
 *
 * @return array<string, array<string, string>>
 */
function module_parts(): array
{
    return [
        'custody' => [
            'employee' => 'عهدة الموظف',
            'branch'   => 'عهدة الفرع',
        ],
        'hr' => [
            'employees'  => 'الموظفون',
            'advances'   => 'السلف',
            'bonuses'    => 'المكافآت',
            'penalties'  => 'الجزاءات',
            'salaries'   => 'الرواتب',
            'attendance' => 'الحضور والانصراف',
            'requests'   => 'طلبات التصحيح',
        ],
        'reports' => [
            'sales'      => 'المبيعات',
            'treasury'   => 'الخزينة',
            'expenses'   => 'المصاريف',
            'jtotals'    => 'إجماليات القيود',
            'custody'    => 'العهد',
            'deficits'   => 'العجوزات',
            'suppliers'  => 'الموردين',
            'salaries'   => 'الرواتب',
            'advances'   => 'السلف',
            'attendance'        => 'الحضور',
            'attendance_totals' => 'إجمالي الحضور',
            'stocktake'  => 'الجرد',
            'noprice'    => 'أصناف بدون سعر بيع',
            'stock'      => 'استعلام أرصدة الأصناف',
            'customers'  => 'داتا العملاء',
            'receiving'  => 'استلام الفواتير',
        ],
        'invoices' => [
            'problems' => 'مشاكل الفواتير',
            'undistributed' => 'الأصناف غير الموزّعة',
            // فواتير الفروع (إشراقة وإخواتها) بقت موديول لوحده: branch_invoices
        ],
        'receiving' => [
            'diffs' => 'صفحة الفروقات',
        ],
        'treasury' => [
            'statement' => 'الخزينة وكشف الحساب',
            'operate'   => 'صرف واستلام وتحويل',
            'reverse'   => 'قيد عكسي',
        ],
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

    $permsVer = 1;
    try {
        $stVer = db()->prepare('SELECT perms_version FROM users WHERE id = ?');
        $stVer->execute([(int) $user['id']]);
        $v = $stVer->fetchColumn();
        if ($v !== false && $v !== null) {
            $permsVer = (int) $v;
        }
    } catch (Throwable $e) {
        // لو العمود لسه ما اتضافش على السيرفر، بنكمّل بالقيمة الافتراضية
        $permsVer = 1;
    }

    $_SESSION['user'] = [
        'id'            => (int) $user['id'],
        'username'      => $user['username'],
        'full_name'     => $user['full_name'],
        'role_id'       => (int) $user['role_id'],
        'branch_id'     => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
        'is_owner'      => (int) $user['is_owner'] === 1,
        'perms_version' => $permsVer,
    ];
    $_SESSION['app'] = 'fagala';

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
 * هل المستخدم الحالي ليه صلاحية المفتاح ده؟
 * المفتاح ممكن يكون مديول بسيط ('cashier') أو مفتاح مركّب ('hr:salaries').
 *
 * القواعد:
 * - الأونر: true دايماً.
 * - مفتاح مركب ('module:part'): true لو معاه المديول كامل ('module') أو الجزء بالظبط ('module:part').
 * - مديول بسيط ('hr'): true لو معاه المديول كامل بالظبط. (معاه 'hr:salaries' بس → false).
 */
function can(string $moduleKey): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_owner()) {
        return true;
    }

    $perms = $_SESSION['permissions'] ?? [];

    // لو مفتاح مركّب: مسموح لو معاه المديول كامل كـ wildcard أو معاه الجزء بالتحديد
    if (str_contains($moduleKey, ':')) {
        [$mod] = explode(':', $moduleKey, 2);
        return in_array($mod, $perms, true) || in_array($moduleKey, $perms, true);
    }

    // مديول بسيط: تطابق تام فقط (can('hr') ترجع true لو معاه 'hr' بس مش لو معاه جزء)
    return in_array($moduleKey, $perms, true);
}

/**
 * هل المستخدم الحالي يقدر يفتح المديول ده أصلاً؟
 * بترجّع true لو:
 *  1. أونر
 *  2. عنده المديول كامل (زي can($moduleKey))
 *  3. عنده أي جزء من المديول (أي مفتاح يبدأ بـ "$moduleKey:")
 *
 * دي معمولة للقايمة الجانبية وحراسة الصفحة الرئيسية للمديول.
 */
function can_module(string $moduleKey): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_owner()) {
        return true;
    }

    $perms = $_SESSION['permissions'] ?? [];
    if (in_array($moduleKey, $perms, true)) {
        return true;
    }

    $prefix = $moduleKey . ':';
    $prefixLen = strlen($prefix);
    foreach ($perms as $p) {
        if (strncmp((string) $p, $prefix, $prefixLen) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * يفرض تسجيل الدخول — لو مش داخل يحوّله لصفحة الدخول.
 * كمان بيتأكد إن الحساب لسه نشط وإن الصلاحيات متزامنة مع أحدث إصدار.
 * يتحط في أول كل صفحة محمية.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }

    if (($_SESSION['app'] ?? '') !== 'fagala') {
        logout();
        header('Location: login.php');
        exit;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        logout();
        header('Location: login.php');
        exit;
    }

    try {
        $st = db()->prepare(
            'SELECT u.is_active, u.perms_version, u.branch_id, u.role_id, r.is_owner
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // لو المستخدم اتمسح أو حسابه اتعطّل وهو شغال، يطرد فوراً
        if (!$row || empty($row['is_active'])) {
            logout();
            header('Location: login.php');
            exit;
        }

        $_SESSION['user']['branch_id'] = $row['branch_id'] !== null ? (int) $row['branch_id'] : null;
        $_SESSION['user']['role_id']   = (int) $row['role_id'];
        $_SESSION['user']['is_owner']  = (int) $row['is_owner'] === 1;

        // لو رقم إصدار الصلاحيات اتغير (الأونر عدّل صلاحياته)، نحدّث الجلسة فوراً من غير خروج
        if (isset($row['perms_version'])) {
            $currentVer = (int) $row['perms_version'];
            $sessionVer = (int) ($_SESSION['user']['perms_version'] ?? 0);
            if ($sessionVer !== $currentVer) {
                load_permissions($userId);
                $_SESSION['user']['perms_version'] = $currentVer;
            }
        }
    } catch (Throwable $ex) {
        // =====================================================================
        //  حزام أمان حاسم: الدالة دي بتشتغل في أول كل ريكوست لكل الـ ٤٩ مستخدم.
        //  لو الاستعلام رمى أي خطأ (مثلاً ملف SQL لسه ما اتنفّذش على السيرفر
        //  والعمود perms_version لسه مش موجود، أو فيه عطل لحظي)، بنبلع الخطأ
        //  بهدوء ونكمّل بالسلوك العادي من غير ما نوقّع الموقع في وش الناس.
        // =====================================================================
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
 * يفرض صلاحية الوصول للمديول (سواء المديول كامل أو أي جزء منه) — لو مالوش يمنعه تماماً.
 * نفس سلوك require_permission بس بتستعمل can_module().
 */
function require_module_access(string $moduleKey): void
{
    require_login();
    if (!can_module($moduleKey)) {
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
        if (can_module($key)) {
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

/**
 * عرض رقم/تليفون جوه نص عربي من غير ما ترتيبه أو إشارته تتقلب.
 *
 * 🔴 الأرقام اللاتينية جوه فقرة RTL بتتلخبط: التليفون بيتقطّع
 *    («0 11 26857134») والسالب بتروح إشارته لآخر الرقم.
 *    <bdi dir="ltr"> بتعزل الرقم وتخليه يتقرا زي ما هو.
 *    الدالة دي **للنص الجوّاني اللي مالوش كلاس** — اللي له كلاس
 *    متحلول من `fagala.css` (قاعدة الأرقام في آخر الملف).
 *
 * 🔴 مكانها هنا مش في `finance.php` عن قصد: `book_reserve.php`
 *    بيستعملها وهو مابيعملش require لـfinance.php، فالصفحة وقعت
 *    بـFatal على السيرفر (٢٠ سبتمبر ٢٠٢٦). `auth.php` بيتحمّل في
 *    كل صفحة، فالدالة متاحة في أي مكان.
 *
 * ⚠️ الحارس مش زيادة: `config.php` (مش في الريبو) بيعرّف دوال —
 *    وده وقّع إشراقة بـ«Cannot redeclare» ٦٧ مرة.
 */
if (!function_exists('ltr_out')) {
    function ltr_out($value): string
    {
        return '<bdi dir="ltr">' . e((string) $value) . '</bdi>';
    }
}
