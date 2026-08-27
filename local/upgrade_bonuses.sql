-- =====================================================================
--  upgrade_bonuses.sql — إضافة المكافآت لنموذج الرواتب وتوسيع حالات السلف
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  (١) إنشاء جدول `employee_bonuses` (مكافآت الموظفين):
--      - يطابق تماماً هيكل ونمط جدول `employee_penalties` (الجزاءات):
--        id, employee_id, amount, bonus_date, reason, status ('pending','paid'), created_at
--      - الفهارس: PRIMARY KEY على id وفهرس fk_bonus_emp على employee_id.
--      - آمن للتكرار: CREATE TABLE IF NOT EXISTS.
--
--  (٢) إضافة عمود `total_bonuses` لجدول `salaries`:
--      - لتسجيل إجمالي المكافآت المعتمدة والمصروفة مع مسير الرواتب الشهري.
--      - القيمة الافتراضية DEFAULT 0.00 و NOT NULL لضمان سلامة كل السجلات والرواتب السابقة بنسبة ١٠٠٪.
--      - آمن للتكرار: ADD COLUMN IF NOT EXISTS.
--
--  (٣) توسيع عمود `status` في جدول `employee_advances`:
--      - إضافة حالة 'recovered' ليصبح: ENUM('pending','deducted','recovered')
--      - السبب: دالة رد السلفة في finance.php تحدّث الحالة إلى 'recovered' عند سداد السلفة نقداً للخزينة،
--        وكانت القيمة غير موجودة في الـ ENUM الأصلي مما يسبب خطأ في الوضع الصارم (Strict Mode 1265)
--        أو تحويل الحالة إلى نص فارغ بدون الوضع الصارم.
--      - الأمان: آمن تماماً على البيانات الحالية لأن القيم القديمة ('pending', 'deducted') ما زالت صالحة ولم تتغير،
--        فلا تتأثر أي صفوف موجودة.
--
--  🔒 صفر تأثير على أي بيانات ورواتب وسلف سابقة:
--     - الرواتب السابقة تحتفظ بقيمها كما هي مع total_bonuses = 0.00.
--     - صفوف السلف الحالية تظل صحيحة ومقبولة.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
-- =====================================================================

-- (١) جدول مكافآت الموظفين
CREATE TABLE IF NOT EXISTS `employee_bonuses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `bonus_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_bonus_emp` (`employee_id`),
  CONSTRAINT `fk_bonus_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (٢) إضافة إجمالي المكافآت لسجل الرواتب
ALTER TABLE `salaries`
  ADD COLUMN IF NOT EXISTS `total_bonuses` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- (٣) توسيع حالات السلف لتشمل 'recovered' عند السداد النقدي
ALTER TABLE `employee_advances`
  MODIFY `status` ENUM('pending','deducted','recovered') NOT NULL DEFAULT 'pending';
