# تقرير بريف ١٧١ — `reports_api.php`: التقارير غير المالية (مرحلة أ)

- **الفرع:** `ds/brief171` في `D:/fagala_wt_ds2`
- **كوميت النسخة:** `68cc9f5`
- **كوميت الرئيسي (cherry-pick):** `feafc29`
- **بيئة الاختبار:** `LARAGON_WWW=/d/laragon/www_ds2` (قاعدة `fagala`)
- **التاريخ:** 2026-10-21

---

## ١. الجرد — جميع تابات موديول التقارير

| # | التاب | الحالة في هذا البريف | السبب والصلاحية |
|---|---|:---:|---|
| ١ | `attendance` | ✅ مغطى بـ API | تقرير الحضور: ساعات وأيام الحضور والانصراف (صلاحية `reports:attendance`) |
| ٢ | `attendance_totals` | ✅ مغطى بـ API | تقرير إجمالي الحضور: إجمالي ساعات الحضور للموظف بدون أي مرتبات (`reports:attendance_totals`) |
| ٣ | `receiving` | ✅ مغطى بـ API | تقرير استلام الفواتير: سجل الفواتير والمجموعات المستلمة والمعتمدة وأوقات الاستلام (`reports:receiving`) |
| ٤ | `stocktake` | ✅ مغطى بـ API | أرشيف الجرد: جلسات الجرد والمحاضر وخطوط الجرد للمطابقة والمراجعة (`reports:stocktake`) |
| ٥ | `customers` | ✅ مغطى بـ API | داتا العملاء: أسماء وتليفونات حجوزات الكتب والفروع وتاريخ آخر حجز بدون مبالغ مالية (`reports:customers`) |
| ٦ | `supplier_purchases` | ⚪ مستبعد | له API مستقل مسبقاً (`supplier_purchases_api.php`) |
| ٧ | `daily` | ⚪ مستبعد | صفحة مستقلة برابط مباشر (`daily_summary.php` / `daily_summary_api.php`) |
| ٨ | `stock` | ⚪ مستبعد | له صفحة وAPI مستقلين (`stock_query.php` / `stock_query_api.php`) |
| ٩ | `sales` | 🔒 موجة ٣ (فلوس) | حركة مبيعات وأموال وخزائن — يرجع 403 «التقرير ده لسه مش متاح من الـAPI» |
| ١٠ | `treasury` | 🔒 موجة ٣ (فلوس) | حركة قيود الخزينة والحسابات — يرجع 403 |
| ١١ | `expenses` | 🔒 موجة ٣ (فلوس) | مصاريف وبنود محاسبية — يرجع 403 |
| ١٢ | `jtotals` | 🔒 موجة ٣ (فلوس) | إجماليات قيود اليومية العامة — يرجع 403 |
| ١٣ | `custody` | 🔒 موجة ٣ (فلوس) | عهد الفروع وتسوياتها — يرجع 403 |
| ١٤ | `deficits` | 🔒 موجة ٣ (فلوس) | عجوزات الخزائن ونقاط البيع — يرجع 403 |
| ١٥ | `suppliers` | 🔒 موجة ٣ (فلوس) | حسابات وأرصدة الموردين — يرجع 403 |
| ١٦ | `salaries` | 🔒 موجة ٣ (فلوس) | مسيرات رواتب واستحقاقات واستقطاعات حساسة — يرجع 403 |
| ١٧ | `advances` | 🔒 موجة ٣ (فلوس) | سلف الموظفين وأرصدتها — يرجع 403 |
| ١٨ | `noprice` | 🔒 مرحلة (ج) | تسعير وتعديل أسعار بيع الأصناف، مؤجل لمرحلة تسعير الفواتير — يرجع 403 |

---

## ٢. ناتج الـ `diff` لدالة `build_report` (الأصلية والمنقولة)

أمر الفحص:
```bash
diff -u <(git -C /d/fagala_wt_ds2 show HEAD~1:site/reports.php | sed -n '279,1273p') <(sed -n '8,1002p' /d/fagala_wt_ds2/site/reports_lib.php)
```
**الناتج:** فارغ تماماً (0 bytes / 0 diff).
تم النقل بالحرف دون تغيير بايت واحد.

---

## ٣. الديف (`git diff --stat`)

```text
 site/reports.php     | 1118 +-------------------------------------------------
 site/reports_api.php |   86 ++++
 site/reports_lib.php | 1193 ++++++++++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 1279 insertions(+), 1118 deletions(-)
```

---

## ٤. نتائج عقود الاختبار (Completeness Contract) بالناتج الحقيقي

| # | الحالة | المتوقع | الناتج الحقيقي | النتيجة |
|---|---|---|---|:---:|
| ١ | رندر الصفحة قبل وبعد للسبعة تقارير: الـ ٥ غير المالية + sales + treasury | مطابقة تامة 0 diff عبر `cmp` | تطابق ١٠٠٪ لجميع الصفحات السبعة بدون اختلاف بايت واحد عبر `cmp` | ✅ |
| ٢ | `diff` بتاع `build_report` المنقولة | فارغ | فارغ تماماً 0 diff | ✅ |
| ٣ | `part=report&type=<كل واحد من الـ ٥>` بحساب المالك | 200 و ok=true مع تطابق الصفوف والإجماليات | HTTP 200 و ok=true مع تطابق كامل لعناوين وصفوف وإجماليات التقارير الخمسة | ✅ |
| ٤ | طلب تقرير مالي أو تسعير (`sales`, `treasury`, `expenses`, `salaries`, `noprice`) | 403 ورسالة مخصصة | HTTP 403: `{"ok":false,"error":"التقرير ده لسه مش متاح من الـAPI","code":"forbidden"}` | ✅ |
| ٥ | عزل الصلاحيات الجزئية: يوزر معه `reports:attendance` فقط يطلب `receiving` ثم `attendance` | الأولى 403 والثانية 200 | الأولى رُفضت بـ HTTP 403 والثانية نجحت بـ HTTP 200 | ✅ |
| ٦ | نوع تقرير غير معروف `type=xyz` | 400 bad_request | HTTP 400: `{"ok":false,"error":"نوع التقرير غير صحيح","code":"bad_request"}` | ✅ |
| ٧ | يوزر بدون صلاحيات تقارير (`ashraqa`) | 403 forbidden | HTTP 403: `{"ok":false,"error":"ليس لديك صلاحية للوصول إلى هذه الصفحة.","code":"forbidden"}` | ✅ |
| ٨ | طريقة غير مسموحة `POST` | 405 Method Not Allowed | HTTP 405: `{"ok":false,"error":"طريقة الطلب غير مسموحة","code":"bad_method"}` | ✅ |
| ٩ | جزء غير معروف `part=unknown` | 400 bad_request | HTTP 400: `{"ok":false,"error":"جزء غير معروف (part)","code":"bad_request"}` | ✅ |

---

## ٥. حالة المستودع (`git status --porcelain site/`)

```text
 M site/reports.php
?? site/reports_api.php
?? site/reports_lib.php
```
`grep -c "^function build_report" site/reports.php` ← **0**
`grep -c "^function build_report" site/reports_lib.php` ← **1**
