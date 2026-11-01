<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_doc_lib.php';
require_once __DIR__ . '/branch_recon_lib.php';
require_once __DIR__ . '/reorder_lib.php';

function fifo_internal_ids(): array
{
    return array_values(array_diff(PURCHASE_INTERNAL_ERP_SUPPLIERS, ASHRAQA_ERP_IDS));
}

/** نفس تحويل كارت الصنف، وصافي السطر بعد خصم الفاتورة. */
function fifo_normalize_event(array $e, array $factors = []): array
{
    if (!isset($e['qty'])) {
        $u = item_line_pieces((float)$e['quantity'], isset($e['unit_factor']) ? (float)$e['unit_factor'] : null,
            isset($factors[$e['item_code']]) ? (float)$factors[$e['item_code']]['unit_factor'] : null);
        $e['qty'] = $u['pieces'];
        $e['revenue'] = (float)$e['quantity'] * (float)$e['unit_price']
            * ((float)$e['total_amount'] != 0 ? (float)$e['net_amount'] / (float)$e['total_amount'] : 1);
    }
    $e += ['supplier_id'=>0, 'supplier_name'=>'', 'sth_id'=>0, 'suffix'=>'', 'line_no'=>0, 'doc_no'=>'', 'agent_key'=>''];
    $e['doc_type'] = (int)$e['doc_type'];
    $e['supplier_id'] = (int)$e['supplier_id'];
    $e['supplier_name'] = (string)$e['supplier_name'];
    if ((float)$e['qty']<0) throw new RuntimeException('فيه سطر كميته سالبة؛ راجع داتا المصدر قبل الحساب');
    if ((float)$e['qty']>0 && (int)($e['item_id'] ?? 0)<=0) throw new RuntimeException('فيه سطر من غير رقم صنف؛ راجع داتا المصدر قبل الحساب');
    if ($e['agent_key'] === '' && $e['doc_type'] === 6 && in_array($e['supplier_id'], ASHRAQA_ERP_IDS, true)) {
        $e['agent_key'] = 'ashraqa';
    }
    return $e;
}

