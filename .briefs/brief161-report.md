# تقرير بريف ١٦١ — `ops_api.php`: المراقبة والتشغيل

- **الفرع:** `ds/brief161` في `D:/fagala_wt_ds`
- **كوميت النسخة:** `8e4434e`
- **كوميت الرئيسي (cherry-pick):** `a8c4a7d`
- **بيئة الاختبار:** `LARAGON_WWW=/d/laragon/www_ds` (قاعدة `fagala`)
- **التاريخ:** 2026-10-21

---

## ١. الجرد — جميع عمليات موديول المراقبة والتشغيل

| # | العملية / الـ part | الطريقة | الدالة في `ops_lib.php` | الصلاحية والحراسة | ملاحظات الرد |
|---|---|:---:|---|---|---|
| ١ | `perf` | GET | `ops_perf_data(?string $day)` | `ops:perf` | جمع وتحليل سجلات السرعة لليوم المختار أو الافتراضي: إجمالي الطلبات، المتوسط العام، الطلبات البطيئة، ومؤشرات p95 |
| ٢ | `audit` | GET | `ops_audit_data(...)` | `ops:audit` + `sens_unlocked()` | جلب سجل العمليات audit مع الفلترة بالتواريخ، المستخدم، الصفحة، والعملية + Pagination. يرفض بـ 423 locked إذا كانت الأرقام مقفولة |
| ٣ | `devices` | GET | `ops_devices_data()` | `ops:devices` | جلب قائمة توكنات الأجهزة المسجلة (api_tokens) مع بيانات المستخدمين وحالة التوكن |
| ٤ | `db` | GET | `ops_db_data()` | `ops:db` | جلب حالة الميجريشنز ومطابقة `migrations_manifest.php` مع جدول `schema_migrations` في القاعدة |
| ٥ | `revoke_token` | POST | `ops_revoke_token(int $id)` | `ops:devices` + `is_owner()` + CSRF + Idempotency | إلغاء توكن جهاز مخصص للأونر فقط عبر تحديث `revoked_at = NOW()` |

- دالة العرض `ops_ms_style()` بقيت في `site/ops.php` كما ينص البريف.
- تصدير الإكسيل `?export=audit` ظل مكانه في `site/ops.php`.

---

## ٢. الديف (`git diff --stat`)

```text
 site/ops.php     | 227 +++-----------------------------------------------------
 site/ops_api.php |  89 ++++++++++++++++++++++
 site/ops_lib.php | 323 ++++++++++++++++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 439 insertions(+), 217 deletions(-)
```

---

## ٣. نتائج عقود الاختبار (Completeness Contract) بالناتج الحقيقي

| # | الحالة | المتوقع | الناتج الحقيقي | النتيجة |
|---|---|---|---|:---:|
| ١ | رندر الصفحة قبل وبعد لجميع التبويبات (audit مفتوح ومقفول، devices, db, perf ثابت) | مطابقة 0 diff عبر `cmp` | تطابق ١٠٠٪ لجميع التبويبات دون اختلاف بايت واحد عبر `cmp` | ✅ |
| ٢ | `part=perf` | 200 و ok=true مع المفاتيح المطلوبة | HTTP 200 و ok=true مع `available_days`, `selected_day`, `total_requests`, `pages_stats` | ✅ |
| ٣ | `part=audit` أثناء قفل الأرقام الحساسة | 423 locked | HTTP 423: `{"ok":false,"error":"الأرقام الحساسة مقفولة","code":"locked"}` | ✅ |
| ٤ | `part=audit` بعد فتح الأرقام الحساسة | 200 و ok=true مع الصفوف | HTTP 200 و ok=true مع `rows`, `total`, `users_list`, `pages_list` | ✅ |
| ٥ | `part=devices` | 200 و ok=true | HTTP 200 و ok=true مع قائمة صفوف الأجهزة | ✅ |
| ٦ | `part=db` | 200 و ok=true | HTTP 200 و ok=true مع `ready: true`, `pending_count`, `rows` | ✅ |
| ٧ | الصلاحيات الجزئية (user_perf لـ audit أو يوزر بدون ops) | 403 forbidden | تم رفض الوصول غير المصرح به بـ HTTP 403 forbidden بنجاح | ✅ |
| ٨ | `revoke_token` بـ GET أو POST بدون CSRF | 405 أو 403 | GET رُفض بـ 405 Method Not Allowed وبدون CSRF رُفض بـ 403 | ✅ |
| ٩ | `revoke_token` لمستخدم عادي ليس أونر | 403 forbidden | HTTP 403: `{"ok":false,"error":"خاصية إلغاء التوكن مقتصرة على مدير النظام (الأونر)","code":"forbidden"}` | ✅ |
| ١٠ | دورة `revoke_token` للأونر وتحديث القاعدة والتكرار | 200 وتعيين revoked_at | نجاح إلغاء التوكن التجريبي 200، والتأكد من تعيين `revoked_at` في القاعدة، ونجاح الـ retry بـ Idempotency، ثم تنظيف التوكن | ✅ |
