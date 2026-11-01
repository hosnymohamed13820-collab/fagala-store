-- تقسيم الأصناف عندنا بس؛ إعادة التشغيل مش بتغيّر أي تقسيم موجود.
CREATE TABLE IF NOT EXISTS `item_class_assign` (
  item_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  class_id INT NOT NULL,
  status ENUM('pending','approved') NOT NULL,
  assigned_by INT NOT NULL,
  assigned_at DATETIME NOT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (item_id,kind),
  KEY idx_ica_status (status),
  KEY idx_ica_user_date (assigned_by,assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `item_class_assign_log` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  old_class_id INT NULL,
  new_class_id INT NULL,
  action ENUM('assign','approve','clear') NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ical_item (item_id,kind,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `schema_migrations` (name) VALUES ('20261101_038_item_class_assign');
