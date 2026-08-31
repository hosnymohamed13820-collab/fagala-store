<?php
/**
 * test_render.php — رندر analysis.php بدوال حقيقية وفحص الـHTML الناتج.
 * بيمسك: خاصية onclick مقطوعة · عنصر ناقص · id مكرر · قوس ناقص.
 */

$root = sys_get_temp_dir() . '/anrender';
@mkdir($root . '/includes', 0775, true);
@mkdir($root . '/uploads/analysis', 0775, true);

// ===== تجهيز نسخة قابلة للتشغيل =====
copy(__DIR__ . '/stub_auth.php', $root . '/auth.php');

$lib = file_get_contents(__DIR__ . '/../../site/analysis_lib.php');
$lib = str_replace("require_once __DIR__ . '/auth.php';", "require_once __DIR__ . '/auth.php';", $lib);
$lib = str_replace('INSERT INTO shortage_lines', 'INSERT OR REPLACE INTO shortage_lines', $lib);
$lib = str_replace('INSERT INTO settings',       'INSERT OR REPLACE INTO settings',       $lib);
$lib = str_replace(
    "ON DUPLICATE KEY UPDATE
                        item_name = VALUES(item_name), balance = VALUES(balance),
                        in_qty = VALUES(in_qty), in_cost = VALUES(in_cost),
                        in_sale = VALUES(in_sale), out_qty = VALUES(out_qty)", '', $lib);
$lib = str_replace("ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", '', $lib);
$lib = str_replace(
    'UPDATE shortage_batches
            SET rows_count',
    'UPDATE shortage_batches
            SET rows_count', $lib);
file_put_contents($root . '/analysis_lib.php', $lib);

copy(__DIR__ . '/../../site/analysis.php', $root . '/analysis.php');

file_put_contents($root . '/includes/header.php',
    "<!DOCTYPE html><html lang=\"ar\" dir=\"rtl\"><head><meta charset=\"UTF-8\">"
    . "<title><?= e(\$page_title ?? '') ?></title></head><body><div class=\"content\">\n");
file_put_contents($root . '/includes/footer.php', "</div>\n<script src=\"assets/app.js\"></script></body></html>\n");

// ===== داتا تجريبية =====
function seed(): int
{
    $p = db();
    $p->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $p->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, name TEXT, sto_id INTEGER)');
    $p->exec("INSERT INTO branches (id,name,sto_id) VALUES (1,'الفجالة',2),(2,'دجلة',3),(3,'بونجور',5),(4,'ABC',6)");
    $p->exec('CREATE TABLE shortage_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT,
        period_from TEXT, period_to TEXT, label TEXT, rows_count INTEGER DEFAULT 0,
        stores_count INTEGER DEFAULT 0, note TEXT, created_by INTEGER, created_at TEXT)');
    $p->exec('CREATE TABLE shortage_lines (batch_id INTEGER, store_id INTEGER, barcode TEXT,
        item_name TEXT, balance REAL DEFAULT 0, in_qty REAL DEFAULT 0, in_cost REAL DEFAULT 0,
        in_sale REAL DEFAULT 0, out_qty REAL DEFAULT 0, PRIMARY KEY (batch_id, store_id, barcode))');
    $p->exec('CREATE TABLE hq_codes (code TEXT PRIMARY KEY, item_id INTEGER, name TEXT, cost REAL)');
    $p->exec('CREATE TABLE hq_item_cats (item_id INTEGER PRIMARY KEY, is1_id INTEGER, item_name TEXT)');
    $p->exec('CREATE TABLE hq_cat_names (is1_id INTEGER PRIMARY KEY, name TEXT)');
    $p->exec("INSERT INTO hq_cat_names VALUES (1000031,'أدوات مكتبية'),(1000045,'العاب أطفال')");

    $p->exec("INSERT INTO shortage_batches (id,source,period_from,period_to,label,rows_count,stores_count)
              VALUES (1,'manual','2025-01-01','2025-12-31','موسم 2025',0,0)");

    // ⚠️ أسامي فيها محارف HTML خطرة — لازم تتهرب صح
    $items = [
        ['6223007312271', 'كشكول 60 ورقة', 101, 1000031, 7.5],
        ['easy',          'قلم <ايزي> & "الجديد"', 102, 1000031, 3.0],
        ['*A100',         "لعبة 'أطفال'", 103, 1000045, 40.0],
    ];
    $ins = $p->prepare('INSERT INTO hq_codes VALUES (?,?,?,?)');
    $cat = $p->prepare('INSERT INTO hq_item_cats VALUES (?,?,?)');
    foreach ($items as [$c, $n, $iid, $cid, $cost]) {
        $ins->execute([$c, $iid, $n, $cost]);
        $cat->execute([$iid, $cid, $n]);
    }

    $l = $p->prepare('INSERT INTO shortage_lines VALUES (?,?,?,?,?,?,?,?,?)');
    // مبيعات/أرصدة مختلفة عشان نضمن ظهور كل الحالات
    $data = [
        // store, code,   name, bal, in_qty, in_cost, in_sale, out_qty
        [2, '6223007312271', 'كشكول 60 ورقة', 10, 50, 375, 500, 40],   // محتاج
        [3, '6223007312271', 'كشكول 60 ورقة', 60, 50, 375, 500, 10],   // فايض
        [5, '6223007312271', 'كشكول 60 ورقة',  5,  0,   0,   0,  5],
        [6, '6223007312271', 'كشكول 60 ورقة',  5,  0,   0,   0,  5],
        [2, 'easy', 'قلم <ايزي> & "الجديد"', 100, 0, 0, 0, 1],         // راكد
        [3, 'easy', 'قلم <ايزي> & "الجديد"', 100, 0, 0, 0, 1],
        [5, 'easy', 'قلم <ايزي> & "الجديد"', 100, 0, 0, 0, 1],
        [6, 'easy', 'قلم <ايزي> & "الجديد"', 100, 0, 0, 0, 1],
        [2, '*A100', "لعبة 'أطفال'", 0, 0, 0, 0, 0],                   // يرجى النظر
        [3, '*A100', "لعبة 'أطفال'", 0, 0, 0, 0, 0],
        [5, '*A100', "لعبة 'أطفال'", 0, 0, 0, 0, 0],
        [6, '*A100', "لعبة 'أطفال'", 0, 0, 0, 0, 0],
        [1, '6223007312271', 'كشكول 60 ورقة', 0, 200, 1500, 2000, 0],  // الإدارة
    ];
    foreach ($data as $d) {
        $l->execute([1, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7]]);
    }
    an_batch_recount(1);
    return 1;
}

// ===== تشغيل صفحة =====
function render(array $get): string
{
    global $root;
    // 🔴 مطب ويندوز: escapeshellarg بتحوّل علامات التنصيص اللي جوه النص لمسافات،
    //    فالكود اللي كان بيتبعت بـ-r كان بيوصل مكسور: getenv("AN_GET") بقت
    //    getenv( AN_GET ) يعني ثابت مش موجود، والصفحة ما بترندرش خالص.
    //    الحل: البوت سترابر في ملف (_run.php) بدل ما يتبعت في سطر الأوامر.
    // ومطب تاني: 'cd C:\...' من درايف D مابيغيّرش الدرايف. proc_open نفسها
    //    بتاخد مسار التشغيل في البارامتر الرابع — أنضف وبيشتغل على الاتنين.
    $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' -d error_reporting=E_ALL -d display_errors=1 '
         . escapeshellarg($root . '/_run.php') . ' 2>&1';
    $env = ['AN_GET' => json_encode($get)];
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $pipes, $root, array_merge($_ENV, $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

// seed.php بيرجع دالة عشان نشغّلها بعد تحميل المكتبة
$seedSrc = "<?php\nreturn function () {\n" .
    substr(
        file_get_contents(__FILE__),
        strpos(file_get_contents(__FILE__), "    \$p = db();"),
        strpos(file_get_contents(__FILE__), "    return 1;\n}") - strpos(file_get_contents(__FILE__), "    \$p = db();")
    ) . "\n};\n";
file_put_contents($root . '/seed.php', $seedSrc);

file_put_contents($root . '/_run.php', <<<'RUN'
<?php
$_GET = json_decode(getenv('AN_GET'), true) ?: [];
$_SERVER['REQUEST_METHOD'] = 'GET';
session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/analysis_lib.php';
$__seed = require __DIR__ . '/seed.php';
$__seed();
include __DIR__ . '/analysis.php';
RUN);

$pass = 0; $fail = 0; $msgs = [];
function chk(string $n, bool $c, string $extra = ''): void
{
    global $pass, $fail, $msgs;
    if ($c) { $pass++; } else { $fail++; $msgs[] = "  ✗ $n" . ($extra ? "\n      $extra" : ''); }
}

foreach ([
    'تاب الرفعات'          => ['tab' => 'batches', 'batch' => '1'],
    'التقرير — كل الأقسام' => ['tab' => 'report',  'batch' => '1', 'cat' => '0', 'st' => '1'],
    'التقرير — قسم واحد'   => ['tab' => 'report',  'batch' => '1', 'cat' => '1000045', 'st' => '1'],
    'التقرير — أرقام بس'   => ['tab' => 'report',  'batch' => '1', 'cat' => '0', 'st' => '0'],
] as $name => $get) {
    $html = render($get);

    chk("$name: مفيش خطأ PHP", !preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated)/', $html),
        substr(trim($html), 0, 300));

    if (!$html) { chk("$name: فيه ناتج", false); continue; }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    $errs = array_filter(libxml_get_errors(), static fn($e) => $e->level >= LIBXML_ERR_ERROR);
    libxml_clear_errors();
    chk("$name: HTML سليم", count($errs) === 0,
        $errs ? trim($errs[0]->message) . ' سطر ' . $errs[0]->line : '');

    // id مكرر
    $ids = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        $id = $el->getAttribute('id');
        if ($id !== '') $ids[] = $id;
    }
    chk("$name: مفيش id مكرر", count($ids) === count(array_unique($ids)),
        implode(', ', array_diff_assoc($ids, array_unique($ids))));

    // كل فورم فيه CSRF (فورمات POST بس)
    foreach ($doc->getElementsByTagName('form') as $f) {
        if (strtolower($f->getAttribute('method')) !== 'post') continue;
        $has = false;
        foreach ($f->getElementsByTagName('input') as $i) {
            if ($i->getAttribute('name') === 'csrf') $has = true;
        }
        chk("$name: فورم POST فيه CSRF", $has, $f->getAttribute('action') ?: '(نفس الصفحة)');
    }
}

// ===== فحص التقرير بالتفصيل =====
$html = render(['tab' => 'report', 'batch' => '1', 'cat' => '0', 'st' => '1']);

chk('التقرير: الحالات الأربعة ظهرت',
    str_contains($html, 'ناقص') && str_contains($html, 'راكد')
    && str_contains($html, 'يرجى النظر') && str_contains($html, 'تمام'));

chk('التقرير: أسامي الفروع في الرأس',
    str_contains($html, 'مبيعات<br>الفجالة') || str_contains($html, 'الفجالة'));

chk('التقرير: القسم من المرآة', str_contains($html, 'أدوات مكتبية'));
chk('التقرير: عمود الإدارة', str_contains($html, 'الإدارة'));

// 🔴 الهروب من HTML — اسم الصنف فيه < > & "
chk('الأسامي الخطرة اتهربت',
    !str_contains($html, '<ايزي>') && str_contains($html, '&lt;ايزي&gt;'),
    'اسم صنف فيه وسم HTML نزل خام — ثغرة XSS');

// التنقيلة ظهرت (دجلة فايض → الفجالة محتاج)
chk('اقتراح التنقيلة ظهر', str_contains($html, 'ق لـ'));

// ===== التصدير =====
$csv = render(['export' => '1', 'batch' => '1', 'cat' => '0']);
chk('التصدير: فيه BOM',    str_starts_with($csv, "\xEF\xBB\xBF"));
chk('التصدير: فيه العناوين', str_contains($csv, 'كود الصنف'));
chk('التصدير: فيه الصفوف',   str_contains($csv, '6223007312271'));
chk('التصدير: مش بيطلع HTML', !str_contains($csv, '<html'));

// ===== تصادم اسم دالة JS مع id =====
preg_match_all('/function\s+([A-Za-z_$][\w$]*)\s*\(/', $html, $fn);
preg_match_all('/\bid="([^"]+)"/', $html, $idm);
$clash = array_intersect($fn[1] ?? [], $idm[1] ?? []);
chk('مفيش اسم دالة JS = id عنصر', count($clash) === 0, implode(', ', $clash));


// =====================================================================
//  🔴 خصائص CSS منطقية جوه جدول فيه خلايا direction:ltr
// =====================================================================
//  الخاصية المنطقية بتتحدد حسب اتجاه الخلية نفسها. خانات الأرقام
//  عندها direction:ltr، فـborder-inline-start بيترسم على الشمال بدل
//  اليمين → حدود فاضية بين أعمدة معيّنة، والعمود الثابت يتثبّت في
//  الناحية الغلط. لازم فيزيائية (right/left).
$css = '';
if (preg_match('/<style>(.*?)<\/style>/s', $html, $m)) $css = $m[1];
$css = preg_replace('!/\*.*?\*/!s', '', $css);   // شيل التعليقات
preg_match_all('/(?:border|inset|margin|padding)-(?:inline|block)[\w-]*\s*:/', $css, $lg);
chk('مفيش خاصية CSS منطقية في الجدول', count($lg[0]) === 0, implode(' · ', $lg[0]));

// اللون بقى `var(--ink-300)` بدل `#000` (٣١ أغسطس ٢٠٢٦): الأسود الصريح
// كان بيختفي على أرضية الوضع الليلي فالجدول يفضل بلا شبكة. الفحص هنا
// بيحرس حاجتين: إن الخاصية **فيزيائية** (`right` مش `inline-start`)،
// وإن اللون **توكن** بيقلب مع الوضع مش قيمة صريحة.
chk('البوردر على يمين كل خلية', str_contains($css, 'border-right:1px solid var(--ink-300)'));
chk('العمود الثابت متثبّت يمين', str_contains($css, 'right:0') && str_contains($css, 'right:118px'));


// =====================================================================
//  متغيّرات CSS — لازم تكون معرّفة فعلاً في fagala.css
// =====================================================================
//  var(--اسم-مش-موجود) مابيرميش خطأ — بيتجاهل الخاصية بصمت.
//  استخدمت --ink-100 وهي مش موجودة، فالشريط طلع بلا لون.
$DEFINED = [
    'accent','accent-hover','accent-ring','accent-soft','bg','font',
    'ink-200','ink-300','ink-400','ink-500','ink-600','ink-700','ink-800','ink-900',
    'line','line-soft','muted','neg','neg-soft','pos','pos-soft',
    'purple','purple-dark','r','r-lg','r-sm','shadow','shadow-lg','shadow-sm',
    'sidebar','sidebar-hover','surface','text','warn','warn-soft',
    // الوضع الليلي (٣١ أغسطس ٢٠٢٦): لون واحد مايقدرش يبقى مقروء ككتابة على
    // خلفية غامقة ويقبل كتابة بيضا فوقه في نفس الوقت. فالدلالية اتقسمت دورين:
    // `--pos`/`--neg`/`--warn` بيقلبوا فاتحين (دور الكتابة)، و`--*-fill`
    // بيفضلوا غامقين (دور الخلفية). و`--accent-text` نفس الحكاية للأزرق.
    'pos-fill','neg-fill','warn-fill','accent-text',
];
preg_match_all('/var\(\s*--([a-z0-9-]+)\s*\)/i', $css, $vm);
$used    = array_unique($vm[1] ?? []);
$unknown = array_values(array_diff($used, $DEFINED));
chk('كل متغيّرات CSS معرّفة في fagala.css', count($unknown) === 0,
    'مش موجودة: ' . implode(' · ', $unknown));

// خلية الأولوية: الشريط في <i> عشان xtable يتجاهله ويقرا الرقم لوحده
chk('شريط الأولوية جوه <i>',
    preg_match('/<td class="pri[^"]*"><b>[\d,]+<\/b><i style="width:[\d.]+%"><\/i><\/td>/u', $html) === 1);
// الشريط لازم يفضل في السياق العادي — مش مطلق جوه خلية جدول
$pcss = '';
if (preg_match('/\.an-tbl td\.pri > i \{[^}]*\}/', $css, $pm)) $pcss = $pm[0];
chk('الشريط مش position:absolute', $pcss !== '' && !str_contains($pcss, 'absolute'), $pcss);
chk('رقم الأولوية جوه <b> مش جوه <i>',
    preg_match('/<i style="width:[^"]*">\s*[\d,]/u', $html) === 0);

echo "\n" . str_repeat('═', 50) . "\n";
if ($fail) { echo "❌ فشل $fail | نجح $pass\n\n" . implode("\n", $msgs) . "\n"; exit(1); }
echo "✅ كل فحوص الرندر نجحت ($pass)\n";

// =====================================================================
//  فحص إضافي — بنية الجدول متوافقة مع xtable.js
// =====================================================================
$html = render(['tab' => 'report', 'batch' => '1', 'cat' => '0', 'st' => '1']);
libxml_use_internal_errors(true);
$d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors();

$tbl = null;
foreach ($d->getElementsByTagName('table') as $t) {
    if (str_contains($t->getAttribute('class'), 'an-tbl')) $tbl = $t;
}
chk('لقينا جدول التقرير', $tbl !== null);

if ($tbl) {
    $thead = $tbl->getElementsByTagName('thead')->item(0);
    $hrows = [];
    foreach ($thead->getElementsByTagName('tr') as $tr) $hrows[] = $tr;
    chk('الرأس صفين', count($hrows) === 2, 'عدد الصفوف: ' . count($hrows));

    $last = $hrows[count($hrows) - 1];
    $lastCells = $last->getElementsByTagName('th');

    // 🔴 شرط xtable.js: الصف الأخير من غير أي colspan
    $spans = 0; $cols = 0;
    foreach ($lastCells as $th) {
        $cs = (int) ($th->getAttribute('colspan') ?: 1);
        if ($cs > 1) $spans++;
        $cols += $cs;
    }
    chk('صف الرأس الأخير من غير دمج (شرط xtable)', $spans === 0, "فيه $spans خلية مدموجة");
    chk('عدد أعمدة الصف الأخير = عدد خلاياه', $cols === $lastCells->length);

    // صف المجموعات لازم يغطي نفس العدد بالظبط
    $gcols = 0;
    foreach ($hrows[0]->getElementsByTagName('th') as $th) {
        $gcols += (int) ($th->getAttribute('colspan') ?: 1);
    }
    chk('صف المجموعات بيغطي نفس عدد الأعمدة', $gcols === $cols, "مجموعات=$gcols تفاصيل=$cols");

    // كل صف داتا لازم يبقى نفس عدد الخلايا
    $tbody = $tbl->getElementsByTagName('tbody')->item(0);
    $bad = 0; $n = 0;
    foreach ($tbody->getElementsByTagName('tr') as $tr) {
        $n++;
        if ($tr->getElementsByTagName('td')->length !== $cols) $bad++;
    }
    chk('كل صفوف الداتا نفس عدد الأعمدة', $bad === 0, "$bad صف من $n مختلفين (المفروض $cols)");
    chk('فيه صفوف داتا', $n > 0);
}

// السالب لازم يطلع في خلية اتجاهها لاتيني وإلا يبان «16-»
chk('السالب في خلية num', preg_match('/class="num[^"]*neg[^"]*"[^>]*>\s*-\d/u', $html) === 1);

// عمودا الكود والاسم ثابتين
chk('العمودين الثابتين موجودين',
    substr_count($html, 'class="c-code"') > 1 && substr_count($html, 'class="c-name"') > 1);

// .btn معرّف محلياً (النسيان ده هو اللي خرّب الشكل أول مرة)
chk('.btn معرّف في الصفحة', str_contains($html, '.btn { background:var(--accent)'));

echo "\n" . str_repeat('═', 50) . "\n";
if ($fail) { echo "❌ فشل $fail | نجح $pass\n\n" . implode("\n", $msgs) . "\n"; exit(1); }
echo "✅ كل فحوص الرندر نجحت ($pass)\n";
