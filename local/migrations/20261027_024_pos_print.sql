-- بريف ٢٤٤: وضع الطباعة لكل جهاز (مباشر من المتصفح أو عن طريق طابور الوكيل)
-- آمن للتكرار: يفحص information_schema أولاً والتسجيل بـINSERT IGNORE.

SET @db_name := DATABASE();

SET @had_print_mode := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'sale_devices' AND COLUMN_NAME = 'print_mode'
);

SET @sql_print_mode := IF(@had_print_mode = 0,
    'ALTER TABLE `sale_devices` ADD COLUMN `print_mode` ENUM(''browser'',''queue'') NOT NULL DEFAULT ''browser'' AFTER `is_active`',
    'DO 0'
);
PREPARE stmt_pm FROM @sql_print_mode;
EXECUTE stmt_pm;
DEALLOCATE PREPARE stmt_pm;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_024_pos_print');
