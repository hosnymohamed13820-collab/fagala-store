-- =====================================================================
-- فك «الاستلامات» عن «فواتير الفروع» (٢٢ سبتمبر ٢٠٢٦)
-- 🔴 شغّله من phpMyAdmin **قبل** رفع الملفات — من غيره عاشور بيتقفل عليه.
--
-- اللي كان معاه جزء فرع من الاستلامات (invoices:ashraqa وإخواته) بياخد
-- موديول «فواتير الفروع» بداله، والأجزاء القديمة بتتشال (مبقاش ليها
-- مكان في شاشة الصلاحيات). الموديول بيفتحله فرعه من users.branch_id —
-- 🔴 اتأكد إن عاشور متسجّل على إشراقة (هو كده في الشاشة).
-- «مشاكل الفواتير» (invoices:problems) ما بتتلمسش.
-- =====================================================================

-- ١) مين هيتأثر — بص عليهم الأول
SELECT p.user_id, u.full_name, u.branch_id, p.module_key
  FROM user_permissions p JOIN users u ON u.id = p.user_id
 WHERE p.module_key IN ('invoices:ashraqa','invoices:fagala','invoices:degla','invoices:bonjour','invoices:abc');

-- ٢) الموديول الجديد بدل الأجزاء
INSERT IGNORE INTO user_permissions (user_id, module_key)
SELECT DISTINCT user_id, 'branch_invoices'
  FROM user_permissions
 WHERE module_key IN ('invoices:ashraqa','invoices:fagala','invoices:degla','invoices:bonjour','invoices:abc');

-- ٣) الجلسة المفتوحة تقرا الصلاحيات الجديدة من غير خروج ودخول
UPDATE users SET perms_version = perms_version + 1
 WHERE id IN (SELECT user_id FROM (
        SELECT DISTINCT user_id FROM user_permissions
         WHERE module_key IN ('invoices:ashraqa','invoices:fagala','invoices:degla','invoices:bonjour','invoices:abc')
       ) x);

-- ٤) شيل الأجزاء القديمة
DELETE FROM user_permissions
 WHERE module_key IN ('invoices:ashraqa','invoices:fagala','invoices:degla','invoices:bonjour','invoices:abc');
