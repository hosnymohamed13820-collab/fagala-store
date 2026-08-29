-- =====================================================================
--  upgrade_branch_requests.sql — طلبات الفروع · تجميع الحجوزات · تخصيص الاستلام
--  نظام الفجالة ستور المالي
--
--  السلسلة الكاملة:
--    فرع يحجز كتاب / يطلب ناقص
--      ↓  طلبات الفروع        (شاشة عرض للمشتريات — كارت لكل فرع)
--      ↓  تجميع الحجوزات      (مجموعة شراء — الكميات بتتجمع بالـ item_id)
--      ↓  المشتريات تشتري     (تشيك على الصنف في صفحة المجموعة)
--      ↓  الاستلام            (زرار «سحب أعداد الفروع»)
--      ↓  التوزيع بالأقدم     (أقدم حجز ياخد الأول — مهما كان فرعه)
--
--  🔴 التجميع للشراء بس. تفاصيل المصدر (الفرع + الحجز) **مابتضيعش** —
--     عشان لما المشتريات تشتري أقل، الاستلام يرجع للحجوزات الأصلية
--     ويرتّبها بالأقدم.
--
--  التشغيل: phpMyAdmin ← SQL ← الصق ونفّذ. آمن للتكرار.
--  الملف ده **مايترفعش على الدومين**.
-- =====================================================================


-- ---------------------------------------------------------------------
--  (١) طلب النواقص: مصدر الطلب + حالة كل سطر + رقم الصنف
--
--  ⚠️ الجداول دي **مش** shortage_batches / shortage_lines بتاعة
--     «تحليل الموسم» — دول حاجة تانية خالص بنفس الكلمة.
--
--  source = 'branch'   : فرع طالب من فرع (السلوك القديم — الافتراضي)
--           'purchase' : فرع طالب من المشتريات (مفيش فرع مطلوب منه)
--
--  🔴 to_branch_id بقى NULL — طلب المشتريات مالوش فرع مستقبِل.
--     الصفوف القديمة كلها ليها فرع، فمفيش صف بيتأثر.
-- ---------------------------------------------------------------------
ALTER TABLE `shortage_requests`
  ADD COLUMN IF NOT EXISTS `source` ENUM('branch','purchase') NOT NULL DEFAULT 'branch'
      COMMENT 'branch = طلب من فرع · purchase = طلب من المشتريات',
  MODIFY COLUMN `to_branch_id` INT NULL
      COMMENT 'الفرع المطلوب منه — NULL لما الطلب رايح للمشتريات';

-- رقم الصنف على السطر. الربط بالـ item_id مش بالكود:
-- الصنف الواحد ليه أكتر من كود (أساسي + فرعي) وكلهم بنفس الـ item_id،
-- فالمقارنة بالكود بتدّي نتيجة غلط.
-- 0 = صف قديم ما اتترجمش لسه (الكود بيرجع للكود وقتها).
ALTER TABLE `shortage_request_lines`
  ADD COLUMN IF NOT EXISTS `item_id` INT NOT NULL DEFAULT 0
      COMMENT 'رقم الصنف — 0 يعني صف قديم بيتعرّف بالكود',
  ADD COLUMN IF NOT EXISTS `status` ENUM('open','grouped','purchased','done','cancelled')
      NOT NULL DEFAULT 'open'
      COMMENT 'مرحلة السطر: مفتوح ← اتجمّع للشراء ← اتشرى ← خلص',
  ADD INDEX IF NOT EXISTS `idx_srl_item`   (`item_id`),
  ADD INDEX IF NOT EXISTS `idx_srl_status` (`status`);


-- ---------------------------------------------------------------------
--  (٢) حجز الكتب: الكمية المخصّصة + مرحلة السطر
--
--  🔴 allocated_qty **بتتحسب من جدول التخصيصات مش بتتزوّد**.
--     أي كود بيعمل allocated_qty = allocated_qty + x غلط —
--     تشغيل «سحب أعداد الفروع» مرتين هيضاعف الكمية.
--     القاعدة: SUM من reservation_allocations وخلاص.
--
--  حالة الحجز للعرض بتتحسب من الكميتين مش من عمود:
--      allocated = 0            → محجوز
--      0 < allocated < qty      → تم توفير جزء من الحجز
--      allocated >= qty         → تم توفير كامل الحجز
--
--  stage بتمشي لقدام بس، ومالهاش علاقة بالتخصيص:
--      reserved → grouped (دخل مجموعة شراء) → purchased (المشتريات علّمت عليه)
-- ---------------------------------------------------------------------
ALTER TABLE `book_reservation_lines`
  ADD COLUMN IF NOT EXISTS `allocated_qty` DECIMAL(12,2) NOT NULL DEFAULT 0
      COMMENT 'المخصّص فعلاً من الاستلامات — محسوبة من reservation_allocations',
  ADD COLUMN IF NOT EXISTS `stage` ENUM('reserved','grouped','purchased') NOT NULL DEFAULT 'reserved'
      COMMENT 'مرحلة الشراء — مش حالة التوفير',
  ADD INDEX IF NOT EXISTS `idx_brl_stage` (`stage`);


