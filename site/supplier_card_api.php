<?php
declare(strict_types=1);
define('API_MODE',true);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_lib.php';
api_begin();
require_login();
require_once __DIR__.'/supplier_card_lib.php';
api_require('supplier_card:view');
sens_page();
$part=(string)($_GET['part'] ?? '');
$prices=can('supplier_card:prices') && sens_unlocked();
try {
    if (($_GET['action'] ?? '')==='save_field' || $part==='save_field') {
        api_method('POST');api_require('supplier_card:edit');api_csrf();
        $input=api_input();
        if (($input['field'] ?? '')==='credit_limit') {
            api_require('supplier_card:prices');
            if (!$prices) api_fail('الأرقام الحساسة مقفولة',423,'locked');
        }
        if (!array_key_exists('value',$input)) api_fail('قيمة الخانة مطلوبة',400,'bad_request');
        api_ok(['value'=>sc_save_field((string)($input['code'] ?? ''),(string)($input['field'] ?? ''),$input['value'],$prices)]);
    }
    if ($part==='save') {
        api_method('POST');api_require('supplier_card:edit');api_csrf();api_idempotent();
        $input=api_input();
        if (array_key_exists('credit_limit',$input)) {
            api_require('supplier_card:prices');
            if (!$prices) api_fail('الأرقام الحساسة مقفولة',423,'locked');
        }
        api_ok(['code'=>sc_save($input,$prices)]);
    }
    api_method('GET');
    if ($part==='search') api_ok(['rows'=>sc_search((string)($_GET['q'] ?? ''))]);
    if ($part==='find') {
        $s=sc_find((string)($_GET['code'] ?? ''));
        api_ok($s ? sc_public($s,$prices) : null);
    }
    if ($part==='card') api_ok(sc_card((string)($_GET['code'] ?? ''),$prices));
    if (!in_array($part,['purchases','returns','lines','items','statement'],true)) api_fail('جزء مش معروف',400,'bad_request');
    api_require('supplier_card:prices');
    if (!$prices) api_fail('الأرقام الحساسة مقفولة',423,'locked');
    if ($part==='lines') api_ok(['rows'=>sc_lines((string)($_GET['src'] ?? ''),(string)($_GET['id'] ?? ''))]);
    $s=sc_find((string)($_GET['code'] ?? ''));if (!$s) api_ok(null);
    if ($part==='items') api_ok(['rows'=>sc_items($s)]);
    if ($part==='statement') api_ok(sc_statement($s,$_GET));
    [$from,$to]=sc_dates($_GET);$type=$part==='purchases'?2:6;
    api_ok(['rows'=>array_values(array_filter(sc_documents($s,$from,$to),static fn($r)=>(int)$r['doc_type']===$type))]);
} catch (DomainException $e) {
    api_fail($e->getMessage(),409,'duplicate');
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(),400,'bad_request');
}
