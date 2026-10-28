<?php
/**
 * item_card_lib.php — كارت الصنف: كل حركته في كل مخزن + الرصيد دلوقتي (بريف ٢١٢)
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  قراية بس — مفيش أي INSERT/UPDATE/DELETE هنا ولا في الصفحة.
 *
 *  الكارت بيجاوب على سؤالين:
 *    ١) الصنف ده رصيده كام دلوقتي في كل مخزن؟ (الإدارة + ٤ فروع)
 *    ٢) اتحرّك إزاي في الفترة اللي فاتت؟ (شراء · مردود · تحويل · بيع · مرتجع)
 *
 *  مصادر الداتا (كلها المرآة):
 *    hq_codes · hq_items · hq_item_cats · hq_cat_names  ← الأكواد والاسم والقسم
 *    hq_balances (الإدارة sto_id=1) · hq_branch_balances (الفروع) ← الرصيد
 *    hq_invoices + hq_invoice_lines   ← شراء/مردود/تحويلات كل المخازن
 *    br_invoices + br_invoice_lines   ← مبيعات الفروع
 *    branches.sto_id                  ← ربط المخزن بالفرع
 *
 *  🔴 كل كمية بتعدّي على item_line_pieces() — الرصيد والحركة **بالقطعة**
 *     دايماً، مش بوحدة السطر. (الغلطة ٣٠/٩: ٨ آلاف قطعة وهمي.)
 *  🔴 عدد الاستعلامات ثابت (٥ بالكتير) مهما كان عدد الحركات.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/item_units_lib.php';        // item_line_pieces() · item_code_factors()
require_once __DIR__ . '/branch_invoices_lib.php';   // br_inv_map() — مفتاح وكيل كل فرع
require_once __DIR__ . '/sensitive_lib.php';         // sens_mask() على الأسعار والتكلفة

/** الفترة الافتراضية للحركات */
const IC_DEFAULT_DAYS = 90;

/** أقصى عدد سطور بنجيبها من كل جهة في المرة */
const IC_ROWS_LIMIT = 1000;

/** الموردين الخاصين في المرآة */
const IC_SUPPLIER_OPENING    = 1000195;   // رصيد افتتاحي
const IC_SUPPLIER_CONSUMABLE = 1000192;   // مستهلكات

// =====================================================================
//  عداد الاستعلامات — بيتطبع في التقرير وبيمنع «استعلام جوه حلقة»
//  لو الرقم طلع أكبر من ٥ يبقى حد كسر القاعدة.
// =====================================================================

function ic_q_count(): int
{
    return (int) ($GLOBALS['IC_QUERIES'] ?? 0);
}

/**
 * كل استعلام في الملف ده بيعدّي من هنا (ولو مرة واحدة في التقرير بنطبع العدد).
 *
 * @param array<int|string,mixed> $params
 */
