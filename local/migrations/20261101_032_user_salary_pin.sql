-- بريف ٨٣٢: رقم سري منفصل لكشف الحساب والمرتب، وعدّاد الغلط محفوظ بعد الخروج.
-- آمن للتكرار: بنفحص كل عمود قبل إضافته، والتسجيل بـINSERT IGNORE.
SET @salary_pin_schema = DATABASE();
SET @salary_pin_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@salary_pin_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_hash'),
    'DO 0', 'ALTER TABLE users ADD COLUMN salary_pin_hash VARCHAR(255) NULL'
);
PREPARE salary_pin_stmt FROM @salary_pin_sql; EXECUTE salary_pin_stmt; DEALLOCATE PREPARE salary_pin_stmt;
SET @salary_pin_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@salary_pin_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_fails'),
    'DO 0', 'ALTER TABLE users ADD COLUMN salary_pin_fails TINYINT UNSIGNED NOT NULL DEFAULT 0'
);
PREPARE salary_pin_stmt FROM @salary_pin_sql; EXECUTE salary_pin_stmt; DEALLOCATE PREPARE salary_pin_stmt;
SET @salary_pin_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@salary_pin_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_block_until'),
    'DO 0', 'ALTER TABLE users ADD COLUMN salary_pin_block_until DATETIME NULL'
);
PREPARE salary_pin_stmt FROM @salary_pin_sql; EXECUTE salary_pin_stmt; DEALLOCATE PREPARE salary_pin_stmt;
SET @salary_pin_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@salary_pin_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_set_at'),
    'DO 0', 'ALTER TABLE users ADD COLUMN salary_pin_set_at DATETIME NULL'
);
PREPARE salary_pin_stmt FROM @salary_pin_sql; EXECUTE salary_pin_stmt; DEALLOCATE PREPARE salary_pin_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_032_user_salary_pin');
