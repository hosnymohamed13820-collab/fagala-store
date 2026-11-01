<?php
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
api_method('POST');
api_begin();
api_csrf();
$input = api_input();
$action = (string) ($input['action'] ?? '');
try {
    if ($action === 'request') {
        api_ok(pwreset_request(db(), (string) ($input['username'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    }
    if ($action === 'redeem') {
        $result = pwreset_redeem(db(), (string) ($input['username'] ?? ''), (string) ($input['reset_otp'] ?? ''),
            (string) ($input['new_password'] ?? ''), (string) ($input['new_password2'] ?? ''));
        if (!$result['ok']) api_fail($result['error']);
        api_ok($result);
    }
    require_login();
    pwreset_manager(db());
    switch ($action) {
        case 'list': api_ok(pwreset_list(db(), (int) ($input['page'] ?? 1)));
        case 'approve': api_ok(pwreset_decide(db(), (int) ($input['id'] ?? 0), true));
        case 'reject': api_ok(pwreset_decide(db(), (int) ($input['id'] ?? 0), false));
        default: api_fail('طلب غير صالح');
    }
} catch (RuntimeException $e) {
    if (in_array($e->getCode(), [403, 409], true)) api_fail($e->getMessage(), $e->getCode(), $e->getCode() === 403 ? 'forbidden' : 'bad_request');
    throw $e;
}
