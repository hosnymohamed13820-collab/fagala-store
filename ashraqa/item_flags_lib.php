<?php
/**
 * item_flags_lib.php — علامات الأصناف في الاستلامات
 * نظام الفجالة ستور المالي — 2026-08-13
 *
 * =====================================================================
 *  حاجتين منفصلتين عن بعض عن قصد:
 *
 *  (١) علامات الصنف جوه فاتورة — `invoice_item_flags`
 *      المفتاح (سجل التوزيع + كود الصنف) — **نفس مفتاح
 *      invoice_item_prices بالحرف**. يعني الملاحظة واللون بيمشوا مع
 *      الفاتورة زي التوزيع والسعر: تكتبها في تاب الفواتير المرحّلة
 *      تلاقيها في المجموعة، وتكتبها في المجموعة تنزل على الفواتير.
 *
 *  (٢) منع الصنف من ملف الفرع — `item_export_flags`
 *      المفتاح **كود الصنف** بس. ده على مستوى النظام كله: الصنف
 *      المتعلّم مابينزلش في ملف أي فرع في أي فاتورة أو مجموعة.
 *
 *      ⚠️ بالكود مش بالـ ITEM_ID — الصنف الواحد ليه باركودات بوحدات
 *         مختلفة (قطعة / علبة ١٢) وتكاليف مختلفة، وكل التوزيع في
 *         النظام ماشي بالكود. (قرار محمد 2026-08-13)
 *
 *  ⛔ الفلاجات دي **بصرية وتصديرية بس**. صفر أثر على:
 *     الكميات · التوزيع · حالة الفاتورة · الأسعار · تقارير الإكسيل.
 *     recalc_invoice_status ماتلمستش ولا هتتلمس.
 *
 *  الأداء: كل الدوال بتقرا بـ**استعلام واحد** لكل الشاشة.
 *  مفيش استعلام جوه حلقة الصفوف.
 *
 *  الجداول: local/upgrade_item_flags.sql
 * =====================================================================
 */
require_once __DIR__ . '/config.php';

/** الألوان المسموحة للتمييز اليدوي */
const IFL_COLORS = ['red', 'green', 'yellow', 'orange'];

/**
 * الجداول موجودة؟ (لو ملف الترقية لسه ماتشغّلش، الشاشة تشتغل عادي
 * من غير الميزة بدل ما تقع.)
 */
function ifl_ready(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    try {
        db()->query('SELECT 1 FROM invoice_item_flags LIMIT 1');
        $r = true;
    } catch (Throwable $ex) {
        $r = false;
    }
    return $r;
}

/**
 * تنضيف قائمة سجلات التوزيع.
 * @return int[] بالترتيب اللي وصل (الأقدم أولاً) ومن غير تكرار
 */
function ifl_ids(array $distIds): array
{
    $out = [];
    foreach ($distIds as $d) {
        $d = (int) $d;
        if ($d > 0 && !in_array($d, $out, true)) $out[] = $d;
    }
    return $out;
}

/**
 * علامات كل أصناف الشاشة — استعلام واحد.
 *
 * في الفاتورة الواحدة: `ifl_load([$distId])`.
 * في المجموعة: `ifl_load($orderedDistIds)` — الأقدم أولاً.
 *
 * لو الصنف في أكتر من فاتورة، بناخد **أول قيمة حقيقية** لكل حقل
 * على حدة بترتيب الفواتير (نفس قاعدة الأسعار: الأقدم هو المرجع).
 * الكتابة بتنشر على كل الفواتير، فالحالة الطبيعية إنهم متطابقين
 * أصلاً — الترتيب ده بيغطي بس ملاحظة اتكتبت قبل ما المجموعة تتعمل.
 *
 * @return array [item_code => ['highlight'=>string,'done_actual'=>int,'user_notes'=>string]]
 */
