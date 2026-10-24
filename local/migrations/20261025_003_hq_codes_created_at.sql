-- =====================================================================
--  20261025_003_hq_codes_created_at.sql
--  بريف ٢٠٦ — «حركات النظام»: نوع «صنف جديد»
-- ---------------------------------------------------------------------
--  ليه: hq_codes مكنش فيه وقت إضافة. من غير created_at الصفحة مابتعرفش
--  تفرّق بين صنف اتضاف النهارده وصنف عمره سنين — فكل الأصناف كانت
--  هتبان «جديدة» أول ما العمود يتضاف.
--
--  اللي بيعمله:
--   ١) يضيف العمود created_at (TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP).
--      MySQL بيملا الصفوف القديمة بوقت تشغيل الـALTER نفسه — عشان كده
--      الخطوة (٣) بترجّعهم NULL.
--   ٢) فهرس على العمود — الصفحة بتفلتر بيه على الفترة.
--   ٣) UPDATE يرجّع الصفوف القديمة NULL — **مرة واحدة بس**.
--
--  آمن للتكرار؟
--   - الـALTER والفهرس بيتنفّذوا بس لو مش موجودين (فحص information_schema)،
--     لأن MySQL 8 مافيهوش ADD COLUMN IF NOT EXISTS.
--   - الـUPDATE محروس بـ@had_col: بيتنفّذ بس في التشغيل اللي **ضاف**
--     العمود فعلاً. تاني مرة @had_col = 1 → مفيش UPDATE خالص، فالصنف
--     الجديد اللي دخل بعد الترقية مابيتصفّرش.
--   - مفيش NOW() في شرط التحديث — الشرط على حالة العمود نفسه مش على وقت،
--     فمفيش نافذة زمنية تخلي صنف جديد يتصفّر في تشغيل تاني.
--
--  🔴 نفس الملف بيتشغّل على Laragon (fagala) وعلى السيرفر
--     (u834909335_fagala) — عشان كده مفيش اسم قاعدة ثابت في الملف،
--     وبياخد DATABASE() من القاعدة المختارة في phpMyAdmin.
-- =====================================================================

SET @db := DATABASE();

SET @had_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hq_codes' AND COLUMN_NAME = 'created_at'
);

SET @sql := IF(@had_col = 0,
    'ALTER TABLE `hq_codes` ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @had_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hq_codes' AND INDEX_NAME = 'idx_hq_codes_created'
);

SET @sql := IF(@had_idx = 0,
    'ALTER TABLE `hq_codes` ADD INDEX `idx_hq_codes_created` (`created_at`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- الصفوف القديمة بس — والتشغيل اللي ضاف العمود بس
UPDATE `hq_codes`
   SET `created_at` = NULL
 WHERE @had_col = 0
   AND `created_at` IS NOT NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_003_hq_codes_created_at');
