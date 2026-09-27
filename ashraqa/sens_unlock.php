<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة الطلب غير مسموح بها'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (!csrf_verify($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'انتهت الجلسة، اعمل تحديث للصفحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'lock') {
    unset($_SESSION['sens_until']);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'unlock') {
    $now = time();
    $blockUntil = (int) ($_SESSION['sens_block_until'] ?? 0);
    if ($blockUntil > $now) {
        $waitMin = max(1, (int) ceil(($blockUntil - $now) / 60));
        echo json_encode(['ok' => false, 'error' => 'استنى ' . $waitMin . ' دقيقة وحاول تاني'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $u = current_user();
    $uid = (int) ($u['id'] ?? 0);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$uid]);
    $hash = $stmt->fetchColumn();

    $pwd = (string) ($_POST['password'] ?? '');

    if (!$hash || !password_verify($pwd, (string) $hash)) {
        $fails = ((int) ($_SESSION['sens_fails'] ?? 0)) + 1;
        if ($fails >= SENS_MAX_FAILS) {
            $_SESSION['sens_block_until'] = $now + SENS_BLOCK_SECONDS;
            $_SESSION['sens_fails'] = 0;
        } else {
            $_SESSION['sens_fails'] = $fails;
        }
        echo json_encode(['ok' => false, 'error' => 'الباسورد غلط'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // صح
    $_SESSION['sens_fails'] = 0;
    unset($_SESSION['sens_block_until']);
    $_SESSION['sens_until'] = $now + SENS_IDLE_SECONDS;
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'طلب غير صالح'], JSON_UNESCAPED_UNICODE);
exit;
