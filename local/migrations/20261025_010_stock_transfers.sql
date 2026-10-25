-- بريف ٢١٥: التحويلات بين المخازن — جداول العد والفروقات
-- آمن للتكرار: CREATE TABLE IF NOT EXISTS و INSERT IGNORE.

CREATE TABLE IF NOT EXISTS stock_transfer_rx (
    doc_line_id BIGINT UNSIGNED NOT NULL,
    received_pieces DECIMAL(14,3) NOT NULL,
    counted_by INT UNSIGNED NOT NULL,
    counted_at DATETIME NOT NULL,
    PRIMARY KEY (doc_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_diffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    doc_line_id BIGINT UNSIGNED NOT NULL,
    item_id INT NOT NULL,
    kind ENUM('short','excess') NOT NULL,
    qty DECIMAL(14,3) NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    source_branch_id INT UNSIGNED NULL,
    resolve_doc_id BIGINT UNSIGNED NULL,
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    note VARCHAR(255) NULL,
    KEY idx_stock_transfer_diffs_status_doc (status, doc_id),
    KEY idx_stock_transfer_diffs_doc (doc_id),
    KEY idx_stock_transfer_diffs_item (item_id),
    KEY idx_stock_transfer_diffs_line (doc_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_010_stock_transfers');
