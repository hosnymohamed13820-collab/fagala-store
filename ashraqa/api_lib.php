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

