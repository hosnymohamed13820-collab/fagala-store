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