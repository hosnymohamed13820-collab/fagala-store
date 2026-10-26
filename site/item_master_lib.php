<?php
/**
 * منطق كارت الصنف — كل True من المرآة فقط، ومفيش أي اتصال بالجسر.
 */
declare(strict_types=1);

require_once __DIR__ . '/sensitive_lib.php';

const IM_PAGE_SIZE = 20;

/** @return string[] */
function im_words(string $q): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    if ($q === '') return [];
    return array_values(array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8));
}

/** @return array{rows:array<int,array<string,mixed>>,page:int,pages:int,total:int} */
function im_search(string $q, int $page): array
{
    $q = trim($q);
    $page = max(1, $page);
    if ($q === '') return ['rows' => [], 'page' => 1, 'pages' => 0, 'total' => 0];

    $words = im_words($q);
    $trueParts = [];
    $siteParts = [];
    $trueParams = [$q];
    $siteParams = [$q];
    foreach ($words as $word) {
        $trueParts[] = "CONCAT_WS(' ', c.name, ic.item_name) LIKE ?";
        $siteParts[] = "CONCAT_WS(' ', m.name1, mc.name1, mc.name2) LIKE ?";
        $like = '%' . $word . '%';
        $trueParams[] = $like;
        $siteParams[] = $like;
    }
    $trueName = $trueParts ? '(' . implode(' AND ', $trueParts) . ')' : '0';
    $siteName = $siteParts ? '(' . implode(' AND ', $siteParts) . ')' : '0';
    $union = "
        SELECT 'true' source, c.item_id item_key, c.item_id id,
               COALESCE(MAX(ic.item_name), MAX(c.name), '') name1,
               COALESCE(MAX(CASE WHEN c.code_type=0 THEN c.code END), MIN(c.code)) code,
               MAX(c.code = ?) exact_code
          FROM hq_codes c
          LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
         WHERE c.code = ? OR $trueName
         GROUP BY c.item_id
        UNION ALL
        SELECT 'site' source, m.id item_key, m.id id, m.name1,
               COALESCE(MAX(CASE WHEN mc.code_type=0 THEN mc.code END), MIN(mc.code)) code,
               MAX(mc.code = ?) exact_code
          FROM item_master m
          JOIN item_master_codes mc ON mc.item_master_id=m.id
         WHERE mc.code = ? OR $siteName
         GROUP BY m.id, m.name1";

    $params = array_merge([$q], $trueParams, [$q], $siteParams);
    $count = db()->prepare("SELECT COUNT(*) FROM ($union) im_results");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = $total > 0 ? (int) ceil($total / IM_PAGE_SIZE) : 0;
    if ($pages > 0) $page = min($page, $pages);
    $offset = ($page - 1) * IM_PAGE_SIZE;

    $st = db()->prepare("SELECT source,id,name1,code FROM ($union) im_results
                          ORDER BY exact_code DESC, name1 ASC, source ASC
                          LIMIT " . IM_PAGE_SIZE . " OFFSET " . $offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages, 'total' => $total];
}

/** @return array<int,array{is1_id:int,name:string}> */
function im_categories(): array
{
    return db()->query('SELECT is1_id,name FROM hq_cat_names ORDER BY name,is1_id')->fetchAll(PDO::FETCH_ASSOC);
}

/** @param string[] $codes */
function im_history(array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    if (!$codes) return [];
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $st = db()->prepare("SELECT h.id,h.code,h.old_price,h.new_price,h.true_price,h.reason,
                                h.created_at,u.full_name user_name
                           FROM item_price_history h
                           LEFT JOIN users u ON u.id=h.user_id
                          WHERE h.code IN ($ph)
                          ORDER BY h.created_at DESC,h.id DESC LIMIT 200");
    $st->execute($codes);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * موردين الصنف من المصدرين في استعلام واحد. التجميع في الذاكرة بياخد أول
 * سطر لكل مورد لأن الناتج مرتب من الأحدث، من غير أي استعلام جوه حلقة.
 */
function im_suppliers(string $source, int $id): array
{
    $localCondition = $source === 'site'
        ? '(l.item_id = ? OR l.code IN (SELECT code FROM item_master_codes WHERE item_master_id = ?))'
        : 'l.item_id = ?';
    $localParams = $source === 'site' ? [$id, $id] : [$id];
    $trueId = $source === 'true' ? $id : -1;
    $sql = "SELECT supplier_name,unit_price,unit_factor,unit_name,quantity,purchase_date,source
              FROM (
                    SELECT COALESCE(h.supplier_name,h.supplier_code,'مورد غير معروف') supplier_name,
                           l.unit_price,l.unit_factor,NULL unit_name,l.quantity,
                           h.invoice_date purchase_date,'true' source
                      FROM hq_invoice_lines l
                      JOIN hq_invoices h ON h.sth_id=l.sth_id AND h.suffix=l.suffix
                     WHERE h.doc_type=2 AND l.item_id=?
                    UNION ALL
                    SELECT s.name supplier_name,l.unit_price,l.unit_factor,
                           COALESCE(mc.unit_name,'') unit_name,l.qty_units quantity,
                           CONCAT(d.doc_date,' 00:00:00') purchase_date,'site' source
                      FROM stock_doc_lines l
                      JOIN stock_docs d ON d.id=l.doc_id
                      JOIN suppliers s ON s.id=d.supplier_id
                      LEFT JOIN item_master_codes mc ON mc.code=l.code
                     WHERE d.doc_type='purchase' AND d.status='posted' AND $localCondition
                   ) purchases
             ORDER BY purchase_date DESC,supplier_name";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$trueId], $localParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $out = [];
    $unlocked = sens_unlocked();
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string) $row['supplier_name']));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $row['unit_price'] = $unlocked ? ($row['unit_price'] !== null ? (float) $row['unit_price'] : null) : null;
        $out[] = $row;
    }
    return $out;
}

