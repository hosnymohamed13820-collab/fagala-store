-- طلبات استرجاع الباسورد وسجل حدود الطلبات، آمن يتشغّل تاني.
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','used','expired') NOT NULL DEFAULT 'pending',
    request_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    code_hash VARCHAR(255) NULL,
    code_expires_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    KEY idx_user_status_created (user_id, status, created_at),
    KEY idx_ip_created (request_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بنعدّ المحاولات حتى لو الاسم غلط أو الطلب مكرر، من غير حسابات وهمية في سجل الموافقات.
CREATE TABLE IF NOT EXISTS password_reset_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username_key CHAR(64) NOT NULL,
    request_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_name_created (username_key, created_at),
    KEY idx_ip_created (request_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_033_password_reset_requests');
