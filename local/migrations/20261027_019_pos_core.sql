-- بريف ٢٣٨: أساس نقطة البيع الجديدة.
-- آمن للتكرار: الجداول بـIF NOT EXISTS، والأعمدة بحارس information_schema،
-- والإعدادات وتسجيل الـmigration بـINSERT IGNORE. الحارس شغال على MySQL وMariaDB.
-- جداول pos_* القديمة أرشيف مقفول ومش مستخدمة هنا.

CREATE TABLE IF NOT EXISTS sale_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    device_uuid VARCHAR(64) NOT NULL,
    code VARCHAR(12) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_devices_uuid (device_uuid),
    UNIQUE KEY uq_sale_devices_branch_code (branch_id, code),
    KEY idx_sale_devices_branch_active (branch_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    cashier_id INT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    shift_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sale_sessions_device_status (device_id, status),
    KEY idx_sale_sessions_branch_date (branch_id, business_date),
    KEY idx_sale_sessions_cashier_opened (cashier_id, opened_at),
    UNIQUE KEY uq_sale_sessions_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='وردية واحدة مفتوحة لكل جهاز؛ الحماية في الكود بقفل صف sale_devices باستخدام SELECT FOR UPDATE قبل الفتح';

CREATE TABLE IF NOT EXISTS sale_docs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_kind ENUM('sale','return') NOT NULL,
    status ENUM('held','completed','void') NOT NULL DEFAULT 'held',
    branch_id INT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    cashier_id INT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    client_uuid VARCHAR(64) NOT NULL,
    receipt_no VARCHAR(60) NULL,
    ref_doc_id BIGINT UNSIGNED NULL,
    gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    line_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    invoice_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    change_due DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    discount_approved_by INT UNSIGNED NULL,
    stock_doc_id BIGINT UNSIGNED NULL,
    completed_at DATETIME NULL,
    eta_uuid VARCHAR(64) NULL,
    eta_status VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_docs_device_client (device_id, client_uuid),
    UNIQUE KEY uq_sale_docs_receipt (receipt_no),
    UNIQUE KEY uq_sale_docs_stock_doc (stock_doc_id),
    KEY idx_sale_docs_branch_completed (branch_id, completed_at),
    KEY idx_sale_docs_session_status (session_id, status),
    KEY idx_sale_docs_ref (ref_doc_id),
    KEY idx_sale_docs_customer (customer_id),
    KEY idx_sale_docs_eta_uuid (eta_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_doc_seq (
    device_id BIGINT UNSIGNED NOT NULL,
    doc_year SMALLINT UNSIGNED NOT NULL,
    last_no INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id, doc_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    item_id INT NOT NULL,
    code VARCHAR(64) NOT NULL,
    unit_name VARCHAR(80) NOT NULL,
    unit_factor DECIMAL(14,3) NOT NULL DEFAULT 1.000,
    qty DECIMAL(14,3) NOT NULL,
    qty_pieces DECIMAL(14,3) NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    line_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net DECIMAL(14,2) NOT NULL,
    unit_cost DECIMAL(14,4) NULL,
    ref_line_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_lines_doc_line (doc_id, line_no),
    KEY idx_sale_lines_item (item_id),
    KEY idx_sale_lines_ref (ref_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    method ENUM('cash','visa','instapay') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    reference VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_payments_doc (doc_id),
    KEY idx_sale_payments_method (method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    doc_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    approved_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_events_branch_time (branch_id, created_at),
    KEY idx_sale_events_device (device_id, created_at),
    KEY idx_sale_events_user_type_time (user_id, event_type, created_at),
    KEY idx_sale_events_doc (doc_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @has_shift_source := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'source'
);
SET @sql := IF(@has_shift_source = 0,
    'ALTER TABLE `shifts` ADD COLUMN `source` VARCHAR(10) NOT NULL DEFAULT ''manual''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_shift_session := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'session_id'
);
SET @sql := IF(@has_shift_session = 0,
    'ALTER TABLE `shifts` ADD COLUMN `session_id` BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_discount_cap := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discount_cap_pct'
);
SET @sql := IF(@has_discount_cap = 0,
    'ALTER TABLE `users` ADD COLUMN `discount_cap_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('pos_return_days', '14'),
('pos_max_payments', '3'),
('pos_close_expenses_mode', 'fagala'),
('pos_eta_enabled', '0');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_019_pos_core');
