<?php
/** اختبارات خصم الشراء وصافي التكلفة، من غير اتصال بقاعدة الموقع. */
declare(strict_types=1);
require_once __DIR__.'/../../site/purchase_doc_lib.php';
$passed = 0;
function b829_check(string $name, bool $ok, mixed $actual = null): void {
    global $passed;
    if (!$ok) throw new RuntimeException($name.': '.json_encode($actual, JSON_UNESCAPED_UNICODE));
    ++$passed;
    echo "PASS $name".($actual !== null ? ' '.json_encode($actual, JSON_UNESCAPED_UNICODE) : '')."\n";
}
function b829_line(float $qty, float $price, float $discount = 0, float $factor = 1): array {
    return ['qty_units'=>$qty,'qty_pieces'=>round($qty*$factor,3),'unit_price'=>$price,'discount_pct'=>$discount];
}
$roto = purchase_calculate_totals([b829_line(10,12)], ['type'=>'percent','value'=>10]);
b829_check('روتو 108 وتكلفة 10.8', $roto['total_amount'] === 108.0 && $roto['lines'][0]['unit_cost'] === 10.8
    && $roto['lines'][0]['net_unit_cost'] === 10.8, $roto);
$mixed = purchase_calculate_totals([b829_line(10,12,5),b829_line(3,50)], ['type'=>'amount','value'=>26.4]);
b829_check('خصم صنف وفاتورة قيمة', array_column($mixed['lines'],'inv_discount_alloc') === [11.4,15.0]
    && array_column($mixed['lines'],'line_total') === [102.6,135.0] && $mixed['gross_amount'] === 264.0
    && $mixed['total_amount'] === 237.6, $mixed);
$penny = purchase_calculate_totals(array_fill(0,3,b829_line(1,10)), ['type'=>'amount','value'=>10]);
b829_check('فرق القرش', round(array_sum(array_column($penny['lines'],'inv_discount_alloc')),2) === 10.0
    && $penny['total_amount'] === 20.0, $penny);
foreach ([b829_line(1.235,12.3489,4.125,12),b829_line(3,50),b829_line(0.001,0.0001),b829_line(1,10.075)] as $line) {
    $oldTotal = round($line['qty_units']*$line['unit_price']*(1-$line['discount_pct']/100),2);
    $oldCost = round($oldTotal/$line['qty_pieces'],4);
    $new = purchase_calculate_totals([$line]);
    b829_check('من غير خصم زي القديم', $new['lines'][0]['line_total'] === $oldTotal && $new['lines'][0]['unit_cost'] === $oldCost, $new);
}
foreach ([['type'=>'amount','value'=>121],['type'=>'percent','value'=>101],['type'=>'amount','value'=>-1],['type'=>'wrong','value'=>10]] as $discount) {
    $rejected = false;
    try { purchase_calculate_totals([b829_line(10,12)],$discount); } catch (RuntimeException $e) { $rejected=true; }
    b829_check('خصم غلط مرفوض',$rejected,$discount);
}
$rejected=false;
try { purchase_calculate_totals([b829_line(2,10.8)],['type'=>'percent','value'=>0],true); } catch (RuntimeException $e) { $rejected=true; }
b829_check('خصم على أصل مرفوض حتى لو صفر',$rejected);
// SQLite في الذاكرة علشان نجرب نفس مسار تجاهل سعر POST وقراءة تكلفة الأصل.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE hq_codes(code TEXT,item_id INT,unit_factor REAL,code_type INT)');
$pdo->exec("INSERT INTO hq_codes VALUES ('ROTO',1,1,0),('BOX',1,12,1)");
$pdo->exec('CREATE TABLE stock_doc_lines(doc_id INT,item_id INT,qty_pieces REAL,unit_cost REAL)');
$pdo->exec('INSERT INTO stock_doc_lines VALUES (829,1,10,10.8)');
$return = purchase_calculate_totals(purchase_normalize_lines($pdo,[['code'=>'ROTO','qty_units'=>2,'unit_price'=>12,'discount_pct'=>90]],829),null,true);
b829_check('مرتجع سعر POST متجاهل', $return['lines'][0]['unit_price'] === 10.8 && $return['lines'][0]['discount_pct'] === 0
    && $return['total_amount'] === 21.6, $return);
