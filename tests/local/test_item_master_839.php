<?php
/** بريف ٨٣٩: مقارنة مستقلة للقطع، وإنشاء معزول بجداول مؤقتة على Laragon. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once 'D:/laragon/www/config.php';
require_once __DIR__.'/../../site/item_master_lib.php';
$pdo=db();
if (!str_starts_with($pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS),'localhost')) exit(2);
function check839(bool $ok,string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function render839(): string {
    $_SERVER['HTTP_HOST']=$_SERVER['SERVER_NAME']='localhost';
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/item_master.php';
    $_SERVER['REQUEST_URI']='/item_master.php?source=true&id=1010437';
    $_SERVER['REQUEST_METHOD']='GET';
    $_GET=['source'=>'true','id'=>'1010437'];
    session_start();
    $_SESSION['app']='fagala';
    $_SESSION['user']=['id'=>1,'username'=>'owner','full_name'=>'المالك','is_owner'=>1,'branch_id'=>null,'perms_version'=>0];
    $_SESSION['permissions']=[];
    ob_start();require __DIR__.'/../../site/item_master.php';return ob_get_clean();
}
$html=render839();
check839((bool)preg_match('~<script type="application/json" id="imBoot">(.*?)</script>~s',$html,$match),'imBoot مش موجود');
$boot=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
check839(count($boot['item']['codes'])===15,'داتا الصفحة مش كاملة');
echo 'PASS: real page imBoot, 15 codes, '.strlen($html)." bytes\n";
$item=im_detail('true',1010437);
check839($item!==null && count($item['codes'])===15,'كارت ورق التصوير أو عدد الأكواد مختلف');
$balance=(float)$pdo->query("SELECT SUM(balance) FROM (
    SELECT balance FROM hq_branch_balances WHERE item_id=1010437
    AND ((agent_key='zahraa' AND sto_id=2) OR (agent_key='bonjour' AND sto_id=3)
    OR (agent_key='degla' AND sto_id=5) OR (agent_key='abc' AND sto_id=6))
    UNION ALL SELECT balance FROM hq_balances WHERE item_id=1010437 AND sto_id=1) b")->fetchColumn();
$st=$pdo->query("SELECT l.item_code,l.quantity,l.unit_factor,h.doc_type FROM br_invoice_lines l
    JOIN br_invoices h ON h.agent_key=l.agent_key AND h.sth_id=l.sth_id AND h.suffix=l.suffix
    WHERE l.item_id=1010437 AND h.doc_type IN (4,7,17) AND h.agent_key IN ('zahraa','bonjour','degla','abc')
    AND h.invoice_date>=CURRENT_DATE - INTERVAL 29 DAY AND h.invoice_date<CURRENT_DATE + INTERVAL 1 DAY");
$lines=$st->fetchAll(PDO::FETCH_ASSOC);
$factors=item_code_factors($pdo,array_column($lines,'item_code'));$sales=0;
foreach ($lines as $line) {
    $pieces=item_line_pieces((float)$line['quantity'],$line['unit_factor']===null?null:(float)$line['unit_factor'],isset($factors[$line['item_code']])?(float)$factors[$line['item_code']]['unit_factor']:null);
    $sales+=$pieces['pieces']*((int)$line['doc_type']===4?1:-1);
}
check839(abs($balance-$item['sidebar']['balance'])<0.001,'الرصيد مختلف');
check839(abs($balance-array_sum(array_column($item['sidebar']['balances'],'balance')))<0.001,'مجموع الفروع مختلف');
check839(abs($sales-$item['sidebar']['sold'])<0.001,'المبيعات مختلفة');
echo json_encode(['item'=>1010437,'codes'=>count($item['codes']),'balance'=>$balance,'sales30'=>$sales,'branches'=>$item['sidebar']['balances'],'date'=>$pdo->query('SELECT CURRENT_DATE')->fetchColumn()],JSON_UNESCAPED_UNICODE)."\n";
check839(im_lookup('9999')===null,'9999 موجود في المرآة');
check839((int)im_lookup('0056')['id']===1010437,'الباركود مش بيفتح الكارت');
check839((int)im_lookup('1010437')['id']===1010437,'رقم الصنف مش بيفتح الكارت');
// الجداول المؤقتة بتختفي بانتهاء الاتصال، والأصلية مابتتلمسش.
$definitions=[
    $pdo->query('SHOW CREATE TABLE item_master')->fetch(PDO::FETCH_NUM)[1],
    $pdo->query('SHOW CREATE TABLE item_master_codes')->fetch(PDO::FETCH_NUM)[1],
    $pdo->query('SHOW CREATE TABLE item_prices')->fetch(PDO::FETCH_NUM)[1],
    $pdo->query('SHOW CREATE TABLE item_price_history')->fetch(PDO::FETCH_NUM)[1],
];
$pdo->exec(implode(';',array_map(fn($sql)=>str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$sql),$definitions)));
$section=(int)$pdo->query("SELECT id FROM item_classes WHERE kind='section' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$data=['name1'=>'اختبار مؤقت ٨٣٩','cat_is1_id'=>$section,'codes'=>[['code'=>'','code_type'=>0,'unit_factor'=>1,'price'=>12.5]]];
$first=im_create($data,1,true);$second=im_create($data,1,true);
$a=$pdo->query("SELECT m.sys_code,c.code,p.price,(SELECT COUNT(*) FROM item_price_history h WHERE h.code=c.code) history_count FROM item_master m JOIN item_master_codes c ON c.item_master_id=m.id LEFT JOIN item_prices p ON p.code=c.code WHERE m.id=$first")->fetch(PDO::FETCH_ASSOC);
$b=$pdo->query("SELECT sys_code FROM item_master WHERE id=$second")->fetch(PDO::FETCH_ASSOC);
check839((int)$b['sys_code']===(int)$a['sys_code']+1,'الأكواد مش متتالية');
check839(str_starts_with($a['sys_code'],str_pad((string)$section,2,'0',STR_PAD_LEFT)),'بادئة القسم مختلفة');
check839($a['code']===$a['sys_code'] && (float)$a['price']===12.5 && (int)$a['history_count']===1,'الكود الأساسي أو السعر أو السجل مختلف');
$data['cat_is1_id']='';$data['item_type']='service';$data['codes'][0]['code']='B839-OPTIONAL';
$third=im_create($data,1,true);
$c=$pdo->query("SELECT m.sys_code,m.item_type,c.code FROM item_master m JOIN item_master_codes c ON c.item_master_id=m.id WHERE m.id=$third")->fetch(PDO::FETCH_ASSOC);
check839(str_starts_with($c['sys_code'],'00') && $c['code']==='B839-OPTIONAL' && $c['item_type']==='service','من غير قسم أو الباركود الاختياري أو الخدمة مختلف');
echo json_encode(['section'=>$section,'first'=>$a['sys_code'],'second'=>$b['sys_code'],'no_section'=>$c['sys_code'],'barcode'=>$c['code'],'price'=>12.5,'initial_history'=>$a['history_count']],JSON_UNESCAPED_UNICODE)."\n";
echo "PASS: real balance/sales + exact lookup + temporary creates + initial price history\n";
// فواتير مؤقتة بتثبت تحويل الروزمة والمرتجع، حتى لو الفترة الحقيقية مافيهاش بيع.
$pdo->exec("CREATE TEMPORARY TABLE br_invoices (agent_key VARCHAR(32),sth_id INT,suffix VARCHAR(8),doc_type INT,invoice_date DATETIME) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    CREATE TEMPORARY TABLE br_invoice_lines (agent_key VARCHAR(32),sth_id INT,suffix VARCHAR(8),item_id INT,item_code VARCHAR(64),quantity DECIMAL(16,3),unit_factor DECIMAL(12,4)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO br_invoices VALUES ('zahraa',1,'a',4,CURRENT_DATE),('zahraa',2,'a',7,CURRENT_DATE),('zahraa',3,'a',17,CURRENT_DATE),('bonjour',1,'b',4,CURRENT_DATE);
    INSERT INTO br_invoice_lines VALUES ('zahraa',1,'a',1010437,'0056',2,500),('zahraa',2,'a',1010437,'0056',1,NULL),('zahraa',3,'a',1010437,'0056',3,2),('bonjour',1,'b',1010437,'0042',3,1)");
$side=im_sidebar(1010437,$item['codes'],[],false);
check839(abs($side['sold']-497)<0.001,'تحويل القطع والمرتجع مش سليم');
echo "PASS: 1000 - 500 - 6 + 3 = 497 pieces, per-branch aggregate\n";
