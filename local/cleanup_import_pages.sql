-- =====================================================================
--  مسح صفحتَي الرفع اليدوي — السلف والحضور
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  الصفحتين اتشالوا من الكود:
--    site/advances_import.php    (رفع شيت السلف — كانت مؤقتة لرفعة واحدة)
--    site/attendance_import.php  (رفع شيت كروينجو للحضور)
--
--  الملف ده بيشيل مفتاح الصلاحية اللي بقى ميت بس. مفيش داتا بتتمسح.
--  ينزل **بعد** رفع الكود — مش قبله. لو نزل قبله، الزرار هيختفي من
--  الشاشة عند اللي كانوا بياخدوا المفتاح، والصفحة لسه موجودة. مش خطر.
--
--  🔴 اللي **مابيتمسحش** ومقصود:
--    · عمود `employees.external_id` — معرّف جهاز البصمة. مبقاش ليه قارئ
--      في الكود، بس ده ربط حقيقي بين الموظف والجهاز. سيبه.
--    · جدول `attendance` وكل صفوفه — الحضور اللي اترفع بالشيت قبل كده
--      يفضل زي ما هو.
--    · `employee_advances` — السلف اللي اترفعت تفضل زي ما هي.
-- =====================================================================

-- (0) نسخة احتياطية قبل أي لمسة
DROP TABLE IF EXISTS `user_permissions_backup_import_cleanup`;
CREATE TABLE `user_permissions_backup_import_cleanup`
  AS SELECT * FROM `user_permissions`;

-- (1) شوف مين بياخد المفتاح دلوقتي (للسجل قبل المسح)
SELECT u.`id`, u.`username`, u.`full_name`
  FROM `user_permissions` up
  JOIN `users` u ON u.`id` = up.`user_id`
 WHERE up.`module_key` = 'hr:attendance_import';

-- (2) امسح المفتاح الميت
DELETE FROM `user_permissions` WHERE `module_key` = 'hr:attendance_import';

-- (3) وامسح الاسم القديم كمان لو لسه فاضل من قبل تجزئة الصلاحيات
DELETE FROM `user_permissions` WHERE `module_key` = 'attendance_import';

-- (4) الفحص — الاتنين لازم يرجّعوا صفر
SELECT 'hr:attendance_import (لازم صفر)' AS `الفحص`,
       COUNT(*) AS `العدد`
  FROM `user_permissions` WHERE `module_key` = 'hr:attendance_import'
UNION ALL
SELECT 'attendance_import (لازم صفر)', COUNT(*)
  FROM `user_permissions` WHERE `module_key` = 'attendance_import';
