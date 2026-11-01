<?php
/** تقسيم الأصناف عندنا؛ المرآة قراية بس، وكل العمليات مجمّعة. */
declare(strict_types=1);
require_once __DIR__ . '/item_classes_lib.php';
require_once __DIR__ . '/reorder_lib.php';

function ica_q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** حساب القطع قبل الجمع، بنفس أولوية معامل السطر ثم الكود. */
function ica_sales_sql(): string
{
    return "SELECT l.item_id,SUM(ROUND(l.quantity * COALESCE(NULLIF(GREATEST(COALESCE(l.unit_factor,0),0),0),
        NULLIF(GREATEST(COALESCE(c.unit_factor,0),0),0),1),3) * CASE WHEN h.doc_type=4 THEN 1 ELSE -1 END) sold
        FROM br_invoice_lines l JOIN br_invoices h ON h.agent_key=l.agent_key AND h.sth_id=l.sth_id AND h.suffix=l.suffix
        LEFT JOIN hq_codes c ON c.code=l.item_code
        WHERE h.doc_type IN (4,7,17) AND h.agent_key IN ('zahraa','bonjour','degla','abc')
        AND h.invoice_date>=CURRENT_DATE - INTERVAL 89 DAY AND h.invoice_date<CURRENT_DATE + INTERVAL 1 DAY
        GROUP BY l.item_id";
}

