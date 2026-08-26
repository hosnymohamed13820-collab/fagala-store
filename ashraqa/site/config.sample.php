<?php
/**
 * config.sample.php — نموذج ملف الإعدادات والاتصال بقاعدة البيانات
 * فرع إشراقة (Ashraqa)
 * ===========================================================================
 *  ⚠️ تنبيه مهم للأونر (محمد):
 *  الملف ده نموذج تجريبي فقط وفيه القيم فاضية.
 *  عشان تشغّل موقع إشراقة على السيرفر:
 *    1) املأ بيانات قاعدة بيانات إشراقة (اسم القاعدة، اليوزر، الباسورد) تحت.
 *    2) املأ بيانات الجسر أو اتصال SQL Server لو مستخدم.
 *    3) غيّر اسم الملف ده من config.sample.php إلى config.php على السيرفر.
 * ===========================================================================
 */

// ===== التوقيت: القاهرة (يتعامل مع التوقيت الصيفي والشتوي تلقائيًا) =====
date_default_timezone_set('Africa/Cairo');

// ===== بيئة التشغيل: 'hosting' للاستضافة (Hostinger) — 'local' للجهاز المحلي (XAMPP) =====
define('APP_ENV', 'hosting');   // <<< غيّرها: 'local' أو 'hosting'

// ===========================================================================
//  (أ) إعدادات MySQL (قاعدة بيانات إشراقة المنفصلة)
// ===========================================================================
if (APP_ENV === 'hosting') {
    // ----- بيانات الاستضافة (Hostinger) الخاصة بقاعدة بيانات إشراقة -----
    define('DB_HOST', 'localhost');
    define('DB_NAME', '');           // <<< اسم قاعدة بيانات إشراقة على هوستنجر (مثلاً: u834909335_ashraqa)
    define('DB_USER', '');           // <<< اسم مستخدم قاعدة البيانات
    define('DB_PASS', '');           // <<< كلمة سر قاعدة البيانات
} else {
    // ----- بيانات الجهاز المحلي (XAMPP) -----
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'ashraqa_store');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}
define('DB_CHARSET', 'utf8mb4');

// ===========================================================================
//  (ب) إعدادات الجسر وقاعدة بيانات الأصناف (SQL Server)
// ===========================================================================
if (APP_ENV === 'hosting') {
    // الاتصال عبر الجسر (bridge.php)
    define('USE_BRIDGE', false);     // فعّلها true لو هتستخدم الجسر لقراءة بيانات الأصناف
    define('BRIDGE_URL', '');        // <<< رابط الجسر (مثلاً: http://ashraqa.duckdns.org:8080/bridge.php)
    define('BRIDGE_KEY', '');        // <<< المفتاح السري للجسر (نفس الموجود في bridge.php)
    
    // جسر الكتابة (لو مستخدم لترحيل نقاط البيع مستقبلاً)
    define('BRIDGE_WRITE_URL', '');
    define('BRIDGE_WRITE_KEY', '');

    // إعدادات الاتصال المباشر (لو متاح اتصال IP مباشر بدون جسر)
    define('SQLSRV_HOST', '');
    define('SQLSRV_PORT', '1433');
    define('ODBC_DSN',  '');
    define('ODBC_USER', '');
    define('ODBC_PASS', '');
} else {
    // محلياً: عبر ODBC DSN
    define('USE_BRIDGE', false);
    define('BRIDGE_URL', '');
    define('BRIDGE_KEY', '');
    define('BRIDGE_WRITE_URL', '');
    define('BRIDGE_WRITE_KEY', '');
    define('SQLSRV_HOST', '');
    define('SQLSRV_PORT', '1433');
    define('ODBC_DSN',  '');         // <<< اسم DSN المعرف في ويندوز
    define('ODBC_USER', '');
    define('ODBC_PASS', '');
}

// ===== اسم قاعدة بيانات الأصناف الخاصة بالفرع في SQL Server =====
define('SQLSRV_DB',     'ReduxSalesPUB2');            // <<< قاعدة بيانات إشراقة (نظام Redux)
define('SQLSRV_PREFIX', SQLSRV_DB . '.dbo.');         // البادئة التلقائية للاستعلامات


// ===========================================================================
//  (ج) الدوال الأساسية التي يتطلبها النظام
// ===========================================================================

/**
 * دالة الاتصال بقاعدة بيانات MySQL عبر PDO (Singleton)
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // توحيد ترميز الجلسة مع ترميز الجداول
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            // ضبط التوقيت ليتطابق مع القاهرة تلقائياً
            $tzOffset = (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('P');
            $pdo->exec("SET time_zone = '" . $tzOffset . "'");
        } catch (PDOException $e) {
            error_log('DB connect failed: ' . $e->getMessage());
            http_response_code(503);
            die('تعذّر الاتصال بقاعدة البيانات. حاول مرة أخرى بعد قليل، ولو استمرت المشكلة تواصل مع مسؤول النظام.');
        }
    }
    return $pdo;
}

/**
 * قراءة إعداد من جدول settings مع كاش محلي في الذاكرة
 */
function setting(string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val === false) ? $default : $val;
    } catch (Throwable $ex) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * معرّف مسؤول متابعة النواقص من الإعدادات
 */
function shortage_supervisor_id(): int
{
    return (int) setting('shortage_supervisor_id', 0);
}

/**
 * الاتصال بقاعدة بيانات SQL Server عبر ODBC / PDO
 */
