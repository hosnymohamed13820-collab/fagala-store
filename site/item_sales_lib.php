<?php
/**
 * item_sales_lib.php — مكتبة تحليل المبيعات: حالة الرصيد · حركة البيع · الأرباح
 * نظام الفجالة ستور المالي (بريف ٩٦)
 *
 * دوال برمجية ترجع arrays فقط بدون أي HTML أو echo.
 * ⚠️ لا توجد استعلامات داخل حلقات (foreach / while) — أقصى عدد استعلامات ٥ لكامل الصفحة.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis_lib.php';

// ثوابت تنبيه المرتفع العالي (بريف ١٠٦)
const ISL_RET_WARN_PCT = 10;
const ISL_RET_WARN_MIN = 3;

/**
 * تحديد وحساب فترة التقرير وعدد الأيام.
 * الافتراضي: آخر ٣٠ يوماً شاملة اليوم.
 *
 * @param string|null $from تاريخ البداية (YYYY-MM-DD)
 * @param string|null $to تاريخ النهاية (YYYY-MM-DD)
 * @return array{from:string,to:string,to_param:string,days:int}
 */
function isl_period(?string $from, ?string $to): array
{
    $toClean = trim((string) $to);
    if ($toClean === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toClean)) {
        $toClean = date('Y-m-d');
    }

    $fromClean = trim((string) $from);
    if ($fromClean === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromClean)) {
        $fromClean = date('Y-m-d', strtotime('-29 days', strtotime($toClean)));
    }

    // حساب عدد الأيام الشاملة: DATEDIFF(to, from) + 1
    $t1 = strtotime($fromClean);
    $t2 = strtotime($toClean);
    $diffDays = (int) round(($t2 - $t1) / 86400);
    $days = max(1, $diffDays + 1);

    // لنهاية الفترة في SQL: إضافة يوم للمقارنة الصارمة مع حقول datetime (< to_param)
    $toParam = date('Y-m-d', strtotime($toClean . ' +1 day'));

    return [
        'from'     => $fromClean,
        'to'       => $toClean,
        'to_param' => $toParam,
        'days'     => $days,
    ];
}

/**
 * استخراج خريطة الوكيل إلى رقم المخزن (agent_key -> sto_id) من الأرصدة الحالية.
 *
 * @return array<string,int>
 */
