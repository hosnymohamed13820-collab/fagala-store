-- توافق إشراقة مع الموديولات المحاسبية والجرد في site/. DDL بس، من غير seed أو تعديل داتا.
-- كل تغيير محروس بالهيكل الحالي؛ التشغيل التاني no-op. الفجالة عندها الهيكل بالفعل.
SET @ac_schema = DATABASE();
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='schema_migrations'), 'DO 0', 'CREATE TABLE `schema_migrations` (
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='api_idempotency'), 'DO 0', 'CREATE TABLE `api_idempotency` (
  `key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL,
  `status` smallint DEFAULT NULL,
  `response` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`key_hash`),
  KEY `idx_idem_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='api_tokens'), 'DO 0', 'CREATE TABLE `api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pw_version` int NOT NULL DEFAULT ''1'',
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_token_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='audit_requests'), 'DO 0', 'CREATE TABLE `audit_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `params` text COLLATE utf8mb4_unicode_ci,
  `status` smallint DEFAULT NULL,
  `ms` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_time` (`created_at`),
  KEY `idx_audit_user` (`user_id`,`created_at`),
  KEY `idx_audit_page` (`page`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='role_permissions'), 'DO 0', 'CREATE TABLE `role_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned NOT NULL,
  `module_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_mod` (`role_id`,`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='journal_entry_marks'), 'DO 0', 'CREATE TABLE `journal_entry_marks` (
  `entry_id` bigint unsigned NOT NULL,
  `color` enum(''green'',''yellow'',''red'',''blue'') COLLATE utf8mb4_unicode_ci NOT NULL,
  `marked_by` int unsigned NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`entry_id`),
  KEY `idx_jem_color` (`color`),
  KEY `idx_jem_marked_by` (`marked_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='password_reset_requests'), 'DO 0', 'CREATE TABLE `password_reset_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `status` enum(''pending'',''approved'',''rejected'',''used'',''expired'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''pending'',
  `request_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by` int unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_expires_at` datetime DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT ''0'',
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status_created` (`user_id`,`status`,`created_at`),
  KEY `idx_ip_created` (`request_ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='password_reset_limits'), 'DO 0', 'CREATE TABLE `password_reset_limits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name_created` (`username_key`,`created_at`),
  KEY `idx_ip_created` (`request_ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='sms_marks'), 'DO 0', 'CREATE TABLE `sms_marks` (
  `sms_id` bigint unsigned NOT NULL,
  `note` text,
  `color` varchar(20) DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sms_id`),
  CONSTRAINT `fk_sms_marks_sms` FOREIGN KEY (`sms_id`) REFERENCES `sms_inbox` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='custody_lines' AND COLUMN_NAME='line_type'), 'DO 0', 'ALTER TABLE `custody_lines` ADD COLUMN `line_type` enum(''expense'',''advance'',''supplier'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''expense''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='custody_lines' AND COLUMN_NAME='employee_id'), 'DO 0', 'ALTER TABLE `custody_lines` ADD COLUMN `employee_id` int unsigned DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='custody_lines' AND COLUMN_NAME='supplier_id'), 'DO 0', 'ALTER TABLE `custody_lines` ADD COLUMN `supplier_id` int unsigned DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='custody_lines' AND COLUMN_NAME='branch_id'), 'DO 0', 'ALTER TABLE `custody_lines` ADD COLUMN `branch_id` int unsigned DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancelled_at'), 'DO 0', 'ALTER TABLE `employee_penalties` ADD COLUMN `cancelled_at` datetime DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancelled_by'), 'DO 0', 'ALTER TABLE `employee_penalties` ADD COLUMN `cancelled_by` int DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='employee_penalties' AND COLUMN_NAME='cancel_reason'), 'DO 0', 'ALTER TABLE `employee_penalties` ADD COLUMN `cancel_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='hq_invoice_lines' AND COLUMN_NAME='unit_factor'), 'DO 0', 'ALTER TABLE `hq_invoice_lines` ADD COLUMN `unit_factor` decimal(12,4) DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is2_id'), 'DO 0', 'ALTER TABLE `hq_item_cats` ADD COLUMN `is2_id` int NOT NULL DEFAULT ''0''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is3_id'), 'DO 0', 'ALTER TABLE `hq_item_cats` ADD COLUMN `is3_id` int NOT NULL DEFAULT ''0''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is4_id'), 'DO 0', 'ALTER TABLE `hq_item_cats` ADD COLUMN `is4_id` int NOT NULL DEFAULT ''0''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='hq_item_cats' AND COLUMN_NAME='is5_id'), 'DO 0', 'ALTER TABLE `hq_item_cats` ADD COLUMN `is5_id` int NOT NULL DEFAULT ''0''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='sms_inbox' AND COLUMN_NAME='ref_no'), 'DO 0', 'ALTER TABLE `sms_inbox` ADD COLUMN `ref_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='sms_inbox' AND COLUMN_NAME='dup_of'), 'DO 0', 'ALTER TABLE `sms_inbox` ADD COLUMN `dup_of` bigint unsigned DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='payment_type'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `payment_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='erp_supplier_code'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `erp_supplier_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='phone2'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `phone2` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='tax_no'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `tax_no` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='credit_days'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `credit_days` int DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='credit_limit'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `credit_limit` decimal(14,2) DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND COLUMN_NAME='notes'), 'DO 0', 'ALTER TABLE `suppliers` ADD COLUMN `notes` text COLLATE utf8mb4_unicode_ci');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='users' AND COLUMN_NAME='discount_cap_pct'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `discount_cap_pct` decimal(5,2) NOT NULL DEFAULT ''0.00''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_hash'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `salary_pin_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_fails'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `salary_pin_fails` tinyint unsigned NOT NULL DEFAULT ''0''');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_block_until'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `salary_pin_block_until` datetime DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='users' AND COLUMN_NAME='salary_pin_set_at'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `salary_pin_set_at` datetime DEFAULT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='shift_edit_log' AND COLUMN_NAME='action' AND COLUMN_TYPE LIKE '%date_branch_approved%'), 'DO 0', 'ALTER TABLE shift_edit_log MODIFY COLUMN action enum(''edit'',''date_branch'',''delete'',''date_branch_approved'') NOT NULL');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='sms_inbox' AND INDEX_NAME='uq_sms_ref'), 'DO 0', 'ALTER TABLE `sms_inbox` ADD UNIQUE KEY `uq_sms_ref` (`ref_no`)');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='sms_inbox' AND INDEX_NAME='idx_sms_dup'), 'DO 0', 'ALTER TABLE `sms_inbox` ADD KEY `idx_sms_dup` (`dup_of`)');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
SET @ac_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ac_schema AND TABLE_NAME='suppliers' AND INDEX_NAME='uq_suppliers_erp_code'), 'DO 0', 'ALTER TABLE `suppliers` ADD UNIQUE KEY `uq_suppliers_erp_code` (`erp_supplier_code`)');
PREPARE ac_stmt FROM @ac_sql; EXECUTE ac_stmt; DEALLOCATE PREPARE ac_stmt;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_041_ashraqa_compat');
