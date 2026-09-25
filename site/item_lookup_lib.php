<?php
/**
 * item_lookup_lib.php — تتبّع الصنف عبر فواتير الشراء والمرتجع
 * نظام الفجالة ستور المالي — 2026-08-11
 *
 * =====================================================================
 *  بتجاوب على سؤال واحد: «الصنف ده نزل في أنهي فواتير، وراح فين؟»
 * =====================================================================
 *
 *  بترجّع لكل فاتورة نزل فيها الصنف:
 *      نوع المستند (شراء / مردود شراء) · التاريخ · المورد ·
 *      الكمية والسعر · التوزيع على الفروع · اللي فضل مخزن
 *
 *  ⚠️ فواتير **البيع** مش موجودة أصلاً في المرآة: وكيل المزامنة
 *     بيجيب SSTT_ID IN (2,6) بس — يعني شراء ومردود شراء. لو احتجنا
 *     البيع لازم نضيف نوع المستند في agent_hq.ps1 الأول.
 *
 *  كله محلي (hq_*) — صفر نداءات للجسر.
 *
 *  الربط: الصنف الواحد ليه أكتر من باركود وكلهم بنفس الـ ITEM_ID،
 *  فبنبحث بالـ item_id لما نعرفه — وإلا بالكود زي ما هو.
 *
 *  التوزيع: invoice_distribution_lines.notes بيخزّن **كود الصنف**
 *  (الجدول عام) والمفتاح distribution_id — وده مربوط بـ invoice_no
 *  مش sth_id، فبيفضل شغال حتى بعد ما الفاتورة تترحّل.
 */
require_once __DIR__ . '/config.php';

const ILK_MAX_INVOICES = 50;   // سقف الفواتير المعروضة للصنف

/** الجداول جاهزة؟ */
function ilk_ready(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    try { db()->query('SELECT 1 FROM hq_invoice_lines LIMIT 1'); $r = true; }
    catch (Throwable $ex) { $r = false; }
    return $r;
}

/**
 * بحث بالكود أو الاسم — للاقتراحات تحت السيرش بار.
 * بترجّع code · item_id · name · sale_price
 */
function ilk_search(string $term, int $limit = 15): array
{
    $term = trim($term);
    if ($term === '' || !ilk_ready()) return [];
    if ($limit < 1 || $limit > 50) $limit = 15;

    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';

    // hq_codes فيها كل الباركودات + التكلفة، وهي مفهرسة على code
    $st = db()->prepare(
        "SELECT code, item_id, name, price, cost
         FROM hq_codes
         WHERE code = ? OR code LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!'
         ORDER BY (code = ?) DESC, name
         LIMIT $limit"
    );
    $st->execute([$term, $like, $like, $term]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'code'    => (string) $r['code'],
            'item_id' => (int) $r['item_id'],
            'name'    => (string) ($r['name'] ?? ''),
            'price'   => (float) ($r['price'] ?? 0),
            'cost'    => (float) ($r['cost'] ?? 0),
        ];
    }
    return $out;
}

/**
 * كل فواتير الشراء/المرتجع اللي نزل فيها الصنف + توزيعته.
 *
 * @param string $code    كود الصنف (باركود)
 * @param int    $itemId  لو معروف، بنبحث بيه (بيغطي كل باركودات الصنف)
 * @return array ['item'=>[...], 'rows'=>[...], 'totals'=>[...], 'truncated'=>bool]
 */
