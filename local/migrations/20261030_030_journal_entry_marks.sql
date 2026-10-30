-- بريف ٨٢٥: تلوين صفوف كشف الخزينة للمراجعة
-- آمن للتكرار: IF NOT EXISTS وتسجيل الـmigration بـINSERT IGNORE
-- من غير اسم قاعدة البيانات

CREATE TABLE IF NOT EXISTS journal_entry_marks (
    entry_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    color ENUM('green', 'yellow', 'red', 'blue') NOT NULL,
    marked_by INT UNSIGNED NOT NULL,
    marked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jem_color (color),
    KEY idx_jem_marked_by (marked_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261030_030_journal_entry_marks');