/** نفس تطبيع xtable، في SQL علشان الاقتراح يفضل استعلام واحد. */
function ica_norm_sql(string $column): string
{
    $sql = "LOWER($column)";
    $from = ['أ','إ','آ','ٱ','ة','ى','ؤ','ئ','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $to = ['ا','ا','ا','ا','ه','ي','و','ي','0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    foreach ($from as $i=>$char) $sql = "REPLACE($sql,'$char','{$to[$i]}')";
    return "TRIM(REGEXP_REPLACE(REGEXP_REPLACE($sql,'[ً-ْـٰ]',''),'[[:space:]]+',' '))";
}

function ica_list(array $filters): array
{
    $effective = ic_effective_sql('section');
    $sales = ica_sales_sql();
    $base = "SELECT c.item_id,COALESCE(MAX(NULLIF(t.item_name,'')),MAX(c.name),'') name,
        COALESCE(MIN(CASE WHEN c.code_type=0 THEN c.code END),MIN(c.code)) code
        FROM hq_codes c LEFT JOIN hq_item_cats t ON t.item_id=c.item_id GROUP BY c.item_id";
    $where = ['1=1']; $params = [];
    if (!empty($filters['item_ids'])) {
        $selectedIds=array_values(array_unique(array_map('intval',(array)$filters['item_ids'])));
        if (count($selectedIds)>200) throw new InvalidArgumentException('٢٠٠ صنف بالكتير');
        $where[]='p.item_id IN ('.implode(',',array_fill(0,count($selectedIds),'?')).')';
        array_push($params,...$selectedIds);
    }
    if (!empty($filters['no_section'])) $where[] = 'ec.class_id IS NULL';
    if (!empty($filters['sold90'])) $where[] = 'COALESCE(s.sold,0)>0';
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $parts = [];
        foreach (array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY),0,8) as $word) {
            $parts[] = "p.name LIKE ? ESCAPE '!'";
            $params[] = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';
        }
        $where[] = '('.implode(' AND ',$parts)." OR EXISTS (SELECT 1 FROM hq_codes hc WHERE hc.item_id=p.item_id AND hc.code LIKE ? ESCAPE '!'))";
        $params[] = str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
    }
    $assigned = (string)($filters['assigned_by'] ?? '');
    if ($assigned === 'none') $where[] = 'NOT EXISTS (SELECT 1 FROM item_class_assign a WHERE a.item_id=p.item_id)';
    elseif ((int)$assigned>0) { $where[]='EXISTS (SELECT 1 FROM item_class_assign a WHERE a.item_id=p.item_id AND a.assigned_by=?)'; $params[]=(int)$assigned; }
    $status = (string)($filters['status'] ?? '');
    if ($status === 'pending') $where[]="EXISTS (SELECT 1 FROM item_class_assign a WHERE a.item_id=p.item_id AND a.status='pending')";
    if ($status === 'approved') $where[]="EXISTS (SELECT 1 FROM item_class_assign a WHERE a.item_id=p.item_id) AND NOT EXISTS (SELECT 1 FROM item_class_assign a WHERE a.item_id=p.item_id AND a.status='pending')";
    $from = "FROM ($base) p LEFT JOIN ($effective) ec ON ec.item_id=p.item_id LEFT JOIN ($sales) s ON s.item_id=p.item_id WHERE ".implode(' AND ',$where);
    $total = (int)ica_q("SELECT COUNT(*) $from",$params)->fetchColumn();
    $pages = max(1,(int)ceil($total/200)); $page = min($pages,max(1,(int)($filters['page'] ?? 1))); $offset=($page-1)*200;
    // التجميع العام ده للترتيب والفلتر بس؛ الرصيد والتصنيفات والاقتراح لأصناف الصفحة بس.
    $rows = ica_q("SELECT p.*,COALESCE(s.sold,0) sold $from ORDER BY sold DESC,p.item_id LIMIT 200 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC);
    $remaining = (int)ica_q("SELECT COUNT(*) FROM ($base) p LEFT JOIN ($effective) e ON e.item_id=p.item_id WHERE e.class_id IS NULL")->fetchColumn();
    $options = ic_options();
    $users = ica_q('SELECT DISTINCT u.id,u.full_name name FROM users u JOIN item_class_assign a ON a.assigned_by=u.id ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return compact('rows','total','pages','page','remaining','options','users');
    $ids=array_column($rows,'item_id'); $ph=implode(',',array_fill(0,count($ids),'?'));
    $balanceParts=[];
    foreach (REORDER_AGENT_TO_STO as $agent=>$sto) $balanceParts[]="(agent_key='$agent' AND sto_id=$sto)";
    $balanceWhere=implode(' OR ',$balanceParts);
    $balances=ica_q("SELECT item_id,SUM(balance) balance FROM (
        SELECT item_id,balance FROM hq_branch_balances WHERE item_id IN ($ph) AND ($balanceWhere)
        UNION ALL SELECT item_id,balance FROM hq_balances WHERE item_id IN ($ph) AND sto_id=1) bb GROUP BY item_id",array_merge($ids,$ids))->fetchAll(PDO::FETCH_KEY_PAIR);
    $classes=ica_q("SELECT a.*,c.name,u.full_name user_name FROM item_class_assign a JOIN item_classes c ON c.id=a.class_id
        LEFT JOIN users u ON u.id=a.assigned_by WHERE a.item_id IN ($ph)",$ids)->fetchAll(PDO::FETCH_ASSOC);
    $true=ica_q("SELECT t.item_id,c.kind,MIN(c.id) id,MIN(c.name) name FROM hq_item_cats t JOIN item_classes c ON c.true_id>0 AND
        ((c.kind='section' AND c.true_id=t.is1_id) OR (c.kind='description' AND c.true_id=t.is2_id)
        OR (c.kind='type' AND c.true_id=t.is3_id) OR (c.kind='stage' AND c.true_id=t.is4_id) OR (c.kind='company' AND c.true_id=t.is5_id))
        WHERE t.item_id IN ($ph) GROUP BY t.item_id,c.kind",$ids)->fetchAll(PDO::FETCH_ASSOC);
    $map=[];
    foreach ($true as $c) $map[$c['item_id']][$c['kind']]=['id'=>(int)$c['id'],'name'=>$c['name'],'source'=>'true'];
    $states=[]; $names=[];
    foreach ($classes as $c) {
        $map[$c['item_id']][$c['kind']]=['id'=>(int)$c['class_id'],'name'=>$c['name'],'source'=>'site'];
        if (($states[$c['item_id']] ?? '')!=='pending') $states[$c['item_id']]=$c['status'];
        $names[$c['item_id']][$c['assigned_by']]=$c['user_name'];
    }
    $norm=ica_norm_sql('p.name');
    // أول كلمتين بعد التطبيع، والأكثر تكراراً، والتعادل بأقل رقم تصنيف.
    $suggest=ica_q("WITH pool AS (SELECT p.item_id,SUBSTRING_INDEX($norm,' ',2) prefix,e.class_id
        FROM ($base) p LEFT JOIN ($effective) e ON e.item_id=p.item_id),
        counts AS (SELECT prefix,class_id,COUNT(*) n FROM pool WHERE class_id IS NOT NULL
        AND prefix IN (SELECT prefix FROM pool WHERE item_id IN ($ph)) GROUP BY prefix,class_id),
        ranked AS (SELECT prefix,class_id,ROW_NUMBER() OVER(PARTITION BY prefix ORDER BY n DESC,class_id) rn FROM counts)
        SELECT p.item_id,r.class_id,c.name FROM pool p JOIN ranked r ON r.prefix=p.prefix AND r.rn=1
        JOIN item_classes c ON c.id=r.class_id AND c.is_active=1 WHERE p.item_id IN ($ph)",array_merge($ids,$ids))->fetchAll(PDO::FETCH_ASSOC);
    $suggestions=[];
    foreach ($suggest as $s) $suggestions[$s['item_id']]=['id'=>(int)$s['class_id'],'name'=>$s['name']];
    foreach ($rows as &$row) {
        $id=$row['item_id'];$row['balance']=$balances[$id] ?? 0;$row['classes']=$map[$id] ?? [];
        $row['status']=$states[$id] ?? '';$row['assigned_name']=implode('، ',array_values($names[$id] ?? []));$row['suggestion']=$suggestions[$id] ?? null;
    }
    unset($row);
    return compact('rows','total','pages','page','remaining','options','users');
}

/** عملية واحدة لكل المعلّم، بقفل الصفوف وسجل وكتابة في نفس المعاملة. */
function ica_write(array $input, int $userId, bool $review): array
{
    $action=(string)($input['action'] ?? 'assign');
    if (!in_array($action,['assign','approve','clear'],true)) throw new InvalidArgumentException('العملية مش معروفة');
    if ($action==='approve' && !$review) throw new DomainException('مالكش صلاحية المراجعة');
    $ids=array_values(array_unique(array_map('intval',(array)($input['item_ids'] ?? []))));
    if (!$ids || count($ids)>200 || min($ids)<=0) throw new InvalidArgumentException('علّم من صنف لـ٢٠٠ صنف');
    $kind=(string)($input['kind'] ?? 'section');ic_kind_label($kind);
    $classId=(int)($input['class_id'] ?? 0);
    if ($action==='assign') ic_check_refs([$kind=>$classId]);
    $ph=implode(',',array_fill(0,count($ids),'?'));$pdo=db();$pdo->beginTransaction();
    try {
        // قفل أصل الصنف كمان بيحمي أول تقسيم لما لسه مفيش صف ربط.
        $found=ica_q("SELECT item_id FROM hq_codes WHERE item_id IN ($ph) ORDER BY item_id,code FOR UPDATE",$ids)->fetchAll(PDO::FETCH_COLUMN);
        $found=array_unique($found);
        if (count($found)!==count($ids)) throw new InvalidArgumentException('في صنف مش موجود في مرآة الأصناف');
        $old=ica_q("SELECT * FROM item_class_assign WHERE item_id IN ($ph) ORDER BY item_id,kind FOR UPDATE",$ids)->fetchAll(PDO::FETCH_ASSOC);
        $oldMap=[]; foreach ($old as $r) $oldMap[$r['item_id']][$r['kind']]=$r;
        $logs=[];$values=[];$changed=[];
        if ($action==='approve') {
            foreach ($old as $r) if ($r['status']==='pending') {
                array_push($logs,$r['item_id'],$r['kind'],$r['class_id'],$r['class_id'],'approve',$userId);
                $changed[$r['item_id']]=true;
            }
            ica_q("UPDATE item_class_assign SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE item_id IN ($ph) AND status='pending'",array_merge([$userId],$ids));
        } else {
            foreach ($ids as $id) {
                $previous=$oldMap[$id][$kind]['class_id'] ?? null;
                if ($action==='clear' && $previous===null) continue;
                array_push($logs,$id,$kind,$previous,$action==='clear'?null:$classId,$action,$userId);$changed[$id]=true;
                if ($action==='assign') array_push($values,$id,$kind,$classId,$review?'approved':'pending',$userId,$review?$userId:null);
            }
            if ($action==='clear') ica_q("DELETE FROM item_class_assign WHERE item_id IN ($ph) AND kind=?",array_merge($ids,[$kind]));
            elseif ($values) {
                $slots=implode(',',array_fill(0,count($ids),'(?,?,?,?,?,NOW(),?,'.($review?'NOW()':'NULL').')'));
                ica_q("INSERT INTO item_class_assign (item_id,kind,class_id,status,assigned_by,assigned_at,reviewed_by,reviewed_at) VALUES $slots
                    ON DUPLICATE KEY UPDATE class_id=VALUES(class_id),status=VALUES(status),assigned_by=VALUES(assigned_by),assigned_at=VALUES(assigned_at),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)",$values);
            }
        }
        if ($logs) {
            $slots=implode(',',array_fill(0,(int)(count($logs)/6),'(?,?,?,?,?,?,NOW())'));
            ica_q("INSERT INTO item_class_assign_log (item_id,kind,old_class_id,new_class_id,action,user_id,created_at) VALUES $slots",$logs);
        }
        $pdo->commit();return ['changed'=>count($changed),'status'=>$review?'approved':'pending'];
    } catch (Throwable $e) { $pdo->rollBack();throw $e; }
}
