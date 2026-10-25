-- بريف ٢١٤: أساس المخزن على الموقع — المستندات · دفتر الحركة · الأرصدة · الترقيم · السجل
-- آمن للتكرار: CREATE TABLE IF NOT EXISTS و INSERT IGNORE.

CREATE TABLE IF NOT EXISTS stock_docs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_type VARCHAR(20) NOT NULL,
    doc_no INT UNSIGNED NULL,
    doc_year SMALLINT UNSIGNED NULL,
    branch_id INT UNSIGNED NOT NULL,
    counter_branch_id INT UNSIGNED NULL,
    supplier_id BIGINT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    doc_date DATE NOT NULL,
    ref_text VARCHAR(100) NULL,
    notes VARCHAR(1000) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_by INT UNSIGNED NULL,
    posted_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(500) NULL,
    UNIQUE KEY uq_doc_no (doc_type, branch_id, doc_year, doc_no),
    KEY idx_stock_docs_branch_type_date (branch_id, doc_type, doc_date),
    KEY idx_stock_docs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_doc_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    line_no INT NOT NULL,
    item_id INT NOT NULL,
    code VARCHAR(64) NOT NULL,
    unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    qty_units DECIMAL(14,3) NOT NULL,
    qty_pieces DECIMAL(14,3) NOT NULL,
    unit_cost DECIMAL(14,4) NULL,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_stock_doc_lines_doc_line (doc_id, line_no),
    KEY idx_stock_doc_lines_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_moves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    doc_line_id BIGINT UNSIGNED NULL,
    item_id INT NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    is_transit TINYINT(1) NOT NULL DEFAULT 0,
    qty DECIMAL(14,3) NOT NULL,
    unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    qty_after DECIMAL(14,3) NOT NULL,
    avg_cost_after DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    move_at DATETIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    reverses_move_id BIGINT UNSIGNED NULL,
    KEY idx_stock_moves_item_branch_transit (item_id, branch_id, is_transit, id),
    KEY idx_stock_moves_doc (doc_id),
    KEY idx_stock_moves_branch_move_at (branch_id, move_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_balances (
    item_id INT NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    is_transit TINYINT(1) NOT NULL DEFAULT 0,
    qty DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    avg_cost DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    last_move_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, branch_id, is_transit),
    KEY idx_stock_balances_branch_qty (branch_id, qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_seq (
    doc_type VARCHAR(20) NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    doc_year SMALLINT UNSIGNED NOT NULL,
    last_no INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (doc_type, branch_id, doc_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_kind VARCHAR(20) NOT NULL DEFAULT 'stock',
    doc_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(30) NOT NULL,
    user_id INT UNSIGNED NULL,
    at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details TEXT NULL,
    KEY idx_doc_events_kind_doc (doc_kind, doc_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('erp_stock_enabled', '0'),
('stock_allow_negative', '1'),
('stock_mode_1', 'mirror'),
('stock_mode_2', 'mirror'),
('stock_mode_3', 'mirror'),
('stock_mode_4', 'mirror'),
('stock_mode_6', 'mirror');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_009_stock_ledger');
