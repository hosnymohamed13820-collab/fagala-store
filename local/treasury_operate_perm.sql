-- =====================================================================
-- إضافة صلاحية حركة الفلوس في الخزينة: treasury:operate (٢٣ سبتمبر ٢٠٢٦)
-- 🔴 شغّله من phpMyAdmin قبل رفع الملفات — على قاعدة الفجالة وإشراقة الاتنين
--
-- السبب: قبل التعديل ده، أي حد معاه treasury:statement («الخزينة وكشف الحساب»)
-- كان يقدر يصرف ويستلم ويحوّل. تم فصل حركة الفلوس في صلاحية مستقلة
-- treasury:operate.
-- لتفادي قفل الصرف والاستلام على المحاسبين فجأة، كل مستخدم معاه
-- treasury:statement حالياً بياخد treasury:operate أوتوماتيكياً.
-- بعدها يقدر المالك يدخل شاشة الصلاحيات ويشيلها ممن يريده أن يشاهد فقط.
--
-- ملاحظة:
-- ١) المستخدم الذي لديه 'treasury' كامل لا يحتاج صفاً هنا لأن can()
--    في auth.php تعدّيه تلقائياً لأي جزء فرعي.
-- ٢) المالك (is_owner = 1) لا يحتاج صفاً لأن can() ترجع true دائماً.
-- ٣) لا يوجد أي أمر DELETE في هذا السكربت.
-- =====================================================================

-- ١) مين هياخدها — بص عليهم الأول
SELECT p.user_id, u.full_name
  FROM user_permissions p JOIN users u ON u.id = p.user_id
 WHERE p.module_key = 'treasury:statement';

-- ٢) الجزء الجديد لكل اللي بيشوف الخزينة النهارده — يوم واحد مفيش حاجة بتتغيّر
INSERT IGNORE INTO user_permissions (user_id, module_key)
SELECT DISTINCT user_id, 'treasury:operate'
  FROM user_permissions
 WHERE module_key = 'treasury:statement';

-- ٣) الجلسة المفتوحة تقرا الصلاحيات الجديدة من غير خروج ودخول
UPDATE users SET perms_version = perms_version + 1
 WHERE id IN (SELECT user_id FROM (
        SELECT DISTINCT user_id FROM user_permissions
         WHERE module_key = 'treasury:operate'
       ) x);

