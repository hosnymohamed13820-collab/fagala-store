<?php
/**
 * pos_devices_api.php — API تسجيل أجهزة نقطة البيع.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

require_once __DIR__ . '/pos_devices_lib.php';
require_once __DIR__ . '/sale_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));
$pdo = db();

switch ($part) {
    case 'list_all':
        api_method('GET');
        api_require('pos:devices');
        $allowedBranchIds = sale_allowed_branch_ids($pdo, current_user() ?? []);
        api_ok([
            'devices' => posdev_list_all($pdo, $allowedBranchIds),
        ]);

    case 'list':
        api_method('GET');
        api_require('pos:devices');
        $branches = posdev_branches($pdo);
        $branchId = (int) ($_GET['branch_id'] ?? ($branches[0]['id'] ?? 0));
        if ($branchId <= 0) {
            api_ok(['branches' => $branches, 'branch_id' => null, 'devices' => []]);
        }
        try {
            api_ok([
                'branches' => $branches,
                'branch_id' => $branchId,
                'devices' => posdev_list($pdo, $branchId),
            ]);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 403, 'forbidden');
        }

    case 'add':
        api_method('POST');
        api_require('pos:devices');
        api_csrf();
        api_idempotent();
        $in = api_input();
        try {
            $made = posdev_add(
                $pdo,
                (int) ($in['branch_id'] ?? 0),
                (string) ($in['name'] ?? ''),
                (string) ($in['code'] ?? ''),
                (int) current_user()['id'],
                (string) ($in['print_mode'] ?? 'browser')
            );
            api_ok(['device' => $made]);
        } catch (DomainException $e) {
            api_fail($e->getMessage(), 409, 'duplicate');
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 403, 'forbidden');
        }

    case 'update':
        api_method('POST');
        api_require('pos:devices');
        api_csrf();
        api_idempotent();
        $in = api_input();
        try {
            posdev_update(
                $pdo,
                (int) ($in['device_id'] ?? 0),
                (int) ($in['branch_id'] ?? 0),
                (string) ($in['name'] ?? ''),
                (string) ($in['code'] ?? ''),
                !empty($in['is_active']),
                (string) ($in['print_mode'] ?? 'browser')
            );
            api_ok(['saved' => true]);
        } catch (DomainException $e) {
            api_fail($e->getMessage(), 409, 'duplicate');
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 403, 'forbidden');
        }

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
