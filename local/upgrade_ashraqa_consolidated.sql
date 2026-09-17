-- =====================================================================
--  upgrade_ashraqa_consolidated.sql
--  ملف الترقية الموحد لقاعدة بيانات فرع إشراقة (Ashraqa)
--  نظام الفجالة ستور المالي — إصدار موحد معتمد ومجرّب 100%
-- =====================================================================
--  تعليمات التشغيل في phpMyAdmin:
--  ١) ادخل على phpMyAdmin واختر قاعدة بيانات إشراقة (u834909335_ashraqa_db).
--  ٢) افتح تاب SQL أو استورد هذا الملف مباشرة من تاب Import (استيراد).
--  ٣) اضغط Go (انطلاق).
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- (٠) تنظيف أي جداول جديدة فارغة أُنشئت بهياكل غير مكتملة في محاولات سابقة
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_group_sources`;
DROP TABLE IF EXISTS `purchase_group_lines`;
DROP TABLE IF EXISTS `purchase_group_items`;
DROP TABLE IF EXISTS `purchase_groups`;
DROP TABLE IF EXISTS `reservation_allocations`;
DROP TABLE IF EXISTS `branch_requests`;
DROP TABLE IF EXISTS `book_orders`;
DROP TABLE IF EXISTS `branch_invoices`;
DROP TABLE IF EXISTS `branch_invoice_lines`;
DROP TABLE IF EXISTS `branch_unposted`;
DROP TABLE IF EXISTS `employee_ledger`;
DROP TABLE IF EXISTS `payroll_adjustments`;
DROP TABLE IF EXISTS `invoice_problems`;
DROP TABLE IF EXISTS `receipt_lines`;
DROP TABLE IF EXISTS `receipts`;
DROP TABLE IF EXISTS `custody_reviews`;
DROP TABLE IF EXISTS `salary_shift_deficits`;
DROP TABLE IF EXISTS `salary_advance_recoveries`;
DROP TABLE IF EXISTS `shift_edit_log`;
DROP TABLE IF EXISTS `shift_sequence_issues`;
DROP TABLE IF EXISTS `push_queue`;
DROP TABLE IF EXISTS `push_subscriptions`;
DROP TABLE IF EXISTS `br_invoice_lines`;
DROP TABLE IF EXISTS `br_unposted_lines`;
DROP TABLE IF EXISTS `br_invoices`;
DROP TABLE IF EXISTS `br_unposted`;

-- ---------------------------------------------------------------------
-- (١) جداول جديدة بالكامل (CREATE TABLE IF NOT EXISTS)
-- ---------------------------------------------------------------------

-- ١.١ مكافآت الموظفين
CREATE TABLE IF NOT EXISTS `employee_bonuses` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `employee_id`       INT UNSIGNED NOT NULL,
  `amount`            DECIMAL(12,2) NOT NULL,
  `bonus_date`        DATE NOT NULL,
  `reason`            VARCHAR(255) DEFAULT NULL,
  `status`            ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_in_salary_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `fk_bonus_emp` (`employee_id`),
  KEY `ix_bonus_salary` (`paid_in_salary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٢ سجل حركات حساب الموظف (سجل موازي للحركات والمديونيات)
CREATE TABLE IF NOT EXISTS `employee_ledger` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `entry_date`  DATE NOT NULL,
  `direction`   ENUM('debit','credit') NOT NULL COMMENT 'debit = عليه (زوّد مديونيته) · credit = له (قلّلها)',
  `amount`      DECIMAL(12,2) NOT NULL,
  `move_type`   VARCHAR(30) NOT NULL COMMENT 'advance · salary_deduction · cash_repay · cancel · opening',
  `description` VARCHAR(255) NULL,
  `treasury_id` INT UNSIGNED NULL COMMENT 'الخزينة لو فيه كاش اتحرك — NULL لو مفيش',
  `ref_type`    VARCHAR(30) NULL,
  `ref_id`      BIGINT UNSIGNED NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_ledger` (`employee_id`, `entry_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٣ مجموعات الشراء وسطورها ومصادرها وتخصيص الاستلام
