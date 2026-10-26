<?php
/** API الأسعار الجماعية — بريف ٢٣٣. */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/item_prices_lib.php';

$part = (string) ($_GET['part'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (int) (current_user()['id'] ?? 0);

try {
    if ($method === 'GET') {
        api_require('sale_prices:view');
        switch ($part) {
            case 'meta':
                api_ok(ipp_meta());
            case 'preview':
                api_ok(ipp_preview_page((string) ($_GET['token'] ?? ''), (int) ($_GET['page'] ?? 1), $userId));
            case 'export':
                ipp_export_preview((string) ($_GET['token'] ?? ''), $userId);
            case 'batches':
                api_ok(ipp_batches((int) ($_GET['page'] ?? 1)));
            case 'batch':
                api_ok(ipp_batch_detail((int) ($_GET['id'] ?? 0), (int) ($_GET['page'] ?? 1)));
            default:
                api_fail('جزء غير معروف', 400, 'bad_request');
        }
    }

    api_method('POST');
    if ($part === 'preview') {
        api_require('sale_prices:view');
    } elseif ($part === 'apply') {
        api_require('sale_prices:apply');
    } else {
        api_fail('جزء غير معروف', 400, 'bad_request');
    }
    api_csrf();
    $input = api_input();
    if ($part === 'preview') {
        api_ok(ipp_create_preview($input, $userId));
    }
    if ($part === 'apply') {
        api_idempotent();
        api_ok(ipp_apply_preview((string) ($input['token'] ?? ''), $userId));
    }
    api_fail('جزء غير معروف', 400, 'bad_request');
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
} catch (JsonException) {
    api_fail('تعذّر تثبيت لقطة المعاينة', 500, 'server');
}
