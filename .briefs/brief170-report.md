# تقرير بريف ١٧٠ — `attendance_api.php` مرحلة (أ): القراية بس (حضوري وسجلّي)

## ١. ملخص التنفيذ وجرد العمليات
تم بحمد الله استخراج حسابات واستعلامات الحضور والانصراف من `site/attendance.php` إلى مكتبة `site/attendance_lib.php` وإنشاء واجهة البرمجة `site/attendance_api.php` للقراءة فقط (المرحلة أ):

### الموظف الذي تم اختباره:
- المستخدم: **محمود ربيع** (`user_id = 4`)
- الموظف: **#1** (`employee_id = 1`)
- إجمالي سجلات الحضور بقاعدة البيانات: **67 سجلاً**.
- كما تم اختبار المستخدم بدون موظف عبر حساب المالك (`user_id = 1`).

### جرد عمليات الكتابة المستبعدة من المرحلة (أ) (متروكة لمرحلة ب بقرار كلود):
| العملية | الحماية في الصفحة | ملاحظات | في المرحلة (أ)؟ |
|---|---|---|---|
| `clock_in` (تسجيل حضور) | `attendance` + CSRF + موظف نشط | استدعاء `clock_attendance` مع فحص النطاق الجغرافي (`evaluate_geofence`) وتحديد ساعات المرتب | ❌ مستبعدة (مرحلة ب) |
| `clock_out` (تسجيل انصراف) | `attendance` + CSRF + موظف نشط | استدعاء `clock_attendance` مع فحص النطاق الجغرافي وحساب ساعات العمل وإغلاق الوردية | ❌ مستبعدة (مرحلة ب) |
| `request_fix` (طلب تصحيح) | `attendance` + CSRF + موظف نشط | إدراج طلب في `attendance_requests` بحالة `pending` للاعتماد من الموارد البشرية | ❌ مستبعدة (مرحلة ب) |

### دوال `site/attendance_lib.php` المستخرجة:
1. `attendance_calc_period()`: حساب فترة سجل الحضور (من/إلى أو شهر أو الافتراضي من ٢٦ الشهر الماضي لـ ٢٥ الشهر الحالي مع نقل التوثيق بالكامل).
2. `attendance_get_active_date()`: تحديد اليوم الشغال الفعلي مع فحص الورديات التي تعبر منتصف الليل قبل `business_day_start_hour()`.
3. `attendance_get_today()`: جلب سجل حضور اليوم للموظف المحدد.
4. `attendance_get_period()`: جلب سجلات حضور الموظف خلال الفترة المطلوبة مرتبة تنازلياً.
5. `attendance_get_my_employee()`: جلب بيانات موظف المستخدم مع اسم الفرع والحقول الآمنة فقط.

### أجزاء الـ API في `site/attendance_api.php`:
- `?part=me`: بيانات الموظف الأساسية (الاسم، الفرع، المتوقع حضور، المتوقع انصراف، السماح بأي مكان) مع استبعاد حقول: `base_salary`, `expected_monthly_hours`, `salary_mode`, `national_id`.
- `?part=today`: سجل حضور اليوم واليوم الفعلي وحالة الإكمال عبر منتصف الليل.
- `?part=period`: سجلات الفترة (تاريخ البداية والنهاية والصفوف).
- دعم الحسابات غير المربوطة بموظف بإرجاع `employee: null` وسجلات فارغة دون خطأ.
- تجاهل بارامتر `employee_id` لحصر العرض على المستخدم الحالي فقط.

---

## ٢. نتائج اختبارات العقد (Contract Completeness)

```text
=== Brief 170 Contract Tests ===

--- Case 1: Baseline Page Renders (0 diff) ---
MATCH: no_params (exact 0 diff, length 58123)
MATCH: month (exact 0 diff, length 60216)
MATCH: from_to (exact 0 diff, length 60236)
ALL 3 RENDERS MATCHED 100% WITH 0 DIFF
PASS: Case 1: Baseline renders match 100% with 0 diff

--- Case 2: today & period with same parameters ---
PASS: GET part=today status 200
PASS: today matches page/lib data
PASS: GET part=period&month=2026-09 status 200
PASS: period rows count matches page (1)
PASS: period rows data matches page

--- Case 3: period without parameters ---
PASS: GET part=period status 200
PASS: from is 26th of last month (2026-09-26)
PASS: to is 25th of this month (2026-10-25)

--- Case 4: me fields security check ---
PASS: GET part=me status 200
PASS: me has full_name
PASS: me has expected_in
PASS: me has expected_out
PASS: me has allow_anywhere
PASS: me has NO base_salary
PASS: me has NO expected_monthly_hours
PASS: me has NO salary_mode
PASS: me has NO national_id

--- Case 5: employee_id parameter is ignored ---
PASS: GET with employee_id=17 status 200
PASS: returned data is identical to user 4's data

--- Case 6: User without employee ---
PASS: no_emp part=me returns ok: true and employee: null
PASS: no_emp part=today returns ok: true and today: null
PASS: no_emp part=period returns ok: true and period: []

--- Case 7: ashraqa -> 403, POST -> 405, part=clock_in -> 400 ---
PASS: ashraqa user without attendance perm -> 403 Forbidden
PASS: POST method -> 405 Method Not Allowed
PASS: part=clock_in -> 400 Bad Request

=============================================
>>> ALL 7 BRIEF 170 CONTRACT CASES PASSED! <<<
=============================================
```

---

## ٣. التحقق من الكود وسلامة التغييرات
- فحص الكلمات المحظورة: `grep -n "INSERT\|UPDATE\|clock_attendance" site/attendance_api.php` ← **0 نتائج**.
- حالة المستودع:
```text
 M site/attendance.php
?? site/attendance_api.php
?? site/attendance_lib.php
```
- لم يتم لمس `local/` أو `ashraqa/` أو `finance.php`.
- لم يتم تشغيل `run_all.sh` ولم يتم استخدام أي وكلاء فرعيين.