function ic_q(string $sql, array $params = []): PDOStatement
{
    $GLOBALS['IC_QUERIES'] = ic_q_count() + 1;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// =====================================================================
//  الموردين الداخليين (التحويلات) — المرآة بتسجّلها شراء/مردود باسم المورد
// =====================================================================

/** @return array<int,string> supplier_id => اسم المورد */
function ic_internal_suppliers(): array
{
    return [
        4       => 'فرع بونجور',
        1000180 => 'فرع دجلة',
        1000181 => 'فرع ABC',
        3000091 => 'فرع الفجالة',
        1000182 => 'الرئيسية (الادارة)',
        1000245 => 'مكتبة أشراقة',
        1000252 => 'مكتبة أشراقة',
    ];
}

/** أنواع الحركة — للفلتر والفلاج */
function ic_types(): array
{
    return [
        'sale'            => 'بيع',
        'sale_return'     => 'مرتجع بيع',
        'purchase'        => 'شراء من مورد',
        'purchase_return' => 'مردود لمورد',
        'transfer_in'     => 'تحويل وارد',
        'transfer_out'    => 'تحويل صادر',
        'opening'         => 'رصيد افتتاحي',
        'consumable'      => 'مستهلكات',
    ];
}

/**
 * تصنيف سطر الحركة — أول شرط ينطبق هو اللي بيكسب (بريف ٢١٢).
 *
 * @return array{key:string,label:string,sign:int,chip:string}
 *         sign: +1 داخل المخزن · -1 خارج · 0 مش معروف
 */
function ic_classify(string $src, int $docType, int $supplierId): array
{
    // ١) الفروع: مبيعات ومرتجعها
    if ($src === 'br') {
        if ($docType === 4) {
            return ['key' => 'sale', 'label' => 'بيع', 'sign' => -1, 'chip' => 'fchip-neg'];
        }
        if ($docType === 7 || $docType === 17) {
            return ['key' => 'sale_return', 'label' => 'مرتجع بيع', 'sign' => 1, 'chip' => 'fchip-pos'];
        }
        return ['key' => 'unknown', 'label' => 'نوع مش معروف', 'sign' => 0, 'chip' => 'fchip-warn'];
    }

    // ٢) رصيد افتتاحي
    if ($supplierId === IC_SUPPLIER_OPENING) {
        return ['key' => 'opening', 'label' => 'رصيد افتتاحي', 'sign' => 1, 'chip' => 'fchip-mute'];
    }

    // ٣) مستهلكات — داخلة (شراء) أو خارجة (مردود)
    if ($supplierId === IC_SUPPLIER_CONSUMABLE) {
        return [
            'key'   => 'consumable',
            'label' => 'مستهلكات',
            'sign'  => $docType === 6 ? -1 : 1,
            'chip'  => 'fchip-warn',
        ];
    }

    // ٤) تحويل بين المخازن — بيتسجّل شراء (وارد) أو مردود (صادر)
    $internal = ic_internal_suppliers();
    if (isset($internal[$supplierId])) {
        if ($docType === 2) {
            return ['key' => 'transfer_in', 'label' => 'تحويل وارد', 'sign' => 1, 'chip' => 'fchip-pos'];
        }
        if ($docType === 6) {
            return ['key' => 'transfer_out', 'label' => 'تحويل صادر', 'sign' => -1, 'chip' => 'fchip-neg'];
        }
    }

    // ٥) مورد عادي
    if ($docType === 2) {
        return ['key' => 'purchase', 'label' => 'شراء من مورد', 'sign' => 1, 'chip' => 'fchip-pos'];
    }
    if ($docType === 6) {
        return ['key' => 'purchase_return', 'label' => 'مردود لمورد', 'sign' => -1, 'chip' => 'fchip-neg'];
    }

    return ['key' => 'unknown', 'label' => 'نوع مش معروف', 'sign' => 0, 'chip' => 'fchip-warn'];
}

// =====================================================================
//  الفترة والتواريخ
// =====================================================================

/** تاريخ صحيح بالشكل YYYY-MM-DD وإلا null */
function ic_date(?string $value): ?string
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/**
 * الفترة المطلوبة: الافتراضي آخر ٩٠ يوم، والفلتر بيعدّل.
 *
 * @return array{from:string,to:string,from_dt:string,to_dt:string,days:int}
 */
function ic_period(?string $from, ?string $to): array
{
    $today   = date('Y-m-d');
    $defFrom = date('Y-m-d', strtotime('-' . (IC_DEFAULT_DAYS - 1) . ' days'));

    $from = ic_date($from) ?? $defFrom;
    $to   = ic_date($to) ?? $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;

    return [
        'from'    => $from,
        'to'      => $to,
        'from_dt' => $from . ' 00:00:00',
        // آخر اليوم كامل — الحركة اللي الساعة ٢٣:٥٩ لازم تبان
        'to_dt'   => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00',
        'days'    => max(1, $days),
    ];
}

// =====================================================================
//  تنسيقات
// =====================================================================

/** رقم كمية: ٣ خانات عشرية بالكتير ومن غير أصفار زيادة */
function ic_num(float $value): string
{
    $s = number_format($value, 3, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

/** فلوس بالشكل العادي (من غير علامة عملة) */
function ic_money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

/**
 * فلوس جوه sens_mask — التكلفة والأسعار مابتظهرش غير بعد فتح الأرقام.
 * بترجّع HTML جاهز للعرض (نفس اللي باقي الموقع بيعمله).
 */
function ic_money_html(float $value): string
{
    return sens_mask(ic_money($value));
}

/** هل الأرقام مقفولة دلوقتي؟ (الصفحة بتعرف منه إن السعر متخفي) */
function ic_sens_locked(): bool
{
    return !sens_unlocked();
}

// =====================================================================
//  الفهرس (البحث)
// =====================================================================

/**
 * بحث واحد بيفهم تلات حاجات: باركود (كود)، كود صنف، اسم.
 *
 * @return array{exact:?int,items:array<int,array{item_id:int,code:string,name:string,label:string}>}
 */
function ic_search(string $q, int $limit = 20): array
{
    $q = trim($q);
    $out = ['exact' => null, 'items' => []];
    if ($q === '') {
        return $out;
    }

    // ١) كود مطابق بالحرف (باركود الباكو والقطعة الاتنين أكواد في hq_codes)
    $row = ic_q(
        'SELECT code, item_id, name, code_type FROM hq_codes WHERE code = ? LIMIT 1',
        [$q]
    )->fetch(PDO::FETCH_ASSOC);
    if ($row && (int) $row['item_id'] > 0) {
        $out['exact'] = (int) $row['item_id'];
        $out['items'][] = [
            'item_id' => (int) $row['item_id'],
            'code'    => (string) $row['code'],
            'name'    => (string) ($row['name'] ?? ''),
            'label'   => (int) $row['code_type'] === 0 ? 'كود أساسي' : 'كود فرعي',
        ];
        return $out;
    }

    // ٢) باركود متسجّل في hq_items بس (صنف لسه مالوش أكواد في المرآة)
    $row = ic_q(
        'SELECT item_id, barcode, name FROM hq_items WHERE barcode = ? LIMIT 1',
        [$q]
    )->fetch(PDO::FETCH_ASSOC);
    if ($row && (int) $row['item_id'] > 0) {
        $out['exact'] = (int) $row['item_id'];
        $out['items'][] = [
            'item_id' => (int) $row['item_id'],
            'code'    => (string) $row['barcode'],
            'name'    => (string) ($row['name'] ?? ''),
            'label'   => 'باركود',
        ];
        return $out;
    }

    // ٣) بحث بالاسم — كل كلمة بتتدور لوحدها (AND) زي استعلام الأرصدة
    if (mb_strlen($q) < 2) {
        return $out;
    }
    $words  = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $where  = [];
    $params = [];
    foreach ($words as $w) {
        $where[]  = 'name LIKE ?';
        $params[] = '%' . $w . '%';
    }
    $rows = ic_q(
        'SELECT item_id, code, name, code_type FROM hq_codes
          WHERE ' . implode(' AND ', $where) . ' AND item_id > 0
          ORDER BY (code_type = 0) DESC, name
          LIMIT 80',
        $params
    )->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rows as $r) {
        $iid = (int) $r['item_id'];
        if (isset($seen[$iid])) {
            continue;
        }
        $seen[$iid] = true;
        $out['items'][] = [
            'item_id' => $iid,
            'code'    => (string) $r['code'],
            'name'    => (string) ($r['name'] ?? ''),
            'label'   => (int) $r['code_type'] === 0 ? 'كود أساسي' : 'كود فرعي',
        ];
        if (count($out['items']) >= $limit) {
            break;
        }
    }

    return $out;
}

// =====================================================================
//  الكارت
// =====================================================================

/**
 * الكارت كامل: الرأس + الأكواد + الأرصدة + الحركات.
 *
 * 🔴 ٥ استعلامات بالكتير: (١) الأكواد والرأس (٢) الفروع والأرصدة
 *    (٣) مشتريات/تحويلات الإدارة (٤) مبيعات الفروع (٥) معاملات الوحدات.
 *
 * @return array<string,mixed>|null  null لو مفيش أي أثر للصنف
 */
function ic_card(int $itemId, ?string $from, ?string $to, int $stoId, string $typeKey): ?array
{
    if ($itemId <= 0) {
        return null;
    }
    $period = ic_period($from, $to);

    // ---------- (١) الأكواد + رأس الكارت ----------
    // من hq_codes، ولو الصنف مالوش أكواد في المرآة بيفضل سطر واحد بالبيانات
    // اللي في hq_items — عشان الكارت مايطلعش فاضي على صنف ليه حركات.
    $codeRows = ic_q(
        "SELECT c.code, c.code_type, c.unit_factor, c.price, c.cost, c.name AS code_name,
                i.barcode, i.name AS item_name, i.sale_price, cat.name AS cat_name
           FROM (SELECT ? AS item_id) x
           LEFT JOIN hq_codes c ON c.item_id = x.item_id
           LEFT JOIN (SELECT item_id, MIN(barcode) AS barcode, MIN(name) AS name,
                             MAX(sale_price) AS sale_price
                        FROM hq_items WHERE item_id = ? GROUP BY item_id) i
                  ON i.item_id = x.item_id
           LEFT JOIN (SELECT ic.item_id, MIN(cn.name) AS name
                        FROM hq_item_cats ic
                        JOIN hq_cat_names cn ON cn.is1_id = ic.is1_id
                       WHERE ic.item_id = ? GROUP BY ic.item_id) cat
                  ON cat.item_id = x.item_id
          ORDER BY c.code_type ASC, c.code ASC",
        [$itemId, $itemId, $itemId]
    )->fetchAll(PDO::FETCH_ASSOC);

    $codes     = [];
    $baseCode  = '';
    $baseCost  = null;
    $itemName  = '';
    $barcode   = '';
    $salePrice = null;
    $catName   = '';

    foreach ($codeRows as $r) {
        if ($itemName === '' && !empty($r['item_name'])) {
            $itemName = (string) $r['item_name'];
        }
        if ($barcode === '' && !empty($r['barcode'])) {
            $barcode = (string) $r['barcode'];
        }
        if ($salePrice === null && $r['sale_price'] !== null) {
            $salePrice = (float) $r['sale_price'];
        }
        if ($catName === '' && !empty($r['cat_name'])) {
            $catName = (string) $r['cat_name'];
        }
        if ($r['code'] === null) {
            continue;   // الصنف مالوش كود — بنكمّل ببيانات hq_items
        }
        $codeType = (int) $r['code_type'];
        $codes[] = [
            'code'       => (string) $r['code'],
            'is_base'    => $codeType === 0,
            'type_label' => $codeType === 0 ? 'أساسي' : 'فرعي',
            'factor'     => (float) ($r['unit_factor'] ?? 1),
            'price_html' => ic_money_html((float) ($r['price'] ?? 0)),
            'cost_html'  => ic_money_html((float) ($r['cost'] ?? 0)),
        ];
        if ($codeType === 0 && $baseCode === '') {
            $baseCode = (string) $r['code'];
            $baseCost = (float) ($r['cost'] ?? 0);
            if ($itemName === '' && !empty($r['code_name'])) {
                $itemName = (string) $r['code_name'];
            }
            if ($salePrice === null && $r['price'] !== null) {
                $salePrice = (float) $r['price'];
            }
        }
    }
    // الاسم من الكود الأساسي أولاً (زي باقي الشاشات)
    if ($baseCode !== '') {
        foreach ($codeRows as $r) {
            if ((string) ($r['code'] ?? '') === $baseCode && !empty($r['code_name'])) {
                $itemName = (string) $r['code_name'];
                break;
            }
        }
    }

    // ---------- (٢) الفروع + الأرصدة (استعلام واحد) ----------
    // الإدارة بتاخد رصيدها من hq_balances، والفروع من hq_branch_balances،
    // ولو الفرع مالوش صف هناك بناخد صف hq_balances بتاعه بدل ما نوري صفر كذب.
    $storeRows = ic_q(
        "SELECT b.id AS branch_id, b.name, b.sto_id, b.in_receiving,
                bb.balance AS br_balance, bb.updated_at AS br_updated,
                hb.balance AS hq_balance, hb.updated_at AS hq_updated
           FROM branches b
           LEFT JOIN (SELECT sto_id, balance, updated_at
                        FROM hq_branch_balances WHERE item_id = ?) bb ON bb.sto_id = b.sto_id
           LEFT JOIN (SELECT sto_id, balance, updated_at
                        FROM hq_balances WHERE item_id = ?) hb ON hb.sto_id = b.sto_id
          WHERE b.is_active = 1 AND b.sto_id IS NOT NULL
          ORDER BY (b.in_receiving = 0) DESC, b.name",
        [$itemId, $itemId]
    )->fetchAll(PDO::FETCH_ASSOC);

    $agentByBranch = [];
    foreach (br_inv_map() as $b) {
        $agentByBranch[(int) $b['id']] = (string) $b['agent'];
    }

    $stores     = [];
    $stoAgent   = [];      // sto_id => agent_key
    $storesById = [];      // sto_id => اسم المخزن
    $balTotal   = 0.0;
    $lastUpdate = '';

    foreach ($storeRows as $r) {
        $psid    = (int) $r['sto_id'];
        $isAdmin = (int) $r['in_receiving'] === 0;
        $agent   = $agentByBranch[(int) $r['branch_id']] ?? '';
        $brBal   = $r['br_balance'];
        $hqBal   = $r['hq_balance'];

        if ($isAdmin) {
            $bal = $hqBal !== null ? (float) $hqBal : ($brBal !== null ? (float) $brBal : 0.0);
            $upd = $hqBal !== null ? (string) $r['hq_updated'] : (string) ($r['br_updated'] ?? '');
        } else {
            $bal = $brBal !== null ? (float) $brBal : ($hqBal !== null ? (float) $hqBal : 0.0);
            $upd = $brBal !== null ? (string) $r['br_updated'] : (string) ($r['hq_updated'] ?? '');
        }

        if ($agent !== '') {
            $stoAgent[$psid] = $agent;
        }
        $storesById[$psid] = (string) $r['name'];
        if ($bal !== null) {
            $balTotal += $bal;
        }
        if ($upd !== '' && $upd > $lastUpdate) {
            $lastUpdate = $upd;
        }

        $stores[] = [
            'sto_id'      => $psid,
            'name'        => (string) $r['name'],
            'is_admin'    => $isAdmin,
            'agent'       => $agent,
            'balance'     => $bal,
            'balance_txt' => ic_num($bal),
            'updated'     => $upd,
            'moved'       => 0.0,      // بيتملى بعد الحركات
        ];
    }

    // ---------- (٣) مشتريات/مردود/تحويلات (hq_*) ----------
    $hqRows = ic_q(
        "SELECT h.sth_id, h.suffix, h.doc_no, h.doc_type, h.supplier_id, h.supplier_name,
                h.company_branch, h.invoice_date,
                l.line_no, l.quantity, l.unit_price, l.cost_price, l.unit_factor, l.item_code
           FROM hq_invoice_lines l
           JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
          WHERE l.item_id = ?
            AND h.invoice_date >= ? AND h.invoice_date < ?
            AND (? = 0 OR h.company_branch = ?)
          ORDER BY h.invoice_date DESC, h.sth_id DESC, l.line_no ASC
          LIMIT " . IC_ROWS_LIMIT,
        [$itemId, $period['from_dt'], $period['to_dt'], $stoId, $stoId]
    )->fetchAll(PDO::FETCH_ASSOC);

    // ---------- (٤) مبيعات الفروع (br_*) ----------
    $brRows = [];
    if ($stoId === 0) {
        $brRows = ic_q(
            "SELECT b.agent_key, b.sth_id, b.suffix, b.doc_no, b.doc_type, b.supplier_id, b.supplier_name,
                    b.company_branch, b.invoice_date,
                    bl.line_no, bl.quantity, bl.unit_price, bl.cost_price, bl.unit_factor, bl.item_code
               FROM br_invoice_lines bl
               JOIN br_invoices b ON b.agent_key = bl.agent_key AND b.sth_id = bl.sth_id AND b.suffix = bl.suffix
              WHERE bl.item_id = ?
                AND b.invoice_date >= ? AND b.invoice_date < ?
              ORDER BY b.invoice_date DESC, b.sth_id DESC, bl.line_no ASC
              LIMIT " . IC_ROWS_LIMIT,
            [$itemId, $period['from_dt'], $period['to_dt']]
        )->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($stoAgent[$stoId])) {
        $brRows = ic_q(
            "SELECT b.agent_key, b.sth_id, b.suffix, b.doc_no, b.doc_type, b.supplier_id, b.supplier_name,
                    b.company_branch, b.invoice_date,
                    bl.line_no, bl.quantity, bl.unit_price, bl.cost_price, bl.unit_factor, bl.item_code
               FROM br_invoice_lines bl
               JOIN br_invoices b ON b.agent_key = bl.agent_key AND b.sth_id = bl.sth_id AND b.suffix = bl.suffix
              WHERE bl.item_id = ? AND b.agent_key = ?
                AND b.invoice_date >= ? AND b.invoice_date < ?
              ORDER BY b.invoice_date DESC, b.sth_id DESC, bl.line_no ASC
              LIMIT " . IC_ROWS_LIMIT,
            [$itemId, $stoAgent[$stoId], $period['from_dt'], $period['to_dt']]
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    // مخزن الإدارة مالوش مبيعات في br_* — مفيش استعلام أصلاً.

    // ---------- (٥) معاملات الوحدات لكل أكواد الصفحة (استعلام واحد) ----------
    $pageCodes = array_column($codes, 'code');
    foreach ($hqRows as $r) {
        $pageCodes[] = (string) ($r['item_code'] ?? '');
    }
    foreach ($brRows as $r) {
        $pageCodes[] = (string) ($r['item_code'] ?? '');
    }
    $factors = [];
    if ($pageCodes) {
        // item_code_factors() بتعمل استعلام واحد بنفسها — بنعدّه في العداد
        $GLOBALS['IC_QUERIES'] = ic_q_count() + 1;
        $factors = item_code_factors(db(), $pageCodes);
    }

    // ---------- الدمج والتصنيف (في PHP — مفيش استعلام جوه حلقة) ----------
    $moves   = [];
    $hqShown = 0;
    $brShown = 0;

    foreach ($hqRows as $r) {
        $hqShown++;
        $psid = (int) ($r['company_branch'] ?? 0);
        $cls  = ic_classify('hq', (int) $r['doc_type'], (int) $r['supplier_id']);
        $moves[] = ic_move_row(
            $r,
            $cls,
            $psid,
            $storesById[$psid] ?? '',
            $factors,
            false
        );
    }
    foreach ($brRows as $r) {
        $brShown++;
        $psid = 0;
        foreach ($stoAgent as $sid => $ag) {
            if ($ag === (string) $r['agent_key']) {
                $psid = (int) $sid;
                break;
            }
        }
        $cls = ic_classify('br', (int) $r['doc_type'], (int) $r['supplier_id']);
        $moves[] = ic_move_row(
            $r,
            $cls,
            $psid,
            $storesById[$psid] ?? '',
            $factors,
            true
        );
    }

    // الترتيب: الأحدث فوق (الاستعلامين مرتبين، والدمج بيلخبط — فنرتّب هنا)
    usort($moves, function (array $a, array $b): int {
        return [$b['date'], $b['sth_id'], $b['line_no']] <=> [$a['date'], $a['sth_id'], $a['line_no']];
    });

    // فلتر نوع الحركة + حساب حركة الفترة لكل مخزن
    $shown  = [];
    $moved  = [];      // sto_id => مجموع الفترة (داخل - خارج)
    $periodTotal = 0.0;
    foreach ($moves as $m) {
        if ($m['sto_id'] > 0) {
            $moved[$m['sto_id']] = ($moved[$m['sto_id']] ?? 0.0) + $m['qty'];
        }
        $periodTotal += $m['qty'];
        if ($typeKey !== '' && $m['type'] !== $typeKey) {
            continue;
        }
        $shown[] = $m;
    }

    foreach ($stores as $i => $s) {
        $sid = $s['sto_id'];
        $stores[$i]['moved']     = $moved[$sid] ?? 0.0;
        $stores[$i]['moved_txt'] = ic_num($stores[$i]['moved']);
    }

    $truncated = ($hqShown >= IC_ROWS_LIMIT) || ($brShown >= IC_ROWS_LIMIT);

    // مفيش أثر للصنف خالص
    if (!$codes && $itemName === '' && !$shown && $balTotal == 0.0) {
        return null;
    }

    return [
        'item' => [
            'item_id'     => $itemId,
            'name'        => $itemName !== '' ? $itemName : ('صنف #' . $itemId),
            'base_code'   => $baseCode !== '' ? $baseCode : $barcode,
            'barcode'     => $barcode,
            'category'    => $catName,
            'sale_price_html' => ic_money_html($salePrice ?? 0.0),
            'cost_html'       => ic_money_html($baseCost ?? 0.0),
            'codes'       => $codes,
        ],
        'period' => [
            'from'  => $period['from'],
            'to'    => $period['to'],
            'days'  => $period['days'],
            'sto'   => $stoId,
            'type'  => $typeKey,
        ],
        'stores' => $stores,
        'totals' => [
            'balance'     => $balTotal,
            'balance_txt' => ic_num($balTotal),
            'moved'       => $periodTotal,
            'moved_txt'   => ic_num($periodTotal),
            'updated'     => $lastUpdate,
        ],
        'movements' => $shown,
        'counts'    => [
            'hq'        => $hqShown,
            'br'        => $brShown,
            'shown'     => count($shown),
            'limit'     => IC_ROWS_LIMIT,
            'truncated' => $truncated,
            'queries'   => ic_q_count(),
        ],
        'sens' => ['locked' => ic_sens_locked()],
    ];
}

/**
 * تحويل سطر فاتورة لسطر كارت (الكمية بالقطعة + الوحدة الأصلية).
 *
 * @param array<string,mixed> $r سطر الفاتورة
 * @param array{key:string,label:string,sign:int,chip:string} $cls التصنيف
 * @param array<string,array{item_id:int,unit_factor:float,code_type:int}> $factors معاملات الأكواد
 */
function ic_move_row(array $r, array $cls, int $stoId, string $storeName, array $factors, bool $isBranch): array
{
    $itemCode   = (string) ($r['item_code'] ?? '');
    $lineFactor = $r['unit_factor'] !== null ? (float) $r['unit_factor'] : null;
    $codeFactor = isset($factors[$itemCode]) ? (float) $factors[$itemCode]['unit_factor'] : null;

    $calc   = item_line_pieces((float) ($r['quantity'] ?? 0), $lineFactor, $codeFactor);
    $pieces = $calc['pieces'] * $cls['sign'];

    $unit = $calc['factor'] > 1.0
        ? 'باكو ' . ic_num($calc['factor'])
        : 'قطعة';

    return [
        'date'     => (string) ($r['invoice_date'] ?? ''),
        'sto_id'   => $stoId,
        'store'    => $storeName,
        'type'     => $cls['key'],
        'label'    => $cls['label'],
        'chip'     => $cls['chip'],
        'party'    => trim((string) ($r['supplier_name'] ?? '')),
        'doc'      => (string) ($r['doc_no'] ?? ''),
        'qty'      => $pieces,
        'qty_txt'  => ($pieces > 0 ? '+' : ($pieces < 0 ? '−' : '')) . ic_num(abs($pieces)),
        'raw_qty'  => (float) ($r['quantity'] ?? 0),
        'factor'   => $calc['factor'],
        'source'   => $calc['source'],
        // العلامة الصغيرة بتظهر بس لما المعامل اتقدّر من الكود **لفرع فعلي**
        // (باكو مثلًا). كود القطعة معامله ١ — مش محتاج علامة على كل سطر.
        'estimated' => $calc['source'] === 'code' && $calc['factor'] > 1.0,
        'unit'     => $unit,
        // السعر رقم لما الأرقام مفتوحة، وnull وهي مقفولة (الإكسيل كمان)
        'price'      => ic_sens_locked() ? null : (float) ($r['unit_price'] ?? 0),
        'price_html' => ic_money_html((float) ($r['unit_price'] ?? 0)),
        'sth_id'   => (int) $r['sth_id'],
        'line_no'  => (int) ($r['line_no'] ?? 0),
        'branch'   => $isBranch ? (string) ($r['agent_key'] ?? '') : '',
    ];
}
