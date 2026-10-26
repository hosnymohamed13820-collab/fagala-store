-- بريف ٢٣٣: دفعات تغيير أسعار الموقع الجماعية ولقطة كل سطر.
-- آمن للتكرار: الجداول CREATE TABLE IF NOT EXISTS والتسجيل INSERT IGNORE.

CREATE TABLE IF NOT EXISTS item_price_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    preview_token_hash CHAR(64) NOT NULL,
    filters_json JSON NOT NULL,
    operation_type ENUM('percent','amount') NOT NULL,
    direction ENUM('increase','decrease') NOT NULL,
    operation_value DECIMAL(14,4) NOT NULL,
    rounding_rule ENUM('none','0.25','0.5','1') NOT NULL DEFAULT 'none',
    line_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('applying','applied') NOT NULL DEFAULT 'applying',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_by INT UNSIGNED NULL,
    applied_at DATETIME NULL,
    UNIQUE KEY uq_item_price_batches_token (preview_token_hash),
    KEY idx_item_price_batches_created (created_at),
    KEY idx_item_price_batches_user (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_price_batch_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    old_price DECIMAL(14,2) NOT NULL,
    proposed_price DECIMAL(14,2) NOT NULL,
    true_price_at_preview DECIMAL(14,2) NULL,
    result ENUM('applied','skipped') NOT NULL,
    skip_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_item_price_batch_lines_code (batch_id, code),
    KEY idx_item_price_batch_lines_result (batch_id, result),
    KEY idx_item_price_batch_lines_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ipp_schema = DATABASE();
SET @ipp_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ipp_schema AND TABLE_NAME='stock_doc_lines' AND INDEX_NAME='idx_stock_doc_lines_code'),
    'DO 0',
    'ALTER TABLE stock_doc_lines ADD KEY idx_stock_doc_lines_code (code)'
);
PREPARE ipp_stmt FROM @ipp_sql; EXECUTE ipp_stmt; DEALLOCATE PREPARE ipp_stmt;

SET @ipp_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ipp_schema AND TABLE_NAME='stock_docs' AND INDEX_NAME='idx_stock_docs_type_status'),
    'DO 0',
    'ALTER TABLE stock_docs ADD KEY idx_stock_docs_type_status (doc_type,status,doc_date)'
);
PREPARE ipp_stmt FROM @ipp_sql; EXECUTE ipp_stmt; DEALLOCATE PREPARE ipp_stmt;

-- فصل شاشة أسعار البيع مع الحفاظ على وصول اللي كان معاه تغيير سعر الكارت.
-- بنضيف الجزئين صراحةً، ومابنديش موديول sale_prices كامل لأي حد.
DROP TEMPORARY TABLE IF EXISTS ipp_sale_price_users;
CREATE TEMPORARY TABLE ipp_sale_price_users (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=Memory;

INSERT IGNORE INTO ipp_sale_price_users (user_id)
SELECT DISTINCT u.id
  FROM users u
 WHERE (
        EXISTS(SELECT 1 FROM user_permissions up
                WHERE up.user_id=u.id AND up.module_key IN ('item_master','item_master:prices'))
        OR EXISTS(SELECT 1 FROM role_permissions rp
                   WHERE rp.role_id=u.role_id AND rp.module_key IN ('item_master','item_master:prices'))
       )
   AND NOT (
        (EXISTS(SELECT 1 FROM user_permissions up
                 WHERE up.user_id=u.id AND up.module_key IN ('sale_prices','sale_prices:view'))
         OR EXISTS(SELECT 1 FROM role_permissions rp
                    WHERE rp.role_id=u.role_id AND rp.module_key IN ('sale_prices','sale_prices:view')))
        AND
        (EXISTS(SELECT 1 FROM user_permissions up
                 WHERE up.user_id=u.id AND up.module_key IN ('sale_prices','sale_prices:apply'))
         OR EXISTS(SELECT 1 FROM role_permissions rp
                    WHERE rp.role_id=u.role_id AND rp.module_key IN ('sale_prices','sale_prices:apply')))
       );

INSERT IGNORE INTO user_permissions (user_id,module_key)
SELECT user_id,'sale_prices:view' FROM user_permissions
 WHERE module_key IN ('item_master','item_master:prices');
INSERT IGNORE INTO user_permissions (user_id,module_key)
SELECT user_id,'sale_prices:apply' FROM user_permissions
 WHERE module_key IN ('item_master','item_master:prices');

INSERT IGNORE INTO role_permissions (role_id,module_key)
SELECT role_id,'sale_prices:view' FROM role_permissions
 WHERE module_key IN ('item_master','item_master:prices');
INSERT IGNORE INTO role_permissions (role_id,module_key)
SELECT role_id,'sale_prices:apply' FROM role_permissions
 WHERE module_key IN ('item_master','item_master:prices');

UPDATE users u JOIN ipp_sale_price_users x ON x.user_id=u.id
   SET u.perms_version=u.perms_version+1;
DROP TEMPORARY TABLE ipp_sale_price_users;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261026_017_item_price_batches');
