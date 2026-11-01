<?php
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/sensitive_lib.php';
api_begin();
require_login();
api_require('item_sales:profit');
sens_page();
require_once __DIR__ . '/fifo_lib.php';

$part=(string)($_GET['part'] ?? '');
if (!in_array($part, ['status', 'suppliers'], true) && !sens_unlocked()) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}
if ($part==='rebuild') {
    api_method('POST'); api_csrf();
    session_write_close();
    set_time_limit(0);
    api_ok(fifo_rebuild(db()));
}
api_method('GET');
if ($part==='status') api_ok(fifo_status(db()));
if ($part==='suppliers') {
    $names=db()->query('SELECT sto_id,name FROM hq_stores')->fetchAll(PDO::FETCH_KEY_PAIR);
    $stores=[];
    foreach (REORDER_AGENT_TO_STO as $key=>$sto) $stores[]=['key'=>$key,'name'=>$names[$sto] ?? $key];
    api_ok(['rows'=>fifo_suppliers(db()),'stores'=>$stores]);
}
$from=(string)($_GET['from'] ?? '2026-06-27'); $to=(string)($_GET['to'] ?? date('Y-m-d'));
foreach ([$from,$to] as $date) {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$d || $d->format('Y-m-d')!==$date) api_fail('التاريخ مش صحيح');
}
if ($from>$to || $from<'2026-06-27') api_fail('الفترة لازم تبدأ من ٢٧/٦/٢٠٢٦، ومن قبل إلى');
$report=$part==='export' ? (string)($_GET['report'] ?? '') : $part;
$store=(string)($_GET['store'] ?? 'all'); $group=(string)($_GET['group'] ?? 'item');
if ($report==='store_profit' && $store!=='all' && !isset(REORDER_AGENT_TO_STO[$store])) api_fail('المخزن مش صحيح');
if (!in_array($group,['item','category'],true)) api_fail('طريقة العرض مش صحيحة');
// نفس لقطة الداتا لكل أجزاء الرد، حتى لو الحساب اتبدّل وإحنا بنقرا.
$pdo=db(); $pdo->beginTransaction();
switch ($report) {
    case 'supplier_invoices':
        $sid=filter_var($_GET['supplier_id'] ?? '',FILTER_VALIDATE_INT);
        if (!$sid || in_array($sid,fifo_internal_ids(),true)) api_fail('اختار مورد صحيح');
        $data=fifo_supplier_invoices($pdo,$sid,$from,$to); break;
    case 'store_profit': $data=fifo_store_profit($pdo,$store,$from,$to,$group); break;
    case 'suppliers_profit': $data=fifo_suppliers_profit($pdo,$from,$to); break;
    default: api_fail('جزء مش معروف');
}
$data['status']=fifo_status($pdo); $pdo->commit();
if ($part!=='export') api_ok($data);

require_once __DIR__ . '/xlsx_lib.php';
$xlsx=new SimpleXlsx('أرباح الموردين');
$cols=$report==='supplier_invoices'
    ? ['doc_no'=>'الفاتورة','lot_date'=>'التاريخ','store_name'=>'المخزن','code'=>'الكود','name'=>'الصنف','qty_in'=>'الكمية','qty_returned'=>'المرتجع','unit_cost'=>'تكلفة القطعة','sale_price'=>'سعر البيع','sold'=>'إجمالي المباع','zahraa'=>'الفجالة','bonjour'=>'بونجور','degla'=>'دجلة','abc'=>'اي بي سي','ashraqa'=>'إشراقة','remaining'=>'الباقي','revenue'=>'الإيراد','cost'=>'التكلفة','profit'=>'الربح','margin'=>'النسبة','sell_through'=>'نسبة البيع','sold_days'=>'اتباعت في']
    : ($report==='store_profit'
        ? ['name'=>'الصنف / التصنيف','supplier_name'=>'المورد','in_qty'=>'الوارد','sold'=>'الصادر','ashraqa'=>'إشراقة','revenue'=>'الإيراد','cost'=>'التكلفة','profit'=>'الربح','margin'=>'النسبة','balance'=>'الرصيد','no_purchase_qty'=>'من غير شراء']
        : ['name'=>'المورد','qty_in'=>'المشترى','qty_returned'=>'المرتجع','sold'=>'المباع','ashraqa'=>'إشراقة','revenue'=>'الإيراد','cost'=>'التكلفة','profit'=>'الربح','margin'=>'النسبة','sell_through'=>'نسبة البيع','no_purchase_qty'=>'من غير شراء']);
if ($report==='store_profit' && $group==='item') $cols=['code'=>'الكود']+$cols;
$xlsx->setColumnTypes(array_map(static fn($k)=>in_array($k,['code','doc_no','lot_date','store_name','name','supplier_name'],true) ? 's' : 'n',array_keys($cols)));
$xlsx->addRow(array_values($cols),true);
foreach ($data['rows'] as $row) {
    $lines=$report==='supplier_invoices' ? $row['lines'] : [$row];
    foreach ($lines as $line) {
        if ($report==='supplier_invoices') $line=array_merge($line,array_intersect_key($row,array_flip(['doc_no','lot_date','store_name'])));
        $xlsx->addRow(array_map(static fn($key)=>$line[$key] ?? '—',array_keys($cols)));
    }
}
$xlsx->addRow(['الإجمالي'],true);
$xlsx->addRow(array_map(static fn($key)=>$data['totals'][$key] ?? '—',array_keys($cols)));
$xlsx->addRow(['اتبعت لإشراقة',(float)$data['totals']['ashraqa'],'قطعة بتكلفة',(float)$data['totals']['ashraqa_cost']]);
$xlsx->addRow(['من غير فاتورة شراء',(float)$data['totals']['no_purchase_qty']]);
$xlsx->addRow(['إيراد البيع من غير فاتورة شراء',(float)$data['totals']['no_purchase_revenue']]);
if ($report!=='store_profit') {
    $xlsx->addRow(['عمر الباقي لحد النهارده'],true);
    $xlsx->addRow(['المورد / الفاتورة','العمر بالأيام','الكمية','القيمة'],true);
    foreach ($data['rows'] as $row) foreach (['0-30','31-60','61-90','90+'] as $bucket) {
        $xlsx->addRow([$row['name'] ?? $row['doc_no'],$bucket,(float)($row['aging'][$bucket]['qty'] ?? 0),(float)($row['aging'][$bucket]['value'] ?? 0)]);
    }
}
$xlsx->download('fifo_'.$report.'_'.$from.'_'.$to.'.xlsx');
