<?php
/**
 * shortage_lib.php — تقرير الصادر والوارد وتحليل النواقص
 * =====================================================================
 *  الفجالة ستور — 2026-08-23
 *
 *  بيعمل ٣ حاجات:
 *    ١) يقرا ملفات TXT اللي بتتسحب من برنامج الشركة
 *    ٢) يجمّع: صف واحد لكل صنف وجنبه أعمدة كل فرع
 *    ٣) يصنّف (ناقص/راكد/تمام) ويوزّع الفايض على المحتاج
 *
 *  ⚠️ منطق التصنيف والتوزيع ترجمة حرفية لماكرو `تحليل_الاصناف.bas`
 *     اللي محمد شغّال بيه. اتحقق منه على شيت التحليل بتاعه:
 *     **٥٠٤٠ تطابق من ٥١١٠ صف = ٩٨.٦٣٪**. الـ٧٠ الباقيين الشيت
 *     مكتوب فيهم «تمام» والماكرو بيقول «يرجى النظر» — يعني الشيت
 *     اتعدّل بالإيد بعد الماكرو، مش اختلاف في المنطق.
 */

declare(strict_types=1);

/** الدستة — أقل كمية تجربة لفرع مادخلوش الصنف */
function shortage_dosta(): float
{
    return (float) (setting('shortage_dosta') ?: 12);
}

/** مخزن الإدارة — بره حسابات الناقص والراكد والتوزيع */
function shortage_hq_store(): int
{
    return (int) (setting('shortage_hq_store') ?: 1);
}

/**
 * الفروع الداخلة في التحليل، بالترتيب.
 * الإدارة مش منهم عن قصد: «الإدارة ملهاش نواقص ولا رواكد، بناخد منها
 * الكمية المشتراة بس» — قرار محمد 2026-08-23.
 */
function shortage_branch_ids(): array
{
    $raw = (string) (setting('shortage_branch_ids') ?: '2,3,5,6');
    $out = [];
    foreach (explode(',', $raw) as $x) {
        $x = (int) trim($x);
        if ($x > 0) $out[] = $x;
    }
    return $out;
}

/** أسماء المخازن — من الفروع عندنا، والباقي برقمه */
function shortage_store_names(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map = [1 => 'الإدارة', 2 => 'الفجالة', 3 => 'بونجور', 5 => 'دجلة',
            6 => 'اي بي سي', 7 => 'إشراقة'];
    try {
        foreach (db()->query('SELECT store_id, name FROM hq_stores') as $r) {
            $map[(int) $r['store_id']] = (string) $r['name'];
        }
    } catch (Throwable $e) {
        // hq_stores لسه ماتعملش — الأسماء الافتراضية تكفي
    }
    return $map;
}

/* =====================================================================
   ١) قراءة ملفات TXT
   ===================================================================== */

/**
 * بيحوّل محتوى الملف لـUTF-8 مهما كان ترميزه الأصلي.
 *
 * ⚠️ ده مش رفاهية: ملف بونجور بيتسحب بترميز cp1256 والباقي utf-8.
 *    لو افترضنا ترميز واحد، أسامي أصناف بونجور بتطلع «؟؟؟».
 *    (اتفحص على الملفات الخمسة يوم 2026-08-23.)
 */
function shortage_to_utf8(string $raw): array
{
    // BOM بتاع UTF-8
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        return [substr($raw, 3), 'utf-8-bom'];
    }
    // BOM بتاع UTF-16
    if (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) {
        $conv = @iconv('UTF-16', 'UTF-8//IGNORE', $raw);
        if ($conv !== false) return [$conv, 'utf-16'];
    }
    if (mb_check_encoding($raw, 'UTF-8')) {
        return [$raw, 'utf-8'];
    }
    // مش UTF-8 صالح → عربي ويندوز
    $conv = @iconv('CP1256', 'UTF-8//IGNORE', $raw);
    if ($conv !== false) return [$conv, 'cp1256'];

    return [mb_convert_encoding($raw, 'UTF-8', 'UTF-8'), 'unknown'];
}

/** تطبيع اسم عمود عشان المقارنة (مسافات · تشكيل · همزات · تطويل) */
function shortage_norm_header(string $h): string
{
    $h = trim($h);
    $h = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $h) ?? $h;
    $h = str_replace(['أ', 'إ', 'آ'], 'ا', $h);
    $h = str_replace(['ى'], 'ي', $h);
    $h = str_replace(['ة'], 'ه', $h);
    $h = preg_replace('/\s+/u', ' ', $h) ?? $h;
    return $h;
}

