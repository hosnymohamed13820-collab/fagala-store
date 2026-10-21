# تقرير بريف ١٦٦ — `invoices_api.php` مرحلة (ب١): التوزيع والحالة وسحب الحجز (كتابة)

## ١. ملخص التنفيذ
تم بحمد الله استخراج عمليات كتابة التوزيع والحالة وسحب الحجز من `site/invoices.php` إلى دوال نظيفة في `site/invoices_lib.php`:
1. `inv_set_status()`: تغيير حالة الفاتورة يدوياً أو تلقائياً ومعاينة النواقص (`dry=1`) واستدعاء `receiving_open` عند التحول إلى `done`.
2. `inv_autosave()`: حفظ كميات التوزيع لصنف معين لفرع محدد وحساب إجمالي الفرع وتحديث حالة الفاتورة.
3. `inv_group_autosave()`: حفظ التوزيع من الشاشة المجمعة (شلال على فواتير المجموعة) وتحديث الحالات.
4. `inv_reservation_preview()`: معاينة الحجوزات المرشحة لسحب الحجز.
5. `inv_reservation_apply()`: تطبيق وتخصيص كميات الحجز وسحبها للفروع وتحديث جدول `book_reservation_lines`.
6. نقل `dist_is_return()` إلى `invoices_lib.php`.

وتمت إضافة أجزاء الكتابة (POST) في `site/invoices_api.php` مع تأمين كامل بـ `api_method('POST')` و `api_csrf()` و `api_idempotent()`.

---

## ٢. الجرد والبيانات والتوزيعة المختبرة `<D>`
- **سجل التوزيع المختبر `<D>`:** `id = 38` (فاتورة رقم `43`، `sth_id = 100000148`، لاحقة `26_07`).
- **توزيعات المجموعة المختبرة:** `group_id = 1` (`dist_ids = 52, 53, 54`).
- **جداول النسخ الاحتياطية التي تم إنشاؤها واستعادتها بالكامل:**
  - `b166_dl` (من `invoice_distribution_lines` للتوزيعة 38)
  - `b166_d` (من `invoice_distribution` للتوزيعة 38)
  - `b166_ra` (من `reservation_allocations` للتوزيعة 38)
  - `b166_brl` (من `book_reservation_lines` للسطر 6)
  - `b166_gdl` (من `invoice_distribution_lines` لتوزيعات المجموعة 52, 53, 54)
  - `b166_gd` (من `invoice_distribution` لتوزيعات المجموعة 52, 53, 54)

### أرقام ومؤشرات قاعدة البيانات (قبل / أثناء / بعد):
| الجدول | البداية (قبل الاختبار) | أثناء تشغيل `set_status=done` | بعد التنظيف والاستعادة |
|---|---|---|---|
| `receipts` (MAX id) | 35 | 905 (+870) | 35 |
| `push_queue` (MAX id) | 1022 | 1100 (+78) | 1022 |
| `notifications` (MAX id) | 1018 | 1115 (+97) | 1018 |
| `invoice_distribution_lines` (للتوزيعة 38) | 0 | 1 | 0 |
| `reservation_allocations` (للتوزيعة 38) | 0 | 1 | 0 |
| `book_reservation_lines` (سطر 6: `allocated_qty`) | 0.0 | 1.0 | 0.0 |

تم حذف جميع الاستلامات والإشعارات وطابور البوش التي تولدت أثناء الاختبار وعادت المؤشرات متطابقة مع البداية تماماً.

---

## ٣. نتائج اختبارات العقد (Contract Completeness)

```text
=== STARTING BRIEF 166 CONTRACT TESTS ===

Initial DB markers: max_push=1022, max_notif=1018, max_receipt=35, max_receipt_line=5
Backup created: b166_dl=0, b166_d=1, b166_ra=0

--- Contract Case 1: Page Renders (0 diff) ---
PASS: Case 1: Open invoice with dist renders identical (0 diff)
PASS: Case 1: Group open renders identical (0 diff)

--- Contract Case 2: Page Operations (invoices.php POST) ---
PASS: Page set_status (dry=1) returns ok=true and pending array
PASS: Page autosave returns ok=true and branch_total
PASS: Page group_autosave returns ok=true and group_totals
PASS: Page reservation_pull_preview returns ok=true and total_candidates
PASS: Page set_status (status=split) returns ok=true and status=split

--- Contract Case 3: Rejection without CSRF (403) ---
PASS: autosave without CSRF returns 403
PASS: group_autosave without CSRF returns 403
PASS: set_status without CSRF returns 403
PASS: reservation_preview without CSRF returns 403
PASS: reservation_apply without CSRF returns 403

--- Contract Case 4: autosave flow & Idempotency ---
PASS: autosave (branch 3, qty 5) returns 200
PASS: DB has amount=5 after autosave
PASS: autosave (branch 3, qty 0) returns 200
PASS: DB has amount=0 (or deleted) after reset
PASS: First request with Idempotency-Key returns 200
PASS: Second request with same Idempotency-Key returns 200 (cached)
PASS: Idempotent response data matches original

--- Contract Case 5: set_status dry=1 ---
PASS: set_status dry=1 returns 200 and pending list
PASS: invoice_distribution status was NOT changed by dry=1

--- Contract Case 6: set_status=done, receipts, push_queue, and revert ---
PASS: set_status=done returns 200 and status=done
PASS: DB invoice_distribution status is now done
After set_status=done: new receipts=870, new push_queue=78, new notifs=97
PASS: Receipts were opened by receiving_open
PASS: Receipts and push_queue successfully reverted to initial markers

--- Contract Case 7: Return invoice rejected ---
PASS: autosave on return returns 422 with exact message

--- Contract Case 8: reservation_preview & reservation_apply ---
PASS: reservation_preview returns 200 with candidates
PASS: reservation_apply returns 200 and allocated_count >= 1
PASS: book_reservation_lines allocated_qty updated
PASS: reservation_allocations record created

--- Contract Case 9: Permissions & Methods ---
PASS: ashraqa user rejected on write (403)
PASS: GET on autosave rejected (405 Method Not Allowed)
PASS: unknown part rejected (400 bad_request)

--- Restoring Database to pristine state ---
Restoration complete:
  distribution_lines: before=0, after=0
  distribution: before=1, after=1
  reservation_allocations: before=0, after=0
  book_reservation_lines id=6 allocated_qty: 0
PASS: Table rows restored exactly (counts match)

============================================
>>> ALL BRIEF 166 CONTRACT TESTS PASSED 100%! <<<
```

---

## ٤. فحص الديف وحجم التغيير في الملفات

```text
 site/invoices.php     | 172 ++++--------------------------------------
 site/invoices_api.php |  72 +++++++++++++++++-
 site/invoices_lib.php | 203 ++++++++++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 289 insertions(+), 158 deletions(-)
```

- تم التحقق من عدم وجود أي ذكر لـ `b166_` داخل أي ملف في `site/` (`grep -rn 'b166_' site/ | wc -l` ← `0`).

---

## ٥. حالة Git
- فرع العمل: `ds/brief166` في مستودع `D:\fagala_wt_ds2` (كوميت `7f16739`).
- تم استيراد الكوميت بنجاح في `D:\fagala_store` (كوميت `0180519`).
- لم يتم المساس بـ `ashraqa/` أو `local/` أو `finance.php`.