function ifl_load(array $distIds): array
{
    $ids = ifl_ids($distIds);
    if (!$ids || !ifl_ready()) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT distribution_id, item_code, highlight, done_actual, user_notes
             FROM invoice_item_flags
             WHERE distribution_id IN ($ph)"
        );
        $st->execute($ids);
        $rows = $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
    if (!$rows) return [];

    // رتّب الصفوف بترتيب الفواتير (الأقدم أولاً) قبل ما نطبّق الأسبقية
    $rank = array_flip($ids);
    usort($rows, fn($a, $b) =>
        ($rank[(int) $a['distribution_id']] ?? 9999) <=> ($rank[(int) $b['distribution_id']] ?? 9999)
    );

    $out = [];
    foreach ($rows as $r) {
        $c = trim((string) $r['item_code']);
        if ($c === '') continue;

        if (!isset($out[$c])) {
            $out[$c] = ['highlight' => '', 'done_actual' => 0, 'user_notes' => ''];
        }
        $hl = trim((string) ($r['highlight'] ?? ''));
        if ($out[$c]['highlight'] === '' && in_array($hl, IFL_COLORS, true)) {
            $out[$c]['highlight'] = $hl;
        }
        if ((int) $out[$c]['done_actual'] === 0 && (int) ($r['done_actual'] ?? 0) === 1) {
            $out[$c]['done_actual'] = 1;
        }
        $nt = trim((string) ($r['user_notes'] ?? ''));
        if ($out[$c]['user_notes'] === '' && $nt !== '') {
            $out[$c]['user_notes'] = $nt;
        }
    }
    return $out;
}

/**
 * حفظ علامة (أو أكتر) لصنف على كل سجلات التوزيع اللي وصلت.
 *
 * في الفاتورة الواحدة سجل واحد، وفي المجموعة كل الفواتير اللي فيها
 * الصنف — عشان تفتح الفاتورة لوحدها بعدين وتلاقي نفس الملاحظة.
 * (نفس سلوك group_save_sale_price بالظبط.)
 *
 * @param array $fields أي مجموعة من: highlight · done_actual · user_notes
 * @return int عدد الفواتير اللي اتكتب فيها
 */
function ifl_save(array $distIds, string $itemCode, array $fields, ?int $userId = null): int
{
    $itemCode = trim($itemCode);
    $ids      = ifl_ids($distIds);

    if ($itemCode === '') throw new RuntimeException('كود الصنف ناقص.');
    if (!$ids)            throw new RuntimeException('مالقيتش سجل التوزيع بتاع الصنف ده.');
    if (!ifl_ready())     throw new RuntimeException('جدول العلامات مش موجود — شغّل upgrade_item_flags.sql الأول.');

    // ---- تنضيف القيم (قايمة بيضاء — مفيش اسم عمود جاي من المستخدم) ----
    $set = [];
    if (array_key_exists('highlight', $fields)) {
        $hl = trim((string) $fields['highlight']);
        if ($hl !== '' && !in_array($hl, IFL_COLORS, true)) {
            throw new RuntimeException('لون غير معروف.');
        }
        $set['highlight'] = $hl;
    }
    if (array_key_exists('done_actual', $fields)) {
        $set['done_actual'] = ((int) $fields['done_actual']) === 1 ? 1 : 0;
    }
    if (array_key_exists('user_notes', $fields)) {
        $nt = trim((string) $fields['user_notes']);
        if (mb_strlen($nt) > 1000) $nt = mb_substr($nt, 0, 1000);
        $set['user_notes'] = $nt !== '' ? $nt : null;
    }
    if (!$set) return 0;

    $cols = array_keys($set);
    $sql  = 'INSERT INTO invoice_item_flags (distribution_id, item_code, '
          . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ', updated_by) VALUES (?, ?, '
          . implode(', ', array_fill(0, count($cols), '?')) . ', ?) '
          . 'ON DUPLICATE KEY UPDATE '
          . implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols))
          . ', updated_by = VALUES(updated_by)';

    $pdo = db();
    $st  = $pdo->prepare($sql);

    // الحلقة على **الفواتير** (أرقام قليلة: فاتورة واحدة أو فواتير
    // المجموعة) مش على الأصناف — فمفيش استعلام بيتضاعف مع الداتا.
    $n = 0;
    foreach ($ids as $d) {
        $args = [$d, $itemCode];
        foreach ($cols as $c) $args[] = $set[$c];
        $args[] = $userId;
        $st->execute($args);
        $n++;
    }
    return $n;
}

