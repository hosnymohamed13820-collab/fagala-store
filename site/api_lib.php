<?php
/**
 * أساس الـAPI الموحّد (بريف ١٠١).
 *
 * الاستخدام في أي endpoint:
 *   define('API_MODE', true);
 *   require_once __DIR__ . '/auth.php';
 *   require_once __DIR__ . '/api_lib.php';
 *   api_begin();
 *   require_login();
 */

require_once __DIR__ . '/errlog_lib.php';

/**
 * قائمة الـ Origins المسموح بها في الـ API والتطبيق (بريف ١٠١ وبريف ١٠٤).
 *
 * @return string[]
 */
function api_allowed_origins_list(): array
{
    $allowedOriginsStr = 'tauri://localhost,https://tauri.localhost,capacitor://localhost,http://localhost';
    if (function_exists('setting')) {
        $allowedOriginsStr = setting('api_allowed_origins', $allowedOriginsStr);
    }
    return array_map('trim', explode(',', (string) $allowedOriginsStr));
}

/**
 * تهيئة بيئة الـAPI: الهيدرز، ومعالج الاستثناءات، والإغلاق الآمن.
 */
function api_begin(): void
{
    // فحص ومعالجة CORS
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null && $origin !== '') {
        $allowedList = api_allowed_origins_list();
        if (in_array($origin, $allowedList, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-CSRF-Token');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 600');

            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                http_response_code(204);
                exit;
            }
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    // ⚠️ قواعد errlog_lib.php بتقول «مفيش set_exception_handler» — ده للصفحات العادية.
    // هنا جوه api_begin() بس، اللي بتتنده من endpoints الـAPI بس عشان نضمن رد JSON دايماً.
    set_exception_handler(function (Throwable $e) {
        if (function_exists('errlog_record')) {
            errlog_record('php', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'حصل خطأ في السيرفر',
            'code'  => 'server',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (function_exists('errlog_record')) {
                errlog_record('php', $err['message'], $err['file'], $err['line']);
            }
            if (!headers_sent()) {
                http_response_code(500);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'حصل خطأ في السيرفر',
                    'code'  => 'server',
                ], JSON_UNESCAPED_UNICODE);
            }
        }
    });
}

/**
 * إنهاء الطلب بنجاح.
 */
function api_ok(mixed $data = null): never
{
    echo json_encode([
        'ok'   => true,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * إنهاء الطلب بفشل مع كود HTTP ورسالة بالعامية.
 */
function api_fail(string $msg, int $http = 400, string $code = 'bad_request'): never
{
    http_response_code($http);
    echo json_encode([
        'ok'    => false,
        'error' => $msg,
        'code'  => $code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * التحقق من صلاحية المستخدم للـAPI.
 */
function api_require(string $perm): void
{
    if (!site_permission_enabled($perm)) api_fail('مالكش صلاحية', 403, 'forbidden');
    if (!can($perm)) {
        api_fail('مالكش صلاحية', 403, 'forbidden');
    }
    if (view_all_borrowed($perm)) {
        $GLOBALS['VIEW_ALL_PAGE'] = true;
    }
}

/**
 * التحقق من طريقة الطلب (GET, POST, إلخ).
 */
function api_method(string ...$allowed): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $upperAllowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $upperAllowed, true)) {
        api_fail('طريقة الطلب غير مدعومة', 405, 'bad_request');
    }
}

/**
 * التحقق من توكن CSRF لأي طريقة كتابة (POST, PUT, DELETE, إلخ).
 */
function api_csrf(): void
{
    if (defined('API_BEARER')) {
        return;
    }
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
    if (!$token || !csrf_verify((string) $token)) {
        api_fail('انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني', 403, 'forbidden');
    }
}

/**
 * قراءة مدخلات الطلب (JSON أو POST).
 */
function api_input(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input', false, null, 0, 1048576); // حد أقصى 1 ميجا
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return is_array($_POST) ? $_POST : [];
}

/**
 * حماية العمليات من التكرار باستخدام هيدر Idempotency-Key.
 */
function api_idempotent(): void
{
    $rawKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($rawKey === '') {
        return;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $keyHash = hash('sha256', $userId . '|' . $rawKey);

    // تنظيف قديم بنسبة 1%
    if (random_int(1, 100) === 1) {
        try {
            db()->exec("DELETE FROM api_idempotency WHERE created_at < NOW() - INTERVAL 2 DAY LIMIT 2000");
        } catch (Throwable) {}
    }

    try {
        $st = db()->prepare("SELECT status, response FROM api_idempotency WHERE key_hash = ?");
        $st->execute([$keyHash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        // لو الجدول مش موجود أو حصل خطأ في القاعدة، نكمل عادي بدون حماية
        return;
    }

    if ($row) {
        if ($row['response'] !== null) {
            $code = $row['status'] ? (int) $row['status'] : 200;
            http_response_code($code);
            header('Idempotent-Replay: true');
            echo $row['response'];
            exit;
        }
        // ما زالت العملية شغالة
        api_fail('العملية دي لسه شغالة', 409, 'duplicate');
    }

    // غير موجود: إدراج سجل جديد فارغ
    try {
        $ins = db()->prepare(
            "INSERT INTO api_idempotency (key_hash, user_id, created_at, status, response)
             VALUES (?, ?, NOW(), NULL, NULL)"
        );
        $ins->execute([$keyHash, $userId]);
    } catch (PDOException $pe) {
        // إذا فشل الإدراج بسبب تكرار المفتاح الأساسي في نفس اللحظة
        if ((int) $pe->getCode() === 23000 || (int) ($pe->errorInfo[1] ?? 0) === 1062) {
            api_fail('العملية دي لسه شغالة', 409, 'duplicate');
        }
        // لو الخطأ سببه عدم وجود الجدول، نكمل عادي
        return;
    }

    // بدء التقاط الرد وتخزينه عند الانتهاء
    ob_start();
    register_shutdown_function(function () use ($keyHash) {
        $resp = ob_get_contents();
        $status = http_response_code() ?: 200;
        try {
            $up = db()->prepare(
                "UPDATE api_idempotency SET status = ?, response = ? WHERE key_hash = ?"
            );
            $up->execute([$status, $resp, $keyHash]);
        } catch (Throwable) {}
    });
}
