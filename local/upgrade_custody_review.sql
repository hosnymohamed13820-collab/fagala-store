-- =====================================================================
--  upgrade_custody_review.sql — إقفال العهدة على مرحلتين (كاشير ← محاسب)
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  الهدف:
--    العهدة كانت بتتقفل بضغطة واحدة والقيود بتتعمل على طول. بقت زي
--    الوردية بالظبط: الكاشير بيعدّ ويقدّم (مفيش أي قيد)، والمحاسب
--    بيراجع ويعتمد — ووقت الاعتماد بس الفلوس بتتحرّك.
--
--  ★ قرار مهم في التصميم:
--    الحالة النهائية اسمها فضل `closed` زي ما هي — **مش** `approved`.
--    السبب: كل الكود الموجود (`add_custody_expense` · `delete_custody_expense`
--    · التقارير · `open_custody_for_branch`) بيقارن بـ`open` و`closed`.
--    لو غيّرنا اسم الحالة النهائية كان لازم نعدّل كل ده **وكمان** نرحّل
--    الصفوف القديمة. بالشكل ده: **العهد المقفولة قبل كده مالهاش أي ترحيل
--    وقيودها مابتتعادش** — وده أخطر حاجة كانت ممكن تحصل.
--
--  الحالات الأربعة:
--    open            = شغالة، الكاشير بيسجّل مصاريف
--    pending_review  = الكاشير عدّ وقدّم، مستنية المحاسب — **مفيش قيد لسه**
--    rejected        = المحاسب رفض، رجعت للكاشير يظبّط ويقدّم تاني
--    closed          = المحاسب اعتمد، القيود اتعملت والعهدة اتصفّرت
--
--  🔒 آمن للتكرار: كل الأعمدة بـIF NOT EXISTS والجدول بـIF NOT EXISTS،
--     وتعديل الـenum بيكتب نفس التعريف تاني من غير ضرر.
--
--  🔴 لازم ينزل **مع** تعديل الـPHP في نفس الوقت:
--     - SQL من غير كود  → الكاشير هيقفل والقيد هيتعمل على طول (زي دلوقتي).
--     - كود من غير SQL  → التقفيل هيقع، الحالة الجديدة مش في الـenum.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
--  🔴 متعملش أي استعلام ALTER TABLE أو CREATE TABLE من كود PHP أبداً.
-- =====================================================================

-- (١) الحالات الجديدة. الصفوف الموجودة (`open` أو `closed`) مابتتغيّرش.
ALTER TABLE `branch_custody`
  MODIFY COLUMN `status` ENUM('open','pending_review','rejected','closed')
  NOT NULL DEFAULT 'open'
  COMMENT 'open=شغالة · pending_review=مقدّمة للمحاسب · rejected=مرفوضة راجعة للكاشير · closed=معتمدة والقيود اتعملت';

-- (٢) بيانات التقديم — مين قدّم العدّ وإمتى، ومين الموظف المسؤول عن العجز.
--     الموظف المسؤول بقى لازم يتخزّن دلوقتي لأن تسجيل العجز اتأجّل
--     لوقت الاعتماد، والكاشير هو اللي بيحدده وقت التقديم.
ALTER TABLE `branch_custody`
  ADD COLUMN IF NOT EXISTS `responsible_employee_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'الموظف اللي الكاشير حدده مسؤول عن العجز — بيتسجّل عليه وقت الاعتماد',
  ADD COLUMN IF NOT EXISTS `submitted_by` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'الكاشير اللي قدّم العدّ',
  ADD COLUMN IF NOT EXISTS `submitted_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'وقت التقديم — مش وقت الاعتماد',
  ADD COLUMN IF NOT EXISTS `accountant_counted` DECIMAL(14,2) NULL DEFAULT NULL
    COMMENT 'الرقم اللي المحاسب استلمه فعلاً — ده اللي بيتعمل بيه القيد، مش رقم الكاشير',
  ADD COLUMN IF NOT EXISTS `reject_reason` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'سبب الرفض — بيظهر للكاشير',
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'المحاسب اللي راجع',
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL DEFAULT NULL;

-- (٣) فهرس على (الفرع، الحالة) — الشاشات بتدوّر بيهم في كل تحميل.
ALTER TABLE `branch_custody`
  ADD INDEX IF NOT EXISTS `idx_branch_status` (`branch_id`, `status`);

-- (٤) سجل المراجعات — نفس مبدأ `shift_reviews`: كل محاولة مراجعة
--     بتتسجّل صف، فلو اترفضت واتقدّمت تاني السجل كله موجود.
CREATE TABLE IF NOT EXISTS `custody_reviews` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `custody_id`         BIGINT UNSIGNED NOT NULL,
  `expected`           DECIMAL(14,2) NOT NULL DEFAULT 0.00
                       COMMENT 'آخر المدة المفروض وقت المراجعة = closing_balance',
  `cashier_counted`    DECIMAL(14,2) NOT NULL DEFAULT 0.00
                       COMMENT 'اللي الكاشير عدّه وقدّمه',
  `accountant_counted` DECIMAL(14,2) NOT NULL DEFAULT 0.00
                       COMMENT 'اللي المحاسب استلمه فعلاً',
  `variance`           DECIMAL(14,2) NOT NULL DEFAULT 0.00
                       COMMENT 'رقم المحاسب ناقص المفروض — سالب = عجز، موجب = زيادة',
  `decision`           ENUM('approved','rejected') NOT NULL,
  `reason`             VARCHAR(255) DEFAULT NULL,
  `reviewed_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_custody` (`custody_id`),
  KEY `idx_decision` (`decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  فحص بعد التنفيذ — الناتج المتوقع مكتوب جنب كل استعلام
-- =====================================================================

-- لازم تشوف الحالات الأربعة في التعريف:
-- SHOW COLUMNS FROM `branch_custody` LIKE 'status';

-- لازم يفضل زي ما هو — مفيش عهدة قديمة اتغيّرت حالتها:
-- SELECT status, COUNT(*) FROM `branch_custody` GROUP BY status;

-- لازم يرجّع صفر — مفيش عهدة اتعلّقت في حالة وسط بالغلط:
-- SELECT COUNT(*) FROM `branch_custody`
--  WHERE status IN ('pending_review','rejected') AND submitted_at IS NULL;
