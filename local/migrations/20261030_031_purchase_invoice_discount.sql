-- بريف ٨٢٩: خصم الفاتورة بيتوزع على السطور، من غير تغيير أي مستند قديم.
-- آمن للتكرار: كل عمود بيتفحص قبل إضافته، والتسجيل بـINSERT IGNORE.
SET @purchase_schema = DATABASE();
SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='inv_discount_type'),
    'DO 0', 'ALTER TABLE stock_docs ADD COLUMN inv_discount_type ENUM(''percent'',''amount'') NULL'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;
SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='inv_discount_value'),
    'DO 0', 'ALTER TABLE stock_docs ADD COLUMN inv_discount_value DECIMAL(12,4) NOT NULL DEFAULT 0'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;
SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_docs' AND COLUMN_NAME='gross_amount'),
    'DO 0', 'ALTER TABLE stock_docs ADD COLUMN gross_amount DECIMAL(14,2) NULL'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;
SET @purchase_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@purchase_schema AND TABLE_NAME='stock_doc_lines' AND COLUMN_NAME='inv_discount_alloc'),
    'DO 0', 'ALTER TABLE stock_doc_lines ADD COLUMN inv_discount_alloc DECIMAL(12,2) NOT NULL DEFAULT 0'
);
PREPARE purchase_stmt FROM @purchase_sql; EXECUTE purchase_stmt; DEALLOCATE PREPARE purchase_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261030_031_purchase_invoice_discount');