function sqlsrv_db(): PDO
{
    static $conn = null;
    if ($conn !== null) return $conn;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $drivers = PDO::getAvailableDrivers();

    if (APP_ENV === 'hosting') {
        if (defined('SQLSRV_HOST') && SQLSRV_HOST !== '' && SQLSRV_HOST !== '0.0.0.0') {
            if (in_array('sqlsrv', $drivers, true)) {
                $dsn = 'sqlsrv:Server=' . SQLSRV_HOST . ',' . SQLSRV_PORT . ';Database=' . SQLSRV_DB;
                $conn = new PDO($dsn, ODBC_USER, ODBC_PASS, $options);
                return $conn;
            }
            if (in_array('dblib', $drivers, true)) {
                $dsn = 'dblib:host=' . SQLSRV_HOST . ':' . SQLSRV_PORT . ';dbname=' . SQLSRV_DB;
                $conn = new PDO($dsn, ODBC_USER, ODBC_PASS, $options);
                return $conn;
            }
            if (in_array('odbc', $drivers, true)) {
                $cs = 'odbc:Driver={ODBC Driver 17 for SQL Server};Server=' . SQLSRV_HOST . ',' . SQLSRV_PORT
                    . ';Database=' . SQLSRV_DB . ';Uid=' . ODBC_USER . ';Pwd=' . ODBC_PASS . ';';
                $conn = new PDO($cs, null, null, $options);
                return $conn;
            }
            throw new RuntimeException('لا يوجد درايفر SQL Server متوفر على الاستضافة.');
        }
        throw new RuntimeException('إعدادات الاتصال المباشر بـ SQL Server غير مكتملة في config.php.');
    }

    if (!defined('ODBC_DSN') || ODBC_DSN === '') {
        throw new RuntimeException('إعداد ODBC_DSN فارغ في config.php.');
    }
    if (!in_array('odbc', $drivers, true)) {
        throw new RuntimeException('امتداد pdo_odbc غير مفعّل في PHP.');
    }
    $conn = new PDO('odbc:' . ODBC_DSN, ODBC_USER, ODBC_PASS, $options);
    return $conn;
}

/**
 * تنفيذ استعلام SELECT على SQL Server (مباشر أو عبر الجسر)
 */
function sqlsrv_query(string $sql, array $params = []): array
{
    if (defined('USE_BRIDGE') && USE_BRIDGE) {
        return bridge_query($sql, $params);
    }
    $st = sqlsrv_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll();
}

/**
 * إرسال استعلام للجسر واستقبال النتائج مع قاطع دائرة (Circuit Breaker)
 */
function bridge_query(string $sql, array $params = []): array
{
    static $down = null;
    if ($down !== null) {
        throw new RuntimeException($down);
    }

    if (!defined('BRIDGE_URL') || BRIDGE_URL === '' || !defined('BRIDGE_KEY') || BRIDGE_KEY === '') {
        $down = 'إعدادات الجسر (BRIDGE_URL / BRIDGE_KEY) غير معرّفة في config.php.';
        throw new RuntimeException($down);
    }

    $post = http_build_query([
        'key'    => BRIDGE_KEY,
        'sql'    => $sql,
        'params' => json_encode(array_values($params)),
    ]);

    $ch = curl_init(BRIDGE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['X-Bridge-Key: ' . BRIDGE_KEY],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = $raw === false ? '' : substr((string) $raw, 0, $hlen);
    $resp    = $raw === false ? false : substr((string) $raw, $hlen);

    if ($resp === false || $err) {
        $down = 'تعذّر الاتصال بجهاز الجسر: ' . $err . '. تأكد من تشغيل الجسر والاتصال بالإنترنت.';
        throw new RuntimeException($down);
    }
    $data = json_decode((string) $resp, true);
    if (!is_array($data)) {
        $extra = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $extra = ' → تحويل إلى: ' . trim($m[1]);
        }
        $body = trim(preg_replace('/\s+/', ' ', strip_tags((string) $resp)));
        if ($body !== '') $extra .= ' | الرد: ' . mb_substr($body, 0, 200);
        $down = 'رد غير صالح من الجسر (HTTP ' . $code . ')' . $extra;
        throw new RuntimeException($down);
    }
    if (empty($data['ok'])) {
        throw new RuntimeException('الجسر: ' . ($data['error'] ?? 'خطأ غير معروف'));
    }
    return $data['rows'] ?? [];
}

/**
 * تصحيح ترميز النصوص العربية من SQL Server (Windows-1256 إلى UTF-8)
 */
function sqlsrv_fix_encoding(?string $text): string
{
    if ($text === null || $text === '') return '';
    if (mb_check_encoding($text, 'UTF-8') && mb_detect_encoding($text, 'UTF-8', true)) {
        $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $text);
        if ($hasArabic || ctype_print($text)) return $text;
    }
    $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $text);
    if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
        return $converted;
    }
    return $text;
}

/**
 * فحص حالة اتصال SQL Server / الجسر
 */
function sqlsrv_check(): array
{
    try {
        sqlsrv_query('SELECT 1 AS ok');
        return [
            'ok' => true,
            'message' => defined('USE_BRIDGE') && USE_BRIDGE
                ? 'الاتصال بالجسر شغّال بنجاح.'
                : 'الاتصال بـ SQL Server شغّال بنجاح.'
        ];
    } catch (Throwable $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    }
}
