-- =====================================================================
--  الفواتير المجمّعة (Invoice Groups) — نظام الفجالة ستور
--  شغّله مرة على اللوكال ومرة على الهوستنج. آمن للتكرار.
--
--  الفكرة: المستخدم بيختار أكتر من فاتورة، والشاشة بتدمج أصنافهم
--  في جدول واحد ويوزّع مرة واحدة. التوزيع بيتكتب في
--  invoice_distribution_lines بتاع **كل فاتورة أصلية** —
--  فالمرجع بيفضل سليم، ومسح المجموعة مابيمسحش أي توزيع.
-- =====================================================================

-- (1) المجموعة
CREATE TABLE IF NOT EXISTS `invoice_groups` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NULL COMMENT 'اسم اختياري للمجموعة',
  `status`     ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ig_status` (`status`),
  CONSTRAINT `fk_ig_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (2) فواتير المجموعة
--     sort_order بيحدد ترتيب "الشلال" — الفاتورة الأقدم تتملي الأول.
CREATE TABLE IF NOT EXISTS `invoice_group_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`    BIGINT UNSIGNED NOT NULL,
  `sth_id`      BIGINT NOT NULL COMMENT 'معرّف الفاتورة في نظام الشركة',
  `suffix`      VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'YY_MM = غير محفوظة، أو لاحقة الشهر',
  `invoice_no`  VARCHAR(60) NULL,
  `supplier_name` VARCHAR(150) NULL,
  `invoice_date`  DATETIME NULL,
  `sort_order`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_igi` (`group_id`,`sth_id`,`suffix`),
  KEY `idx_igi_group` (`group_id`),
  CONSTRAINT `fk_igi_group` FOREIGN KEY (`group_id`) REFERENCES `invoice_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