/** الطابور لصنف واحد بس، من غير أي اتصال بالقاعدة. */
function fifo_compute_item(array $events): array
{
    $events = array_map('fifo_normalize_event', $events);
    usort($events, static fn($a,$b) => [$a['invoice_date'], $a['doc_type'] === 2 ? 0 : 1, (int)$a['sth_id'], (int)$a['line_no'], $a['suffix'], $a['agent_key']]
        <=> [$b['invoice_date'], $b['doc_type'] === 2 ? 0 : 1, (int)$b['sth_id'], (int)$b['line_no'], $b['suffix'], $b['agent_key']]);
    $lots = []; $alloc = []; $remaining = []; $history = []; $head = 0;
    $warnings = ['purchase_return_without_lot'=>0.0, 'sale_without_purchase'=>0.0, 'sale_return_without_sale'=>0.0];
    foreach ($events as $e) {
        if ($e['invoice_date'] < '2026-06-27' || $e['qty'] <= 0) continue;
        if ($e['agent_key'] === '' && in_array($e['supplier_id'], fifo_internal_ids(), true)) continue;
        $q = (float)$e['qty']; $type = $e['doc_type'];
        if ($type === 2) {
            $id = count($lots);
            $lots[] = ['lot_id'=>$id, 'item_id'=>$e['item_id'], 'kind'=>'purchase', 'supplier_id'=>$e['supplier_id'],
                'supplier_name'=>in_array($e['supplier_id'], ASHRAQA_ERP_IDS, true) ? 'إشراقة' : $e['supplier_name'],
                'sth_id'=>$e['sth_id'], 'suffix'=>$e['suffix'], 'line_no'=>$e['line_no'], 'doc_no'=>$e['doc_no'],
                'lot_date'=>$e['invoice_date'], 'qty_in'=>$q, 'qty_returned'=>0.0, 'unit_cost'=>(float)$e['revenue']/$q];
            $remaining[$id] = $q;
            continue;
        }
        if ($type === 6 && $e['agent_key'] !== 'ashraqa') {
            for ($i=count($lots)-1; $i>=0 && $q>0.0000001; --$i) {
                if ($lots[$i]['kind'] !== 'purchase' || $lots[$i]['supplier_id'] !== $e['supplier_id']
                    || $lots[$i]['lot_date'] > $e['invoice_date']) continue;
                $take = min($q, $remaining[$i]);
                $lots[$i]['qty_returned'] += $take; $remaining[$i] -= $take; $q -= $take;
            }
            $warnings['purchase_return_without_lot'] += $q;
            continue;
        }
        $return = in_array($type, [7,17], true);
        $parts = [];
        if ($return) {
            // آخر استهلاك لنفس الفرع؛ المردود بيرجع على نفس دفعاته.
            $key = $e['agent_key'];
            while ($q>0.0000001 && !empty($history[$key])) {
                $h = array_key_last($history[$key]); $a = $history[$key][$h];
                $take = min($q, $a['left']); $id = $a['lot_id'];
                $parts[] = [$id, -$take]; $remaining[$id] += $take;
                if ($lots[$id]['kind'] === 'purchase') $head = min($head, $id);
                $q -= $take; $history[$key][$h]['left'] -= $take;
                if ($history[$key][$h]['left'] < 0.0000001) array_pop($history[$key]);
            }
            $warnings['sale_return_without_sale'] += $q;
        } else {
            while ($head<count($lots) && $q>0.0000001) {
                if ($lots[$head]['kind'] !== 'purchase' || $remaining[$head]<0.0000001) { ++$head; continue; }
                $take = min($q, $remaining[$head]);
                $parts[] = [$head, $take]; $remaining[$head] -= $take; $q -= $take;
            }
        }
        if ($q>0.0000001) {
            $id=count($lots);
            $lots[]=['lot_id'=>$id,'item_id'=>$e['item_id'],'kind'=>'no_purchase','supplier_id'=>null,
                'supplier_name'=>'من غير فاتورة شراء','sth_id'=>$e['sth_id'],'suffix'=>$e['suffix'],'line_no'=>$e['line_no'],
                'doc_no'=>$e['doc_no'],'lot_date'=>$e['invoice_date'],'qty_in'=>$return ? 0 : $q,'qty_returned'=>0.0,'unit_cost'=>null];
            $remaining[$id]=$return ? $q : 0.0;
            $parts[]=[$id,$return ? -$q : $q];
            if (!$return) $warnings['sale_without_purchase'] += $q;
        }
        foreach ($parts as [$id,$qty]) {
            $alloc[]=['lot_id'=>$id,'item_id'=>$e['item_id'],'agent_key'=>$e['agent_key'],'sale_sth_id'=>$e['sth_id'],
                'sale_suffix'=>$e['suffix'],'sale_line_no'=>$e['line_no'],'sale_date'=>$e['invoice_date'],'qty'=>$qty,
                'revenue'=>(float)$e['revenue'] * $qty / (float)$e['qty'],
                'cost'=>$lots[$id]['unit_cost'] === null ? null : $qty*$lots[$id]['unit_cost']];
            if (!$return) $history[$e['agent_key']][]=['lot_id'=>$id,'left'=>$qty];
        }
    }
    return ['lots'=>$lots,'alloc'=>$alloc,'warnings'=>$warnings];
}

function fifo_q(PDO $pdo, string $sql, array $args = []): PDOStatement
{
    $s=$pdo->prepare($sql); $s->execute($args); return $s;
}

/** الكتابة على دفعات، مش صف بصف. */
function fifo_insert_batch(PDO $pdo, string $table, array $columns, array $rows): void
{
    if (!$rows) return;
    $values=[];
    foreach ($rows as $row) foreach ($columns as $c) $values[]=$row[$c];
    $tuple='('.implode(',',array_fill(0,count($columns),'?')).')';
    fifo_q($pdo, 'INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES '.implode(',',array_fill(0,count($rows),$tuple)), $values);
}

