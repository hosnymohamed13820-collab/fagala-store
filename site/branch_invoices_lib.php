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
            . '<h2>🚫 غير مصرّح</h2>'
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
