# تقرير بريف ١٦٥ — `invoices_api.php`: البحث والبوب أب (مرحلة أ١)

- **الفرع:** `ds/brief165` في `D:/fagala_wt_ds`
- **كوميت النسخة:** `600730b`
- **كوميت الرئيسي (cherry-pick):** `9f1f743`
- **بيئة الاختبار:** `LARAGON_WWW=/d/laragon/www_ds` (قاعدة `fagala`)
- **التاريخ:** 2026-10-21

---

## ١. الجرد — الأجزاء الـ ٦ لمرحلة أ١

| # | الـ part | الطريقة | الدالة في `invoices_lib.php` أو `item_lookup_lib.php` | الصلاحية والحراسة | ملاحظات الرد |
|---|---|:---:|---|---|---|
| ١ | `item_search` | GET | `ilk_search($term, 15)` | `invoices` | بحث في مرآة الأصناف hq_codes بالاسم أو الكود (حد أقصى 15 صنف)، فحص ilk_ready ويرجع 422 إذا كانت المرآة غير جاهزة |
| ٢ | `item_history` | GET | `ilk_item_history($code, $itemId)` | `invoices` | تاريخ الصنف عبر الفواتير والمشتريات لجميع الفروع بدون فلترة فرع (رؤية الإدارة الشاملة) |
| ٣ | `pick_search` | GET | `inv_pick_search($term, $gid)` | `invoices` | بحث الأصناف لبوب أب اختيار أصناف المجموعات مع تمييز الأصناف الموجودة بالمجموعة بالفعل `in_group` |
| ٤ | `pick_items` | GET | `inv_pick_items($sth, $suf, $gid)` | `invoices` | أصناف فاتورة معينة (مرحلة أو غير مرحلة) مع دمج السطور المكررة وتحديد الموجود بالمجموعة |
| ٥ | `pick_group_invoices` | GET | `inv_pick_group_invoices($g2)` | `invoices` | فواتير مجموعة معينة للتصفح داخل بوب أب المجموعات |
| ٦ | `group_rows` | GET | `inv_group_picker_data()` | `invoices` | إرجاع الداتا الخام للمجموعات والفواتير `{unposted, posted, branch_count}` مع بقاء توليد الـ HTML في الصفحة |

- مفيش أي عمليات كتابة POST في هذه المرحلة، وأي POST يرفض بـ 405 Method Not Allowed.

---

## ٢. الديف (`git diff --stat`)

```text
 site/invoices.php     | 103 ++++++------------------------------------------------
 site/invoices_api.php |  73 ++++++++++++++++++++++++++++++++++++++
 site/invoices_lib.php | 156 ++++++++++++++++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 240 insertions(+), 92 deletions(-)
```

جميع التعديلات في `site/invoices.php` تمت بين السطور ١٦ و ٥٩٠ فقط ولم يتم لمس بقية الملف (٣٥٨٤ سطر).

---

## ٣. نتائج عقود الاختبار (Completeness Contract) بالناتج الحقيقي

| # | الحالة | المتوقع | الناتج الحقيقي | النتيجة |
|---|---|---|---|:---:|
| ١ | رندر التابات الأربعة (posted, unposted, discount, group) والنداءات الستة قبل وبعد | تطابق 0 diff عبر `cmp` | تطابق ١٠٠٪ لجميع الملفات العشرة بدون اختلاف بايت واحد عبر `cmp` | ✅ |
| ٢ | `part=item_search&term=قلم` | 200 و ok=true وتطابق الصفوف والعدد | HTTP 200 مع تطابق تام لعدد الصفوف (15 صنف) والكود الأول مع رندر الصفحة القديمة | ✅ |
| ٣ | `part=item_history` للصنف | 200 و ok=true وتطابق بيانات الصنف | HTTP 200 مع تطابق معرف الصنف `id` والأسعار والفواتير مع القديم | ✅ |
| ٤ | `part=pick_search&term=قلم&g=0` | 200 وتطابق عدد النتائج | HTTP 200 وتطابق عدد الصفوف مع نداء `gpick=search` القديم | ✅ |
| ٥ | `part=pick_items&sth=5000001&suffix=26_07&g=0` | 200 وتطابق العنوان والصفوف | HTTP 200 وتطابق عنوان الفاتورة وعدد الأصناف والبيانات | ✅ |
| ٦ | `part=pick_group_invoices&g2=1` | 200 وتطابق العنوان والفواتير | HTTP 200 وتطابق العنوان وقائمة الفواتير داخل المجموعة | ✅ |
| ٧ | `part=group_rows` | 200 مع المفاتيح unposted, posted, branch_count وتطابق العدد | HTTP 200 وتطابق دقيق لقيمة `branch_count` (186 فرع) مع القديم | ✅ |
| ٨ | يوزر إشراقة بدون صلاحية `invoices` | 403 forbidden | تم رفض الطلب بـ HTTP 403 | ✅ |
| ٩ | يوزر بصلاحية `invoices` | 200 ok | تم قبول الطلب بنجاح HTTP 200 | ✅ |
| ١٠ | طلب `POST` على `item_search` | 405 Method Not Allowed | HTTP 405: `{"ok":false,"error":"طريقة الطلب غير مسموحة","code":"bad_method"}` | ✅ |
| ١١ | طلب `part=xyz` غير معروف | 400 bad_request | HTTP 400: `{"ok":false,"error":"جزء غير معروف (part)","code":"bad_request"}` | ✅ |

---

## ٤. حالة المستودع (`git status --porcelain`)

```text
 M site/invoices.php
?? site/invoices_api.php
?? site/invoices_lib.php
```
