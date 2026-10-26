-- بريف ٢٣١: كتالوج أصناف الموقع وأسعار الأكواد وسجلها.
-- آمن للتكرار: كل الجداول CREATE TABLE IF NOT EXISTS والتسجيل INSERT IGNORE.

CREATE TABLE IF NOT EXISTS item_master (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name1 VARCHAR(255) NOT NULL,
    sale_price_class VARCHAR(20) NOT NULL DEFAULT 'code',
    item_type VARCHAR(30) NOT NULL DEFAULT 'stock',
    is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    base_unit VARCHAR(80) NOT NULL DEFAULT 'قطعة',
    open_price TINYINT(1) NOT NULL DEFAULT 0,
    cat_is1_id INT NULL,
    cat_description_id INT NULL,
    cat_type_id INT NULL,
    cat_stage_id INT NULL,
    cat_company_id INT NULL,
    min_qty DECIMAL(14,3) NULL,
    max_qty DECIMAL(14,3) NULL,
    max_discount_pct DECIMAL(6,3) NULL,
    true_item_id INT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_master_true (true_item_id),
    KEY idx_item_master_name (name1),
    KEY idx_item_master_cat1 (cat_is1_id),
    KEY idx_item_master_cancelled (is_cancelled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_master_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_master_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name1 VARCHAR(255) NOT NULL,
    name2 VARCHAR(255) NULL,
    code_type TINYINT NOT NULL DEFAULT 1,
    unit_name VARCHAR(80) NOT NULL DEFAULT 'قطعة',
    unit_type VARCHAR(30) NOT NULL DEFAULT 'base',
    unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    whole_qty TINYINT(1) NOT NULL DEFAULT 1,
    open_price TINYINT(1) NOT NULL DEFAULT 0,
    prep_days INT UNSIGNED NOT NULL DEFAULT 0,
    display_screen VARCHAR(80) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_purchase_code TINYINT(1) NOT NULL DEFAULT 1,
    is_sale_code TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_item_master_codes_code (code),
    KEY idx_item_master_codes_item (item_master_id),
    KEY idx_item_master_codes_name1 (name1),
    KEY idx_item_master_codes_type (item_master_id, code_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_prices (
    code VARCHAR(64) NOT NULL PRIMARY KEY,
    price DECIMAL(14,2) NOT NULL,
    true_price_at_change DECIMAL(14,2) NULL,
    updated_by INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_item_prices_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_price_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    old_price DECIMAL(14,2) NULL,
    new_price DECIMAL(14,2) NOT NULL,
    true_price DECIMAL(14,2) NULL,
    reason VARCHAR(500) NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    batch_id BIGINT UNSIGNED NULL,
    KEY idx_item_price_history_code_date (code, created_at),
    KEY idx_item_price_history_batch (batch_id),
    KEY idx_item_price_history_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261026_016_item_master');
