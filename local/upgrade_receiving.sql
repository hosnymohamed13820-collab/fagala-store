-- =====================================================================
--  upgrade_receiving.sql — موديول استلام الفواتير + عهدة الموظف
--  قاعدة البيانات: u834909335_fagala
-- =====================================================================

CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`receipts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'المجموعة — 0 يعني فاتورة مفردة',
  `distribution_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'الفاتورة المفردة — 0 يعني مجموعة',
  `branch_id`       INT UNSIGNED NOT NULL,
  `status`          ENUM('sent','receiving','received','approved') NOT NULL DEFAULT 'sent',
  `started_at`      TIMESTAMP NULL DEFAULT NULL,
  `started_by`      INT UNSIGNED DEFAULT NULL,
  `received_at`     TIMESTAMP NULL DEFAULT NULL,
  `received_by`     INT UNSIGNED DEFAULT NULL,
  `approved_at`     TIMESTAMP NULL DEFAULT NULL,
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt` (`group_id`, `distribution_id`, `branch_id`),
  KEY `idx_branch_status` (`branch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`receipt_lines` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id`  BIGINT UNSIGNED NOT NULL,
  `item_code`   VARCHAR(60) NOT NULL,
  `item_name`   VARCHAR(255) DEFAULT NULL,
  `scanned_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_item` (`receipt_id`, `item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `u834909335_fagala`.`settings` (setting_key, setting_value)
VALUES ('receiving_supervisor_id', '0');

ALTER TABLE `u834909335_fagala`.`branch_custody`
  ADD COLUMN IF NOT EXISTS `employee_id` INT UNSIGNED DEFAULT NULL
  COMMENT 'عهدة موظف — NULL = عهدة فرع زي الأول';

ALTER TABLE `u834909335_fagala`.`branch_custody`
  ADD INDEX IF NOT EXISTS `idx_employee` (`employee_id`);