/** بنفرّغ قراية المصدر على ملف مؤقت عشان مشغل MySQL مايخزنش كل الحركات في الذاكرة. */
function fifo_spool(PDO $pdo, string $sql, array &$codes)
{
    $file=tmpfile();
    if (!$file) throw new RuntimeException('مش قادرين نجهّز ملف الحساب المؤقت');
    $attribute=defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    $buffered=$pdo->getAttribute($attribute); $s=null;
    try {
        $pdo->setAttribute($attribute,false);
        $s=$pdo->query($sql);
        while ($row=$s->fetch(PDO::FETCH_ASSOC)) {
            if ((float)($row['unit_factor'] ?? 0)<=0 && (string)($row['item_code'] ?? '')!=='') $codes[$row['item_code']]=true;
            $line=json_encode($row,JSON_THROW_ON_ERROR)."\n";
            if (fwrite($file,$line)!==strlen($line)) throw new RuntimeException('المساحة مش كفاية لملف الحساب المؤقت');
        }
        rewind($file);
        return $file;
    } catch (Throwable $e) { fclose($file); throw $e; }
    finally {
        if ($s) $s->closeCursor();
        $pdo->setAttribute($attribute,$buffered);
    }
}

function fifo_stream_row($file): array|false
{
    $line=fgets($file);
    if ($line===false && !feof($file)) throw new RuntimeException('قراية ملف الحساب المؤقت ماكملتش');
    return $line===false ? false : json_decode($line,true,512,JSON_THROW_ON_ERROR);
}

