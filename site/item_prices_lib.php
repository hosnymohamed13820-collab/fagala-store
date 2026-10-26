<?php
/** منطق تغيير أسعار الموقع الجماعي — True للقراءة بس. */
declare(strict_types=1);

require_once __DIR__ . '/item_master_lib.php';

const IPP_PAGE_SIZE = 50;
const IPP_PREVIEW_TTL = 1800;

/** @return array{categories:array<int,mixed>,suppliers:array<int,string>} */
function ipp_meta(): array
{
    $sql = "SELECT supplier_name FROM (
                SELECT DISTINCT COALESCE(h.supplier_name,h.supplier_code,'مورد غير معروف') supplier_name
                  FROM hq_invoices h WHERE h.doc_type=2
                UNION
                SELECT DISTINCT s.name supplier_name
                  FROM stock_docs d JOIN suppliers s ON s.id=d.supplier_id
                 WHERE d.doc_type='purchase' AND d.status='posted'
            ) x ORDER BY supplier_name";
    return [
        'categories' => im_categories(),
        'suppliers' => array_map('strval', db()->query($sql)->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function ipp_normalize_request(array $input): array
{
    $type = (string) ($input['operation_type'] ?? 'percent');
    $direction = (string) ($input['direction'] ?? 'increase');
    $rounding = (string) ($input['rounding'] ?? 'none');
    $source = (string) ($input['source'] ?? 'all');
    $value = $input['value'] ?? null;
    if (!in_array($type, ['percent', 'amount'], true)
        || !in_array($direction, ['increase', 'decrease'], true)
        || !in_array($rounding, ['none', '0.25', '0.5', '1'], true)
        || !in_array($source, ['all', 'true', 'site'], true)
        || !is_numeric($value) || (float) $value <= 0) {
        throw new InvalidArgumentException('راجع نوع العملية والاتجاه والقيمة والتقريب');
    }
    return [
        'filters' => [
            'category_id' => max(0, (int) ($input['category_id'] ?? 0)),
            'supplier' => mb_substr(trim((string) ($input['supplier'] ?? '')), 0, 255),
            'source' => $source,
            'zero_only' => !empty($input['zero_only']),
            'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 120),
        ],
        'operation_type' => $type,
        'direction' => $direction,
        'value' => round((float) $value, 4),
        'rounding' => $rounding,
    ];
}

/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
function ipp_filtered_items(array $filters): array
{
    // في «الكل» بنعرض الكود مرة واحدة ونفضّل صف True. فلتر «الموقع»
    // بيعرض أكواد أصناف الموقع حتى لو وصلت بعدين لمرآة True.
    $siteOnlyCondition = $filters['source'] === 'site' ? '' : 'WHERE hc.code IS NULL';
    $purchases = "SELECT purchase_key,
                         SUBSTRING_INDEX(MAX(CONCAT(stamp,'\t',supplier_name)),'\t',-1) supplier_name
                    FROM (
                        SELECT CONCAT('t:',hl.item_id) purchase_key,
                               COALESCE(h.supplier_name,h.supplier_code,'مورد غير معروف') supplier_name,
                               CONCAT(DATE_FORMAT(COALESCE(h.invoice_date,'1000-01-01'),'%Y%m%d%H%i%s'),
                                      LPAD(h.sth_id,20,'0')) stamp
                          FROM hq_invoice_lines hl
                          JOIN hq_invoices h ON h.sth_id=hl.sth_id AND h.suffix=hl.suffix
                         WHERE h.doc_type=2
                        UNION ALL
                        SELECT CONCAT('t:',l.item_id),s.name,
                               CONCAT(DATE_FORMAT(d.doc_date,'%Y%m%d'),'000000',LPAD(d.id,20,'0'))
                          FROM stock_doc_lines l
                          JOIN stock_docs d ON d.id=l.doc_id
                          JOIN suppliers s ON s.id=d.supplier_id
                         WHERE d.doc_type='purchase' AND d.status='posted'
                        UNION ALL
                        SELECT CONCAT('s:',m.id),s.name,
                               CONCAT(DATE_FORMAT(d.doc_date,'%Y%m%d'),'000000',LPAD(d.id,20,'0'))
                          FROM stock_doc_lines l
                          JOIN stock_docs d ON d.id=l.doc_id
                          JOIN suppliers s ON s.id=d.supplier_id
                          JOIN item_master m ON m.id=l.item_id
                         WHERE d.doc_type='purchase' AND d.status='posted'
                        UNION ALL
                        SELECT CONCAT('s:',mc.item_master_id),s.name,
                               CONCAT(DATE_FORMAT(d.doc_date,'%Y%m%d'),'000000',LPAD(d.id,20,'0'))
                          FROM stock_doc_lines l
                          JOIN stock_docs d ON d.id=l.doc_id
                          JOIN suppliers s ON s.id=d.supplier_id
                          JOIN item_master_codes mc ON mc.code=l.code
                         WHERE d.doc_type='purchase' AND d.status='posted'
                    ) all_purchases GROUP BY purchase_key";

    $items = "SELECT c.code,COALESCE(NULLIF(ic.item_name,''),NULLIF(c.name,''),c.code) item_name,
                       'true' item_source,c.item_id,ic.is1_id category_id,cn.name category_name,
                       c.price true_price,CONCAT('t:',c.item_id) purchase_key
                  FROM hq_codes c
                  LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
                  LEFT JOIN hq_cat_names cn ON cn.is1_id=ic.is1_id
                UNION ALL
                SELECT mc.code,COALESCE(NULLIF(mc.name1,''),m.name1,mc.code),'site',m.id,
                       m.cat_is1_id,cn.name,hc.price,CONCAT('s:',m.id)
                  FROM item_master_codes mc
                  JOIN item_master m ON m.id=mc.item_master_id
                  LEFT JOIN hq_cat_names cn ON cn.is1_id=m.cat_is1_id
                  LEFT JOIN hq_codes hc ON hc.code=mc.code
                 $siteOnlyCondition";

    $where = [];
    $params = [];
    if ($filters['source'] !== 'all') {
        $where[] = 'i.item_source=?';
        $params[] = $filters['source'];
    }
    if ((int) $filters['category_id'] > 0) {
        $where[] = 'i.category_id=?';
        $params[] = (int) $filters['category_id'];
    }
    if ($filters['supplier'] !== '') {
        $where[] = 'lp.supplier_name=?';
        $params[] = $filters['supplier'];
    }
    if (!empty($filters['zero_only'])) {
        $where[] = 'COALESCE(ip.price,i.true_price,0)=0';
    }
    foreach (im_words((string) $filters['search']) as $word) {
        $where[] = '(i.code LIKE ? OR i.item_name LIKE ?)';
        $like = '%' . $word . '%';
        array_push($params, $like, $like);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT i.code,i.item_name,i.item_source,i.category_id,
                   COALESCE(i.category_name,'') category_name,COALESCE(lp.supplier_name,'') supplier_name,
                   i.true_price,ip.price site_price,COALESCE(ip.price,i.true_price,0) old_price,
                   CASE WHEN ip.code IS NOT NULL THEN 'site'
                        WHEN i.true_price IS NOT NULL THEN 'true' ELSE 'zero' END base_source
              FROM ($items) i
              LEFT JOIN item_prices ip ON ip.code=i.code
              LEFT JOIN ($purchases) lp ON lp.purchase_key=i.purchase_key
              $whereSql
             ORDER BY i.item_name,i.code";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ipp_proposed_price(float $old, string $type, string $direction, float $value, string $rounding): float
{
    $delta = $type === 'percent' ? $old * $value / 100 : $value;
    $raw = $direction === 'increase' ? $old + $delta : $old - $delta;
    $step = match ($rounding) {
        '0.25' => 0.25,
        '0.5' => 0.5,
        '1' => 1.0,
        default => 0.01,
    };
    return round(round($raw / $step, 0, PHP_ROUND_HALF_UP) * $step, 2);
}

/** @param array<int,array<string,mixed>> $rows @return array<string,int|float> */
function ipp_summary(array $rows): array
{
    $summary = ['count' => count($rows), 'ready' => 0, 'skipped' => 0, 'old_total' => 0.0, 'new_total' => 0.0, 'diff_total' => 0.0];
    foreach ($rows as $row) {
        if ($row['result'] === 'skipped') {
            $summary['skipped']++;
            continue;
        }
        $summary['ready']++;
        $summary['old_total'] += (float) $row['old_price'];
        $summary['new_total'] += (float) $row['proposed_price'];
        $summary['diff_total'] += (float) $row['difference'];
    }
    foreach (['old_total','new_total','diff_total'] as $key) $summary[$key] = round((float) $summary[$key], 2);
    return $summary;
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function ipp_create_preview(array $input, int $userId): array
{
    $request = ipp_normalize_request($input);
    $rows = ipp_filtered_items($request['filters']);
    foreach ($rows as &$row) {
        $old = round((float) $row['old_price'], 2);
        $new = ipp_proposed_price($old, $request['operation_type'], $request['direction'], (float) $request['value'], $request['rounding']);
        $skip = $request['direction'] === 'decrease' && $new <= 0;
        $row['true_price'] = $row['true_price'] !== null ? round((float) $row['true_price'], 2) : null;
        $row['old_price'] = $old;
        $row['proposed_price'] = $new;
        $row['difference'] = round($new - $old, 2);
        $row['result'] = $skip ? 'skipped' : 'ready';
        $row['skip_reason'] = $skip ? 'السعر بعد النقص هيبقى صفر أو أقل' : null;
    }
    unset($row);

    ipp_clean_previews();
    $token = bin2hex(random_bytes(24));
    $_SESSION['item_price_previews'][$token] = [
        'created_at' => time(), 'user_id' => $userId, 'request' => $request,
        // JSON أخف بكتير في ملف الـsession من serialize لمصفوفة آلاف السطور.
        'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
    return ipp_preview_page($token, 1, $userId);
}

function ipp_clean_previews(): void
{
    $all = $_SESSION['item_price_previews'] ?? [];
    if (!is_array($all)) $all = [];
    $cutoff = time() - IPP_PREVIEW_TTL;
    foreach ($all as $token => $preview) {
        if (!is_array($preview) || (int) ($preview['created_at'] ?? 0) < $cutoff) unset($all[$token]);
    }
    if (count($all) > 5) {
        uasort($all, static fn($a, $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
        $all = array_slice($all, 0, 5, true);
    }
    $_SESSION['item_price_previews'] = $all;
}

/** @return array<string,mixed> */
function ipp_get_preview(string $token, int $userId): array
{
    ipp_clean_previews();
    $preview = $_SESSION['item_price_previews'][$token] ?? null;
    if (!is_array($preview) || (int) ($preview['user_id'] ?? 0) !== $userId) {
        throw new InvalidArgumentException('المعاينة انتهت؛ اعمل معاينة جديدة');
    }
    $rows = json_decode((string) ($preview['rows_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows)) throw new InvalidArgumentException('لقطة المعاينة غير صالحة؛ اعمل معاينة جديدة');
    $preview['rows'] = $rows;
    return $preview;
}

/** @return array<string,mixed> */
function ipp_preview_page(string $token, int $page, int $userId): array
{
    $preview = ipp_get_preview($token, $userId);
    $rows = $preview['rows'];
    $total = count($rows);
    $pages = $total ? (int) ceil($total / IPP_PAGE_SIZE) : 0;
    $page = $pages ? min(max(1, $page), $pages) : 1;
    return [
        'token' => $token,
        'summary' => ipp_summary($rows),
        'rows' => array_slice($rows, ($page - 1) * IPP_PAGE_SIZE, IPP_PAGE_SIZE),
        'page' => $page,
        'pages' => $pages,
        'request' => $preview['request'],
        'expires_in' => max(0, IPP_PREVIEW_TTL - (time() - (int) $preview['created_at'])),
    ];
}

/** @return array<string,mixed> */
function ipp_apply_preview(string $token, int $userId): array
{
    $tokenHash = hash('sha256', $token);
    $existing = db()->prepare('SELECT id FROM item_price_batches WHERE preview_token_hash=?');
    $existing->execute([$tokenHash]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($existingId > 0) return ipp_batch_result($existingId);

    $preview = ipp_get_preview($token, $userId);
    if (!$preview['rows']) throw new InvalidArgumentException('المعاينة مفيهاش أصناف للتطبيق');
    $request = $preview['request'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $batch = $pdo->prepare('INSERT INTO item_price_batches
            (preview_token_hash,filters_json,operation_type,direction,operation_value,rounding_rule,line_count,status,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,\'applying\',?,NOW())');
        $batch->execute([
            $tokenHash, json_encode($request['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $request['operation_type'], $request['direction'], $request['value'], $request['rounding'],
            count($preview['rows']), $userId,
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $pdo->exec('CREATE TEMPORARY TABLE ipp_preview_rows (
            code VARCHAR(64) NOT NULL PRIMARY KEY,snapshot_json LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $groups = [];
        $params = [];
        foreach ($preview['rows'] as $row) {
            $groups[] = '(?,?)';
            $params[] = (string) $row['code'];
            $params[] = json_encode([
                'old' => $row['old_price'], 'proposed' => $row['proposed_price'],
                'true' => $row['true_price'], 'skip_reason' => $row['skip_reason'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $load = $pdo->prepare('INSERT INTO ipp_preview_rows (code,snapshot_json) VALUES ' . implode(',', $groups));
        $load->execute($params);

        $oldExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.old')) AS DECIMAL(14,2))";
        $newExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.proposed')) AS DECIMAL(14,2))";
        $trueExpr = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.true')),'null') AS DECIMAL(14,2))";
        $skipExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.skip_reason')),'null')";
        $currentExpr = 'COALESCE(ip.price,c.true_price,0)';
        $catalog = "SELECT code,MAX(true_price) true_price FROM (
                        SELECT code,price true_price FROM hq_codes
                        UNION ALL
                        SELECT mc.code,NULL FROM item_master_codes mc
                         WHERE NOT EXISTS (SELECT 1 FROM hq_codes hc WHERE hc.code=mc.code)
                    ) all_codes GROUP BY code";
        $lines = $pdo->prepare("INSERT INTO item_price_batch_lines
            (batch_id,code,old_price,proposed_price,true_price_at_preview,result,skip_reason)
            SELECT ?,p.code,$oldExpr,$newExpr,$trueExpr,
                   CASE WHEN $skipExpr IS NOT NULL OR c.code IS NULL
                                  OR ABS($currentExpr-$oldExpr)>=0.005 THEN 'skipped' ELSE 'applied' END,
                   CASE WHEN $skipExpr IS NOT NULL THEN $skipExpr
                        WHEN c.code IS NULL THEN 'الكود مبقاش موجود'
                        WHEN ABS($currentExpr-$oldExpr)>=0.005 THEN 'السعر الأساسي اتغيّر بعد المعاينة'
                        ELSE NULL END
              FROM ipp_preview_rows p
              LEFT JOIN ($catalog) c ON c.code=p.code
              LEFT JOIN item_prices ip ON ip.code=p.code");
        $lines->execute([$batchId]);

        im_write_price_changes(
            $pdo,
            "SELECT code,old_price,proposed_price,true_price_at_preview,
                    CONCAT('تغيير أسعار جماعي - دفعة #',?) reason
               FROM item_price_batch_lines WHERE batch_id=? AND result='applied'",
            [$batchId, $batchId],
            $userId,
            $batchId
        );
        $done = $pdo->prepare("UPDATE item_price_batches
                                 SET status='applied',applied_by=?,applied_at=NOW() WHERE id=?");
        $done->execute([$userId, $batchId]);
        $pdo->exec('DROP TEMPORARY TABLE ipp_preview_rows');
        $pdo->commit();
        unset($_SESSION['item_price_previews'][$token]);
        return ipp_batch_result($batchId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) $e->getCode() === '23000')) {
            $race = db()->prepare('SELECT id FROM item_price_batches WHERE preview_token_hash=?');
            $race->execute([$tokenHash]);
            $raceId = (int) ($race->fetchColumn() ?: 0);
            if ($raceId > 0) return ipp_batch_result($raceId);
        }
        throw $e;
    }
}

/** @return array<string,mixed> */
function ipp_batch_result(int $batchId): array
{
    $st = db()->prepare("SELECT b.id,b.line_count,b.status,
        SUM(l.result='applied') applied_count,SUM(l.result='skipped') skipped_count
        FROM item_price_batches b LEFT JOIN item_price_batch_lines l ON l.batch_id=b.id
        WHERE b.id=? GROUP BY b.id,b.line_count,b.status");
    $st->execute([$batchId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('الدفعة مش موجودة');
    return $row;
}

/** @return array<string,mixed> */
function ipp_batches(int $page): array
{
    $page = max(1, $page);
    $total = (int) db()->query('SELECT COUNT(*) FROM item_price_batches')->fetchColumn();
    $pages = $total ? (int) ceil($total / IPP_PAGE_SIZE) : 0;
    if ($pages) $page = min($page, $pages);
    $offset = ($page - 1) * IPP_PAGE_SIZE;
    $sql = "SELECT b.id,b.operation_type,b.direction,b.operation_value,b.rounding_rule,b.line_count,
                   b.status,b.created_at,b.applied_at,u.full_name created_by_name,
                   SUM(l.result='applied') applied_count,SUM(l.result='skipped') skipped_count
              FROM item_price_batches b
              LEFT JOIN users u ON u.id=b.created_by
              LEFT JOIN item_price_batch_lines l ON l.batch_id=b.id
             GROUP BY b.id,b.operation_type,b.direction,b.operation_value,b.rounding_rule,b.line_count,
                      b.status,b.created_at,b.applied_at,u.full_name
             ORDER BY b.id DESC LIMIT " . IPP_PAGE_SIZE . " OFFSET $offset";
    return ['rows' => db()->query($sql)->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages, 'total' => $total];
}

/** @return array<string,mixed> */
function ipp_batch_detail(int $batchId, int $page): array
{
    $head = db()->prepare('SELECT b.*,u.full_name created_by_name,au.full_name applied_by_name
        FROM item_price_batches b LEFT JOIN users u ON u.id=b.created_by
        LEFT JOIN users au ON au.id=b.applied_by WHERE b.id=?');
    $head->execute([$batchId]);
    $batch = $head->fetch(PDO::FETCH_ASSOC);
    if (!$batch) throw new InvalidArgumentException('الدفعة مش موجودة');
    $page = max(1, $page);
    $pages = (int) ceil((int) $batch['line_count'] / IPP_PAGE_SIZE);
    if ($pages) $page = min($page, $pages);
    $offset = ($page - 1) * IPP_PAGE_SIZE;
    $lines = db()->prepare('SELECT code,old_price,proposed_price,true_price_at_preview,result,skip_reason
        FROM item_price_batch_lines WHERE batch_id=? ORDER BY id LIMIT ' . IPP_PAGE_SIZE . " OFFSET $offset");
    $lines->execute([$batchId]);
    return ['batch' => $batch, 'rows' => $lines->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages];
}

function ipp_export_preview(string $token, int $userId): never
{
    $preview = ipp_get_preview($token, $userId);
    require_once __DIR__ . '/xlsx_lib.php';
    $x = new SimpleXlsx('معاينة الأسعار');
    $x->setColumnTypes(['s','s','s','s','s','n','n','n','n','s']);
    $x->addRow(['الكود','الصنف','المصدر','القسم','آخر مورد','سعر True','السعر القديم','السعر المقترح','الفرق','النتيجة'], true);
    foreach ($preview['rows'] as $row) {
        $x->addRow([
            (string) $row['code'], $row['item_name'], $row['item_source'] === 'true' ? 'True' : 'الموقع',
            $row['category_name'], $row['supplier_name'], $row['true_price'], $row['old_price'],
            $row['proposed_price'], $row['difference'], $row['skip_reason'] ?: 'جاهز للتطبيق',
        ]);
    }
    $x->download('item_price_preview_' . date('Ymd_His') . '.xlsx');
    exit;
}
