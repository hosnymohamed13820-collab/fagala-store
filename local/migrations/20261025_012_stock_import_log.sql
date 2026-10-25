-- بريف ٢١٨: استيراد حركات True للدفتر + سجل الاستيراد + المطابقة مع المرآة
-- آمن للتكرار: CREATE TABLE IF NOT EXISTS و INSERT IGNORE

CREATE TABLE IF NOT EXISTS stock_import_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(10) NOT NULL COMMENT 'hq أو br',
    src_key VARCHAR(80) NOT NULL COMMENT 'hq:<sth_id>:<suffix> أو br:<agent_key>:<sth_id>:<suffix>',
    src_hash CHAR(40) NOT NULL COMMENT 'sha1 للرأس والسطور',
    doc_id BIGINT UNSIGNED NOT NULL COMMENT 'معرف المستند في stock_docs',
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_src_key (source, src_key),
    KEY idx_stock_import_log_doc (doc_id),
    KEY idx_stock_import_log_imported (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_import_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_sec DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    details TEXT NULL,
    created_by INT UNSIGNED NULL,
    KEY idx_stock_import_runs_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('stock_import_last', '');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_012_stock_import_log');