function isl_agent_store_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    $pdo = db();
    try {
        $st = $pdo->query('SELECT agent_key, sto_id FROM hq_branch_balances GROUP BY agent_key, sto_id');
        foreach ($st as $r) {
            $ak = trim((string) ($r['agent_key'] ?? ''));
            if ($ak !== '') {
                $map[$ak] = (int) $r['sto_id'];
            }
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

/**
 * استخراج بيانات الأصناف الأساسية (الاسم والباركود).
 *
 * @return array<int,array{item_id:int,barcode:string,name:string}>
 */
function isl_items_map(): array
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }

    $items = [];
    $pdo = db();
    try {
        $st = $pdo->query('SELECT item_id, barcode, name FROM hq_items');
        foreach ($st as $r) {
            $iid = (int) $r['item_id'];
            $items[$iid] = [
                'item_id' => $iid,
                'barcode' => (string) ($r['barcode'] ?? ''),
                'name'    => (string) ($r['name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $items = [];
    }
    return $items;
}

/**
 * استخراج تصنيفات الأصناف وأسماء الأقسام.
 *
 * @return array{by_item:array<int,array{is1_id:int,cat_name:string}>,all_cats:array<int,string>}
 */
function isl_categories_map(): array
{
    static $cats = null;
    if ($cats !== null) {
        return $cats;
    }

    $byItem = [];
    $allCats = [];
    $pdo = db();
    try {
        $st = $pdo->query(
            'SELECT c.item_id, c.is1_id, n.name AS cat_name
             FROM hq_item_cats c
             JOIN hq_cat_names n ON n.is1_id = c.is1_id'
        );
        foreach ($st as $r) {
            $iid = (int) $r['item_id'];
            $cid = (int) $r['is1_id'];
            $cname = (string) ($r['cat_name'] ?? '');
            $byItem[$iid] = ['is1_id' => $cid, 'cat_name' => $cname];
            $allCats[$cid] = $cname;
        }
    } catch (Throwable $e) {
        $byItem = [];
        $allCats = [];
    }

    $cats = ['by_item' => $byItem, 'all_cats' => $allCats];
    return $cats;
}

/**
 * استخراج الأرصدة الحالية للأصناف في جميع المخازن.
 *
 * @return array<int,array<int,float>> [sto_id => [item_id => balance]]
 */
function isl_balances_map(): array
{
    static $balances = null;
    if ($balances !== null) {
        return $balances;
    }

    $balances = [];
    $pdo = db();
    try {
        $st = $pdo->query('SELECT sto_id, item_id, balance FROM hq_branch_balances');
        foreach ($st as $r) {
            $sid = (int) $r['sto_id'];
            $iid = (int) $r['item_id'];
            $bal = (float) $r['balance'];
            $balances[$sid][$iid] = $bal;
        }
    } catch (Throwable $e) {
        $balances = [];
    }
    return $balances;
}

/**
 * استعلام المبيعات الصافية بعد خصم الفاتورة وطرح المرتجعات.
 * استعلام واحد مجمّع لكل (مخزن، صنف).
 *
 * @param string $from تاريخ البداية (YYYY-MM-DD)
 * @param string $to تاريخ النهاية (YYYY-MM-DD)
 * @return array{by_store:array<int,array<int,array{qty:float,revenue:float,cost:float,zero_cost_lines:int,sold_qty:float,ret_qty:float,ret_revenue:float,fallback_name:string,fallback_code:string}>>,by_item:array<int,array{qty:float,revenue:float,cost:float,zero_cost_lines:int,sold_qty:float,ret_qty:float,ret_revenue:float,fallback_name:string,fallback_code:string}>}
 */
function isl_sales(string $from, string $to): array
{
    static $cache = [];
    $cacheKey = "$from|$to";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $period = isl_period($from, $to);
    $agentMap = isl_agent_store_map();

    // أنواع المرتجع من الإعدادات أو 7,17
    $returnTypes = array_values(array_filter(array_map(
        'intval',
        explode(',', (string) setting('analysis_sales_return_types', '7,17'))
    ), static fn($x) => $x > 0));
    if (empty($returnTypes)) {
        $returnTypes = [7, 17];
    }
    $inReturns = implode(',', $returnTypes);

    $pdo = db();
    // المفتاح مركب من ٣ أعمدة (agent_key, sth_id, suffix)
    // الإيراد الصافي للسطر = quantity * unit_price * (net_amount / total_amount)
    $sql = "SELECT
                h.agent_key,
                l.item_id,
                SUM(CASE WHEN h.doc_type IN ($inReturns) THEN -l.quantity ELSE l.quantity END) AS qty,
                SUM(CASE
                    WHEN h.doc_type IN ($inReturns) THEN
                        - (l.quantity * l.unit_price * (CASE WHEN h.total_amount > 0 THEN (h.net_amount / h.total_amount) ELSE 1.0 END))
                    ELSE
                        (l.quantity * l.unit_price * (CASE WHEN h.total_amount > 0 THEN (h.net_amount / h.total_amount) ELSE 1.0 END))
                END) AS revenue,
                SUM(CASE WHEN h.doc_type IN ($inReturns) THEN -(l.quantity * l.cost_price) ELSE (l.quantity * l.cost_price) END) AS cost,
                SUM(CASE WHEN l.cost_price = 0 THEN 1 ELSE 0 END) AS zero_cost_lines,
                SUM(CASE WHEN h.doc_type NOT IN ($inReturns) THEN l.quantity ELSE 0 END) AS sold_qty,
                SUM(CASE WHEN h.doc_type IN ($inReturns) THEN l.quantity ELSE 0 END) AS ret_qty,
                SUM(CASE
                    WHEN h.doc_type IN ($inReturns) THEN
                        (l.quantity * l.unit_price * (CASE WHEN h.total_amount > 0 THEN (h.net_amount / h.total_amount) ELSE 1.0 END))
                    ELSE 0
                END) AS ret_revenue,
                MAX(l.item_name) AS fallback_name,
                MAX(l.item_code) AS fallback_code
            FROM br_invoice_lines l
            JOIN br_invoices h
              ON  h.agent_key = l.agent_key
              AND h.sth_id    = l.sth_id
              AND h.suffix    = l.suffix
            WHERE h.invoice_date >= ? AND h.invoice_date < ?
            GROUP BY h.agent_key, l.item_id";

    $st = $pdo->prepare($sql);
    $st->execute([$period['from'], $period['to_param']]);

    $byStore = [];
    $byItem = [];

    foreach ($st as $r) {
        $ak = (string) ($r['agent_key'] ?? '');
        $stoId = $agentMap[$ak] ?? null;
        if ($stoId === null) {
            continue;
        }

        $iid = (int) $r['item_id'];
        $qty = (float) $r['qty'];
        $rev = (float) $r['revenue'];
        $cst = (float) $r['cost'];
        $zcl = (int) $r['zero_cost_lines'];
        $soldQty = (float) $r['sold_qty'];
        $retQty = (float) $r['ret_qty'];
        $retRev = (float) $r['ret_revenue'];
        $fbName = (string) ($r['fallback_name'] ?? '');
        $fbCode = (string) ($r['fallback_code'] ?? '');

        if (!isset($byStore[$stoId][$iid])) {
            $byStore[$stoId][$iid] = [
                'qty'             => 0.0,
                'revenue'         => 0.0,
                'cost'            => 0.0,
                'zero_cost_lines' => 0,
                'sold_qty'        => 0.0,
                'ret_qty'         => 0.0,
                'ret_revenue'     => 0.0,
                'fallback_name'   => $fbName,
                'fallback_code'   => $fbCode,
            ];
        }
        $byStore[$stoId][$iid]['qty'] += $qty;
        $byStore[$stoId][$iid]['revenue'] += $rev;
        $byStore[$stoId][$iid]['cost'] += $cst;
        $byStore[$stoId][$iid]['zero_cost_lines'] += $zcl;
        $byStore[$stoId][$iid]['sold_qty'] += $soldQty;
        $byStore[$stoId][$iid]['ret_qty'] += $retQty;
        $byStore[$stoId][$iid]['ret_revenue'] += $retRev;
        if ($fbName !== '') $byStore[$stoId][$iid]['fallback_name'] = $fbName;
        if ($fbCode !== '') $byStore[$stoId][$iid]['fallback_code'] = $fbCode;

        if (!isset($byItem[$iid])) {
            $byItem[$iid] = [
                'qty'             => 0.0,
                'revenue'         => 0.0,
                'cost'            => 0.0,
                'zero_cost_lines' => 0,
                'sold_qty'        => 0.0,
                'ret_qty'         => 0.0,
                'ret_revenue'     => 0.0,
                'fallback_name'   => $fbName,
                'fallback_code'   => $fbCode,
            ];
        }
        $byItem[$iid]['qty'] += $qty;
        $byItem[$iid]['revenue'] += $rev;
        $byItem[$iid]['cost'] += $cst;
        $byItem[$iid]['zero_cost_lines'] += $zcl;
        $byItem[$iid]['sold_qty'] += $soldQty;
        $byItem[$iid]['ret_qty'] += $retQty;
        $byItem[$iid]['ret_revenue'] += $retRev;
        if ($fbName !== '') $byItem[$iid]['fallback_name'] = $fbName;
        if ($fbCode !== '') $byItem[$iid]['fallback_code'] = $fbCode;
    }

    $result = ['by_store' => $byStore, 'by_item' => $byItem];
    $cache[$cacheKey] = $result;
    return $result;
}

/**
 * حالة الرصيد: سالب · صفر · قرب يخلص · ماتباعش.
 * لكل مخزن ٤ قوائم.
 *
 * @param string $from
 * @param string $to
 * @param int $coverDays عدد أيام التغطية (افتراضي 7 من الإعدادات)
 * @return array<int,array{negative:array,zero:array,near:array,idle:array,counts:array<string,int>}>
 */
function isl_stock_status(string $from, string $to, int $coverDays = 7): array
{
    $period   = isl_period($from, $to);
    $days     = $period['days'];
    $sales    = isl_sales($from, $to);
    $balances = isl_balances_map();
    $itemsMap = isl_items_map();
    $catsData = isl_categories_map();
    $catsMap  = $catsData['by_item'];
    $stores   = an_stores();
    $hqId     = an_hq_store();

    $out = [];

    foreach ($stores as $stoId => $stoInfo) {
        $isHq = ($stoId === $hqId);
        $stoName = (string) $stoInfo['name'];
        $stoBalances = $balances[$stoId] ?? [];
        $stoSales    = $sales['by_store'][$stoId] ?? [];

        $negative = [];
        $zero     = [];
        $near     = [];
        $idle     = [];

        // جمع كل الأصناف في هذا المخزن (من الأرصدة أو المبيعات)
        $allItemIds = array_unique(array_merge(array_keys($stoBalances), array_keys($stoSales)));

        foreach ($allItemIds as $iid) {
            $bal = (float) ($stoBalances[$iid] ?? 0.0);
            $saleData = $stoSales[$iid] ?? null;
            $qty = $saleData ? (float) $saleData['qty'] : 0.0;

            // اسم وكود وقسم الصنف
            $master = $itemsMap[$iid] ?? null;
            $name = $master && $master['name'] !== '' ? $master['name']
                    : ($saleData['fallback_name'] ?? "صنف #$iid");
            $barcode = $master && $master['barcode'] !== '' ? $master['barcode']
                    : ($saleData['fallback_code'] ?? "—");

            $catInfo = $catsMap[$iid] ?? null;
            $catId = $catInfo ? (int) $catInfo['is1_id'] : 0;
            $catName = $catInfo ? $catInfo['cat_name'] : 'بدون قسم';

            $dailyAvg = ($qty > 0) ? ($qty / $days) : 0.0;
            $daysLeft = ($bal > 0 && $dailyAvg > 0) ? round($bal / $dailyAvg, 1) : null;

            $row = [
                'sto_id'     => $stoId,
                'store_name' => $stoName,
                'item_id'    => $iid,
                'barcode'    => $barcode,
                'name'       => $name,
                'cat_id'     => $catId,
                'cat_name'   => $catName,
                'balance'    => $bal,
                'qty'        => $qty,
                'daily_avg'  => round($dailyAvg, 2),
                'days_left'  => $daysLeft,
            ];

            // ١) سالب: balance < 0 (كل الأصناف، والإدارة معاهم)
            if ($bal < 0) {
                $negative[] = $row;
            }

            // الإدارة مش داخلة في صفر أو قرب يخلص أو ماتباعش
            if ($isHq) {
                continue;
            }

            // ٢) صفر: balance = 0 وصافي مبيعاته > 0
            if ($bal == 0.0 && $qty > 0) {
                $zero[] = $row;
            }

            // ٣) قرب يخلص: balance > 0 و avg > 0 و balance / avg <= coverDays
            if ($bal > 0 && $dailyAvg > 0 && ($bal / $dailyAvg) <= $coverDays) {
                $near[] = $row;
            }

            // ٤) ماتباعش (راكد): balance > 0 وصافي مبيعاته = 0
            if ($bal > 0 && $qty <= 0) {
                $idle[] = $row;
            }
        }

        // الترتيب:
        // سالب: تنازلي في السلبية (الأكثر سالبية أولاً)
        usort($negative, static fn($a, $b) => $a['balance'] <=> $b['balance']);
        // صفر: الأكثر مبيعاً أولاً
        usort($zero, static fn($a, $b) => $b['qty'] <=> $a['qty']);
        // قرب يخلص: الأقرب للنفاد أولاً
        usort($near, static fn($a, $b) => ($a['days_left'] ?? 999999) <=> ($b['days_left'] ?? 999999));
        // ماتباعش: الرصيد تنازلي
        usort($idle, static fn($a, $b) => $b['balance'] <=> $a['balance']);

        $out[$stoId] = [
            'sto_id'   => $stoId,
            'name'     => $stoName,
            'is_hq'    => $isHq,
            'negative' => $negative,
            'zero'     => $zero,
            'near'     => $near,
            'idle'     => $idle,
            'counts'   => [
                'negative' => count($negative),
                'zero'     => count($zero),
                'near'     => count($near),
                'idle'     => count($idle),
            ],
        ];
    }

    return $out;
}

/**
 * حركة البيع: الأكثر والأقل مبيعاً.
 *
 * @param string $from
 * @param string $to
 * @param int|null $stoId null = كل الفروع مجموعين
 * @param int|null $catId null = كل الأقسام
 * @param string $by 'qty' أو 'revenue'
 * @param int $limit عدد الصفوف (افتراضي 50)
 * @return array{top:array,bottom:array,by:string,limit:int}
 */
function isl_movement(
    string $from,
    string $to,
    ?int $stoId = null,
    ?int $catId = null,
    string $by = 'qty',
    int $limit = 50
): array {
    $sales    = isl_sales($from, $to);
    $itemsMap = isl_items_map();
    $catsData = isl_categories_map();
    $catsMap  = $catsData['by_item'];
    $balances = isl_balances_map();
    $stores   = an_stores();

    $by = ($by === 'revenue') ? 'revenue' : 'qty';
    $limit = max(1, $limit);

    // تجميع المبيعات حسب شرط المخزن
    $pool = [];
    if ($stoId !== null && $stoId > 0) {
        $pool = $sales['by_store'][$stoId] ?? [];
    } else {
        $pool = $sales['by_item'];
    }

    $candidates = [];
    $returnCandidates = [];

    foreach ($pool as $iid => $s) {
        $qty = (float) $s['qty'];
        $rev = (float) $s['revenue'];
        $soldQty = (float) ($s['sold_qty'] ?? 0.0);
        $retQty = (float) ($s['ret_qty'] ?? 0.0);
        $retRev = (float) ($s['ret_revenue'] ?? 0.0);

        $catInfo = $catsMap[$iid] ?? null;
        $itemCatId = $catInfo ? (int) $catInfo['is1_id'] : 0;
        $catName = $catInfo ? $catInfo['cat_name'] : 'بدون قسم';

        if ($catId !== null && $catId > 0 && $itemCatId !== $catId) {
            continue;
        }

        $master = $itemsMap[$iid] ?? null;
        $name = $master && $master['name'] !== '' ? $master['name'] : ($s['fallback_name'] ?? "صنف #$iid");
        $barcode = $master && $master['barcode'] !== '' ? $master['barcode'] : ($s['fallback_code'] ?? "—");

        // الرصيد
        $bal = 0.0;
        if ($stoId !== null && $stoId > 0) {
            $bal = (float) ($balances[$stoId][$iid] ?? 0.0);
        } else {
            foreach ($balances as $stBal) {
                $bal += (float) ($stBal[$iid] ?? 0.0);
            }
        }

        $cst = (float) $s['cost'];
        $profit = $rev - $cst;
        $margin = ($rev > 0) ? round(($profit / $rev) * 100, 1) : 0.0;

        $retPct = ($soldQty > 0) ? round(($retQty / $soldQty) * 100, 1) : 0.0;
        $retHigh = ($retPct >= ISL_RET_WARN_PCT && $retQty >= ISL_RET_WARN_MIN);

        $rowBase = [
            'item_id'     => $iid,
            'barcode'     => $barcode,
            'name'        => $name,
            'cat_id'      => $itemCatId,
            'cat_name'    => $catName,
            'qty'         => $qty,
            'sold_qty'    => $soldQty,
            'ret_qty'     => $retQty,
            'ret_revenue' => round($retRev, 2),
            'ret_pct'     => $retPct,
            'ret_high'    => $retHigh,
            'revenue'     => round($rev, 2),
            'cost'        => round($cst, 2),
            'profit'      => round($profit, 2),
            'margin'      => $margin,
            'balance'     => $bal,
        ];

        // شرط الأكثر والأقل: اللي اتباعت كميته الصافية فعلاً (> 0)
        if ($qty > 0) {
            $candidates[] = $rowBase;
        }

        // شرط الأكثر مرتجعاً: أي صنف ret_qty > 0 حتى لو صافيه صفر أو سالب
        if ($retQty > 0) {
            $returnCandidates[] = $rowBase;
        }
    }

    // فرز الأكثر (تنازلي)
    $top = $candidates;
    usort($top, static function ($a, $b) use ($by) {
        if ($b[$by] == $a[$by]) {
            return $a['item_id'] <=> $b['item_id'];
        }
        return ($b[$by] > $a[$by]) ? 1 : -1;
    });
    $top = array_slice($top, 0, $limit);

    // فرز الأقل (تصاعدي)
    $bottom = $candidates;
    usort($bottom, static function ($a, $b) use ($by) {
        if ($a[$by] == $b[$by]) {
            return $a['item_id'] <=> $b['item_id'];
        }
        return ($a[$by] > $b[$by]) ? 1 : -1;
    });
    $bottom = array_slice($bottom, 0, $limit);

    // فرز الأكثر مرتجعاً (تنازلي بـ ret_qty لو qty وبـ ret_revenue لو revenue)
    $retKey = ($by === 'revenue') ? 'ret_revenue' : 'ret_qty';
    $returns = $returnCandidates;
    usort($returns, static function ($a, $b) use ($retKey) {
        if ($b[$retKey] == $a[$retKey]) {
            return $a['item_id'] <=> $b['item_id'];
        }
        return ($b[$retKey] > $a[$retKey]) ? 1 : -1;
    });
    $returns = array_slice($returns, 0, $limit);

    return [
        'top'     => $top,
        'bottom'  => $bottom,
        'returns' => $returns,
        'by'      => $by,
        'limit'   => $limit,
    ];
}

/**
 * شيل التكلفة والربح والهامش من ناتج isl_movement().
 * 🔴 «حركة البيع» جزء للموظفين — الأرقام دي من حق اللي معاه item_sales:profit
 *    والأرقام مفتوحة بس. من غيرها كانت بتطلع في الـapi والتصدير لأي حد.
 */
function isl_movement_public(array $data): array
{
    foreach (['top', 'bottom', 'returns'] as $k) {
        foreach ($data[$k] ?? [] as $i => $r) {
            unset($data[$k][$i]['cost'], $data[$k][$i]['profit'], $data[$k][$i]['margin']);
        }
    }
    return $data;
}

/**
 * تقرير الأرباح والهوامش.
 *
 * @param string $from
 * @param string $to
 * @param int|null $stoId
 * @param int|null $catId
 * @return array{items:array,by_store:array,by_cat:array,totals:array,zero_cost_lines:int}
 */
function isl_profit(
    string $from,
    string $to,
    ?int $stoId = null,
    ?int $catId = null
): array {
    $sales    = isl_sales($from, $to);
    $itemsMap = isl_items_map();
    $catsData = isl_categories_map();
    $catsMap  = $catsData['by_item'];
    $stores   = an_stores();

    $itemsAgg = []; // [item_id => [...]]
    $storeAgg = []; // [sto_id => [...]]
    $catAgg   = []; // [cat_id => [...]]

    $totQty = 0.0;
    $totRev = 0.0;
    $totCost = 0.0;
    $totalZeroCost = 0;

    foreach ($sales['by_store'] as $sid => $storeSales) {
        if ($stoId !== null && $stoId > 0 && $sid !== $stoId) {
            continue;
        }

        $stoName = $stores[$sid]['name'] ?? ("مخزن " . $sid);

        foreach ($storeSales as $iid => $s) {
            $catInfo = $catsMap[$iid] ?? null;
            $itemCatId = $catInfo ? (int) $catInfo['is1_id'] : 0;
            $catName = $catInfo ? $catInfo['cat_name'] : 'بدون قسم';

            if ($catId !== null && $catId > 0 && $itemCatId !== $catId) {
                continue;
            }

            $qty = (float) $s['qty'];
            $rev = (float) $s['revenue'];
            $cst = (float) $s['cost'];
            $zcl = (int) $s['zero_cost_lines'];

            $totQty += $qty;
            $totRev += $rev;
            $totCost += $cst;
            $totalZeroCost += $zcl;

            // تجميع الصنف
            if (!isset($itemsAgg[$iid])) {
                $master = $itemsMap[$iid] ?? null;
                $name = $master && $master['name'] !== '' ? $master['name'] : ($s['fallback_name'] ?? "صنف #$iid");
                $barcode = $master && $master['barcode'] !== '' ? $master['barcode'] : ($s['fallback_code'] ?? "—");

                $itemsAgg[$iid] = [
                    'item_id'         => $iid,
                    'barcode'         => $barcode,
                    'name'            => $name,
                    'cat_id'          => $itemCatId,
                    'cat_name'        => $catName,
                    'qty'             => 0.0,
                    'revenue'         => 0.0,
                    'cost'            => 0.0,
                    'zero_cost_lines' => 0,
                ];
            }
            $itemsAgg[$iid]['qty'] += $qty;
            $itemsAgg[$iid]['revenue'] += $rev;
            $itemsAgg[$iid]['cost'] += $cst;
            $itemsAgg[$iid]['zero_cost_lines'] += $zcl;

            // تجميع المخزن
            if (!isset($storeAgg[$sid])) {
                $storeAgg[$sid] = [
                    'sto_id'  => $sid,
                    'name'    => $stoName,
                    'qty'     => 0.0,
                    'revenue' => 0.0,
                    'cost'    => 0.0,
                ];
            }
            $storeAgg[$sid]['qty'] += $qty;
            $storeAgg[$sid]['revenue'] += $rev;
            $storeAgg[$sid]['cost'] += $cst;

            // تجميع القسم
            if (!isset($catAgg[$itemCatId])) {
                $catAgg[$itemCatId] = [
                    'cat_id'  => $itemCatId,
                    'name'    => $catName,
                    'qty'     => 0.0,
                    'revenue' => 0.0,
                    'cost'    => 0.0,
                ];
            }
            $catAgg[$itemCatId]['qty'] += $qty;
            $catAgg[$itemCatId]['revenue'] += $rev;
            $catAgg[$itemCatId]['cost'] += $cst;
        }
    }

    // حساب الأرباح والهوامش للأصناف
    $itemsList = [];
    foreach ($itemsAgg as $row) {
        $p = $row['revenue'] - $row['cost'];
        $m = ($row['revenue'] > 0) ? round(($p / $row['revenue']) * 100, 1) : 0.0;
        $row['profit']  = round($p, 2);
        $row['margin']  = $m;
        $row['revenue'] = round($row['revenue'], 2);
        $row['cost']    = round($row['cost'], 2);
        $itemsList[] = $row;
    }
    // الترتيب: الأكثر ربحاً أولاً
    usort($itemsList, static fn($a, $b) => $b['profit'] <=> $a['profit']);

    // المخازن
    $storeList = [];
    foreach ($storeAgg as $row) {
        $p = $row['revenue'] - $row['cost'];
        $m = ($row['revenue'] > 0) ? round(($p / $row['revenue']) * 100, 1) : 0.0;
        $row['profit']  = round($p, 2);
        $row['margin']  = $m;
        $row['revenue'] = round($row['revenue'], 2);
        $row['cost']    = round($row['cost'], 2);
        $storeList[] = $row;
    }
    usort($storeList, static fn($a, $b) => $b['profit'] <=> $a['profit']);

    // الأقسام
    $catList = [];
    foreach ($catAgg as $row) {
        $p = $row['revenue'] - $row['cost'];
        $m = ($row['revenue'] > 0) ? round(($p / $row['revenue']) * 100, 1) : 0.0;
        $row['profit']  = round($p, 2);
        $row['margin']  = $m;
        $row['revenue'] = round($row['revenue'], 2);
        $row['cost']    = round($row['cost'], 2);
        $catList[] = $row;
    }
    usort($catList, static fn($a, $b) => $b['profit'] <=> $a['profit']);

    // الإجماليات
    $totProfit = $totRev - $totCost;
    $totMargin = ($totRev > 0) ? round(($totProfit / $totRev) * 100, 1) : 0.0;
    $totals = [
        'qty'     => $totQty,
        'revenue' => round($totRev, 2),
        'cost'    => round($totCost, 2),
        'profit'  => round($totProfit, 2),
        'margin'  => $totMargin,
    ];

    return [
        'items'           => $itemsList,
        'by_store'        => $storeList,
        'by_cat'          => $catList,
        'totals'          => $totals,
        'zero_cost_lines' => $totalZeroCost,
    ];
}

/**
 * تحليل مبيعات الأصناف شهرياً (بريف ١٠٦).
 * استعلام واحد فقط على br_invoice_lines + br_invoices.
 *
 * @param string $to تاريخ النهاية (YYYY-MM-DD)
 * @param int $months عدد الشهور (3, 6, 12 - غير ذلك 6)
 * @param int|null $stoId رقم المخزن أو null لكل الفروع
 * @param int|null $catId رقم القسم أو null لكل الأقسام
 * @param string $by 'qty' أو 'revenue'
 * @param int $limit أقصى عدد للأصناف
 * @return array
 */
function isl_monthly(
    string $to,
    int $months = 6,
    ?int $stoId = null,
    ?int $catId = null,
    string $by = 'qty',
    int $limit = 100
): array {
    $toClean = trim($to);
    if ($toClean === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toClean)) {
        $toClean = date('Y-m-d');
    }

    if (!in_array($months, [3, 6, 12], true)) {
        $months = 6;
    }

    $by = ($by === 'revenue') ? 'revenue' : 'qty';
    $limit = max(1, $limit);

    // حساب الشهور المطلوبة بالتاريخ
    $toTimestamp = strtotime($toClean);
    $toY = (int) date('Y', $toTimestamp);
    $toM = (int) date('m', $toTimestamp);
    $toD = (int) date('d', $toTimestamp);
    $daysInToMonth = (int) date('t', $toTimestamp);

    $isPartial = ($toD < $daysInToMonth);

    // تحديد الشهور المطلوبة من الأقدم للأحدث
    $arMonths = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];

    $monthSlots = []; // ['2026-07' => ['ym'=>'2026-07', 'label'=>'...', 'partial'=>bool, 'until'=>int|null]]
    for ($i = $months - 1; $i >= 0; $i--) {
        // حساب السنة والشهر بإرجاع $i شهراً من ($toY, $toM)
        $mTotal = ($toY * 12 + $toM) - $i;
        $y = intdiv($mTotal - 1, 12);
        $m = (($mTotal - 1) % 12) + 1;
        $ym = sprintf('%04d-%02d', $y, $m);

        $partial = false;
        $until = null;
        if ($i === 0 && $isPartial) {
            $partial = true;
            $until = $toD;
        }

        $mLabel = $arMonths[$m] . ' ' . $y;

        $monthSlots[$ym] = [
            'ym'            => $ym,
            'key'           => $ym,
            'label'         => $mLabel,
            'partial'       => $partial,
            'is_partial'    => $partial,
            'until'         => $until,
            'days_in_range' => $until,
        ];
    }

    $firstYm = array_key_first($monthSlots);
    $startDate = $firstYm . '-01';
    $endDateExclusive = date('Y-m-d', strtotime($toClean . ' +1 day'));

    $agentMap = isl_agent_store_map();

    // أنواع المرتجع من الإعدادات
    $returnTypes = array_values(array_filter(array_map(
        'intval',
        explode(',', (string) setting('analysis_sales_return_types', '7,17'))
    ), static fn($x) => $x > 0));
    if (empty($returnTypes)) {
        $returnTypes = [7, 17];
    }
    $inReturns = implode(',', $returnTypes);

    $pdo = db();

    // استعلام واحد مجمّع لكل (agent_key, item_id, ym)
    $sql = "SELECT
                h.agent_key,
                l.item_id,
                DATE_FORMAT(h.invoice_date, '%Y-%m') AS ym,
                SUM(CASE WHEN h.doc_type IN ($inReturns) THEN -l.quantity ELSE l.quantity END) AS net_qty,
                SUM(CASE
                    WHEN h.doc_type IN ($inReturns) THEN
                        - (l.quantity * l.unit_price * (CASE WHEN h.total_amount > 0 THEN (h.net_amount / h.total_amount) ELSE 1.0 END))
                    ELSE
                        (l.quantity * l.unit_price * (CASE WHEN h.total_amount > 0 THEN (h.net_amount / h.total_amount) ELSE 1.0 END))
                END) AS net_revenue
            FROM br_invoice_lines l
            JOIN br_invoices h
              ON  h.agent_key = l.agent_key
              AND h.sth_id    = l.sth_id
              AND h.suffix    = l.suffix
            WHERE h.invoice_date >= ? AND h.invoice_date < ?
            GROUP BY h.agent_key, l.item_id, DATE_FORMAT(h.invoice_date, '%Y-%m')";

    $st = $pdo->prepare($sql);
    $st->execute([$startDate, $endDateExclusive]);

    $activeMonthsSet = [];
    $itemMonthlyData = []; // [item_id => ['ym' => val, ...]]

    foreach ($st as $r) {
        $ak = (string) ($r['agent_key'] ?? '');
        $sid = $agentMap[$ak] ?? null;
        if ($sid === null) {
            continue;
        }

        // تطبيق فلتر الفرع
        if ($stoId !== null && $stoId > 0 && $sid !== $stoId) {
            continue;
        }

        $ym = (string) $r['ym'];
        if (!isset($monthSlots[$ym])) {
            continue;
        }

        $activeMonthsSet[$ym] = true;

        $iid = (int) $r['item_id'];
        $val = ($by === 'revenue') ? (float) $r['net_revenue'] : (float) $r['net_qty'];

        if (!isset($itemMonthlyData[$iid][$ym])) {
            $itemMonthlyData[$iid][$ym] = 0.0;
        }
        $itemMonthlyData[$iid][$ym] += $val;
    }

    // تصفية الشهور المعروضة: الشهر الذي ليس فيه أي فاتورة في النطاق بعد فلتر الفرع لا يظهر
    $displayMonths = [];
    foreach ($monthSlots as $ym => $info) {
        if (isset($activeMonthsSet[$ym])) {
            $displayMonths[] = $info;
        }
    }

    // تحديد آخر شهرين كاملين لحساب الاتجاه
    $fullMonths = [];
    foreach ($displayMonths as $mInfo) {
        if (empty($mInfo['partial'])) {
            $fullMonths[] = $mInfo['ym'];
        }
    }
    $hasTwoFull = count($fullMonths) >= 2;
    $prevFullYm = $hasTwoFull ? $fullMonths[count($fullMonths) - 2] : null;
    $lastFullYm = $hasTwoFull ? $fullMonths[count($fullMonths) - 1] : null;

    // جلب الخرائط القائمة
    $itemsMap = isl_items_map();
    $catsData = isl_categories_map();
    $catsMap  = $catsData['by_item'];
    $balances = isl_balances_map();

    $candidateRows = [];

    foreach ($itemMonthlyData as $iid => $byM) {
        // فلتر القسم
        $catInfo = $catsMap[$iid] ?? null;
        $itemCatId = $catInfo ? (int) $catInfo['is1_id'] : 0;
        $catName = $catInfo ? $catInfo['cat_name'] : 'بدون قسم';

        if ($catId !== null && $catId > 0 && $itemCatId !== $catId) {
            continue;
        }

        // حساب إجمالي الشهور المعروضة
        $total = 0.0;
        $byMonthClean = [];
        foreach ($displayMonths as $mInfo) {
            $ym = $mInfo['ym'];
            $v = (float) ($byM[$ym] ?? 0.0);
            $total += $v;
            $byMonthClean[$ym] = ($by === 'revenue') ? round($v, 2) : (float)$v;
        }

        // الصنف الذي إجماليه <= 0 مايطلعش
        if ($total <= 0) {
            continue;
        }

        // حساب الاتجاه
        $trend = null;
        if ($hasTwoFull && $prevFullYm !== null && $lastFullYm !== null) {
            $prevVal = (float) ($byM[$prevFullYm] ?? 0.0);
            $lastVal = (float) ($byM[$lastFullYm] ?? 0.0);

            if ($prevVal <= 0 && $lastVal > 0) {
                $trend = 'new';
            } elseif ($lastVal >= ($prevVal * 1.25)) {
                $trend = 'up';
            } elseif ($lastVal <= ($prevVal * 0.75)) {
                $trend = 'down';
            } else {
                $trend = 'flat';
            }
        }

        $master = $itemsMap[$iid] ?? null;
        $name = $master && $master['name'] !== '' ? $master['name'] : "صنف #$iid";
        $barcode = $master && $master['barcode'] !== '' ? $master['barcode'] : "—";

        // الرصيد
        $bal = 0.0;
        if ($stoId !== null && $stoId > 0) {
            $bal = (float) ($balances[$stoId][$iid] ?? 0.0);
        } else {
            foreach ($balances as $stBal) {
                $bal += (float) ($stBal[$iid] ?? 0.0);
            }
        }

        $candidateRows[] = [
            'item_id'  => $iid,
            'barcode'  => $barcode,
            'name'     => $name,
            'cat_id'   => $itemCatId,
            'cat_name' => $catName,
            'by_month' => $byMonthClean,
            'months'   => $byMonthClean,
            'total'    => ($by === 'revenue') ? round($total, 2) : (float)$total,
            'trend'    => $trend,
            'balance'  => $bal,
        ];
    }

    $totalItems = count($candidateRows);

    // ترتيب الصفوف بالإجمالي تنازلي
    usort($candidateRows, static function ($a, $b) {
        if ($b['total'] == $a['total']) {
            return $a['item_id'] <=> $b['item_id'];
        }
        return ($b['total'] > $a['total']) ? 1 : -1;
    });

    $pagedRows = array_slice($candidateRows, 0, $limit);

    return [
        'months'      => $displayMonths,
        'rows'        => $pagedRows,
        'items'       => $pagedRows,
        'by'          => $by,
        'limit'       => $limit,
        'total_items' => $totalItems,
    ];
}
