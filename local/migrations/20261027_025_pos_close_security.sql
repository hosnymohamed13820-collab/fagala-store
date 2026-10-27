-- بريف ٢٤٣: ربط اعتماد المرتجع بالفاتورة الأصلية، ومنع تقفيل جلسة POS مرتين بنفس طلب العميل.
-- آمن للتكرار: كل عمود وفهرس بيتفحصوا في information_schema، والتسجيل INSERT IGNORE.

SET @db_name := DATABASE();

SET @had_ref_doc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'sale_approval_tokens' AND COLUMN_NAME = 'ref_doc_id'
);
SET @sql_ref_doc := IF(@had_ref_doc = 0,
    'ALTER TABLE `sale_approval_tokens` ADD COLUMN `ref_doc_id` BIGINT UNSIGNED NULL AFTER `approved_value`',
    'DO 0'
);
PREPARE stmt_ref_doc FROM @sql_ref_doc;
EXECUTE stmt_ref_doc;
DEALLOCATE PREPARE stmt_ref_doc;

SET @had_ref_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'sale_approval_tokens' AND INDEX_NAME = 'idx_sale_approval_ref_doc'
);
SET @sql_ref_idx := IF(@had_ref_idx = 0,
    'ALTER TABLE `sale_approval_tokens` ADD KEY `idx_sale_approval_ref_doc` (`ref_doc_id`)',
    'DO 0'
);
PREPARE stmt_ref_idx FROM @sql_ref_idx;
EXECUTE stmt_ref_idx;
DEALLOCATE PREPARE stmt_ref_idx;

SET @had_close_uuid := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'sale_sessions' AND COLUMN_NAME = 'close_client_uuid'
);
SET @sql_close_uuid := IF(@had_close_uuid = 0,
    'ALTER TABLE `sale_sessions` ADD COLUMN `close_client_uuid` VARCHAR(64) NULL AFTER `shift_id`',
    'DO 0'
);
PREPARE stmt_close_uuid FROM @sql_close_uuid;
EXECUTE stmt_close_uuid;
DEALLOCATE PREPARE stmt_close_uuid;

SET @had_close_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'sale_sessions' AND INDEX_NAME = 'uq_sale_session_close_uuid'
);
SET @sql_close_idx := IF(@had_close_idx = 0,
    'ALTER TABLE `sale_sessions` ADD UNIQUE KEY `uq_sale_session_close_uuid` (`close_client_uuid`)',
    'DO 0'
);
PREPARE stmt_close_idx FROM @sql_close_idx;
EXECUTE stmt_close_idx;
DEALLOCATE PREPARE stmt_close_idx;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_025_pos_close_security');
