-- =====================================================================
--  ashraqa_invoice_status — حالة مراجعة الفاتورة عند فرع إشراقة
--  نظام الفجالة ستور · ١٢ سبتمبر ٢٠٢٦
--
--  ليه جدول لوحده؟
--    `invoice_distribution.status` (new/split/done) بتاعة موديول
--    الاستلامات والمشتريات شغّالة بيها. عاشور بيراجع فواتيره ويعلّم
--    «تم الإدخال» لما يخلّص إدخالها عندهم — وده **معنى تاني خالص**
--    مالوش علاقة بتوزيع الفاتورة على الفروع.
--    الكتابة في العمود المشترك كانت هتخلّي فاتورة اتوزّعت خلاص
--    تبان «جديدة» للمشتريات.
--
--  🔴 غياب الصف = «جديدة». مفيش backfill ولا أي لمس للفواتير القديمة.
--
--  شغّله من phpMyAdmin **قبل** ما ترفع الملفات.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ashraqa_invoice_status` (
  `distribution_id` bigint(20) UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `status` enum('new','entered') NOT NULL DEFAULT 'new' COMMENT 'new=جديدة · entered=تم الإدخال',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`distribution_id`),
  KEY `idx_ais_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
