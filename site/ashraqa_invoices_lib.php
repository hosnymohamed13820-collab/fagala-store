<?php
/**
 * ashraqa_invoices_lib.php — مكتبة استعلامات وحسابات فواتير فرع إشراقة (branch_id = 5)
 * نظام الفجالة ستور المالي
 *
 * مخصصة لمراجعة وتسعير فواتير فرع إشراقة (عاشور).
 * يعتمد على branch_invoices_lib.php للحسابات العامة و ashraqa_prices_lib.php للتسعير والرصيد.
 */

declare(strict_types=1);

require_once __DIR__ . '/branch_invoices_lib.php';
require_once __DIR__ . '/ashraqa_prices_lib.php';
require_once __DIR__ . '/receiving_lib.php';

// فرع إشراقة الثابت
const ASHRAQA_BRANCH_ID = 5;

// نوع المستند في نظام الشركة: 2 = فاتورة شراء · 6 = مردود شراء.
// عاشور بيراجع المشتريات بس — المردودات مابتظهرش ليه خالص.
const ASH_DOC_RETURN = 6;

// دقة تقريب نسبة التسعير لأقرب نصف جنيه
const ASH_MRK_ROUND_STEP = 0.5;

function ash_mrk_round(float $v): float
{
    return round($v / ASH_MRK_ROUND_STEP) * ASH_MRK_ROUND_STEP;
}

function ash_mrk_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

function ash_mrk_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

