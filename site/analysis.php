<?php
/**
 * analysis.php — تحليل الموسم
 * نظام الفجالة ستور المالي
 *
 * بيحل محل الشغل اليدوي: ٥ ملفات TXT → إكسل → VLOOKUP × ٦ → ماكرو.
 *
 * تابين:
 *   «الرفعات»  — تعمل رفعة (موسم)، وترفع ملف TXT لكل فرع.
 *   «التقرير»  — تختار رفعة وقسم، والجدول المجمّع بيتبني بحالة كل صنف
 *                واقتراح التنقيلات.
 *
 * ⚠️ ده مش «طلب النواقص» (shortage.php) — شوف التعليق في analysis_lib.php.
 */
require_once __DIR__ . '/auth.php';
require_permission('analysis');
require_once __DIR__ . '/analysis_lib.php';

$pdo       = db();
$uid       = (int) current_user()['id'];
$flash     = '';
$flashType = 'success';
$tab       = $_GET['tab'] ?? 'batches';
$ready     = an_ready();

// الملف المؤقت (بيتستخدم بس لو الربط التلقائي فشل) بيتحط في مجلد
// المؤقت بتاع النظام **مش** تحت روت الموقع — كده مفيش مجلد جديد
// يتعمل ولا .htaccess يتنسي ولا ملف يتنزّل بالرابط.
$tmpDir = rtrim(sys_get_temp_dir(), '/') . '/fagala_an';

