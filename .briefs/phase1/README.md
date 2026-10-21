# المرحلة (١): فصل كل صفحة لـAPI + شاشة

> اتكتب ٢٠/١٠/٢٠٢٦ (كلود). المرجع: `.briefs/الخطة_المستقبلية.md` قسم ٣.
> **القالب الأساسي لكل بريف هنا:** `.briefs/handoff/api_template.md`. كل ملف في الفولدر ده = **إضافات على القالب** وبتكسب عليه.
> المراجعة والكوميت والرفع: زي `.briefs/handoff/README.md` بالظبط.

## فين إحنا (٢٠/١٠)
- **٢٢ ملف `_api.php` موجودين** (٢١ صفحة من ٤٥ متغطية). آخرهم: ١٥٢ الاستلام (أ) · ١٥٦ الجرد (أ).
- `pc_scan.php` **مش محتاج API**: مفيهاش داتا من السيرفر (QR بالجافاسكربت بس).
- صفحات التصدير (`*_export.php`) بتفضل زي ما هي: لينكات بتنزّل ملف، والتطبيق هيفتحها بنفس الشكل.

## مسارين بالتوازي

| المسار | النسخة | Laragon | مين بيشغّل |
|---|---|---|---|
| **أ** | `D:\fagala_wt_ds` | `/d/laragon/www_ds` | كلود (OpenCode في الخلفية: DeepSeek، ولو مقفول MiniMax) |
| **ب** | `D:\fagala_wt_ds2` | `/d/laragon/www_ds2` | محمد (أجي من البانل) |

🔴 **وكيل واحد في كل نسخة.** و**جلسة DeepSeek واحدة في نفس الوقت.**
🔴 قبل أي بريف: `git -C <النسخة> status --porcelain` لازم يبقى فاضي (أو `??` بتاع `.claude/` `.github/` `DESIGN.md` في wt_ds2 بس) ← `git checkout -q -B ds/brief<N> claude/nifty-mccarthy-5if170` ← `LARAGON_WWW=<laragon> bash tests/local/sync_to_laragon.sh`.

## الطابور

**الموجة ٢: مفيهاش فلوس.** بالترتيب، وكل سطر مستقل عن اللي قبله إلا لو مكتوب.

| # | الصفحة | الملف | المسار | الحالة |
|---|---|---|---|---|
| ١٥٥ | `receiving.php` مرحلة (ب) | `.briefs/handoff/brief155_receiving_b.md` | ب (أجي) | ✅ `c6508e1` اترفع |
| ١٥٨ | `analysis.php` | (القالب + ملاحظات في أمر التشغيل) | أ | ✅ `3fa4bb0` اترفع (MiniMax) |
| ١٥٩ | `invoice_problems.php` | `brief159_invoice_problems.md` | أ | ✅ `935c95a` (MiniMax، كلود راجع) — مش مرفوع |
| ١٦٠ | `undistributed.php` | `brief160_undistributed.md` | أ | ✅ `b0a2f91` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦١ | `ops.php` | `brief161_ops.md` | أ | ✅ `a8c4a7d` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٢ | `branch_invoices_core.php` (صفحات فواتير الفروع الخمسة) | `brief162_branch_invoices.md` | ب | ✅ `5d1d50e` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٣ | `ashraqa_invoices.php` | `brief163_ashraqa_invoices.md` | ب | ✅ `5b10612` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٤ | `stocktake.php` مرحلة (ب): الجرد والمراجعة | `brief164_stocktake_b.md` | ب | ✅ `1420fc4` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٤ب | `stocktake.php`: عمليات المالك (الجلسات · مسح · تعيين) | `brief164b_stocktake_owner.md` | ب | ✅ `9cd950f` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٥ | `invoices.php` (أ١): البحث والبوب أب (٦ GET) | `brief165_invoices_a.md` | أ | ✅ `9f1f743` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٥ب | `invoices.php` (أ٢): القايمة والفاتورة والمجموعة والخصم | `brief165b_invoices_view.md` | أ | ✅ `e6f99b6` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٦٦ | `invoices.php` (ب١): التوزيع والحالة وسحب الحجز (فيه إشعارات) | `brief166_invoices_dist.md` | ب | **بعد ١٦٥ب** |
| ١٦٦ب | `invoices.php` (ب٢): الملاحظات والعلامات والمشاكل | `brief166b_invoices_notes.md` | ب | **بعد ١٦٦** (ويفضّل بعد ١٥٩) |
| ١٦٦ج | `invoices.php` (ب٣): إدارة المجموعات | `brief166c_invoices_groups.md` | ب | **بعد ١٦٦ب** |
| ١٦٧ | `settings.php`: القراية + `invoice_guard` + `daily_summary_save` | `brief167_settings.md` | أ | |
| ١٦٨ | `users.php` (أ): القراية بس | `brief168_users_a.md` | أ | |
| ١٦٩ | `definitions.php` (أ): القراية بس، من غير الخزاين | `brief169_definitions_a.md` | ب | ✅ `66de71b` (Gemini نفّذ وراجع) — مش مرفوع |
| ١٧٠ | `attendance.php` (أ): حضوري وسجلّي | `brief170_attendance_a.md` | أ | |
| ١٧١ | `reports.php` (أ): ٥ تابات من غير فلوس + نقل `build_report` | `brief171_reports_a.md` | ب | ✅ `feafc29` (Gemini نفّذ وراجع) — مش مرفوع |

