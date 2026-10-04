-- بريف ١٠٢: توكن لكل جهاز. 🔴 شغّله على قاعدة الفجالة بس (اختارها في phpMyAdmin الأول).
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `device_name`  VARCHAR(80) NOT NULL,
  `pw_version`   INT NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `last_ip`      VARCHAR(45) NULL,
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_token_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
