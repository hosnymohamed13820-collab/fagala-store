-- =====================================================================
--  ashraqa_item_prices — أسعار بيع خاصة بفرع إشراقة
--  نظام الفجالة ستور · ١٢ سبتمبر ٢٠٢٦
--
--  ليه جدول لوحده؟
--    `invoice_item_prices` سعر **مشترك** لكل الفروع (المفتاح
--    distribution_id + item_code، مفيش فيه فرع). لو عاشور سعّر صنف
--    هناك، السعر كان هيتغيّر عند المشتريات وكل الفروع.
--    قرار محمد ١٢ سبتمبر ٢٠٢٦: «عايز سعر لإشراقة لوحده ميغيّرش سعر
--    أي حاجة في الجداول الباقية.»
--
--  🔴 الجدول ده **مايمسّش** `invoice_item_prices` خالص. الاتنين
--     بيعيشوا جنب بعض: المشتريات بتقرا من القديم، وصفحة فواتير
--     إشراقة بتقرا وتكتب في ده بس.
--
--  شغّله من phpMyAdmin **قبل** ما ترفع الملفات.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ashraqa_item_prices` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_id` bigint(20) UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code` varchar(64) NOT NULL COMMENT 'كود الصنف = الباركود',
  `sale_price` decimal(14,5) NOT NULL DEFAULT 0.00000 COMMENT 'سعر بيع إشراقة وحده',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ashraqa_dist_item` (`distribution_id`,`item_code`),
  KEY `idx_aip_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
