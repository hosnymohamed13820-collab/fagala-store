-- إضافة معامل الوحدة unit_factor في سطور فواتير المرآة (بريف ٢١١)
-- في الجدولين: hq_invoice_lines و br_invoice_lines
-- قابل لإعادة التشغيل: يفحص information_schema أولاً ومفيش backfill للصفوف القديمة (تفضل NULL).

SET @had_hq_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hq_invoice_lines' AND COLUMN_NAME = 'unit_factor'
);

SET @sql_hq := IF(@had_hq_col = 0,
    'ALTER TABLE `hq_invoice_lines` ADD COLUMN `unit_factor` DECIMAL(12,4) NULL AFTER `cost_price`',
    'DO 0'
);
PREPARE stmt_hq FROM @sql_hq;
EXECUTE stmt_hq;
DEALLOCATE PREPARE stmt_hq;

SET @had_br_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'br_invoice_lines' AND COLUMN_NAME = 'unit_factor'
);

SET @sql_br := IF(@had_br_col = 0,
    'ALTER TABLE `br_invoice_lines` ADD COLUMN `unit_factor` DECIMAL(12,4) NULL AFTER `cost_price`',
    'DO 0'
);
PREPARE stmt_br FROM @sql_br;
EXECUTE stmt_br;
DEALLOCATE PREPARE stmt_br;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_007_line_unit_factor');
