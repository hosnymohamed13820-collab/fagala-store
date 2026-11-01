<?php
declare(strict_types=1);
require_once __DIR__.'/../site/fifo_lib.php';

$checks=0;
function check834(bool $ok, string $message): void {
    global $checks; ++$checks;
    if (!$ok) throw new RuntimeException('فشل: '.$message);
    echo '✓ '.$message.PHP_EOL;
}
function close834(float $a,float $b): bool { return abs($a-$b)<0.00001; }
function event834(int $type,string $date,float $qty,float $amount=0,int $supplier=101,string $agent='',int $id=1): array {
    return ['doc_type'=>$type,'invoice_date'=>$date.' 12:00:00','qty'=>$qty,'revenue'=>$amount,'supplier_id'=>$supplier,
        'supplier_name'=>'س','agent_key'=>$agent,'sth_id'=>$id,'suffix'=>'26_09','line_no'=>1,'item_id'=>1];
}
function profit834(array $r): float {
    $total=0.0;
    foreach ($r['alloc'] as $a) if ($a['cost']!==null && $a['agent_key']!=='ashraqa') $total+=$a['revenue']-$a['cost'];
    return $total;
}
$opening=event834(2,'2026-06-27',10,30); $opening['supplier_name']='رصيد افتتاحي';
$r=fifo_compute_item([$opening,event834(2,'2026-07-01',20,100,102),event834(4,'2026-07-02',15,150,0,'zahraa')]);
check834(close834($r['alloc'][0]['qty'],10) && close834($r['alloc'][0]['cost'],30) && close834($r['alloc'][1]['qty'],5) && close834(profit834($r),95),'١: الافتتاحي قبل الشراء والربح 95');
$r=fifo_compute_item([event834(2,'2026-09-05',10,40),event834(2,'2026-09-10',10,60),event834(6,'2026-09-06',3)]);
check834(close834($r['lots'][0]['qty_returned'],3) && close834($r['lots'][1]['qty_returned'],0),'٢: المردود على ٥/٩، مش ١٠/٩');
$r=fifo_compute_item([event834(2,'2026-09-01',2,8),event834(4,'2026-09-02',2,20,0,'zahraa'),event834(2,'2026-09-05',10,40),event834(4,'2026-09-05',9,90,0,'zahraa',2),event834(6,'2026-09-06',3)]);
check834(close834($r['lots'][1]['qty_returned'],1) && close834($r['warnings']['purchase_return_without_lot'],2),'٣: قطعة من الباقي ومردود من غير دفعة 2');
$r=fifo_compute_item([event834(2,'2026-09-01',2,8),event834(4,'2026-09-02',2,20,0,'zahraa'),event834(2,'2026-09-05',10,40),event834(4,'2026-09-05',9,90,0,'abc',2),event834(7,'2026-09-05',2,20,0,'zahraa',3),event834(6,'2026-09-06',3)]);
check834(close834($r['lots'][1]['qty_returned'],1) && close834($r['lots'][0]['qty_returned'],2),'٣ إضافي: المردود بيرجع للفواتير الأقدم لنفس المورد');
$r=fifo_compute_item([event834(2,'2026-09-01',10,20,102),event834(6,'2026-09-02',3)]);
check834(close834($r['lots'][0]['qty_returned'],0) && close834($r['warnings']['purchase_return_without_lot'],3),'٤: مورد تاني مايتلمسش');
$r=fifo_compute_item([event834(2,'2026-09-01',10,40),event834(4,'2026-09-02',4,40,0,'degla'),event834(7,'2026-09-03',1,10,0,'degla')]);
check834(close834($r['alloc'][1]['qty'],-1) && $r['alloc'][1]['lot_id']===$r['alloc'][0]['lot_id'] && $r['alloc'][1]['agent_key']==='degla' && close834($r['alloc'][1]['cost'],-4),'٥: مردود البيع بالسالب على نفس الدفعة والفرع');
$r=fifo_compute_item([event834(2,'2026-09-01',2,10),event834(4,'2026-09-02',5,50,0,'abc'),event834(2,'2026-09-03',10,100)]);
check834($r['lots'][1]['kind']==='no_purchase' && $r['alloc'][1]['cost']===null && close834($r['alloc'][1]['revenue'],30) && close834(profit834($r),10),'٦: الزيادة من غير تكلفة وربحها بره الإجمالي ومابتتخصصش تاني');
$u=event834(2,'2026-09-01',1); unset($u['qty'],$u['revenue']);
$u+=['quantity'=>2,'unit_factor'=>12,'unit_price'=>120,'total_amount'=>240,'net_amount'=>240,'item_code'=>'x'];
$r=fifo_compute_item([$u]);
check834(close834($r['lots'][0]['qty_in'],24) && close834($r['lots'][0]['unit_cost'],10),'٧: ٢ كرتونة = ٢٤ قطعة وتكلفة القطعة 10');
$u['quantity']=1; $u['unit_factor']=null; $u['unit_price']=100; $u['total_amount']=200; $u['net_amount']=180;
$n=fifo_normalize_event($u,['x'=>['unit_factor'=>12]]);
check834(close834($n['qty'],12) && close834($n['revenue'],90),'٨: خصم الفاتورة ومعامل الكود الاحتياطي');
$u['total_amount']=0;
check834(close834(fifo_normalize_event($u)['revenue'],100),'٨ إضافي: إجمالي صفر يخلي نسبة الخصم 1');
$ids=fifo_internal_ids();
$r=fifo_compute_item([event834(2,'2026-09-01',10,50),event834(2,'2026-09-02',50,100,$ids[0]),event834(6,'2026-09-03',30,0,$ids[1])]);
check834(count($r['lots'])===1 && close834($r['lots'][0]['qty_returned'],0) && close834($r['warnings']['purchase_return_without_lot'],0),'٨أ: التحويلات مش دفعات ولا مردودات');
$r=fifo_compute_item([event834(2,'2026-09-01',10,50),event834(6,'2026-09-02',4,80,ASHRAQA_ERP_IDS[0])]);
check834($r['alloc'][0]['agent_key']==='ashraqa' && close834($r['alloc'][0]['qty'],4) && close834($r['lots'][0]['qty_returned'],0) && close834(profit834($r),0),'٨ب: مردود إشراقة صادر وربحه بره الإجمالي');
$r=fifo_compute_item([event834(2,'2026-09-01',10,50,ASHRAQA_ERP_IDS[1])]);
check834($r['lots'][0]['supplier_name']==='إشراقة','٨ب إضافي: شراء إشراقة دفعة عادية');
$r=fifo_compute_item([event834(2,'2026-09-17',10,50),event834(4,'2026-09-18',4,40,0,'abc')]);
$lot=$r['lots'][0]; $left=$lot['qty_in']-$lot['qty_returned']-array_sum(array_column($r['alloc'],'qty'));
$bucket=fifo_age_bucket(substr($lot['lot_date'],0,10),'2026-11-01');
check834($bucket==='31-60' && close834($left,6) && close834($left*$lot['unit_cost'],30),'٨ج: ٤٥ يوم في خانة 31–60، كمية 6 وقيمة 30');
check834(fifo_sold_days('2026-09-01','2026-09-11',0)===10 && fifo_sold_days('2026-09-01','2026-09-11',1)===null,'٨د: اتباعت في 10 أيام، أو لسه');
$r=fifo_compute_item([event834(4,'2026-09-01',2,20,0,'abc',1),event834(2,'2026-09-01',2,10,101,'',2)]);
check834($r['lots'][0]['kind']==='purchase' && close834(profit834($r),10),'الشراء قبل البيع في نفس اللحظة');
$r=fifo_compute_item([event834(7,'2026-09-01',3,30,0,'abc')]);
check834(close834($r['alloc'][0]['qty'],-3) && $r['alloc'][0]['cost']===null && close834($r['warnings']['sale_return_without_sale'],3),'مردود من غير بيع محفوظ بكميته في سجل التشغيلة');
$unknown=fifo_finish_row(['revenue'=>30,'cost'=>0,'profit_revenue'=>0,'known_rows'=>0,'sold'=>3,'no_purchase_qty'=>3]);
$known=fifo_finish_row(['revenue'=>20,'cost'=>10,'profit_revenue'=>20,'known_rows'=>1,'sold'=>2]);
$total=fifo_totals([$unknown,$known]);
check834($unknown['profit']===null && $unknown['margin']===null && close834($total['revenue'],20) && close834($total['profit'],10) && close834($total['margin'],50) && close834($total['no_purchase_revenue'],30),'إجمالي الإيراد والربح والنسبة بيستبعد من غير شراء، وفلوسه ظاهرة لوحدها');
echo "عدّى $checks فحص\n";
