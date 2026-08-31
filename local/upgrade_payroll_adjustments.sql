-- =====================================================================
--  مسوّدة تعديلات الرواتب — `payroll_adjustments`
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  المشكلة: في شاشة الرواتب المستخدم بيعدّل ٦ خانات، وزرار «حفظ
--  التعديلات» كان بيحفظ **المكافآت وبس**. الخمسة التانية مالهاش
--  مكان تخزين خالص — كانت بتتقرا وقت الصرف بس، فالصفحة تترستر
--  والتعديل يضيع.
--
--  الجدول ده بيخزّن **مسوّدة** التعديل قبل الصرف. مش سجل مالي:
--  مافيش فلوس بتتحرك منه، وبيتمسح أول ما المرتب يتصرف فعلاً.
--
--  🔴 كل الأعمدة `NULL` تعني «مفيش تعديل — استخدم المحسوب».
--     و`0.00` تعني «المستخدم قال صفر عن قصد».
--     الاتنين **مش نفس الحاجة** ولازم يفضلوا متفرقين.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `employee_id`   INT NOT NULL,
  `month`         CHAR(7) NOT NULL COMMENT 'YYYY-MM',

  -- الخمس خانات. NULL = مفيش تعديل.
  `leave_days`    INT            NULL COMMENT 'أيام الإجازة',
  `due_days`      DECIMAL(10,2)  NULL COMMENT 'أيام الاستحقاق',
  `monthly_take`  DECIMAL(12,2)  NULL COMMENT 'المخصوم من سلف الشهر',
  `standing_take` DECIMAL(12,2)  NULL COMMENT 'المخصوم من السلف المستديمة',
  `cashier_take`  DECIMAL(12,2)  NULL COMMENT 'المخصوم من عجز الكاشير',

  `updated_by`    INT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- صف واحد بس لكل موظف في الشهر — الحفظ بيبقى UPSERT
  UNIQUE KEY `uq_payadj_emp_month` (`employee_id`, `month`),
  KEY `ix_payadj_month` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الفحص — لازم يرجّع الجدول بأعمدته
SHOW COLUMNS FROM `payroll_adjustments`;