/**
 * خريطة الأعمدة: اسم العمود في الملف → المفتاح عندنا.
 *
 * ⚠️ القراءة **بالاسم مش بالموضع** عن قصد:
 *    ملف الإدارة ١٨ عمود وفيه «القســـــم» زيادة بعد الرصيد،
 *    وباقي الملفات ١٧. القراءة بالموضع كانت هتخلي «الوارد» بتاع
 *    الإدارة يقرا رقم القسم (شفنا 1000032 كوارد) — يعني كل أرقام
 *    الإدارة غلط من غير ما حد ياخد باله.
 */
function shortage_header_map(): array
{
    return [
        'المخزن'                     => 'store_id',
        'كود الصنف'                  => 'barcode',
        'اسم الصنف'                  => 'item_name',
        'الرصيد'                     => 'balance',
        'صافي مشتريات الكميه'        => 'in_qty',
        'صافي مشتريات سعر-شراء'      => 'in_cost',
        'صافي مشتريات سعر-بيع'       => 'in_sale',
        'صافي مبيعات الكميه'         => 'out_qty',
    ];
}

/**
 * بيقرا ملف TXT واحد ويرجّع صفوفه.
 *
 * @return array{rows: array, encoding: string, skipped: int, errors: array}
 */
function shortage_parse_txt(string $raw): array
{
    [$text, $enc] = shortage_to_utf8($raw);
    $text  = str_replace("\r\n", "\n", str_replace("\r", "\n", $text));
    $lines = explode("\n", $text);

    $errors = [];
    $header = null;
    foreach ($lines as $i => $ln) {
        if (trim($ln) === '') continue;
        $header = explode("\t", $ln);
        unset($lines[$i]);
        break;
    }
    if ($header === null) {
        return ['rows' => [], 'encoding' => $enc, 'skipped' => 0,
                'errors' => ['الملف فاضي.']];
    }

    // الرأس → مواضع الأعمدة اللي تهمنا
    $map = shortage_header_map();
    $pos = [];
    foreach ($header as $idx => $h) {
        $n = shortage_norm_header($h);
        if ($n === '' || $n === '!') continue;          // الأعمدة الفاضية
        foreach ($map as $want => $key) {
            if ($n === shortage_norm_header($want)) { $pos[$key] = $idx; break; }
        }
    }

    foreach (['store_id', 'barcode', 'balance', 'in_qty', 'out_qty'] as $must) {
        if (!isset($pos[$must])) {
            $errors[] = 'عمود مفقود في الملف: ' . array_search($must, $map, true);
        }
    }
    if ($errors) {
        return ['rows' => [], 'encoding' => $enc, 'skipped' => 0, 'errors' => $errors];
    }

    $num = static function (array $f, ?int $i): float {
        if ($i === null || !isset($f[$i])) return 0.0;
        $v = str_replace([',', ' '], '', trim((string) $f[$i]));
        return is_numeric($v) ? (float) $v : 0.0;
    };

    $rows = [];
    $skipped = 0;
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        $f = explode("\t", $ln);

        $bc = trim((string) ($f[$pos['barcode']] ?? ''));
        $st = (int) trim((string) ($f[$pos['store_id']] ?? '0'));
        // مفيش شرط على شكل الكود — أكواد المنتجات عندنا فيها حروف
        // ونجوم وعربي (easy · PRIMA · *6222002206080). الفاضي بس
        // والأطول من العمود هما اللي بيترموا.
        if ($bc === '' || mb_strlen($bc) > 64 || $st <= 0) { $skipped++; continue; }

        $rows[] = [
            'store_id'  => $st,
            'barcode'   => $bc,
            'item_name' => mb_substr(trim((string) ($f[$pos['item_name']] ?? '')), 0, 255),
            'balance'   => $num($f, $pos['balance']  ?? null),
            'in_qty'    => $num($f, $pos['in_qty']   ?? null),
            'in_cost'   => $num($f, $pos['in_cost']  ?? null),
            'in_sale'   => $num($f, $pos['in_sale']  ?? null),
            'out_qty'   => $num($f, $pos['out_qty']  ?? null),
        ];
    }

    return ['rows' => $rows, 'encoding' => $enc, 'skipped' => $skipped, 'errors' => []];
}

