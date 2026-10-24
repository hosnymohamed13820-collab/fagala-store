-- عدادات مكن التصوير اليومية. كل أوامر الإنشاء والإضافة آمنة عند إعادة تشغيل الملف.
CREATE TABLE IF NOT EXISTS machines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    kind ENUM('bw','color') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_machines_branch (branch_id, is_active, sort_order),
    CONSTRAINT fk_machines_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS machine_readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id INT UNSIGNED NOT NULL,
    reading_date DATE NOT NULL,
    bw_total BIGINT NULL,
    black_small BIGINT NULL,
    black_large BIGINT NULL,
    color_small BIGINT NULL,
    color_large BIGINT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_machine_date (machine_id, reading_date),
    KEY idx_reading_date (reading_date),
    CONSTRAINT fk_machine_readings_machine FOREIGN KEY (machine_id) REFERENCES machines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS machine_service_codes (
    item_code VARCHAR(64) NOT NULL PRIMARY KEY,
    color TINYINT(1) NOT NULL,
    clicks_per_qty DECIMAL(6,3) NOT NULL,
    note VARCHAR(180) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لو جداول تجربة النسخة القديمة لسه موجودة: نحتفظ بالصفوف القديمة ونضيف
-- الخانات الجديدة من غير تخمين هل «copy/print» كان أبيض وأسود ولا ألوان.
-- القيم القديمة تفضل Legacy وخارج المطابقة لحد ما نوع المكنة يتحدد.
SET @mc_legacy_kind = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machines'
      AND COLUMN_NAME = 'kind' AND COLUMN_TYPE LIKE '%copy%' AND COLUMN_TYPE NOT LIKE '%bw%'
);
SET @mc_ddl = IF(@mc_legacy_kind > 0,
    'ALTER TABLE machines MODIFY kind ENUM(''copy'',''print'',''bw'',''color'') NOT NULL',
    'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

SET @mc_missing = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machine_readings' AND COLUMN_NAME = 'bw_total'
);
SET @mc_ddl = IF(@mc_missing = 0, 'ALTER TABLE machine_readings ADD COLUMN bw_total BIGINT NULL', 'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

SET @mc_missing = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machine_readings' AND COLUMN_NAME = 'black_small'
);
SET @mc_ddl = IF(@mc_missing = 0, 'ALTER TABLE machine_readings ADD COLUMN black_small BIGINT NULL', 'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

SET @mc_missing = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machine_readings' AND COLUMN_NAME = 'black_large'
);
SET @mc_ddl = IF(@mc_missing = 0, 'ALTER TABLE machine_readings ADD COLUMN black_large BIGINT NULL', 'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

SET @mc_missing = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machine_readings' AND COLUMN_NAME = 'color_small'
);
SET @mc_ddl = IF(@mc_missing = 0, 'ALTER TABLE machine_readings ADD COLUMN color_small BIGINT NULL', 'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

SET @mc_missing = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machine_readings' AND COLUMN_NAME = 'color_large'
);
SET @mc_ddl = IF(@mc_missing = 0, 'ALTER TABLE machine_readings ADD COLUMN color_large BIGINT NULL', 'DO 0');
PREPARE mc_stmt FROM @mc_ddl;
EXECUTE mc_stmt;
DEALLOCATE PREPARE mc_stmt;

INSERT IGNORE INTO machine_service_codes (item_code, color, clicks_per_qty, note) VALUES
('004',0,1,'طباعة وجه واحد A4'),
('0044',0,1,'وجه واحد للشركات'),
('002',0,1,'تصوير وش وضهر A4 — الكمية متسجّلة مضروبة في ٢'),
('0046',0,1,'وش وضهر للشركات — الكمية متسجّلة مضروبة في ٢'),
('0045',0,2,'تصوير بطاقة/كارنيه'),
('001',0,2,'اسود (وش) A3'),
('0048',0,4,'اسود (وش وضهر) A3'),
('*001001',0,4,'اسود A3 وش وضهر (كود قديم)'),
('0018',1,1,'ألوان A4 صفحة كاملة'),
('0020',1,1,'ألوان A4 خفيف'),
('0022',1,1,'ألوان A4 وسط'),
('0052',1,1,'ألوان خاص بفرع ABC'),
('0053',1,1,'ألوان خاص بفرع ABC'),
('0054',1,1,'ألوان خاص بفرع ABC'),
('*0010',1,2,'ألوان خفيف وش وضهر A4'),
('0024',1,2,'ألوان A3'),
('0025',1,2,'ألوان A3'),
('0026',1,2,'ألوان A3'),
('*0025',1,2,'ألوان A3');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261024_001_machine_counters');
