-- الفترة المحفوظة — صف واحد (id = 1) مشترك لكل المستخدمين، زي دفعة تحليل الموسم
CREATE TABLE IF NOT EXISTS `undist_state` (
  `id`         TINYINT UNSIGNED NOT NULL,
  `date_from`  DATE NOT NULL,
  `date_to`    DATE NOT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ملاحظة لكل صنف في الفترة المحفوظة — بتتمسح كلها لما الفترة تتغيّر
CREATE TABLE IF NOT EXISTS `undist_notes` (
  `item_code`  VARCHAR(64) NOT NULL,
  `note`       TEXT NOT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
