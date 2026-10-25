<?php
/** اختبارات بريف ٢١٧ على أصناف الحجز 999970–999989 والمخزن ٦ بس. */
declare(strict_types=1);

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds4';
if (!is_file("$root/config.php")) { fwrite(STDERR,"تعذر العثور على config.php في $root\n"); exit(1); }
require_once "$root/config.php";
require_once __DIR__ . '/../../site/item_units_lib.php';
require_once __DIR__ . '/../../site/stock_lib.php';
require_once __DIR__ . '/../../site/purchase_doc_lib.php';

$pdo=db(); $results=[]; $docs=[]; $madeSuppliers=[];
function b217(int $n,string $name,bool $ok,string $details=''):void{global $results;$results[$n]=$ok;echo '['.($ok?'✓':'✗')."] الحالة $n: $name\n";if($details!=='')echo "    $details\n";}
function b217_setting(PDO $pdo,string $key):?string{$st=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$st->execute([$key]);$v=$st->fetchColumn();return $v===false?null:(string)$v;}
function b217_finance(PDO $pdo,int $supplierId):array{
    $st=$pdo->prepare('SELECT balance FROM suppliers WHERE id=?');$st->execute([$supplierId]);
    $j=$pdo->query('SELECT COUNT(*) c,COALESCE(SUM(debit),0) debit_sum,COALESCE(SUM(credit),0) credit_sum FROM journal_entries')->fetch(PDO::FETCH_ASSOC);
    return ['balance'=>(string)$st->fetchColumn(),'journal_count'=>(int)$j['c'],'debit_sum'=>(string)$j['debit_sum'],'credit_sum'=>(string)$j['credit_sum']];
}

