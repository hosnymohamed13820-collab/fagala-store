-- =====================================================================
--  upgrade_shift_edit_log.sql — سجل تعديل ومسح الورديات
--  نظام الفجالة ستور المالي
-- =====================================================================

CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`shift_edit_log` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shift_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `action`      ENUM('edit','date_branch','delete') NOT NULL,
  `before_json` TEXT NOT NULL,
  `after_json`  TEXT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `shift_id` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `u834909335_fagala`.`settings` (setting_key, setting_value) VALUES ('shift_edit_accountant_id', '0');