// =====================================================================
//  التصدير (لازم يبقى قبل أي HTML)
// =====================================================================
if ($ready && ($_GET['export'] ?? '') === '1') {
    $bid = (int) ($_GET['batch'] ?? 0);
    $cat = (int) ($_GET['cat'] ?? 0);
    if ($bid > 0) {
        // ⚠️ التصدير بياخد **كل** الصفوف مش المعروضة بس
        $rep = an_report($bid, $cat, true, 1000000);
        $b   = an_batch($bid);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="analysis_' . $bid . '_' . $cat . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM عشان العربي يبان في إكسل
        $out = fopen('php://output', 'w');

        $hdr = ['كود الصنف', 'اسم الصنف', 'القسم'];
        foreach ($rep['names'] as $n) { $hdr[] = 'مبيعات ' . $n; $hdr[] = 'رصيد ' . $n; $hdr[] = 'مطلوب ' . $n; }
        $hdr = array_merge($hdr, ['مبيعات عام', 'رصيد عام', 'المطلوب العام',
            'وارد ' . $rep['hq_name'], 'سعر التكلفة', 'حالة الصنف', 'الأولوية (ج)']);
        foreach ($rep['names'] as $n) $hdr[] = 'من ' . $n;
        fputcsv($out, $hdr);

        foreach ($rep['rows'] as $r) {
            $line = [$r['barcode'], $r['name'], $r['cat']];
            foreach (array_keys($rep['names']) as $k) {
                $line[] = $r['sales'][$k]; $line[] = $r['bal'][$k]; $line[] = $r['req'][$k];
            }
            $line = array_merge($line, [$r['sum_sales'], $r['sum_bal'], $r['sum_req'],
                $r['hq_in'], $r['cost'], $r['status'], $r['priority']]);
            foreach (array_keys($rep['names']) as $k) $line[] = $r['transfers'][$k];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}

// =====================================================================
//  POST
// =====================================================================
$preview = null;   // لو الربط التلقائي فشل، بنعرض شاشة الربط اليدوي

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حمّل الصفحة من تاني.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // ===== رفعة جديدة =====
            if ($action === 'new_batch') {
                $label = trim($_POST['label'] ?? '');
                $from  = $_POST['period_from'] ?? '';
                $to    = $_POST['period_to'] ?? '';
                if ($label === '' || $from === '' || $to === '') {
                    throw new RuntimeException('اكتب الاسم والفترة كاملة.');
                }
                if ($from > $to) throw new RuntimeException('تاريخ البداية بعد النهاية.');

                $bid = an_batch_create($label, $from, $to, $uid);
                $_SESSION['flash'] = 'الرفعة «' . $label . '» اتعملت. ارفع ملفات الفروع.';
                header('Location: analysis.php?tab=batches&batch=' . $bid);
                exit;
            }

            // ===== مسح رفعة =====
            if ($action === 'del_batch') {
                an_batch_delete((int) $_POST['batch_id']);
                $_SESSION['flash'] = 'الرفعة اتمسحت هي وبنودها.';
                header('Location: analysis.php?tab=batches');
                exit;
            }

            // ===== رفع ملف فرع =====
            if ($action === 'upload') {
                $bid   = (int) ($_POST['batch_id'] ?? 0);
                $stoId = (int) ($_POST['store_id'] ?? 0);
                if (!an_batch($bid))  throw new RuntimeException('الرفعة مش موجودة.');
                if ($stoId <= 0)      throw new RuntimeException('اختار الفرع.');

                $f = $_FILES['txt'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('الملف مااترفعش — جرّب تاني.');
                }
                if ($f['size'] > 20 * 1024 * 1024) {
                    throw new RuntimeException('الملف أكبر من ٢٠ ميجا.');
                }

                $read  = an_read_file($f['tmp_name']);
                $split = an_split($read['text']);

                // الربط: المحفوظ للفرع ده الأول، وبعدين التلقائي
                $saved = an_map_get($stoId);
                $auto  = an_auto_map($split['headers']);
                $map   = $saved ?: $auto;

                // العمود المحفوظ بره حدود الملف؟ يبقى الملف اتغيّر — نرجع للتلقائي
                foreach ($map as $fkey => $i) {
                    if ($i >= count($split['headers'])) { $map = $auto; break; }
                }

                $missing = [];
                foreach (AN_REQUIRED_FIELDS as $fk) if (!isset($map[$fk])) $missing[] = AN_FIELDS[$fk];

                if ($missing) {
                    // مش هنخمّن — بنعرض العناوين ونخلّي محمد يربط بإيده مرة واحدة
                    @mkdir($tmpDir, 0700, true);
                    // كنس أي ملف قديم فات عليه ساعة
                    foreach ((array) glob($tmpDir . '/*.txt') as $old) {
                        if (is_file($old) && filemtime($old) < time() - 3600) @unlink($old);
                    }
                    $tok  = bin2hex(random_bytes(8));
                    $path = $tmpDir . '/' . $tok . '.txt';
                    move_uploaded_file($f['tmp_name'], $path);

                    $preview = [
                        'token'    => $tok,
                        'batch_id' => $bid,
                        'store_id' => $stoId,
                        'file'     => $f['name'],
                        'encoding' => $read['encoding'],
                        'delim'    => $split['delim'],
                        'headers'  => $split['headers'],
                        'rows'     => array_slice($split['rows'], 0, 5),
                        'map'      => $map,
                        'missing'  => $missing,
                    ];
                    $flash = 'مش عارف أحدد: ' . implode(' · ', $missing) . ' — اختارهم من تحت مرة واحدة وهفتكرهم.';
                    $flashType = 'error';
                    $tab = 'batches';
                } else {
                    $res = an_import($bid, $stoId, $split['rows'], $map);
                    an_map_set($stoId, $map);

                    $_SESSION['flash'] = sprintf(
                        'اتخزن %d صنف (%s · %s)%s.',
                        $res['stored'], $read['encoding'], $split['delim'],
                        $res['skipped'] ? ' — واتتجاهل ' . $res['skipped'] . ' صف من غير كود' : ''
                    );
                    header('Location: analysis.php?tab=batches&batch=' . $bid);
                    exit;
                }
            }

            // ===== تأكيد الربط اليدوي =====
            if ($action === 'confirm_map') {
                $bid   = (int) ($_POST['batch_id'] ?? 0);
                $stoId = (int) ($_POST['store_id'] ?? 0);
                $tok   = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
                $path  = $tmpDir . '/' . $tok . '.txt';
                if ($tok === '' || !is_file($path)) {
                    throw new RuntimeException('الملف المؤقت راح — ارفعه من تاني.');
                }

                $map = [];
                foreach (array_keys(AN_FIELDS) as $fk) {
                    $v = $_POST['col_' . $fk] ?? '';
                    if ($v !== '') $map[$fk] = (int) $v;
                }

                $read  = an_read_file($path);
                $split = an_split($read['text']);
                $res   = an_import($bid, $stoId, $split['rows'], $map);
                an_map_set($stoId, $map);
                @unlink($path);

                $_SESSION['flash'] = 'اتخزن ' . $res['stored'] . ' صنف. الربط اتحفظ للفرع ده.';
                header('Location: analysis.php?tab=batches&batch=' . $bid);
                exit;
            }
        } catch (Throwable $ex) {
            $flash     = $ex->getMessage();
            $flashType = 'error';
        }
    }
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// =====================================================================
//  قراءة الصفحة
// =====================================================================
$batches   = $ready ? an_batches() : [];
$curBatch  = (int) ($_GET['batch'] ?? ($batches[0]['id'] ?? 0));
$stores    = $ready ? an_stores() : [];
$uploaded  = ($ready && $curBatch) ? an_batch_stores($curBatch) : [];

$report = null; $summary = null; $cats = [];
if ($ready && $tab === 'report' && $curBatch) {
    $cats     = an_cats($curBatch);
    $catId    = (int) ($_GET['cat'] ?? 0);
    $withSt   = ($_GET['st'] ?? '1') === '1';
    $report   = an_report($curBatch, $catId, $withSt);
    $summary  = an_summary($report['rows']);
}

