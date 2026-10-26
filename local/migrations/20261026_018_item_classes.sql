-- بريف ٢٣٤ — موديول الأقسام والتصنيفات (قرار محمد ٤٩).
--
-- كارت الصنف في True فيه ٥ تصنيفات: القسم · الوصف · النوع · المرحلة · الشركات.
-- المرآة فيها القسم بس (hq_cat_names.is1_id/name)، فبيتنسخ هنا مرة واحدة كنوع
-- 'section' — وأي قسم/وصف/نوع/مرحلة/شركة جديدة من بعد كده بتتضاف من شاشة
-- «الأقسام والتصنيفات» (item_classes.php) وبتتخزن عندنا.
--
-- الصف اللي true_id بتاعه مش NULL = جاي من True = عرض بس (مش بيتعدّل ولا بيتوقف
-- من الموقع). اللي true_id بتاعه NULL = صنف الموقع = كامل الصلاحيات.
--
-- آمن للتكرار:
--   * CREATE TABLE IF NOT EXISTS  → الجدول مايتعملش تاني.
--   * INSERT IGNORE               → أقسام True ماتتكررش (unique على kind+name)،
--                                   فالتشغيل التاني بيضيف صفر صفوف.
--   * UPDATE بيشتغل **مرة واحدة بس**: شرطه إن سطر الملف ده لسه مش مسجّل في
--     schema_migrations، والتسجيل آخر سطر في الملف. يعني أول تشغيل بيحوّل ثم
--     بيسجّل، وأي تشغيل بعده بيلاقي السطر موجود فيتخطّى التحويل خالص.
--
-- 🔴 ليه مش حارس «الصف اللي cat_is1_id بتاعه بقى item_classes.id»
--    (LEFT JOIN item_classes cur … WHERE cur.id IS NULL)؟
--    لأنه بيغلط في **أول** تشغيل: أرقام item_classes.id بتبدأ من 1، ولو صنف
--    موقع cat_is1_id بتاعه (يعني is1_id من True) بالصدفة ساوي id موجود في
--    item_classes من نوع section، الحارس بيفتكره «اتحوّل خلاص» ويتخطّاه —
--    والصنف يفضل بيشاور على قسم غلط ومحدش بياخد باله.
--    الحارس بالـschema_migrations مابيغلطش لأنه بيسأل «الملف اتشغّل ولا لأ»،
--    مش بيستنتج من شكل الداتا.

CREATE TABLE IF NOT EXISTS item_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    true_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_classes_kind_name (kind, name),
    KEY idx_item_classes_kind_active (kind, is_active),
    KEY idx_item_classes_kind_true (kind, true_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO item_classes (kind, name, true_id, is_active)
SELECT 'section', cn.name, cn.is1_id, 1
  FROM hq_cat_names cn
 WHERE cn.name IS NOT NULL AND cn.name <> '';

-- تحويل أصناف الموقع اللي كانت بتشاور على hq_cat_names.is1_id لـitem_classes.id.
-- بيشتغل مرة واحدة بس (شرط الـschema_migrations في الآخر) — والتسجيل آخر أمر في الملف.
UPDATE item_master m
  JOIN item_classes c ON c.kind = 'section' AND c.true_id = m.cat_is1_id
   SET m.cat_is1_id = c.id
 WHERE m.cat_is1_id IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM schema_migrations sm
         WHERE sm.name = '20261026_018_item_classes'
   );

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261026_018_item_classes');
