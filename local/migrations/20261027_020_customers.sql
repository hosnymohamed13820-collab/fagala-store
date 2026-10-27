-- بريف ٢٣٩: موديول العملاء (بيانات + كشف حساب + رقم العميل مربوط بكل حاجة).
-- آمن للتكرار: الجداول بـIF NOT EXISTS وتسجيل الـmigration بـINSERT IGNORE.
-- من غير اسم قاعدة البيانات.

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL DEFAULT NULL,
    phone2 VARCHAR(30) NULL DEFAULT NULL,
    kind ENUM('regular', 'walkin') NOT NULL DEFAULT 'walkin',
    branch_id INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_code (code),
    UNIQUE KEY uq_customers_phone (phone),
    KEY idx_customers_branch_active (branch_id, is_active),
    KEY idx_customers_name (name),
    KEY idx_customers_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_020_customers');