function ilk_item_history(string $code, int $itemId = 0): array
{
    $empty = ['item' => null, 'rows' => [], 'totals' => [], 'truncated' => false];
    $code  = trim($code);
    if ($code === '' && $itemId <= 0) return $empty;
    if (!ilk_ready()) return $empty;

    $pdo = db();

    // ---- بيانات الصنف ----
    $item = null;
    if ($code !== '') {
        $st = $pdo->prepare('SELECT code, item_id, name, price, cost FROM hq_codes WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $item = $st->fetch() ?: null;
    }
    if (!$item && $itemId > 0) {
        $st = $pdo->prepare('SELECT code, item_id, name, price, cost FROM hq_codes WHERE item_id = ? LIMIT 1');
        $st->execute([$itemId]);
        $item = $st->fetch() ?: null;
    }
    if ($item && $itemId <= 0) $itemId = (int) $item['item_id'];

    // ---- سطور الفواتير ----
    // بالـ item_id لو موجود (بيغطي كل باركودات الصنف)، وإلا بالكود.
    $lim = ILK_MAX_INVOICES + 1;   // +1 عشان نعرف لو فيه أكتر
    if ($itemId > 0) {
        $sql  = 'WHERE l.item_id = ?';
        $args = [$itemId];
    } else {
        $sql  = 'WHERE l.item_code = ?';
        $args = [$code];
    }

    $st = $pdo->prepare(
        "SELECT l.sth_id, l.suffix, l.item_id, l.item_code, l.item_name,
                l.quantity, l.unit_price, l.cost_price,
                h.doc_no, h.doc_type, h.supplier_name, h.invoice_date, h.company_branch
         FROM hq_invoice_lines l
         JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
         $sql
         ORDER BY h.invoice_date DESC, h.sth_id DESC
         LIMIT $lim"
    );
    $st->execute($args);
    $lines = $st->fetchAll();

    $truncated = count($lines) > ILK_MAX_INVOICES;
    if ($truncated) array_pop($lines);
    if (!$lines) {
        return ['item' => $item, 'rows' => [], 'totals' => [], 'truncated' => false];
    }

    // ---- التوزيع: استعلام واحد لكل الفواتير مرة واحدة ----
    $docNos = array_values(array_unique(array_map(
        fn($l) => trim((string) $l['doc_no']), $lines
    )));
    $codes = array_values(array_unique(array_map(
        fn($l) => (string) $l['item_code'], $lines
    )));
    if ($code !== '') $codes[] = $code;
    if ($itemId > 0) $codes[] = '#' . $itemId;
    foreach ($lines as $l) {
        if (!empty($l['item_id'])) $codes[] = '#' . (int) $l['item_id'];
    }
    $codes = array_values(array_unique(array_filter($codes)));
    $dist = ilk_distribution_map($docNos, $codes);

    // ---- أسماء الفروع ----
    // وفرع الشركة اللي اشترى الفاتورة: company_branch = branches.sto_id
    // (نفس ربط group_invoices_by_branch في قايمة الفواتير)
    $branches = [];
    $buyerNames = [1 => 'الإدارة'];
    try {
        foreach ($pdo->query('SELECT id, name, sto_id, in_receiving FROM branches') as $b) {
            $branches[(int) $b['id']] = (string) $b['name'];
            $sid = (int) ($b['sto_id'] ?? 0);
            if ($sid > 1) $buyerNames[$sid] = (int) $b['in_receiving'] === 1 ? (string) $b['name'] : 'الإدارة';
        }
    } catch (Throwable $ex) { /* تجاهل */ }

    // ---- تجميع النتيجة ----
    $rows = [];
    $tot  = ['bought' => 0.0, 'returned' => 0.0, 'distributed' => 0.0, 'invoices' => 0];

    foreach ($lines as $l) {
        $docNo  = trim((string) $l['doc_no']);
        $iCode  = (string) $l['item_code'];
        $isRet  = ((int) $l['doc_type'] === 6);
        $qty    = (float) $l['quantity'];

        $cb = ($l['company_branch'] !== null && $l['company_branch'] !== '') ? (string) $l['company_branch'] : '*';
        $dt = ($l['doc_type'] !== null && $l['doc_type'] !== '') ? (string) $l['doc_type'] : '*';
        $fullKey = $docNo . '|' . $cb . '|' . $dt . '|' . $iCode;
        $fallbackKey = $docNo . '|*|*|' . $iCode;
        $oldKey = $docNo . '|' . $iCode;
        $d      = $dist[$fullKey] ?? ($dist[$fallbackKey] ?? ($dist[$oldKey] ?? []));
        if (empty($d) && !empty($l['item_id'])) {
            $hCode = '#' . (int) $l['item_id'];
            $d = $dist[$docNo . '|' . $cb . '|' . $dt . '|' . $hCode]
              ?? ($dist[$docNo . '|*|*|' . $hCode]
              ?? ($dist[$docNo . '|' . $hCode] ?? []));
        }
        if (empty($d) && $code !== '') {
            $d = $dist[$docNo . '|' . $cb . '|' . $dt . '|' . $code]
              ?? ($dist[$docNo . '|*|*|' . $code]
              ?? ($dist[$docNo . '|' . $code] ?? []));
        }
        $dTotal = 0.0;
        $perBranch = [];
        foreach ($d as $bid => $q) {
            $dTotal += $q;
            $perBranch[] = [
                'branch_id' => $bid,
                'name'      => $branches[$bid] ?? ('فرع #' . $bid),
                'qty'       => $q,
            ];
        }
        usort($perBranch, fn($a, $b) => $b['qty'] <=> $a['qty']);

        $rows[] = [
            'doc_no'         => $docNo,
            'sth_id'         => (int) $l['sth_id'],
            'suffix'         => (string) $l['suffix'],
            'company_branch' => ($l['company_branch'] !== null && $l['company_branch'] !== '') ? (int) $l['company_branch'] : null,
            // من غير فرع = الإدارة (زي قايمة الفواتير)
            'buyer'          => $buyerNames[(int) ($l['company_branch'] ?? 0) ?: 1] ?? ('فرع #' . (int) $l['company_branch']),
            'doc_type'       => ($l['doc_type'] !== null && $l['doc_type'] !== '') ? (int) $l['doc_type'] : null,
            'is_return'      => $isRet,
            'type_label'     => $isRet ? 'مردود شراء' : 'فاتورة شراء',
            'date'           => (string) ($l['invoice_date'] ?? ''),
            'supplier'       => (string) ($l['supplier_name'] ?? ''),
            'item_id'        => (int) ($l['item_id'] ?? 0),
            'item_code'      => $iCode,
            'item_name'      => (string) ($l['item_name'] ?? ''),
            'qty'            => $qty,
            'unit_price'     => (float) $l['unit_price'],
            'cost_price'     => (float) $l['cost_price'],
            'dist'           => $perBranch,
            'dist_total'     => $dTotal,
            'in_store'       => round($qty - $dTotal, 3),   // اللي فضل في المخزن
        ];

        $tot['invoices']++;
        if ($isRet) $tot['returned'] += $qty;
        else        $tot['bought']   += $qty;
        $tot['distributed'] += $dTotal;
    }

    $tot['net'] = round($tot['bought'] - $tot['returned'], 3);
    return ['item' => $item, 'rows' => $rows, 'totals' => $tot, 'truncated' => $truncated];
}

/**
 * توزيعات مجموعة فواتير/أصناف — استعلام واحد بالمفتاح المركب (docNo|companyBranch|docType|itemCode).
 * بترجّع ["docNo|cb|dt|itemCode" => [branch_id => qty]]
 * مع التوافق مع السجلات القديمة docNo|*|*|itemCode و docNo|itemCode
 */
function ilk_distribution_map(array $docNos, array $codes): array
{
    $docNos = array_values(array_filter($docNos));
    $codes  = array_values(array_filter($codes));
    if (!$docNos || !$codes) return [];

    $pd = implode(',', array_fill(0, count($docNos), '?'));
    $pc = implode(',', array_fill(0, count($codes), '?'));

    try {
        $st = db()->prepare(
            "SELECT d.invoice_no, d.company_branch, d.doc_type, dl.notes AS item_code, dl.branch_id,
                    SUM(dl.amount) AS qty
             FROM invoice_distribution d
             JOIN invoice_distribution_lines dl ON dl.distribution_id = d.id
             WHERE d.invoice_no IN ($pd) AND dl.notes IN ($pc)
             GROUP BY d.invoice_no, d.company_branch, d.doc_type, dl.notes, dl.branch_id"
        );
        $st->execute(array_merge($docNos, $codes));
    } catch (Throwable $ex) {
        // لو الأعمدة لسه مش موجودة في الجدول (قبل تشغيل ملف الترقية)
        try {
            $st = db()->prepare(
                "SELECT d.invoice_no, NULL AS company_branch, NULL AS doc_type, dl.notes AS item_code, dl.branch_id,
                        SUM(dl.amount) AS qty
                 FROM invoice_distribution d
                 JOIN invoice_distribution_lines dl ON dl.distribution_id = d.id
                 WHERE d.invoice_no IN ($pd) AND dl.notes IN ($pc)
                 GROUP BY d.invoice_no, dl.notes, dl.branch_id"
            );
            $st->execute(array_merge($docNos, $codes));
        } catch (Throwable $ex2) {
            return [];
        }
    }

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $doc = trim((string) $r['invoice_no']);
        $cb  = ($r['company_branch'] !== null && $r['company_branch'] !== '') ? (string) $r['company_branch'] : '*';
        $dt  = ($r['doc_type'] !== null && $r['doc_type'] !== '') ? (string) $r['doc_type'] : '*';
        $code = (string) $r['item_code'];

        $key = $doc . '|' . $cb . '|' . $dt . '|' . $code;
        $out[$key][(int) $r['branch_id']] = (float) $r['qty'];

        // دائماً نسجّل المفتاح القديم والمفتاح العام كاحتياطي للمطابقة
        $oldKey = $doc . '|' . $code;
        if (!isset($out[$oldKey][(int) $r['branch_id']])) {
            $out[$oldKey][(int) $r['branch_id']] = (float) $r['qty'];
        }
        $fallbackKey = $doc . '|*|*|' . $code;
        if (!isset($out[$fallbackKey][(int) $r['branch_id']])) {
            $out[$fallbackKey][(int) $r['branch_id']] = (float) $r['qty'];
        }
    }
    return $out;
}

