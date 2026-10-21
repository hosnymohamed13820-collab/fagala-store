# تقرير بريف ١٦٨ — `users_api.php` مرحلة (أ): القراية بس

## ١. ملخص التنفيذ وجرد العمليات
تم بحمد الله استخراج استعلامات ودوال إدارة المستخدمين من `site/users.php` إلى مكتبة `site/users_lib.php` وإنشاء واجهة البرمجة `site/users_api.php` للقراءة فقط (المرحلة أ):

### جرد عمليات الكتابة المستبعدة من المرحلة (أ) (متروكة لمرحلة ب بقرار كلود):
| العملية | الحماية في الصفحة | ملاحظات | في المرحلة (أ)؟ |
|---|---|---|---|
| `add` (إضافة مستخدم جديد) | `is_owner()` + CSRF | تنشئ يوزر وموظف وتلمس الصلاحيات والمرتب | ❌ مستبعدة (مرحلة ب) |
| `edit` (تعديل مستخدم) | `is_owner()` + CSRF | تعدل بيانات الموظف والفرع والصلاحيات والمرتب | ❌ مستبعدة (مرحلة ب) |
| `toggle` (تفعيل/إيقاف) | `is_owner()` + CSRF | تعدل `is_active` وتمنع إيقاف الحساب الذاتي | ❌ مستبعدة (مرحلة ب) |
| `bulk_perm` (تطبيق صلاحية بالجملة) | `is_owner()` + CSRF | تضيف أو تزيل صلاحية من عدة مستخدمين | ❌ مستبعدة (مرحلة ب) |
| `save_role` (إنشاء/تعديل دور) | `is_owner()` + CSRF | تنشئ وتعدل أدوار وصلاحياتها في `role_permissions` | ❌ مستبعدة (مرحلة ب) |
| `delete_role` (حذف دور) | `is_owner()` + CSRF | تحذف الدور وتمنع حذف أدوار مرتبطة بمستخدمين | ❌ مستبعدة (مرحلة ب) |

### دوال `site/users_lib.php` المستخرجة:
1. `sanitize_user_permissions()`: تنقية الصلاحيات والتحقق منها وتطبيق قاعدة «كامل ولا أجزاء».
2. `user_extra_permissions()`: حساب الصلاحيات الزائدة الممنوحة للمستخدم فوق صلاحيات دوره.
3. `users_get_list()`: جلب قائمة المستخدمين مع الأدوار والفروع والموظفين.
4. `users_get_perms_map()`: جلب خريطة الصلاحيات لكل مستخدم.
5. `users_get_roles()`: جلب قائمة الأدوار وأعداد المستخدمين النشطين والكل لكل دور.
6. `users_get_role_perms_map()`: جلب خريطة صلاحيات الأدوار من `role_permissions`.
7. `users_get_branches()`: جلب قائمة الفروع النشطة.

### أجزاء الـ API في `site/users_api.php`:
- `?part=users`: جلب المستخدمين وصلاحياتهم والصلاحيات الزيادة، مع حجب `base_salary` عند قفل الأرقام الحساسة، والتأكد التام من عدم تسريب أي تجزئة لكلمات المرور أو أرقام إصدار الصلاحيات.
- `?part=roles`: جلب الأدوار وصلاحيات كل دور.
- `?part=meta`: جلب `all_modules()` و `module_parts()` والفروع النشطة.

---

## ٢. نتائج اختبارات العقد (Contract Completeness)

```text
=== Brief 168 Contract Tests ===

--- Case 1: Baseline Page Renders ---
MATCH: users_locked (exact 0 diff, length 274288)
MATCH: users_unlocked (exact 0 diff, length 275083)
MATCH: roles_locked (exact 0 diff, length 110218)
MATCH: roles_unlocked (exact 0 diff, length 110217)
ALL 4 RENDERS MATCHED 100% WITH 0 DIFF
PASS: Case 1: Baseline renders match 100% with 0 diff in all 4 combinations

--- Case 2: users · roles · meta with owner match page counts ---
PASS: GET part=users status 200
PASS: users count matches page (44)
PASS: GET part=roles status 200
PASS: roles count matches page (6)
PASS: GET part=meta status 200
PASS: meta branches count matches page (6)
PASS: meta modules exist

--- Case 3: users with numbers locked ---
PASS: No base_salary key in any row when locked

--- Case 4: users with numbers unlocked ---
PASS: base_salary key present when unlocked
PASS: base_salary matches employees.base_salary

--- Case 5: No password_hash or pw_version in response ---
PASS: No password_hash in response
PASS: No pw_version in response
PASS: No perms_version in response

--- Case 6: Non-owner user returns 403 ---
PASS: Non-owner user -> 403 Forbidden

--- Case 7: ashraqa -> 403, POST users -> 405, part=edit -> 400 ---
PASS: ashraqa user -> 403 Forbidden
PASS: POST on ?part=users -> 405 Method Not Allowed
PASS: Unknown part=edit -> 400 Bad Request

=============================================
>>> ALL 7 BRIEF 168 CONTRACT CASES PASSED! <<<
=============================================
```

---

## ٣. التحقق من الكود وسلامة التغييرات
- فحص الدوال المحذوفة من الصفحة:
  `grep -c "function sanitize_user_permissions\|function user_extra_permissions" site/users.php` ← **0**.
- حالة المستودع:
```text
 M site/users.php
?? site/users_api.php
?? site/users_lib.php
```
- لم يتم لمس `local/` أو تشغيل `run_all.sh` أو استخدام أي وكلاء فرعيين.
