-- تقارير FIFO محسوبة بس، وتشغيل الملف تاني مابيغيرش الداتا الموجودة.
CREATE TABLE IF NOT EXISTS fifo_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME NULL,
 status ENUM('running','done','failed') NOT NULL DEFAULT 'running',
 is_current TINYINT NOT NULL DEFAULT 0,
 lots_count BIGINT NOT NULL DEFAULT 0,
 alloc_count BIGINT NOT NULL DEFAULT 0,
 note TEXT NULL,
 KEY current_run (is_current, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fifo_lots (
 run_id BIGINT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NOT NULL,
 item_id INT NOT NULL,
 kind ENUM('purchase','no_purchase') NOT NULL,
 supplier_id INT NULL,
 supplier_name VARCHAR(255) NOT NULL DEFAULT '',
 sth_id BIGINT NOT NULL,
 suffix VARCHAR(8) NOT NULL,
 line_no INT NOT NULL,
 doc_no VARCHAR(40) NULL,
 lot_date DATETIME NOT NULL,
 qty_in DECIMAL(20,3) NOT NULL,
 qty_returned DECIMAL(20,3) NOT NULL DEFAULT 0,
 unit_cost DECIMAL(24,10) NULL,
 PRIMARY KEY (run_id, lot_id),
 KEY supplier_date (run_id, supplier_id, lot_date),
 KEY item (run_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fifo_alloc (
 run_id BIGINT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NOT NULL,
 item_id INT NOT NULL,
 agent_key VARCHAR(32) NOT NULL,
 sale_sth_id BIGINT NOT NULL,
 sale_suffix VARCHAR(8) NOT NULL,
 sale_line_no INT NOT NULL,
 sale_date DATETIME NOT NULL,
 qty DECIMAL(20,3) NOT NULL,
 revenue DECIMAL(24,8) NOT NULL,
 cost DECIMAL(24,8) NULL,
 KEY lot (run_id, lot_id),
 KEY agent_date (run_id, agent_key, sale_date),
 KEY item (run_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_034_fifo_cost');
