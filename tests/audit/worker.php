<?php
/**
 * worker.php — مشغّل الرندر المعزول للزاحف (بريف ١٨٢)
 * كل رندر في process مستقل تماماً لعزل الـ exit / die والسيشن
 * يستقبل معاملات التشغيل كـ base64_encoded JSON لتجنب مشاكل الـ shell quoting
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rawArg = $argv[1] ?? '';
$cfg = json_decode(base64_decode($rawArg), true);
if (!is_array($cfg)) {
    fwrite(STDERR, "Invalid worker config payload\n");
    exit(2);
}

$root        = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds2';
$page        = $cfg['page'] ?? '';
$userType    = $cfg['user_type'] ?? 'owner_locked';
$userId      = (int) ($cfg['user_id'] ?? 1);
$unlocked    = !empty($cfg['unlocked']);
$qs          = (string) ($cfg['qs'] ?? '');
$method      = strtoupper((string) ($cfg['method'] ?? 'GET'));
$postBody    = (string) ($cfg['post'] ?? '');
$metaOutFile = (string) ($cfg['meta'] ?? '');
$bodyOutFile = (string) ($cfg['body'] ?? '');

if ($page === '' || !is_file("$root/$page")) {
    fwrite(STDERR, "Page not found: $root/$page\n");
    if ($metaOutFile !== '') {
        file_put_contents($metaOutFile, json_encode([
            'status_code' => 404,
            'error'       => "Page not found: $page",
        ], JSON_UNESCAPED_UNICODE));
    }
    exit(2);
}

chdir($root);
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['PHP_SELF']    = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page . ($qs !== '' ? '?' . $qs : '');
$_SERVER['REQUEST_METHOD'] = $method;

parse_str($qs, $_GET);
if ($method === 'POST' && $postBody !== '') {
    parse_str($postBody, $_POST);
} else {
    $_POST = [];
}

// 1. بدء السيشن فوراً بهوية مستخدم الاختبار قبل أي require
$cleanType = preg_replace('/[^a-zA-Z0-9-]/', '', $userType);
session_id('audit-' . $cleanType . ($unlocked ? '-u1' : '-u0'));
session_start();
$_SESSION['app'] = getenv('RUN_APP') ?: 'fagala';

if ($unlocked) {
    $_SESSION['sens_until'] = time() + 3600;
} else {
    unset($_SESSION['sens_until']);
}

// 2. الاتصال بقاعدة البيانات لجلب بيانات المستخدم الفعلية وصلاحياته
require_once "$root/config.php";
$pdo = db();

$uStmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.role_id, u.branch_id, u.perms_version, u.pw_version, u.is_active, r.is_owner
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
");
$uStmt->execute([$userId]);
$userData = $uStmt->fetch(PDO::FETCH_ASSOC);

if ($userData) {
    $_SESSION['user'] = [
        'id'            => (int) $userData['id'],
        'username'      => (string) $userData['username'],
        'full_name'     => (string) $userData['full_name'],
        'role_id'       => (int) $userData['role_id'],
        'branch_id'     => $userData['branch_id'] !== null ? (int) $userData['branch_id'] : null,
        'is_owner'      => (int) $userData['is_owner'] === 1,
        'perms_version' => (int) ($userData['perms_version'] ?? 0),
        'pw_version'    => (int) ($userData['pw_version'] ?? 0),
    ];
} else {
    $_SESSION['user'] = [
        'id'            => $userId,
        'username'      => 'audit_' . $userType,
        'full_name'     => 'Audit User',
        'role_id'       => 0,
        'branch_id'     => null,
        'is_owner'      => false,
        'perms_version' => 0,
        'pw_version'    => 0,
    ];
}

// جلب الصلاحيات الفعلية المطبقة
try {
    $pStmt = $pdo->prepare("
        SELECT module_key FROM user_permissions WHERE user_id = ?
        UNION
        SELECT rp.module_key FROM role_permissions rp JOIN users u ON u.role_id = rp.role_id WHERE u.id = ?
    ");
    $pStmt->execute([$userId, $userId]);
    $_SESSION['permissions'] = $pStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $pStmt = $pdo->prepare("SELECT module_key FROM user_permissions WHERE user_id = ?");
    $pStmt->execute([$userId]);
    $_SESSION['permissions'] = $pStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// التقاط الأخطاء والتحذيرات
$capturedErrors = [];
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) use (&$capturedErrors) {
    $typeStr = match($errno) {
        E_WARNING, E_USER_WARNING       => 'Warning',
        E_NOTICE, E_USER_NOTICE         => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
        default                         => 'Error (' . $errno . ')',
    };
    $capturedErrors[] = [
        'type'    => $typeStr,
        'message' => $errstr,
        'file'    => str_replace('\\', '/', $errfile),
        'line'    => $errline,
    ];
    return false;
});

// قراءة حالة الاستعلامات والكتابة قبل تنفيذ الصفحة
function fetchDbSessionMetrics(PDO $p): array {
    try {
        $st = $p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Questions', 'Com_insert', 'Com_update', 'Com_delete', 'Com_replace')");
        $m = ['Questions' => 0, 'Com_insert' => 0, 'Com_update' => 0, 'Com_delete' => 0, 'Com_replace' => 0];
        foreach ($st as $row) {
            $m[$row['Variable_name']] = (int) $row['Value'];
        }
        return $m;
    } catch (Throwable $e) {
        return ['Questions' => 0, 'Com_insert' => 0, 'Com_update' => 0, 'Com_delete' => 0, 'Com_replace' => 0];
    }
}

$metricsBefore = fetchDbSessionMetrics($pdo);
$timeStart = hrtime(true);

register_shutdown_function(function() use ($pdo, $metricsBefore, $timeStart, $metaOutFile, $bodyOutFile, &$capturedErrors, $page) {
    try {
        $timeEnd = hrtime(true);
        $timeMs = round(($timeEnd - $timeStart) / 1e6, 2);

        $html = ob_get_clean();
        $bytes = strlen($html);

        $fatal = null;
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $fatal = [
                'type'    => 'Fatal',
                'message' => $e['message'],
                'file'    => str_replace('\\', '/', $e['file']),
                'line'    => $e['line'],
            ];
            $capturedErrors[] = $fatal;
        }

        // قراءة سجل أخطاء errlog_lib في حال قام باستبدال الـ error_handler
        if (function_exists('errlog_buffer')) {
            foreach (errlog_buffer() as $logged) {
                $capturedErrors[] = [
                    'type'    => $logged['type'] ?? 'Error',
                    'message' => $logged['msg'] ?? '',
                    'file'    => $logged['file'] ?? '',
                    'line'    => $logged['line'] ?? 0,
                ];
            }
        }

        $statusCode = http_response_code() ?: 200;
        $headers = headers_list();
        $location = null;
        foreach ($headers as $h) {
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }

        $metricsAfter = fetchDbSessionMetrics($pdo);
        $questions = max(0, $metricsAfter['Questions'] - $metricsBefore['Questions'] - 1);
        $inserts   = max(0, $metricsAfter['Com_insert']  - $metricsBefore['Com_insert']);
        $updates   = max(0, $metricsAfter['Com_update']  - $metricsBefore['Com_update']);
        $deletes   = max(0, $metricsAfter['Com_delete']  - $metricsBefore['Com_delete']);
        $replaces  = max(0, $metricsAfter['Com_replace'] - $metricsBefore['Com_replace']);
        $writes    = $inserts + $updates + $deletes + $replaces;

        $meta = [
            'page'        => $page,
            'status_code' => $statusCode,
            'location'    => $location,
            'time_ms'     => $timeMs,
            'bytes'       => $bytes,
            'questions'   => $questions,
            'writes'      => $writes,
            'writes_detail' => [
                'insert'  => $inserts,
                'update'  => $updates,
                'delete'  => $deletes,
                'replace' => $replaces,
            ],
            'errors'      => $capturedErrors,
            'fatal'       => $fatal,
        ];

        if ($bodyOutFile !== '') {
            file_put_contents($bodyOutFile, $html);
        }
        if ($metaOutFile !== '') {
            file_put_contents($metaOutFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $ex) {
        fwrite(STDERR, "Shutdown error: " . $ex->getMessage() . "\n");
    }
});

ob_start();
require "$root/$page";
