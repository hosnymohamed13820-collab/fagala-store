CREATE TABLE IF NOT EXISTS `sms_permits` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sms_id`       BIGINT UNSIGNED NOT NULL,                        -- sms_inbox.id
  `action`       ENUM('receipt','disbursement') NOT NULL,
  `payload`      JSON NOT NULL,                                   -- خانات البوب أب زي ما اتبعتت (treasury_id · op_type · entry_code_id · cost_branch_id · supplier_id · employee_id · branch_id · amount · date · description · notes)
  `amount`       DECIMAL(14,2) NOT NULL,                          -- نسخة من payload للعرض والفلترة
  `status`       ENUM('pending','approving','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_by`   INT NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by`  INT DEFAULT NULL,
  `reviewed_at`  DATETIME DEFAULT NULL,
  `review_note`  VARCHAR(500) DEFAULT NULL,                       -- سبب الرفض
  `movement_id`  INT DEFAULT NULL,                                -- اللي رجّعته treasury_movement() لو رجّعت
  PRIMARY KEY (`id`),
  KEY `idx_sp_sms` (`sms_id`, `status`),
  KEY `idx_sp_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
