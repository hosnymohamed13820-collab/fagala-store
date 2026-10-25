-- بريف ٢١٧: قيم فواتير الشراء ومرتجعات المورد على مستندات المخزن.
-- آمن للتكرار: كل ALTER بيفحص information_schema قبل الإضافة.

SET @purchase_schema = DATABASE();

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_doc_lines' AND COLUMN_NAME='unit_price'),
    'DO 0',
    'ALTER TABLE stock_doc_lines ADD COLUMN unit_price DECIMAL(14,4) NULL AFTER unit_cost'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_doc_lines' AND COLUMN_NAME='discount_pct'),
    'DO 0',
    'ALTER TABLE stock_doc_lines ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000 AFTER unit_price'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_doc_lines' AND COLUMN_NAME='line_total'),
    'DO 0',
    'ALTER TABLE stock_doc_lines ADD COLUMN line_total DECIMAL(14,2) NULL AFTER discount_pct'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='orig_doc_id'),
    'DO 0',
    'ALTER TABLE stock_docs ADD COLUMN orig_doc_id BIGINT UNSIGNED NULL AFTER supplier_id, ADD KEY idx_stock_docs_orig (orig_doc_id)'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='total_amount'),
    'DO 0',
    'ALTER TABLE stock_docs ADD COLUMN total_amount DECIMAL(14,2) NULL AFTER orig_doc_id'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='hq_match'),
    'DO 0',
    'ALTER TABLE stock_docs ADD COLUMN hq_match VARCHAR(40) NULL AFTER total_amount'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_011_purchase_docs');
