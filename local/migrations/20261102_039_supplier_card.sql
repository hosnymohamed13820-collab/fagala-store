-- بيانات كارت المورد وكوده الفريد؛ الفحص قبل كل تعديل بيخلّي التشغيل التاني آمن.
SET @sc_schema = DATABASE();
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='phone2'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN phone2 varchar(30) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='tax_no'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN tax_no varchar(40) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='credit_days'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN credit_days int NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='credit_limit'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN credit_limit decimal(14,2) NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='notes'), 'DO 0', 'ALTER TABLE suppliers ADD COLUMN notes text NULL');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
SET @sc_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='suppliers' AND INDEX_NAME='uq_suppliers_erp_code'), 'DO 0', 'ALTER TABLE suppliers ADD UNIQUE KEY uq_suppliers_erp_code (erp_supplier_code)');
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_039_supplier_card');