function fifo_rebuild(PDO $pdo): array
{
    if (!(int)$pdo->query("SELECT GET_LOCK('fifo_rebuild',0)")->fetchColumn()) return ['busy'=>true,'message'=>'فيه حساب شغّال'];
    $start=microtime(true); $run=0; $published=false; $hq=null; $br=null;
    try {
        $pdo->exec("INSERT INTO fifo_runs (status) VALUES ('running')"); $run=(int)$pdo->lastInsertId();
        $excluded=implode(',',fifo_internal_ids());
        $codes=[];
        $hq=fifo_spool($pdo,"SELECT l.*, h.doc_type,h.supplier_id,h.supplier_name,h.doc_no,h.invoice_date,h.net_amount,h.total_amount,'' agent_key
            FROM hq_invoice_lines l JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
            WHERE h.doc_type IN (2,6) AND h.invoice_date >= '2026-06-27' AND h.invoice_date < CURRENT_DATE + INTERVAL 1 DAY
              AND (h.supplier_id IS NULL OR h.supplier_id NOT IN ($excluded))
            ORDER BY l.item_id,h.invoice_date,CASE WHEN h.doc_type=2 THEN 0 ELSE 1 END,h.sth_id,l.line_no",$codes);
        $br=fifo_spool($pdo,"SELECT l.*,h.doc_type,h.invoice_date,h.net_amount,h.total_amount
            FROM br_invoice_lines l JOIN br_invoices h ON h.agent_key=l.agent_key AND h.sth_id=l.sth_id AND h.suffix=l.suffix
            WHERE h.doc_type IN (4,7,17) AND h.agent_key IN ('zahraa','bonjour','degla','abc')
              AND h.invoice_date >= '2026-06-27' AND h.invoice_date < CURRENT_DATE + INTERVAL 1 DAY
            ORDER BY l.item_id,h.invoice_date,h.sth_id,l.line_no",$codes);
        // بنجيب بس معاملات الأكواد اللي سطورها مالهاش معامل، بنفس دالة كارت الصنف.
        $factors=item_code_factors($pdo,array_keys($codes));
        unset($codes);
        $a=fifo_stream_row($hq); $b=fifo_stream_row($br);
        $lotBuffer=[]; $allocBuffer=[]; $lotCount=0; $allocCount=0;
        $warnings=['purchase_return_without_lot'=>0.0,'sale_without_purchase'=>0.0,'sale_return_without_sale'=>0.0];
        $lc=['run_id','lot_id','item_id','kind','supplier_id','supplier_name','sth_id','suffix','line_no','doc_no','lot_date','qty_in','qty_returned','unit_cost'];
        $ac=['run_id','lot_id','item_id','agent_key','sale_sth_id','sale_suffix','sale_line_no','sale_date','qty','revenue','cost'];
        while ($a || $b) {
            $item=min($a ? (int)$a['item_id'] : PHP_INT_MAX,$b ? (int)$b['item_id'] : PHP_INT_MAX); $events=[];
            while ($a && (int)$a['item_id']===$item) { $events[]=fifo_normalize_event($a,$factors); $a=fifo_stream_row($hq); }
            while ($b && (int)$b['item_id']===$item) { $events[]=fifo_normalize_event($b,$factors); $b=fifo_stream_row($br); }
            $result=fifo_compute_item($events); $offset=$lotCount+1;
            foreach ($result['lots'] as $row) {
                $row['lot_id']+=$offset; $row['run_id']=$run; $lotBuffer[]=$row; ++$lotCount;
                if (count($lotBuffer)>=500) { fifo_insert_batch($pdo,'fifo_lots',$lc,$lotBuffer); $lotBuffer=[]; }
            }
            foreach ($result['alloc'] as $row) {
                $row['lot_id']+=$offset; $row['run_id']=$run; $allocBuffer[]=$row; ++$allocCount;
                if (count($allocBuffer)>=500) { fifo_insert_batch($pdo,'fifo_alloc',$ac,$allocBuffer); $allocBuffer=[]; }
            }
            foreach ($warnings as $k=>$v) $warnings[$k]+=$result['warnings'][$k];
        }
        fifo_insert_batch($pdo,'fifo_lots',$lc,$lotBuffer); fifo_insert_batch($pdo,'fifo_alloc',$ac,$allocBuffer);
        $note=json_encode($warnings,JSON_UNESCAPED_UNICODE);
        $pdo->beginTransaction();
        $pdo->exec('UPDATE fifo_runs SET is_current=0 WHERE is_current=1');
        fifo_q($pdo,"UPDATE fifo_runs SET status='done',is_current=1,finished_at=NOW(),lots_count=?,alloc_count=?,note=? WHERE id=?",[$lotCount,$allocCount,$note,$run]);
        $pdo->commit();
        $published=true;
        fifo_q($pdo,'DELETE FROM fifo_alloc WHERE run_id<>?',[$run]);
        fifo_q($pdo,'DELETE FROM fifo_lots WHERE run_id<>?',[$run]);
        fifo_q($pdo,'DELETE FROM fifo_runs WHERE id<>?',[$run]);
        return ['run_id'=>$run,'seconds'=>round(microtime(true)-$start,3),'lots_count'=>$lotCount,'alloc_count'=>$allocCount,'warnings'=>$warnings];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($run && !$published) fifo_q($pdo,"UPDATE fifo_runs SET status='failed',finished_at=NOW(),note=? WHERE id=?",['الحساب ماكملش؛ راجع سجل الأخطاء',$run]);
        throw $e;
    } finally {
        if (is_resource($hq)) fclose($hq);
        if (is_resource($br)) fclose($br);
        $pdo->query("SELECT RELEASE_LOCK('fifo_rebuild')");
    }
}

/** تجميع التخصيصات الأول يمنع تكرار كمية الشراء مع كل بيعة. */
function fifo_lot_sales_sql(): string
{
    return "SELECT a.run_id,a.lot_id,SUM(a.qty) sold,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.qty ELSE 0 END) branch_sold,
        SUM(CASE WHEN a.agent_key='zahraa' THEN a.qty ELSE 0 END) zahraa,
        SUM(CASE WHEN a.agent_key='bonjour' THEN a.qty ELSE 0 END) bonjour,
        SUM(CASE WHEN a.agent_key='degla' THEN a.qty ELSE 0 END) degla,
        SUM(CASE WHEN a.agent_key='abc' THEN a.qty ELSE 0 END) abc,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.qty ELSE 0 END) ashraqa,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.cost ELSE 0 END) ashraqa_cost,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.revenue ELSE 0 END) revenue,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.cost ELSE 0 END) cost,
        MAX(CASE WHEN a.qty>0 THEN a.sale_date END) last_sale
        FROM fifo_alloc a JOIN fifo_runs r ON r.id=a.run_id AND r.is_current=1 AND r.status='done'
        GROUP BY a.run_id,a.lot_id";
}