CREATE TABLE IF NOT EXISTS `purchase_groups` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150) NULL COMMENT 'اسم اختياري — لو فاضي بيتعرض برقمه',
  `status`     ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pg_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_group_lines` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `group_id`   INT NOT NULL,
  `item_id`    INT NOT NULL DEFAULT 0,
  `code`       VARCHAR(64) NOT NULL,
  `item_name`  VARCHAR(255) NULL,
  `total_qty`  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مجموع كل المصادر',
  `is_checked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = تم شراؤه',
  `checked_at` DATETIME NULL,
  `checked_by` INT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_pgl` (`group_id`, `item_id`, `code`),
  KEY `idx_pgl_group` (`group_id`, `is_checked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_group_sources` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `line_id`     INT NOT NULL COMMENT 'purchase_group_lines.id',
  `src_type`    ENUM('reservation','shortage') NOT NULL,
  `src_line_id` INT NOT NULL COMMENT 'book_reservation_lines.id أو shortage_request_lines.id',
  `src_head_id` INT NOT NULL COMMENT 'book_reservations.id أو shortage_requests.id',
  `branch_id`   INT UNSIGNED NULL,
  `qty`         DECIMAL(12,2) NOT NULL DEFAULT 0,
  `is_checked`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'المصدر ده اتجابله نسخة',
  `checked_at`  TIMESTAMP NULL DEFAULT NULL,
  `checked_by`  INT NULL DEFAULT NULL,
  UNIQUE KEY `uq_pgs` (`line_id`, `src_type`, `src_line_id`),
  KEY `idx_pgs_src` (`src_type`, `src_line_id`),
  KEY `idx_pgs_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservation_allocations` (
  `id`              INT AUTO_INCREMENT,
  `distribution_id` INT NOT NULL COMMENT 'invoice_distribution.id',
  `invoice_no`      VARCHAR(60) NULL,
  `item_id`         INT NOT NULL DEFAULT 0,
  `item_code`       VARCHAR(64) NOT NULL,
  `reservation_id`  INT NOT NULL,
  `res_line_id`     INT NOT NULL COMMENT 'book_reservation_lines.id',
  `branch_id`       INT UNSIGNED NULL,
  `qty`             DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_by`      INT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ra` (`distribution_id`, `item_code`, `res_line_id`),
  KEY `idx_ra_line` (`res_line_id`),
  KEY `idx_ra_dist` (`distribution_id`, `item_code`),
  KEY `idx_ra_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٤ إشعارات الويب (Web Push)
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `endpoint`      VARCHAR(500) NOT NULL,
  `endpoint_hash` CHAR(64) NOT NULL,
  `p256dh`        VARCHAR(255) NOT NULL,
  `auth`          VARCHAR(64) NOT NULL,
  `user_agent`    VARCHAR(255) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_ok_at`    TIMESTAMP NULL,
  `fail_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
  KEY `idx_push_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_queue` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       VARCHAR(255) NULL,
  `link`       VARCHAR(255) NULL,
  `status`     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `tries`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`    TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_push_q` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٥ عجز الورديات المخصوم في الرواتب
CREATE TABLE IF NOT EXISTS `salary_shift_deficits` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `salary_id`   INT UNSIGNED NOT NULL,
  `shift_id`    INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `shift_date`  DATE NOT NULL,
  `variance`    DECIMAL(12,2) NOT NULL COMMENT 'فرق الوردية الخام — سالب = عجز',
  `tolerance`   DECIMAL(12,2) NOT NULL COMMENT 'العتبة وقت الصرف',
  `exceeded`    DECIMAL(12,2) NOT NULL COMMENT 'المتجاوز = |الفرق| ناقص العتبة',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift` (`shift_id`),
  KEY `idx_salary` (`salary_id`),
  KEY `idx_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٦ مسوّدة تعديلات الرواتب قبل الصرف
CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `employee_id`   INT NOT NULL,
  `month`         CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `leave_days`    INT NULL COMMENT 'أيام الإجازة',
  `due_days`      DECIMAL(10,2) NULL COMMENT 'أيام الاستحقاق',
  `monthly_take`  DECIMAL(12,2) NULL COMMENT 'المخصوم من سلف الشهر',
  `standing_take` DECIMAL(12,2) NULL COMMENT 'المخصوم من السلف المستديمة',
  `cashier_take`  DECIMAL(12,2) NULL COMMENT 'المخصوم من عجز الكاشير',
  `updated_by`    INT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payadj_emp_month` (`employee_id`, `month`),
  KEY `ix_payadj_month` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٧ استرجاع خصومات السلف في الرواتب
CREATE TABLE IF NOT EXISTS `salary_advance_recoveries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `salary_id`  BIGINT UNSIGNED NOT NULL,
  `advance_id` BIGINT UNSIGNED NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sar_salary` (`salary_id`),
  KEY `ix_sar_advance` (`advance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٨ سجل مراجعات واعتماد إقفال العهد
