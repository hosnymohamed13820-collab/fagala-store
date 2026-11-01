<?php
/** اختبار محلي بس: قاعدة معزولة بتتمسح في نهاية الاختبار حتى لو فشل. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require getenv('IC_TEST_CONFIG') ?: 'D:/laragon/www/config.php';
require_once __DIR__.'/../../site/item_classify_lib.php';
require_once __DIR__.'/../../site/fifo_lib.php';
$pdo=db();
function check840(bool $ok,string $what): void { if(!$ok)throw new RuntimeException($what);echo "PASS $what\n"; }
// MySQL بيمنع قراية الجدول المؤقت مرتين في استعلام، فبننسخ البنية لقاعدة اختبار معزولة.
$tables=['hq_item_cats','hq_codes','hq_items','hq_branch_balances','hq_balances','br_invoice_lines','br_invoices','hq_invoice_lines','hq_invoices','fifo_alloc','fifo_runs','fifo_lots','users','roles'];
$ddls=[];
foreach($tables as $table){
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]+\n/m','',$ddl);
    $ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
    $ddls[]=$ddl;
}
$testDb='brief840_test_'.getmypid();
if (!preg_match('/^brief840_test_[0-9]+$/',$testDb))exit(2);
$pdo->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function()use($pdo,$testDb){try{if(isset($GLOBALS['brief840_validate']))($GLOBALS['brief840_validate'])();}finally{$pdo->exec("DROP DATABASE `$testDb`");}});
$pdo->exec("USE `$testDb`");
foreach($ddls as $ddl)$pdo->exec($ddl);
$pdo->exec("CREATE TABLE item_classes (id INT PRIMARY KEY,kind VARCHAR(20),name VARCHAR(255),true_id INT NULL,is_active INT) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$sql=file_get_contents(__DIR__.'/../../local/migrations/20261101_038_item_class_assign.sql');
$sql=preg_replace('/`u834909335_fagala`\./','',$sql);
$sql=preg_replace('/INSERT IGNORE INTO.*schema_migrations.*;/s','',$sql);
$pdo->exec($sql);
$pdo->exec($sql);
$pdo->exec("INSERT INTO item_classes VALUES (1,'section','خدمات',1,1),(2,'section','ورق وتصوير',NULL,1),(3,'section','أدوات مدرسية',2,1)");
$pdo->exec("INSERT INTO hq_item_cats (item_id,is1_id,item_name) VALUES (840001,1,'ورق تصوير أبيض'),(840002,0,'ورق تصوير ملون'),(840003,0,'ورق تصوير فاخر')");
$pdo->exec("INSERT INTO hq_codes (code,item_id,name,code_type,unit_factor) VALUES ('840001',840001,'ورق تصوير أبيض',0,1),('840002',840002,'ورق تصوير ملون',0,1),('840003',840003,'ورق تصوير فاخر',0,1)");
$pdo->exec("INSERT INTO hq_branch_balances (agent_key,sto_id,item_id,balance) VALUES ('zahraa',2,840001,10)");
$pdo->exec("INSERT INTO br_invoices (agent_key,sth_id,suffix,doc_type,invoice_date) VALUES ('zahraa',840,'26_11',4,CURRENT_DATE)");
$pdo->exec("INSERT INTO br_invoice_lines (agent_key,sth_id,suffix,line_no,item_id,item_code,quantity,unit_factor) VALUES ('zahraa',840,'26_11',1,840001,'840001',2,12)");
$pdo->exec("INSERT INTO roles (id,name,name_ar,is_owner) VALUES (1,'test','اختبار',0)");
$pdo->exec("INSERT INTO users (id,username,full_name,password_hash,role_id,perms_version) VALUES (1,'brief840a','مقسّم','unused',1,1),(2,'brief840b','مراجع','unused',1,1)");
$mode=$argv[1] ?? '';
if (str_starts_with($mode,'api-')) {
    session_save_path(sys_get_temp_dir());session_start();
    $review=$mode==='api-review';
    $_SESSION=['app'=>'fagala','user'=>['id'=>$review?2:1,'is_owner'=>false,'perms_version'=>1],
        'permissions'=>[$review?'item_master:classify_review':'item_master:classify'],'csrf'=>'brief840-token'];
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_X_CSRF_TOKEN']='brief840-token';
    $_SERVER['SCRIPT_NAME']='/item_classify_api.php';
    $_POST=['action'=>in_array($mode,['api-deny','api-review'],true)?'approve':'assign',
        'item_ids'=>$mode==='api-bulk'?[840001,840002,840003]:[840001],'kind'=>'section','class_id'=>2];
    if($review)ica_write(['item_ids'=>[840001],'kind'=>'section','class_id'=>2],1,false);
    $GLOBALS['brief840_validate']=static function()use($pdo,$mode){
        $response=ob_get_contents();
        echo "\n";
        if($mode==='api-deny') {
            check840(http_response_code()===403,'actual API classify approve HTTP 403');
            check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign_log')->fetchColumn()===0,'forbidden POST no writes');return;
        }
        check840((http_response_code()?:200)===200,'actual API POST 200');
        check840(!preg_match('/"[^"\\s]*(cost|price)[^"\\s]*"\s*:/i',$response),'API has no cost or price fields');
        $count=$mode==='api-bulk'?3:1;
        check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign')->fetchColumn()===$count,'actual API row count');
        check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign_log')->fetchColumn()===($mode==='api-review'?2:$count),'actual API log count');
        check840($pdo->query('SELECT status FROM item_class_assign LIMIT 1')->fetchColumn()===($mode==='api-review'?'approved':'pending'),'actual API status');
    };
    ob_start();require __DIR__.'/../../site/item_classify_api.php';exit;
}
ica_write(['item_ids'=>[840001],'kind'=>'section','class_id'=>2],1,false);
check840($pdo->query('SELECT status FROM item_class_assign')->fetchColumn()==='pending','classify pending');
check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign_log')->fetchColumn()===1,'single log');
try {ica_write(['action'=>'approve','item_ids'=>[840001]],1,false);throw new RuntimeException('approve allowed');}catch(DomainException){echo "PASS library denies approve\n";}
ica_write(['action'=>'approve','item_ids'=>[840001]],2,true);
check840($pdo->query('SELECT status FROM item_class_assign')->fetchColumn()==='approved','review approved');
$pdo->exec('DELETE FROM item_class_assign_log');
ica_write(['item_ids'=>[840001,840002,840003],'kind'=>'section','class_id'=>2],1,false);
check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign')->fetchColumn()===3,'bulk three rows');
check840((int)$pdo->query('SELECT COUNT(*) FROM item_class_assign_log')->fetchColumn()===3,'bulk three logs');
$page=ica_list(['no_section'=>0]);
check840(count($page['rows'])===3,'page no duplicates');
check840((float)$page['rows'][0]['sold']===24.0,'sales line factor pieces');
check840((float)$page['rows'][0]['balance']===10.0,'same FIFO balance stores');
check840($page['rows'][0]['classes']['section']['name']==='ورق وتصوير','site wins True');
check840($page['rows'][1]['suggestion']['name']==='ورق وتصوير','normalized grouped suggestion');
check840(ica_list(['no_section'=>1])['total']===0,'no effective section filter');
check840(ica_list(['q'=>'84000%'])['total']===0,'escaped LIKE');
check840(ica_list(['status'=>'pending'])['total']===3,'pending filter');
ica_write(['item_ids'=>[840001],'kind'=>'section','class_id'=>2],2,true);
check840($pdo->query('SELECT status FROM item_class_assign WHERE item_id=840001')->fetchColumn()==='approved','reviewer direct assign approved');
$pdo->exec("INSERT INTO fifo_runs (id,is_current,status) VALUES (840,1,'done')");
$pdo->exec("INSERT INTO fifo_lots (run_id,lot_id,item_id,kind,sth_id,suffix,line_no,lot_date,qty_in,qty_returned) VALUES (840,1,840001,'purchase',840,'26_11',1,CURRENT_DATE,30,0)");
$pdo->exec("INSERT INTO fifo_alloc (run_id,lot_id,item_id,agent_key,sale_sth_id,sale_suffix,sale_line_no,sale_date,qty,revenue,cost) VALUES (840,1,840001,'zahraa',840,'26_11',1,CURRENT_DATE,1,10,5)");
$fifo=fifo_store_profit($pdo,'all',date('Y-m-d'),date('Y-m-d'),'category');
check840(count($fifo['rows'])===1 && $fifo['rows'][0]['name']==='ورق وتصوير','FIFO effective category wins services');
ica_write(['action'=>'clear','item_ids'=>[840001],'kind'=>'section'],1,false);
$effective=$pdo->query('SELECT * FROM ('.ic_effective_sql('section').') e WHERE item_id=840001')->fetch(PDO::FETCH_ASSOC);
check840((int)$effective['class_id']===1,'clear restores True');
echo "PASS all isolated MySQL scenarios\n";
