<?php
/**
 * ashraqa_prices_lib.php — أسعار بيع فرع إشراقة
 * نظام الفجالة ستور المالي
 *
 * جدول ashraqa_item_prices منفصل تماماً عن باقي الجداول.
 * قرار محمد ١٢ سبتمبر ٢٠٢٦: «عايز سعر لإشراقة لوحده ميغيّرش سعر أي حاجة في الجداول الباقية.»
 */

/**
 * أسعار إشراقة لفاتورة — [item_code => price]
 * استعلام واحد مجمّع لكل أصناف الفاتورة
 *
 * @param  int $distributionId معرف سجل التوزيع (invoice_distribution.id)
 * @return array<string, float>
 */
function ashraqa_get_prices(int $distributionId): array
{
    if ($distributionId <= 0) {
        return [];
    }

    try {
        $st = db()->prepare(
            'SELECT item_code, sale_price
               FROM ashraqa_item_prices
              WHERE distribution_id = ?'
        );
        $st->execute([$distributionId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['item_code']] = (float) $r['sale_price'];
        }
        return $out;
    } catch (Throwable $ex) {
        error_log('ashraqa_get_prices: ' . $ex->getMessage());
        return [];
    }
}

/**
 * حفظ/تحديث سعر إشراقة لصنف (UPSERT على المفتاح الفريد uq_ashraqa_dist_item)
 *
 * @param int $distributionId معرف سجل التوزيع (invoice_distribution.id)
 * @param string $itemCode كود الصنف (الباركود)
 * @param float $price سعر البيع لفرع إشراقة
 * @param int|null $userId معرف المستخدم الذي قام بالتحديث
 */
function ashraqa_save_price(int $distributionId, string $itemCode, float $price, ?int $userId = null): void
{
    if ($distributionId <= 0) {
        throw new InvalidArgumentException('معرف التوزيع غير صالح.');
    }
    $itemCode = trim($itemCode);
    if ($itemCode === '') {
        throw new InvalidArgumentException('كود الصنف مطلوب.');
    }
    if ($price < 0) {
        throw new InvalidArgumentException('السعر لازم يكون موجب.');
    }

    $st = db()->prepare(
        'INSERT INTO ashraqa_item_prices (distribution_id, item_code, sale_price, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            sale_price = VALUES(sale_price),
            updated_by = VALUES(updated_by)'
    );
    $st->execute([$distributionId, $itemCode, $price, $userId]);
}
