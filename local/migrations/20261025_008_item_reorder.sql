-- بريف ٢١٣: حد إعادة الطلب لكل صنف/فرع + اقتراحات طلب النواقص
-- آمن للتكرار: CREATE TABLE IF NOT EXISTS و INSERT IGNORE.

CREATE TABLE IF NOT EXISTS item_reorder (
    item_id INT NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    avg_daily DECIMAL(12,4) NOT NULL DEFAULT 0,
    sale_days INT NOT NULL DEFAULT 0,
    min_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    max_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    calc_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, branch_id),
    KEY idx_item_reorder_branch_min (branch_id, min_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('reorder_window_days', '60'),
('reorder_lead_days', '7'),
('reorder_cover_days', '21'),
('reorder_min_sale_days', '4');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_008_item_reorder');
