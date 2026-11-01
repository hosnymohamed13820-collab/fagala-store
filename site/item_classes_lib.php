<?php
/**
 * الأقسام والتصنيفات (بريف ٢٣٤ — قرار محمد ٤٩).
 *
 * كارت الصنف في True فيه ٥ تصنيفات — القسم · الوصف · النوع · المرحلة · الشركات.
 * كلهم في جدول واحد `item_classes` ومفتاحهم `kind`. اللي جاي من True
 * (`true_id` مش NULL) **عرض بس**؛ اللي الموقع عمله (`true_id` NULL) بيتعدّل
 * ويتوقف ويتفعّل عادي.
 *
 * كل الدوال هنا مابتكتبش غير على صفوف الموقع. الرقم اللي بيتخزن في
 * `item_master.cat_*` هو `item_classes.id` مش كود True.
 */
declare(strict_types=1);

/** أنواع التصنيفات الخمسة زي ما هي في كارت الصنف. */
const IC_KINDS = [
    'section'     => 'القسم',
    'description' => 'الوصف',
    'type'        => 'النوع',
    'stage'       => 'المرحلة',
    'company'     => 'الشركات',
];

/** @return array<string,string> */
function ic_kinds(): array
{
    return IC_KINDS;
}

function ic_kind_label(string $kind): string
{
    if (!isset(IC_KINDS[$kind])) throw new InvalidArgumentException('نوع التصنيف غير معروف');
    return IC_KINDS[$kind];
}