function fifo_age_bucket(string $date, string $today): string
{
    $days=(int)(new DateTimeImmutable($date))->diff(new DateTimeImmutable($today))->format('%r%a');
    return $days<=30 ? '0-30' : ($days<=60 ? '31-60' : ($days<=90 ? '61-90' : '90+'));
}

function fifo_sold_days(string $date, ?string $lastSale, float $remaining): ?int
{
    return $remaining>0.0000001 || !$lastSale ? null
        : (int)(new DateTimeImmutable(substr($date,0,10)))->diff(new DateTimeImmutable(substr($lastSale,0,10)))->format('%r%a');
}

function fifo_finish_row(array $row): array
{
    foreach (['qty_in','qty_returned','sold','branch_sold','remaining','revenue','cost','profit_revenue','no_purchase_qty',
        'ashraqa','ashraqa_cost','zahraa','bonjour','degla','abc','in_qty','balance'] as $k) {
        if (array_key_exists($k,$row)) $row[$k]=(float)($row[$k] ?? 0);
    }
    $base=(float)($row['profit_revenue'] ?? $row['revenue'] ?? 0);
    $row['profit']=isset($row['known_rows']) && !(int)$row['known_rows'] ? null : $base-(float)($row['cost'] ?? 0);
    $row['margin']=$base!=0 && $row['profit']!==null ? $row['profit']/$base*100 : null;
    $net=(float)($row['qty_in'] ?? 0)-(float)($row['qty_returned'] ?? 0);
    $row['sell_through']=$net!=0 ? (float)($row['sold'] ?? 0)/$net*100 : null;
    if (isset($row['lot_date'])) $row['sold_days']=fifo_sold_days($row['lot_date'],$row['last_sale'] ?? null,(float)($row['remaining'] ?? 0));
    $row['sale_price']=!empty($row['branch_sold']) ? (float)$row['revenue']/$row['branch_sold'] : null;
    return $row;
}

function fifo_totals(array $rows): array
{
    $total=array_fill_keys(['qty_in','qty_returned','sold','branch_sold','remaining','revenue','cost','profit_revenue','no_purchase_qty','ashraqa','ashraqa_cost','in_qty','balance'],0.0);
    foreach ($rows as $row) foreach ($total as $k=>$v) $total[$k]+=(float)($row[$k] ?? ($k==='profit_revenue' ? ($row['revenue'] ?? 0) : 0));
    $total['all_revenue']=$total['revenue'];
    $total['no_purchase_revenue']=$total['revenue']-$total['profit_revenue'];
    $total['revenue']=$total['profit_revenue'];
    $total['known_rows']=count(array_filter($rows,static fn($row)=>($row['profit'] ?? null)!==null));
    return fifo_finish_row($total);
}