/** @return array<string,mixed>|null */
function im_detail(string $source, int $id): ?array
{
    if ($id <= 0 || !in_array($source, ['true', 'site'], true)) return null;
    $unlocked = sens_unlocked();

    if ($source === 'true') {
        $st = db()->prepare(
            'SELECT c.code,c.name name1,c.code_type,c.unit_factor,c.price,c.cost,
                    ic.item_name,ic.is1_id,cn.name category_name,ip.price site_price
               FROM hq_codes c
               LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
               LEFT JOIN hq_cat_names cn ON cn.is1_id=ic.is1_id
               LEFT JOIN item_prices ip ON ip.code=c.code
              WHERE c.item_id=? ORDER BY c.code_type,c.code'
        );
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $first = $rows[0];
        $codes = [];
        foreach ($rows as $row) {
            $codes[] = [
                'id' => null, 'code' => (string) $row['code'], 'name1' => (string) ($row['name1'] ?? ''),
                'name2' => null, 'code_type' => (int) $row['code_type'], 'unit_name' => null,
                'unit_type' => null, 'unit_factor' => (float) $row['unit_factor'], 'whole_qty' => null,
                'open_price' => null, 'prep_days' => null, 'display_screen' => null, 'display_order' => null,
                'is_purchase_code' => null, 'is_sale_code' => null,
                'true_price' => $row['price'] !== null ? (float) $row['price'] : null,
                'site_price' => $row['site_price'] !== null ? (float) $row['site_price'] : null,
                'cost' => $unlocked && $row['cost'] !== null ? (float) $row['cost'] : null,
            ];
        }
        $main = [
            'id' => $id, 'true_item_id' => $id,
            'name1' => (string) ($first['item_name'] ?: $first['name1']),
            'sale_price_class' => null, 'item_type' => null, 'is_cancelled' => null,
            'base_unit' => null, 'open_price' => null,
            'cat_is1_id' => $first['is1_id'] !== null ? (int) $first['is1_id'] : null,
            'category_name' => (string) ($first['category_name'] ?? ''),
            'cat_description_id' => null, 'cat_type_id' => null, 'cat_stage_id' => null,
            'cat_company_id' => null, 'min_qty' => null, 'max_qty' => null,
            'max_discount_pct' => null,
        ];
    } else {
        $st = db()->prepare(
            'SELECT m.*,cn.name category_name,mc.id code_id,mc.code,mc.name1 code_name1,mc.name2,
                    mc.code_type,mc.unit_name,mc.unit_type,mc.unit_factor,mc.whole_qty,mc.open_price code_open_price,
                    mc.prep_days,mc.display_screen,mc.display_order,mc.is_purchase_code,mc.is_sale_code,
                    hp.price true_price,hp.cost,ip.price site_price
               FROM item_master m
               LEFT JOIN hq_cat_names cn ON cn.is1_id=m.cat_is1_id
               LEFT JOIN item_master_codes mc ON mc.item_master_id=m.id
               LEFT JOIN hq_codes hp ON hp.code=mc.code
               LEFT JOIN item_prices ip ON ip.code=mc.code
              WHERE m.id=? ORDER BY mc.code_type,mc.id'
        );
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $first = $rows[0];
        $mainKeys = ['id','true_item_id','name1','sale_price_class','item_type','is_cancelled','base_unit','open_price',
            'cat_is1_id','cat_description_id','cat_type_id','cat_stage_id','cat_company_id','min_qty','max_qty','max_discount_pct'];
        $main = [];
        foreach ($mainKeys as $key) $main[$key] = $first[$key];
        $main['category_name'] = (string) ($first['category_name'] ?? '');
        $codes = [];
        foreach ($rows as $row) {
            if ($row['code_id'] === null) continue;
            $codes[] = [
                'id' => (int) $row['code_id'], 'code' => (string) $row['code'],
                'name1' => (string) $row['code_name1'], 'name2' => $row['name2'],
                'code_type' => (int) $row['code_type'], 'unit_name' => (string) $row['unit_name'],
                'unit_type' => (string) $row['unit_type'], 'unit_factor' => (float) $row['unit_factor'],
                'whole_qty' => (int) $row['whole_qty'], 'open_price' => (int) $row['code_open_price'],
                'prep_days' => (int) $row['prep_days'], 'display_screen' => $row['display_screen'],
                'display_order' => (int) $row['display_order'], 'is_purchase_code' => (int) $row['is_purchase_code'],
                'is_sale_code' => (int) $row['is_sale_code'],
                'true_price' => $row['true_price'] !== null ? (float) $row['true_price'] : null,
                'site_price' => $row['site_price'] !== null ? (float) $row['site_price'] : null,
                'cost' => $unlocked && $row['cost'] !== null ? (float) $row['cost'] : null,
            ];
        }
    }

    $codeValues = array_column($codes, 'code');
    return [
        'source' => $source, 'readonly' => $source === 'true', 'main' => $main, 'codes' => $codes,
        'history' => im_history($codeValues), 'suppliers' => im_suppliers($source, $id),
        'cost_unlocked' => $unlocked,
    ];
}

