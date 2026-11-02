<?php
declare(strict_types=1);
/** كارت المورد: الكود الظاهر دايماً erp_supplier_code، والمرآة للقراية بس. */
// قرار محمد ٢/١١: لحد ما نستغنى عن ترو، المورد الجديد يتعرّف على ترو بس (علشان الأكواد ماتتخبطش مع المزامنة). الكارت بيعدّل البيانات بس.
const SC_ALLOW_CREATE = false;
require_once __DIR__ . '/suppliers_lib.php';
require_once __DIR__ . '/sensitive_lib.php';

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
    $branch="CASE COALESCE(h.company_branch,1) WHEN 1 THEN 'الإدارة'";
    foreach (SUPP_BRANCHES as $b) $branch.=' WHEN '.(int)$b['cb'].' THEN '.db()->quote($b['label']);
    $branch.=" ELSE CONCAT('فرع ',h.company_branch) END";
    $st=db()->prepare("SELECT CONCAT(h.sth_id,':',h.suffix) id,'hq' src,h.doc_type,
        h.doc_no,DATE(h.invoice_date) doc_date,$branch branch,
        COUNT(DISTINCT l.item_id) item_count,h.total_amount,h.net_amount
        FROM hq_invoices h LEFT JOIN hq_invoice_lines l ON l.sth_id=h.sth_id AND l.suffix=h.suffix
        WHERE h.supplier_id=? AND h.doc_type IN (2,6) AND h.invoice_date>=? AND h.invoice_date<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY h.sth_id,h.suffix,h.doc_type,h.doc_no,h.invoice_date,h.company_branch,h.total_amount,h.net_amount");
    $st->execute([$s['erp_supplier_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if ((string)setting('supplier_site_docs','1')!=='0') {
        $st=db()->prepare("SELECT CAST(d.id AS CHAR) id,'site' src,CASE WHEN d.doc_type='purchase' THEN 2 ELSE 6 END doc_type,
            d.doc_no,d.doc_date,b.name branch,COUNT(DISTINCT l.item_id) item_count,
            COALESCE(d.gross_amount,d.total_amount) total_amount,d.total_amount net_amount
            FROM stock_docs d LEFT JOIN stock_doc_lines l ON l.doc_id=d.id AND l.qty_pieces>0
            LEFT JOIN branches b ON b.id=d.branch_id
            WHERE d.supplier_id=? AND d.status='posted' AND d.doc_type IN ('purchase','purchase_return') AND d.doc_date>=? AND d.doc_date<=?
            GROUP BY d.id,d.doc_type,d.doc_no,d.doc_date,b.name,d.gross_amount,d.total_amount");
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
    $sql="SELECT CONCAT('hq:',l.item_id) item_key,l.item_id,c.code,l.item_name name,
        l.quantity * COALESCE(NULLIF(l.unit_factor,0),NULLIF(hc.unit_factor,0),1) quantity,
        l.cost_price/COALESCE(NULLIF(l.unit_factor,0),NULLIF(hc.unit_factor,0),1) cost,
        h.invoice_date purchase_date,CONCAT('hq:',h.suffix,':',h.sth_id,':',l.line_no) tie
        FROM hq_invoice_lines l JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
        LEFT JOIN hq_codes hc ON hc.code=l.item_code
        LEFT JOIN (SELECT item_id,MIN(code) code FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
        WHERE h.supplier_id=? AND h.doc_type=2";
    $params=[$s['erp_supplier_id']];
    if ($site) {
        $sql.=" UNION ALL SELECT CONCAT('hq:',l.item_id),
            l.item_id,COALESCE(c.code,l.code),COALESCE(c.name,l.code),
            l.qty_pieces,l.unit_cost,d.doc_date,CONCAT('site:',d.id,':',l.line_no)
            FROM stock_doc_lines l JOIN stock_docs d ON d.id=l.doc_id
            LEFT JOIN (SELECT item_id,MIN(code) code,MAX(name) name FROM hq_codes WHERE code_type=0 GROUP BY item_id) c ON c.item_id=l.item_id
            WHERE d.supplier_id=? AND d.doc_type='purchase' AND d.status='posted' AND l.qty_pieces>0";
        $params[]=$s['id'];
    }
    $st=db()->prepare("SELECT MAX(code) code,MAX(CASE WHEN rn=1 THEN name END) name,SUM(quantity) quantity,
        MAX(CASE WHEN rn=1 THEN cost END) cost,MAX(purchase_date) purchase_date
        FROM (SELECT u.*,ROW_NUMBER() OVER(PARTITION BY item_key ORDER BY purchase_date DESC,tie DESC) rn FROM ($sql) u) ranked
        GROUP BY item_key ORDER BY name");
    $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sc_statement(array $s,array $in): array {
    $start=suppliers_get_start_date(db()); [$from,$to]=sc_dates($in,$start);
    $data=suppliers_get_statement(db(),(int)$s['id'],$from,$to,$start,null);
    // الحسابات هي نفسها بالحرف؛ البيانات الشخصية والمعرفات مش جزء من جدول الحركات.
    unset($data['supplier']);
    return $data+['from'=>$from,'to'=>$to];
}

function sc_next_code(): string {
    return (string)((int)db()->query('SELECT MAX(CAST(erp_supplier_code AS UNSIGNED)) FROM suppliers')->fetchColumn()+1);
}

function sc_card(string $code,bool $prices): ?array {
    $s=sc_find($code); if (!$s) return null;
    $docs=sc_documents($s,'1000-01-01','9999-12-30'); $items=sc_items($s);
    $purchases=array_values(array_filter($docs,static fn($r)=>(int)$r['doc_type']===2));
    $data=['supplier'=>sc_public($s,$prices),'counts'=>['purchases'=>count($purchases),'returns'=>count($docs)-count($purchases),'items'=>count($items)],
        'purchase_url'=>'purchase_docs.php?type=purchase','statement_url'=>'suppliers.php?supplier='.(int)$s['id']];
    if ($prices) {
        $data['statement']=sc_statement($s,[]);
        $data['latest']=$purchases[0] ?? null;
        $since=date('Y-m-d',strtotime('-89 days'));
        $recent=array_filter($purchases,static fn($r)=>$r['doc_date']>=$since && $r['doc_date']<=date('Y-m-d'));
        $data['recent']=['count'=>count($recent),'net'=>array_sum(array_column($recent,'net_amount'))];
    }
    return $data;
}

/** الحقول مسموحة بالاسم؛ الرصيد وربط ترو والكود القديم مستحيل يتكتبوا من المدخلات. */
function sc_save(array $in,bool $prices,bool $retry=true): string {
    $name=trim((string)($in['name'] ?? ''));
    if ($name==='' || mb_strlen($name)>120) throw new InvalidArgumentException('اكتب اسم المورد، بحد أقصى ١٢٠ حرف');
    $fields=['name'=>$name];
    foreach (['phone'=>30,'phone2'=>30,'tax_no'=>40,'address'=>255,'notes'=>16000] as $key=>$max) {
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