/** عمر الباقي كله لحد النهارده، من غير فلتر فترة. التجميع في القاعدة. */
function fifo_aging(PDO $pdo, ?int $supplierId = null): array
{
    $sales=fifo_lot_sales_sql();
    $rows=fifo_q($pdo,"SELECT l.supplier_id,l.sth_id,l.suffix,
        CASE WHEN DATEDIFF(CURRENT_DATE,l.lot_date)<=30 THEN '0-30'
             WHEN DATEDIFF(CURRENT_DATE,l.lot_date)<=60 THEN '31-60'
             WHEN DATEDIFF(CURRENT_DATE,l.lot_date)<=90 THEN '61-90' ELSE '90+' END bucket,
        SUM(l.qty_in-l.qty_returned-COALESCE(a.sold,0)) qty,
        SUM((l.qty_in-l.qty_returned-COALESCE(a.sold,0))*l.unit_cost) value
        FROM fifo_lots l JOIN fifo_runs r ON r.id=l.run_id AND r.is_current=1 AND r.status='done'
        LEFT JOIN ($sales) a ON a.run_id=l.run_id AND a.lot_id=l.lot_id
        WHERE l.kind='purchase' ".($supplierId!==null ? 'AND l.supplier_id=?' : '')."
        GROUP BY l.supplier_id,l.sth_id,l.suffix,bucket HAVING qty>0",$supplierId!==null ? [$supplierId] : [])->fetchAll(PDO::FETCH_ASSOC);
    $suppliers=[]; $invoices=[];
    foreach ($rows as $row) {
        $sid=$row['supplier_id']; $key=$sid.':'.$row['sth_id'].':'.$row['suffix']; $bucket=$row['bucket'];
        foreach (['qty','value'] as $k) {
            $suppliers[$sid][$bucket][$k]=($suppliers[$sid][$bucket][$k] ?? 0)+(float)$row[$k];
            $invoices[$key][$bucket][$k]=($invoices[$key][$bucket][$k] ?? 0)+(float)$row[$k];
        }
    }
    return ['suppliers'=>$suppliers,'invoices'=>$invoices];
}

function fifo_supplier_invoices(PDO $pdo, int $supplierId, string $from, string $to): array
{
    $sales=fifo_lot_sales_sql();
    $rows=fifo_q($pdo,"SELECT l.*,COALESCE(n.name,CONCAT('صنف ',l.item_id)) name,
        h.company_branch,COALESCE(st.name,'مخزن مش معروف') store_name,
        COALESCE(a.sold,0) sold,COALESCE(a.branch_sold,0) branch_sold,
        COALESCE(a.zahraa,0) zahraa,COALESCE(a.bonjour,0) bonjour,COALESCE(a.degla,0) degla,COALESCE(a.abc,0) abc,
        COALESCE(a.ashraqa,0) ashraqa,COALESCE(a.ashraqa_cost,0) ashraqa_cost,
        COALESCE(a.revenue,0) revenue,COALESCE(a.cost,0) cost,a.last_sale,
        l.qty_in-l.qty_returned-COALESCE(a.sold,0) remaining
        FROM fifo_lots l JOIN fifo_runs r ON r.id=l.run_id AND r.is_current=1 AND r.status='done'
        LEFT JOIN ($sales) a ON a.run_id=l.run_id AND a.lot_id=l.lot_id
        LEFT JOIN (SELECT item_id,MAX(name) name FROM hq_items GROUP BY item_id) n ON n.item_id=l.item_id
        LEFT JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
        LEFT JOIN hq_stores st ON st.sto_id=h.company_branch
        WHERE l.kind='purchase' AND l.supplier_id=? AND l.lot_date>=? AND l.lot_date<DATE_ADD(?,INTERVAL 1 DAY)
        ORDER BY l.lot_date,l.sth_id,l.line_no",[$supplierId,$from,$to])->fetchAll(PDO::FETCH_ASSOC);
    $aging=fifo_aging($pdo,$supplierId); $invoices=[];
    foreach ($rows as $row) {
        $key=$supplierId.':'.$row['sth_id'].':'.$row['suffix'];
        if (!isset($invoices[$key])) $invoices[$key]=['sth_id'=>$row['sth_id'],'suffix'=>$row['suffix'],'doc_no'=>$row['doc_no'],
            'lot_date'=>$row['lot_date'],'store_name'=>$row['store_name'],'lines'=>[],'aging'=>$aging['invoices'][$key] ?? []];
        $invoices[$key]['lines'][]=fifo_finish_row($row);
    }
    foreach ($invoices as &$inv) {
        $t=fifo_totals($inv['lines']);
        $inv=array_merge($inv,$t);
    }
    unset($inv);
    return ['rows'=>array_values($invoices),'totals'=>fifo_totals(array_values($invoices)),'aging'=>$aging['suppliers'][$supplierId] ?? []];
}

function fifo_suppliers_profit(PDO $pdo, string $from, string $to): array
{
    $rows=fifo_q($pdo,"SELECT l.supplier_id,MAX(l.supplier_name) name,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.qty ELSE 0 END) branch_sold,
        SUM(a.qty) sold,SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.revenue ELSE 0 END) revenue,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.cost ELSE 0 END) cost,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.qty ELSE 0 END) ashraqa,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.cost ELSE 0 END) ashraqa_cost,
        SUM(CASE WHEN l.kind='no_purchase' AND a.agent_key<>'ashraqa' THEN a.qty ELSE 0 END) no_purchase_qty,
        SUM(CASE WHEN l.kind='purchase' AND a.agent_key<>'ashraqa' THEN a.revenue ELSE 0 END) profit_revenue,
        SUM(CASE WHEN l.kind='purchase' AND a.agent_key<>'ashraqa' THEN 1 ELSE 0 END) known_rows
        FROM fifo_lots l JOIN fifo_runs r ON r.id=l.run_id AND r.is_current=1 AND r.status='done'
        JOIN fifo_alloc a ON a.run_id=l.run_id AND a.lot_id=l.lot_id
        WHERE a.sale_date>=? AND a.sale_date<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY l.supplier_id",[$from,$to])->fetchAll(PDO::FETCH_ASSOC);
    $purchases=fifo_q($pdo,"SELECT l.supplier_id,MAX(l.supplier_name) name,
        SUM(CASE WHEN l.lot_date>=? AND l.lot_date<DATE_ADD(?,INTERVAL 1 DAY) THEN l.qty_in ELSE 0 END) qty_in,
        SUM(CASE WHEN l.lot_date>=? AND l.lot_date<DATE_ADD(?,INTERVAL 1 DAY) THEN l.qty_returned ELSE 0 END) qty_returned
        FROM fifo_lots l JOIN fifo_runs r ON r.id=l.run_id AND r.is_current=1 AND r.status='done'
        WHERE l.kind='purchase' GROUP BY l.supplier_id",[$from,$to,$from,$to])->fetchAll(PDO::FETCH_ASSOC);
    $map=[];
    foreach ($purchases as $row) $map[$row['supplier_id']]=$row;
    foreach ($rows as $row) { $key=$row['supplier_id'] ?? ''; $map[$key]=array_merge($map[$key] ?? [],$row); }
    $aging=fifo_aging($pdo);
    foreach ($map as $key=>&$row) { $row=fifo_finish_row($row); $row['aging']=$aging['suppliers'][$key] ?? []; }
    unset($row);
    return ['rows'=>array_values($map),'totals'=>fifo_totals(array_values($map))];
}

