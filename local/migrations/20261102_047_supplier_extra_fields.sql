-- مكان الاستلام والتخصص وسبب المرتجع؛ فحص الجداول والأعمدة بيخلّي التشغيل التاني من غير أخطاء.
SET @sc_schema = DATABASE();
SET @sc_sql = IF(NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers') OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='receiving_location'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN receiving_location VARCHAR(150) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers') OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='trade_specialty'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN trade_specialty VARCHAR(100) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_docs') OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='return_reason'), 'DO 0', 'ALTER TABLE stock_docs ADD COLUMN return_reason VARCHAR(255) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_047_supplier_extra_fields');
