<?php
/**
 * customers_api.php — API موديول العملاء (بيانات + كشف حساب + رقم العميل مربوط بكل حاجة)
 * نظام الفجالة ستور المالي
 *
 * النمط الموحّد:
 *   API_MODE · api_begin · require_login · api_method · api_require · api_csrf
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/customers_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

// تصدير كشف الحساب إكسيل يتعالج قبل api_begin() لتفادي قفل رد الـJSON
if ($part === 'statement' && (($_GET['format'] ?? '') === 'xlsx' || ($_GET['export'] ?? '') === 'xlsx')) {
    require_login();
    if (!can('customers:statement') && !can('customers:view')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('مالكش صلاحية');
    }
    if (view_all_borrowed('customers:statement') || view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
        $GLOBALS['VIEW_ALL_PAGE'] = true;
    }
    api_method('GET');
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $data = cust_statement($pdo, $id);
        cust_export_statement_xlsx($data);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit($e->getMessage());
    } catch (RuntimeException $e) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit($e->getMessage());
    }
}

api_begin();
require_login();

$pdo = db();

switch ($part) {
    case 'search':
        api_method('GET');
        if (!can('customers:view') && !can('pos:sell')) {
            api_fail('مالكش صلاحية', 403, 'forbidden');
        }
        if (view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
            $GLOBALS['VIEW_ALL_PAGE'] = true;
        }

        $page = (int) ($_GET['page'] ?? 1);
        $filters = [
            'q'         => (string) ($_GET['q'] ?? $_GET['term'] ?? ''),
            'branch_id' => isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? (int) $_GET['branch_id'] : null,
            'kind'      => (string) ($_GET['kind'] ?? ''),
            'is_active' => isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (string) $_GET['is_active'] : '',
        ];

        try {
            $res = cust_search($pdo, $filters, $page, 50);
            $res['branches'] = cust_branches($pdo);
            api_ok($res);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 403, 'forbidden');
        }

    case 'get':
        api_method('GET');
        if (!can('customers:view') && !can('pos:sell')) {
            api_fail('مالكش صلاحية', 403, 'forbidden');
        }
        if (view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
            $GLOBALS['VIEW_ALL_PAGE'] = true;
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            api_fail('معرّف العميل مطلوب.', 400, 'bad_request');
        }

        $cust = cust_get($pdo, $id);
        if (!$cust) {
            api_fail('العميل مش موجود أو مالكش صلاحية عليه.', 404, 'not_found');
        }
        api_ok(['customer' => $cust]);

    case 'create':
        api_method('POST');
        // الصلاحية customers:edit أو pos:sell (عشان الكاشير يسجّل العميل من شاشة البيع)
        if (!can('customers:edit') && !can('pos:sell')) {
            api_fail('مالكش صلاحية', 403, 'forbidden');
        }
        api_csrf();
        api_idempotent();

        $input = api_input();
        $userId = (int) (current_user()['id'] ?? 0);

        try {
            $customer = cust_create($pdo, $input, $userId);
            api_ok(['customer' => $customer]);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'متسجّل لعميل تاني') ? 'duplicate' : 'bad_request';
            api_fail($e->getMessage(), 400, $code);
        }

    case 'update_field':
        api_method('POST');
        // الصلاحية customers:edit فقط — الكاشير بـpos:sell بس ما يعدّلش بيانات
        api_require('customers:edit');
        api_csrf();

        $input = api_input();
        $id = (int) ($input['id'] ?? 0);
        $field = (string) ($input['field'] ?? '');
        $value = $input['value'] ?? null;
        $userId = (int) (current_user()['id'] ?? 0);

        if ($id <= 0 || $field === '') {
            api_fail('بيانات التعديل ناقصة.', 400, 'bad_request');
        }

        try {
            $updated = cust_update_field($pdo, $id, $field, $value, $userId);
            api_ok(['customer' => $updated]);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'متسجّل لعميل تاني') ? 'duplicate' : 'bad_request';
            $http = str_contains($e->getMessage(), 'صلاحية') ? 403 : 400;
            api_fail($e->getMessage(), $http, $code);
        }

    case 'statement':
        api_method('GET');
        if (!can('customers:statement') && !can('customers:view')) {
            api_fail('مالكش صلاحية', 403, 'forbidden');
        }
        if (view_all_borrowed('customers:statement') || view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
            $GLOBALS['VIEW_ALL_PAGE'] = true;
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            api_fail('معرّف العميل مطلوب.', 400, 'bad_request');
        }

        try {
            $stmtData = cust_statement($pdo, $id);
            api_ok($stmtData);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            $http = str_contains($e->getMessage(), 'صلاحية') ? 403 : 404;
            api_fail($e->getMessage(), $http, 'forbidden');
        }

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
