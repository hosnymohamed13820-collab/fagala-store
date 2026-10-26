<?php
/**
 * API الأقسام والتصنيفات (بريف ٢٣٤).
 *
 *   ?part=list&kind=section        ← {kinds, rows}   (GET)
 *   ?part=options                  ← {kinds, options} (GET — كارت الصنف بيستخدمها)
 *   ?part=create                   ← {id,name}       (POST: kind · name)
 *   ?part=rename                   ← {name}          (POST: id · name)
 *   ?part=toggle                   ← {is_active}     (POST: id · is_active)
 *
 * الصلاحية: `item_master:classes` = «الأقسام والتصنيفات».
 * أي تصنيف جاي من True (true_id مش NULL) = عرض بس — المحاولة بترجع 409.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/item_classes_lib.php';

$part = (string) ($_GET['part'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    api_require('item_master:classes');
    try {
        switch ($part) {
            case 'list':
                $kind = (string) ($_GET['kind'] ?? 'section');
                api_ok(['kind' => $kind, 'kinds' => ic_kinds(), 'rows' => ic_list($kind)]);
            case 'options':
                api_ok(['kinds' => ic_kinds(), 'options' => ic_options()]);
            default:
                api_fail('جزء غير معروف', 400, 'bad_request');
        }
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage(), 400, 'bad_request');
    }
}

api_method('POST');
api_csrf();
api_idempotent();
api_require('item_master:classes');
$input = api_input();
$userId = (int) (current_user()['id'] ?? 0);

try {
    switch ($part) {
        case 'create':
            $kind = (string) ($input['kind'] ?? '');
            $name = ic_clean_name($input['name'] ?? '');
            api_ok(['id' => ic_create($kind, $name, $userId), 'name' => $name]);
        case 'rename':
            $name = ic_rename((int) ($input['id'] ?? 0), $input['name'] ?? '', $userId);
            api_ok(['name' => $name]);
        case 'toggle':
            $active = !empty($input['is_active']);
            ic_toggle((int) ($input['id'] ?? 0), $active, $userId);
            api_ok(['is_active' => $active ? 1 : 0]);
        default:
            api_fail('جزء غير معروف', 400, 'bad_request');
    }
} catch (DomainException $e) {
    api_fail($e->getMessage(), 409, 'duplicate');
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
}