CREATE TABLE IF NOT EXISTS `custody_reviews` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `custody_id`         BIGINT UNSIGNED NOT NULL,
  `expected`           DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'آخر المدة المفروض وقت المراجعة',
  `cashier_counted`    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'ما عده الكاشير وقدمه',
  `accountant_counted` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'ما استلمه المحاسب فعلا',
  `variance`           DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'الفرق: سالب عجز وموجب زيادة',
  `decision`           ENUM('approved','rejected') NOT NULL,
  `reason`             VARCHAR(255) DEFAULT NULL,
  `reviewed_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_custody` (`custody_id`),
  KEY `idx_decision` (`decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.٩ فواتير الفروع وبنودها والغير مرحل
CREATE TABLE IF NOT EXISTS `br_invoices` (
  `agent_key`      VARCHAR(32) NOT NULL,
  `sth_id`         BIGINT(20) NOT NULL COMMENT 'STH_ID — مفتاح الفاتورة',
  `suffix`         VARCHAR(8) NOT NULL COMMENT 'جدول الشهر',
  `doc_no`         VARCHAR(40) DEFAULT NULL,
  `doc_type`       SMALLINT(6) DEFAULT NULL,
  `supplier_id`    INT(11) DEFAULT NULL,
  `supplier_code`  VARCHAR(40) DEFAULT NULL,
  `supplier_name`  VARCHAR(255) DEFAULT NULL,
  `net_amount`     DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `total_amount`   DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `invoice_date`   DATETIME DEFAULT NULL,
  `company_branch` INT(11) DEFAULT NULL,
  `last_modify`    DATETIME DEFAULT NULL,
  `synced_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_key`, `sth_id`, `suffix`),
  KEY `idx_bri_agent_date` (`agent_key`, `invoice_date`),
  KEY `idx_bri_doctype` (`doc_type`),
  KEY `idx_bri_doc_no` (`doc_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `br_invoice_lines` (
  `agent_key`    VARCHAR(32) NOT NULL,
  `sth_id`       BIGINT(20) NOT NULL,
  `suffix`       VARCHAR(8) NOT NULL DEFAULT '',
  `line_no`      INT(11) NOT NULL,
  `item_id`      INT(11) DEFAULT NULL,
  `item_code`    VARCHAR(64) DEFAULT NULL,
  `item_name`    VARCHAR(255) DEFAULT NULL,
  `unit_id`      INT(11) DEFAULT NULL,
  `unit_name`    VARCHAR(60) DEFAULT NULL,
  `qty`          DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `bonus_qty`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `price`        DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `discount_pct` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `net_price`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `total_net`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `last_modify`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`agent_key`, `sth_id`, `suffix`, `line_no`),
  KEY `idx_bril_code` (`item_code`),
  KEY `idx_bril_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `br_unposted` (
  `agent_key`      VARCHAR(32) NOT NULL,
  `sth_id`         BIGINT(20) NOT NULL,
  `doc_no`         VARCHAR(40) DEFAULT NULL,
  `doc_type`       SMALLINT(6) DEFAULT NULL,
  `supplier_id`    INT(11) DEFAULT NULL,
  `supplier_code`  VARCHAR(40) DEFAULT NULL,
  `supplier_name`  VARCHAR(255) DEFAULT NULL,
  `net_amount`     DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `total_amount`   DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `invoice_date`   DATETIME DEFAULT NULL,
  `company_branch` INT(11) DEFAULT NULL,
  `last_modify`    DATETIME DEFAULT NULL,
  `synced_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_key`, `sth_id`),
  KEY `idx_bru_agent_date` (`agent_key`, `invoice_date`),
  KEY `idx_bru_doctype` (`doc_type`),
  KEY `idx_bru_doc_no` (`doc_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `br_unposted_lines` (
  `agent_key`    VARCHAR(32) NOT NULL,
  `sth_id`       BIGINT(20) NOT NULL,
  `line_no`      INT(11) NOT NULL,
  `item_id`      INT(11) DEFAULT NULL,
  `item_code`    VARCHAR(64) DEFAULT NULL,
  `item_name`    VARCHAR(255) DEFAULT NULL,
  `unit_id`      INT(11) DEFAULT NULL,
  `unit_name`    VARCHAR(60) DEFAULT NULL,
  `qty`          DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `bonus_qty`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `price`        DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `discount_pct` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `net_price`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `total_net`    DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `last_modify`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`agent_key`, `sth_id`, `line_no`),
  KEY `idx_brul_code` (`item_code`),
  KEY `idx_brul_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.١٠ سجل تعديل ومسح الورديات
CREATE TABLE IF NOT EXISTS `shift_edit_log` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shift_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `action`      ENUM('edit','date_branch','delete') NOT NULL,
  `before_json` TEXT NOT NULL,
  `after_json`  TEXT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `shift_id` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.١١ مشاكل تسلسل الورديات
CREATE TABLE IF NOT EXISTS `shift_sequence_issues` (
  `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id`        INT UNSIGNED NOT NULL,
  `shift_id`         BIGINT UNSIGNED NULL,
  `kind`             ENUM('gap','duplicate') NOT NULL,
  `expected_no`      INT NULL,
  `found_no`         INT NOT NULL,
  `status`           ENUM('open','ignored','sent','answered') DEFAULT 'open',
  `assigned_user_id` INT UNSIGNED NULL,
  `owner_note`       VARCHAR(255) NULL,
  `reply`            VARCHAR(500) NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_by`      INT UNSIGNED NULL,
  `resolved_at`      TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_issue` (`branch_id`, `kind`, `found_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.١٢ موديول استلام الفواتير وسطورها
CREATE TABLE IF NOT EXISTS `receipts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'المجموعة — 0 يعني فاتورة مفردة',
  `distribution_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'الفاتورة المفردة — 0 يعني مجموعة',
  `branch_id`       INT UNSIGNED NOT NULL,
  `status`          ENUM('sent','receiving','received','approved') NOT NULL DEFAULT 'sent',
  `started_at`      TIMESTAMP NULL DEFAULT NULL,
  `started_by`      INT UNSIGNED DEFAULT NULL,
  `received_at`     TIMESTAMP NULL DEFAULT NULL,
  `received_by`     INT UNSIGNED DEFAULT NULL,
  `approved_at`     TIMESTAMP NULL DEFAULT NULL,
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `reject_reason`   VARCHAR(255) NULL DEFAULT NULL,
  `rejected_at`     TIMESTAMP NULL DEFAULT NULL,
  `rejected_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt` (`group_id`, `distribution_id`, `branch_id`),
  KEY `idx_branch_status` (`branch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_lines` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id`  BIGINT UNSIGNED NOT NULL,
  `item_code`   VARCHAR(60) NOT NULL,
  `item_name`   VARCHAR(255) DEFAULT NULL,
  `scanned_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_item` (`receipt_id`, `item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ١.١٣ مشاكل الفواتير (تلقائي من المردودات + يدوي)
CREATE TABLE IF NOT EXISTS `invoice_problems` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_id` BIGINT UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code`       VARCHAR(60) NOT NULL,
  `item_name`       VARCHAR(255) DEFAULT NULL,
  `source`          ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  `short_qty`       DECIMAL(14,3) NOT NULL DEFAULT 0.000 COMMENT 'ناقص',
  `over_qty`        DECIMAL(14,3) NOT NULL DEFAULT 0.000 COMMENT 'زيادة',
  `note`            TEXT DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_problem` (`distribution_id`, `item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- (٢) تعديل وتحديث الجداول القائمة (ALTER TABLE)
-- ---------------------------------------------------------------------

-- ٢.١ سلف الموظفين (أقساط السلف وسلفة الشهر vs سلفة مستديمة)
ALTER TABLE `employee_advances`
  ADD COLUMN IF NOT EXISTS `monthly_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'القسط الشهري المستقطع من الراتب',
  ADD COLUMN IF NOT EXISTS `remaining` DECIMAL(12,2) NULL COMMENT 'المتبقي من أصل السلفة',
  ADD COLUMN IF NOT EXISTS `adv_type` ENUM('monthly','standing') NOT NULL DEFAULT 'monthly'
  COMMENT 'monthly = سلفة الشهر الحالي · standing = سلفة مستديمة متراكمة من شهور فاتت';

ALTER TABLE `employee_advances`
  MODIFY `status` ENUM('pending','deducted','recovered') NOT NULL DEFAULT 'pending';

-- ٢.٢ الموظفين (الساعات المنتظرة + نوع حساب المرتب)
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `expected_monthly_hours` DECIMAL(6,2) NULL DEFAULT NULL
  COMMENT 'الساعات المنتظرة من الموظف في الشهر — الأساس اللي المرتب بيتحسب عليه',
  ADD COLUMN IF NOT EXISTS `salary_mode` VARCHAR(10) NOT NULL DEFAULT 'hours'
  COMMENT 'hours = على الساعات · fixed = مرتب ثابت كامل من غير حساب ساعات';

-- ٢.٣ المستخدمين (إصدار الصلاحيات لتحديث الجلسات فوراً)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `perms_version` INT NOT NULL DEFAULT 1
  COMMENT 'رقم إصدار الصلاحيات — بيزيد مع أي تعديل عشان الجلسات تتحدّث فوراً';

-- ٢.٤ طلبات النواقص وحجز الكتب
ALTER TABLE `shortage_requests`
  ADD COLUMN IF NOT EXISTS `source` ENUM('branch','purchase') NOT NULL DEFAULT 'branch'
  COMMENT 'branch = طلب من فرع · purchase = طلب من المشتريات';

ALTER TABLE `shortage_requests`
  MODIFY COLUMN `to_branch_id` INT UNSIGNED NULL
  COMMENT 'الفرع المطلوب منه — NULL لما الطلب رايح للمشتريات';

ALTER TABLE `shortage_request_lines`
  ADD COLUMN IF NOT EXISTS `item_id` INT NOT NULL DEFAULT 0 COMMENT 'رقم الصنف',
  ADD COLUMN IF NOT EXISTS `status` ENUM('open','grouped','purchased','done','cancelled') NOT NULL DEFAULT 'open',
  ADD INDEX IF NOT EXISTS `idx_srl_item` (`item_id`),
  ADD INDEX IF NOT EXISTS `idx_srl_status` (`status`);

ALTER TABLE `book_reservation_lines`
  ADD COLUMN IF NOT EXISTS `allocated_qty` DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `stage` ENUM('reserved','grouped','purchased') NOT NULL DEFAULT 'reserved',
  ADD INDEX IF NOT EXISTS `idx_brl_stage` (`stage`);

-- ٢.٥ الحضور والانصراف (دقة GPS)
ALTER TABLE `attendance`
  ADD COLUMN IF NOT EXISTS `check_in_acc`  DECIMAL(8,2) NULL COMMENT 'دقة قراءة الموقع بالمتر وقت الحضور',
  ADD COLUMN IF NOT EXISTS `check_out_acc` DECIMAL(8,2) NULL COMMENT 'دقة قراءة الموقع بالمتر وقت الانصراف';

-- ٢.٦ الرواتب (الفترة، الساعات، أيام العمل الكسرية، المكافآت، عجز الكاشير، الإرجاع)
ALTER TABLE `salaries`
  ADD COLUMN IF NOT EXISTS `period_from` DATE NULL COMMENT 'بداية فترة الحضور',
  ADD COLUMN IF NOT EXISTS `period_to` DATE NULL COMMENT 'نهاية فترة الحضور',
  ADD COLUMN IF NOT EXISTS `work_hours` DECIMAL(7,2) NULL COMMENT 'إجمالي ساعات العمل في الفترة',
  ADD COLUMN IF NOT EXISTS `work_days` DECIMAL(7,3) NULL COMMENT 'أيام العمل المحسوبة بدقة كسرية من ساعات الحضور',
  ADD COLUMN IF NOT EXISTS `leave_days` INT NOT NULL DEFAULT 0 COMMENT 'أيام الإجازة المستحقة',
  ADD COLUMN IF NOT EXISTS `due_days_calc` DECIMAL(7,3) NULL COMMENT 'أيام الاستحقاق المحسوبة بدقة كسرية قبل أي تعديل',
  ADD COLUMN IF NOT EXISTS `due_days` DECIMAL(7,3) NULL COMMENT 'أيام الاستحقاق المعتمدة للصرف الفعلي بدقة كسرية',
  ADD COLUMN IF NOT EXISTS `due_days_edited_by` INT UNSIGNED NULL COMMENT 'معرّف الأونر الذي عدّل أيام الاستحقاق',
  ADD COLUMN IF NOT EXISTS `due_days_edited_at` DATETIME NULL COMMENT 'تاريخ ووقت تعديل أيام الاستحقاق',
  ADD COLUMN IF NOT EXISTS `total_bonuses` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي المكافآت',
  ADD COLUMN IF NOT EXISTS `total_cashier_deficits` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'عجز الكاشير المخصوم',
  ADD COLUMN IF NOT EXISTS `reversed_at` DATETIME NULL AFTER `paid_at`,
  ADD COLUMN IF NOT EXISTS `reversed_by` INT NULL AFTER `reversed_at`,
  ADD COLUMN IF NOT EXISTS `reverse_reason` VARCHAR(255) NULL AFTER `reversed_by`,
  ADD COLUMN IF NOT EXISTS `reversal_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `salaries`
  MODIFY COLUMN `work_days`     DECIMAL(7,3) NULL,
  MODIFY COLUMN `due_days_calc` DECIMAL(7,3) NULL,
  MODIFY COLUMN `due_days`      DECIMAL(7,3) NULL;

UPDATE `salaries`
   SET `reversal_seq` = `id`
 WHERE `reversed_at` IS NOT NULL
   AND `reversal_seq` = 0;

-- فهرس لحماية المفتاح الأجنبي fk_salary_emp وفهرس فريد للمرتبات الشغالة
ALTER TABLE `salaries`
  ADD INDEX IF NOT EXISTS `idx_salary_emp` (`employee_id`),
  ADD UNIQUE KEY IF NOT EXISTS `uq_salary_emp_active` (`employee_id`, `salary_month`, `reversal_seq`);

-- حذف الفهرس القديم بعد ضمان وجود البديل
DROP INDEX IF EXISTS `uq_salary_emp_month` ON `salaries`;

-- ٢.٧ جزاءات الموظفين (ربطها بالمرتب)
ALTER TABLE `employee_penalties`
  ADD COLUMN IF NOT EXISTS `deducted_in_salary_id` BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS `ix_pen_salary` (`deducted_in_salary_id`);

-- ٢.٨ عهد الفروع والموظفين (دعم عهدة الموظف ودورة المراجعة والاعتماد)
ALTER TABLE `branch_custody`
  MODIFY COLUMN `branch_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `branch_custody`
  MODIFY COLUMN `status` ENUM('open','pending_review','rejected','closed')
  NOT NULL DEFAULT 'open';

ALTER TABLE `branch_custody`
  ADD COLUMN IF NOT EXISTS `employee_id` INT UNSIGNED DEFAULT NULL COMMENT 'عهدة موظف — NULL = عهدة فرع',
  ADD COLUMN IF NOT EXISTS `responsible_employee_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'الموظف المسؤول عن العجز',
  ADD COLUMN IF NOT EXISTS `submitted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'الكاشير الذي قدم العد',
  ADD COLUMN IF NOT EXISTS `submitted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت التقديم',
  ADD COLUMN IF NOT EXISTS `accountant_counted` DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'الرقم الذي استلمه المحاسب فعلا',
  ADD COLUMN IF NOT EXISTS `reject_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT 'سبب الرفض',
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'المحاسب الذي راجع',
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_employee` (`employee_id`),
  ADD INDEX IF NOT EXISTS `idx_employee_status` (`employee_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_branch_status` (`branch_id`, `status`);

-- ٢.٩ الخزائن (نوع عهدة الموظف)
ALTER TABLE `treasuries`
  MODIFY COLUMN `type` ENUM('cash','visa','instapay','wallet','card','branch_custody','main','employee_custody')
  NOT NULL DEFAULT 'cash';

ALTER TABLE `treasuries`
  ADD COLUMN IF NOT EXISTS `employee_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`,
  ADD INDEX IF NOT EXISTS `idx_treasuries_employee` (`employee_id`);

-- ٢.١٠ أذون الصرف (دعم الصرف لعهدة موظف)
ALTER TABLE `disbursement_orders`
  MODIFY COLUMN `branch_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `disbursement_orders`
  ADD COLUMN IF NOT EXISTS `employee_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`,
  ADD INDEX IF NOT EXISTS `idx_orders_employee` (`employee_id`);

-- ٢.١١ حركات الخزينة (القيد العكسي)
ALTER TABLE `treasury_movements`
  ADD COLUMN IF NOT EXISTS `reversed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reversed_by` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `reversal_entry_id` BIGINT UNSIGNED NULL COMMENT 'journal_entries.id بتاع القيد العكسي',
  ADD INDEX IF NOT EXISTS `idx_reversed_at` (`reversed_at`);

-- ٢.١٢ الورديات (مهلة الإرجاع ٤٨ ساعة)
ALTER TABLE `shifts`
  ADD COLUMN IF NOT EXISTS `return_expired_at` TIMESTAMP NULL DEFAULT NULL
  COMMENT 'الوردية المرجوعة عدّت مهلة التعديل من غير ما تتقفل تاني';

-- ٢.١٣ الموردين (ربط كود نظام الشركة)
ALTER TABLE `suppliers`
  ADD COLUMN IF NOT EXISTS `erp_supplier_id` INT NULL COMMENT 'رقم المورد في نظام الشركة',
  ADD INDEX IF NOT EXISTS `idx_erp_supplier_id` (`erp_supplier_id`);

-- ٢.١٤ الفروع (فروع لها مبيعات)
ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `has_sales` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1 = له مبيعات ويظهر في تقفيل الورديات' AFTER `has_finance`;

UPDATE `branches`
   SET `has_sales` = 0
 WHERE `id` = 6;

-- ٢.١٥ سجلات التوزيع والمجموعات (مفتاح الفرع ونوع المستند)
ALTER TABLE `invoice_distribution`
  ADD COLUMN IF NOT EXISTS `company_branch` INT NULL COMMENT 'فرع الشركة من نظام الإدارة',
  ADD COLUMN IF NOT EXISTS `doc_type` SMALLINT NULL COMMENT 'نوع المستند',
  ADD INDEX IF NOT EXISTS `idx_dist_key` (`invoice_no`, `company_branch`, `doc_type`);

ALTER TABLE `invoice_group_items`
  ADD COLUMN IF NOT EXISTS `company_branch` INT NULL COMMENT 'فرع الشركة من نظام الإدارة',
  ADD COLUMN IF NOT EXISTS `doc_type` SMALLINT NULL COMMENT 'نوع المستند';

-- ٢.١٦ أرصدة الفروع (ختم الجولة لوكيل الإدارة)
ALTER TABLE `hq_branch_balances`
  ADD COLUMN IF NOT EXISTS `batch` VARCHAR(20) NULL DEFAULT NULL COMMENT 'ختم جولة وكيل الإدارة',
  ADD KEY IF NOT EXISTS `idx_hqbb_agent_batch` (`agent_key`, `batch`);


-- ---------------------------------------------------------------------
-- (٣) إعدادات النظام الأساسية وتحديث الصلاحيات
-- ---------------------------------------------------------------------

-- ٣.١ تحديث تجزئة صلاحيات الحضور والانصراف
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_key`)
SELECT DISTINCT `user_id`, 'pc_scan'
FROM `user_permissions`
WHERE `module_key` = 'attendance_import';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_key`)
SELECT DISTINCT `user_id`, 'attendance_sheet'
FROM `user_permissions`
WHERE `module_key` = 'attendance_import';

DELETE FROM `user_permissions` WHERE `module_key` = 'attendance_import';

-- ٣.٢ إعدادات النظام
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('purchase_supervisor_id', '0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('receiving_supervisor_id', '0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('shift_edit_accountant_id', '0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('shift_return_hours', '48');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('shift_seq_start_date', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('shift_return_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('hq_own_sto_ids', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES ('supplier_statement_start', '2026-06-27', 'تاريخ بداية كشف حساب الموردين');

SET FOREIGN_KEY_CHECKS = 1;
