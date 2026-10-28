<?php
/**
 * group_lib.php — منطق الفواتير المجمّعة
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  الغرض: توزيع أكتر من فاتورة مرة واحدة بدل ما نفتح فاتورة فاتورة.
 *
 *  المبدأ الأساسي: **المجموعة شاشة عرض بس.**
 *  مفيش أي توزيع بيتخزّن في جداول المجموعة. كل رقم بيتكتب في
 *  invoice_distribution_lines بتاع الفاتورة الأصلية — بالظبط زي ما
 *  لو المستخدم فتح كل فاتورة لوحدها. عشان كده:
 *    • مسح المجموعة مابيأثرش على أي توزيع
 *    • تقارير الفواتير وملفات الفروع بتشتغل زي ما هي
 *    • لو حد فتح الفاتورة لوحدها هيلاقي التوزيع موجود
 *
 *  توزيع الصنف المكرر (الشلال):
 *    الصنف الواحد ممكن يكون في أكتر من فاتورة. لما المستخدم يكتب
 *    كمية في الشاشة المجمعة، بنملي الفاتورة الأقدم الأول لحد ما
 *    تخلص كميتها، وبعدين اللي بعدها. أرقام صحيحة من غير كسور.
 * =====================================================================
 */
require_once __DIR__ . '/receiving_lib.php';

/**
 * المجموعة المفتوحة الحالية (مشتركة بين كل المستخدمين).
 * @return array|null
 */
