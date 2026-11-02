<?php
/**
 * branch_invoices_lib.php — فواتير الفروع (الفجالة · دجلة · بونجور · اي بي سي)
 *
 * كل فرع ليه صفحة لوحده (fagala_invoices.php …) بس الكود كله في
 * branch_invoices_core.php — والملف ده فيه الخريطة والتسعير والرصيد.
 *
 * 🔴 إشراقة **مش هنا** — ليها ashraqa_invoices.php وجداولها بتاعتها
 *    (ashraqa_item_prices · ashraqa_invoice_status). قرار محمد ٢٢ سبتمبر ٢٠٢٦:
 *    تفضل زي ما هي لحد ما الصفحات دي تثبت.
 * 🔴 التسعير والحالة لكل فرع لوحده: كل صف عليه branch_id، وأي استعلام
 *    من غيره بيطلّع أسعار فرع في فرع تاني.
 */

/**
 * الفروع اللي ليها صفحة فواتير.
 * `agent` = مفتاح الوكيل في hq_branch_balances — 🔴 الفجالة `zahraa` مش `fagala`.
 *
 * @return array<string, array{id:int,key:string,agent:string,name:string,url:string}>
 */
function br_inv_map(): array
{
    return [
        'fagala'  => ['id' => 3, 'key' => 'fagala',  'agent' => 'zahraa',  'name' => 'الفجالة',  'url' => 'fagala_invoices.php'],
        'degla'   => ['id' => 4, 'key' => 'degla',   'agent' => 'degla',   'name' => 'دجلة',     'url' => 'degla_invoices.php'],
        'bonjour' => ['id' => 1, 'key' => 'bonjour', 'agent' => 'bonjour', 'name' => 'بونجور',   'url' => 'bonjour_invoices.php'],
        'abc'     => ['id' => 2, 'key' => 'abc',     'agent' => 'abc',     'name' => 'اي بي سي', 'url' => 'abc_invoices.php'],
    ];
}

/**
 * كل الصفحات اللي ليها فواتير فرع — الأربعة + إشراقة (ليها صفحتها بتاعتها).
 * @return array<int, array{id:int,key:string,name:string,url:string}> [branch_id => …]
 */
function br_inv_all_pages(): array
{
    $out = [];
    foreach (br_inv_map() as $b) {
        $out[$b['id']] = ['id' => $b['id'], 'key' => $b['key'], 'name' => $b['name'], 'url' => $b['url']];
    }
    $out[5] = ['id' => 5, 'key' => 'ashraqa', 'name' => 'إشراقة', 'url' => 'ashraqa_invoices.php'];
    ksort($out);
    return $out;
}

/**
 * موديول «فواتير الفروع» (branch_invoices) — اليوزر ده يشوف أنهي فرع؟
 * قرار محمد ٢٢ سبتمبر ٢٠٢٦:
 *   · فرعه **الإدارة** (in_receiving = 0) ← كل الفروع ('all')
 *   · فرعه فرع بيع ← فرعه بس (رقمه)
 *   · مش متسجّل على فرع ← ولا حاجة (null)
 * مالوش الموديول أصلاً ← null.
 *
 * @return 'all'|int|null
 */
function br_inv_user_scope()
{
    static $done = false, $scope = null;
    if ($done) return $scope;
    $done = true;
    if (!can('branch_invoices')) return $scope = null;
    $bid = (int) (current_user()['branch_id'] ?? 0);
    if ($bid <= 0) return $scope = null;
    try {
        $st = db()->prepare('SELECT in_receiving FROM branches WHERE id = ?');
        $st->execute([$bid]);
        $v = $st->fetchColumn();
    } catch (Throwable $ex) {
        $v = false;
    }
    // 🔴 فرع مش موجود أو غلط = فرعه بس (نقفل مش نفتح)
    return $scope = ($v !== false && (int) $v === 0) ? 'all' : $bid;
}

