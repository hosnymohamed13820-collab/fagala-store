-- بريف ٨١٣: فهارس السرعة للأرصدة والفواتير
-- 1) hq_branch_balances(sto_id, item_id): استعلامات رصيد المخزن/الفرع
-- 2) br_invoices(invoice_date, agent_key): تسريع فلترة فواتير اليوم/الشهر وتحويل full scan لـ range scan
-- آمن للتكرار: فحص information_schema.TABLES وSTATISTICS والتسجيل INSERT IGNORE في schema_migrations.

SET @db_name := DATABASE();

-- 1) فهرس hq_branch_balances(sto_id, item_id)
SET @has_hqbb := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'hq_branch_balances'
);
SET @had_hqbb_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name
       AND TABLE_NAME = 'hq_branch_balances'
       AND INDEX_NAME = 'idx_hqbb_sto_item'
);
SET @sql_hqbb_idx := IF(@has_hqbb > 0 AND @had_hqbb_idx = 0,
    'ALTER TABLE `hq_branch_balances` ADD KEY `idx_hqbb_sto_item` (`sto_id`, `item_id`)',
    'DO 0'
);
PREPARE stmt_hqbb FROM @sql_hqbb_idx;
EXECUTE stmt_hqbb;
DEALLOCATE PREPARE stmt_hqbb;

-- 2) فهرس br_invoices(invoice_date, agent_key)
SET @has_bri := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'br_invoices'
);
SET @had_bri_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name
       AND TABLE_NAME = 'br_invoices'
       AND INDEX_NAME = 'idx_bri_date_agent'
);
SET @sql_bri_idx := IF(@has_bri > 0 AND @had_bri_idx = 0,
    'ALTER TABLE `br_invoices` ADD KEY `idx_bri_date_agent` (`invoice_date`, `agent_key`)',
    'DO 0'
);
PREPARE stmt_bri FROM @sql_bri_idx;
EXECUTE stmt_bri;
DEALLOCATE PREPARE stmt_bri;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261028_026_speed_indexes');