/** الصفوف كلها (نشط وموقوف) لنوع واحد — لشاشة الإدارة. */
function ic_list(string $kind): array
{
    ic_kind_label($kind);
    $st = db()->prepare('SELECT id,kind,name,true_id,is_active,created_at
                           FROM item_classes
                          WHERE kind=?
                          ORDER BY is_active DESC, name ASC, id ASC');
    $st->execute([$kind]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * النشط بس، مجمّع بالنوع — لاختيارات كارت الصنف.
 *
 * @return array<string, array<int, array{id:int,name:string}>>
 */
function ic_options(): array
{
    $rows = db()->query('SELECT id,kind,name FROM item_classes WHERE is_active=1 ORDER BY kind ASC, name ASC, id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    $out = array_fill_keys(array_keys(IC_KINDS), []);
    foreach ($rows as $row) {
        $kind = (string) $row['kind'];
        if (!isset($out[$kind])) continue;
        $out[$kind][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
    return $out;
}

/**
 * التحقق من مجموعة مراجع تصنيف في **استعلام واحد**.
 * المفتاح = النوع، القيمة = item_classes.id.
 *
 * @param array<string,int|string|null> $refs
 * @return array<string,string> أسماء التصنيفات المقبولة (نوع => اسم)
 */
function ic_check_refs(array $refs): array
{
    $clean = [];
    foreach ($refs as $kind => $id) {
        if ($id === null || $id === '') continue;
        ic_kind_label((string) $kind);
        $clean[(string) $kind] = (int) $id;
    }
    if (!$clean) return [];

    $conds = [];
    $params = [];
    foreach ($clean as $kind => $id) {
        $conds[] = '(kind=? AND id=?)';
        array_push($params, $kind, $id);
    }
    $st = db()->prepare('SELECT kind,id,name,is_active FROM item_classes WHERE ' . implode(' OR ', $conds));
    $st->execute($params);

    $found = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $found[(string) $row['kind']] = $row;
    }
    $names = [];
    foreach ($clean as $kind => $id) {
        if (!isset($found[$kind])) {
            throw new InvalidArgumentException('التصنيف المختار مش موجود في ' . IC_KINDS[$kind]);
        }
        if ((int) $found[$kind]['is_active'] !== 1) {
            throw new InvalidArgumentException('التصنيف «' . $found[$kind]['name'] . '» موقوف — اختار غيره');
        }
        $names[$kind] = (string) $found[$kind]['name'];
    }
    return $names;
}

/** @return array<string,mixed> */
function ic_load(int $id): array
{
    $st = db()->prepare('SELECT id,kind,name,true_id,is_active FROM item_classes WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('التصنيف مش موجود');
    return $row;
}

function ic_clean_name(mixed $name): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') throw new InvalidArgumentException('اكتب الاسم');
    if (mb_strlen($name) > 255) throw new InvalidArgumentException('الاسم طويل أوي — ٢٥٥ حرف بالكتير');
    return $name;
}

function ic_create(string $kind, mixed $name, int $userId): int
{
    $label = ic_kind_label($kind);
    $name = ic_clean_name($name);

    $dup = db()->prepare('SELECT id FROM item_classes WHERE kind=? AND name=?');
    $dup->execute([$kind, $name]);
    if ($dup->fetchColumn() !== false) {
        throw new DomainException('الاسم «' . $name . '» موجود بالفعل في ' . $label);
    }

    try {
        $st = db()->prepare('INSERT INTO item_classes (kind,name,true_id,is_active,created_by) VALUES (?,?,NULL,1,?)');
        $st->execute([$kind, $name, $userId]);
    } catch (PDOException $e) {
        // سباق: اتحط بين الفحص والإدراج (أو فرق في الـcollation)
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('الاسم «' . $name . '» موجود بالفعل في ' . $label);
        }
        throw $e;
    }
    return (int) db()->lastInsertId();
}

function ic_rename(int $id, mixed $name, int $userId): string
{
    $row = ic_load($id);
    if ($row['true_id'] !== null) {
        throw new DomainException('التصنيف ده جاي من True — عرض بس، مش بيتعدّل من الموقع');
    }
    $name = ic_clean_name($name);
    if ($name === (string) $row['name']) return $name;

    $dup = db()->prepare('SELECT id FROM item_classes WHERE kind=? AND name=? AND id<>?');
    $dup->execute([$row['kind'], $name, $id]);
    if ($dup->fetchColumn() !== false) {
        throw new DomainException('الاسم «' . $name . '» موجود بالفعل في ' . IC_KINDS[$row['kind']]);
    }

    try {
        $st = db()->prepare('UPDATE item_classes SET name=?, created_by=? WHERE id=? AND true_id IS NULL');
        $st->execute([$name, $userId, $id]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('الاسم «' . $name . '» موجود بالفعل في ' . IC_KINDS[$row['kind']]);
        }
        throw $e;
    }
    return $name;
}

function ic_toggle(int $id, bool $active, int $userId): bool
{
    $row = ic_load($id);
    if ($row['true_id'] !== null) {
        throw new DomainException('التصنيف ده جاي من True — عرض بس، مش بيتوقف من الموقع');
    }
    $st = db()->prepare('UPDATE item_classes SET is_active=?, created_by=? WHERE id=? AND true_id IS NULL');
    $st->execute([$active ? 1 : 0, $userId, $id]);
    return $active;
}

/** زرع تصنيفات True دفعة واحدة؛ صفوف الموقع تفضل زي ما هي. */
function ic_sync_true_specs(PDO $pdo, array $rows): array
{
    $valid = [];
    $skipped = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) { $skipped++; continue; }
        $spec = (int) ($r['spec'] ?? 0);
        $id = (int) ($r['spec_id'] ?? 0);
        $name = mb_substr(trim((string) ($r['name'] ?? '')), 0, 255);
        if ($spec < 2 || $spec > 5 || $id <= 0 || $name === '') { $skipped++; continue; }
        $valid[$spec . ':' . $id] = [$spec, $id, $name];
    }
    if (!$valid) return ['stored' => 0, 'skipped' => $skipped];
    $params = [];
    foreach ($valid as $row) array_push($params, ...$row);
    $values = implode(',', array_fill(0, count($valid), '(?,?,?)'));
    $st = $pdo->prepare("INSERT INTO hq_spec_names (spec,spec_id,name) VALUES $values
                        ON DUPLICATE KEY UPDATE name=VALUES(name)");
    $st->execute($params);
    $source = "SELECT spec_id,name,ELT(spec-1,'description','type','stage','company') AS kind
                 FROM hq_spec_names WHERE spec BETWEEN 2 AND 5 AND name<>''";
    // المفتاح kind+true_id عليه index بس؛ التحديث والإدراج مجمّعين، من غير استعلام لكل صف.
    // تعارض الاسم مع صف موقع ماينفعش يغيّر صف الموقع أو يربطه بـTrue.
    $pdo->exec("UPDATE IGNORE item_classes c JOIN ($source) s ON c.kind=s.kind AND c.true_id=s.spec_id
                   SET c.name=s.name, c.is_active=1");
    $pdo->exec("INSERT IGNORE INTO item_classes (kind,name,true_id,is_active)
               SELECT s.kind,s.name,s.spec_id,1 FROM ($source) s
                WHERE NOT EXISTS (SELECT 1 FROM item_classes c WHERE c.kind=s.kind AND c.true_id=s.spec_id)");
    return ['stored' => count($valid), 'skipped' => $skipped];
}
