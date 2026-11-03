-- 20261102_045_transfer_diffs_approval.sql
-- فروق التحويلات: دورة اعتماد مزدوجة ومقاصة ذكية بين الفروع.
-- آمن للتكرار: فحص information_schema للجداول والأعمدة والفهارس قبل كل تعديل.
SET @sc_schema = DATABASE();

-- 1. توسيع status ليشمل pending, approved, rejected
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(
        SELECT 1 FROM information_schema.COLUMNS 
         WHERE TABLE_SCHEMA=@sc_schema 
           AND TABLE_NAME='stock_transfer_diffs' 
           AND COLUMN_NAME='status' 
           AND COLUMN_TYPE LIKE '%pending%'
    ),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs MODIFY COLUMN status ENUM(\'open\',\'pending\',\'approved\',\'rejected\',\'resolved\') NOT NULL DEFAULT \'pending\''
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 2. resolution ENUM('swap','to_sender','from_sender') NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='resolution'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN resolution ENUM(\'swap\',\'to_sender\',\'from_sender\') NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 3. pair_diff_id BIGINT UNSIGNED NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='pair_diff_id'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN pair_diff_id BIGINT UNSIGNED NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 4. counter_branch_id INT UNSIGNED NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='counter_branch_id'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN counter_branch_id INT UNSIGNED NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 5. recv_approved_by INT UNSIGNED NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='recv_approved_by'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN recv_approved_by INT UNSIGNED NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 6. recv_approved_at DATETIME NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='recv_approved_at'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN recv_approved_at DATETIME NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 7. owner_approved_by INT UNSIGNED NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='owner_approved_by'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN owner_approved_by INT UNSIGNED NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 8. owner_approved_at DATETIME NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='owner_approved_at'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN owner_approved_at DATETIME NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 9. rejected_by INT UNSIGNED NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='rejected_by'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN rejected_by INT UNSIGNED NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 10. rejected_at DATETIME NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='rejected_at'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN rejected_at DATETIME NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 11. reject_reason VARCHAR(255) NULL
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND COLUMN_NAME='reject_reason'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD COLUMN reject_reason VARCHAR(255) NULL'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

-- 12. فهرس للفرق المقابل
SET @sc_sql = IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs')
    OR EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@sc_schema AND TABLE_NAME='stock_transfer_diffs' AND INDEX_NAME='idx_stock_transfer_diffs_pair'),
    'DO 0',
    'ALTER TABLE stock_transfer_diffs ADD KEY idx_stock_transfer_diffs_pair (pair_diff_id)'
);
PREPARE sc_stmt FROM @sc_sql; EXECUTE sc_stmt; DEALLOCATE PREPARE sc_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_045_transfer_diffs_approval');
