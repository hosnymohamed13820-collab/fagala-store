-- =====================================================================
--  تجزئة الصلاحيات — شيل الأسماء المستعارة
--  الفجالة ستور · ٣٠ أغسطس ٢٠٢٦
--
--  🔴 الملف ده لازم ينزل **قبل** رفع الكود.
--     الكود الجديد بيلغي الاسمين المستعارين في auth.php:
--       remote_scan       → price_inquiry
--       attendance_import → hr
--     لو الكود نزل الأول، كل اللي بياخد السكانر بالاسم المستعار هيفقده.
-- =====================================================================

-- (0) نسخة احتياطية قبل أي لمسة
DROP TABLE IF EXISTS `user_permissions_backup_perms_split`;
CREATE TABLE `user_permissions_backup_perms_split`
  AS SELECT * FROM `user_permissions`;

-- (1) السكانر: كل واحد بياخده النهاردة بالاسم المستعار (يعني معاه price_inquiry)
--     ياخد المفتاح الحقيقي remote_scan صراحةً. مفيش حد بيفقد حاجة.
INSERT INTO `user_permissions` (`user_id`, `module_key`)
SELECT up.`user_id`, 'remote_scan'
FROM `user_permissions` up
WHERE up.`module_key` = 'price_inquiry'
  AND NOT EXISTS (
        SELECT 1 FROM `user_permissions` x
        WHERE x.`user_id` = up.`user_id` AND x.`module_key` = 'remote_scan'
      );

-- (2) رفع شيت الحضور: كان مديول مستقل، بقى جزء جوّه الموارد البشرية.
--     الصفوف القديمة كانت بلا أثر (الاسم المستعار كان بيحوّلها لـhr)،
--     بس اللي اتحطّت له في الشاشة كان المقصود إنه ياخدها — فبنحترم النية.
INSERT INTO `user_permissions` (`user_id`, `module_key`)
SELECT up.`user_id`, 'hr:attendance_import'
FROM `user_permissions` up
WHERE up.`module_key` = 'attendance_import'
  AND NOT EXISTS (
        SELECT 1 FROM `user_permissions` x
        WHERE x.`user_id` = up.`user_id` AND x.`module_key` = 'hr:attendance_import'
      );

-- (3) المفتاح القديم بقى غير معروف لـsanitize_user_permissions — يتشال.
DELETE FROM `user_permissions` WHERE `module_key` = 'attendance_import';

-- ملحوظة: اللي معاه 'hr' كامل مايحتاجش صف جديد —
-- can('hr:attendance_import') بترجّع true للمفتاح الكامل تلقائياً.

-- (4) فحص بعد التنفيذ
SELECT 'السكانر' AS البند, COUNT(*) AS العدد FROM `user_permissions` WHERE `module_key`='remote_scan'
UNION ALL
SELECT 'رفع الشيت', COUNT(*) FROM `user_permissions` WHERE `module_key`='hr:attendance_import'
UNION ALL
SELECT 'مفاتيح قديمة (لازم صفر)', COUNT(*) FROM `user_permissions` WHERE `module_key`='attendance_import';
