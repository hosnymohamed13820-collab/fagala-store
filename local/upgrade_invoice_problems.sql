-- upgrade_invoice_problems.sql
-- إنشاء جدول مشاكل الفواتير (تلقائي من المردودات + يدوي من ملاحظات الأصناف)
-- نظام الفجالة ستور المالي

CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`invoice_problems` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_id` BIGINT UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code`       VARCHAR(60) NOT NULL,
  `item_name`       VARCHAR(255) DEFAULT NULL,
  `source`          ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  `short_qty`       DECIMAL(14,3) NOT NULL DEFAULT 0.000 COMMENT 'ناقص',
  `over_qty`        DECIMAL(14,3) NOT NULL DEFAULT 0.000 COMMENT 'زيادة',
  `note`            TEXT DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_problem` (`distribution_id`, `item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
