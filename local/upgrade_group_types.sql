-- =====================================================================
--  upgrade_group_types.sql — أنواع المجموعات + مجموعة الأصناف المحددة
--  نظام الفجالة ستور المالي — 2026-08-14
-- =====================================================================
--
--  المجموعة بقى ليها نوعين:
--
--    full  = مجموعة فواتير كاملة (السلوك القديم بالحرف)
--            يسمح: إضافة فاتورة كاملة
--            يمنع: إضافة صنف منفرد من فاتورة تانية
--
--    items = مجموعة أصناف محددة من فواتير مختلفة
--            يسمح: إضافة صنف منفرد · وإضافة فاتورة كاملة
--                  (وساعتها كل أصناف الفاتورة بتدخل المجموعة)
--
--  🔑 فكرة التصميم (مهمة):
--     `invoice_group_items` بيفضل زي ما هو = **قايمة فواتير المجموعة**،
--     وكل الميكانيكا القديمة (سجلات التوزيع · الشلال · التصدير ·
--     group_heal_posted) شغالة عليه من غير أي تغيير.
--     `invoice_group_lines` الجديد = **فلتر** على الأصناف بس.
--
--     المجموعة نوع `full` مالهاش أي صف في invoice_group_lines →
--     يعني «كل الأصناف» → **نفس السلوك القديم تمامًا**.
--
--  🔒 التوافق مع القديم:
--     كل المجموعات الموجودة هتاخد group_type = 'full' تلقائيًا
--     (القيمة الافتراضية للعمود) — صفر Migration للبيانات، وصفر
--     تغيير في سلوكها.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
-- =====================================================================

-- (١) نوع المجموعة
ALTER TABLE `invoice_groups`
  ADD COLUMN IF NOT EXISTS `group_type` ENUM('full','items') NOT NULL DEFAULT 'full'
  COMMENT 'full = فواتير كاملة (السلوك القديم) · items = أصناف محددة';

-- (٢) الأصناف المختارة (فلتر) — فاضي للمجموعة نوع full
CREATE TABLE IF NOT EXISTS `invoice_group_lines` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`  BIGINT UNSIGNED NOT NULL,
  `sth_id`    BIGINT NOT NULL COMMENT 'الفاتورة اللي الصنف اتاخد منها',
  `suffix`    VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'YY_MM = غير محفوظة، أو لاحقة الشهر',
  `item_code` VARCHAR(60) NOT NULL,
  `added_by`  INT UNSIGNED NULL,
  `added_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- ⚠️ ده اللي بيمنع إضافة نفس السطر مرتين — الهوية هي
  --    (المجموعة + الفاتورة + الكود)، مش اسم الصنف.
  UNIQUE KEY `uq_igl` (`group_id`,`sth_id`,`suffix`,`item_code`),
  KEY `idx_igl_group` (`group_id`),
  KEY `idx_igl_inv` (`sth_id`,`suffix`),
  CONSTRAINT `fk_igl_group` FOREIGN KEY (`group_id`)
    REFERENCES `invoice_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (٣) فهرس بيسرّع بحث الصنف في البوب أب (بحث بالاسم على المرآة).
--     لو الفهرس موجود هترمي خطأ 1061 — تجاهله.
ALTER TABLE `hq_invoice_lines`  ADD INDEX `idx_hqil_code` (`item_code`);
ALTER TABLE `hq_unposted_lines` ADD INDEX `idx_hqul_code` (`item_code`);