> 🔴 **نفس الملف = واحد ورا التاني.** `invoices.php` (١٦٥ ← ١٦٦ج) و`stocktake.php` (١٦٤ ← ١٦٤ب) ماينفعش اتنين منهم يشتغلوا في نفس الوقت في نسختين، حتى لو المسارين فاضيين: الكوميت التاني هيخبط في الأول.

**الموجة ٣: الفلوس.** 🔴 **مقفولة لحد ما محمد يقول «ابدأ الفلوس».** ده قرار الخطة: الفلوس آخر حاجة، وكل صفحة لوحدها.
المرحلة (أ) هنا **قراية بس**، والصفحة لازم تطلع بالحرف. الكتابة في صفحات الفلوس **مش في الفولدر ده**: كلود هيكتبها بعد ما محمد يشوف أرقام (أ) بعينه.

| # | الصفحة | الملف |
|---|---|---|
| ١٧٢–١٨١ | `dashboard` · `suppliers` · `sms_inbox` · `treasury` · `cashier` · `custody` · `review` · `branch_recon` · `hr` (٣ تشغيلات) · تابات الفلوس في `reports` | `wave3_read.md` (قسم لكل صفحة) |

`finance.php` **مش في القايمة**: دي مكتبة (فيها `post_journal` والمحرّك كله)، مش صفحة، ومالهاش شاشة.

**بعد ما الطابور يخلص — كلود يكتبهم (محتاجين قرارات محمد):**
- `invoices.php` مرحلة (ج) + `reports.php` تاب `noprice`: **الأسعار** (`save_price` · `bulk_prices` · `refresh_prices` …). الأسعار مش حركة فلوس، بس بتحدد سعر البيع في كل الفروع.
- `users.php` مرحلة (ب): الصلاحيات والأدوار.
- `definitions.php` (ب): كل الكتابة (الفروع بتعمل خزنة عهدة · الموظفين فيهم المرتب · الأقسام = شجرة الحسابات · الخزاين · نوع دفع المورد).
- `settings.php`: الحفظ العام (فيه حساب إيراد المبيعات وعتبة العجز) وتغيير الباسورد.
- `attendance.php` مرحلة (ب): البصمة (`clock_in` / `clock_out`)، لأنها بتحدد ساعات المرتب.
- كل كتابة في الموجة ٣.
- 🟡 **تصدير `analysis.php` لسه CSV** (`fputcsv`)، وده ضد قاعدة «إكسيل بس» (لقاه MiniMax في ١٥٨). بريف صغير يحوّله لـ`SimpleXlsx`، والنسخة اللي في `ashraqa/analysis.php` معاه.

## فولدر `ashraqa/` (موقع إشراقة المنفصل)
برّه الشغل ده خالص: **ولا ملف فيه يتلمس.** وفيه ملفين **متطابقين** مع `site/`: `ashraqa_prices_lib.php` و`analysis_lib.php`. تعديلهم في `site/` لوحده بيفرّق التوأم. لو البريف محتاج دوال جديدة جنبهم ← **lib جديد**. ولو مفيش مفر، اكتب ده في التقرير، وكلود يقرر في المراجعة يتنسخ لإشراقة ولا لأ.

## أداة الاختبار
`C:\Users\User\AppData\Local\Temp\opencode\api141\run_api.php` فيها يوزرين بس: `owner` (id 1) و`ashraqa` (id 66، من غير صلاحيات). الحالات اللي محتاجة **موظف فرع** أو **مشرف**: انسخ الأداة لفولدر `b<N>` بتاعك، وزوّد يوزر بـid **حقيقي** من `users` على Laragon (بصلاحياته الحقيقية من `user_permissions`). واكتب الـid وفرعه في التقرير. متعدّلش الأداة الأصلية.

## أمر التشغيل

**المسار أ (كلود بيشغّله):**
```bash
cd /d/fagala_wt_ds && export LARAGON_WWW=/d/laragon/www_ds && timeout 3600 opencode run -m opencode/deepseek-v4.1-flash-free \
 "اقرا D:/fagala_store/.briefs/handoff/api_template.md وبعده D:/fagala_store/.briefs/phase1/brief<N>_<x>.md ونفّذهم. انت شغال في D:/fagala_wt_ds (فرع ds/brief<N> — اتعمل خلاص). التقرير في D:/fagala_store/.briefs/brief<N>-report.md" > /tmp/b<N>.log 2>&1
```

**المسار ب (محمد يلزقه لأجي):**
```
اقرا D:/fagala_store/.briefs/handoff/api_template.md وبعده D:/fagala_store/.briefs/phase1/brief<N>_<x>.md ونفّذهم. انت شغال في D:/fagala_wt_ds2 (فرع ds/brief<N> — اتعمل خلاص، متعملش checkout)، و LARAGON_WWW=/d/laragon/www_ds2. متشغّلش tests/run_all.sh. التقرير في D:/fagala_store/.briefs/brief<N>-report.md
```

## المراجعة (كلود) — زيادة على `handoff/README.md`
1. 🔴 **حالة ١ بنفسي:** ١٠ رندرات قبل وبعد (`tests/local/run_page.php`)، ومقارنة بـ`cmp` (**مش** `Set-Content`، لأنه بيحط BOM: ده اللي عطّل ١٥٥ ساعتين). والرندرات لازم يبقى فيها داتا فعلاً، مش محضر فاضي.
2. ٣ حالات API على الأقل بنفسي بـ`run_api.php`.
3. `php tests/analysis/test_analysis.php` لو اتلمس أي حاجة في `analysis_lib.php`.
4. الكوميت في النسخة ← `cherry-pick` في `D:\fagala_store`.