// =====================================================================
//  بحث الأصناف جوه الفواتير — لبوب أب «تحديد أصناف» في المجموعات
//  2026-08-14
// =====================================================================
//
//  الفرق عن ilk_item_history: ده بيرجّع **سطور قابلة للاختيار**
//  (فاتورة + كود) عشان المستخدم يضيفها لمجموعة، وبيشمل الفواتير
//  **المرحّلة والغير محفوظة** مع بعض (قرار محمد 2026-08-14).
//
//  ⚠️ الصنف ممكن يتكرر في أكتر من سطر جوه نفس الفاتورة — بندمجهم
//     في صف واحد بالكمية المجمّعة، لأن التوزيع مفتاحه الكود مش
//     السطر (شوف CONTEXT.md §٢٣).
//
//  كله محلي على hq_* — صفر نداءات للجسر.

const ILK_PICK_LIMIT = 40;   // سقف نتايج البحث

/**
 * دوّر على صنف جوه فواتير الشراء والمردود.
 *
 * @param string $term كود كامل أو جزء من الاسم
 * @return array صفوف: sth_id · suffix · invoice_no · doc_type ·
 *               type_label · supplier · date · item_code · item_name · qty
 */
function ilk_pick_search(string $term, int $limit = ILK_PICK_LIMIT): array
{
    $term = trim($term);
    if ($term === '' || mb_strlen($term) < 2 || !ilk_ready()) return [];
    if ($limit < 1 || $limit > 100) $limit = ILK_PICK_LIMIT;

    $pdo  = db();
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    $rows = [];

    // ---- (١) الفواتير المرحّلة ----
    try {
        $st = $pdo->prepare(
            "SELECT l.sth_id, l.suffix, l.item_code, l.item_name, l.quantity,
                    h.doc_no, h.doc_type, h.supplier_name, h.invoice_date
             FROM hq_invoice_lines l
             JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
             WHERE l.item_code = ? OR l.item_code LIKE ? ESCAPE '!' OR l.item_name LIKE ? ESCAPE '!'
             ORDER BY (l.item_code = ?) DESC, h.invoice_date DESC
             LIMIT $limit"
        );
        $st->execute([$term, $like, $like, $term]);
        foreach ($st->fetchAll() as $r) $rows[] = $r + ['_unposted' => 0];
    } catch (Throwable $ex) { /* تجاهل */ }

    // ---- (٢) الفواتير غير المحفوظة ----
    try {
        $st = $pdo->prepare(
            "SELECT u.sth_id, u.item_code, u.item_name, u.quantity,
                    n.doc_no, n.doc_type, n.supplier_name, n.invoice_date
             FROM hq_unposted_lines u
             JOIN hq_unposted n ON n.sth_id = u.sth_id
             WHERE u.item_code = ? OR u.item_code LIKE ? ESCAPE '!' OR u.item_name LIKE ? ESCAPE '!'
             ORDER BY (u.item_code = ?) DESC, n.invoice_date DESC
             LIMIT $limit"
        );
        $st->execute([$term, $like, $like, $term]);
        foreach ($st->fetchAll() as $r) {
            $r['suffix'] = 'YY_MM';   // ثابت للفواتير غير المحفوظة
            $rows[] = $r + ['_unposted' => 1];
        }
    } catch (Throwable $ex) { /* تجاهل */ }

    if (!$rows) return [];

    // ---- دمج السطر المكرر جوه نفس الفاتورة ----
    $merged = [];
    foreach ($rows as $r) {
        $sth  = (int) $r['sth_id'];
        $suf  = (string) $r['suffix'];
        $code = trim((string) $r['item_code']);
        if ($code === '') continue;

        $key = $sth . '|' . $suf . '|' . $code;
        if (isset($merged[$key])) {
            $merged[$key]['qty'] += (float) $r['quantity'];
            continue;
        }
        $isRet = ((int) $r['doc_type'] === 6);
        $merged[$key] = [
            'key'        => $key,
            'sth_id'     => $sth,
            'suffix'     => $suf,
            'invoice_no' => trim((string) ($r['doc_no'] ?? '')),
            'doc_type'   => (int) $r['doc_type'],
            'type_label' => $isRet ? 'مردود' : 'فاتورة شراء',
            'is_return'  => $isRet,
            'unposted'   => (int) $r['_unposted'] === 1,
            'supplier'   => (string) ($r['supplier_name'] ?? ''),
            'date'       => (string) ($r['invoice_date'] ?? ''),
            'item_code'  => $code,
            'item_name'  => (string) ($r['item_name'] ?? ''),
            'qty'        => (float) $r['quantity'],
        ];
    }

    // الأحدث الأول
    $out = array_values($merged);
    usort($out, fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

    return array_slice($out, 0, $limit);
}
