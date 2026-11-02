<?php
/** API كارت الصنف — بريف ٢٣١. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/item_master_lib.php';

$part = (string) ($_GET['part'] ?? $_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    switch ($part) {
        case 'next_code':
            api_require('item_master:edit');
            $sectionId = (int) ($_GET['section'] ?? $_GET['section_id'] ?? 0);
            api_ok(['code' => im_next_sys_code(db(), $sectionId, false)]);
        case 'lookup':
            api_require('item_master:view');
            api_ok(['match' => im_lookup((string) ($_GET['code'] ?? ''))]);
        case 'search':
            api_require('item_master:view');
            api_ok(im_search((string) ($_GET['q'] ?? ''), (int) ($_GET['page'] ?? 1)));
        case 'detail':
            api_require('item_master:view');
            api_ok(['item' => im_detail((string) ($_GET['source'] ?? ''), (int) ($_GET['id'] ?? 0))]);
        case 'categories':
            // اختيارات التصنيفات الخمسة — لازم تتفتح لأي حد عنده view (كارت الصنف
            // محتاجها في الفورم)، مش صلاحية «الأقسام والتصنيفات».
            api_require('item_master:view');
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
            $itemId = im_create($input, $userId, can('item_master:prices'));
            $st = db()->prepare('SELECT sys_code FROM item_master WHERE id = ?');
            $st->execute([$itemId]);
            $savedCode = (string) $st->fetchColumn();
            api_ok(['id' => $itemId, 'code' => $savedCode]);
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
