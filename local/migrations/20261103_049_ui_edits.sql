-- طلبات الشكل بتتسجّل هنا بس، ومابتغيّرش أي صفحة للموظفين.
CREATE TABLE IF NOT EXISTS ui_edits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page VARCHAR(1500) NOT NULL,
    title VARCHAR(180) NOT NULL,
    status ENUM('open','briefed','done') NOT NULL DEFAULT 'open',
    edits JSON NOT NULL,
    viewport_w INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ui_edits_status_id (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261103_049_ui_edits');
