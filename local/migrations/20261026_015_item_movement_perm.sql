-- =====================================================================
--  صلاحية تقرير حركة الصنف لليوزرز والرولز اللي معاهم كارت الصنف
-- ---------------------------------------------------------------------
--  بريف ٢٢٦: تحويل كارت الصنف لتقرير «حركة الصنف» (reports:item_movement).
--  كل يوزر ورول عنده صلاحية item_card ياخد reports:item_movement بس.
--  🔴 مش 'reports' كاملة: الموديول الكامل wildcard بيفتح كل التقارير (الخزينة والمرتبات…)،
--  والجزء لوحده كفاية يفتح reports.php (can_module).
-- =====================================================================

-- ١) user_permissions: إضافة reports:item_movement لكل يوزر عنده item_card
INSERT IGNORE INTO user_permissions (user_id, module_key)
SELECT user_id, 'reports:item_movement'
  FROM user_permissions
 WHERE module_key = 'item_card';

-- ٢) role_permissions: إضافة reports:item_movement لكل رول عنده item_card
INSERT IGNORE INTO role_permissions (role_id, module_key)
SELECT role_id, 'reports:item_movement'
  FROM role_permissions
 WHERE module_key = 'item_card';

-- ٣) تسجيل الترقية
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261026_015_item_movement_perm');
