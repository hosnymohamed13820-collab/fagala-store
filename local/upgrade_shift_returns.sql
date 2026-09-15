-- =====================================================================
--  upgrade_shift_returns.sql — إرجاع الورديات وتسلسل الأرقام
--  نظام الفجالة ستور المالي
-- =====================================================================

-- الجزء (أ): عمود انتهاء مهلة الوردية المرجوعة وإعداد الساعات
ALTER TABLE `u834909335_fagala`.`shifts`
  ADD COLUMN IF NOT EXISTS `return_expired_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'الوردية المرجوعة عدّت مهلة التعديل (٤٨ ساعة) من غير ما تتقفل تاني';

INSERT IGNORE INTO `u834909335_fagala`.`settings` (setting_key, setting_value) VALUES ('shift_return_hours', '48');

-- الجزء (ب): جدول مشاكل تسلسل أرقام الورديات وإعداد تاريخ البداية
CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`shift_sequence_issues` (
  `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id`        INT UNSIGNED NOT NULL,
  `shift_id`         BIGINT UNSIGNED NULL,
  `kind`             ENUM('gap','duplicate') NOT NULL,
  `expected_no`      INT NULL,
  `found_no`         INT NOT NULL,
  `status`           ENUM('open','ignored','sent','answered') DEFAULT 'open',
  `assigned_user_id` INT UNSIGNED NULL,
  `owner_note`       VARCHAR(255) NULL,
  `reply`            VARCHAR(500) NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_by`      INT UNSIGNED NULL,
  `resolved_at`      TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_issue` (`branch_id`, `kind`, `found_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `u834909335_fagala`.`settings` (setting_key, setting_value) VALUES ('shift_seq_start_date', '');

-- 🔴 مهلة الـ٤٨ ساعة بتبدأ من **لحظة نزول الملف ده** مش على الورديات القديمة.
--    من غير السطر ده كل وردية مرجوعة من أغسطس كانت هتتحمّل على الكاشير
--    بكامل قيمة السيستم أول ما الكود يترفع (والعجز بيتخصم من المرتب لوحده).
--    INSERT IGNORE: لو اتشغّل تاني مابيغيّرش التاريخ الأصلي.
INSERT IGNORE INTO `u834909335_fagala`.`settings` (setting_key, setting_value)
VALUES ('shift_return_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));
