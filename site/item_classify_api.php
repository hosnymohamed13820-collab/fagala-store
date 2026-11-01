<?php
/** API تقسيم الأصناف، من غير أسعار أو تكلفة. */
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
require_once __DIR__ . '/item_classify_lib.php';
$review=can('item_master:classify_review');
if (!can('item_master:classify') && !$review) api_fail('مالكش صلاحية تقسيم الأصناف',403,'forbidden');
api_method('GET','POST');
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') api_ok(ica_list($_GET));
    $input=api_input();
    if (($input['action'] ?? '')==='approve') api_require('item_master:classify_review');
    elseif (!$review) api_require('item_master:classify');
    api_csrf();
    api_idempotent();
    $result=ica_write($input,(int)current_user()['id'],$review);
    $updated=ica_list(['item_ids'=>$input['item_ids']]);
    api_ok($result+['rows'=>$updated['rows'],'remaining'=>$updated['remaining']]);
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(),400,'bad_request');
} catch (DomainException $e) {
    api_fail('مالكش صلاحية المراجعة',403,'forbidden');
}