/**
 * يقدر يفتح فواتير الفرع ده؟ — الأونر، أو موديول «فواتير الفروع» بنطاقه.
 * 🔴 «الاستلامات» مالهاش دعوة (قرار محمد ٢٢ سبتمبر ٢٠٢٦: الموديولين
 *    مبقوش مربوطين). بتتنده في كل صفحة وكل export.
 */
function br_inv_can_view(int $branchId): bool
{
    if (!site_permission_enabled('branch_invoices')) return false;
    if (is_owner()) return true;
    $scope = br_inv_user_scope();
    return $scope === 'all' || $scope === $branchId;
}

/** زي require_permission بالظبط — نفس رسالة الصد. */
function br_inv_require(int $branchId): void
{
    require_login();
    if (!br_inv_can_view($branchId)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
            . '<h2>غير مصرّح</h2>'
            . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
            . '<a href="logout.php">تسجيل الخروج</a></div>');
    }
}

/** أسعار بيع الفرع لأصناف توزيع واحد. @return array<string,float> [item_code => price] */
function br_inv_get_prices(int $branchId, int $distributionId): array
{
    if ($branchId <= 0 || $distributionId <= 0) return [];
    try {
        $st = db()->prepare(
            'SELECT item_code, sale_price FROM branch_item_prices
              WHERE branch_id = ? AND distribution_id = ?'
        );
        $st->execute([$branchId, $distributionId]);
        $out = [];
        foreach ($st as $r) {
            $out[(string) $r['item_code']] = (float) $r['sale_price'];
        }
        return $out;
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش (local/branch_invoices.sql) — الصفحة تفضل شغالة من غير أسعار
        error_log('br_inv_get_prices: ' . $ex->getMessage());
        return [];
    }
}

/** حفظ/تحديث سعر صنف للفرع (UPSERT على uq_bip_branch_dist_item). */
function br_inv_save_price(int $branchId, int $distributionId, string $itemCode, float $price, ?int $userId = null): void
{
    if ($branchId <= 0 || $distributionId <= 0) {
        throw new InvalidArgumentException('معرف الفرع أو التوزيع غير صالح.');
    }
    $itemCode = trim($itemCode);
    if ($itemCode === '') {
        throw new InvalidArgumentException('كود الصنف مطلوب.');
    }
    if ($price < 0) {
        throw new InvalidArgumentException('السعر لازم يكون موجب.');
    }
    $st = db()->prepare(
        'INSERT INTO branch_item_prices (branch_id, distribution_id, item_code, sale_price, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            sale_price = VALUES(sale_price),
            updated_by = VALUES(updated_by)'
    );
    $st->execute([$branchId, $distributionId, $itemCode, $price, $userId]);
}

/**
 * رصيد الفرع لأصناف — استعلام واحد، بالـitem_id.
 *
 * 🔴 بنقرا **المخزن الرئيسي** للوكيل بس (اللي فيه أكتر أصناف): الوكيل ممكن
 *    يبعت أكتر من مخزن، وجمعهم بيدّي رقم مش هو اللي على الرف.
 * مفيش `unit_factor` هنا — الجدول ده بالـitem_id بالوحدة الأساسية، فمطب
 * «باركود العلبة ×١٢» بتاع إشراقة مش موجود.
 *
 * @param int[] $itemIds
 * @return array<int,float> [item_id => balance]
 */
function br_inv_balances(string $agent, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0)));
    if ($agent === '' || !$itemIds) return [];
    try {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $st = db()->prepare(
            "SELECT b.item_id, b.balance
               FROM hq_branch_balances b
               JOIN (SELECT sto_id FROM hq_branch_balances
                      WHERE agent_key = ?
                      GROUP BY sto_id ORDER BY COUNT(*) DESC LIMIT 1) m ON m.sto_id = b.sto_id
              WHERE b.agent_key = ? AND b.item_id IN ($ph)"
        );
        $st->execute(array_merge([$agent, $agent], $itemIds));
        $out = [];
        foreach ($st as $r) {
            $out[(int) $r['item_id']] = (float) $r['balance'];
        }
        return $out;
    } catch (Throwable $ex) {
        error_log('br_inv_balances: ' . $ex->getMessage());
        return [];
    }
}

