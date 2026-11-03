-- إشراقة: منح صلاحية استعلام الأسعار (price_inquiry) لكل المستخدمين النشطين (غير الأونر).
-- على إشراقة ٧ مستخدمين كاشير نشطين، ٦ منهم معاهم الصلاحية في user_permissions، والمستخدم رقم ٥ (username '2') كان ناقصه.
-- على الفجالة: الـSELECT مش هيرجّع حاجة بفضل شرط (DATABASE() LIKE '%ashraqa%')، فالملف no-op وبيسجّل نفسه في schema_migrations عادي بدون ما يضيف صلاحيات لمستخدمي الفجالة.

INSERT IGNORE INTO user_permissions (user_id, module_key)
SELECT u.id, 'price_inquiry'
FROM users u
JOIN roles r ON r.id = u.role_id
WHERE u.is_active = 1
  AND COALESCE(r.is_owner, 0) = 0
  AND DATABASE() LIKE '%ashraqa%';

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261103_050_ashraqa_price_inquiry_all');