/* =====================================================================
   ٢) التصنيف والتوزيع — ترجمة ماكرو تحليل_الاصناف
   ===================================================================== */

/**
 * بيصنّف صنف ويوزّع الفايض على المحتاج.
 *
 * @param array $mat  المطلوب لكل فرع (مبيعات − رصيد)
 * @param array $mab  المبيعات لكل فرع
 * @param array $ras  الرصيد لكل فرع
 * @param array $names أسماء الفروع (لنص التحويل)
 *
 * @return array{status: string, transfers: array}
 */
function shortage_classify(array $mat, array $mab, array $ras, array $names): array
{
    $n     = count($mat);
    $dosta = shortage_dosta();
    $tr    = array_fill(0, $n, '');

    // كلهم فايض
    $allNeg = true;
    foreach ($mat as $m) if ($m >= 0) { $allNeg = false; break; }
    if ($allNeg) return ['status' => 'راكد على مستوى الفروع', 'transfers' => $tr];

    // كلهم صفر
    $allZero = true;
    foreach ($mat as $m) if ($m != 0) { $allZero = false; break; }
    if ($allZero) {
        for ($j = 0; $j < $n; $j++) {
            if ($mab[$j] == 0 && $ras[$j] == 0) {
                return ['status' => 'يرجى النظر في الأمر', 'transfers' => $tr];
            }
        }
        return ['status' => 'تمام', 'transfers' => $tr];
    }

    // كلهم محتاج
    $allPos = true;
    foreach ($mat as $m) if ($m <= 0) { $allPos = false; break; }
    if ($allPos) return ['status' => 'ناقص', 'transfers' => $tr];

    // مختلط: فيه فايض وفيه محتاج → نوزّع
    $sur = []; $surIdx = []; $need = []; $needIdx = [];
    for ($j = 0; $j < $n; $j++) {
        if ($mat[$j] < 0) { $sur[] = abs($mat[$j]); $surIdx[] = $j; }
        elseif ($mat[$j] > 0) { $need[] = $mat[$j]; $needIdx[] = $j; }
        elseif ($mab[$j] == 0 && $ras[$j] == 0) {
            // فرع مادخلوش الصنف خالص → دستة تجربة
            $need[] = $dosta; $needIdx[] = $j;
        }
    }

    // تنازلي: الأكبر فايض مع الأكبر احتياج
    //
    // ⚠️ لو فرعين محتاجين **نفس الكمية**، مين فيهم الأول؟ الماكرو
    //    الأصلي بيستخدم Bubble Sort غير مستقر، فالترتيب عنده عشوائي
    //    حسب ترتيب المقارنات. array_multisort مستقر، فبيدّي ترتيب
    //    ثابت. النتيجة: **الكميات مطابقة ١٠٠٪** بس اسم الفرع ممكن
    //    يتبادل بين فرعين طلبهم متساوي.
    //    (اتقاس على ٥١١٠ صف: ٥١٠٣ مطابق تماماً · ٧ اختلاف ترتيب بس.)
    array_multisort($sur,  SORT_DESC, SORT_NUMERIC, $surIdx);
    array_multisort($need, SORT_DESC, SORT_NUMERIC, $needIdx);

    $rs = $sur; $rn = $need;
    $si = 0; $ni = 0;
    while ($si < count($rs) && $ni < count($rn)) {
        $amt = min($rs[$si], $rn[$ni]);
        if ($amt > 0) {
            $from = $surIdx[$si];
            $to   = $needIdx[$ni];
            $piece = rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.')
                   . 'ق لـ' . ($names[$to] ?? ('فرع' . $to));
            $tr[$from] = $tr[$from] === '' ? $piece : $tr[$from] . '، ' . $piece;
            $rs[$si] -= $amt;
            $rn[$ni] -= $amt;
        }
        if ($rs[$si] == 0) $si++;
        if ($ni < count($rn) && $rn[$ni] == 0) $ni++;
    }

    $totS = array_sum($rs);
    $totN = array_sum($rn);
    if ($totS == 0 && $totN == 0) return ['status' => 'تمام',  'transfers' => $tr];
    if ($totN > 0)  return ['status' => $totN < $dosta ? 'تمام' : 'ناقص', 'transfers' => $tr];
    if ($totS > 0)  return ['status' => $totS < $dosta ? 'تمام' : 'راكد', 'transfers' => $tr];
    return ['status' => '', 'transfers' => $tr];
}
