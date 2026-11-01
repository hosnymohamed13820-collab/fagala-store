-- محمد ١/١١/٢٠٢٦: محمود ربيع (users.id=4) يبقى أونر بصلاحيات المالك بالظبط، بدل «مشتريات + مشاهدة بس».
-- الأونر مابيتعملش من users.php (قرار قديم)، فده التعديل الوحيد.
-- الرجوع: UPDATE users SET role_id = 5 WHERE id = 4;  — صلاحياته القديمة في user_permissions زي ما هي (فيها view_all).
UPDATE users SET role_id = (SELECT id FROM roles WHERE is_owner = 1 ORDER BY id LIMIT 1) WHERE id = 4 AND full_name = 'محمود ربيع';
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261101_035_mahmoud_rabie_owner');
