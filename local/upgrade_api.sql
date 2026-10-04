-- بريف ١٠١: جدول حماية العمليات من التكرار. 🔴 شغّله على قاعدة الفجالة بس (اختارها في phpMyAdmin الأول).
CREATE TABLE IF NOT EXISTS `api_idempotency` (
  `key_hash`   CHAR(64) NOT NULL,
  `user_id`    INT NOT NULL,
  `created_at` DATETIME NOT NULL,
  `status`     SMALLINT NULL,
  `response`   MEDIUMTEXT NULL,
  PRIMARY KEY (`key_hash`),
  KEY `idx_idem_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
