-- كود تلقائي لأصناف الموقع بس، من غير backfill. آمن للتكرار بفحص العمود والفهرس.
SET @im_schema = DATABASE();
SET @im_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@im_schema AND TABLE_NAME='item_master' AND COLUMN_NAME='sys_code'),
    'DO 0', 'ALTER TABLE item_master ADD COLUMN sys_code VARCHAR(20) NULL');
PREPARE im_stmt FROM @im_sql; EXECUTE im_stmt; DEALLOCATE PREPARE im_stmt;
SET @im_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@im_schema AND TABLE_NAME='item_master' AND INDEX_NAME='uq_item_master_sys_code'),
    'DO 0', 'ALTER TABLE item_master ADD UNIQUE KEY uq_item_master_sys_code (sys_code)');
PREPARE im_stmt FROM @im_sql; EXECUTE im_stmt; DEALLOCATE PREPARE im_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_037_item_sys_code');
