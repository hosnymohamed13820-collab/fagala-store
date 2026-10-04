-- ترقية بريف ١٠٠: إنشاء جدول سجل العمليات audit_requests
-- 🔴 شغّله على قاعدة الفجالة بس (اختارها في phpMyAdmin الأول).
-- لتسجيل كل طلبات الكتابة من المستخدمين (الوقت، المستخدم، الصفحة، العملية، المدخلات بدون كلمات السر)
CREATE TABLE IF NOT EXISTS `audit_requests` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL,
  `user_id`    INT NULL,
  `username`   VARCHAR(60) NULL,
  `ip`         VARCHAR(45) NULL,
  `method`     VARCHAR(8) NOT NULL,
  `page`       VARCHAR(80) NOT NULL,
  `action`     VARCHAR(80) NULL,
  `params`     TEXT NULL,
  `status`     SMALLINT NULL,
  `ms`         INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_time` (`created_at`),
  KEY `idx_audit_user` (`user_id`, `created_at`),
  KEY `idx_audit_page` (`page`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
