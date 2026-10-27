-- بريف ٢٤٠: توكنات اعتماد المشرف لنقطة البيع.
-- آمن للتكرار: الجدول بـIF NOT EXISTS والتسجيل بـINSERT IGNORE.
-- التوكن الصريح بيرجع للعميل مرة واحدة؛ القاعدة تحتفظ ببصمة SHA-256 بس.

CREATE TABLE IF NOT EXISTS sale_approval_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    approval_type VARCHAR(30) NOT NULL,
    approved_value DECIMAL(8,3) NOT NULL,
    approved_by INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    used_doc_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_approval_token_hash (token_hash),
    KEY idx_sale_approval_device_type (device_id, approval_type, expires_at),
    KEY idx_sale_approval_supervisor (approved_by, created_at),
    KEY idx_sale_approval_used_doc (used_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_021_pos_api');