$box = purchase_calculate_totals(purchase_normalize_lines($pdo,[['code'=>'BOX','qty_units'=>1,'unit_price'=>12]],829),null,true);
b829_check('مرتجع بوحدة مختلفة', $box['lines'][0]['unit_price'] === 129.6 && $box['lines'][0]['unit_cost'] === 10.8, $box);
if (in_array('--laragon', $argv, true)) {
    require_once (getenv('LARAGON_WWW') ?: 'D:/laragon/www').'/config.php';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = 999829;
        b829_check('صنف التجربة مش مستخدم',(int)$pdo->query('SELECT COUNT(*) FROM hq_codes WHERE item_id=999829 OR code=\'B829-ROTO\'')->fetchColumn()===0);
        $pdo->exec("INSERT INTO hq_codes(code,item_id,name,code_type,unit_factor,price,cost) VALUES('B829-ROTO',999829,'قلم تجربة بريف ٨٢٩',0,1,15,12)");
        $pdo->exec("INSERT INTO suppliers(name,is_active,erp_supplier_id) VALUES('مورد تجربة بريف ٨٢٩',1,999999829)");
        $supplier = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('erp_stock_enabled','1'),('stock_mode_6','live') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $head = ['branch_id'=>6,'supplier_id'=>$supplier,'doc_date'=>date('Y-m-d'),'inv_discount'=>['type'=>'percent','value'=>10]];
        $id = purchase_save_draft($pdo,'purchase',$head,[['code'=>'B829-ROTO','qty_units'=>10,'unit_price'=>12]],1);
        $saved = $pdo->query("SELECT total_amount,gross_amount,inv_discount_type,inv_discount_value FROM stock_docs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        b829_check('حفظ الخصم في MySQL',(float)$saved['total_amount']===108.0&&(float)$saved['gross_amount']===120.0&&$saved['inv_discount_type']==='percent'&&(float)$saved['inv_discount_value']===10.0,$saved);
        purchase_post($pdo,$id,1);
        $move = $pdo->query("SELECT unit_cost FROM stock_moves WHERE doc_id=$id")->fetchColumn();
        $balance = stock_balance($pdo,$item,6);
        b829_check('ترحيل تكلفة 10.8 على رصيد صفر',(float)$move===10.8&&$balance['avg_cost']===10.8&&$balance['qty']===10.0,['move'=>$move,'balance'=>$balance]);
        $sto=(int)$pdo->query('SELECT sto_id FROM branches WHERE id=6')->fetchColumn();
        $pdo->prepare('INSERT INTO hq_invoices(sth_id,suffix,doc_no,doc_type,supplier_id,supplier_code,supplier_name,net_amount,total_amount,invoice_date,company_branch) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([999829001,'98_29','B829-NET',2,999999829,'B829','مورد تجربة بريف ٨٢٩',108,120,date('Y-m-d'),$sto]);
        $match=purchase_match_hq($pdo,[$id]);
        b829_check('المطابقة على صافي 108 مش إجمالي 120',($match[$id]['state']??'')==='matched',$match[$id]);
        unset($head['inv_discount']);
        $head['orig_doc_id']=$id;
        $rid = purchase_save_draft($pdo,'purchase_return',$head,[['code'=>'B829-ROTO','qty_units'=>2,'unit_price'=>12,'discount_pct'=>50]],1);
        $retLine = $pdo->query("SELECT unit_price,unit_cost,line_total FROM stock_doc_lines WHERE doc_id=$rid")->fetch(PDO::FETCH_ASSOC);
        b829_check('حفظ مرتجع 21.60 رغم سعر POST 12',(float)$retLine['unit_price']===10.8&&(float)$retLine['line_total']===21.6,$retLine);
        purchase_post($pdo,$rid,1);
        $rejected=false;
        $postedHead=$head;
        unset($postedHead['orig_doc_id']);
        try { purchase_save_draft($pdo,'purchase',$postedHead,[['code'=>'B829-ROTO','qty_units'=>10,'unit_price'=>12]],1,$id); } catch (RuntimeException $e) { $rejected=true; }
        b829_check('المترحل مابيتعدلش',$rejected&&(float)$pdo->query("SELECT total_amount FROM stock_docs WHERE id=$id")->fetchColumn()===108.0);
        unset($head['orig_doc_id']);
        $head['inv_discount']=['type'=>'amount','value'=>10];
        $draft=purchase_save_draft($pdo,'purchase',$head,array_fill(0,3,['code'=>'B829-ROTO','qty_units'=>1,'unit_price'=>10]),1);
        $head['inv_discount']=['type'=>'amount','value'=>1];
        purchase_save_draft($pdo,'purchase',$head,[['code'=>'B829-ROTO','qty_units'=>1,'unit_price'=>10]],1,$draft);
        $stale=$pdo->query("SELECT SUM(inv_discount_alloc) alloc,SUM(line_total) total,SUM(qty_pieces) qty FROM stock_doc_lines WHERE doc_id=$draft")->fetch(PDO::FETCH_ASSOC);
        b829_check('تعديل المسودة بيصفر خصم السطور القديمة',(float)$stale['alloc']===1.0&&(float)$stale['total']===9.0&&(float)$stale['qty']===1.0,$stale);
        unset($head['inv_discount']);
        purchase_save_draft($pdo,'purchase',$head,[['code'=>'B829-ROTO','qty_units'=>10,'unit_price'=>12,'discount_pct'=>5]],1,$draft);
        $before=$pdo->query("SELECT l.line_total,l.unit_cost,d.total_amount FROM stock_doc_lines l JOIN stock_docs d ON d.id=l.doc_id WHERE l.doc_id=$draft AND l.qty_pieces>0")->fetch(PDO::FETCH_ASSOC);
        purchase_save_draft($pdo,'purchase',$head,[['code'=>'B829-ROTO','qty_units'=>10,'unit_price'=>12,'discount_pct'=>5]],1,$draft);
        $after=$pdo->query("SELECT l.line_total,l.unit_cost,d.total_amount FROM stock_doc_lines l JOIN stock_docs d ON d.id=l.doc_id WHERE l.doc_id=$draft AND l.qty_pieces>0")->fetch(PDO::FETCH_ASSOC);
        b829_check('إعادة حفظ من غير خصم بتحافظ على الأرقام',$before===$after&&(float)$after['total_amount']===114.0,$after);
    } finally {
        $pdo->rollBack();
    }
}
echo "Passed $passed purchase net cost checks\n";
