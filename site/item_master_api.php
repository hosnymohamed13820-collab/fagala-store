<?php
/** API كارت الصنف — بريف ٢٣١. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/item_master_lib.php';

$part = (string) ($_GET['part'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    api_require('item_master:view');
    switch ($part) {
        case 'lookup':
            api_ok(['match' => im_lookup((string) ($_GET['code'] ?? ''))]);
        case 'search':
            api_ok(im_search((string) ($_GET['q'] ?? ''), (int) ($_GET['page'] ?? 1)));
        case 'detail':
            api_ok(['item' => im_detail((string) ($_GET['source'] ?? ''), (int) ($_GET['id'] ?? 0))]);
        case 'categories':
            // اختيارات التصنيفات الخمسة — لازم تتفتح لأي حد عنده view (كارت الصنف
            // محتاجها في الفورم)، مش صلاحية «الأقسام والتصنيفات».
            api_ok(im_class_options());
        default:
            api_fail('جزء غير معروف', 400, 'bad_request');
    }
}

api_method('POST');
api_csrf();
api_idempotent();
$input = api_input();
$userId = (int) (current_user()['id'] ?? 0);

try {
    switch ($part) {
        case 'create':
            api_require('item_master:edit');
            api_ok(['id' => im_create($input, $userId, can('item_master:prices'))]);
        case 'update':
            api_require('item_master:edit');
            im_update_field((int) ($input['id'] ?? 0), (string) ($input['field'] ?? ''), $input['value'] ?? null, $userId);
            api_ok(['saved' => true]);
        case 'code_save':
            api_require('item_master:edit');
            api_ok(['id' => im_save_code((int) ($input['item_id'] ?? 0), (int) ($input['code_id'] ?? 0), $input)]);
        case 'price':
            api_require('item_master:prices');
            if (!isset($input['price']) || !is_numeric($input['price'])) {
                api_fail('اكتب سعر صحيح', 400, 'bad_request');
            }
            im_change_price((string) ($input['code'] ?? ''), (float) $input['price'], (string) ($input['reason'] ?? ''), $userId);
            api_ok(['saved' => true]);
        default:
            api_fail('جزء غير معروف', 400, 'bad_request');
    }
} catch (DomainException $e) {
    api_fail($e->getMessage(), 409, 'duplicate');
} catch (ImForbiddenException $e) {
    api_fail($e->getMessage(), 403, 'forbidden');
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
}