$item=999970;$code='B217-999970';$branch=6;$uid=1;$date=date('Y-m-d');
$oldEnabled=b217_setting($pdo,'erp_stock_enabled');$oldMode=b217_setting($pdo,'stock_mode_6');
try{
    // تنظيف حصري لنفس fixture لو تشغيل قديم وقف قبل finally.
    $old=$pdo->query("SELECT id FROM stock_docs WHERE notes LIKE 'test_b217:%'")->fetchAll(PDO::FETCH_COLUMN);
    if($old){$m=implode(',',array_map('intval',$old));$pdo->exec("DELETE FROM doc_events WHERE doc_kind='stock' AND doc_id IN ($m)");$pdo->exec("DELETE FROM stock_moves WHERE doc_id IN ($m)");$pdo->exec("DELETE FROM stock_doc_lines WHERE doc_id IN ($m)");$pdo->exec("DELETE FROM stock_docs WHERE id IN ($m)");}
    $pdo->exec("DELETE FROM stock_balances WHERE item_id BETWEEN 999970 AND 999989 AND branch_id=6");
    $pdo->exec("DELETE FROM hq_invoices WHERE sth_id IN (999970001,999970002) AND suffix='99_97'");
    $pdo->prepare('INSERT INTO hq_codes(code,item_id,name,code_type,unit_factor,price,cost) VALUES(?,?,?,1,20,40,2) ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),name=VALUES(name),unit_factor=VALUES(unit_factor),cost=VALUES(cost)')->execute([$code,$item,'صنف اختبار ٢١٧']);

    $erpSupplier=999979;
    $st=$pdo->prepare('SELECT id FROM suppliers WHERE erp_supplier_id=? LIMIT 1');$st->execute([$erpSupplier]);$supplierId=(int)$st->fetchColumn();
    if(!$supplierId){$pdo->prepare('INSERT INTO suppliers(name,erp_supplier_id,erp_supplier_code,is_active) VALUES(?,?,?,1)')->execute(['مورد اختبار بريف ٢١٧',$erpSupplier,'B217']);$supplierId=(int)$pdo->lastInsertId();$madeSuppliers[]=$supplierId;}
    $internal=$pdo->query('SELECT id FROM suppliers WHERE erp_supplier_id IN (4,1000180,1000181,3000091,1000182,1000245,1000252) LIMIT 1')->fetchColumn();
    if($internal===false){$pdo->prepare('INSERT INTO suppliers(name,erp_supplier_id,erp_supplier_code,is_active) VALUES(?,?,?,1)')->execute(['فرع داخلي اختبار ٢١٧',1000182,'B217-I']);$internal=(int)$pdo->lastInsertId();$madeSuppliers[]=(int)$internal;}
    $stoId=(int)$pdo->query('SELECT sto_id FROM branches WHERE id=6')->fetchColumn();
    $financeBefore=b217_finance($pdo,$supplierId);

    $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='erp_stock_enabled'");
    $pdo->exec("UPDATE settings SET setting_value='live' WHERE setting_key='stock_mode_6'");

    $closed=purchase_save_draft($pdo,'purchase',['branch_id'=>$branch,'supplier_id'=>$supplierId,'doc_date'=>$date,'ref_text'=>'B217-CLOSED','notes'=>'test_b217:closed'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>1,'unit_price'=>40]],$uid);$docs[]=$closed;$closedMsg='';try{purchase_post($pdo,$closed,$uid);}catch(RuntimeException $e){$closedMsg=$e->getMessage();}
    b217(1,'erp_stock_enabled=0 يرفض الترحيل',str_contains($closedMsg,'لسه مقفول'),$closedMsg);
    $pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='erp_stock_enabled'");

    $opening=stock_doc_create($pdo,'adjust',['branch_id'=>$branch,'doc_date'=>$date,'notes'=>'test_b217:opening'],[['item_id'=>$item,'code'=>$code,'qty_units'=>5,'unit_factor'=>20,'unit_cost'=>2]],$uid);$docs[]=$opening;stock_post($pdo,$opening,$uid);
    $before=stock_balance($pdo,$item,$branch);

    $purchase=purchase_save_draft($pdo,'purchase',['branch_id'=>$branch,'supplier_id'=>$supplierId,'doc_date'=>$date,'ref_text'=>'B217-INV-1','notes'=>'test_b217:purchase'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>5,'unit_price'=>40,'discount_pct'=>10]],$uid);$docs[]=$purchase;
    $line=$pdo->query("SELECT * FROM stock_doc_lines WHERE doc_id=$purchase")->fetch(PDO::FETCH_ASSOC);
    purchase_post($pdo,$purchase,$uid);$afterPurchase=stock_balance($pdo,$item,$branch);
    b217(2,'شراء باكو ×٢٠ وخصم ١٠٪',(float)$line['qty_pieces']===100.0&&(float)$line['line_total']===180.0&&(float)$line['unit_cost']===1.8,"qty_pieces={$line['qty_pieces']} · line_total={$line['line_total']} · unit_cost={$line['unit_cost']}");
    b217(3,'متوسط التكلفة المتحرك',(float)$before['qty']===100.0&&(float)$afterPurchase['qty']===200.0&&round((float)$afterPurchase['avg_cost'],4)===1.9,"قبل {$before['qty']} @ {$before['avg_cost']} · بعد {$afterPurchase['qty']} @ {$afterPurchase['avg_cost']}");

    $ret=purchase_save_draft($pdo,'purchase_return',['branch_id'=>$branch,'supplier_id'=>$supplierId,'orig_doc_id'=>$purchase,'doc_date'=>$date,'ref_text'=>'B217-RET-1','notes'=>'test_b217:return'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>2,'unit_price'=>40,'discount_pct'=>10]],$uid);$docs[]=$ret;purchase_post($pdo,$ret,$uid);
    $afterReturn=stock_balance($pdo,$item,$branch);$retMove=$pdo->query("SELECT qty,unit_cost FROM stock_moves WHERE doc_id=$ret ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    b217(4,'مرتجع ٢ باكو بمتوسط المخزن',(float)$afterReturn['qty']===160.0&&(float)$retMove['qty']===-40.0&&round((float)$retMove['unit_cost'],4)===1.9,"الرصيد {$afterReturn['qty']} · الحركة {$retMove['qty']} @ {$retMove['unit_cost']}");

    $over=purchase_save_draft($pdo,'purchase_return',['branch_id'=>$branch,'supplier_id'=>$supplierId,'orig_doc_id'=>$purchase,'doc_date'=>$date,'ref_text'=>'B217-RET-2','notes'=>'test_b217:over'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>4,'unit_price'=>40,'discount_pct'=>0]],$uid);$docs[]=$over;$msg='';try{purchase_post($pdo,$over,$uid);}catch(RuntimeException $e){$msg=$e->getMessage();}
    b217(5,'إجمالي المرتجع أكبر من المشتري يترفض',str_contains($msg,'أكبر من المتاح')&&str_contains($msg,'20.000'),$msg);

    $dup=purchase_save_draft($pdo,'purchase',['branch_id'=>$branch,'supplier_id'=>$supplierId,'doc_date'=>$date,'ref_text'=>'B217-INV-1','notes'=>'test_b217:duplicate'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>1,'unit_price'=>40]],$uid);$docs[]=$dup;$dupMsg='';try{purchase_post($pdo,$dup,$uid);}catch(RuntimeException $e){$dupMsg=$e->getMessage();}
    b217(6,'تكرار رقم فاتورة المورد في نفس السنة يترفض',str_contains($dupMsg,'اتسجّلت قبل كده'),$dupMsg);

    $internalMsg='';try{purchase_save_draft($pdo,'purchase',['branch_id'=>$branch,'supplier_id'=>(int)$internal,'doc_date'=>$date,'notes'=>'test_b217:internal'],[['code'=>$code,'item_id'=>$item,'qty_units'=>1,'unit_price'=>1]],$uid);}catch(RuntimeException $e){$internalMsg=$e->getMessage();}
    b217(7,'المورد الداخلي يترفض من السيرفر',str_contains($internalMsg,'فرع داخلي'),$internalMsg);

    $cancelMsg='';try{purchase_cancel($pdo,$purchase,$uid,'اختبار منع الإلغاء');}catch(RuntimeException $e){$cancelMsg=$e->getMessage();}
    b217(8,'إلغاء فاتورة عليها مرتجع يترفض',str_contains($cancelMsg,'ألغي المرتجع الأول'),$cancelMsg);

    $insHq=$pdo->prepare('INSERT INTO hq_invoices(sth_id,suffix,doc_no,doc_type,supplier_id,supplier_code,supplier_name,net_amount,total_amount,invoice_date,company_branch) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $insHq->execute([999970001,'99_97','TRUE-B217-1',2,$erpSupplier,'B217','مورد اختبار بريف ٢١٧',180,180,date('Y-m-d',strtotime('+1 day')),$stoId]);
    $match=purchase_match_hq($pdo,[$purchase]);
    b217(9,'مطابقة True في فرق يوم ومبلغ أقل من جنيه',($match[$purchase]['state']??'')==='matched'&&$match[$purchase]['doc_no']==='TRUE-B217-1',json_encode($match[$purchase],JSON_UNESCAPED_UNICODE));
    $insHq->execute([999970002,'99_97','TRUE-B217-2',2,$erpSupplier,'B217','مورد اختبار بريف ٢١٧',180.5,180.5,$date,$stoId]);
    $match2=purchase_match_hq($pdo,[$purchase]);$stored=$pdo->query("SELECT hq_match FROM stock_docs WHERE id=$purchase")->fetchColumn();
    b217(10,'أكتر من مطابقة تبقى مش أكيدة من غير حفظ',($match2[$purchase]['state']??'')==='ambiguous'&&$stored===null,json_encode($match2[$purchase],JSON_UNESCAPED_UNICODE).' · المخزن NULL');

    $sum=$pdo->prepare('SELECT ROUND(SUM(m.qty),3) moves_qty,b.qty balance_qty FROM stock_moves m JOIN stock_balances b ON b.item_id=m.item_id AND b.branch_id=m.branch_id AND b.is_transit=m.is_transit WHERE m.item_id=? AND m.branch_id=? GROUP BY b.qty');$sum->execute([$item,$branch]);$sumRow=$sum->fetch(PDO::FETCH_ASSOC);
    b217(11,'مجموع الحركات يساوي الرصيد',(float)$sumRow['moves_qty']===(float)$sumRow['balance_qty'],"SUM={$sumRow['moves_qty']} · balance={$sumRow['balance_qty']}");

    $financeAfter=b217_finance($pdo,$supplierId);
    b217(12,'القيود ورصيد المورد ماتغيروش',$financeBefore===$financeAfter,'قبل '.json_encode($financeBefore,JSON_UNESCAPED_UNICODE).' · بعد '.json_encode($financeAfter,JSON_UNESCAPED_UNICODE));

    $pdo->exec("UPDATE settings SET setting_value='mirror' WHERE setting_key='stock_mode_6'");
    $mirror=purchase_save_draft($pdo,'purchase',['branch_id'=>$branch,'supplier_id'=>$supplierId,'doc_date'=>$date,'ref_text'=>'B217-MIRROR','notes'=>'test_b217:mirror'],[['code'=>$code,'item_id'=>$item,'unit_factor'=>20,'qty_units'=>1,'unit_price'=>40]],$uid);$docs[]=$mirror;$mirrorMsg='';try{purchase_post($pdo,$mirror,$uid);}catch(RuntimeException $e){$mirrorMsg=$e->getMessage();}
    b217(14,'المخزن mirror يرفض الترحيل',str_contains($mirrorMsg,'وضع المرآة'),$mirrorMsg);
}catch(Throwable $e){echo "[✗] خطأ أوقف الاختبار: {$e->getMessage()}\n";$results[99]=false;}
finally{
    if($docs){$m=implode(',',array_map('intval',$docs));$pdo->exec("DELETE FROM doc_events WHERE doc_kind='stock' AND doc_id IN ($m)");$pdo->exec("DELETE FROM stock_moves WHERE doc_id IN ($m)");$pdo->exec("DELETE FROM stock_doc_lines WHERE doc_id IN ($m)");$pdo->exec("DELETE FROM stock_docs WHERE id IN ($m)");}
    $pdo->exec("DELETE FROM stock_balances WHERE item_id BETWEEN 999970 AND 999989 AND branch_id=6");
    $pdo->exec("DELETE FROM hq_invoices WHERE sth_id IN (999970001,999970002) AND suffix='99_97'");
    $pdo->exec("DELETE FROM hq_codes WHERE code='B217-999970' AND item_id=999970");
    foreach($madeSuppliers as $sid){$pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$sid]);}
    if($oldEnabled!==null)$pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='erp_stock_enabled'")->execute([$oldEnabled]);
    if($oldMode!==null)$pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='stock_mode_6'")->execute([$oldMode]);
}

$passed=count(array_filter($results));echo "النتيجة: $passed من ".count($results)." حالة نجحت\n";exit($passed===count($results)?0:1);
