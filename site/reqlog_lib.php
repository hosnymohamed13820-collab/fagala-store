<?php
/**
 * reqlog_lib.php — سجل قياس سرعة وأداء الصفحات (بريف ٩٩)
 * نظام الفجالة ستور المالي
 *
 * القواعد:
 * ١) عمره ما يوقّع صفحة — كل حاجة جوّه try/catch صامت.
 * ٢) مابيغيّرش سلوك أي صفحة — مابيطبعش، ومابيلمسش هيدرز.
 * ٣) مفيش أي بيانات حساسة — اسم الملف المجرّد فقط، بدون مدخلات.
 */
declare(strict_types=1);

/**
 * تركيب مسجّل الأداء عند إغلاق الريكوست — مرة واحدة مهما اتندهت
 */
function reqlog_install(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;

    register_shutdown_function('reqlog_shutdown');
}

/**
 * كتابة سطر الأداء في نهاية تنفيذ الصفحة
 */
function reqlog_shutdown(): void
{
    try {
        $reqTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $ms = round((microtime(true) - $reqTime) * 1000, 2);
        $mb = round(memory_get_peak_usage(true) / 1048576, 2);

        $status = http_response_code();
        if ($status === false || $status === 0) {
            $status = 200;
        }

        $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?: basename((string) ($_SERVER['PHP_SELF'] ?? 'unknown'));
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uid = '';
        if (isset($_SESSION) && is_array($_SESSION) && !empty($_SESSION['user']['id'])) {
            $uid = (string) (int) $_SESSION['user']['id'];
        }

        // توقيت القاهرة الصريح مش دالة الوقت الافتراضية
        $nowCairo = new \DateTime('now', new \DateTimeZone('Africa/Cairo'));
        $date = $nowCairo->format('Y-m-d');
        $time = $nowCairo->format('H:i:s');

        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) return;
        }

        if (!function_exists('errlog_guard_dir')) {
            @require_once __DIR__ . '/errlog_lib.php';
        }
        if (function_exists('errlog_guard_dir')) {
            errlog_guard_dir();
        } else {
            $idx = $dir . '/index.php';
            if (!is_file($idx)) @file_put_contents($idx, "<?php http_response_code(404); exit;\n");
            $ht = $dir . '/.htaccess';
            if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
        }

        $logFile = $dir . '/perf-' . $date . '.log.php';
        $isNew = !is_file($logFile) || filesize($logFile) === 0;

        if ($isNew) {
            reqlog_cleanup($dir);
        }

        // TSV: HH:MM:SS \t page \t method \t ms \t mb \t status \t uid
        $row = $time . "\t" . $page . "\t" . $method . "\t" . $ms . "\t" . $mb . "\t" . $status . "\t" . $uid . "\n";

        if ($isNew) {
            $guard = defined('ERRLOG_GUARD') ? ERRLOG_GUARD : "<?php http_response_code(404); exit; ?>\n";
            $row = $guard . $row;
        }

        @file_put_contents($logFile, $row, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $ex) {
        // صامت تماماً — السجل عمره ما يوقّع صفحة
    }

    // سجل العمليات (بريف ١٠٠) — محاولات الكتابة للمستخدمين المسجلين
    try {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $userId = $_SESSION['user']['id'] ?? null;
        if (!$userId || !is_numeric($userId)) {
            return;
        }
        $userId = (int) $userId;

        $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?: basename((string) ($_SERVER['PHP_SELF'] ?? 'unknown'));

        // استثناء الصفحات المحددة
        if (str_starts_with($page, 'sync_')) {
            return;
        }
        $excludedPages = [
            'push_worker.php',
            'guard_feed.php',
            'guard_note.php',
            'sms_hook.php',
            'print_pull.php',
            'scan_pull.php',
            'errors.php',
            'notifications_read.php',
        ];
        if (in_array($page, $excludedPages, true)) {
            return;
        }

        // اسم العملية action
        $rawAction = $_POST['action'] ?? $_POST['act'] ?? $_GET['action'] ?? null;
        $action = null;
        if ($rawAction !== null && $rawAction !== '') {
            $action = mb_substr((string) $rawAction, 0, 80);
        }

        // تجهيز المدخلات params مع إخفاء البيانات الحساسة
        $paramsStr = reqlog_collect_params();

        // بيانات المستخدم والطلب
        $username = isset($_SESSION['user']['username']) ? (string) $_SESSION['user']['username'] : null;
        if ($username !== null) {
            $username = mb_substr($username, 0, 60);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;

        $reqTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $ms = (int) round((microtime(true) - $reqTime) * 1000);

        $status = http_response_code();
        if ($status === false || $status === 0) {
            $status = 200;
        }

        $nowCairo = new \DateTime('now', new \DateTimeZone('Africa/Cairo'));
        $createdAt = $nowCairo->format('Y-m-d H:i:s');

        // الحفظ في قاعدة البيانات بصمت تام
        if (function_exists('db')) {
            $pdo = db();
            if ($pdo instanceof \PDO) {
                $st = $pdo->prepare(
                    "INSERT INTO audit_requests (created_at, user_id, username, ip, method, page, action, params, status, ms) " .
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $st->execute([$createdAt, $userId, $username, $ip, $method, $page, $action, $paramsStr, (int) $status, $ms]);

                // تنظيف قديم بنسبة ١٪ من الطلبات
                if (random_int(1, 100) === 1) {
                    $pdo->exec("DELETE FROM audit_requests WHERE created_at < NOW() - INTERVAL 180 DAY LIMIT 5000");
                }
            }
        }
    } catch (\Throwable $ex) {
        // سكوت تام
    }
}

/**
 * تنظيف ملفات الأداء الأقدم من ١٤ يوم
 */
function reqlog_cleanup(string $dir): void
{
    try {
        $threshold = (new \DateTime('-14 days', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');
        $files = @glob($dir . '/perf-*.log.php');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (preg_match('/perf-(\d{4}-\d{2}-\d{2})\.log\.php$/', $f, $m)) {
                    if ($m[1] < $threshold) {
                        @unlink($f);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // صامت
    }
}

/**
 * جمع وتجهيز مدخلات الطلب مع تطبيق قواعد إخفاء البيانات الحساسة (بريف ١٠٠)
 */
function reqlog_collect_params(): ?string
{
    $data = [];

    // قراءة php://input إذا كان الطلب JSON وأقل من 8KB
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($rawLen <= 8192) {
            $raw = @file_get_contents('php://input');
            if ($raw !== false && strlen($raw) <= 8192 && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
    }

    // إذا لم تكن البيانات مأخوذة من JSON، نأخذ نسخة من $_POST
    if (empty($data) && !empty($_POST)) {
        $data = $_POST;
    }

    // إضافة معلومات الملفات المرفوعة $_FILES
    if (!empty($_FILES)) {
        $filesInfo = reqlog_collect_files($_FILES);
        if (!empty($filesInfo)) {
            $data['_FILES'] = $filesInfo;
        }
    }

    if (empty($data)) {
        return null;
    }

    $sanitized = reqlog_sanitize_value($data);

    $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return null;
    }

    if (mb_strlen($json) > 6000) {
        $json = mb_substr($json, 0, 6000) . '…';
    }

    return $json;
}

/**
 * إخفاء وتنظيف مصفوفات أو قيم المدخلات:
 * - أي مفتاح فيه pass|token|csrf|secret|pin (case-insensitive) ← '***'
 * - النصوص تقص إلى 300 حرف
 * - المصفوفات تقص إلى أول 50 عنصر
 */
function reqlog_sanitize_value(mixed $value, ?string $keyName = null): mixed
{
    if ($keyName !== null && preg_match('/pass|pwd|token|csrf|secret|pin|otp|api_?key/i', $keyName)) {
        return '***';
    }

    if (is_array($value)) {
        $cleaned = [];
        $count = 0;
        foreach ($value as $k => $v) {
            if ($count >= 50) {
                break;
            }
            $cleaned[$k] = reqlog_sanitize_value($v, is_string($k) ? $k : null);
            $count++;
        }
        return $cleaned;
    }

    if (is_string($value)) {
        return mb_substr($value, 0, 300);
    }

    return $value;
}

/**
 * استخراج حقول وأسماء الملفات المرفوعة من $_FILES
 */
function reqlog_collect_files(array $files): array
{
    $out = [];
    $count = 0;
    foreach ($files as $field => $item) {
        if ($count >= 50) {
            break;
        }
        if (is_array($item)) {
            if (isset($item['name'])) {
                if (is_array($item['name'])) {
                    $names = [];
                    foreach (array_slice($item['name'], 0, 50) as $n) {
                        $names[] = mb_substr((string) $n, 0, 300);
                    }
                    $out[$field] = $names;
                } else {
                    $out[$field] = mb_substr((string) $item['name'], 0, 300);
                }
            } else {
                $out[$field] = 'file';
            }
        }
        $count++;
    }
    return $out;
}