/**
 * مسح كل ألوان التمييز اليدوية في فاتورة أو مجموعة — استعلام واحد.
 * ⚠️ مابيلمسش done_actual (تم التوزيع الفعلي) ولا الملاحظات.
 *
 * @return int عدد الصفوف اللي اتغيّرت
 */
function ifl_clear_highlights(array $distIds): int
{
    $ids = ifl_ids($distIds);
    if (!$ids || !ifl_ready()) return 0;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare(
            "UPDATE invoice_item_flags SET highlight = ''
             WHERE distribution_id IN ($ph) AND highlight <> ''"
        );
        $st->execute($ids);
        return $st->rowCount();
    } catch (Throwable $ex) {
        return 0;
    }
}

/**
 * مسح كل علامات «تم التوزيع الفعلي» في فاتورة أو مجموعة.
 * زرار منفصل عن مسح الألوان عن قصد — دول حالتين مختلفتين.
 */
function ifl_clear_done(array $distIds): int
{
    $ids = ifl_ids($distIds);
    if (!$ids || !ifl_ready()) return 0;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare(
            "UPDATE invoice_item_flags SET done_actual = 0
             WHERE distribution_id IN ($ph) AND done_actual = 1"
        );
        $st->execute($ids);
        return $st->rowCount();
    } catch (Throwable $ex) {
        return 0;
    }
}

// =====================================================================
//  منع الصنف من ملف الفرع (TXT) — عالمي بالكود
// =====================================================================

/**
 * الأكواد الممنوعة من ملف الفرع من بين قايمة أكواد — استعلام واحد.
 * @return array [item_code => true] للممنوع بس
 */
function ifl_skip_map(array $codes): array
{
    $clean = [];
    foreach ($codes as $c) {
        $c = trim((string) $c);
        if ($c !== '' && !isset($clean[$c])) $clean[$c] = true;
    }
    if (!$clean) return [];

    $list = array_keys($clean);
    $ph   = implode(',', array_fill(0, count($list), '?'));
    try {
        $st = db()->prepare(
            "SELECT item_code FROM item_export_flags
             WHERE skip_export = 1 AND item_code IN ($ph)"
        );
        $st->execute($list);
    } catch (Throwable $ex) {
        return [];   // الجدول لسه ما اتعملش — كل حاجة بتنزل عادي
    }

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $out[(string) $c] = true;
    return $out;
}

/** تعليم/فك صنف من ملف الفرع. */
function ifl_set_skip(string $itemCode, bool $skip, ?int $userId = null): void
{
    $itemCode = trim($itemCode);
    if ($itemCode === '') throw new RuntimeException('كود الصنف ناقص.');

    try {
        db()->prepare(
            'INSERT INTO item_export_flags (item_code, skip_export, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE skip_export = VALUES(skip_export),
                                     updated_by  = VALUES(updated_by)'
        )->execute([$itemCode, $skip ? 1 : 0, $userId]);
    } catch (Throwable $ex) {
        throw new RuntimeException('جدول الإعدادات مش موجود — شغّل upgrade_item_flags.sql الأول.');
    }
}

/**
 * فلترة كميات ملف الفرع قبل الكتابة.
 *
 * بتتنده من branch_export.php و group_export.php **بس** — ملفات
 * الأسعار والإكسيل ماليهاش دعوة بالفلاج ده خالص (قرار محمد).
 *
 * @param array $qtyByCode [item_code => qty]
 * @return array{kept:array, skipped:string[]}
 */
function ifl_filter_export(array $qtyByCode): array
{
    if (!$qtyByCode) return ['kept' => [], 'skipped' => []];

    $skip = ifl_skip_map(array_keys($qtyByCode));
    if (!$skip) return ['kept' => $qtyByCode, 'skipped' => []];

    $kept = [];
    $out  = [];
    foreach ($qtyByCode as $code => $qty) {
        if (isset($skip[(string) $code])) { $out[] = (string) $code; continue; }
        $kept[$code] = $qty;
    }
    return ['kept' => $kept, 'skipped' => $out];
}
