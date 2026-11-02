-- إلغاء الجزاء مع حفظ مين ألغاه وإمتى وليه؛ فحص الهيكل بيخلّي تكرار التشغيل آمن.
SET @pc_schema = DATABASE();
SET @pc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pc_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='status' AND COLUMN_TYPE="enum('pending','deducted','cancelled')"), 'DO 0', "ALTER TABLE employee_penalties MODIFY COLUMN status enum('pending','deducted','cancelled') NOT NULL DEFAULT 'pending'");
PREPARE pc_stmt FROM @pc_sql; EXECUTE pc_stmt; DEALLOCATE PREPARE pc_stmt;
SET @pc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pc_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancelled_at'), 'DO 0', 'ALTER TABLE employee_penalties ADD COLUMN cancelled_at datetime NULL');
PREPARE pc_stmt FROM @pc_sql; EXECUTE pc_stmt; DEALLOCATE PREPARE pc_stmt;
SET @pc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pc_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancelled_by'), 'DO 0', 'ALTER TABLE employee_penalties ADD COLUMN cancelled_by int NULL');
PREPARE pc_stmt FROM @pc_sql; EXECUTE pc_stmt; DEALLOCATE PREPARE pc_stmt;
SET @pc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pc_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancel_reason'), 'DO 0', 'ALTER TABLE employee_penalties ADD COLUMN cancel_reason varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
PREPARE pc_stmt FROM @pc_sql; EXECUTE pc_stmt; DEALLOCATE PREPARE pc_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_040_penalty_cancel');
