<?php
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
if (!is_real_owner()) api_fail('الطلبات دي للمالك بس.', 403, 'forbidden');
require_once __DIR__ . '/ui_edits_lib.php';

$action = (string) ($_GET['action'] ?? 'list');
try {
    switch ($action) {
        case 'list':
            api_method('GET');
            api_ok(ui_edits_list((int) ($_GET['page'] ?? 1)));
        case 'get':
            api_method('GET');
            api_ok(['request' => ui_edits_get((int) ($_GET['id'] ?? 0))]);
        case 'save':
            api_method('POST');
            api_csrf();
            api_idempotent();
            api_ok(['id' => ui_edits_save(api_input(), (int) current_user()['id'])]);
        case 'set_status':
            api_method('POST');
            api_csrf();
            api_idempotent();
            $input = api_input();
            api_ok(['found' => ui_edits_set_status((int) ($input['id'] ?? 0), (string) ($input['status'] ?? ''))]);
        default:
            api_fail('الطلب ده مش معروف.', 400, 'bad_request');
    }
} catch (InvalidArgumentException $e) {
    // دي رسايل التحقق المكتوبة عندنا بس، مش تفاصيل أخطاء قاعدة البيانات.
    api_fail('بيانات الطلب مش صحيحة. راجع العنوان والمقاسات وأنواع المكتبة.', 400, 'bad_request');
}
