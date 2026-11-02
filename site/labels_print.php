<?php
/**
 * labels_print.php — صفحة الطباعة نفسها (بريف ٢١٦)
 * نظام الفجالة ستور المالي
 *
 * بتاخد قايمة الأكواد وعدد الليبلز وبتطلع صفحة أبيض وأسود فيها ليبل لكل
 * نسخة بمقاس الإعدادات بالمليمتر، و@page بيقوله للمتصفح مقاس الورق
 * بالظبط (مش بكسل) عشان الطابعة الحرارية تطبع من غير تصغير.
 *
 * الطلب: POST rows=JSON  أو  GET ids=JSON   ← [{"code":"…","copies":2}, …]
 *
 * 🔴 السعر بيتقرا من hq_codes.price هنا كمان (مش من المتصفح).
 * 🔴 الصفحة standalone — مش فيها header/footer ولا ثيم ليلي: ورق أبيض.
 */
require_once __DIR__ . '/auth.php';
require_permission('labels');
require_once __DIR__ . '/labels_lib.php';

$lb = labels_settings();

$raw = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? (string) ($_POST['rows'] ?? '')
    : (string) ($_GET['ids'] ?? '');
[$req, $total] = labels_parse_rows($raw);

$error = '';
if (!$req) {
    $error = 'مفيش أصناف مختارة للطباعة.';
} elseif ($total > LABELS_MAX) {
    $error = 'الحد الأقصى ' . LABELS_MAX . ' ليبل في الطباعة الواحدة — انت طلبت ' . $total . ' ليبل.';
}

$map     = ($req && $error === '') ? labels_codes_map(array_column($req, 'code')) : [];
$missing = $map ? array_values(array_diff(array_column($req, 'code'), array_keys($map))) : [];

// الليبلز الفعلية: نسخة لكل ليبل، والأكواد اللي مالهاش ترميز ASCII بتتخطى
$labels = [];
$skipped = 0;
// لو فيه خطأ (مفيش أصناف أو أكتر من الحد) مش بنبني ليبلز أصلاً
foreach ($error === '' ? $req : [] as $r) {
    $it = $map[$r['code']] ?? null;
    if (!$it) {
        continue;
    }
    if (!$it['printable']) {
        $skipped++;
        continue;
    }
    for ($i = 0; $i < $r['copies']; $i++) {
        $labels[] = $it;
    }
}
if ($error === '' && !$labels) {
    $error = 'الأصناف اللي في القايمة مش هتتطبع كباركود (أكواد فيها حروف مش ASCII).';
}

$w     = $lb['w_mm'];
$h     = $lb['h_mm'];
$cols  = $lb['cols'];
$gap   = $lb['gap_mm'];
$sheetW = round($w * $cols + $gap * ($cols - 1), 2);

/**
 * ليبل واحد. كل المقاسات بالمليمتر وبتتحسب من ارتفاع الليبل عشان لو
 * محمد غيّر المقاس الليبل يفضل مرتّب من غير تعديل كود.
 */
function labels_label_html(array $it, array $lb): string
{
    $w = (float) $lb['w_mm'];
    $h = (float) $lb['h_mm'];
    $pad = round(max(0.8, $h * 0.04), 2);

    $fsShop  = round(max(1.7, $h * 0.088), 2);
    $fsName  = round(max(1.9, $h * 0.100), 2);
    $fsPrice = round(max(2.6, $h * 0.184), 2);
    $fsCode  = round(max(1.7, $h * 0.088), 2);
    $barH    = round(min(9.0, max(4.5, $h * 0.24)), 2);

    $avail = round($w - 2 * $pad, 2);
    $mod   = barcode_fit_module($it['code'], $avail);
    $svg   = barcode_svg($it['code'], $barH, $mod);

    $html  = '<div class="lbl" style="width:' . $w . 'mm;height:' . $h . 'mm;padding:' . $pad . 'mm;">';
    $html .= '<div class="shop" style="font-size:' . $fsShop . 'mm;">' . e($lb['shop_name']) . '</div>';
    $html .= '<div class="nm" style="font-size:' . $fsName . 'mm;">' . e($it['name']) . '</div>';
    if ($lb['show_price']) {
        $html .= '<div class="pr" style="font-size:' . $fsPrice . 'mm;">' . labels_price((float) $it['price']) . '</div>';
    }
    $html .= '<div class="bc">' . $svg . '</div>';
    $html .= '<div class="cd" style="font-size:' . $fsCode . 'mm;">' . e($it['code']) . '</div>';
    return $html . '</div>';
}

