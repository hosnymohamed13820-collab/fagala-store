-- إنشاء جدول مكالمات الفروع وإعداد مهلة المتابعة بالدقائق (بريف ٢٠٩)
-- قابل لإعادة التشغيل ولا يمس أي بيانات موجودة.

CREATE TABLE IF NOT EXISTS branch_calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    android_call_id BIGINT NOT NULL,
    number VARCHAR(32) NULL,
    number_norm VARCHAR(20) NULL,
    call_type TINYINT UNSIGNED NOT NULL,
    call_at DATETIME NOT NULL,
    call_at_ms BIGINT NOT NULL,
    duration_sec INT UNSIGNED NOT NULL DEFAULT 0,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bc_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    UNIQUE KEY uq_bc_call (branch_id, android_call_id),
    KEY idx_bc_branch_at (branch_id, call_at),
    KEY idx_bc_num_at (number_norm, call_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value, description)
VALUES ('calls_followup_minutes', '60', 'مهلة متابعة المكالمات الفائتة بالدقائق');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_006_branch_calls');

