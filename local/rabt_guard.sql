CREATE TABLE rabt_incidents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_key VARCHAR(32) NOT NULL COMMENT 'الوكيل اللي لقاها',
  check_code VARCHAR(8) NOT NULL COMMENT 'رقم الفحص من الكتالوج: H01..H17',
  fingerprint CHAR(40) NOT NULL COMMENT 'sha1 من الوكيل — نفس المشكلة = نفس البصمة',
  branch_key VARCHAR(32) NULL COMMENT 'الفرع المتأثر لو فيه',
  title VARCHAR(200) NOT NULL,
  details MEDIUMTEXT NULL COMMENT 'JSON من الوكيل بالمستندات والأرقام',
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  seen_count INT NOT NULL DEFAULT 1,
  status ENUM('new','claimed','analyzed','closed') NOT NULL DEFAULT 'new',
  claimed_at DATETIME NULL,
  analysis MEDIUMTEXT NULL COMMENT 'تحليل كلود (نص)',
  analysis_at DATETIME NULL,
  closed_at DATETIME NULL,
  close_reason VARCHAR(64) NULL,
  KEY idx_open (status, last_seen), KEY idx_fp (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_commands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_key VARCHAR(32) NOT NULL,
  cmd VARCHAR(32) NOT NULL COMMENT 'restart_repl بس دلوقتي',
  reason VARCHAR(255) NULL,
  incident_id INT UNSIGNED NULL,
  status ENUM('pending','picked','done','failed','expired') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  picked_at DATETIME NULL,
  done_at DATETIME NULL,
  result VARCHAR(255) NULL,
  KEY idx_agent (agent_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
