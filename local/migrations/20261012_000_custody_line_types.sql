ALTER TABLE custody_lines
  ADD COLUMN line_type ENUM('expense','advance','supplier') NOT NULL DEFAULT 'expense' AFTER custody_id,
  ADD COLUMN employee_id INT UNSIGNED NULL AFTER entry_code_id,
  ADD COLUMN supplier_id INT UNSIGNED NULL AFTER employee_id;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261012_000_custody_line_types');

