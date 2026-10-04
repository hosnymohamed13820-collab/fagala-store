-- شغّله على قاعدة الفجالة بس. أول ملف في السجل — بيعمل الجدول نفسه (بريف ١٠٨).
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `name`       VARCHAR(120) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- خط الأساس: كل اللي قبل ٤ أكتوبر ٢٠٢٦ (ملفات local/*.sql + upgrade_audit · upgrade_api ·
-- upgrade_api_tokens · upgrade_role_perms) اتشغّل ومتسجّل في local/schema_live.sql.
INSERT IGNORE INTO `schema_migrations` (`name`) VALUES ('20261004_000_schema_migrations');