if ($error !== '') {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>تعذّرت الطباعة</title>'
        . '<link rel="stylesheet" href="assets/fagala.css?v=39">'
        . '<style>body{font-family:inherit;background:var(--surface);color:var(--text);'
        . 'text-align:center;padding:60px 18px;margin:0;}'
        . 'h2{font-size:20px;margin:0 0 10px;}p{font-size:15px;margin:0 0 22px;}'
        . 'a{display:inline-block;border:1.5px solid var(--line);border-radius:8px;padding:9px 20px;'
        . 'color:var(--text);text-decoration:none;font-weight:600;}</style></head><body>'
        . '<h2>تعذّرت الطباعة</h2><p>' . e($error) . '</p>'
        . '<a href="labels.php">رجوع لليبلز</a></body></html>';
    exit;
}

$chunks = array_chunk($labels, $cols);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ليبلز — <?= count($labels) ?> ليبل</title>
<link rel="stylesheet" href="assets/fagala.css?v=39">
<style>
    /* 🔴 المقاس بالمليمتر: ده اللي بيخلي الطابعة الحرارية تطبع بمقاسها الحقيقي */
    @page { size: <?= $sheetW ?>mm <?= $h ?>mm; margin: 0; }

    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: var(--surface); color: var(--text); }
    body { font-family: inherit; }

    .bar {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        flex-wrap: wrap; padding: 10px 14px; border-bottom: 2px solid var(--text);
        background: var(--surface); position: sticky; top: 0; z-index: 10;
    }
    .bar .ttl { font-size: 15px; font-weight: 700; }
    .bar .sub { font-size: 12px; }
    .bar .acts { display: flex; gap: 8px; align-items: center; }
    .bar button, .bar a {
        font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
        border: 1.5px solid var(--text); border-radius: 8px; padding: 8px 18px;
        background: var(--fill-strong); color: var(--fill-strong-fg); text-decoration: none;
    }
    .bar a { background: var(--surface); color: var(--text); }
    .bar .warn { font-size: 12px; font-weight: 700; }

    .sheet { padding: 10px 0 30px; }
    .row { display: flex; justify-content: center; gap: <?= $gap ?>mm; margin: 0 auto 4mm; }

    .lbl {
        display: flex; flex-direction: column; align-items: center; justify-content: space-between;
        overflow: hidden; background: var(--surface); color: var(--text); text-align: center;
        font-weight: 700; line-height: 1.05;
    }
    .lbl .shop { white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                 max-width: 100%; font-weight: 600; }
    .lbl .nm {
        max-width: 100%; overflow: hidden; font-weight: 700;
        display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
        line-clamp: 2; word-break: break-word; line-height: 1.12;
    }
    .lbl .pr { direction: ltr; unicode-bidi: embed; font-variant-numeric: tabular-nums;
               font-weight: 800; line-height: 1.0; }
    .lbl .bc { max-width: 100%; line-height: 0; }
    .lbl .bc svg { max-width: 100%; height: auto; }
    .lbl .cd { direction: ltr; unicode-bidi: embed; font-variant-numeric: tabular-nums;
               font-weight: 600; max-width: 100%; overflow: hidden; white-space: nowrap; }

    @media screen {
        body { background: var(--surface); }
        .lbl { outline: 1px dashed var(--line); }
        .sheet { max-width: 100%; overflow-x: auto; }
    }

    @media print {
        .bar { display: none; }
        .sheet { padding: 0; }
        .row { margin: 0; break-after: page; page-break-after: always; }
        .row:last-child { break-after: auto; page-break-after: auto; }
        .lbl { outline: none; }
    }
</style>
</head>
<body>

<div class="bar">
    <span>
        <span class="ttl"><?= count($labels) ?> ليبل</span>
        <span class="sub"> — مقاس <?= $w ?>×<?= $h ?> مم<?= $cols > 1 ? ' · ' . $cols . ' أعمدة' : '' ?></span>
        <?php if ($skipped): ?>
            <br><span class="warn"><?= $skipped ?> كود اتجاهل لأن فيه حروف مش ASCII</span>
        <?php endif; ?>
        <?php if ($missing): ?>
            <br><span class="warn"><?= count($missing) ?> كود مش موجود في المرآة: <?= e(implode(' · ', array_slice($missing, 0, 5))) ?></span>
        <?php endif; ?>
    </span>
    <span class="acts">
        <a href="labels.php">رجوع</a>
        <button type="button" onclick="window.print()">اطبع</button>
    </span>
</div>

<div class="sheet">
    <?php foreach ($chunks as $chunk): ?>
        <div class="row">
            <?php foreach ($chunk as $it): ?>
                <?= labels_label_html($it, $lb) ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
