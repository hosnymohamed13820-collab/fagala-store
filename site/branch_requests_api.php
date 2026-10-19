<?php
/**
 * branch_requests_api.php — داتا «طلبات الفروع» (بريف ١٢٤)
 * نظام الفجالة ستور المالي
 *
 *   ?part=list   ← {branches, lines, reservations, shortages_by_branch, reservations_by_branch}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');
api_require('shortage');

require_once __DIR__ . '/shortage_lib.php';
require_once __DIR__ . '/book_lib.php';

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'list':
        $uid      = (int) current_user()['id'];
        $myBranch = (int) (current_user()['branch_id'] ?? 0);
        $resScope = book_res_scope(is_owner(), $myBranch);
        api_ok(branch_requests_data($resScope, $uid));

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}