function fifo_store_profit(PDO $pdo, string $store, string $from, string $to, string $group = 'item'): array
{
    if ($store!=='all' && !isset(REORDER_AGENT_TO_STO[$store])) throw new InvalidArgumentException('المخزن مش صحيح');
    $excluded=implode(',',fifo_internal_ids());
    $sto=$store==='all' ? null : REORDER_AGENT_TO_STO[$store];
    $balanceParts=[];
    foreach (REORDER_AGENT_TO_STO as $agent=>$id) $balanceParts[]="(agent_key='$agent' AND sto_id=$id)";
    $balanceWhere=$sto===null ? implode(' OR ',$balanceParts) : "agent_key='$store' AND sto_id=$sto";
    $balanceSql="SELECT item_id,SUM(balance) balance FROM (SELECT item_id,balance FROM hq_branch_balances WHERE $balanceWhere"
        .($sto===null ? ' UNION ALL SELECT item_id,balance FROM hq_balances WHERE sto_id=1' : '').") bb GROUP BY item_id";
    $inSql="SELECT l.item_id,SUM(ROUND(l.quantity * COALESCE(NULLIF(GREATEST(COALESCE(l.unit_factor,0),0),0),NULLIF(GREATEST(COALESCE(c.unit_factor,0),0),0),1),3)
        * CASE WHEN h.doc_type=6 THEN -1 ELSE 1 END) in_qty
        FROM hq_invoice_lines l JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
        LEFT JOIN hq_codes c ON c.code=l.item_code
        WHERE h.doc_type IN (2,6) AND (h.supplier_id IS NULL OR h.supplier_id NOT IN ($excluded))
        AND NOT (h.doc_type=6 AND h.supplier_id IN (".implode(',',ASHRAQA_ERP_IDS)."))
        AND h.invoice_date>=? AND h.invoice_date<DATE_ADD(?,INTERVAL 1 DAY)"
        .($sto!==null ? " AND h.company_branch=$sto" : '').' GROUP BY l.item_id';
    $sales="SELECT a.item_id,GROUP_CONCAT(DISTINCT l.supplier_name ORDER BY l.supplier_name SEPARATOR '، ') supplier_name,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.qty ELSE 0 END) sold,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.revenue ELSE 0 END) revenue,
        SUM(CASE WHEN a.agent_key<>'ashraqa' THEN a.cost ELSE 0 END) cost,
        SUM(CASE WHEN a.agent_key<>'ashraqa' AND a.cost IS NOT NULL THEN a.revenue ELSE 0 END) profit_revenue,
        SUM(CASE WHEN a.agent_key<>'ashraqa' AND a.cost IS NOT NULL THEN 1 ELSE 0 END) known_rows,
        SUM(CASE WHEN a.agent_key<>'ashraqa' AND a.cost IS NULL THEN a.qty ELSE 0 END) no_purchase_qty,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.qty ELSE 0 END) ashraqa,
        SUM(CASE WHEN a.agent_key='ashraqa' THEN a.cost ELSE 0 END) ashraqa_cost
        FROM fifo_alloc a JOIN fifo_runs r ON r.id=a.run_id AND r.is_current=1 AND r.status='done'
        JOIN fifo_lots l ON l.run_id=a.run_id AND l.lot_id=a.lot_id
        WHERE a.sale_date>=? AND a.sale_date<DATE_ADD(?,INTERVAL 1 DAY)"
        .($store!=='all' ? " AND a.agent_key='$store'" : '').' GROUP BY a.item_id';
    $byCat=$group==='category';
    $select=$byCat ? "COALESCE(cn.name,'من غير تصنيف') name,COALESCE(ic.is1_id,0) category_id,
        GROUP_CONCAT(DISTINCT s.supplier_name SEPARATOR '، ') supplier_name" : "s.item_id,COALESCE(n.name,CONCAT('صنف ',s.item_id)) name,MAX(s.supplier_name) supplier_name";
    $metrics=[];
    foreach (['sold','revenue','cost','profit_revenue','known_rows','no_purchase_qty','ashraqa','ashraqa_cost'] as $k) $metrics[]="SUM(s.$k) $k";
    $rows=fifo_q($pdo,"SELECT $select,".implode(',',$metrics).",SUM(COALESCE(i.in_qty,0)) in_qty,SUM(COALESCE(b.balance,0)) balance
        FROM ($sales) s LEFT JOIN ($inSql) i ON i.item_id=s.item_id
        LEFT JOIN ($balanceSql) b ON b.item_id=s.item_id
        LEFT JOIN (SELECT item_id,MAX(name) name FROM hq_items GROUP BY item_id) n ON n.item_id=s.item_id
        LEFT JOIN hq_item_cats ic ON ic.item_id=s.item_id LEFT JOIN hq_cat_names cn ON cn.is1_id=ic.is1_id
        GROUP BY ".($byCat ? "COALESCE(ic.is1_id,0),COALESCE(cn.name,'من غير تصنيف')" : 's.item_id,n.name')." ORDER BY name",[$from,$to,$from,$to])->fetchAll(PDO::FETCH_ASSOC);
    $rows=array_map('fifo_finish_row',$rows);
    return ['rows'=>$rows,'totals'=>fifo_totals($rows)];
}

function fifo_status(PDO $pdo): array
{
    return ['latest'=>$pdo->query('SELECT * FROM fifo_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null,
        'current'=>$pdo->query("SELECT * FROM fifo_runs WHERE is_current=1 AND status='done' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null];
}

function fifo_suppliers(PDO $pdo): array
{
    $excluded=implode(',',fifo_internal_ids());
    return $pdo->query("SELECT supplier_id id,MAX(CASE WHEN supplier_id IN (".implode(',',ASHRAQA_ERP_IDS).") THEN 'إشراقة' ELSE supplier_name END) name
        FROM hq_invoices WHERE doc_type=2 AND invoice_date>='2026-06-27' AND supplier_id NOT IN ($excluded)
        GROUP BY supplier_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
