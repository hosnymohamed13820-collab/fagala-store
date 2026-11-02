<?php
declare(strict_types=1);
/** كارت المورد: الكود الظاهر دايماً erp_supplier_code، والمرآة للقراية بس. */
// قرار محمد ٢/١١: لحد ما نستغنى عن ترو، المورد الجديد يتعرّف على ترو بس (علشان الأكواد ماتتخبطش مع المزامنة). الكارت بيعدّل البيانات بس.
const SC_ALLOW_CREATE = false;
require_once __DIR__ . '/suppliers_lib.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/item_classes_lib.php';

function sc_find(string $code): ?array {
    if (trim($code)==='') return null;
    $st=db()->prepare('SELECT * FROM suppliers WHERE erp_supplier_code=?');
    $st->execute([trim($code)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sc_public(array $s, bool $prices): array {
    unset($s['balance'],$s['erp_supplier_id'],$s['id']);
    if (!$prices) unset($s['credit_limit']);
    return $s;
}

/** تطبيع عربي للبحث بالكلمات بأي ترتيب، من غير كشف المعرفات الداخلية. */
function sc_normalize(string $s): string {
    return mb_strtolower(strtr(preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u','',$s) ?? '',
        ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ى'=>'ي','ئ'=>'ي','ؤ'=>'و','ة'=>'ه']));
}

function sc_search(string $q): array {
    $words=preg_split('/\s+/u',trim(sc_normalize($q)),-1,PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return [];
    // ٢٨٤ مورد بس: التطبيع في الذاكرة بيشمل التشكيل وكل صور الحروف بنفس المعنى.
    $rows=db()->query('SELECT erp_supplier_code,name,phone,payment_type,is_active FROM suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    return array_slice(array_values(array_filter($rows,static function($r) use($words) {
        $text=sc_normalize($r['name'].' '.$r['erp_supplier_code']);
        foreach ($words as $word) if (!str_contains($text,$word)) return false;
        return true;
    })),0,50);
}

function sc_dates(array $in, string $defaultFrom='1000-01-01'): array {
    $from=(string)($in['from'] ?? $defaultFrom); $to=(string)($in['to'] ?? date('Y-m-d'));
    foreach ([$from,$to] as $value) {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new InvalidArgumentException('اكتب تاريخ صحيح');
    }
    if ($from>$to) throw new InvalidArgumentException('بداية الفترة لازم تكون قبل نهايتها');
    return [$from,$to];
}

/** استعلام مجمّع لكل مصدر، والربط المركب بيمنع خلط الشهور. */
function sc_documents(array $s, string $from, string $to): array {
    $supplierWhere=!empty($s['erp_supplier_id']) ? '(h.supplier_id=? OR (h.supplier_id IS NULL AND h.supplier_name=?))' : 'h.supplier_name=?';
    $supplierParams=!empty($s['erp_supplier_id']) ? [$s['erp_supplier_id'],$s['name']] : [$s['name']];
    $branch="CASE COALESCE(h.company_branch,1) WHEN 1 THEN 'الإدارة'";
    foreach (SUPP_BRANCHES as $b) $branch.=' WHEN '.(int)$b['cb'].' THEN '.db()->quote($b['label']);
    $branch.=" ELSE CONCAT('فرع ',h.company_branch) END";
    $st=db()->prepare("SELECT CONCAT(h.sth_id,':',h.suffix) id,'hq' src,h.doc_type,
        h.doc_no,NULL supplier_invoice,NULL return_reason,DATE(h.invoice_date) doc_date,$branch branch,
        COUNT(DISTINCT l.item_id) item_count,h.total_amount,h.net_amount
        FROM hq_invoices h LEFT JOIN hq_invoice_lines l ON l.sth_id=h.sth_id AND l.suffix=h.suffix
        WHERE $supplierWhere AND h.doc_type IN (2,6) AND h.invoice_date>=? AND h.invoice_date<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY h.sth_id,h.suffix,h.doc_type,h.doc_no,h.invoice_date,h.company_branch,h.total_amount,h.net_amount");
    $st->execute([...$supplierParams,$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if ((string)setting('supplier_site_docs','1')!=='0') {
        $st=db()->prepare("SELECT CAST(d.id AS CHAR) id,'site' src,CASE WHEN d.doc_type='purchase' THEN 2 ELSE 6 END doc_type,
            d.doc_no,d.ref_text supplier_invoice,d.return_reason,d.doc_date,b.name branch,COUNT(DISTINCT l.item_id) item_count,
            COALESCE(d.gross_amount,d.total_amount) total_amount,d.total_amount net_amount
            FROM stock_docs d LEFT JOIN stock_doc_lines l ON l.doc_id=d.id AND l.qty_pieces>0
            LEFT JOIN branches b ON b.id=d.branch_id
            WHERE d.supplier_id=? AND d.status='posted' AND d.doc_type IN ('purchase','purchase_return') AND d.doc_date>=? AND d.doc_date<=?
            GROUP BY d.id,d.doc_type,d.doc_no,d.ref_text,d.return_reason,d.doc_date,b.name,d.gross_amount,d.total_amount");
        $st->execute([$s['id'],$from,$to]); $rows=array_merge($rows,$st->fetchAll(PDO::FETCH_ASSOC));
    }
    usort($rows,static fn($a,$b)=>strcmp($b['doc_date'],$a['doc_date']) ?: strcmp($b['id'],$a['id']));
    return $rows;
}

function sc_lines(string $src,string $id): array {
    if ($src==='hq' && preg_match('/^(\d+):([a-zA-Z0-9_]+)$/D',$id,$m)) {
        $st=db()->prepare("SELECT c.code,l.item_name name,l.quantity,l.cost_price cost,l.quantity * l.cost_price total
            FROM hq_invoice_lines l JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
            LEFT JOIN (SELECT item_id,MIN(code) code FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
            WHERE h.sth_id=? AND h.suffix=? AND h.doc_type IN (2,6) ORDER BY l.line_no");
        $st->execute([$m[1],$m[2]]);
    } elseif ($src==='site' && ctype_digit($id) && (string)setting('supplier_site_docs','1')!=='0') {
        $st=db()->prepare("SELECT COALESCE(c.code,l.code) code,COALESCE(c.name,l.code) name,
            l.qty_units quantity,l.line_total/NULLIF(l.qty_units,0) cost,l.line_total total
            FROM stock_doc_lines l JOIN stock_docs d ON d.id=l.doc_id
            LEFT JOIN (SELECT item_id,MIN(code) code,MAX(name) name FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
            WHERE d.id=? AND d.status='posted' AND d.doc_type IN ('purchase','purchase_return') AND l.qty_pieces>0 ORDER BY l.line_no");
        $st->execute([$id]);
    } else throw new InvalidArgumentException('اختار فاتورة صحيحة');
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** الكمية والتكلفة بالقطعة الأساسية، وآخر تكلفة من أحدث سطر شراء فعلي. */
function sc_items(array $s): array {
    $site=(string)setting('supplier_site_docs','1')!=='0';
    $supplierWhere=!empty($s['erp_supplier_id']) ? '(h.supplier_id=? OR (h.supplier_id IS NULL AND h.supplier_name=?))' : 'h.supplier_name=?';
    $sql="SELECT CONCAT('hq:',l.item_id) item_key,l.item_id,c.code,l.item_name name,
        l.quantity * COALESCE(NULLIF(l.unit_factor,0),NULLIF(hc.unit_factor,0),1) quantity,
        l.cost_price/COALESCE(NULLIF(hc.unit_factor,0),NULLIF(l.unit_factor,0),1) cost,
        h.invoice_date purchase_date,CONCAT('hq:',h.suffix,':',h.sth_id,':',l.line_no) tie
        FROM hq_invoice_lines l JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
        LEFT JOIN hq_codes hc ON hc.code=l.item_code
        LEFT JOIN (SELECT item_id,MIN(code) code FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
        WHERE $supplierWhere AND h.doc_type=2";
    $params=!empty($s['erp_supplier_id']) ? [$s['erp_supplier_id'],$s['name']] : [$s['name']];
    if ($site) {
        $sql.=" UNION ALL SELECT CONCAT('hq:',l.item_id),
            l.item_id,COALESCE(c.code,l.code),COALESCE(c.name,l.code),
            l.qty_pieces,l.line_total/NULLIF(l.qty_pieces,0),d.doc_date,CONCAT('site:',d.id,':',l.line_no)
            FROM stock_doc_lines l JOIN stock_docs d ON d.id=l.doc_id
            LEFT JOIN (SELECT item_id,MIN(code) code,MAX(name) name FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
            WHERE d.supplier_id=? AND d.doc_type='purchase' AND d.status='posted' AND l.qty_pieces>0";
        $params[]=$s['id'];
    }
    $st=db()->prepare("SELECT MAX(ranked.item_id) item_id,MAX(code) code,MAX(CASE WHEN rn=1 THEN name END) name,SUM(quantity) quantity,
        MAX(CASE WHEN rn=1 THEN cost END) cost,MAX(purchase_date) purchase_date,
        MIN(CASE WHEN purchase_date>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) AND purchase_date<DATE_ADD(CURDATE(),INTERVAL 1 DAY) THEN cost END) min_cost,
        MAX(CASE WHEN purchase_date>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) AND purchase_date<DATE_ADD(CURDATE(),INTERVAL 1 DAY) THEN cost END) max_cost,
        COALESCE(MAX(b.item_balance),0) item_balance,MAX(b.balance_updated_at) balance_updated_at
        FROM (SELECT u.*,ROW_NUMBER() OVER(PARTITION BY item_key ORDER BY purchase_date DESC,tie DESC) rn FROM ($sql) u) ranked
        LEFT JOIN (SELECT item_id,SUM(balance) item_balance,MAX(updated_at) balance_updated_at
            FROM hq_branch_balances GROUP BY item_id) b ON b.item_id=ranked.item_id
        GROUP BY item_key ORDER BY name");
    $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sc_statement(array $s,array $in): array {
    $start=suppliers_get_start_date(db()); [$from,$to]=sc_dates($in,$start);
    // المطابقة جوه الكشف بتكتب hq_match إلا في وضع القراءة؛ الكارت هنا عرض بس.
    $hadReadFlag=array_key_exists('VIEW_ALL_PAGE',$GLOBALS); $readFlag=$GLOBALS['VIEW_ALL_PAGE'] ?? null;
    $GLOBALS['VIEW_ALL_PAGE']=true;
    try {
        $data=suppliers_get_statement(db(),(int)$s['id'],$from,$to,$start,null);
    } finally {
        if ($hadReadFlag) $GLOBALS['VIEW_ALL_PAGE']=$readFlag;
        else unset($GLOBALS['VIEW_ALL_PAGE']);
    }
    // الحسابات هي نفسها بالحرف؛ البيانات الشخصية والمعرفات مش جزء من جدول الحركات.
    unset($data['supplier']);
    return $data+['from'=>$from,'to'=>$to];
}

/** توزيع للعرض بس: فلوس الكشف نفسها، والمرتجع دفعة واحدة من غير خصمه من الفاتورة. */
function sc_settlement(array $s): array {
    $statement=sc_statement($s,[]);
    // الكشف مابيرجعش suffix في الحركة؛ بنجيبه بنفس اختيار فواتير الكشف، من غير إعادة حساب فلوسه.
    $where=!empty($s['erp_supplier_id']) ? '(supplier_id=? OR (supplier_id IS NULL AND supplier_name=?))' : 'supplier_name=?';
    $params=[$statement['from'],$statement['to']];
    if (!empty($s['erp_supplier_id'])) $params[]=$s['erp_supplier_id'];
    $params[]=$s['name'];
    $st=db()->prepare("SELECT sth_id,suffix,DATE(invoice_date) doc_date,doc_no,net_amount FROM hq_invoices
        WHERE invoice_date>=? AND invoice_date<DATE_ADD(?,INTERVAL 1 DAY) AND doc_type=2 AND $where
        ORDER BY invoice_date ASC,sth_id ASC");
    $st->execute($params); $keys=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $key=$h['sth_id'].'|'.$h['doc_date'].'|فاتورة شراء '.$h['doc_no'].'|'.(float)$h['net_amount'];
        $keys[$key][]=$h['sth_id'].':'.$h['suffix'];
    }
    $invoices=[]; $aliases=[];
    foreach ($statement['moves'] as $m) {
        if ($m['source']==='hq') {
            $lookup=$m['id'].'|'.$m['tx_date'].'|'.$m['description'].'|'.(float)$m['credit'];
            if (empty($keys[$lookup])) continue;
            $key='hq:'.array_shift($keys[$lookup]);
            foreach ($m['site_matches'] ?? [] as $match) $aliases['site:'.$match['id']]=$key;
        } elseif ($m['source']==='site' && ($m['site_state'] ?? '')==='missing' && str_starts_with($m['description'],'فاتورة شراء ')) {
            $key='site:'.$m['id'];
        } else continue;
        $invoices[$key]=['doc_date'=>$m['tx_date'],'amount'=>(float)$m['credit']];
    }
    // أي مبلغ علينا غير الفواتير من نفس الكشف بيتسدد الأول، بما فيه الافتتاحي المسجل كحركة.
    // suppliers.balance مش مصدر افتتاحي هنا: الكشف نفسه مابيحسبهوش.
    $opening=(float)$statement['prior']['balance']+(float)$statement['period']['received'];
    $pool=(float)$statement['period']['paid']+(float)$statement['period']['returns']+max(0,-$opening);
    if ($opening>0) $invoices=['opening'=>['doc_date'=>$statement['from'],'amount'=>$opening]]+$invoices;
    $today=new DateTimeImmutable('today'); $total=0.0; $recent=0.0; $overdue=0; $oldest=null;
    $since=date('Y-m-d',strtotime('-89 days'));
    foreach ($invoices as $key=>&$invoice) {
        $amount=$invoice['amount']; $paid=min(max(0,$pool),max(0,$amount)); $pool-=$paid;
        $remaining=max(0,$amount-$paid);
        $due=($s['credit_days'] ?? '')!=='' && $s['credit_days']!==null
            ? (new DateTimeImmutable($invoice['doc_date']))->modify('+'.(int)$s['credit_days'].' days') : null;
        $days=$due ? (int)$today->diff($due)->format('%r%a') : null;
        $invoice+=['paid'=>$paid,'remaining'=>$remaining,'status'=>$remaining<0.00001?'paid':($paid>0?'partial':'open'),
            'due_date'=>$due?->format('Y-m-d'),'days_left'=>$days];
        $total+=$remaining;
        if ($key!=='opening' && $invoice['doc_date']>=$since) $recent+=$remaining;
        if ($remaining>=0.00001) {
            if ($oldest===null) $oldest=$invoice;
            if ($days!==null && $days<0) $overdue++;
        }
    }
    unset($invoice);
    $difference=$total-max(0,(float)$statement['period']['final_balance']);
    if (abs($difference)>0.00001) error_log('supplier_card settlement difference supplier '.$s['id'].': '.$difference);
    return ['invoices'=>$invoices,'aliases'=>$aliases,'total_remaining'=>$total,'recent_remaining'=>$recent,
        'overdue_count'=>$overdue,'payment_days_left'=>$oldest['days_left'] ?? null,
        'reconciliation_difference'=>$difference,'statement'=>$statement];
}

/** القايمة الآمنة بتسمح بالحالة والميعاد، وبتمنع خروج أي مبلغ من الـAPI. */
function sc_purchase_rows(array $s,string $from,string $to,bool $prices): array {
    $settlement=sc_settlement($s);
    // المستند المتطابق مع ترو نفس الفاتورة؛ مانكررش المتبقي في الجدول.
    $rows=array_values(array_filter(sc_documents($s,$from,$to),static fn($r)=>(int)$r['doc_type']===2 && !isset($settlement['aliases'][$r['src'].':'.$r['id']])));
    foreach ($rows as &$r) {
        $key=$r['src'].':'.$r['id'];
        $key=$settlement['aliases'][$key] ?? $key;
        $invoice=$settlement['invoices'][$key] ?? [];
        $r+=array_intersect_key($invoice,array_flip(['paid','remaining','status','due_date','days_left']));
        if (!$prices) $r=array_intersect_key($r,array_flip(['id','src','doc_no','supplier_invoice','doc_date','branch','item_count','status','due_date','days_left']));
    }
    unset($r);
    return $rows;
}

function sc_item_rows(array $s,bool $prices): array {
    $rows=sc_items($s);
    if (!$prices) $rows=array_map(static fn($r)=>array_intersect_key($r,array_flip(['code','name','quantity','purchase_date','item_balance','balance_updated_at'])),$rows);
    return $rows;
}

function sc_next_code(): string {
    return (string)((int)db()->query('SELECT MAX(CAST(erp_supplier_code AS UNSIGNED)) FROM suppliers')->fetchColumn()+1);
}

/** كل صنف بيتحسب مرة واحدة، والقسم الفعلي من نفس مصدر كارت الصنف. */
function sc_sections(array $items): array {
    $ids=array_values(array_unique(array_column($items,'item_id')));
    if (!$ids) return [];
    $effective=ic_effective_sql('section');
    $selected=implode(' UNION ALL ',array_fill(0,count($ids),'SELECT ? item_id'));
    $st=db()->prepare("SELECT COALESCE(c.name,'من غير قسم') name,COUNT(*) item_count
        FROM ($selected) i
        LEFT JOIN ($effective) e ON e.item_id=i.item_id
        LEFT JOIN item_classes c ON c.id=e.class_id AND c.kind='section'
        GROUP BY COALESCE(c.name,'من غير قسم') ORDER BY item_count DESC,name");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sc_card(string $code,bool $prices): ?array {
    $s=sc_find($code); if (!$s) return null;
    $docs=sc_documents($s,'1000-01-01','9999-12-30'); $items=sc_items($s);
    $settlement=sc_settlement($s);
    $purchases=array_values(array_filter($docs,static fn($r)=>(int)$r['doc_type']===2 && !isset($settlement['aliases'][$r['src'].':'.$r['id']])));
    $data=['supplier'=>sc_public($s,$prices),'counts'=>['purchases'=>count($purchases),'returns'=>count(array_filter($docs,static fn($r)=>(int)$r['doc_type']===6)),'items'=>count($items)],
        'sections'=>sc_sections($items),'payment_days_left'=>$settlement['payment_days_left'],'overdue_count'=>$settlement['overdue_count'],
        'latest'=>isset($purchases[0]) ? array_intersect_key($purchases[0],array_flip(['doc_no','supplier_invoice','doc_date','branch','item_count'])) : null,
        'recent'=>['count'=>count(array_filter($purchases,static fn($r)=>$r['doc_date']>=date('Y-m-d',strtotime('-89 days')) && $r['doc_date']<=date('Y-m-d')))],
        'purchase_url'=>'purchase_docs.php?type=purchase','statement_url'=>'supplier_card.php?code='.rawurlencode($code).'&print=1'];
    if ($prices) {
        $data['statement']=$settlement['statement'];
        $data['settlement']=['total_remaining'=>$settlement['total_remaining'],'reconciliation_difference'=>$settlement['reconciliation_difference']];
        $data['latest']=$purchases[0] ?? null;
        $data['last_purchases']=array_map(static fn($r)=>array_intersect_key($r,array_flip(['doc_no','doc_date','net_amount'])),array_slice($purchases,0,2));
        $since=date('Y-m-d',strtotime('-89 days'));
        $recent=array_filter($purchases,static fn($r)=>$r['doc_date']>=$since && $r['doc_date']<=date('Y-m-d'));
        $period=sc_statement($s,['from'=>max($since,suppliers_get_start_date(db()))]);
        $data['recent']=['count'=>count($recent),'net'=>array_sum(array_column($recent,'net_amount')),
            'returns'=>$period['period']['returns'],'paid'=>$period['period']['paid'],
            // مفيش ربط تسديد بالفاتورة؛ ماننسبش صافي كشف الحساب لفواتير آخر ٩٠ يوم.
            // دلوقتي التوزيع محسوب للعرض من نفس حركات الكشف، من غير ربط مخزّن.
            'unpaid'=>$settlement['recent_remaining']];
    }
    return $data;
}

/** الحقول مسموحة بالاسم؛ الرصيد وربط ترو والكود القديم مستحيل يتكتبوا من المدخلات. */
function sc_fields(array $in,bool $prices): array {
    $fields=[];
    if (array_key_exists('name',$in)) {
        $name=trim((string)$in['name']);
        if ($name==='' || mb_strlen($name)>120) throw new InvalidArgumentException('اكتب اسم المورد، بحد أقصى ١٢٠ حرف');
        $fields['name']=$name;
    }
    foreach (['phone'=>30,'phone2'=>30,'tax_no'=>40,'address'=>255,'notes'=>16000,'receiving_location'=>150,'trade_specialty'=>100] as $key=>$max) {
        if (!array_key_exists($key,$in)) continue;
        $value=trim((string)$in[$key]);
        if (mb_strlen($value)>$max) throw new InvalidArgumentException('بيانات المورد أطول من المسموح');
        $fields[$key]=$value===''?null:$value;
    }
    if (array_key_exists('payment_type',$in)) {
        if (!in_array($in['payment_type'],['','cash','credit',null],true)) throw new InvalidArgumentException('اختار طريقة تعامل صحيحة');
        $fields['payment_type']=$in['payment_type'] ?: null;
    }
    foreach (['credit_days','credit_limit'] as $key) {
        if (!array_key_exists($key,$in) || ($key==='credit_limit' && !$prices)) continue;
        $v=$in[$key];
        if ($v!=='' && $v!==null && (!is_numeric($v) || (float)$v<0 ||
            ($key==='credit_days' && (!ctype_digit((string)$v) || (float)$v>2147483647)) ||
            ($key==='credit_limit' && !preg_match('/^\d{1,12}(\.\d{1,2})?$/D',(string)$v)))) throw new InvalidArgumentException('اكتب فترة سماح وحد ائتمان صحيحين من غير أرقام سالبة');
        $fields[$key]=$v===''?null:$v;
    }
    if (array_key_exists('is_active',$in)) {
        if (!in_array((string)$in['is_active'],['0','1'],true)) throw new InvalidArgumentException('اختار حالة صحيحة');
        $fields['is_active']=(int)$in['is_active'];
    }
    return $fields;
}

/** خانة واحدة بالكود الظاهر بس؛ اسم العمود لازم يعدّي القائمة قبل تكوين SQL. */
function sc_save_field(string $code,string $field,mixed $value,bool $prices): mixed {
    if (!in_array($field,['name','phone','phone2','tax_no','address','notes','payment_type','credit_days','credit_limit','is_active','receiving_location','trade_specialty'],true)) {
        throw new InvalidArgumentException('الخانة دي مش مسموح تعديلها');
    }
    if (!is_scalar($value) && $value!==null) throw new InvalidArgumentException('اكتب قيمة صحيحة');
    if ($field==='credit_limit' && !$prices) throw new InvalidArgumentException('حد الائتمان محتاج صلاحية الأرقام');
    $fields=sc_fields([$field=>$value],$prices);
    $s=sc_find($code);
    if (!$s) throw new InvalidArgumentException('المورد مش متسجّل');
    $saved=$fields[$field];
    if ((string)($s[$field] ?? '')===(string)($saved ?? '')) return $saved;
    try {
        db()->prepare("UPDATE suppliers SET $field=? WHERE erp_supplier_code=?")->execute([$saved,trim($code)]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0)===1062) throw new DomainException('اسم المورد متسجّل قبل كده، اختار اسم تاني');
        throw $e;
    }
    return $saved;
}

function sc_save(array $in,bool $prices,bool $retry=true): string {
    $fields=sc_fields($in+['name'=>''],$prices); $name=$fields['name'];
    $code=trim((string)($in['code'] ?? '')); $pdo=db(); $pdo->beginTransaction();
    try {
        if ($code!=='') {
            $st=$pdo->prepare('SELECT id FROM suppliers WHERE erp_supplier_code=? FOR UPDATE');$st->execute([$code]);
            if (!$st->fetchColumn()) throw new InvalidArgumentException('المورد مش متسجّل');
            $set=implode(',',array_map(static fn($k)=>$k.'=?',array_keys($fields)));
            $pdo->prepare("UPDATE suppliers SET $set WHERE erp_supplier_code=?")->execute([...array_values($fields),$code]);
        } else {
            if (!SC_ALLOW_CREATE) throw new InvalidArgumentException("المورد الجديد يتعرّف على ترو لحد ما نستغنى عنه، والمزامنة هتجيبه هنا");
            $code=(string)((int)$pdo->query('SELECT MAX(CAST(erp_supplier_code AS UNSIGNED)) FROM suppliers FOR UPDATE')->fetchColumn()+1);
            $fields['erp_supplier_code']=$code;
            $pdo->prepare('INSERT INTO suppliers ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($fields));
        }
        $pdo->commit(); return $code;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0),[1062,1213,1205],true)) {
            $st=$pdo->prepare('SELECT erp_supplier_code FROM suppliers WHERE name=?');$st->execute([$name]);
            if ($st->fetch(PDO::FETCH_ASSOC)) throw new DomainException('اسم المورد متسجّل قبل كده، اختار اسم تاني');
            if ($retry) return sc_save($in,$prices,false);
            throw new DomainException('الكود اتسجّل في نفس اللحظة، جرّب الحفظ تاني');
        }
        throw $e;
    }
}
