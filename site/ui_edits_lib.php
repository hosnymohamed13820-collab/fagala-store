<?php
declare(strict_types=1);

// الأنواع دي من المكتبة نفسها؛ بنراجع وجودها في الـCSS قبل عرضها أو قبولها.
function ui_edits_variants(): array
{
    static $families;
    if ($families !== null) return $families;
    $css = file_get_contents(__DIR__ . '/assets/fagala.css');
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $families = [
        'btn' => ['btn-primary', 'btn-ghost', 'btn-secondary', 'btn-cancel', 'btn-danger', 'btn-red', 'btn-reject', 'btn-success', 'btn-green', 'btn-approve', 'btn-outline', 'btn-warn', 'btn-yellow', 'btn-sm'],
        'finput' => ['finput-sm', 'finput-cell'],
        'fselect' => [], 'ffield' => [], 'fcard' => [], 'ftable' => [],
        'fchip' => ['fchip-pos', 'fchip-neg', 'fchip-warn', 'fchip-mute', 'fchip-accent', 'fchip-green', 'fchip-red', 'fchip-yellow', 'fchip-blue'],
        'ftabs' => [], 'falert' => ['falert-info', 'falert-warn', 'falert-neg', 'falert-pos'],
        'fkpis' => [], 'ffilters' => [], 'fpage-head' => [],
    ];
    foreach ($families as &$variants) {
        $variants = array_values(array_filter($variants, fn($v) => preg_match('/\.' . preg_quote($v, '/') . '(?![a-z0-9_-])/', $css)));
    }
    unset($variants);
    return $families;
}

function ui_edits_page(string $page): string
{
    // مسار محلي بس، من غير بروتوكول أو قفز لمجلد أب، مع الاحتفاظ بفلاتر الصفحة.
    if (strlen($page) > 1500 || preg_match('/[\x00-\x20\\\\#]/', $page)) throw new InvalidArgumentException('رابط الصفحة مش صحيح.');
    $url = parse_url($page);
    if (!$url || isset($url['scheme']) || isset($url['host']) || str_starts_with($page, '//')) throw new InvalidArgumentException('رابط الصفحة لازم يبقى محلي.');
    $path = $url['path'] ?? '';
    if (!preg_match('~^/?[a-zA-Z0-9_/-]+\.php$~', $path) || str_contains($path, '..')) throw new InvalidArgumentException('مسار الصفحة مش صحيح.');
    parse_str($url['query'] ?? '', $query);
    unset($query['ui_edit'], $query['req']);
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function ui_edits_validate(array $input): array
{
    $page = ui_edits_page((string) ($input['page'] ?? ''));
    $title = trim((string) ($input['title'] ?? ''));
    $edits = $input['edits'] ?? null;
    $viewport = filter_var($input['viewport_w'] ?? null, FILTER_VALIDATE_INT);
    if ($title === '' || mb_strlen($title) > 180 || !$viewport || $viewport < 200 || $viewport > 20000) throw new InvalidArgumentException('اكتب عنوان الطلب وراجع عرض الشاشة.');
    if (!is_array($edits) || !array_is_list($edits) || count($edits) < 1 || count($edits) > 300) throw new InvalidArgumentException('اختار من تعديل واحد لـ٣٠٠ تعديل.');
    $families = ui_edits_variants();
    foreach ($edits as $edit) {
        if (!is_array($edit) || !isset($families[$edit['family'] ?? ''])) throw new InvalidArgumentException('عيلة المكوّن مش موجودة في المكتبة.');
        foreach (['selector' => 1000, 'label' => 40, 'note' => 2000] as $key => $max) {
            if (!is_string($edit[$key] ?? null) || mb_strlen($edit[$key]) > $max) throw new InvalidArgumentException('وصف التعديل أطول من المسموح.');
        }
        if ($edit['selector'] === '') throw new InvalidArgumentException('مفيش عنوان ثابت للعنصر.');
        foreach (['from', 'to'] as $side) {
            $state = $edit[$side] ?? null;
            if (!is_array($state) || !is_array($state['classes'] ?? null) || !array_is_list($state['classes']) || count($state['classes']) > 60 || !in_array($edit['family'], $state['classes'], true)) throw new InvalidArgumentException('بيانات المكوّن ناقصة.');
            foreach ($state['classes'] as $class) {
                if (!is_string($class) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{0,99}$/', $class)) throw new InvalidArgumentException('كلاس المكوّن مش صحيح.');
            }
            foreach (['width', 'height'] as $key) {
                if (!is_numeric($state[$key] ?? null) || !is_finite((float) $state[$key]) || $state[$key] < 1 || $state[$key] > 20000) throw new InvalidArgumentException('المقاس مش صحيح.');
            }
        }
        // اللي اتضاف أو اتشال لازم يبقى نوع من نفس العيلة أو الإضاءة بس.
        $changed = array_merge(array_diff($edit['to']['classes'], $edit['from']['classes']), array_diff($edit['from']['classes'], $edit['to']['classes']));
        if (array_diff($changed, array_merge($families[$edit['family']], ['fhl']))) throw new InvalidArgumentException('التغيير لازم يبقى من أنواع نفس العيلة.');
        foreach (['x', 'y'] as $key) {
            if (!is_numeric($edit['to'][$key] ?? null) || !is_finite((float) $edit['to'][$key]) || abs((float) $edit['to'][$key]) > 20000) throw new InvalidArgumentException('الإزاحة مش صحيحة.');
        }
        foreach (['fhl', 'hidden'] as $key) {
            if (!is_bool($edit['to'][$key] ?? null)) throw new InvalidArgumentException('حالة العنصر مش صحيحة.');
        }
    }
    if (strlen(json_encode($edits, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 800000) throw new InvalidArgumentException('الطلب كبير أوي، قسّمه على طلبين.');
    return ['page' => $page, 'title' => $title, 'edits' => $edits, 'viewport_w' => $viewport];
}

function ui_edits_save(array $input, int $userId): int
{
    $data = ui_edits_validate($input);
    $st = db()->prepare('INSERT INTO ui_edits (page,title,edits,viewport_w,created_by) VALUES (?,?,?,?,?)');
    $st->execute([$data['page'], $data['title'], json_encode($data['edits'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $data['viewport_w'], $userId]);
    return (int) db()->lastInsertId();
}

function ui_edits_list(int $page = 1): array
{
    $offset = (max(1, min(100000, $page)) - 1) * 50;
    $st = db()->prepare('SELECT id,page,title,status,JSON_LENGTH(edits) AS edit_count,viewport_w,created_by,created_at FROM ui_edits ORDER BY id DESC LIMIT 51 OFFSET ?');
    $st->bindValue(1, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['rows' => array_slice($rows, 0, 50), 'has_more' => count($rows) > 50];
}

function ui_edits_get(int $id): ?array
{
    $st = db()->prepare('SELECT id,page,title,status,edits,viewport_w,created_by,created_at FROM ui_edits WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['edits'] = json_decode($row['edits'], true, 512, JSON_THROW_ON_ERROR);
    return $row;
}

function ui_edits_set_status(int $id, string $status): bool
{
    if (!in_array($status, ['open', 'briefed', 'done'], true)) throw new InvalidArgumentException('الحالة مش صحيحة.');
    $st = db()->prepare('UPDATE ui_edits SET status=? WHERE id=?');
    $st->execute([$status, $id]);
    return ui_edits_get($id) !== null;
}