function group_current(): ?array
{
    try {
        $row = db()->query(
            "SELECT * FROM invoice_groups WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}

/** مجموعة واحدة بالرقم */
function group_get(int $groupId): ?array
{
    if ($groupId <= 0) return null;
    try {
        $st = db()->prepare('SELECT * FROM invoice_groups WHERE id = ? LIMIT 1');
        $st->execute([$groupId]);
        return $st->fetch() ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}

/**
 * كل المجموعات المفتوحة مع ملخص لكل واحدة (لشاشة الكروت).
 * الأرقام بتتحسب باستعلامات مجمّعة — مش بنحمّل بنود كل مجموعة.
 */
function group_list(): array
{
    try {
        $groups = db()->query(
            "SELECT g.*, u.full_name AS creator,
                    (SELECT COUNT(*) FROM invoice_group_items i WHERE i.group_id = g.id) AS inv_count
             FROM invoice_groups g
             LEFT JOIN users u ON u.id = g.created_by
             WHERE g.status = 'open'
             ORDER BY g.id DESC"
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }

    if (empty($groups)) {
        return [];
    }

    $allGids = array_map(fn($g) => (int) $g['id'], $groups);
    $inAll = implode(',', $allGids);

    // إصلاح المجموعات التي تحتوي على فواتير اترحّلت (للمجموعات المتأثرة فقط وقبل الإحصائيات)
    try {
        $orphanGids = db()->query(
            "SELECT DISTINCT i.group_id
             FROM invoice_group_items i
             LEFT JOIN hq_unposted u ON u.sth_id = i.sth_id
             WHERE i.group_id IN ($inAll) AND i.suffix = 'YY_MM' AND u.sth_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orphanGids as $ogid) {
            group_heal_posted((int) $ogid);
        }
    } catch (Throwable $ex) {
        // لو الاستعلام وقع نكمل
    }

    $itemsGids = [];
    $fullGids  = [];
    foreach ($groups as $g) {
        if (((string) ($g['group_type'] ?? 'full')) === 'items') {
            $itemsGids[] = (int) $g['id'];
        } else {
            $fullGids[]  = (int) $g['id'];
        }
    }

    // تجهيز استعلامات الإحصائيات المجمعة
    $itemsTotPostedMap   = [];
    $itemsTotUnpostedMap = [];
    $fullTotPostedMap    = [];
    $fullTotUnpostedMap  = [];
    $itemsDoneMap        = [];
    $fullDoneMap         = [];
    $selCountMap         = [];
    $itemsDataIncomplete = false;
    $fullDataIncomplete  = false;

    if (!empty($itemsGids)) {
        $inItems = implode(',', $itemsGids);
        try {
            $st = db()->query(
                "SELECT gl.group_id, COALESCE(SUM(l.quantity),0) AS qty
                 FROM invoice_group_lines gl
                 JOIN hq_invoice_lines l
                   ON l.sth_id = gl.sth_id AND l.suffix = gl.suffix AND l.item_code = gl.item_code
                 WHERE gl.group_id IN ($inItems)
                 GROUP BY gl.group_id"
            );
            while ($r = $st->fetch()) {
                $itemsTotPostedMap[(int) $r['group_id']] = (float) $r['qty'];
            }
        } catch (Throwable $ex) {
            $itemsDataIncomplete = true;
            error_log('group_list items posted total: ' . $ex->getMessage());
        }

        try {
            $st = db()->query(
                "SELECT gl.group_id, COALESCE(SUM(u.quantity),0) AS qty
                 FROM invoice_group_lines gl
                 JOIN hq_unposted_lines u
                   ON u.sth_id = gl.sth_id AND u.item_code = gl.item_code
                 WHERE gl.group_id IN ($inItems) AND gl.suffix = 'YY_MM'
                 GROUP BY gl.group_id"
            );
            while ($r = $st->fetch()) {
                $itemsTotUnpostedMap[(int) $r['group_id']] = (float) $r['qty'];
            }
        } catch (Throwable $ex) {
            $itemsDataIncomplete = true;
            error_log('group_list items unposted total: ' . $ex->getMessage());
        }

        try {
            $st = db()->query(
                "SELECT i.group_id,
                        COALESCE(SUM(dl.amount),0) AS q,
                        COUNT(DISTINCT dl.branch_id) AS b
                 FROM invoice_group_items i
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 JOIN invoice_distribution_lines dl ON dl.distribution_id = d.id
                 JOIN invoice_group_lines gl
                   ON gl.group_id = i.group_id AND gl.sth_id = i.sth_id
                  AND gl.suffix = i.suffix AND gl.item_code = dl.notes
                 WHERE i.group_id IN ($inItems)
                 GROUP BY i.group_id"
            );
            while ($r = $st->fetch()) {
                $itemsDoneMap[(int) $r['group_id']] = [
                    'q' => (float) $r['q'],
                    'b' => (int) $r['b'],
                ];
            }
        } catch (Throwable $ex) {
            $itemsDataIncomplete = true;
            error_log('group_list items distributed total: ' . $ex->getMessage());
        }

        try {
            $st = db()->query(
                "SELECT group_id, COUNT(DISTINCT item_code) AS cnt
                 FROM invoice_group_lines
                 WHERE group_id IN ($inItems)
                 GROUP BY group_id"
            );
            while ($r = $st->fetch()) {
                $selCountMap[(int) $r['group_id']] = (int) $r['cnt'];
            }
        } catch (Throwable $ex) {
            $itemsDataIncomplete = true;
            error_log('group_list items selected count: ' . $ex->getMessage());
        }
    }

    if (!empty($fullGids)) {
        $inFull = implode(',', $fullGids);
        try {
            $st = db()->query(
                "SELECT i.group_id, COALESCE(SUM(l.quantity),0) AS qty
                 FROM invoice_group_items i
                 JOIN hq_invoice_lines l ON l.sth_id = i.sth_id AND l.suffix = i.suffix
                 WHERE i.group_id IN ($inFull)
                 GROUP BY i.group_id"
            );
            while ($r = $st->fetch()) {
                $fullTotPostedMap[(int) $r['group_id']] = (float) $r['qty'];
            }
        } catch (Throwable $ex) {
            $fullDataIncomplete = true;
            error_log('group_list full posted total: ' . $ex->getMessage());
        }

        try {
            $st = db()->query(
                "SELECT i.group_id, COALESCE(SUM(l.quantity),0) AS qty
                 FROM invoice_group_items i
                 JOIN hq_unposted_lines l ON l.sth_id = i.sth_id
                 WHERE i.group_id IN ($inFull) AND i.suffix = 'YY_MM'
                 GROUP BY i.group_id"
            );
            while ($r = $st->fetch()) {
                $fullTotUnpostedMap[(int) $r['group_id']] = (float) $r['qty'];
            }
        } catch (Throwable $ex) {
            $fullDataIncomplete = true;
            error_log('group_list full unposted total: ' . $ex->getMessage());
        }

        try {
            $st = db()->query(
                "SELECT i.group_id,
                        COALESCE(SUM(dl.amount),0) AS q,
                        COUNT(DISTINCT dl.branch_id) AS b
                 FROM invoice_group_items i
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 JOIN invoice_distribution_lines dl ON dl.distribution_id = d.id
                 WHERE i.group_id IN ($inFull)
                 GROUP BY i.group_id"
            );
            while ($r = $st->fetch()) {
                $fullDoneMap[(int) $r['group_id']] = [
                    'q' => (float) $r['q'],
                    'b' => (int) $r['b'],
                ];
            }
        } catch (Throwable $ex) {
            $fullDataIncomplete = true;
            error_log('group_list full distributed total: ' . $ex->getMessage());
        }
    }

    foreach ($groups as &$g) {
        $gid = (int) $g['id'];
        $isItems = ((string) ($g['group_type'] ?? 'full')) === 'items';

        if ($isItems) {
            $tot = ($itemsTotPostedMap[$gid] ?? 0.0) + ($itemsTotUnpostedMap[$gid] ?? 0.0);
            $done = (float) ($itemsDoneMap[$gid]['q'] ?? 0.0);
            $branches = (int) ($itemsDoneMap[$gid]['b'] ?? 0);
            $g['sel_count'] = (int) ($selCountMap[$gid] ?? 0);
        } else {
            $tot = ($fullTotPostedMap[$gid] ?? 0.0) + ($fullTotUnpostedMap[$gid] ?? 0.0);
            $done = (float) ($fullDoneMap[$gid]['q'] ?? 0.0);
            $branches = (int) ($fullDoneMap[$gid]['b'] ?? 0);
            $g['sel_count'] = 0;
        }

        $g['is_items']  = $isItems;
        $g['total_qty'] = $tot;
        $g['done_qty']  = $done;
        $g['branches']  = $branches;
        $g['pct']       = $tot > 0 ? min(100, round($done / $tot * 100)) : 0;
        $g['data_incomplete'] = $isItems ? $itemsDataIncomplete : $fullDataIncomplete;
    }
    unset($g);

    return $groups;
}

/**
 * ⚠️ إصلاح فواتير المجموعة اللي اترحّلت — 2026-08-11
 *
 * المشكلة:
 *   الفاتورة غير المحفوظة بتتخزّن في المجموعة بـ suffix = 'YY_MM'
 *   (جدول القالب STO_TRANS_HEADER_YY_MM). لما نظام الشركة يرحّلها،
 *   بتختفي من hq_unposted وبتظهر في hq_invoices بـ suffix حقيقي
 *   (26_08 مثلاً). ساعتها sqlsrv_fetch_invoice_header بـ'YY_MM'
 *   بيدوّر في hq_unposted بس ومابيلاقيهاش → بترجّع null →
 *   group_load بترميها في 'missing' → **الفاتورة تختفي من المجموعة**.
 *
 * الحل: نعيد ربطها بنفسها. بندوّر عليها في hq_invoices برقم
 * المستند (doc_no) — ده الرقم اللي مابيتغيّرش بالترحيل، ومفتاح
 * الربط مع invoice_distribution بيعتمد على dist_key_sql
 * لربط company_branch و doc_type بدقة.
 *
 * ✅ التوزيع والأسعار مابيضيعوش: invoice_distribution مفتاحه
 *    المركب (doc_no, company_branch, doc_type) مش sth_id/suffix،
 *    فكل الكميات على الفروع والأسعار المحفوظة بتفضل مربوطة زي ما هي.
 *
 * الأداء: استعلام واحد مفهرس بيرجّع فاضي في الحالة الطبيعية،
 * وبيتنده مرة واحدة لكل مجموعة في الريكوست.
 *
 * @return int عدد الفواتير اللي اترجّع ربطها
 */
function group_heal_posted(int $groupId): int
{
    static $done = [];
    if ($groupId <= 0 || isset($done[$groupId])) return 0;
    $done[$groupId] = true;

    $pdo = db();

    try {
        // فواتير المجموعة المعلّمة «غير محفوظة» ومش موجودة في hq_unposted
        $st = $pdo->prepare(
            "SELECT i.id, i.sth_id, i.invoice_no, i.supplier_name, i.invoice_date
             FROM invoice_group_items i
             LEFT JOIN hq_unposted u ON u.sth_id = i.sth_id
             WHERE i.group_id = ? AND i.suffix = 'YY_MM' AND u.sth_id IS NULL"
        );
        $st->execute([$groupId]);
        $orphans = $st->fetchAll();
    } catch (Throwable $ex) {
        return 0;   // الجداول لسه ما اتعملتش
    }

    if (!$orphans) return 0;

    // المرآة لازم تكون جاهزة، وإلا ممكن نعتبر فاتورة موجودة كأنها ضايعة
    try {
        $ready = (bool) $pdo->query('SELECT 1 FROM hq_unposted LIMIT 1')->fetchColumn()
              || (bool) $pdo->query('SELECT 1 FROM hq_invoices LIMIT 1')->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
    if (!$ready) return 0;

    $find = $pdo->prepare(
        'SELECT sth_id, suffix, supplier_name, invoice_date, company_branch, doc_type
         FROM hq_invoices
         WHERE doc_no = ?
         ORDER BY (supplier_name = ?) DESC, ABS(DATEDIFF(invoice_date, ?)) ASC, suffix DESC
         LIMIT 1'
    );
    $upd = null;
    // ⚠️ لازم نصلّح أسطر الأصناف المختارة كمان (مجموعة الأصناف) —
    //    وإلا الفلتر هيدوّر بـ suffix='YY_MM' والفاتورة بقت 26_08،
    //    فالأصناف هتختفي من المجموعة بعد الترحيل.
    $updL = $pdo->prepare(
        "UPDATE IGNORE invoice_group_lines SET sth_id = ?, suffix = ?
         WHERE group_id = ? AND sth_id = ? AND suffix = 'YY_MM'"
    );

    $healed = 0;
    foreach ($orphans as $o) {
        $docNo = trim((string) $o['invoice_no']);
        if ($docNo === '') continue;

        $find->execute([$docNo, (string) $o['supplier_name'], (string) $o['invoice_date']]);
        $hit = $find->fetch();
        if (!$hit) continue;   // اتلغت أو لسه ما وصلتش المرآة — تفضل زي ما هي

        try {
            $upd = $pdo->prepare('UPDATE invoice_group_items SET sth_id = ?, suffix = ?, company_branch = ?, doc_type = ? WHERE id = ?');
            $upd->execute([(int) $hit['sth_id'], (string) $hit['suffix'], $hit['company_branch'] ?? null, $hit['doc_type'] ?? null, (int) $o['id']]);
        } catch (Throwable $ex) {
            $upd = $pdo->prepare('UPDATE invoice_group_items SET sth_id = ?, suffix = ? WHERE id = ?');
            $upd->execute([(int) $hit['sth_id'], (string) $hit['suffix'], (int) $o['id']]);
        }
        try {
            $updL->execute([
                (int) $hit['sth_id'], (string) $hit['suffix'],
                $groupId, (int) $o['sth_id'],
            ]);
        } catch (Throwable $ex) {
            // جدول الأصناف المختارة لسه ما اتعملش — مجموعة فواتير كاملة
        }
        $healed++;

        error_log(sprintf(
            'group_heal_posted: فاتورة %s اترحّلت — الربط اتغيّر من (%d|YY_MM) لـ (%d|%s)',
            $docNo, (int) $o['sth_id'], (int) $hit['sth_id'], (string) $hit['suffix']
        ));
    }

    return $healed;
}

// =====================================================================
//  أنواع المجموعات — 2026-08-14
//  ---------------------------------------------------------------
//   full  = مجموعة فواتير كاملة (السلوك القديم بالحرف)
//   items = مجموعة أصناف محددة من فواتير مختلفة
//
//  🔑 فكرة التصميم: `invoice_group_items` فضل زي ما هو = قايمة فواتير
//     المجموعة، وكل الميكانيكا القديمة شغالة عليه. الجدول الجديد
//     `invoice_group_lines` مجرد **فلتر** على الأصناف.
//     مجموعة `full` = صفر أسطر فلتر = كل الأصناف = السلوك القديم.
// =====================================================================

/**
 * كاش الريكوست (النوع + فلتر الأصناف).
 *
 * ⚠️ لازم يتفضّى بعد أي كتابة على المجموعة — الجلسة اللي بتضيف أصناف
 *    وبعدين بتقرا المجموعة في نفس الريكوست كانت بتشوف الفلتر القديم
 *    (الفاضي) فالشاشة تطلع من غير أصناف خالص. (اتكشف في الاختبار)
 */
function &group_cache_ref(): array
{
    static $c = ['type' => [], 'sel' => []];
    return $c;
}

/** فضّي كاش مجموعة بعد أي تعديل عليها */
function group_cache_flush(int $groupId): void
{
    $c =& group_cache_ref();
    unset($c['type'][$groupId], $c['sel'][$groupId]);
}

/** نوع المجموعة — 'full' افتراضيًا لأي مجموعة قديمة أو لو العمود لسه مش موجود */
function group_type(int $groupId): string
{
    if ($groupId <= 0) return 'full';
    $c =& group_cache_ref();
    if (isset($c['type'][$groupId])) return $c['type'][$groupId];

    try {
        $st = db()->prepare('SELECT group_type FROM invoice_groups WHERE id = ?');
        $st->execute([$groupId]);
        $t = (string) $st->fetchColumn();
    } catch (Throwable $ex) {
        $t = '';   // upgrade_group_types.sql لسه ماتشغّلش
    }
    return $c['type'][$groupId] = ($t === 'items' ? 'items' : 'full');
}

/** المجموعة دي مجموعة أصناف محددة؟ */
function group_is_items(int $groupId): bool
{
    return group_type($groupId) === 'items';
}

/**
 * فلتر الأصناف المختارة.
 * @return array ["sth_id|suffix" => [item_code => true]]
 *               فاضي = مفيش فلتر (مجموعة فواتير كاملة)
 */
function group_selected_map(int $groupId): array
{
    if ($groupId <= 0) return [];
    $c =& group_cache_ref();
    if (isset($c['sel'][$groupId])) return $c['sel'][$groupId];

    $out = [];
    try {
        $st = db()->prepare(
            'SELECT sth_id, suffix, item_code FROM invoice_group_lines WHERE group_id = ?'
        );
        $st->execute([$groupId]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['sth_id'] . '|' . (string) $r['suffix']][(string) $r['item_code']] = true;
        }
    } catch (Throwable $ex) {
        return $c['sel'][$groupId] = [];
    }
    return $c['sel'][$groupId] = $out;
}

/**
 * الصنف ده جوه المجموعة؟
 * في مجموعة الفواتير الكاملة الإجابة دايمًا نعم.
 */
function group_line_allowed(int $groupId, int $sthId, string $suffix, string $code): bool
{
    if (!group_is_items($groupId)) return true;
    $map = group_selected_map($groupId);
    return !empty($map[$sthId . '|' . $suffix][$code]);
}

/** كل أكواد المجموعة المختارة (لفلترة ملفات التصدير) — فاضي = كل الأصناف */
function group_selected_codes(int $groupId): array
{
    if (!group_is_items($groupId)) return [];
    $out = [];
    foreach (group_selected_map($groupId) as $codes) {
        foreach (array_keys($codes) as $c) $out[$c] = true;
    }
    return $out;
}

/**
 * أكواد فاتورة معيّنة جوه المجموعة — فاضي = كل أصناف الفاتورة.
 * بتستخدمها ملفات التصدير عشان ملف الفاتورة المفردة جوه المجموعة
 * يطلع بالأصناف المختارة بس (قرار محمد: الاختيار «أ»).
 */
function group_invoice_codes(int $groupId, int $sthId, string $suffix): array
{
    if (!group_is_items($groupId)) return [];
    $map = group_selected_map($groupId);
    return $map[$sthId . '|' . $suffix] ?? ['__none__' => true];
}

/** كل أصناف فاتورة من المرآة — [code => true] (بيستخدمها إضافة فاتورة كاملة) */
function group_fetch_invoice_codes(int $sthId, string $suffix, ?array $lines = null): array
{
    $out = [];
    $items = ($lines !== null) ? $lines : sqlsrv_fetch_invoice_items($sthId, $suffix ?: null);
    foreach ($items as $ln) {
        $c = trim((string) ($ln['item_code'] ?? ''));
        if ($c !== '') $out[$c] = true;
    }
    return $out;
}

/**
 * إضافة فاتورة (أو أكتر) كاملة لمجموعة موجودة.
 *
 * في مجموعة الأصناف: كل أصناف الفاتورة بتدخل الفلتر — لأن «إضافة
 * فاتورة كاملة» معناها إن أصنافها كلها بقت جزء من المجموعة.
 *
 * @param array $picks [['sth_id'=>..,'suffix'=>..], ...]
 * @return int عدد الفواتير اللي اتضافت فعلاً (المكرر مابيتحسبش)
 */
function group_add_invoices(int $groupId, array $picks, ?int $userId = null): int
{
    $g = group_get($groupId);
    if (!$g) throw new RuntimeException('المجموعة مش موجودة.');

    $isItems = group_is_items($groupId);

    // الموجود بالفعل (منع التكرار)
    $have = [];
    foreach (group_invoices($groupId) as $inv) {
        $have[(int) $inv['sth_id'] . '|' . (string) $inv['suffix']] = true;
    }

    // جلب الهيدرات والتوزيع مجمّعاً للـ picks غير الموجودة
    $pairs = [];
    foreach ($picks as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = trim((string) ($p['suffix'] ?? ''));
        if ($sth > 0 && !isset($have[$sth . '|' . $suf])) {
            $pairs[$sth . '|' . $suf] = ['sth_id' => $sth, 'suffix' => $suf];
        }
    }
    $headersMap = $pairs ? group_fetch_headers(array_values($pairs)) : [];
    $distPre = group_dist_existing(array_filter($headersMap));

    // في مجموعة الأصناف: جلب السطور مجمّعاً بالـ suffix الخاص بكل هيدر
    $linesPairs = [];
    if ($isItems && !empty($headersMap)) {
        foreach ($pairs as $p) {
            $key = $p['sth_id'] . '|' . $p['suffix'];
            $h = $headersMap[$key] ?? null;
            if ($h) {
                $realSuf = (string) ($h['suffix'] ?? $p['suffix']);
                $linesPairs[$p['sth_id'] . '|' . $realSuf] = ['sth_id' => $p['sth_id'], 'suffix' => $realSuf];
            }
        }
    }
    $linesMap = $linesPairs ? group_fetch_lines(array_values($linesPairs)) : [];

    // أعلى sort_order حالي — الجديد بيتحط بعده
    $maxSort = 0;
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM invoice_group_items WHERE group_id = ?');
        $st->execute([$groupId]);
        $maxSort = (int) $st->fetchColumn() + 1;
    } catch (Throwable $ex) { /* تجاهل */ }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insNew = null;
        try {
            $insNew = $pdo->prepare(
                'INSERT IGNORE INTO invoice_group_items
                    (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order, company_branch, doc_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
        } catch (Throwable $ex) {}
        $insOld = $pdo->prepare(
            'INSERT IGNORE INTO invoice_group_items
                (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insL = $isItems ? $pdo->prepare(
            'INSERT IGNORE INTO invoice_group_lines
                (group_id, sth_id, suffix, item_code, added_by) VALUES (?, ?, ?, ?, ?)'
        ) : null;

        $n = 0;
        foreach ($picks as $p) {
            $sth = (int) ($p['sth_id'] ?? 0);
            if ($sth <= 0) continue;
            $suf = trim((string) ($p['suffix'] ?? ''));
            if (isset($have[$sth . '|' . $suf])) continue;   // موجودة أصلاً

            $key = $sth . '|' . $suf;
            $h = $headersMap[$key] ?? null;
            if (!$h) continue;                                // اتلغت أو مش في المرآة
            $suf = (string) ($h['suffix'] ?? $suf);

            $cb = $h['company_branch'] ?? ($h['company_branch_id'] ?? null);
            $dt = $h['doc_type'] ?? null;
            $inserted = false;
            if ($insNew) {
                try {
                    $insNew->execute([
                        $groupId, $sth, $suf, (string) ($h['inv_no'] ?? ''),
                        (string) ($h['supplier_name'] ?? ''),
                        !empty($h['invoice_date']) ? date('Y-m-d H:i:s', strtotime((string) $h['invoice_date'])) : null,
                        $maxSort++,
                        $cb, $dt,
                    ]);
                    $inserted = true;
                } catch (Throwable $ex) {
                    $insNew = null;
                }
            }
            if (!$inserted) {
                $insOld->execute([
                    $groupId, $sth, $suf, (string) ($h['inv_no'] ?? ''),
                    (string) ($h['supplier_name'] ?? ''),
                    !empty($h['invoice_date']) ? date('Y-m-d H:i:s', strtotime((string) $h['invoice_date'])) : null,
                    $maxSort++,
                ]);
            }

            $k = group_dist_key($h);
            if ($k === null || !isset($distPre[$k])) {
                ensure_distribution_record($h, $userId);
            }

            // مجموعة أصناف → أصناف الفاتورة كلها تدخل الفلتر
            if ($insL) {
                $lines = $linesMap[$sth . '|' . $suf] ?? null;
                foreach (array_keys(group_fetch_invoice_codes($sth, $suf, $lines)) as $c) {
                    $insL->execute([$groupId, $sth, $suf, $c, $userId]);
                }
            }
            $have[$sth . '|' . $suf] = true;
            $n++;
        }

        $pdo->commit();
        group_cache_flush($groupId);
        return $n;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إضافة أصناف محددة لمجموعة.
 *
 * ⛔ ممنوع على مجموعة الفواتير الكاملة — القاعدة اللي طلبها محمد:
 *    المجموعة اللي بدأت «فواتير كاملة» تفضل كده للأبد.
 *
 * @param array $picks [['sth_id'=>..,'suffix'=>..,'item_code'=>..], ...]
 * @return array{added:int, dup:int, bad:int}
 */
function group_add_items(int $groupId, array $picks, ?int $userId = null): array
{
    $g = group_get($groupId);
    if (!$g) throw new RuntimeException('المجموعة مش موجودة.');

    if (!group_is_items($groupId)) {
        throw new RuntimeException(
            'المجموعة دي «فواتير كاملة» — مينفعش تضيف صنف منفرد فيها. '
            . 'تقدر تضيف فاتورة كاملة، أو تعمل مجموعة أصناف جديدة.'
        );
    }

    // الموجود من الأصناف (عشان نعد المكرر ونقول للمستخدم)
    $sel = group_selected_map($groupId);

    // فواتير المجموعة الحالية
    $have = [];
    foreach (group_invoices($groupId) as $inv) {
        $have[(int) $inv['sth_id'] . '|' . (string) $inv['suffix']] = true;
    }

    // جلب الهيدرات للأزواج المميزة غير الموجودة في have
    $pairs = [];
    foreach ($picks as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = trim((string) ($p['suffix'] ?? ''));
        if ($sth > 0 && empty($have[$sth . '|' . $suf])) {
            $pairs[$sth . '|' . $suf] = ['sth_id' => $sth, 'suffix' => $suf];
        }
    }
    $headersMap = $pairs ? group_fetch_headers(array_values($pairs)) : [];
    $distPre = group_dist_existing(array_filter($headersMap));

    $maxSort = 0;
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM invoice_group_items WHERE group_id = ?');
        $st->execute([$groupId]);
        $maxSort = (int) $st->fetchColumn() + 1;
    } catch (Throwable $ex) { /* تجاهل */ }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insINew = null;
        try {
            $insINew = $pdo->prepare(
                'INSERT IGNORE INTO invoice_group_items
                    (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order, company_branch, doc_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
        } catch (Throwable $ex) {}
        $insIOld = $pdo->prepare(
            'INSERT IGNORE INTO invoice_group_items
                (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insL = $pdo->prepare(
            'INSERT IGNORE INTO invoice_group_lines
                (group_id, sth_id, suffix, item_code, added_by) VALUES (?, ?, ?, ?, ?)'
        );

        $added = 0; $dup = 0; $bad = 0;
        foreach ($picks as $p) {
            $sth  = (int) ($p['sth_id'] ?? 0);
            $suf  = trim((string) ($p['suffix'] ?? ''));
            $code = trim((string) ($p['item_code'] ?? ''));
            if ($sth <= 0 || $code === '') { $bad++; continue; }

            if (!empty($sel[$sth . '|' . $suf][$code])) { $dup++; continue; }

            // الفاتورة لازم تكون جوه المجموعة الأول (عشان سجل التوزيع)
            if (empty($have[$sth . '|' . $suf])) {
                $h = $headersMap[$sth . '|' . $suf] ?? null;
                if (!$h) { $bad++; continue; }               // فاتورة اتحذفت/اتعدّلت
                $realSuf = (string) ($h['suffix'] ?? $suf);
                $cb = $h['company_branch'] ?? ($h['company_branch_id'] ?? null);
                $dt = $h['doc_type'] ?? null;
                $inserted = false;
                if ($insINew) {
                    try {
                        $insINew->execute([
                            $groupId, $sth, $realSuf, (string) ($h['inv_no'] ?? ''),
                            (string) ($h['supplier_name'] ?? ''),
                            !empty($h['invoice_date']) ? date('Y-m-d H:i:s', strtotime((string) $h['invoice_date'])) : null,
                            $maxSort++,
                            $cb, $dt,
                        ]);
                        $inserted = true;
                    } catch (Throwable $ex) {
                        $insINew = null;
                    }
                }
                if (!$inserted) {
                    $insIOld->execute([
                        $groupId, $sth, $realSuf, (string) ($h['inv_no'] ?? ''),
                        (string) ($h['supplier_name'] ?? ''),
                        !empty($h['invoice_date']) ? date('Y-m-d H:i:s', strtotime((string) $h['invoice_date'])) : null,
                        $maxSort++,
                    ]);
                }
                $k = group_dist_key($h);
                if ($k === null || !isset($distPre[$k])) {
                    ensure_distribution_record($h, $userId);
                }
                $have[$sth . '|' . $realSuf] = true;
                $suf = $realSuf;
            }

            $insL->execute([$groupId, $sth, $suf, $code, $userId]);
            if ($insL->rowCount() > 0) { $added++; $sel[$sth . '|' . $suf][$code] = true; }
            else                        { $dup++; }
        }

        $pdo->commit();
        group_cache_flush($groupId);
        return ['added' => $added, 'dup' => $dup, 'bad' => $bad];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** فواتير المجموعة مرتّبة بالأقدم (ترتيب الشلال) */
function group_invoices(int $groupId): array
{
    if ($groupId <= 0) return [];

    // صلّح ربط أي فاتورة اترحّلت قبل ما نقرا — وإلا هتبان «ضايعة»
    group_heal_posted($groupId);

    try {
        $st = db()->prepare(
            'SELECT * FROM invoice_group_items WHERE group_id = ?
             ORDER BY sort_order, invoice_date, id'
        );
        $st->execute([$groupId]);
        return $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * إنشاء مجموعة من قائمة فواتير مختارة.
 * كل عنصر: ['sth_id' => .., 'suffix' => ..]
 */
function group_create(array $picks, ?int $userId, ?string $name = null, string $type = 'full'): int
{
    $type = $type === 'items' ? 'items' : 'full';
    // تنضيف + إزالة التكرار
    $clean = [];
    foreach ($picks as $p) {
        if (!is_array($p)) continue;
        $sth = (int) ($p['sth_id'] ?? 0);
        if ($sth <= 0) continue;
        $suf = trim((string) ($p['suffix'] ?? ''));
        $clean[$sth . '|' . $suf] = ['sth_id' => $sth, 'suffix' => $suf];
    }
    $clean = array_values($clean);

    if (count($clean) < 2) {
        throw new RuntimeException('اختار فاتورتين على الأقل — الفاتورة الواحدة افتحها من تاب الفواتير عادي.');
    }

    // جلب الهيدرات (وترتيبها بالتاريخ — الأقدم الأول)
    $rawHeaders = group_fetch_headers($clean);
    $headers = [];
    foreach ($clean as $c) {
        $k = $c['sth_id'] . '|' . $c['suffix'];
        $h = $rawHeaders[$k] ?? null;
        if (!$h) continue;
        $headers[] = [
            'sth_id'        => $c['sth_id'],
            'suffix'        => (string) ($h['suffix'] ?? $c['suffix']),
            'invoice_no'    => (string) ($h['inv_no'] ?? ''),
            'supplier_name' => (string) ($h['supplier_name'] ?? ''),
            'invoice_date'  => (string) ($h['invoice_date'] ?? ''),
            'header'        => $h,
        ];
    }
    if (count($headers) < 2) {
        throw new RuntimeException('مقدرناش نجيب بيانات الفواتير المختارة.');
    }

    $distPre = group_dist_existing(array_column($headers, 'header'));

    usort($headers, fn($a, $b) => strcmp((string)$a['invoice_date'], (string)$b['invoice_date']));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // المجموعات المفتوحة بتفضل مفتوحة — ممكن تشتغل على أكتر من
        // مجموعة بالتوازي وتدخل على اللي عايزها من شاشة الكروت.
        try {
            $pdo->prepare('INSERT INTO invoice_groups (name, created_by, group_type) VALUES (?, ?, ?)')
                ->execute([$name ?: null, $userId, $type]);
        } catch (Throwable $ex) {
            // upgrade_group_types.sql لسه ماتشغّلش — نرجع للسلوك القديم
            $pdo->prepare('INSERT INTO invoice_groups (name, created_by) VALUES (?, ?)')
                ->execute([$name ?: null, $userId]);
        }
        $gid = (int) $pdo->lastInsertId();

        $insNew = null;
        try {
            $insNew = $pdo->prepare(
                'INSERT INTO invoice_group_items
                    (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order, company_branch, doc_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
        } catch (Throwable $ex) {}
        $insOld = $pdo->prepare(
            'INSERT INTO invoice_group_items
                (group_id, sth_id, suffix, invoice_no, supplier_name, invoice_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($headers as $i => $h) {
            $cb = $h['header']['company_branch'] ?? ($h['header']['company_branch_id'] ?? null);
            $dt = $h['header']['doc_type'] ?? null;
            $inserted = false;
            if ($insNew) {
                try {
                    $insNew->execute([
                        $gid, $h['sth_id'], $h['suffix'], $h['invoice_no'],
                        $h['supplier_name'],
                        $h['invoice_date'] ? date('Y-m-d H:i:s', strtotime($h['invoice_date'])) : null,
                        $i,
                        $cb, $dt,
                    ]);
                    $inserted = true;
                } catch (Throwable $ex) {
                    $insNew = null;
                }
            }
            if (!$inserted) {
                $insOld->execute([
                    $gid, $h['sth_id'], $h['suffix'], $h['invoice_no'],
                    $h['supplier_name'],
                    $h['invoice_date'] ? date('Y-m-d H:i:s', strtotime($h['invoice_date'])) : null,
                    $i,
                ]);
            }
            // سجل التوزيع لكل فاتورة لازم يبقى موجود من دلوقتي
            $k = group_dist_key($h['header']);
            if ($k === null || !isset($distPre[$k])) {
                ensure_distribution_record($h['header'], $userId);
            }
        }

        $pdo->commit();
        return $gid;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إنشاء **مجموعة أصناف** من أصناف مختارة من فواتير مختلفة.
 *
 * بيعمل المجموعة بنوع items، بيضيف الفواتير المعنية في
 * invoice_group_items (عشان الميكانيكا القديمة تشتغل)، وبيضيف
 * الأصناف المختارة في invoice_group_lines (الفلتر).
 *
 * @param array $picks [['sth_id'=>..,'suffix'=>..,'item_code'=>..], ...]
 */
function group_create_items(array $picks, ?int $userId, ?string $name = null): int
{
    // تنضيف + إزالة التكرار بهوية السطر: (الفاتورة + الكود)
    $clean = [];
    foreach ($picks as $p) {
        if (!is_array($p)) continue;
        $sth  = (int) ($p['sth_id'] ?? 0);
        $code = trim((string) ($p['item_code'] ?? ''));
        if ($sth <= 0 || $code === '') continue;
        $suf  = trim((string) ($p['suffix'] ?? ''));
        $clean[$sth . '|' . $suf . '|' . $code] = [
            'sth_id' => $sth, 'suffix' => $suf, 'item_code' => $code,
        ];
    }
    if (!$clean) throw new RuntimeException('اختار صنف واحد على الأقل.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO invoice_groups (name, created_by, group_type) VALUES (?, ?, ?)')
            ->execute([$name ?: null, $userId, 'items']);
        $gid = (int) $pdo->lastInsertId();
        $pdo->commit();
        group_cache_flush($gid);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('شغّل upgrade_group_types.sql الأول — عمود نوع المجموعة مش موجود.');
    }

    try {
        $res = group_add_items($gid, array_values($clean), $userId);
        if ($res['added'] < 1) {
            group_delete($gid);
            throw new RuntimeException('مقدرناش نضيف أي صنف — يمكن الفواتير اتغيّرت من نظام الشركة.');
        }
    } catch (Throwable $ex) {
        group_delete($gid);
        throw $ex;
    }
    return $gid;
}

/**
 * مسح المجموعة. ⚠️ التوزيع اللي اتعمل بيفضل محفوظ في الفواتير —
 * اللي بيتمسح هو تجميعة الشغل بس.
 */
/**
 * شيل فاتورة من مجموعة.
 *
 * **مابيمسحش أي توزيع.** نفس مبدأ `group_delete()`: المجموعة تجميعة
 * شغل، وشيل حاجة منها مابيأثرش على `invoice_distribution_lines`.
 * الفاتورة بترجع تشتغل عادي من تاب الفواتير بتوزيعها كامل، ولو
 * رجّعتها للمجموعة تاني هتلاقي شغلك زي ما هو.
 *
 * في مجموعة الأصناف بيشيل كمان سطور الفاتورة دي من `invoice_group_lines`
 * — لأن سيبها هيخلّي فلتر التصدير يدوّر على أصناف فاتورة مش موجودة.
 *
 * @return bool اتشالت فعلاً؟
 * @throws RuntimeException لو دي آخر فاتورة
 */
function group_remove_invoice(int $groupId, int $sthId, string $suffix): bool
{
    if ($groupId <= 0 || $sthId <= 0) return false;

    $g = group_get($groupId);
    if (!$g) throw new RuntimeException('المجموعة مش موجودة.');

    // آخر فاتورة؟ مجموعة من غير فواتير مالهاش أي معنى — امسح المجموعة نفسها
    if (count(group_invoices($groupId)) <= 1) {
        throw new RuntimeException(
            'دي آخر فاتورة في المجموعة. لو عايز تخلص منها كلها، استخدم «امسح المجموعة».'
        );
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // الأصناف الأول (لو مجموعة أصناف) — مافيش ON DELETE بينهم
        $pdo->prepare(
            'DELETE FROM invoice_group_lines
             WHERE group_id = ? AND sth_id = ? AND suffix = ?'
        )->execute([$groupId, $sthId, $suffix]);

        $st = $pdo->prepare(
            'DELETE FROM invoice_group_items
             WHERE group_id = ? AND sth_id = ? AND suffix = ?'
        );
        $st->execute([$groupId, $sthId, $suffix]);
        $n = $st->rowCount();

        $pdo->commit();
        group_cache_flush($groupId);
        return $n > 0;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * شيل صنف من مجموعة أصناف.
 *
 * بيشيله من **كل فواتير المجموعة** — لأن شاشة المجموعة بتعرض الصنف
 * مدموج في سطر واحد جاي من كذا فاتورة، فالمستخدم شايف حاجة واحدة
 * وبيشيل حاجة واحدة.
 *
 * ⛔ مالوش معنى في مجموعة الفواتير الكاملة — دي بتاخد كل الأصناف
 *    بحكم نوعها، ومفيش `invoice_group_lines` تتشال منها.
 *
 * **مابيمسحش أي توزيع** — زي `group_remove_invoice()` بالظبط.
 *
 * @return int عدد السطور اللي اتشالت (فاتورة واحدة = سطر)
 */
function group_remove_item(int $groupId, string $itemCode): int
{
    if ($groupId <= 0) return 0;
    $itemCode = trim($itemCode);
    if ($itemCode === '') return 0;

    if (!group_is_items($groupId)) {
        throw new RuntimeException(
            'دي مجموعة فواتير كاملة — الأصناف فيها بتيجي كلها مع الفاتورة. '
            . 'لو عايز تستثني صنف، شيل الفاتورة نفسها أو اعمل مجموعة أصناف.'
        );
    }

    // آخر صنف؟ مجموعة أصناف فاضية بتطلّع ملفات فاضية
    $codes = group_selected_codes($groupId);
    if (count($codes) <= 1) {
        throw new RuntimeException(
            'ده آخر صنف في المجموعة. لو عايز تخلص منها كلها، استخدم «امسح المجموعة».'
        );
    }

    $st = db()->prepare(
        'DELETE FROM invoice_group_lines WHERE group_id = ? AND item_code = ?'
    );
    $st->execute([$groupId, $itemCode]);
    $n = $st->rowCount();

    group_cache_flush($groupId);
    return $n;
}

function group_delete(int $groupId): void
{
    if ($groupId <= 0) return;
    // ON DELETE CASCADE بيمسح invoice_group_items و invoice_group_lines
    db()->prepare('DELETE FROM invoice_groups WHERE id = ?')->execute([$groupId]);
    group_cache_flush($groupId);
}

/**
 * تحميل المجموعة كاملة: الأصناف مدموجة + سجلات التوزيع + الأرصدة.
 *
 * الصنف الواحد بيتجمّع من كل الفواتير في سطر واحد، مع الاحتفاظ
 * بمصدره (sources) عشان نعرف نوزّع عليه بالشلال بعدين.
 *
 * @return array{
 *   invoices:array, items:array, dist_ids:array,
 *   distribution:array, branch_totals:array, missing:array
 * }
 */
function group_load(int $groupId): array
{
    $invoices = group_invoices($groupId);
    $out = [
        'invoices'      => $invoices,
        'items'         => [],
        'dist_ids'      => [],   // sth_id => distribution_id
        'distribution'  => [],   // item_code => [branch_id => qty]
        'branch_totals' => [],   // branch_id => total
        'missing'       => [],   // فواتير مقدرناش نجيبها
    ];
    if (!$invoices) return $out;

    $merged = [];   // item_code => صف مدموج

    // فلتر الأصناف: مجموعة الأصناف بتعرض المختار بس.
    // مجموعة الفواتير الكاملة بتعدّي كل حاجة (السلوك القديم).
    $selOnly = group_is_items($groupId);
    $selMap  = $selOnly ? group_selected_map($groupId) : [];

    $pairs = [];
    foreach ($invoices as $inv) {
        $pairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
    }
    $headersMap = group_fetch_headers($pairs);
    $distPre    = group_dist_existing(array_filter($headersMap));

    $linePairs = [];
    foreach ($invoices as $inv) {
        $k = (int) $inv['sth_id'] . '|' . (string) $inv['suffix'];
        if (!empty($headersMap[$k])) {
            $linePairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
        }
    }
    $linesMap = group_fetch_lines($linePairs);

    foreach ($invoices as $inv) {
        $sth    = (int) $inv['sth_id'];
        $suffix = (string) $inv['suffix'];
        $key    = $sth . '|' . $suffix;

        $header = $headersMap[$key] ?? null;
        if (!$header) {
            $out['missing'][] = $inv;
            continue;
        }
        $k    = group_dist_key($header);
        $dist = ($k !== null && isset($distPre[$k])) ? $distPre[$k] : ensure_distribution_record($header, null);
        $distId = (int) $dist['id'];
        $out['dist_ids'][$sth] = $distId;

        $lines = $linesMap[$key] ?? [];
        $allow = $selOnly ? ($selMap[$sth . '|' . $suffix] ?? []) : null;
        foreach ($lines as $ln) {
            $code = trim((string) ($ln['item_code'] ?? ''));
            if ($code === '') continue;
            // مجموعة أصناف: السطر اللي مش مختار مايدخلش الشاشة ولا الحسابات
            if ($allow !== null && empty($allow[$code])) continue;
            $qty = (float) ($ln['quantity'] ?? 0);

            if (!isset($merged[$code])) {
                $merged[$code] = [
                    'item_code'  => $code,
                    'item_name'  => (string) ($ln['item_name'] ?? ''),
                    'item_id'    => (int) ($ln['item_id'] ?? 0),
                    'quantity'   => 0.0,
                    'unit_price' => (float) ($ln['unit_price'] ?? 0),
                    'cost_price' => (float) ($ln['cost_price'] ?? 0),
                    'sources'    => [],   // مصادر الصنف بالترتيب (للشلال)
                ];
            }
            $merged[$code]['quantity'] += $qty;

            // ⚠️ الصنف ممكن يتكرر في أكتر من سطر **جوه نفس الفاتورة**.
            //    invoice_distribution_lines مفتاحه (سجل التوزيع + الفرع +
            //    كود الصنف) — يعني الفاتورة ليها سجل واحد للكود مهما
            //    اتكرر. فلازم ندمج السطور دي في مصدر واحد، وإلا الشلال
            //    هيحسب المساحة المستهلكة مرتين ويرمي "مفيش مساحة كافية".
            $found = false;
            foreach ($merged[$code]['sources'] as &$srcRef) {
                if ((int) $srcRef['sth_id'] === $sth) {
                    $srcRef['qty'] += $qty;
                    $found = true;
                    break;
                }
            }
            unset($srcRef);
            if (!$found) {
                $merged[$code]['sources'][] = [
                    'sth_id'     => $sth,
                    'dist_id'    => $distId,
                    'invoice_no' => (string) $inv['invoice_no'],
                    'qty'        => $qty,
                ];
            }

            // أعلى سعر وحدة (الفواتير ممكن تختلف) — للعرض بس
            $up = (float) ($ln['unit_price'] ?? 0);
            if ($up > $merged[$code]['unit_price']) $merged[$code]['unit_price'] = $up;
        }
    }

    // ترتيب بالاسم عشان الشاشة تبقى مقروءة
    uasort($merged, fn($a, $b) => strcmp($a['item_name'], $b['item_name']));
    $out['items'] = array_values($merged);

    // التوزيع الحالي: مجموع كل الفواتير لكل صنف
    if ($out['dist_ids']) {
        $ids = array_values($out['dist_ids']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st  = db()->prepare(
            "SELECT notes AS item_code, branch_id, SUM(amount) AS qty
             FROM invoice_distribution_lines
             WHERE distribution_id IN ($ph)
             GROUP BY notes, branch_id"
        );
        $st->execute($ids);
        // ⚠️ في مجموعة الأصناف، سجل التوزيع بتاع الفاتورة فيه كمان
        //    أصناف اتوزّعت من شاشة الفاتورة المفردة ومش جوه المجموعة.
        //    لو حسبناهم، إجماليات الفروع وأزرار التحميل هتطلع غلط.
        $okCodes = $selOnly ? array_flip(array_keys($merged)) : null;
        foreach ($st->fetchAll() as $r) {
            $c = (string) $r['item_code'];
            if ($okCodes !== null && !isset($okCodes[$c])) continue;
            $b = (int) $r['branch_id'];
            $out['distribution'][$c][$b] = (float) $r['qty'];
            $out['branch_totals'][$b] = ($out['branch_totals'][$b] ?? 0) + (float) $r['qty'];
        }
    }

    return $out;
}

/**
 * توزيع كمية على فرع لصنف — بالشلال على فواتير المجموعة.
 *
 * بنمسح توزيع الفرع ده للصنف ده من كل الفواتير الأول، وبعدين
 * نملي من الأقدم للأحدث. كده الرقم النهائي دايمًا مطابق لللي
 * المستخدم كتبه، ومفيش ازدواج لو عدّل أكتر من مرة.
 *
 * @param array $sources مصادر الصنف بالترتيب (من group_load)
 * @return array{remaining:float, branch_total:float, split:array}
 */
function group_set_distribution(
    array $sources,
    string $itemCode,
    int $branchId,
    float $qty,
    ?int $userId = null
): array {
    if ($qty < 0) throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
    if (!$sources)  throw new RuntimeException('الصنف ده مش في أي فاتورة في المجموعة.');

    // حزام أمان: لو وصلنا أكتر من مصدر بنفس سجل التوزيع (صنف مكرر في
    // نفس الفاتورة)، بندمجهم هنا كمان. التخزين مابيفرّقش بينهم أصلاً،
    // ولو سبناهم منفصلين هنحسب المساحة المستهلكة أكتر من مرة.
    $bySrc = [];
    foreach ($sources as $s) {
        $k = (int) $s['dist_id'];
        if (isset($bySrc[$k])) { $bySrc[$k]['qty'] += (float) $s['qty']; continue; }
        $bySrc[$k] = $s;
        $bySrc[$k]['qty'] = (float) $s['qty'];
    }
    $sources = array_values($bySrc);

    $totalQty = 0.0;
    foreach ($sources as $s) $totalQty += (float) $s['qty'];

    // المتاح = إجمالي الكمية − اللي اتوزّع لباقي الفروع (عبر كل الفواتير)
    $distIds = array_values(array_unique(array_map(fn($s) => (int) $s['dist_id'], $sources)));
    $ph = implode(',', array_fill(0, count($distIds), '?'));

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
             WHERE distribution_id IN ($ph) AND notes = ? AND branch_id <> ? FOR UPDATE"
        );
        $st->execute(array_merge($distIds, [$itemCode, $branchId]));
        $othersTotal = (float) $st->fetchColumn();

        // 🔴 السقف الحقيقي = الأقل بين كمية المجموعة ورصيد الإدارة.
        //    الفاتورة ممكن تبقى ١٢ والواصل فعلاً ١٠ (والباقي اتعمله
        //    مردود)، فتوزيع ١٢ بيطلّع بضاعة مش موجودة والفرق بيظهر
        //    بعدين كعجز على الفرع.
        //    لو حارس رصيد الإدارة متشال عن أي فاتورة للصنف ده في المجموعة (بريف ٥٤):
        //    السقف = كمية المجموعة كاملة.
        if (dist_guard_off($distIds)) {
            $cap = $totalQty;
        } else {
            $hqBal = hq_admin_balance_for_code($itemCode);

            if ($hqBal === null) {
                // 🔴 الرصيد مش واصل: **ممنوع تزوّد، مسموح تقلّل**.
                //    منع الصنف خالص اتجرّب واتشال: اتقفل ٥٤ صنف من ٧٥ في
                //    فاتورة واحدة، بما فيهم أصناف متوزّعة خلاص. طول ما
                //    الكمية مابتزيدش عن المحفوظ، مفيش بضاعة زيادة بتطلع.
                $ownSt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
                     WHERE distribution_id IN ($ph) AND notes = ? AND branch_id = ? FOR UPDATE"
                );
                $ownSt->execute(array_merge($distIds, [$itemCode, $branchId]));
                $ownCurrent = (float) $ownSt->fetchColumn();

                if ($qty > $ownCurrent + 0.0001) {
                    throw new RuntimeException(
                        'رصيد الإدارة للصنف ده مش واصل، فمش هينفع تزوّد الكمية. '
                        . 'تقدر تقلّلها أو تصفّرها. شغّل وكيل مزامنة الإدارة عشان توزّع أكتر.'
                    );
                }
                $cap = min($totalQty, $othersTotal + $ownCurrent);
            } else {
                $cap = min($totalQty, max(0.0, $hqBal));   // الرصيد السالب = صفر
            }
        }

        $available = $cap - $othersTotal;
        if ($qty > $available + 0.0001) {
            $msg = 'الكمية (' . number_format($qty, 2) . ') أكبر من المتاح (' .
                   number_format(max(0.0, $available), 2) . ')';
            if (isset($hqBal) && $hqBal !== null && $cap < $totalQty - 0.0001) {
                $msg .= ' — رصيد الإدارة ' . number_format(max(0.0, $hqBal), 2)
                      . ' وكمية المجموعة ' . number_format($totalQty, 2);
            }
            throw new RuntimeException($msg . '.');
        }

        // امسح توزيع الفرع ده للصنف ده من كل فواتير المجموعة
        $del = $pdo->prepare(
            "DELETE FROM invoice_distribution_lines
             WHERE distribution_id IN ($ph) AND notes = ? AND branch_id = ?"
        );
        $del->execute(array_merge($distIds, [$itemCode, $branchId]));

        // شلال: املا الأقدم الأول
        $ins = $pdo->prepare(
            'INSERT INTO invoice_distribution_lines (distribution_id, branch_id, amount, notes)
             VALUES (?, ?, ?, ?)'
        );
        $q = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
             WHERE distribution_id = ? AND notes = ? AND branch_id <> ?'
        );
        $left  = $qty;
        $split = [];
        foreach ($sources as $s) {
            if ($left <= 0.0001) break;
            $srcQty  = (float) $s['qty'];
            $distId  = (int) $s['dist_id'];

            // المتاح في الفاتورة دي = كميتها − اللي اتوزّع منها لفروع تانية
            $q->execute([$distId, $itemCode, $branchId]);
            $usedHere = (float) $q->fetchColumn();

            $room = $srcQty - $usedHere;
            if ($room <= 0.0001) continue;

            $take = min($left, $room);
            $ins->execute([$distId, $branchId, $take, $itemCode]);
            $split[] = [
                'invoice_no' => (string) $s['invoice_no'],
                'qty'        => $take,
            ];
            $left -= $take;
        }

        if ($left > 0.0001) {
            // مفروض ما يحصلش (الفحص فوق بيمنعه) — بس نحمي نفسنا
            throw new RuntimeException('مفيش مساحة كافية في فواتير المجموعة للكمية دي.');
        }

        if ($own) $pdo->commit();
        return [
            'remaining'    => $totalQty - $othersTotal - $qty,
            'branch_total' => $qty,
            'split'        => $split,
        ];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

// =====================================================================
//  أسعار البيع على مستوى المجموعة
//  ---------------------------------------------------------------
//  الأسعار متخزّنة في invoice_item_prices بمفتاح (سجل التوزيع + الكود).
//  في المجموعة الصنف ممكن يكون في أكتر من فاتورة، فالقاعدة هنا:
//    • القراءة/الجلب من سجل الفاتورة **الأقدم** اللي فيها الصنف.
//    • أي سعر (متجاب من السيستم أو معدّل يدوي) بيتكتب على **كل**
//      الفواتير اللي فيها الصنف — عشان ملف كل فاتورة لوحدها يطلع
//      بنفس السعر بالظبط.
// =====================================================================

/**
 * سجلات التوزيع بتاعت المجموعة اللي فيها كود معيّن (من المرآة المحلية).
 * لو المرآة لسه فاضية بترجّع كل سجلات المجموعة (احتياطي).
 *
 * @return int[] أرقام invoice_distribution
 */
function group_code_dist_ids(int $groupId, string $itemCode): array
{
    if ($groupId <= 0 || $itemCode === '') return [];
    $pdo = db();

    try {
        if (group_is_items($groupId)) {
            // مجموعة أصناف: الفواتير اللي الصنف **مختار** فيها بس
            $st = $pdo->prepare(
                "SELECT DISTINCT d.id
                 FROM invoice_group_lines gl
                 JOIN invoice_group_items i
                   ON i.group_id = gl.group_id AND i.sth_id = gl.sth_id AND i.suffix = gl.suffix
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 WHERE gl.group_id = ? AND gl.item_code = ?"
            );
            $st->execute([$groupId, $itemCode]);
        } else {
            $st = $pdo->prepare(
                "SELECT DISTINCT d.id
                 FROM invoice_group_items i
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 LEFT JOIN hq_invoice_lines l
                        ON l.sth_id = i.sth_id AND l.suffix = i.suffix AND l.item_code = ?
                 LEFT JOIN hq_unposted_lines u
                        ON u.sth_id = i.sth_id AND i.suffix = 'YY_MM' AND u.item_code = ?
                 WHERE i.group_id = ?
                   AND (l.sth_id IS NOT NULL OR u.sth_id IS NOT NULL)"
            );
            $st->execute([$itemCode, $itemCode, $groupId]);
        }
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) return $ids;
    } catch (Throwable $ex) {
        // المرآة ممكن ما تكونش جاهزة — بنكمّل على الاحتياطي تحت
    }

    // احتياطي: كل سجلات توزيع المجموعة
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT d.id
             FROM invoice_group_items i
             JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
             WHERE i.group_id = ?"
        );
        $st->execute([$groupId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * نشر السعر على باقي فواتير المجموعة اللي فيها نفس الصنف.
 *
 * @param array $codeDists  [code => [dist_id, ...]] (الأقدم أولاً)
 * @param array $prices     [code => price]
 * @param bool  $overwrite  true = يكتب فوق الموجود · false = يملا الناقص بس
 */
function group_propagate_prices(array $codeDists, array $prices, bool $overwrite = false): void
{
    if (!$codeDists || !$prices) return;
    $pdo = db();

    $sql = $overwrite
        ? 'INSERT INTO invoice_item_prices
                  (distribution_id, item_code, sale_price, source_price, fetched_at)
           VALUES (?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE
                  sale_price = VALUES(sale_price), fetched_at = NOW()'
        : 'INSERT IGNORE INTO invoice_item_prices
                  (distribution_id, item_code, sale_price, source_price, fetched_at)
           VALUES (?, ?, ?, ?, NOW())';

    try {
        $ins = $pdo->prepare($sql);
    } catch (Throwable $ex) {
        return;
    }

    foreach ($codeDists as $code => $ids) {
        if (!array_key_exists($code, $prices)) continue;
        $price = (float) $prices[$code];
        // أول سجل هو الأصل (اتكتب فيه بالفعل) — نبدأ من التاني
        foreach (array_slice($ids, 1) as $d) {
            try {
                $ins->execute([(int) $d, (string) $code, $price, $price]);
            } catch (Throwable $ex) {
                error_log('group: فشل نشر سعر ' . $code . ' — ' . $ex->getMessage());
            }
        }
    }
}

/**
 * أسعار البيع لأصناف المجموعة — [item_code => price].
 *
 * $force = false → المخزّن، والناقص/اللي عدّى عليه يوم يتجاب من السيستم
 * $force = true  → كل الأصناف تتجاب من السيستم فورًا وتتكتب على كل الفواتير
 *
 * @param array $items أصناف المجموعة (من group_load — لازم فيها sources)
 */
function group_resolve_prices(array $items, bool $force = false): array
{
    if (!$items) return [];

    $byPrimary = [];   // dist_id => [الأصناف اللي ده أقدم سجل ليها]
    $codeDists = [];   // code    => [dist_ids]

    foreach ($items as $it) {
        $code = trim((string) ($it['item_code'] ?? ''));
        if ($code === '' || empty($it['sources'])) continue;

        $ids = [];
        foreach ($it['sources'] as $s) {
            $d = (int) ($s['dist_id'] ?? 0);
            if ($d > 0 && !in_array($d, $ids, true)) $ids[] = $d;
        }
        if (!$ids) continue;

        $codeDists[$code]      = $ids;
        $byPrimary[$ids[0]][]  = $it;
    }
    if (!$byPrimary) return [];

    // (١) نفس منطق الفاتورة الواحدة — بالظبط (تخزين + قِدَم ٢٤ ساعة)
    $out = [];
    foreach ($byPrimary as $distId => $subset) {
        $prices = refresh_invoice_sale_prices((int) $distId, $subset, $force);
        foreach ($subset as $it) {
            $c = (string) $it['item_code'];
            if (array_key_exists($c, $prices)) $out[$c] = (float) $prices[$c];
        }
    }

    // (٢) نشر على باقي الفواتير — الكتابة فوق وقت التحديث الإجباري بس
    group_propagate_prices($codeDists, $out, $force);

    return $out;
}

/**
 * حفظ سعر بيع معدّل يدوي من شاشة المجموعة.
 * بيتكتب على كل فواتير المجموعة اللي فيها الصنف.
 *
 * @return int عدد الفواتير اللي اتكتب فيها
 */
function group_save_sale_price(
    int $groupId,
    string $itemCode,
    float $price,
    ?int $userId = null
): int {
    $itemCode = trim($itemCode);
    if ($groupId <= 0 || $itemCode === '') {
        throw new RuntimeException('بيانات ناقصة.');
    }
    if ($price < 0) {
        throw new RuntimeException('السعر لازم يكون موجب.');
    }

    $ids = group_code_dist_ids($groupId, $itemCode);
    if (!$ids) {
        throw new RuntimeException('مالقيتش الصنف ده في فواتير المجموعة.');
    }

    $n = 0;
    foreach ($ids as $d) {
        save_invoice_sale_price((int) $d, $itemCode, $price, $userId);
        $n++;
    }
    return $n;
}

/**
 * حفظ ملاحظات المجموعة.
 * نفس فكرة ملاحظات الفاتورة — نص حر بيتحفظ لوحده وبيبان على الكارت.
 */
function group_save_notes(int $groupId, string $notes): void
{
    if ($groupId <= 0) throw new RuntimeException('مجموعة غير صحيحة.');

    $notes = trim($notes);
    if (mb_strlen($notes) > 2000) $notes = mb_substr($notes, 0, 2000);

    db()->prepare('UPDATE invoice_groups SET user_notes = ? WHERE id = ?')
        ->execute([$notes !== '' ? $notes : null, $groupId]);
}

/**
 * خريطة كل أكواد المجموعة → سجلات التوزيع اللي فيها الكود (استعلام واحد).
 * بتستخدم في التسعير الجماعي بدل ما نسأل عن كل صنف لوحده.
 *
 * @return array [item_code => [dist_id, ...]]
 */
function group_code_dist_map(int $groupId): array
{
    if ($groupId <= 0) return [];
    $out = [];

    try {
        if (group_is_items($groupId)) {
            // مجموعة أصناف: الأصناف المختارة بس — عشان التسعير الجماعي
            // مايكتبش على صنف مش جوه المجموعة
            $st = db()->prepare(
                "SELECT gl.item_code AS code, d.id AS dist_id
                 FROM invoice_group_lines gl
                 JOIN invoice_group_items i
                   ON i.group_id = gl.group_id AND i.sth_id = gl.sth_id AND i.suffix = gl.suffix
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 WHERE gl.group_id = ?"
            );
            $st->execute([$groupId]);
        } else {
            $st = db()->prepare(
                "SELECT l.item_code AS code, d.id AS dist_id
                 FROM invoice_group_items i
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 JOIN hq_invoice_lines l ON l.sth_id = i.sth_id AND l.suffix = i.suffix
                 WHERE i.group_id = ?
                 UNION
                 SELECT u.item_code AS code, d.id AS dist_id
                 FROM invoice_group_items i
                 JOIN invoice_distribution d ON d.invoice_no = i.invoice_no AND " . dist_key_sql('d', 'i.company_branch', 'i.doc_type') . "
                 JOIN hq_unposted_lines u ON u.sth_id = i.sth_id
                 WHERE i.group_id = ? AND i.suffix = 'YY_MM'"
            );
            $st->execute([$groupId, $groupId]);
        }
        foreach ($st->fetchAll() as $r) {
            $c = trim((string) $r['code']);
            if ($c === '') continue;
            $out[$c][] = (int) $r['dist_id'];
        }
    } catch (Throwable $ex) {
        return [];
    }

    return $out;
}

/**
 * تسعير جماعي: [كود => سعر] على كل فواتير المجموعة اللي فيها الصنف.
 * كله في transaction واحدة — يا كله يا ولا حاجة.
 *
 * @return int عدد الأصناف اللي اتسعّرت
 */
function group_save_prices_bulk(int $groupId, array $prices, ?int $userId = null): int
{
    if ($groupId <= 0 || !$prices) return 0;

    $map = group_code_dist_map($groupId);
    if (!$map) {
        // المرآة مش جاهزة — نرجع لسجلات المجموعة كلها
        $all = group_code_dist_ids($groupId, '__any__');
        if (!$all) throw new RuntimeException('مالقيتش سجلات التوزيع بتاعت المجموعة.');
        foreach (array_keys($prices) as $c) $map[$c] = $all;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($prices as $code => $price) {
            $code  = trim((string) $code);
            $price = (float) $price;
            if ($code === '' || $price < 0 || empty($map[$code])) continue;
            foreach (array_unique($map[$code]) as $d) {
                save_invoice_sale_price((int) $d, $code, $price, $userId);
            }
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * تحديث حالة كل فاتورة في المجموعة بعد التوزيع.
 * بيستخدم نفس دالة الفاتورة الواحدة — مابنلمسش المنطق ده.
 */
function group_refresh_statuses(int $groupId, ?int $userId = null): void
{
    $invoices = group_invoices($groupId);
    if (!$invoices) return;

    $pairs = [];
    foreach ($invoices as $inv) {
        $pairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
    }
    $headersMap = group_fetch_headers($pairs);
    $distPre    = group_dist_existing(array_filter($headersMap));

    $linePairs = [];
    foreach ($invoices as $inv) {
        $k = (int) $inv['sth_id'] . '|' . (string) $inv['suffix'];
        if (!empty($headersMap[$k])) {
            $linePairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
        }
    }
    $linesMap = group_fetch_lines($linePairs);

    foreach ($invoices as $inv) {
        $sth    = (int) $inv['sth_id'];
        $suffix = (string) $inv['suffix'];
        $key    = $sth . '|' . $suffix;
        try {
            $header = $headersMap[$key] ?? null;
            if (!$header) continue;
            $k    = group_dist_key($header);
            $dist = ($k !== null && isset($distPre[$k])) ? $distPre[$k] : ensure_distribution_record($header, null);
            try {
                recalc_invoice_status((int) $dist['id'], $linesMap[$key] ?? [], false /* group_refresh_statuses() بتتنده مع كل حفظ كمية في المجموعة — مش إرسال */);
            } catch (Throwable $ex) {
                error_log('receiving: تعذّر تحديث حالة الفاتورة — ' . $ex->getMessage());
            }
        } catch (Throwable $ex) {
            // فاتورة واحدة فشلت ما توقفش الباقي
            error_log('group: فشل تحديث حالة الفاتورة ' . $sth . ' — ' . $ex->getMessage());
        }
    }

    try {
        $distIds = group_code_dist_ids($groupId, '__any__');
        if (!empty($distIds)) {
            $distIdsClean = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));
            if (!empty($distIdsClean)) {
                $inClause = implode(',', $distIdsClean);
                $notDone = (int) db()->query("SELECT COUNT(*) FROM invoice_distribution WHERE id IN ($inClause) AND status <> 'done'")->fetchColumn();
                if ($notDone === 0) {
                    require_once __DIR__ . '/receiving_flow_lib.php';
                    $uid = $userId ?? ($_SESSION['user']['id'] ?? 0);
                    receiving_open(db(), $groupId, 0, (int) $uid);
                }
            }
        }
    } catch (Throwable $ex) {
        error_log('group: فشل فتح الاستلام للمجموعة ' . $groupId . ' — ' . $ex->getMessage());
    }
}

/**
 * عدد المجموعات المفتوحة (استعلام خفيف وبسيط بدون تحميل بيانات المجموعات).
 */
function group_open_count(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM invoice_groups WHERE status = 'open'")->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

/**
 * استخراج المفتاح الثلاثي (invoice_no|company_branch|doc_type) من هيدر الفاتورة.
 * يرجع null إذا كان أي جزء ناقصاً حتى لا يتم التخمين أو التوزيع الخاطئ.
 */
function group_dist_key(array $h): ?string
{
    $invNo = (string) ($h['inv_no'] ?? '');
    if ($invNo === '') return null;

    $cb = null;
    if (isset($h['company_branch']) && $h['company_branch'] !== null && $h['company_branch'] !== '') {
        $cb = (int) $h['company_branch'];
    } elseif (isset($h['company_branch_id']) && $h['company_branch_id'] !== null && $h['company_branch_id'] !== '') {
        $cb = (int) $h['company_branch_id'];
    }

    $dt = null;
    if (isset($h['doc_type']) && $h['doc_type'] !== null && $h['doc_type'] !== '') {
        $dt = (int) $h['doc_type'];
    }

    if ($cb === null || $dt === null) return null;

    return $invNo . '|' . $cb . '|' . $dt;
}

/**
 * جلب سجلات التوزيع الموجودة مسبقاً لمجموعة هيدرات (قراءة فقط بدون كتابة).
 * المفتاح: invoice_no|company_branch|doc_type.
 *
 * @param array $headers قائمة مصفوفات الهيدرات
 * @return array [key => row]
 */
function group_dist_existing(array $headers): array
{
    $triplets = [];
    foreach ($headers as $h) {
        if (!is_array($h)) continue;
        $k = group_dist_key($h);
        if ($k === null) continue;
        if (!isset($triplets[$k])) {
            $parts = explode('|', $k, 3);
            $triplets[$k] = [$parts[0], (int)$parts[1], (int)$parts[2]];
        }
    }
    if (!$triplets) return [];

    $out = [];
    $pdo = db();
    $chunks = array_chunk(array_values($triplets), 500);
    foreach ($chunks as $chunk) {
        try {
            $phList = [];
            $params = [];
            foreach ($chunk as $t) {
                $phList[] = '(?,?,?)';
                $params[] = $t[0];
                $params[] = $t[1];
                $params[] = $t[2];
            }
            $phStr = implode(',', $phList);
            $st = $pdo->prepare(
                "SELECT * FROM invoice_distribution
                 WHERE (invoice_no, company_branch, doc_type) IN ($phStr)
                 ORDER BY id ASC"
            );
            $st->execute($params);
            while ($r = $st->fetch()) {
                $k = (string)$r['invoice_no'] . '|' . (int)$r['company_branch'] . '|' . (int)$r['doc_type'];
                if (!isset($out[$k])) {
                    $out[$k] = $r;
                }
            }
        } catch (Throwable $ex) {
            // لو رمى، اعتبر كل مفاتيحه مالقيناهاش
        }
    }
    return $out;
}

/**
 * جلب هيدرات فواتير مجمعة: ['sth_id'=>..., 'suffix'=>...].
 * المفتاح بالـ suffix اللي اتطلب.
 *
 * @param array $pairs قائمة أزواج ['sth_id'=>int, 'suffix'=>string]
 * @return array ["sth|suffix" => header|null]
 */
function group_fetch_headers(array $pairs): array
{
    $out = [];
    $postedPairs = [];
    $unpostedIds = [];

    foreach ($pairs as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = (string) ($p['suffix'] ?? '');
        $k   = $sth . '|' . $suf;
        if (isset($out[$k])) continue;

        if ($sth <= 0) {
            $out[$k] = null;
            continue;
        }

        if ($suf !== '' && $suf !== 'YY_MM') {
            $postedPairs[$k] = [$sth, $suf];
        } elseif ($suf === 'YY_MM') {
            $unpostedIds[$k] = $sth;
        }
    }

    $pdo = db();

    // 1) الفواتير المرحلة من hq_invoices
    if ($postedPairs) {
        $chunks = array_chunk($postedPairs, 500);
        foreach ($chunks as $chunk) {
            try {
                $phList = [];
                $params = [];
                foreach ($chunk as $item) {
                    $phList[] = '(?,?)';
                    $params[] = $item[0];
                    $params[] = $item[1];
                }
                $phStr = implode(',', $phList);
                $st = $pdo->prepare(
                    "SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id,
                            supplier_code, supplier_name, net_amount, total_amount,
                            invoice_date, doc_type, suffix, company_branch
                     FROM hq_invoices WHERE (sth_id, suffix) IN ($phStr)"
                );
                $st->execute($params);
                while ($r = $st->fetch()) {
                    $k = (int)$r['inv_id'] . '|' . (string)$r['suffix'];
                    $out[$k] = $r;
                }
            } catch (Throwable $ex) {
                // fall through to fallback
            }
        }
    }

    // 2) الفواتير غير المحفوظة من hq_unposted
    if ($unpostedIds) {
        $chunks = array_chunk($unpostedIds, 500, true);
        foreach ($chunks as $chunk) {
            try {
                $ids = array_values(array_unique($chunk));
                $phStr = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare(
                    "SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id,
                            supplier_code, supplier_name, net_amount, total_amount,
                            invoice_date, doc_type, remark, company_branch
                     FROM hq_unposted WHERE sth_id IN ($phStr)"
                );
                $st->execute($ids);
                while ($r = $st->fetch()) {
                    $r['suffix'] = 'YY_MM';
                    $k = (int)$r['inv_id'] . '|YY_MM';
                    $out[$k] = $r;
                }
            } catch (Throwable $ex) {
                // fall through to fallback
            }
        }
    }

    // 3) Fallback: أي مفتاح مالقيناهوش أو suffix فاضي
    $checked = [];
    foreach ($pairs as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = (string) ($p['suffix'] ?? '');
        $k   = $sth . '|' . $suf;
        if (isset($checked[$k])) continue;
        $checked[$k] = true;
        if (!array_key_exists($k, $out)) {
            $h = sqlsrv_fetch_invoice_header($sth, $suf ?: null);
            $out[$k] = $h ?: null;
        }
    }

    return $out;
}

/**
 * جلب بنود فواتير مجمعة: ['sth_id'=>..., 'suffix'=>...].
 *
 * @param array $pairs قائمة أزواج ['sth_id'=>int, 'suffix'=>string]
 * @return array ["sth|suffix" => rows]
 */
function group_fetch_lines(array $pairs): array
{
    $out = [];
    $postedPairs = [];
    $unpostedIds = [];

    foreach ($pairs as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = (string) ($p['suffix'] ?? '');
        $k   = $sth . '|' . $suf;
        if (isset($out[$k])) continue;

        if ($sth <= 0) {
            $out[$k] = [];
            continue;
        }

        if ($suf !== '' && $suf !== 'YY_MM') {
            $postedPairs[$k] = [$sth, $suf];
        } elseif ($suf === 'YY_MM') {
            $unpostedIds[$k] = $sth;
        }
    }

    $pdo = db();

    // 1) بنود مرحلة من hq_invoice_lines
    if ($postedPairs) {
        $chunks = array_chunk($postedPairs, 500);
        foreach ($chunks as $chunk) {
            try {
                $phList = [];
                $params = [];
                foreach ($chunk as $item) {
                    $phList[] = '(?,?)';
                    $params[] = $item[0];
                    $params[] = $item[1];
                }
                $phStr = implode(',', $phList);
                $st = $pdo->prepare(
                    "SELECT sth_id AS _k_sth, suffix AS _k_suf,
                            item_id, item_code, item_name, quantity,
                            unit_price, cost_price
                     FROM hq_invoice_lines
                     WHERE (sth_id, suffix) IN ($phStr)
                     ORDER BY sth_id, suffix, line_no"
                );
                $st->execute($params);
                while ($r = $st->fetch()) {
                    $k = (int)$r['_k_sth'] . '|' . (string)$r['_k_suf'];
                    unset($r['_k_sth'], $r['_k_suf']);
                    $out[$k][] = $r;
                }
            } catch (Throwable $ex) {
                // fall through to single fetch
            }
        }
    }

    // 2) بنود غير محفوظة من hq_unposted_lines
    if ($unpostedIds) {
        $chunks = array_chunk($unpostedIds, 500, true);
        foreach ($chunks as $chunk) {
            try {
                $ids = array_values(array_unique($chunk));
                $phStr = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare(
                    "SELECT sth_id AS _k_sth,
                            item_id, item_code, item_name, quantity,
                            unit_price, cost_price
                     FROM hq_unposted_lines
                     WHERE sth_id IN ($phStr)
                     ORDER BY sth_id, line_no"
                );
                $st->execute($ids);
                while ($r = $st->fetch()) {
                    $k = (int)$r['_k_sth'] . '|YY_MM';
                    unset($r['_k_sth'], $r['_k_suf']);
                    $out[$k][] = $r;
                }
            } catch (Throwable $ex) {
                // fall through to single fetch
            }
        }
    }

    // 3) Fallback: أي مفتاح مالوش بنود أو suffix فاضي
    $checked = [];
    foreach ($pairs as $p) {
        $sth = (int) ($p['sth_id'] ?? 0);
        $suf = (string) ($p['suffix'] ?? '');
        $k   = $sth . '|' . $suf;
        if (isset($checked[$k])) continue;
        $checked[$k] = true;
        if (empty($out[$k])) {
            $items = sqlsrv_fetch_invoice_items($sth, $suf ?: null);
            $out[$k] = is_array($items) ? $items : [];
        }
    }

    return $out;
}
