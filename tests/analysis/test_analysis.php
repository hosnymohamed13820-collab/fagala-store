<?php
/**
 * test_analysis.php — اختبارات منطق التحليل
 * التشغيل: php tests/test_analysis.php
 */

require_once __DIR__ . '/stub_auth.php';

// بنعمل نسخة من المكتبة من غير سطر require_once auth.php
$lib = file_get_contents(__DIR__ . '/../../site/analysis_lib.php');
$lib = str_replace("require_once __DIR__ . '/auth.php';", '', $lib);

// SQLite مابيعرفش ON DUPLICATE KEY UPDATE — بنترجمها للاختبار بس.
// (بنمسح المخزن قبل الإدخال أصلاً، فـREPLACE ليها نفس المعنى)
$lib = str_replace('INSERT INTO shortage_lines', 'INSERT OR REPLACE INTO shortage_lines', $lib);
$lib = str_replace('INSERT INTO settings',       'INSERT OR REPLACE INTO settings',       $lib);
$lib = str_replace(
    "ON DUPLICATE KEY UPDATE
                        item_name = VALUES(item_name), balance = VALUES(balance),
                        in_qty = VALUES(in_qty), in_cost = VALUES(in_cost),
                        in_sale = VALUES(in_sale), out_qty = VALUES(out_qty)", '', $lib);
$lib = str_replace(
    "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", '', $lib);

eval('?>' . $lib);

$pass = 0; $fail = 0; $fails = [];

function ok(string $name, $got, $want): void
{
    global $pass, $fail, $fails;
    $g = is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got;
    $w = is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want;
    if ($g === $w) { $pass++; }
    else { $fail++; $fails[] = "  ✗ $name\n      طلع: $g\n      المفروض: $w"; }
}

function head(string $t): void { echo "\n── $t ──\n"; }

// =====================================================================
head('١) تطبيع عناوين الأعمدة');

ok('توحيد ى/ي و ة/ه والمسافات',
    an_norm_ar('صافى مشتريات الكميه'), an_norm_ar('صافي مشتريات الكمية'));
ok('«ال» التعريف بتفرق (مقصود)',
    an_norm_ar('مشتريات') === an_norm_ar('المشتريات') ? 'same' : 'diff', 'diff');
ok('الشرطة والمسافة سواء',
    an_norm_ar('صافى مشتريات سعر-شراء'), an_norm_ar('صافى مشتريات سعر شراء'));
ok('التشكيل مابيفرقش', an_norm_ar('الرَّصيد'), an_norm_ar('الرصيد'));
ok('مبيعات ≠ مشتريات',
    an_norm_ar('صافى مبيعات الكميه') === an_norm_ar('صافى مشتريات الكميه') ? 'same' : 'diff', 'diff');

// =====================================================================
head('٢) قراءة الأرقام');

ok('عادي',            an_num('123'),        123.0);
ok('فاصلة آلاف',      an_num('1,234.50'),   1234.5);
ok('سالب قدام',       an_num('-15'),        -15.0);
ok('سالب ورا',        an_num('15-'),        -15.0);
ok('سالب بين قوسين',  an_num('(15)'),       -15.0);
ok('أرقام عربية',     an_num('٤٥٦'),        456.0);
ok('فاصلة عشرية عربية', an_num('١٢٫٥'),     12.5);
ok('فاضي',            an_num(''),           0.0);
ok('شرطة لوحدها',     an_num('-'),          0.0);
ok('مسافة غير منقسمة', an_num("1\xC2\xA0200"), 1200.0);

// =====================================================================
head('٣) الترميز — بونجور cp1256 والباقي utf-8');

$hdr  = "كود الصنف\tاسم الصنف\tالرصيد\tصافى مبيعات الكميه\tصافى مشتريات الكميه\tصافى مشتريات سعر-شراء\n";
$body = "6223007312271\tكشكول ٦٠ ورقة\t20\t35\t50\t1250\n"
      . "easy\tقلم ايزي\t5\t18\t20\t140\n"
      . "*A100\tلعبة أطفال\t0\t0\t0\t0\n";
$utf8 = $hdr . $body;

$dir = sys_get_temp_dir() . '/antest';
@mkdir($dir);
file_put_contents("$dir/utf8.txt",  $utf8);
file_put_contents("$dir/bom.txt',", "\xEF\xBB\xBF" . $utf8);
file_put_contents("$dir/bom.txt",   "\xEF\xBB\xBF" . $utf8);
file_put_contents("$dir/cp1256.txt", iconv('UTF-8', 'CP1256//IGNORE', $utf8));
file_put_contents("$dir/utf16.txt",  "\xFF\xFE" . mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'));

foreach (['utf8' => 'UTF-8', 'bom' => 'UTF-8 (BOM)', 'cp1256' => 'CP1256', 'utf16' => 'UTF-16LE'] as $f => $want) {
    $r = an_read_file("$dir/$f.txt");
    ok("كشف الترميز: $f", $r['encoding'], $want);
    // ⚠️ CP1256 مافيهاش أرقام عربية-هندية، فبنتأكد من الحروف
    $want = ($f === 'cp1256') ? 'كشكول' : 'كشكول ٦٠ ورقة';
    ok("العربي سليم في $f", str_contains($r['text'], $want) ? 'ok' : 'مكسور', 'ok');
}

// =====================================================================
head('٤) تقسيم الصفوف وكشف الفاصل');

$s = an_split($utf8);
ok('الفاصل TAB',       $s['delim'], 'TAB');
ok('عدد العناوين',     count($s['headers']), 6);
ok('عدد الصفوف',       count($s['rows']), 3);
ok('أول كود',          $s['rows'][0][0], '6223007312271');

$csv = str_replace("\t", ',', $utf8);
ok('كشف الفاصلة',      an_split($csv)['delim'], ',');

$sp = "كود الصنف   اسم الصنف   الرصيد\n123   صنف   5\n";
ok('كشف المسافات',     an_split($sp)['delim'], 'مسافات');

// =====================================================================
head('٥) الربط التلقائي للأعمدة');

$map = an_auto_map($s['headers']);
ok('barcode',   $map['barcode']   ?? -1, 0);
ok('item_name', $map['item_name'] ?? -1, 1);
ok('balance',   $map['balance']   ?? -1, 2);
ok('out_qty',   $map['out_qty']   ?? -1, 3);
ok('in_qty',    $map['in_qty']    ?? -1, 4);
ok('in_cost',   $map['in_cost']   ?? -1, 5);

// 🔴 أخطر حالة: «المشتريات» لازم ماتخطفش عمود «صافى مشتريات الكميه»
$tricky = ['الكود', 'الاسم', 'المشتريات الكميه', 'صافى مشتريات الكميه',
           'المبيعات الكميه', 'صافى مبيعات الكميه', 'الرصيد'];
$m2 = an_auto_map($tricky);
ok('الصافي بياخد الأسبقية (شراء)', $m2['in_qty']  ?? -1, 3);
ok('الصافي بياخد الأسبقية (بيع)',  $m2['out_qty'] ?? -1, 5);
ok('الرصيد',                       $m2['balance'] ?? -1, 6);

// عمود واحد مايتاخدش مرتين
$dup = array_unique(array_values($m2));
ok('مفيش عمود متكرر', count($dup), count($m2));

// عناوين مش مفهومة
$bad = an_auto_map(['col1', 'col2', 'col3']);
ok('عناوين غريبة → مفيش ربط', count($bad), 0);

// =====================================================================
head('٦) التصنيف — ترجمة الماكرو');

$N = ['الفجالة', 'دجلة', 'بونجور', 'ABC'];
$c = static fn($mat, $mab, $ras) => an_classify($mat, $mab, $ras, $N, 12.0);

$r = $c([5, 3, 2, 1], [10, 10, 10, 10], [5, 7, 8, 9]);
ok('كله محتاج → ناقص', $r['status'], 'ناقص');

$r = $c([-5, -3, -2, -1], [10, 10, 10, 10], [15, 13, 12, 11]);
ok('كله فايض → راكد على مستوى الفروع', $r['status'], 'راكد على مستوى الفروع');

$r = $c([0, 0, 0, 0], [10, 10, 10, 10], [10, 10, 10, 10]);
ok('كله صفر والصنف موجود → تمام', $r['status'], 'تمام');

$r = $c([0, 0, 0, 0], [10, 10, 0, 10], [10, 10, 0, 10]);
ok('كله صفر وفرع مادخلوش → يرجى النظر', $r['status'], 'يرجى النظر في الأمر');

// مختلط: الفجالة محتاج ١٠، دجلة فايض ١٠ → تنقيلة كاملة
$r = $c([10, -10, 0, 0], [20, 5, 3, 3], [10, 15, 3, 3]);
ok('مختلط متعادل → تمام',   $r['status'], 'تمام');
ok('التنقيلة من دجلة',      $r['transfers'][1], '10ق لـالفجالة');
ok('الفجالة مابيحوّلش',      $r['transfers'][0], '');

// فايض أكبر من المحتاج والباقي ≥ الدستة → راكد
$r = $c([5, -30, 0, 0], [20, 5, 3, 3], [15, 35, 3, 3]);
ok('فايض كبير → راكد',       $r['status'], 'راكد');
ok('حوّل ٥ للفجالة',          $r['transfers'][1], '5ق لـالفجالة');

// محتاج أكبر من الفايض والباقي ≥ الدستة → ناقص
$r = $c([40, -5, 0, 0], [50, 5, 3, 3], [10, 10, 3, 3]);
ok('محتاج كبير → ناقص',      $r['status'], 'ناقص');

// الباقي أقل من الدستة → تمام
$r = $c([3, -1, 0, 0], [10, 5, 3, 3], [7, 6, 3, 3]);
ok('الباقي أقل من دستة → تمام', $r['status'], 'تمام');

// فرع مادخلوش الصنف وسط حالة مختلطة → بياخد دستة تجربة
$r = $c([-20, 5, 0, 0], [5, 10, 0, 3], [25, 5, 0, 3]);
ok('الفرع الفاضي بياخد دستة',
    str_contains($r['transfers'][0], 'بونجور') ? 'ok' : 'مافيش', 'ok');

// الرقم في نص التنقيلة من غير أصفار زايدة
ok('صياغة الكمية', an_fmt_qty(12.0),   '12');
ok('كسر',          an_fmt_qty(2.5),    '2.5');
ok('صفر',          an_fmt_qty(0.0),    '0');

// مفيش فروع خالص — مايكسرش
$r = an_classify([], [], [], [], 12.0);
ok('مفيش فروع → مايرميش استثناء', $r['status'], '');

// =====================================================================
head('٧) المطلوب');

ok('بعت ١٠ وعنده ٣ → محتاج ٧', an_required(10, 3), 7.0);
ok('بعت ٢ وعنده ٢٠ → −١٨',      an_required(2, 20), -18.0);

// =====================================================================
head('٨) الاستيراد على قاعدة حقيقية');

$pdo = db();
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, name TEXT, sto_id INTEGER)');
$pdo->exec("INSERT INTO branches (id,name,sto_id) VALUES (1,'الفجالة',2),(2,'دجلة',3),(3,'بونجور',5),(4,'ABC',6)");
$pdo->exec('CREATE TABLE shortage_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT DEFAULT \'manual\',
    period_from TEXT, period_to TEXT, label TEXT, rows_count INTEGER DEFAULT 0,
    stores_count INTEGER DEFAULT 0, note TEXT, created_by INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE shortage_lines (
    batch_id INTEGER, store_id INTEGER, barcode TEXT, item_name TEXT,
    balance REAL DEFAULT 0, in_qty REAL DEFAULT 0, in_cost REAL DEFAULT 0,
    in_sale REAL DEFAULT 0, out_qty REAL DEFAULT 0,
    PRIMARY KEY (batch_id, store_id, barcode))');
$pdo->exec('CREATE TABLE hq_codes (code TEXT PRIMARY KEY, item_id INTEGER, name TEXT, cost REAL)');
$pdo->exec('CREATE TABLE hq_item_cats (item_id INTEGER PRIMARY KEY, is1_id INTEGER, item_name TEXT)');
$pdo->exec('CREATE TABLE hq_cat_names (is1_id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO hq_codes VALUES ('6223007312271',101,'كشكول 60 ورقة',7.5),
                                        ('easy',102,'قلم ايزي',3.0),
                                        ('*A100',103,'لعبة اطفال',40.0)");
$pdo->exec('INSERT INTO hq_item_cats VALUES (101,1000031,\'كشكول\'),(102,1000031,\'قلم\'),(103,1000045,\'لعبة\')');
$pdo->exec("INSERT INTO hq_cat_names VALUES (1000031,'أدوات مكتبية'),(1000045,'العاب أطفال')");

$bid = an_batch_create('موسم 2025', '2025-01-01', '2025-12-31', 1);
ok('الرفعة اتعملت', $bid > 0 ? 'ok' : 'فشل', 'ok');

$res = an_import($bid, 2, $s['rows'], $map);
ok('اتخزن ٣ صفوف', $res['stored'], 3);
ok('مفيش متجاهل',  $res['skipped'], 0);

// ⚠️ الكود بحروف أو بنجمة لازم يعدّي (الفلتر القديم كان بيرفض ٩٤٤ كود حقيقي)
$codes = $pdo->query('SELECT barcode FROM shortage_lines ORDER BY barcode')->fetchAll(PDO::FETCH_COLUMN);
ok('كود بنجمة عدّى',  in_array('*A100', $codes, true) ? 'ok' : 'اترفض', 'ok');
ok('كود بحروف عدّى',  in_array('easy', $codes, true) ? 'ok' : 'اترفض', 'ok');

// إعادة الرفع بتستبدل مش بتتراكم
$res2 = an_import($bid, 2, $s['rows'], $map);
$n = (int) $pdo->query('SELECT COUNT(*) FROM shortage_lines')->fetchColumn();
ok('إعادة الرفع بتستبدل', $n, 3);

// صف من غير كود يتتجاهل
$res3 = an_import($bid, 3, array_merge($s['rows'], [['', 'بلا كود', '1', '1', '1', '1']]), $map);
ok('الصف بدون كود اتتجاهل', $res3['skipped'], 1);
ok('اتخزن ٣ بس',            $res3['stored'], 3);

an_batch_recount($bid);
$b = an_batch($bid);
ok('عداد الصفوف',  (int) $b['rows_count'], 6);
ok('عداد المخازن', (int) $b['stores_count'], 2);

// حقل مطلوب ناقص → استثناء واضح
try {
    an_import($bid, 5, $s['rows'], ['barcode' => 0]);
    ok('عمود ناقص → استثناء', 'عدّى', 'استثناء');
} catch (RuntimeException $ex) {
    ok('عمود ناقص → استثناء', 'استثناء', 'استثناء');
}

// =====================================================================
head('٩) بناء التقرير');

$rep = an_report($bid, 0, true);
ok('عدد الأصناف المجمّعة', $rep['total'], 3);
ok('عدد الفروع',           count($rep['stores']), 4);

$row = null;
foreach ($rep['rows'] as $r2) if ($r2['barcode'] === '6223007312271') $row = $r2;
ok('الصنف اتلقى',    $row ? 'ok' : 'مش موجود', 'ok');
ok('القسم من المرآة', $row['cat'], 'أدوات مكتبية');
ok('التكلفة من hq_codes', $row['cost'], 7.5);
// الفجالة(2) والفروع: مبيعات ٣٥ ورصيد ٢٠ في مخزنين (2 و 3)
ok('إجمالي المبيعات', $row['sum_sales'], 70.0);
ok('إجمالي الرصيد',   $row['sum_bal'], 40.0);
ok('المطلوب العام',   $row['sum_req'], 30.0);
ok('الأولوية = مطلوب × تكلفة', $row['priority'], 225.0);

$cats = an_cats($bid);
ok('عدد الأقسام', count($cats), 2);

$rep2 = an_report($bid, 1000045, true);
ok('فلتر القسم', $rep2['total'], 1);

$sum = an_summary($rep['rows']);
ok('الملخّص بيعدّ', $sum['count'], 3);

// =====================================================================
echo "\n" . str_repeat('═', 50) . "\n";
if ($fail) { echo "❌ فشل $fail | نجح $pass\n\n" . implode("\n", $fails) . "\n"; exit(1); }
echo "✅ كل الاختبارات نجحت ($pass)\n";