function ash_mrk_fmt(?float $p): string
{
    if ($p === null) return '';
    $s = number_format($p, 1, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

function ash_mrk_cell(string $code, float $cost, ?float $sale): string
{
    $has = $cost > 0;
    $pct = ($has && $sale !== null && $sale > 0) ? ash_mrk_pct($cost, $sale) : null;
    $ph  = $pct === null ? '—' : ash_mrk_fmt($pct) . '%';
    $cs  = $has ? rtrim(rtrim(number_format($cost, 5, '.', ''), '0'), '.') : '0';

    return '<td class="c-mrk">'
        . '<input type="number" step="0.5" class="mrk-input' . ($has ? '' : ' no-cost') . '"'
        . ' aria-label="نسبة التسعير" data-code="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" data-cost="' . htmlspecialchars($cs, ENT_QUOTES, 'UTF-8') . '"'
        . ' placeholder="' . htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') . '"' . ($has ? '' : ' disabled')
        . ' title="' . ($has ? 'اكتب نسبة واضغط Enter — سعر البيع هيتحسب لوحده' : 'مفيش تكلفة للصنف ده') . '"'
        . ' onblur="applyAshMrk(this)" onkeydown="ashMrkKey(event,this)">'
        . '</td>';
}

/**
 * دالة استخراج معرف سجل التوزيع لفاتورة معينة
 */
function ash_dist_for(array $inv, array $ashraqaDocNos): ?int
{
    return br_inv_dist_for($inv, $ashraqaDocNos);
}

/**
 * دالة استخراج أكواد التوزيع لفاتورة معينة
 */
function ash_codes_for(array $inv, array $ashraqaCodesByDoc): array
{
    return br_inv_codes_for($inv, $ashraqaCodesByDoc);
}

/**
 * استخراج بيانات توزيع فرع إشراقة من جداول invoice_distribution و invoice_distribution_lines
 *
 * @return array{doc_nos:array<string,int>, codes_by_doc:array<string,array<string,true>>, qtys_by_dist:array<int,array<string,float>>, codes_by_dist:array<int,array<string,true>>}
 */
function ash_inv_distribution_data(): array
{
    return br_inv_distribution_data(ASHRAQA_BRANCH_ID);
}

/**
 * خريطة حالة مراجعة إشراقة من ashraqa_invoice_status
 * 🔴 غياب الصف = 'new'
 *
 * @return array<int,string> [dist_id => 'new'|'entered']
 */
function ash_inv_status_map(): array
{
    $pdo = db();
    $out = [];
    try {
        foreach ($pdo->query('SELECT distribution_id, status FROM ashraqa_invoice_status') as $r) {
            $out[(int) $r['distribution_id']] = (string) $r['status'];
        }
    } catch (Throwable $ex) {
        error_log('ashraqa_invoice_status: ' . $ex->getMessage());
    }
    return $out;
}

/**
 * تحديث حالة مراجعة الفاتورة لإشراقة
 */
function ash_inv_set_status(int $distId, string $status, ?int $userId = null): void
{
    if ($distId <= 0) {
        throw new InvalidArgumentException('معرف التوزيع غير صالح.');
    }
    if (!in_array($status, ['new', 'entered'], true)) {
        throw new InvalidArgumentException('حالة غير معروفة.');
    }
    $up = db()->prepare(
        'INSERT INTO ashraqa_invoice_status (distribution_id, status, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)'
    );
    $up->execute([$distId, $status, $userId]);
}

/**
 * حفظ أسعار أصناف بالجملة لفرع إشراقة داخل transaction.
 * 🔴 تستخدم ashraqa_save_price() في ashraqa_prices_lib.php
 */
function ash_inv_save_prices_bulk(int $distId, array $map, ?int $userId = null): int
{
    if ($distId <= 0 || empty($map)) {
        throw new InvalidArgumentException('بيانات ناقصة.');
    }
    if (count($map) > 5000) {
        throw new InvalidArgumentException('عدد الأصناف كبير جداً.');
    }

    $n = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($map as $code => $price) {
            $code = trim((string) $code);
            $price = (float) $price;
            if ($code === '' || $price < 0) continue;
            ashraqa_save_price($distId, $code, ash_mrk_round($price), $userId);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    return $n;
}

/**
 * فلترة ناتج ilk_item_history على مستوى السيرفر لحصرها في فرع إشراقة
 * واستبعاد أسماء الموردين والفروع الأخرى.
 */
function ash_inv_filter_item_history(array $res, array $distData, string $searchCode): array
{
    return br_inv_filter_item_history($res, ASHRAQA_BRANCH_ID, $distData, $searchCode);
}

/**
 * جلب قائمة فواتير إشراقة
 */
function ash_inv_list_data(array $distData, array $statusMap = []): array
{
    return br_inv_list_data(ASHRAQA_BRANCH_ID, $distData, $statusMap);
}

/**
 * جلب تفاصيل فاتورة مفتوحة لإشراقة بعد فحص الحماية والشروط
 */
function ash_inv_invoice_detail(string $openId, ?string $openSuf, array $distData): ?array
{
    $header = sqlsrv_fetch_invoice_header($openId, $openSuf);
    if (!$header) return null;

    $isRet = (int)($header['doc_type'] ?? 0) === ASH_DOC_RETURN;
    $cb = $header['company_branch_id'] ?? ($header['company_branch'] ?? null);
    $distId = ash_dist_for($header, $distData['doc_nos'] ?? []);

    // حارس إجباري: الفاتورة فيها توزيع لإشراقة؟ ومش مردود؟ ومن الإدارة المركزية (branch 1)؟
    if ($isRet || ($cb !== null && (int)$cb !== 1) || $distId === null) {
        return null;
    }

    $pdo = db();
    $rawItems = sqlsrv_fetch_invoice_items($openId, $openSuf);
    $items = merge_invoice_items($rawItems);

    // كميات إشراقة باستعلام واحد مجمّع
    $stQty = $pdo->prepare(
        'SELECT notes AS item_code, SUM(amount) AS qty
           FROM invoice_distribution_lines
          WHERE distribution_id = ? AND branch_id = ?
          GROUP BY notes'
    );
    $stQty->execute([$distId, ASHRAQA_BRANCH_ID]);
    $ashraqaQtys = [];
    foreach ($stQty as $qr) {
        $ashraqaQtys[(string)$qr['item_code']] = (float)$qr['qty'];
    }

    // جلب item_id لكل أكواد التوزيع باستعلام واحد مجمّع (للاحتياطي)
    $distCodes = array_keys($ashraqaQtys);
    $distCodeToItemId = [];
    $distCodesByItemId = [];
    $nonHashDistCodes = [];
    foreach ($distCodes as $dc) {
        $dc = (string) $dc;
        if (str_starts_with($dc, '#')) {
            $iid = (int) substr($dc, 1);
            if ($iid > 0) {
                $distCodeToItemId[$dc] = $iid;
                $distCodesByItemId[$iid][] = $dc;
            }
        } else {
            $nonHashDistCodes[] = $dc;
        }
    }
    if ($nonHashDistCodes) {
        $phC = implode(',', array_fill(0, count($nonHashDistCodes), '?'));
        try {
            $stC = $pdo->prepare("SELECT code, item_id FROM hq_codes WHERE code IN ($phC)");
            $stC->execute($nonHashDistCodes);
            foreach ($stC as $cr) {
                $iid = (int) $cr['item_id'];
                $cVal = (string) $cr['code'];
                $distCodeToItemId[$cVal] = $iid;
                $distCodesByItemId[$iid][] = $cVal;
            }
        } catch (Throwable $ex) { /* تجاهل */ }
    }

    // فلترة الأصناف: بالكود أولاً ثم باحتياطي item_id
    $claimedDistCodes = [];
    $ashraqaItems = [];
    $ashraqaCodes = [];
    foreach ($items as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = 0.0;
        if ($c !== '' && isset($ashraqaQtys[$c]) && !isset($claimedDistCodes[$c])) {
            $q = (float) $ashraqaQtys[$c];
            $claimedDistCodes[$c] = true;
        } elseif (!empty($it['item_id'])) {
            $iid = (int) $it['item_id'];
            $candCodes = $distCodesByItemId[$iid] ?? [];
            foreach ($candCodes as $dc) {
                $dc = (string) $dc;
                if (!isset($claimedDistCodes[$dc]) && isset($ashraqaQtys[$dc]) && (str_starts_with($c, '#') || str_starts_with($dc, '#'))) {
                    $q += (float) $ashraqaQtys[$dc];
                    $claimedDistCodes[$dc] = true;
                }
            }
        }
        if ($q <= 0) continue;
        $ashraqaQtys[$c] = $q;
        $ashraqaItems[] = $it;
        $ashraqaCodes[] = $c;
    }

    // سطور التوزيع التي فضلت من غير صنف في الفاتورة (أصناف يتيمة)
    $orphanedDistItems = [];
    $unclaimedCodes = [];
    foreach ($ashraqaQtys as $dc => $dQty) {
        $dc = (string) $dc;
        if (!isset($claimedDistCodes[$dc]) && $dQty > 0) {
            $unclaimedCodes[] = $dc;
        }
    }
    if ($unclaimedCodes) {
        $orpNames = [];
        $regularUnclaimed = [];
        $hashIds = [];
        foreach ($unclaimedCodes as $uc) {
            $uc = (string) $uc;
            if (str_starts_with($uc, '#')) {
                $iid = (int) substr($uc, 1);
                if ($iid > 0) $hashIds[$iid] = $uc;
            } else {
                $regularUnclaimed[] = $uc;
            }
        }
        if ($hashIds) {
            $phH = implode(',', array_fill(0, count($hashIds), '?'));
            try {
                $stI = $pdo->prepare(
                    "SELECT item_id, MAX(name) AS name FROM hq_items
                     WHERE item_id IN ($phH) GROUP BY item_id"
                );
                $stI->execute(array_keys($hashIds));
                foreach ($stI as $ir) {
                    $orpNames[$hashIds[(int) $ir['item_id']]] = (string) $ir['name'];
                }
            } catch (Throwable $ex) {}
        }
        if ($regularUnclaimed) {
            $phU = implode(',', array_fill(0, count($regularUnclaimed), '?'));
            try {
                $stU = $pdo->prepare(
                    "SELECT c.code, COALESCE(i.name, c.code) AS name
                     FROM hq_codes c
                     LEFT JOIN hq_items i ON i.item_id = c.item_id
                     WHERE c.code IN ($phU)"
                );
                $stU->execute($regularUnclaimed);
                foreach ($stU as $ur) {
                    $orpNames[(string)$ur['code']] = (string)$ur['name'];
                }
            } catch (Throwable $ex) {}
        }
        foreach ($unclaimedCodes as $uc) {
            $orphanedDistItems[] = [
                'code' => $uc,
                'name' => $orpNames[$uc] ?? '—',
                'qty'  => (float) $ashraqaQtys[$uc],
            ];
        }
    }

    // رصيد إشراقة — بكل باركودات الصنف مش بكود الفاتورة بس
    $itemIds = [];
    foreach ($ashraqaItems as $it) {
        $iid = (int) ($it['item_id'] ?? 0);
        if ($iid > 0) $itemIds[$iid] = true;
    }

    $codesByItemId = [];
    if ($itemIds) {
        $ids = array_keys($itemIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $qc  = $pdo->prepare(
            "SELECT code, item_id FROM hq_codes
              WHERE item_id IN ($ph) AND unit_factor = 1"
        );
        $qc->execute($ids);
        foreach ($qc as $r) {
            $codesByItemId[(int) $r['item_id']][] = (string) $r['code'];
        }
    }

    $lookup = $ashraqaCodes;
    foreach ($codesByItemId as $list) {
        foreach ($list as $bc) $lookup[] = $bc;
    }
    $rawBalances = ashraqa_fetch_balances($lookup);

    $balances = [];
    foreach ($ashraqaItems as $it) {
        $c = (string) ($it['item_code'] ?? '');
        if (isset($rawBalances[$c])) { $balances[$c] = $rawBalances[$c]; continue; }
        foreach ($codesByItemId[(int) ($it['item_id'] ?? 0)] ?? [] as $bc) {
            if (isset($rawBalances[$bc])) { $balances[$c] = $rawBalances[$bc]; break; }
        }
    }

    $salePrices = ashraqa_get_prices($distId);
    $lastSync   = ashraqa_last_sync();

    // إجمالي تكلفة أصناف إشراقة في هذه الفاتورة
    $totalAshraqaCost = 0.0;
    foreach ($ashraqaItems as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = (float)($ashraqaQtys[$c] ?? 0);
        $cost = ash_mrk_cost($it);
        $totalAshraqaCost += ($q * $cost);
    }

    return [
        'header'        => $header,
        'dist_id'       => $distId,
        'items'         => $ashraqaItems,
        'qtys'          => $ashraqaQtys,
        'balances'      => $balances,
        'sale_prices'   => $salePrices,
        'total_cost'    => $totalAshraqaCost,
        'last_sync'     => $lastSync,
        'orphans'       => $orphanedDistItems,
    ];
}
