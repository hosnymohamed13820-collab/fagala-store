<?php
// بريف ٨٣٢: كل التغييرات على حساب المستخدم اللي عامل دخول بس.
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';
api_begin();
require_login();
api_method('POST');
api_csrf();
$uid = (int) current_user()['id'];
$input = api_input();
$action = (string) ($input['action'] ?? '');
switch ($action) {
    case 'change_password':
        $result = my_account_change_password(db(), $uid,
            (string) ($input['current_password'] ?? ''),
            (string) ($input['new_password'] ?? ''),
            (string) ($input['new_password2'] ?? ''));
        break;
    case 'set_pin':
        $result = salary_pin_set(db(), $uid,
            (string) ($input['current_secret'] ?? ''),
            (string) ($input['new_pin'] ?? ''),
            (string) ($input['new_pin2'] ?? ''));
        break;
    case 'unlock_pin':
        $result = salary_pin_verify(db(), $uid, (string) ($input['pin'] ?? ''));
        break;
    case 'lock_pin':
        unset($_SESSION['salary_pin_until'], $_SESSION['salary_pin_hash'], $_SESSION['salary_pin_uid']);
        api_ok();
    default:
        api_fail('طلب غير صالح');
}
if (!$result['ok']) {
    api_fail($result['error'], (int) ($result['status'] ?? 400), $result['code'] ?? 'bad_request');
}
api_ok(['message' => $result['message'] ?? 'اتفتح']);
