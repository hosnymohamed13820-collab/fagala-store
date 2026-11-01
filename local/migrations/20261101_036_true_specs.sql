-- Brief 838: optional True classifications. Safe to run again.
SET @spec_schema = DATABASE();
SET @spec_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@spec_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is2_id'),
    'DO 0', 'ALTER TABLE hq_item_cats ADD COLUMN is2_id INT NOT NULL DEFAULT 0'
);
PREPARE spec_stmt FROM @spec_sql; EXECUTE spec_stmt; DEALLOCATE PREPARE spec_stmt;
SET @spec_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@spec_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is3_id'),
    'DO 0', 'ALTER TABLE hq_item_cats ADD COLUMN is3_id INT NOT NULL DEFAULT 0'
);
PREPARE spec_stmt FROM @spec_sql; EXECUTE spec_stmt; DEALLOCATE PREPARE spec_stmt;
SET @spec_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@spec_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is4_id'),
    'DO 0', 'ALTER TABLE hq_item_cats ADD COLUMN is4_id INT NOT NULL DEFAULT 0'
);
PREPARE spec_stmt FROM @spec_sql; EXECUTE spec_stmt; DEALLOCATE PREPARE spec_stmt;
SET @spec_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@spec_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is5_id'),
    'DO 0', 'ALTER TABLE hq_item_cats ADD COLUMN is5_id INT NOT NULL DEFAULT 0'
);
PREPARE spec_stmt FROM @spec_sql; EXECUTE spec_stmt; DEALLOCATE PREPARE spec_stmt;
CREATE TABLE IF NOT EXISTS hq_spec_names (
    spec TINYINT NOT NULL,
    spec_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (spec, spec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_036_true_specs');