/** آخر مرة الوكيل بعت أرصدة — للسطر اللي تحت الفاتورة. */
function br_inv_last_sync(string $agent): ?string
{
    try {
        $st = db()->prepare('SELECT MAX(updated_at) FROM hq_branch_balances WHERE agent_key = ?');
        $st->execute([$agent]);
        $v = $st->fetchColumn();
        return $v ? (string) $v : null;
    } catch (Throwable $ex) {
        return null;
    }
}

const BR_INV_DOC_RETURN = 6;
const BR_INV_MRK_ROUND_STEP = 0.5;

/** دقة تقريب نسبة التسعير لأقرب نصف جنيه */
function br_inv_mrk_round(float $v): float
{
    return round($v / BR_INV_MRK_ROUND_STEP) * BR_INV_MRK_ROUND_STEP;
}

/** استخراج تكلفة الصنف */
function br_inv_mrk_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

/** حساب نسبة هامش الربح */
function br_inv_mrk_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

/** تنسيق نسبة التسعير */
function br_inv_mrk_fmt(?float $p): string
{
    if ($p === null) return '';
    $s = number_format($p, 1, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

/**
 * دالة استخراج معرف سجل التوزيع لفاتورة معينة
 * بتجرّب المفتاح الكامل doc_no|company_branch|doc_type ثم doc_no|*|* ثم doc_no
 */
function br_inv_dist_for(array $inv, array $docNos): ?int
{
    $no = trim((string)($inv['inv_no'] ?? ($inv['doc_no'] ?? '')));
    if ($no === '') return null;
    $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
    $dt = $inv['doc_type'] ?? null;
    if ($cb !== null && $cb !== '' && $dt !== null && $dt !== '') {
        $k = $no . '|' . (int)$cb . '|' . (int)$dt;
        if (isset($docNos[$k])) return $docNos[$k];
    }
    if (isset($docNos[$no . '|*|*'])) return $docNos[$no . '|*|*'];
    if (isset($docNos[$no])) return $docNos[$no];
    return null;
}

/**
 * دالة استخراج أكواد التوزيع لفاتورة معينة
 */
function br_inv_codes_for(array $inv, array $codesByDoc): array
{
    $no = trim((string)($inv['inv_no'] ?? ($inv['doc_no'] ?? '')));
    if ($no === '') return [];
    $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
    $dt = $inv['doc_type'] ?? null;
    if ($cb !== null && $cb !== '' && $dt !== null && $dt !== '') {
        $k = $no . '|' . (int)$cb . '|' . (int)$dt;
        if (isset($codesByDoc[$k])) return $codesByDoc[$k];
    }
    if (isset($codesByDoc[$no . '|*|*'])) return $codesByDoc[$no . '|*|*'];
    if (isset($codesByDoc[$no])) return $codesByDoc[$no];
    return [];
}

/**
 * استخراج بيانات توزيع الفرع من جداول invoice_distribution و invoice_distribution_lines
 *
 * @return array{doc_nos:array<string,int>, codes_by_doc:array<string,array<string,true>>, qtys_by_dist:array<int,array<string,float>>, codes_by_dist:array<int,array<string,true>>}
 */
function br_inv_distribution_data(int $branchId): array
{
    $pdo = db();
    try {
        $rows = $pdo->prepare(
            'SELECT d.invoice_no, d.company_branch, d.doc_type, d.id AS dist_id, l.item_code, l.qty
               FROM invoice_distribution d
               JOIN (
                      SELECT distribution_id, notes AS item_code, SUM(amount) AS qty
                        FROM invoice_distribution_lines
                       WHERE branch_id = ?
                       GROUP BY distribution_id, notes
                      HAVING SUM(amount) > 0
                    ) l ON l.distribution_id = d.id
              WHERE (d.company_branch = 1 OR d.company_branch IS NULL)
                AND (d.doc_type = 2 OR d.doc_type IS NULL)'
        );
        $rows->execute([$branchId]);
    } catch (Throwable $ex) {
        $rows = $pdo->prepare(
            'SELECT d.invoice_no, NULL AS company_branch, NULL AS doc_type, d.id AS dist_id, l.item_code, l.qty
               FROM invoice_distribution d
               JOIN (
                      SELECT distribution_id, notes AS item_code, SUM(amount) AS qty
                        FROM invoice_distribution_lines
                       WHERE branch_id = ?
                       GROUP BY distribution_id, notes
                      HAVING SUM(amount) > 0
                    ) l ON l.distribution_id = d.id'
        );
        $rows->execute([$branchId]);
    }

    $docNos = [];
    $codesByDoc = [];
    $qtysByDist = [];
    $codesByDist = [];
    foreach ($rows as $r) {
        $did  = (int) $r['dist_id'];
        $code = trim((string) $r['item_code']);
        $qty  = (float) ($r['qty'] ?? 0);

        $qtysByDist[$did][$code] = $qty;
        $codesByDist[$did][$code] = true;

        $no = trim((string) $r['invoice_no']);
        $cb = ($r['company_branch'] !== null && $r['company_branch'] !== '') ? (string)$r['company_branch'] : '*';
        $dt = ($r['doc_type'] !== null && $r['doc_type'] !== '') ? (string)$r['doc_type'] : '*';

        $key = $no . '|' . $cb . '|' . $dt;
        $docNos[$key] = $did;
        $codesByDoc[$key][$code] = true;

        $docNos[$no] = $did;
        $codesByDoc[$no][$code] = true;
        $docNos[$no . '|*|*'] = $did;
        $codesByDoc[$no . '|*|*'][$code] = true;
    }

    return [
        'doc_nos'       => $docNos,
        'codes_by_doc'  => $codesByDoc,
        'qtys_by_dist'  => $qtysByDist,
        'codes_by_dist' => $codesByDist,
    ];
}

/**
 * خريطة حالات فواتير الفرع (branch_invoice_status).
 * @return array<int,string> [distribution_id => 'new'|'entered']
 */
function br_inv_status_map(int $branchId): array
{
    $out = [];
    try {
        $st = db()->prepare('SELECT distribution_id, status FROM branch_invoice_status WHERE branch_id = ?');
        $st->execute([$branchId]);
        foreach ($st as $r) {
            $out[(int) $r['distribution_id']] = (string) $r['status'];
        }
    } catch (Throwable $ex) {
        error_log('branch_invoice_status: ' . $ex->getMessage());
    }
    return $out;
}

/**
 * تحديث حالة مراجعة الفاتورة للفرع
 */
function br_inv_set_status(int $branchId, int $distId, string $status, ?int $userId = null): void
{
    if ($branchId <= 0 || $distId <= 0) {
        throw new InvalidArgumentException('معرف الفرع أو التوزيع غير صالح.');
    }
    if (!in_array($status, ['new', 'entered'], true)) {
        throw new InvalidArgumentException('حالة غير معروفة.');
    }
    $up = db()->prepare(
        'INSERT INTO branch_invoice_status (branch_id, distribution_id, status, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)'
    );
    $up->execute([$branchId, $distId, $status, $userId]);
}

/**
 * حفظ أسعار أصناف بالجملة للفرع داخل transaction.
 * ملاحظة: بينادي br_inv_save_price() جوه حلقة حسب التصميم الموروث.
 */
function br_inv_save_prices_bulk(int $branchId, int $distId, array $map, ?int $userId = null): int
{
    if ($branchId <= 0 || $distId <= 0 || empty($map)) {
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
            br_inv_save_price($branchId, $distId, $code, br_inv_mrk_round($price), $userId);
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
 * فلترة ناتج ilk_item_history على مستوى السيرفر لحصرها في الفرع المحدد
 * واستبعاد أسماء الموردين والفروع الأخرى.
 */
function br_inv_filter_item_history(array $res, int $branchId, array $distData, string $searchCode): array
{
    $docNos = $distData['doc_nos'] ?? [];
    $qtysByDist = $distData['qtys_by_dist'] ?? [];

    $rows = [];
    foreach ($res['rows'] ?? [] as $row) {
        $docNo  = (string) ($row['doc_no'] ?? $row['inv_no'] ?? '');
        $distId = br_inv_dist_for($row, $docNos) ?? ($docNos[$docNo] ?? null);
        if ($distId === null) continue;   // فاتورة مالهاش توزيع للفرع
        if (!empty($row['is_return'])) continue;        // مردود — مايظهرش

        $ashQty = 0.0;
        foreach (($row['dist'] ?? []) as $b) {
            if ((int) ($b['branch_id'] ?? 0) === $branchId) {
                $ashQty = (float) ($b['qty'] ?? 0);
                break;
            }
        }
        // احتياطي: لو ilk_distribution_map ما جابتهاش، نقراها من مصفوفة التوزيع المباشرة
        if ($ashQty <= 0 && $distId > 0) {
            $rowCode = (string) ($row['item_code'] ?? '');
            $ashQty = (float) (
                $qtysByDist[$distId][$rowCode]
                ?? ($qtysByDist[$distId][$searchCode]
                ?? ($qtysByDist[$distId]['#' . (int)($row['item_id'] ?? 0)] ?? 0))
            );
        }
        if ($ashQty <= 0) continue;

        // الحقول دي بس هي اللي بتوصل المتصفح — مفيش مورد ولا فرع تاني
        $rows[] = [
            'doc_no'    => $docNo,
            'sth_id'    => (int) ($row['sth_id'] ?? 0),
            'suffix'    => (string) ($row['suffix'] ?? ''),
            'date'      => (string) ($row['date'] ?? ''),
            'qty'       => (float) ($row['qty'] ?? 0),
            'ash_qty'   => $ashQty,
        ];
    }

    $item = $res['item'] ?? null;
    return [
        'ok'    => true,
        'item'  => $item ? [
            'code' => (string) ($item['code'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
        ] : null,
        'rows'  => $rows,
    ];
}

/**
 * جلب قائمة فواتير الفرع مع حساب التكلفة وعدد الأصناف وحالة المراجعة.
 */
function br_inv_list_data(int $branchId, array $distData, array $statusMap = []): array
{
    require_once __DIR__ . '/receiving_lib.php';
    $docNos = $distData['doc_nos'] ?? [];
    $codesByDoc = $distData['codes_by_doc'] ?? [];
    $qtysByDist = $distData['qtys_by_dist'] ?? [];
    $codesByDist = $distData['codes_by_dist'] ?? [];

    $all = sqlsrv_fetch_invoices();
    $cand = [];
    foreach ($all as $inv) {
        $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
        if ($cb !== null && (int)$cb !== 1) continue;

        $dId = br_inv_dist_for($inv, $docNos);
        if ($dId === null) continue;
        if ((int) ($inv['doc_type'] ?? 0) === BR_INV_DOC_RETURN) continue;
        $inv['dist_id'] = $dId;
        $cand[] = $inv;
    }

    $sthIds = array_values(array_unique(array_map(
        fn($i) => (int) $i['inv_id'], $cand
    )));
    $lineCodes = [];
    $lineCosts = [];
    if ($sthIds) {
        $ph = implode(',', array_fill(0, count($sthIds), '?'));
        $q  = db()->prepare(
            "SELECT sth_id, suffix, item_code, item_id, unit_price, cost_price
               FROM hq_invoice_lines WHERE sth_id IN ($ph)"
        );
        $q->execute($sthIds);
        foreach ($q as $r) {
            $k = (int) $r['sth_id'] . '|' . (string) $r['suffix'];
            $c = trim((string) ($r['item_code'] ?? ''));
            if ($c === '') $c = '#' . (int) ($r['item_id'] ?? 0);
            $lineCodes[$k][$c] = true;

            $cost = (float)($r['unit_price'] ?? 0);
            if ($cost <= 0) $cost = (float)($r['cost_price'] ?? 0);
            if (!isset($lineCosts[$k][$c]) || $cost > $lineCosts[$k][$c]) {
                $lineCosts[$k][$c] = $cost;
            }
            if (!empty($r['item_id'])) {
                $hKey = '#' . (int)$r['item_id'];
                if (!isset($lineCosts[$k][$hKey]) || $cost > $lineCosts[$k][$hKey]) {
                    $lineCosts[$k][$hKey] = $cost;
                }
            }
        }
    }

    $invoices = [];
    foreach ($cand as $inv) {
        $k   = (int) $inv['inv_id'] . '|' . (string) ($inv['suffix'] ?? '');
        $dId = (int) $inv['dist_id'];
        if (isset($lineCodes[$k])) {
            $codes = br_inv_codes_for($inv, $codesByDoc);
            if (!array_intersect_key($codes, $lineCodes[$k])) {
                continue;
            }
        }

        $invTotal = 0.0;
        foreach (($qtysByDist[$dId] ?? []) as $c => $q) {
            $cost = $lineCosts[$k][$c] ?? 0.0;
            $invTotal += ($q * $cost);
        }
        $inv['ash_total'] = $invTotal;
        $inv['ash_items_count'] = count($codesByDist[$dId] ?? []);
        $inv['branch_status'] = $statusMap[$dId] ?? 'new';

        $invoices[] = $inv;
    }

    return $invoices;
}

/**
 * جلب تفاصيل فاتورة مفتوحة للفرع بعد فحص الحماية والشروط
 */
function br_inv_invoice_detail(int $branchId, string $openId, ?string $openSuf, array $distData, ?string $agent = null): ?array
{
    require_once __DIR__ . '/receiving_lib.php';
    $docNos = $distData['doc_nos'] ?? [];
    $pdo = db();

    $header = sqlsrv_fetch_invoice_header($openId, $openSuf);
    $isRet = $header && (int)($header['doc_type'] ?? 0) === BR_INV_DOC_RETURN;
    $cb = $header ? ($header['company_branch_id'] ?? ($header['company_branch'] ?? null)) : null;
    $distId = $header ? br_inv_dist_for($header, $docNos) : null;

    if (!$header || $isRet || ($cb !== null && (int)$cb !== 1) || $distId === null) {
        return null;
    }

    $rawItems = sqlsrv_fetch_invoice_items($openId, $openSuf);
    $items = merge_invoice_items($rawItems);

    $stQty = $pdo->prepare(
        'SELECT notes AS item_code, SUM(amount) AS qty
           FROM invoice_distribution_lines
          WHERE distribution_id = ? AND branch_id = ?
          GROUP BY notes'
    );
    $stQty->execute([$distId, $branchId]);
    $branchQtys = [];
    foreach ($stQty as $qr) {
        $branchQtys[(string)$qr['item_code']] = (float)$qr['qty'];
    }

    $distCodes = array_keys($branchQtys);
    $distCodeToItemId = [];
    $distCodesByItemId = [];
    $nonHashDistCodes = [];
    foreach ($distCodes as $dc) {
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
        } catch (Throwable $ex) {}
    }

    $claimedDistCodes = [];
    $branchItems = [];
    $branchCodes = [];
    foreach ($items as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = 0.0;
        if ($c !== '' && isset($branchQtys[$c]) && !isset($claimedDistCodes[$c])) {
            $q = (float) $branchQtys[$c];
            $claimedDistCodes[$c] = true;
        } elseif (!empty($it['item_id'])) {
            $iid = (int) $it['item_id'];
            $candCodes = $distCodesByItemId[$iid] ?? [];
            foreach ($candCodes as $dc) {
                if (!isset($claimedDistCodes[$dc]) && isset($branchQtys[$dc]) && (str_starts_with($c, '#') || str_starts_with($dc, '#'))) {
                    $q += (float) $branchQtys[$dc];
                    $claimedDistCodes[$dc] = true;
                }
            }
        }
        if ($q <= 0) continue;
        $branchQtys[$c] = $q;
        $branchItems[] = $it;
        $branchCodes[] = $c;
    }

    $orphanedDistItems = [];
    $unclaimedCodes = [];
    foreach ($branchQtys as $dc => $dQty) {
        if (!isset($claimedDistCodes[$dc]) && $dQty > 0) {
            $unclaimedCodes[] = $dc;
        }
    }
    if ($unclaimedCodes) {
        $orpNames = [];
        $regularUnclaimed = [];
        $hashIds = [];
        foreach ($unclaimedCodes as $uc) {
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
                'qty'  => (float) $branchQtys[$uc],
            ];
        }
    }

    $itemIdByCode = [];
    foreach ($branchItems as $it) {
        $c   = (string) ($it['item_code'] ?? '');
        $iid = (int) ($it['item_id'] ?? 0);
        if ($iid <= 0 && str_starts_with($c, '#')) $iid = (int) substr($c, 1);
        if ($iid <= 0) $iid = (int) ($distCodeToItemId[$c] ?? 0);
        if ($iid > 0) $itemIdByCode[$c] = $iid;
    }

    if ($agent === null || $agent === '') {
        foreach (br_inv_map() as $b) {
            if ($b['id'] === $branchId) {
                $agent = $b['agent'];
                break;
            }
        }
    }

    $rawBalances = ($agent !== null && $agent !== '') ? br_inv_balances($agent, array_values($itemIdByCode)) : [];
    $balances = [];
    foreach ($itemIdByCode as $c => $iid) {
        if (isset($rawBalances[$iid])) $balances[$c] = ['balance' => $rawBalances[$iid]];
    }

    $salePrices = br_inv_get_prices($branchId, $distId);
    $lastSync   = ($agent !== null && $agent !== '') ? br_inv_last_sync($agent) : null;

    $totalCost = 0.0;
    foreach ($branchItems as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = (float)($branchQtys[$c] ?? 0);
        $cost = br_inv_mrk_cost($it);
        $totalCost += ($q * $cost);
    }

    return [
        'header'             => $header,
        'dist_id'            => $distId,
        'items'              => $branchItems,
        'branch_qtys'        => $branchQtys,
        'branch_codes'       => $branchCodes,
        'orphaned_items'     => $orphanedDistItems,
        'balances'           => $balances,
        'sale_prices'        => $salePrices,
        'last_sync'          => $lastSync,
        'total_cost'         => $totalCost,
    ];
}

/** Only fields used by the branch and Ashraqa invoice pages leave the API. */
function br_inv_public_invoice_rows(array $invoices): array
{
    $fields = array_fill_keys([
        'dist_id', 'inv_id', 'suffix', 'inv_no', 'invoice_date',
        'ash_items_count', 'ash_total',
    ], true);

    return array_map(static fn(array $invoice): array => array_intersect_key($invoice, $fields), $invoices);
}

/**
 * إزالة أي مفتاح يبدأ بـ 'supplier' من المصفوفة بشكل متكرر
 * لمنع تسريب بيانات الموردين في واجهات برمجة الفروع وإشراقة (بريف ١٦٢ مكرر).
 */
function br_inv_strip_supplier(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }
    $clean = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && str_starts_with(strtolower($k), 'supplier')) {
            continue;
        }
        $clean[$k] = is_array($v) ? br_inv_strip_supplier($v) : $v;
    }
    return $clean;
}

