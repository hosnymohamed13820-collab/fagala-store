<?php
/** اختبار كارت المورد على Laragon؛ الكتابة في قاعدة معزولة بتتمسح في نهاية التشغيل. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require_once 'D:/laragon/www/config.php';
require_once __DIR__.'/../../site/supplier_card_lib.php';
$pdo=db();
function sc_check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$mode=$argv[1] ?? 'lib';
if ($mode==='worker') {
    $workerDb=$argv[2] ?? '';
    if (!preg_match('/^brief843_test_[0-9]+$/D',$workerDb)) exit(2);
    $pdo->exec("USE $workerDb");
    echo sc_save(['name'=>'مورد متزامن '.getmypid()],true);exit;
}
if ($mode==='lib') {
    sc_check(sc_find('99999')===null,'missing code 99999');
    $search=sc_search('حامد');
    sc_check(count($search)===1 && $search[0]['erp_supplier_code']==='90' && !isset($search[0]['id']),'live search Hamid code 90');
    $s=sc_find('90');
    $same=suppliers_get_statement($pdo,(int)$s['id'],'2026-06-27',date('Y-m-d'),suppliers_get_start_date($pdo),null);
    $ours=sc_statement($s,['from'=>'2026-06-27','to'=>date('Y-m-d')]);
    sc_check($same['period']===$ours['period'] && $same['moves']===$ours['moves'] && $same['prior']===$ours['prior'],'live statement identical');
}
// الجداول العادية المعزولة بتسمح لنفس جدول الأكواد يظهر مرتين في الاستعلام.
$ddl=[];
foreach (['suppliers','hq_invoices','hq_invoice_lines','stock_docs','stock_doc_lines','hq_codes','users','roles','accounts','settings','branches','journal_entries','treasuries'] as $table) {
    $sql=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $sql=preg_replace('/^\s*CONSTRAINT[^\n]+\n/m','',$sql);
    $ddl[]=preg_replace('/,\s*\)/',"\n)",$sql);
}
$testDb='brief843_test_'.getmypid();
if (!preg_match('/^brief843_test_[0-9]+$/D',$testDb)) exit(2);
$pdo->exec("CREATE DATABASE $testDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function() use($pdo,$testDb) {
    register_shutdown_function(static function() use($pdo,$testDb) {$pdo->exec("DROP DATABASE $testDb");});
});
$pdo->exec("USE $testDb");
foreach ($ddl as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO suppliers (id,name,erp_supplier_code,erp_supplier_id,balance) VALUES
    (843,'مورد اختبار','434',1000843,125.50),(844,'مورد تاني','428',1000844,20)");
$pdo->exec("INSERT INTO hq_invoices (sth_id,suffix,doc_no,doc_type,supplier_id,net_amount,total_amount,invoice_date,company_branch) VALUES
    (843,'26_10','P843',2,1000843,100,120,'2026-10-01 23:59:59',1),
    (843,'26_11','P844',2,1000843,200,220,'2026-11-01',2),
    (845,'26_11','R843',6,1000843,20,20,'2026-11-01',1),
    (846,'26_11','OTHER',2,1000844,999,999,'2026-11-01',1)");
$pdo->exec("INSERT INTO hq_codes (code,item_id,name,code_type,unit_factor) VALUES ('0055',843,'صنف اختبار',0,1),('005501',843,'باكو صنف اختبار',1,10)");
$pdo->exec("INSERT INTO hq_invoice_lines (sth_id,suffix,line_no,item_id,item_code,item_name,quantity,cost_price,unit_factor) VALUES
    (843,'26_10',1,843,'005501','صنف اختبار',2,50,10),
    (843,'26_11',1,843,'0055','صنف اختبار',4,60,1)");
$pdo->exec("INSERT INTO stock_docs (id,doc_type,doc_no,doc_year,branch_id,supplier_id,total_amount,gross_amount,status,doc_date,created_by) VALUES
    (843,'purchase',843,2026,1,843,30,40,'posted','2026-11-02',1),
    (844,'purchase',844,2026,1,843,999,999,'draft','2026-11-02',1),
    (845,'purchase_return',845,2026,1,843,5,5,'posted','2026-11-02',1)");
$pdo->exec("INSERT INTO stock_doc_lines (doc_id,line_no,item_id,code,unit_factor,qty_units,qty_pieces,unit_cost,unit_price,line_total) VALUES (843,1,843,'0055',1,3,3,10,10,30)");
$pdo->exec("INSERT INTO roles (id,name,name_ar,is_owner) VALUES (843,'sc_test','اختبار',0)");
$pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id,perms_version,pw_version) VALUES (843,'sc_test','اختبار','unused',843,1,1)");
if ($mode!=='lib') {
    session_save_path(__DIR__.'/../../local');session_start();
    $testSession=__DIR__.'/../../local/sess_'.session_id();
    register_shutdown_function(static function() use($testSession) {if (session_status()===PHP_SESSION_ACTIVE) session_write_close();if (is_file($testSession)) unlink($testSession);});
    $_SESSION=['app'=>'fagala','user'=>['id'=>843,'is_owner'=>false,'perms_version'=>1,'pw_version'=>1],
        'permissions'=>$mode==='deny'?[]:($mode==='viewall'?['view_all']:['supplier_card:view']),
        'csrf'=>'sc843','sens_until'=>time()+600];
    if (in_array($mode,['locked','viewall','allow','save','duplicate','update','csrf'],true)) $_SESSION['permissions'][]='supplier_card:prices';
    if (in_array($mode,['save','duplicate','update','csrf'],true)) $_SESSION['permissions'][]='supplier_card:edit';
    if ($mode==='locked') unset($_SESSION['sens_until']);
    parse_str($argv[2] ?? 'part=card&code=434',$_GET);
    $_SERVER['REQUEST_METHOD']=in_array($mode,['save','duplicate','update','csrf'],true)?'POST':'GET';
    $_SERVER['SCRIPT_NAME']='/supplier_card_api.php';
    $_SERVER['HTTP_X_CSRF_TOKEN']=$mode==='csrf'?'bad':'sc843';
    $_POST=$mode==='duplicate'?['name'=>'مورد اختبار']:($mode==='update'?['code'=>'434','name'=>'مورد اختبار','balance'=>999,'erp_supplier_code'=>'999','erp_supplier_id'=>999]:['name'=>'جديد اختبار','balance'=>999,'erp_supplier_id'=>999]);
    register_shutdown_function(static function() use($pdo,$mode) {
        $response=json_decode((string)ob_get_contents(),true);
        $status=http_response_code() ?: 200;
        $expected=in_array($mode,['deny','view','csrf'],true)?403:(in_array($mode,['locked','viewall'],true)?423:($mode==='duplicate'?409:200));
        $part=$_GET['part'];
        if ($mode==='view' && in_array($part,['card','find','search'],true)) $expected=200;
        if ($status!==$expected) {fwrite(STDERR,"FAIL expected $expected got $status\n");exit(1);}
        if ($mode==='save') {
            $r=$pdo->query("SELECT * FROM suppliers WHERE name='جديد اختبار'")->fetch(PDO::FETCH_ASSOC);
            if ($r['erp_supplier_code']!=='435' || $r['erp_supplier_id']!==null || (float)$r['balance']!==0.0) {fwrite(STDERR,"FAIL creation fields\n");exit(1);}
        }
        if ($mode==='update') {
            $r=$pdo->query("SELECT * FROM suppliers WHERE id=843")->fetch(PDO::FETCH_ASSOC);
            if ($r['erp_supplier_code']!=='434' || (int)$r['erp_supplier_id']!==1000843 || (float)$r['balance']!==125.5) {fwrite(STDERR,"FAIL immutable fields\n");exit(1);}
        }
        if ($mode==='view' && str_contains(json_encode($response), 'credit_limit')) {fwrite(STDERR,"FAIL price leakage\n");exit(1);}
        fwrite(STDERR,"PASS API $mode $part HTTP $status\n");
    });
    ob_start();require __DIR__.'/../../site/supplier_card_api.php';exit;
}
$s=sc_find('434');
$docs=sc_documents($s,'2026-01-01','2026-12-31');
$site=(string)setting('supplier_site_docs','1')!=='0';
sc_check(count($docs)===($site?5:3),'2 HQ purchases + 1 HQ return + 2 posted site docs');
sc_check(count(sc_lines('hq','843:26_10'))===1 && (float)sc_lines('hq','843:26_10')[0]['quantity']===2.0,'composite HQ key October');
sc_check((float)sc_lines('hq','843:26_11')[0]['quantity']===4.0,'composite HQ key November');
sc_check(sc_lines('site','844')===[],'draft lines excluded');
sc_check(count(sc_documents($s,'2026-10-01','2026-10-01'))===1,'inclusive date upper bound');
$items=sc_items($s);
sc_check(count($items)===1 && $items[0]['code']==='0055' && (float)$items[0]['quantity']===($site?27.0:24.0) && (float)$items[0]['cost']===($site?10.0:60.0),'items grouped once, base code, pieces, latest cost');
$card=sc_card('434',false);
sc_check(!isset($card['supplier']['balance'],$card['supplier']['erp_supplier_id'],$card['supplier']['id'],$card['supplier']['credit_limit'],$card['statement'],$card['latest']),'locked card contains no monetary data');
sc_check($card['counts']['purchases']===($site?3:2),'purchase count equals HQ count plus posted site');
$same=suppliers_get_statement($pdo,843,'2026-10-01','2026-11-02',suppliers_get_start_date($pdo),null);
$ours=sc_statement($s,['from'=>'2026-10-01','to'=>'2026-11-02']);
sc_check($same['period']===$ours['period'] && $same['moves']===$ours['moves'] && $same['prior']===$ours['prior'],'fixture statement identical');
$code=sc_save(['name'=>'مورد جديد','phone'=>'01012345678','balance'=>999,'erp_supplier_id'=>999],true);
sc_check($code==='435' && sc_find($code)['erp_supplier_id']===null && (float)sc_find($code)['balance']===0.0,'new code MAX+1, NULL ERP, untouched balance');
try {sc_save(['name'=>'مورد جديد'],true);throw new RuntimeException('duplicate allowed');}catch(DomainException $e){sc_check(str_contains($e->getMessage(),'اسم المورد'),'clear duplicate name error');}
sc_save(['code'=>'434','name'=>'اسم معدل','balance'=>99999,'erp_supplier_code'=>'999','erp_supplier_id'=>999,'credit_limit'=>'100'],false);
$saved=sc_find('434');
sc_check((float)$saved['balance']===125.5 && (int)$saved['erp_supplier_id']===1000843 && $saved['credit_limit']===null,'existing balance ERP and code immutable; no price permission');
sc_check(count(sc_search('اسم مُعَدَّل'))===1,'Arabic normalization');
$a=proc_open([PHP_BINARY,__FILE__,'worker',$testDb],[1=>['pipe','w'],2=>['pipe','w']],$pipesA);
$b=proc_open([PHP_BINARY,__FILE__,'worker',$testDb],[1=>['pipe','w'],2=>['pipe','w']],$pipesB);
$aCode=trim(stream_get_contents($pipesA[1]));$aError=stream_get_contents($pipesA[2]);
$bCode=trim(stream_get_contents($pipesB[1]));$bError=stream_get_contents($pipesB[2]);
foreach ([$pipesA,$pipesB] as $pipes) foreach ($pipes as $pipe) fclose($pipe);
$aExit=proc_close($a);$bExit=proc_close($b);
sc_check($aExit===0 && $bExit===0 && $aCode!==$bCode && in_array($aCode,['436','437'],true) && in_array($bCode,['436','437'],true),'concurrent saves produce unique codes 436 and 437 '.$aError.$bError);