/** @param string[] $codes */
function im_duplicate_codes(array $codes, ?int $excludeCodeId = null): array
{
    $codes = array_values(array_unique(array_filter(array_map(fn($v) => trim((string) $v), $codes))));
    if (!$codes) return [];
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $siteWhere = $excludeCodeId ? ' AND id<>?' : '';
    $params = array_merge($codes, $codes, $excludeCodeId ? [$excludeCodeId] : []);
    $st = db()->prepare("SELECT code FROM hq_codes WHERE code IN ($ph)
                         UNION SELECT code FROM item_master_codes WHERE code IN ($ph)$siteWhere");
    $st->execute($params);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** @param array<string,mixed> $input */
function im_create(array $input, int $userId): int
{
    $name = trim((string) ($input['name1'] ?? ''));
    $codes = is_array($input['codes'] ?? null) ? array_values($input['codes']) : [];
    if ($name === '' || !$codes) throw new InvalidArgumentException('اسم الصنف والكود الأساسي مطلوبين');
    $clean = [];
    $hasBase = false;
    foreach ($codes as $row) {
        if (!is_array($row)) continue;
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') continue;
        $type = (int) ($row['code_type'] ?? 1) === 0 ? 0 : 1;
        $hasBase = $hasBase || $type === 0;
        $factor = (float) ($row['unit_factor'] ?? 1);
        if ($factor <= 0) throw new InvalidArgumentException('معامل التحويل لازم يبقى أكبر من صفر');
        $clean[] = [
            'code' => $code, 'name1' => trim((string) ($row['name1'] ?? $name)) ?: $name,
            'name2' => trim((string) ($row['name2'] ?? '')), 'code_type' => $type,
            'unit_name' => trim((string) ($row['unit_name'] ?? 'قطعة')) ?: 'قطعة',
            'unit_type' => trim((string) ($row['unit_type'] ?? ($type === 0 ? 'base' : 'sub'))) ?: 'base',
            'unit_factor' => $factor, 'whole_qty' => !empty($row['whole_qty']) ? 1 : 0,
            'open_price' => !empty($row['open_price']) ? 1 : 0, 'prep_days' => max(0, (int) ($row['prep_days'] ?? 0)),
            'display_screen' => trim((string) ($row['display_screen'] ?? '')),
            'display_order' => (int) ($row['display_order'] ?? 0),
            'is_purchase_code' => !empty($row['is_purchase_code']) ? 1 : 0,
            'is_sale_code' => !empty($row['is_sale_code']) ? 1 : 0,
            'price' => ($row['price'] ?? '') === '' ? null : (float) $row['price'],
        ];
    }
    if (!$hasBase) throw new InvalidArgumentException('لازم كود أساسي واحد على الأقل');
    if (!$clean) throw new InvalidArgumentException('الكود الأساسي مطلوب');
    $codeCounts = array_count_values(array_column($clean, 'code'));
    if (array_filter($codeCounts, fn($count) => $count > 1)) {
        throw new DomainException('نفس الكود متكرر في الصنف');
    }
    $duplicates = im_duplicate_codes(array_column($clean, 'code'));
    if ($duplicates) throw new DomainException('الكود موجود: ' . implode('، ', $duplicates));
    if (($input['cat_is1_id'] ?? '') !== '') {
        $cat = db()->prepare('SELECT 1 FROM hq_cat_names WHERE is1_id=?');
        $cat->execute([(int) $input['cat_is1_id']]);
        if (!$cat->fetchColumn()) throw new InvalidArgumentException('القسم مش موجود في أقسام True');
    }
    if (($input['max_discount_pct'] ?? '') !== ''
        && ((float) $input['max_discount_pct'] < 0 || (float) $input['max_discount_pct'] > 100)) {
        throw new InvalidArgumentException('نسبة الخصم لازم تبقى من صفر لـ100');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $main = im_main_values($input);
        $st = $pdo->prepare('INSERT INTO item_master
            (name1,sale_price_class,item_type,is_cancelled,base_unit,open_price,cat_is1_id,cat_description_id,
             cat_type_id,cat_stage_id,cat_company_id,min_qty,max_qty,max_discount_pct,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(array_merge($main, [$userId, $userId]));
        $itemId = (int) $pdo->lastInsertId();

        $groups = [];
        $params = [];
        foreach ($clean as $row) {
            $groups[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push($params, $itemId, $row['code'], $row['name1'], $row['name2'] ?: null, $row['code_type'],
                $row['unit_name'], $row['unit_type'], $row['unit_factor'], $row['whole_qty'], $row['open_price'],
                $row['prep_days'], $row['display_screen'] ?: null, $row['display_order'], $row['is_purchase_code'], $row['is_sale_code']);
        }
        $pdo->prepare('INSERT INTO item_master_codes
            (item_master_id,code,name1,name2,code_type,unit_name,unit_type,unit_factor,whole_qty,open_price,
             prep_days,display_screen,display_order,is_purchase_code,is_sale_code) VALUES ' . implode(',', $groups))->execute($params);

        $priced = array_values(array_filter($clean, fn($r) => $r['price'] !== null));
        if ($priced) {
            $priceGroups = [];
            $priceParams = [];
            $histGroups = [];
            $histParams = [];
            foreach ($priced as $row) {
                if ($row['price'] < 0) throw new InvalidArgumentException('السعر مينفعش يبقى سالب');
                $priceGroups[] = '(?,?,NULL,?,NOW())';
                array_push($priceParams, $row['code'], $row['price'], $userId);
                $histGroups[] = '(?,NULL,?,NULL,?,?,NOW(),NULL)';
                array_push($histParams, $row['code'], $row['price'], 'سعر إنشاء الصنف', $userId);
            }
            $pdo->prepare('INSERT INTO item_prices (code,price,true_price_at_change,updated_by,updated_at) VALUES ' . implode(',', $priceGroups))->execute($priceParams);
            $pdo->prepare('INSERT INTO item_price_history (code,old_price,new_price,true_price,reason,user_id,created_at,batch_id) VALUES ' . implode(',', $histGroups))->execute($histParams);
        }
        $pdo->commit();
        return $itemId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<int,mixed> */
function im_main_values(array $input): array
{
    $nullableInt = fn(string $key) => ($input[$key] ?? '') === '' ? null : (int) $input[$key];
    $nullableFloat = fn(string $key) => ($input[$key] ?? '') === '' ? null : (float) $input[$key];
    return [
        trim((string) ($input['name1'] ?? '')),
        in_array(($input['sale_price_class'] ?? ''), ['code','company'], true) ? $input['sale_price_class'] : 'code',
        trim((string) ($input['item_type'] ?? 'stock')) ?: 'stock', !empty($input['is_cancelled']) ? 1 : 0,
        trim((string) ($input['base_unit'] ?? 'قطعة')) ?: 'قطعة', !empty($input['open_price']) ? 1 : 0,
        $nullableInt('cat_is1_id'), $nullableInt('cat_description_id'), $nullableInt('cat_type_id'),
        $nullableInt('cat_stage_id'), $nullableInt('cat_company_id'), $nullableFloat('min_qty'),
        $nullableFloat('max_qty'), $nullableFloat('max_discount_pct'),
    ];
}

function im_update_field(int $id, string $field, mixed $value, int $userId): void
{
    $allowed = ['name1','sale_price_class','item_type','is_cancelled','base_unit','open_price','cat_is1_id',
        'cat_description_id','cat_type_id','cat_stage_id','cat_company_id','min_qty','max_qty','max_discount_pct'];
    if (!in_array($field, $allowed, true)) throw new InvalidArgumentException('الخانة دي مش قابلة للتعديل');
    if ($field === 'name1' && trim((string) $value) === '') throw new InvalidArgumentException('اسم الصنف مطلوب');
    if ($field === 'sale_price_class' && !in_array($value, ['code', 'company'], true)) {
        throw new InvalidArgumentException('تصنيف سعر البيع غير صحيح');
    }
    if (in_array($field, ['is_cancelled','open_price'], true)) $value = !empty($value) ? 1 : 0;
    if (str_starts_with($field, 'cat_') || in_array($field, ['min_qty','max_qty','max_discount_pct'], true)) {
        $value = $value === '' || $value === null ? null : $value;
    }
    if ($field === 'cat_is1_id' && $value !== null) {
        $cat = db()->prepare('SELECT 1 FROM hq_cat_names WHERE is1_id=?');
        $cat->execute([(int) $value]);
        if (!$cat->fetchColumn()) throw new InvalidArgumentException('القسم مش موجود في أقسام True');
        $value = (int) $value;
    }
    if ($field === 'max_discount_pct' && $value !== null && ((float) $value < 0 || (float) $value > 100)) {
        throw new InvalidArgumentException('نسبة الخصم لازم تبقى من صفر لـ100');
    }
    $st = db()->prepare("UPDATE item_master SET `$field`=?,updated_by=?,updated_at=NOW() WHERE id=?");
    $st->execute([$value, $userId, $id]);
    if ($st->rowCount() === 0) {
        $check = db()->prepare('SELECT 1 FROM item_master WHERE id=?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('صنف الموقع مش موجود');
    }
}

/** @param array<string,mixed> $input */
function im_save_code(int $itemId, int $codeId, array $input): int
{
    $code = trim((string) ($input['code'] ?? ''));
    $name = trim((string) ($input['name1'] ?? ''));
    $factor = (float) ($input['unit_factor'] ?? 0);
    if ($itemId <= 0 || $code === '' || $name === '' || $factor <= 0) {
        throw new InvalidArgumentException('الكود والاسم ومعامل التحويل مطلوبين');
    }
    $codeType = (int) ($input['code_type'] ?? 1) === 0 ? 0 : 1;
    if ($codeId > 0) {
        $owned = db()->prepare('SELECT code_type FROM item_master_codes WHERE id=? AND item_master_id=?');
        $owned->execute([$codeId, $itemId]);
        $oldType = $owned->fetchColumn();
        if ($oldType === false) throw new InvalidArgumentException('الكود مش تابع للصنف');
        if ((int) $oldType === 0 && $codeType === 1) {
            $bases = db()->prepare('SELECT COUNT(*) FROM item_master_codes WHERE item_master_id=? AND code_type=0 AND id<>?');
            $bases->execute([$itemId, $codeId]);
            if ((int) $bases->fetchColumn() === 0) throw new InvalidArgumentException('مينفعش تشيل آخر كود أساسي');
        }
    }
    if (im_duplicate_codes([$code], $codeId ?: null)) throw new DomainException('الكود موجود');
    $vals = [
        $code, $name, trim((string) ($input['name2'] ?? '')) ?: null,
        $codeType,
        trim((string) ($input['unit_name'] ?? 'قطعة')) ?: 'قطعة',
        trim((string) ($input['unit_type'] ?? 'base')) ?: 'base', $factor,
        !empty($input['whole_qty']) ? 1 : 0, !empty($input['open_price']) ? 1 : 0,
        max(0, (int) ($input['prep_days'] ?? 0)), trim((string) ($input['display_screen'] ?? '')) ?: null,
        (int) ($input['display_order'] ?? 0), !empty($input['is_purchase_code']) ? 1 : 0,
        !empty($input['is_sale_code']) ? 1 : 0,
    ];
    if ($codeId > 0) {
        $st = db()->prepare('UPDATE item_master_codes SET code=?,name1=?,name2=?,code_type=?,unit_name=?,unit_type=?,
            unit_factor=?,whole_qty=?,open_price=?,prep_days=?,display_screen=?,display_order=?,is_purchase_code=?,is_sale_code=?
            WHERE id=? AND item_master_id=?');
        $st->execute(array_merge($vals, [$codeId, $itemId]));
        return $codeId;
    }
    $st = db()->prepare('INSERT INTO item_master_codes
        (code,name1,name2,code_type,unit_name,unit_type,unit_factor,whole_qty,open_price,prep_days,display_screen,
         display_order,is_purchase_code,is_sale_code,item_master_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute(array_merge($vals, [$itemId]));
    return (int) db()->lastInsertId();
}

function im_change_price(string $code, float $price, string $reason, int $userId): void
{
    $code = trim($code);
    if ($code === '' || $price < 0) throw new InvalidArgumentException('الكود والسعر الصحيح مطلوبين');
    $st = db()->prepare(
        "SELECT x.true_price,ip.price old_price FROM (
             SELECT code,price true_price FROM hq_codes WHERE code=?
             UNION ALL SELECT code,NULL true_price FROM item_master_codes WHERE code=?
         ) x LEFT JOIN item_prices ip ON ip.code=x.code LIMIT 1"
    );
    $st->execute([$code, $code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('الكود مش موجود');
    $truePrice = $row['true_price'] !== null ? (float) $row['true_price'] : null;
    $oldPrice = $row['old_price'] !== null ? (float) $row['old_price'] : null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('INSERT INTO item_prices (code,price,true_price_at_change,updated_by,updated_at)
            VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price),true_price_at_change=VALUES(true_price_at_change),
            updated_by=VALUES(updated_by),updated_at=NOW()');
        $up->execute([$code, $price, $truePrice, $userId]);
        $hist = $pdo->prepare('INSERT INTO item_price_history
            (code,old_price,new_price,true_price,reason,user_id,created_at,batch_id) VALUES (?,?,?,?,?,?,NOW(),NULL)');
        $hist->execute([$code, $oldPrice, $price, $truePrice, trim($reason) ?: null, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