$page_title    = 'تحليل الموسم';
$active_module = 'analysis';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* ===== الزراير — نفس البلوك المتبع في كل صفحات النظام =====
       ⚠️ `.btn` في fagala.css بيحدد الشكل العام بس (حواف/خط)، واللون
       والحشو بيتعرّفوا محلياً في كل صفحة. نسيت البلوك ده أول مرة
       فطلعت الزراير عريانة. */
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; text-decoration:none;
        display:inline-flex; align-items:center; gap:7px; white-space:nowrap; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:7px 13px; font-size:13px; border-radius:8px; }
    .btn-ghost { background:var(--surface); color:var(--accent-text); border:1.5px solid var(--line); }
    .btn-ghost:hover { background:var(--accent-soft); border-color:var(--accent-text); }
    .btn-danger { background:var(--surface); color:var(--neg); border:1.5px solid var(--neg-soft); }
    .btn-danger:hover { background:var(--neg-soft); }

    /* ===== التابات ===== */
    .an-tabs { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
    .an-tab { padding:10px 18px; border-radius:var(--r); background:var(--surface);
        border:1.5px solid var(--line); color:var(--ink-600); font-size:13.5px;
        text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
    .an-tab:hover { border-color:var(--ink-300); color:var(--ink-800); }
    .an-tab.on { background:var(--accent); border-color:var(--accent-text); color:#fff; }

    /* ===== الكروت والنماذج ===== */
    .an-card { background:var(--surface); border:1px solid var(--line); border-radius:var(--r-lg);
        padding:18px; margin-bottom:14px; box-shadow:var(--shadow-sm); }
    .an-h { font-size:14px; font-weight:700; color:var(--ink-900); margin-bottom:14px;
        padding-bottom:10px; border-bottom:1px solid var(--line-soft); }
    .an-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .an-fld { display:flex; flex-direction:column; gap:6px; }
    .an-fld label { font-size:11.5px; color:var(--ink-500); font-weight:500; }
    .an-fld input, .an-fld select {
        padding:10px 12px; border:1.5px solid var(--line); border-radius:var(--r-sm);
        font-family:inherit; font-size:13.5px; color:var(--ink-800); background:var(--surface);
        min-height:42px; }
    .an-fld input:focus, .an-fld select:focus {
        outline:none; border-color:var(--accent-text); box-shadow:0 0 0 3px var(--accent-ring); }
    .an-hint { font-size:11.5px; color:var(--ink-400); margin-top:9px; line-height:1.7; }

    /* شريط أدوات التقرير — الحقول شمال والأفعال يمين */
    .an-bar { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .an-bar .sp { flex:1 1 auto; }
    .an-bar .acts { display:flex; gap:9px; align-items:center; }

    /* ===== قايمة الرفعات ===== */
    .an-blist { display:flex; flex-direction:column; gap:8px; }
    .an-b { display:flex; gap:14px; align-items:center; flex-wrap:wrap;
        padding:13px 15px; border:1.5px solid var(--line); border-radius:var(--r);
        text-decoration:none; color:inherit; }
    .an-b:hover { border-color:var(--ink-300); background:var(--bg); }
    .an-b.on { border-color:var(--accent-text); background:var(--accent-soft); }
    .an-b b { font-size:13.5px; color:var(--ink-900); }
    .an-b span { font-size:12px; color:var(--ink-500); }

    .an-stores { display:flex; gap:9px; flex-wrap:wrap; }
    .an-st { padding:11px 14px; border-radius:var(--r); border:1.5px solid var(--line);
        font-size:12.5px; min-width:130px; }
    .an-st.done { border-color:var(--pos); background:var(--pos-soft); }
    .an-st b { display:block; font-size:13px; color:var(--ink-900); margin-bottom:2px; }
    .an-st span { color:var(--ink-500); }

    /* ===== كروت الملخّص ===== */
    .an-cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .an-c { background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
        padding:13px 16px; min-width:132px; flex:1 1 132px;
        border-top:3px solid var(--ink-300); box-shadow:var(--shadow-sm); }
    .an-c .n { font-size:20px; font-weight:700; color:var(--ink-900);
        font-variant-numeric:tabular-nums; direction:ltr; text-align:right; }
    .an-c .t { font-size:11.5px; color:var(--ink-500); margin-top:3px; }
    .an-c.k-naqes { border-top-color:var(--neg); }
    .an-c.k-raked { border-top-color:var(--warn); }
    .an-c.k-tamam { border-top-color:var(--pos); }
    .an-c.k-nazar { border-top-color:var(--accent-text); }

    /* ===== الشارات — هادية زي باقي النظام ===== */
    .an-badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
        white-space:nowrap; display:inline-block; }
    .b-naqes  { background:var(--neg-soft);    color:var(--neg); }
    .b-raked  { background:var(--warn-soft);   color:var(--warn); }
    .b-rakedf { background:var(--warn-fill);        color:#fff; }
    .b-nazar  { background:var(--accent-soft); color:var(--accent-text); }
    .b-tamam  { background:var(--pos-soft);    color:var(--pos); }

    /* =================================================================
       الجدول — ٢٦ عمود، فالمهم إنك ما تتوهش
       ================================================================= */
    .an-wrap { background:var(--surface); border:1px solid var(--line);
        border-radius:var(--r-lg); box-shadow:var(--shadow-sm);
        overflow:auto; max-height:74vh; }
    .an-tbl { width:100%; border-collapse:separate; border-spacing:0;
        font-size:12.5px; white-space:nowrap; }

    /* الرأس بصفين: صف المجموعات فوق وصف التفاصيل تحت.
       ⚠️ الصف الأخير لازم يبقى **من غير دمج** وإلا xtable.js بيسيب
          الجدول من غير فلتر ولا ترتيب (بيقارن عدد الخلايا بالأعمدة). */
    /* بوردر أسود بين كل الخلايا.
       ⚠️ الجدول `border-collapse: separate` (لازم كده عشان الأعمدة
          الثابتة تشتغل)، فالبوردر بيتحط على **جنب واحد** لكل خلية
          (تحت + البداية) وإلا كل خط هيتكرر مرتين ويطلع تخين. */
    /* 🔴 خصائص **فيزيائية** (right/left) مش منطقية (inline).
          السبب: الخاصية المنطقية بتتحدد حسب اتجاه **الخلية نفسها**،
          وخانات الأرقام عندها `direction: ltr` (عشان السالب يطلع
          «-16» مش «16-»). فكانت بترسم البوردر على شمالها بدل يمينها،
          والنتيجة إن الحد بين «القسم» و«مبيعات الفجالة» — وبين
          «الحالة» و«الأولوية» — يطلع فاضي. */
    /* 🔴 كان `#000` صريح. في الوضع الليلي خط أسود على أرضية سودا =
       شبكة مختفية والجدول بيبقى كتلة أرقام من غير حدود. `--ink-300`
       بيقلب مع الوضع: رمادي فاتح على الأبيض، ورمادي غامق على الأسود —
       فالشبكة بتفضل ظاهرة في الاتنين. */
    .an-tbl th, .an-tbl td {
        border-bottom:1px solid var(--ink-300);
        border-right:1px solid var(--ink-300); }
    .an-tbl th:last-child, .an-tbl td:last-child {
        border-left:1px solid var(--ink-300); }
    .an-tbl thead tr:first-child th { border-top:1px solid var(--ink-300); }

    .an-tbl thead th { background:var(--bg); color:var(--ink-600);
        font-size:11px; font-weight:600; padding:7px 9px; text-align:center;
        position:sticky; z-index:3; }
    .an-tbl thead tr:first-child th { top:0; height:30px; color:var(--ink-800);
        font-size:11.5px; }
    .an-tbl thead tr:last-child th { top:30px; }

    .an-tbl td { padding:7px 9px; text-align:center; color:var(--ink-800); }
    .an-tbl tbody tr:nth-child(even) td { background:var(--surface); }
    .an-tbl tbody tr:hover td { background:var(--accent-soft); }

    /* الأرقام: خانات متساوية العرض، واتجاه لاتيني عشان السالب يطلع
       «-16» مش «16-» جوه الصفحة العربية */
    .an-tbl td.num { font-variant-numeric:tabular-nums; direction:ltr; }
    .an-tbl td.z   { color:var(--ink-300); }       /* الصفر يخفت عشان العين تمسك الأرقام الحقيقية */
    .an-tbl td.neg { color:var(--neg); font-weight:600; }

    /* عمودا الكود والاسم بيفضلوا ثابتين وانت بتلف يمين وشمال */
    .an-tbl .c-code, .an-tbl .c-name { position:sticky; z-index:4;
        background:var(--surface); text-align:right; }
    .an-tbl .c-code { right:0;                  width:118px; min-width:118px;
        font-variant-numeric:tabular-nums; direction:ltr; text-align:left; }
    .an-tbl .c-name { right:118px;              width:210px; min-width:210px;
        white-space:normal; line-height:1.5; }
    .an-tbl tbody tr:nth-child(even) .c-code,
    .an-tbl tbody tr:nth-child(even) .c-name { background:var(--surface); }
    .an-tbl tbody tr:hover .c-code,
    .an-tbl tbody tr:hover .c-name { background:var(--accent-soft); }
    .an-tbl thead .c-code, .an-tbl thead .c-name { z-index:6; background:var(--bg); }

    /* تظليل خفيف يفصل مجموعة كل فرع عن اللي جنبها */
    .an-tbl .g1 td, .an-tbl td.g1 { background:var(--surface); }
    .an-tbl tbody tr:nth-child(even) td.g1 { background:var(--line-soft); }
    .an-tbl tbody tr:hover td.g1 { background:var(--accent-soft); }
    /* فاصل المجموعات بقى أعرض شوية عشان يفضل باين وسط البوردر العام */
    .an-tbl th.gsep, .an-tbl td.gsep { border-right-width:2px; }

    .an-tbl td.trs { text-align:right; white-space:normal; font-size:11.5px;
        color:var(--pos); max-width:180px; line-height:1.6; }

    /* ===== خلية الأولوية — رقم وتحته شريط =====
       ⚠️ الشريط جوه وسم `<i>` عن قصد: دالة txt() في xtable.js بتتجاهل
          وسوم I و BUTTON و SVG، فالترتيب وزرار المجموع (Σ) بيقروا
          الرقم لوحده من غير ما الشريط يلخبطهم.

       🔴 الشريط في **السياق العادي** (display:block) مش position:absolute.
          النسخة الأولى كانت مطلقة جوه الخلية، والمواصفة بتقول إن
          الحاوية المرجعية لعنصر مطلق جوه `table-cell` **غير محددة** —
          فكل متصفح بيتصرف على هواه والشريط ممكن مايبانش خالص.
          كتلة عادية بعرض نسبي بتشتغل في أي مكان. */
    .an-tbl td.pri { text-align:right; min-width:98px; }
    .an-tbl td.pri > b { display:block; font-weight:600;
        font-variant-numeric:tabular-nums; direction:ltr; }
    .an-tbl td.pri > i { display:block; height:5px; margin-top:4px;
        min-width:3px; border-radius:3px; background:var(--line); }
    /* أحمر = فلوس هتطلع من إيدك · أصفر = فلوس واقفة في المخزن */
    .an-tbl td.pri-buy  > i { background:var(--neg-soft); }
    .an-tbl td.pri-buy  > b { color:var(--neg); }
    .an-tbl td.pri-dead > i { background:var(--warn-soft); }
    .an-tbl td.pri-dead > b { color:var(--warn); }
    .an-tbl td.pri-none > b { color:var(--ink-400); font-weight:400; }

    .an-legend { display:flex; gap:16px; flex-wrap:wrap; align-items:center;
        font-size:11.5px; color:var(--ink-500); margin:0 0 12px 2px; }
    .an-legend span { display:inline-flex; align-items:center; gap:6px; }
    .an-legend i { width:22px; height:10px; border-radius:3px; display:inline-block; }

    .an-warn { padding:11px 14px; border-radius:var(--r); background:var(--warn-soft);
        border:1px solid var(--warn); color:var(--ink-800); font-size:12.5px; margin-bottom:12px; }
    .an-ok   { padding:11px 14px; border-radius:var(--r); background:var(--pos-soft);
        border:1px solid var(--pos); color:var(--ink-800); font-size:12.5px; margin-bottom:12px; }
    .an-err  { padding:11px 14px; border-radius:var(--r); background:var(--neg-soft);
        border:1px solid var(--neg); color:var(--ink-800); font-size:12.5px; margin-bottom:12px; }

    .an-prev { width:100%; border-collapse:collapse; font-size:11.5px; margin-top:12px; }
    .an-prev th, .an-prev td { padding:6px 8px; border:1px solid var(--line-soft); text-align:center; }
    .an-prev th { background:var(--bg); color:var(--ink-600); }

    @media (max-width:820px) {
        .an-tbl .c-name { position:static; }
        .an-c { min-width:110px; }
    }
</style>

<?php if (!$ready): ?>
    <div class="an-card" style="color:var(--neg);text-align:center;padding:32px;">
        الجداول لسه ما اتعملتش — شغّل <b>upgrade_shortage.sql</b> من phpMyAdmin الأول.
    </div>
<?php else: ?>

<?php if ($flash): ?>
    <div class="<?= $flashType === 'error' ? 'an-err' : 'an-ok' ?>">
        <i class="ti <?= $flashType === 'error' ? 'ti-alert-triangle' : 'ti-circle-check' ?>"></i>
        <?= e($flash) ?>
    </div>
<?php endif; ?>

<div class="an-tabs">
    <a class="an-tab <?= $tab === 'batches' ? 'on' : '' ?>"
       href="analysis.php?tab=batches&amp;batch=<?= $curBatch ?>">
        <i class="ti ti-upload"></i> الرفعات والملفات
    </a>
    <a class="an-tab <?= $tab === 'report' ? 'on' : '' ?>"
       href="analysis.php?tab=report&amp;batch=<?= $curBatch ?>">
        <i class="ti ti-file-spreadsheet"></i> التقرير
    </a>
</div>

<?php // =============================================================
      //  شاشة الربط اليدوي — بتظهر بس لو التلقائي فشل
      // ============================================================= ?>
<?php if ($preview): ?>
    <div class="an-card" style="border:2px solid var(--accent);">
        <div class="an-h">اربط أعمدة الملف — مرة واحدة بس</div>
        <div style="font-size:12.5px;color:var(--ink-500);margin-bottom:10px;">
            <b><?= e($preview['file']) ?></b> · الترميز <?= e($preview['encoding']) ?>
            · الفاصل <?= e($preview['delim']) ?> · <?= count($preview['headers']) ?> عمود
        </div>

        <table class="an-prev">
            <tr><?php foreach ($preview['headers'] as $i => $h): ?>
                <th><?= $i ?><br><?= e((string) $h) ?></th>
            <?php endforeach; ?></tr>
            <?php foreach ($preview['rows'] as $r): ?>
                <tr><?php foreach ($preview['headers'] as $i => $h): ?>
                    <td><?= e((string) ($r[$i] ?? '')) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
        </table>

        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm_map">
            <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
            <input type="hidden" name="batch_id" value="<?= (int) $preview['batch_id'] ?>">
            <input type="hidden" name="store_id" value="<?= (int) $preview['store_id'] ?>">
            <div class="an-row">
                <?php foreach (AN_FIELDS as $fk => $flabel): ?>
                    <div class="an-fld">
                        <label><?= e($flabel) ?>
                            <?= in_array($fk, AN_REQUIRED_FIELDS, true)
                                ? '<span style="color:var(--neg)">*</span>' : '' ?></label>
                        <select name="col_<?= e($fk) ?>">
                            <option value="">— مفيش —</option>
                            <?php foreach ($preview['headers'] as $i => $h): ?>
                                <option value="<?= $i ?>"
                                    <?= (($preview['map'][$fk] ?? -1) === $i) ? 'selected' : '' ?>>
                                    <?= $i ?> — <?= e((string) $h) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <button class="btn" type="submit"><i class="ti ti-check"></i> استورد</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php // =============================================================
      //  تاب الرفعات
      // ============================================================= ?>
<?php if ($tab === 'batches'): ?>

    <div class="an-card">
        <div class="an-h">رفعة جديدة</div>
        <form method="post" class="an-row">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="new_batch">
            <div class="an-fld">
                <label>الاسم</label>
                <input name="label" placeholder="موسم 2026" required style="min-width:180px;">
            </div>
            <div class="an-fld">
                <label>من</label>
                <input type="date" name="period_from" required>
            </div>
            <div class="an-fld">
                <label>لـ</label>
                <input type="date" name="period_to" required>
            </div>
            <button class="btn" type="submit"><i class="ti ti-plus"></i> اعمل رفعة</button>
        </form>
    </div>

    <?php if (!$batches): ?>
        <div class="an-card" style="text-align:center;color:var(--ink-500);padding:26px;">
            مفيش رفعات لسه. اعمل واحدة وارفع عليها ملفات الفروع.
        </div>
    <?php else: ?>
        <div class="an-card">
            <div class="an-h">الرفعات</div>
            <div class="an-blist">
                <?php foreach ($batches as $b): ?>
                    <a class="an-b <?= (int) $b['id'] === $curBatch ? 'on' : '' ?>"
                       href="analysis.php?tab=batches&amp;batch=<?= (int) $b['id'] ?>">
                        <b><?= e($b['label'] ?: ('رفعة #' . $b['id'])) ?></b>
                        <span><?= e($b['period_from']) ?> ← <?= e($b['period_to']) ?></span>
                        <span><?= number_format((int) $b['rows_count']) ?> صف
                              · <?= (int) $b['stores_in'] ?> فرع</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($curBatch): ?>
        <div class="an-card">
            <div class="an-h">ملفات الفروع</div>
            <div class="an-stores" style="margin-bottom:16px;">
                <?php foreach ($stores as $sid => $s): ?>
                    <?php $u = $uploaded[$sid] ?? null; ?>
                    <div class="an-st <?= $u ? 'done' : '' ?>">
                        <b><?= e($s['name']) ?><?= $s['is_hq'] ? ' (مشتريات)' : '' ?></b>
                        <span><?= $u ? number_format((int) $u['n']) . ' صنف' : 'مااترفعش' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="an-row" style="margin-top:14px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="batch_id" value="<?= $curBatch ?>">
                <div class="an-fld">
                    <label>الفرع</label>
                    <select name="store_id" required>
                        <option value="">— اختار —</option>
                        <?php foreach ($stores as $sid => $s): ?>
                            <option value="<?= $sid ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="an-fld">
                    <label>ملف الـTXT</label>
                    <input type="file" name="txt" accept=".txt,.csv,.tsv,text/plain" required>
                </div>
                <button class="btn" type="submit"><i class="ti ti-upload"></i> ارفع</button>
            </form>
            <div class="an-hint">
                الترميز بيتكشف لوحده (بونجور <code>cp1256</code> والباقي <code>utf-8</code>).
                رفع نفس الفرع تاني <b>بيستبدل</b> القديم مش بيتراكم عليه.
            </div>

            <form method="post" style="margin-top:14px;"
                  onsubmit="return confirm('تمسح الرفعة دي وكل بنودها؟');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="del_batch">
                <input type="hidden" name="batch_id" value="<?= $curBatch ?>">
                <button class="btn btn-sm btn-danger" type="submit">
                    <i class="ti ti-trash"></i> امسح الرفعة دي
                </button>
            </form>
        </div>
    <?php endif; ?>

<?php // =============================================================
      //  تاب التقرير
      // ============================================================= ?>
<?php elseif ($tab === 'report'): ?>

    <?php if (!$curBatch): ?>
        <div class="an-card" style="text-align:center;color:var(--ink-500);padding:26px;">
            اعمل رفعة الأول من تاب «الرفعات».
        </div>
    <?php else: ?>

    <div class="an-card">
        <form method="get" class="an-bar">
            <input type="hidden" name="tab" value="report">
            <div class="an-fld">
                <label>الرفعة</label>
                <select name="batch" onchange="this.form.submit()">
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $curBatch ? 'selected' : '' ?>>
                            <?= e($b['label'] ?: ('#' . $b['id'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-fld">
                <label>القسم</label>
                <select name="cat">
                    <option value="0">كل الأقسام</option>
                    <?php foreach ($cats as $cid => $cn): ?>
                        <option value="<?= (int) $cid ?>"
                            <?= (int) $cid === (int) ($_GET['cat'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($cn) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-fld">
                <label>الحالة والتنقيلات</label>
                <select name="st">
                    <option value="1" <?= ($_GET['st'] ?? '1') === '1' ? 'selected' : '' ?>>احسبها</option>
                    <option value="0" <?= ($_GET['st'] ?? '1') === '0' ? 'selected' : '' ?>>الأرقام بس (أسرع)</option>
                </select>
            </div>
            <div class="sp"></div>
            <div class="acts">
                <button class="btn" type="submit"><i class="ti ti-refresh"></i> ابني التقرير</button>
                <a class="btn btn-ghost"
                   href="analysis.php?export=1&amp;batch=<?= $curBatch ?>&amp;cat=<?= (int) ($_GET['cat'] ?? 0) ?>">
                    <i class="ti ti-download"></i> تصدير الكل
                </a>
            </div>
        </form>
    </div>

    <?php if ($report && $report['total'] === 0): ?>
        <div class="an-card" style="text-align:center;color:var(--ink-500);padding:26px;">
            الرفعة دي مفيهاش بنود — ارفع ملفات الفروع الأول.
        </div>
    <?php elseif ($report): ?>

        <div class="an-cards">
            <div class="an-c"><div class="n"><?= number_format($report['total']) ?></div>
                <div class="t">صنف</div></div>
            <div class="an-c k-naqes"><div class="n"><?= number_format($summary['ناقص']['n']) ?></div>
                <div class="t">ناقص</div></div>
            <div class="an-c k-raked">
                <div class="n"><?= number_format($summary['راكد']['n'] + $summary['راكد على مستوى الفروع']['n']) ?></div>
                <div class="t">راكد</div></div>
            <div class="an-c k-nazar"><div class="n"><?= number_format($summary['يرجى النظر في الأمر']['n']) ?></div>
                <div class="t">يرجى النظر</div></div>
            <div class="an-c k-tamam"><div class="n"><?= number_format($summary['تمام']['n']) ?></div>
                <div class="t">تمام</div></div>
            <div class="an-c k-naqes"><div class="n"><?= number_format($summary['buy_val'], 0) ?></div>
                <div class="t">شراء مطلوب (ج)</div></div>
            <div class="an-c k-raked"><div class="n"><?= number_format($summary['dead_val'], 0) ?></div>
                <div class="t">قيمة الراكد (ج)</div></div>
        </div>

        <?php if ($report['truncated']): ?>
            <div class="an-warn">
                الجدول كبير — بيتعرض <b><?= number_format(count($report['rows'])) ?></b>
                صنف من <b><?= number_format($report['total']) ?></b>.
                فلتر بقسم، أو دوس «تصدير الكل» وهينزل الملف كامل.
            </div>
        <?php endif; ?>

        <div class="an-legend">
            <b style="color:var(--ink-700);">عمود الأولوية:</b>
            <span><i style="background:var(--neg-soft);"></i> فلوس هتشتري بيها</span>
            <span><i style="background:var(--warn-soft);"></i> فلوس واقفة في المخزن</span>
            <span>طول الشريط تحت الرقم = تقل الصنف بالنسبة لأتقل صنف في المعروض</span>
        </div>

        <div class="an-wrap">
            <table class="an-tbl">
                <thead>
                    <?php $nb = count($report['names']); ?>
                    <?php // صف المجموعات — بيغطي كل الأعمدة عشان الصف
                          // اللي تحته يفضل من غير دمج (شرط xtable.js) ?>
                    <tr>
                        <th colspan="3">الصنف</th>
                        <?php foreach ($report['names'] as $gi => $n): ?>
                            <th colspan="3" class="gsep"><?= e($n) ?></th>
                        <?php endforeach; ?>
                        <th colspan="3" class="gsep">الإجمالي العام</th>
                        <th colspan="4" class="gsep">القرار</th>
                        <th colspan="<?= $nb ?>" class="gsep">التنقيلات المقترحة</th>
                    </tr>
                    <tr>
                        <th class="c-code">الكود</th>
                        <th class="c-name">اسم الصنف</th>
                        <th>القسم</th>
                        <?php foreach ($report['names'] as $gi => $n): ?>
                            <th class="gsep">مبيعات</th><th>رصيد</th><th>مطلوب</th>
                        <?php endforeach; ?>
                        <th class="gsep">مبيعات</th><th>رصيد</th><th>مطلوب</th>
                        <th class="gsep">وارد <?= e($report['hq_name']) ?></th>
                        <th>تكلفة</th><th>الحالة</th><th>الأولوية (ج)</th>
                        <?php foreach ($report['names'] as $n): ?>
                            <th class="gsep">من <?= e($n) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                // أعلى قيمة في المعروض — الشريط نسبة منها
                $maxPri = 0.0;
                foreach ($report['rows'] as $r0) {
                    if ($r0['priority'] > $maxPri) $maxPri = (float) $r0['priority'];
                }

                // خلية الأولوية: شريط + رقم، ولونه بيقول الفلوس رايحة
                // ولا واقفة — فالقيمة المطلقة مابقتش ملخبطة.
                //
                // ⚠️ عرض الشريط بالجذر التربيعي مش خطي: صنف واحد بقيمة
                //    مليون كان هيخلّي كل الباقي شعرة مش باينة. الجذر
                //    بيرفع الصغير من غير ما يضيّع ترتيب الكبير.
                $priCell = static function (float $v, string $status, float $max): string {
                    if ($v <= 0) return '<td class="pri pri-none"><b>—</b></td>';
                    $kind = ($status === 'ناقص') ? 'pri-buy'
                          : (($status === 'راكد' || $status === 'راكد على مستوى الفروع')
                             ? 'pri-dead' : 'pri-none');
                    $w = $max > 0 ? sqrt($v / $max) * 100 : 0;
                    return '<td class="pri ' . $kind . '">'
                         . '<b>' . number_format($v, 0) . '</b>'
                         . '<i style="width:' . number_format($w, 1, '.', '') . '%"></i></td>';
                };

                $badge = [
                    'ناقص' => 'b-naqes', 'راكد' => 'b-raked',
                    'راكد على مستوى الفروع' => 'b-rakedf',
                    'يرجى النظر في الأمر' => 'b-nazar', 'تمام' => 'b-tamam',
                ];
                // خلية رقم: الصفر يخفت، والسالب أحمر، والاتجاه لاتيني
                $num = static function (float $v, string $extra = ''): string {
                    $cls = ($v == 0.0) ? 'z' : (($v < 0) ? 'neg' : '');
                    return '<td class="num ' . $cls . ' ' . $extra . '">'
                         . an_fmt_qty($v) . '</td>';
                };
                foreach ($report['rows'] as $r): ?>
                    <tr>
                        <td class="c-code"><?= e($r['barcode']) ?></td>
                        <td class="c-name"><?= e($r['name']) ?></td>
                        <td><?= e($r['cat']) ?></td>
                        <?php foreach (array_keys($report['names']) as $k):
                            $g = ($k % 2) ? 'g1' : ''; ?>
                            <?= $num($r['sales'][$k], $g . ' gsep') ?>
                            <?= $num($r['bal'][$k],   $g) ?>
                            <?= $num($r['req'][$k],   $g) ?>
                        <?php endforeach; ?>
                        <?= $num($r['sum_sales'], 'gsep') ?>
                        <?= $num($r['sum_bal']) ?>
                        <?= $num($r['sum_req']) ?>
                        <?= $num($r['hq_in'], 'gsep') ?>
                        <td class="num <?= $r['cost'] > 0 ? '' : 'z' ?>">
                            <?= $r['cost'] > 0 ? number_format($r['cost'], 2) : '—' ?></td>
                        <td><?php if ($r['status'] !== ''): ?>
                            <span class="an-badge <?= $badge[$r['status']] ?? '' ?>"><?= e($r['status']) ?></span>
                        <?php endif; ?></td>
                        <?= $priCell((float) $r['priority'], $r['status'], $maxPri) ?>
                        <?php foreach (array_keys($report['names']) as $k): ?>
                            <td class="trs gsep"><?= e($r['transfers'][$k]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
