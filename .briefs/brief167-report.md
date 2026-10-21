# تقرير بريف ١٦٧ — `settings_api.php` (الإعدادات): القراية + عمليتين للمالك

## ١. ملخص التنفيذ
تم بحمد الله استخراج وتفويض منطق صفحة الإعدادات `site/settings.php` إلى مكتبة `site/settings_lib.php` وإنشاء الـ API الموحد `site/settings_api.php`:
1. **`site/settings_lib.php`**:
   - `settings_schema()`: مصفوفة الإعدادات القابلة للتعديل والمفاتيح المسموح بها في الواجهة.
   - `settings_get_values()`: جلب قيم الإعدادات مع الحفاظ على الأمان (عدم إرجاع المفاتيح الخاصة أو كلمات المرور).
   - `settings_users()`: جلب قائمة المستخدمين النشطين لحقول الإعدادات من نوع مستخدم.
   - `settings_ds_users()`: جلب قائمة المستخدمين المصرح لهم باستلام الملخص اليومي مرتبة بالأونر أولاً ثم الاسم.
   - `settings_agents_info()`: جلب حالة وكلاء المزامنة من `agent_lib.php` والعتبات ووقت المراقبة.
   - `settings_guard_invoices()`: جلب الفواتير لحارس رصيد الإدارة مع فحص وجود العمود `hq_guard_off`.
   - `settings_set_invoice_guard()`: تحديث فك حارس رصيد الإدارة لفاتورة مع تسجيل المستخدم والتاريخ والتحقق من رقم الفاتورة.
   - `settings_save_daily_summary()`: حفظ إعدادات الملخص اليومي مع فلترة معرّفات المستخدمين وحصر الساعة بين ١٨ و٢٣ أو ٢٤.

2. **`site/settings.php`**:
   - تفويض جميع العمليات لـ `settings_lib.php`.
   - الحفاظ على تطابق الرندر ١٠٠٪ بنتيجة 0 diff.

3. **`site/settings_api.php`**:
   - حماية الصفحة بالكامل بـ `require_login()` و `api_require('settings')`.
   - دعم ٤ أجزاء GET: `values`, `users`, `agents`, `guard_invoices`.
   - دعم عمليتي POST للمالك الحقيقي فقط (`is_owner()`): `invoice_guard`, `daily_summary_save` مع التحقق من CSRF و Idempotency.

4. **جرد العمليات المستبعدة (حسب نص البريف):**
   - `change_password`: مستبعدة (تغيير كلمة المرور وتدوير `pw_version` سيتم تقريرها مع نظام الصلاحيات).
   - الحفظ العام المالي (`$editable`): مستبعدة لاحتوائها على مفاتيح مالية تخص الموجة ٣.

---

## ٢. نتائج اختبارات العقد (Contract Completeness)

```text
=== Brief 167 Contract Tests ===

--- Case 1: Baseline Page Renders ---
MATCH: owner (exact 0 diff after time normalization, length 529357)
MATCH: user_settings (exact 0 diff after time normalization, length 116916)
PASS: Case 1: Baseline renders match 100% with 0 diff

--- Case 2: The 4 GET parts with owner ---
PASS: GET part=values status 200
PASS: GET part=values data matches page/lib
PASS: GET part=users status 200
PASS: GET part=users data matches page/lib
PASS: GET part=agents status 200
PASS: GET part=agents data matches agent_lib
PASS: GET part=guard_invoices status 200
PASS: GET part=guard_invoices data matches page/lib

--- Case 3: Both POST parts without CSRF return 403 ---
PASS: POST invoice_guard without CSRF -> 403
PASS: POST daily_summary_save without CSRF -> 403

--- Case 4: Both POST parts with non-owner return 403 ---
PASS: POST invoice_guard with non-owner -> 403
PASS: POST daily_summary_save with non-owner -> 403

--- Case 5: daily_summary_save filter + idempotency + revert ---
PASS: POST daily_summary_save status 200
PASS: daily_summary_save clamped hour 5 to 18
PASS: daily_summary_save filtered users 99999
PASS: daily_summary_save idempotency returned identical response

--- Case 6: invoice_guard off=1 + revert ---
PASS: POST invoice_guard status 200
PASS: invoice_guard updated DB to off=1
PASS: invoice_guard cleanly reverted

--- Case 7: invoice_guard with dist_id=0 returns 422 ---
PASS: POST invoice_guard dist_id=0 -> 422

--- Case 8: ashraqa -> 403, GET invoice_guard -> 405, change_password -> 400 ---
PASS: Ashraqa user without settings perm -> 403
PASS: GET ?part=invoice_guard -> 405 Method Not Allowed
PASS: GET/POST ?part=change_password -> 400 Bad Request

=============================================
>>> ALL 8 BRIEF 167 CONTRACT CASES PASSED! <<<
=============================================
```

---

## ٣. التحقق من الكود وسلامة التغييرات
- فحص الكلمات المحظورة: `grep -n "change_password\|editable" site/settings_api.php` ← **0 نتائج**.
- حالة المستودع:
```text
 M site/settings.php
?? site/settings_api.php
?? site/settings_lib.php
```
- تم الالتزام الكامل بعدم لمس `local/` أو تشغيل `run_all.sh` أو استخدام وكلاء فرعيين.
- تم استعادة قاعدة البيانات إلى حالتها الأولية فوراً بعد انتهاء الاختبارات والتأكد من ذلك.
