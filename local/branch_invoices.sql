-- =====================================================================
-- فواتير الفروع (بريف ٣٧) — الفجالة · دجلة · بونجور · اي بي سي
-- 🔴 شغّله من phpMyAdmin **قبل** رفع الملفات.
-- نفس جداول إشراقة (ashraqa_item_prices · ashraqa_invoice_status) + branch_id.
-- جداول إشراقة ما بتتلمسش — عاشور شغال عليها.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `branch_item_prices` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) UNSIGNED NOT NULL COMMENT 'branches.id',
  `distribution_id` bigint(20) UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code` varchar(64) NOT NULL COMMENT 'كود الصنف = الباركود',
  `sale_price` decimal(14,5) NOT NULL DEFAULT 0.00000 COMMENT 'سعر بيع الفرع ده وحده',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bip_branch_dist_item` (`branch_id`,`distribution_id`,`item_code`),
  KEY `idx_bip_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `branch_invoice_status` (
  `branch_id` int(10) UNSIGNED NOT NULL COMMENT 'branches.id',
  `distribution_id` bigint(20) UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `status` enum('new','entered') NOT NULL DEFAULT 'new' COMMENT 'new=جديدة · entered=تم الإدخال',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`branch_id`,`distribution_id`),
  KEY `idx_bis_status` (`branch_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