-- ---------------------------------------------------------------------
--  (٣) مجموعة الشراء — رأس المجموعة
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_groups` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(150) NULL COMMENT 'اسم اختياري — لو فاضي بيتعرض برقمه',
    `status`     ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_by` INT       NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pg_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  (٤) سطور المجموعة — سطر واحد لكل صنف مهما كان عدد الفروع
--
--  نفس الكتاب مطلوب من ٥ فروع × ٥ نسخ = **سطر واحد** إجماليه ٢٥،
--  مش خمس سطور. والتفاصيل في purchase_group_sources.
--
--  is_checked = المشتريات علّمت «تم شراؤه» (شكل Keep).
--  🔴 التشييك **مايقفلش الحجز** — الحجز بيتقفل من التخصيص في الاستلام بس.
--
--  مفتاح التجميع: item_id لو أكبر من صفر، وإلا الكود.
--  عشان كده الـUNIQUE على الاتنين مع بعض.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_group_lines` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `group_id`   INT           NOT NULL,
    `item_id`    INT           NOT NULL DEFAULT 0,
    `code`       VARCHAR(64)   NOT NULL,
    `item_name`  VARCHAR(255)  NULL,
    `total_qty`  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مجموع كل المصادر — محسوب من purchase_group_sources',
    `is_checked` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = تم شراؤه',
    `checked_at` DATETIME      NULL,
    `checked_by` INT           NULL,
    `sort_order` INT           NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_pgl` (`group_id`, `item_id`, `code`),
    KEY `idx_pgl_group` (`group_id`, `is_checked`),
    CONSTRAINT `fk_pgl_group` FOREIGN KEY (`group_id`)
        REFERENCES `purchase_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  (٥) مصادر كل سطر — الفرع والحجز اللي الكمية جت منهم
--
--  ده اللي بيخلّي التجميع مايضيّعش حجز العميل: السطر بيقول ٢٥،
--  والمصادر بتقول اشراقة ٥ · ABC ٥ · دجلة ٥ · الفجالة ٥ · بونجور ٥،
--  وكل واحدة مربوطة بسطر الحجز الأصلي برقمه.
--
--  🔴 الـUNIQUE بيمنع نفس سطر الحجز يتحط مرتين في نفس السطر.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_group_sources` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `line_id`     INT NOT NULL COMMENT 'purchase_group_lines.id',
    `src_type`    ENUM('reservation','shortage') NOT NULL,
    `src_line_id` INT NOT NULL COMMENT 'book_reservation_lines.id أو shortage_request_lines.id',
    `src_head_id` INT NOT NULL COMMENT 'book_reservations.id أو shortage_requests.id',
    `branch_id`   INT NULL,
    `qty`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_pgs` (`line_id`, `src_type`, `src_line_id`),
    KEY `idx_pgs_src`    (`src_type`, `src_line_id`),
    KEY `idx_pgs_branch` (`branch_id`),
    CONSTRAINT `fk_pgs_line` FOREIGN KEY (`line_id`)
        REFERENCES `purchase_group_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  (٦) تخصيص الاستلام للحجوزات
--
--  «الكتاب ده اتخصص للعميل ده من أنهي فاتورة استلام وبكام.»
--
--  هوية صنف الفاتورة في النظام ده = (سجل التوزيع + كود الصنف) —
--  زي invoice_distribution_lines بالظبط، اللي بيخزّن الكود في notes
--  والكمية في amount. مفيش «رقم سطر فاتورة» متخزّن عندنا أصلاً.
--
--  🔴 الـUNIQUE هو اللي بيمنع التخصيص المكرّر: تشغيل «سحب أعداد الفروع»
--     تاني على نفس الفاتورة بيعمل UPDATE على نفس الصف، مش صف جديد.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservation_allocations` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `distribution_id` INT           NOT NULL COMMENT 'invoice_distribution.id',
    `invoice_no`      VARCHAR(60)   NULL,
    `item_id`         INT           NOT NULL DEFAULT 0,
    `item_code`       VARCHAR(64)   NOT NULL,
    `reservation_id`  INT           NOT NULL,
    `res_line_id`     INT           NOT NULL COMMENT 'book_reservation_lines.id',
    `branch_id`       INT           NULL,
    `qty`             DECIMAL(12,2) NOT NULL DEFAULT 0,
    `created_by`      INT           NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_ra` (`distribution_id`, `item_code`, `res_line_id`),
    KEY `idx_ra_line`   (`res_line_id`),
    KEY `idx_ra_dist`   (`distribution_id`, `item_code`),
    KEY `idx_ra_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  (٧) مسؤول المشتريات — بيوصله طلب «من المشتريات» مع الأونر
--      ٠ = مفيش. بيتظبط من الإعدادات بقائمة منسدلة.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`)
VALUES ('purchase_supervisor_id', '0');


-- =====================================================================
--  فحص بعد التنفيذ (اختياري — الصق كل واحد لوحده)
-- =====================================================================
-- SHOW COLUMNS FROM shortage_requests      LIKE 'source';
-- SHOW COLUMNS FROM shortage_request_lines LIKE 'item_id';
-- SHOW COLUMNS FROM book_reservation_lines LIKE 'allocated_qty';
-- SELECT COUNT(*) FROM purchase_groups;          -- لازم 0
-- SELECT COUNT(*) FROM reservation_allocations;  -- لازم 0
