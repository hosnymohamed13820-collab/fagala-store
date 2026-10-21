# تقرير بريف ١٦٦ج — `invoices_api.php` مرحلة (ب٣): إدارة المجموعات (كتابة)

## ١. ملخص التنفيذ
تم بحمد الله استخراج عمليات كتابة وإدارة المجموعات من `site/invoices.php` إلى دوال نظيفة ومستقلة في `site/invoices_lib.php` دون المساس بـ `site/group_lib.php`:
1. `inv_parse_invoice_picks()`: فك وتفكيك مصفوفة/JSON الفواتير مع التحقق الصارم ورمي 422 في حال كان الـ JSON غير صالح.
2. `inv_parse_item_picks()`: فك وتفكيك مصفوفة/JSON الأصناف لبوب أب «تحديد أصناف» ومجموعات الأصناف مع التحقق الصارم.
3. `inv_group_create()`: إنشاء مجموعة فواتير كاملة (`type='full'`) والتحقق من اختيار فاتورتين على الأقل.
4. `inv_group_create_items()`: إنشاء مجموعة أصناف محددة (`type='items'`) وتوزيعها المبدئي.
5. `inv_group_add_invoices()`: إضافة فواتير كاملة جديدة لمجموعة موجودة.
6. `inv_group_add_items()`: إضافة أصناف محددة لمجموعة أصناف موجودة (وترفض المجموعات الكاملة بـ 422 تلقائياً).
7. `inv_group_delete()`: مسح مجموعة بالكامل ومسح بنودها التابعة.
8. `inv_group_remove_invoice()`: إزالة فاتورة واحدة من المجموعة.
9. `inv_group_remove_item()`: إزالة صنف واحد من مجموعة أصناف.
10. إضافة أجزاء الكتابة السبعة (`group_create`, `group_create_items`, `group_add_invoices`, `group_add_items`, `group_delete`, `group_remove_invoice`, `group_remove_item`) في `site/invoices_api.php` تحت `POST` مع الحماية الكاملة بـ `api_csrf()` و `api_idempotent()`.

---

## ٢. الجرد وحالة قاعدة البيانات قبل وبعد الاختبارات
- **جداول المجموعات المراقبة:** `invoice_groups` و `invoice_group_items` و `invoice_group_lines`.
- تم إنشاء مجموعات اختبار معزولة (`اختبار ١٦٦ج` و `اختبار أصناف ١٦٦ج` و `اختبار تكرار المفتاح ١٦٦ج`) وحذفها بالكامل في نهاية كل اختبار.

### أرقام ومؤشرات قاعدة البيانات (قبل / أثناء / بعد):
| الجدول | قبل الاختبار | أثناء اختبار مجموعة الفواتير | أثناء اختبار مجموعة الأصناف | بعد التنظيف والاستعادة |
|---|---|---|---|---|
| `invoice_groups` (COUNT(*)) | 24 | 25 (+1) | 25 (+1) | 24 (مطابق تماماً) |
| `invoice_group_items` (COUNT(*)) | 100 | 102 (+2) | 101 (+1) | 100 (مطابق تماماً) |
| `invoice_group_lines` (COUNT(*)) | 76 | 76 | 77 (+1) | 76 (مطابق تماماً) |

عادت جميع جداول المجموعات الثلاثة إلى أعدادها الأصلية تماماً بعد الحذف.

---

## ٣. نتائج اختبارات العقد (Contract Completeness)

```text
=== Brief 166c Contract Tests ===

--- Case 1: Baseline Page Renders (0 diff) ---
PASS: Case 1.1: group_tab render exact match
PASS: Case 1.2: group_1 render exact match

--- Case 2: 7 parts without CSRF (403 Forbidden) ---
PASS: Case 2 (group_create without CSRF -> 403)
PASS: Case 2 (group_create_items without CSRF -> 403)
PASS: Case 2 (group_add_invoices without CSRF -> 403)
PASS: Case 2 (group_add_items without CSRF -> 403)
PASS: Case 2 (group_delete without CSRF -> 403)
PASS: Case 2 (group_remove_invoice without CSRF -> 403)
PASS: Case 2 (group_remove_item without CSRF -> 403)

--- Case 3: Full Invoices Group Sequence ---
Initial counts: groups=24, items=100, lines=76
PASS: Case 3.1: group_create returns 200 and group_id
After group_create: groups=25, items=102, lines=76
PASS: Case 3.2: groups count incremented by 1
PASS: Case 3.3: group_add_invoices returns 200 and added count
PASS: Case 3.4: group_remove_invoice returns 200 and removed=true
PASS: Case 3.5: group_delete returns 200 and deleted=true
After group_delete: groups=24, items=100, lines=76
PASS: Case 3.6: All 3 tables restored to exact initial counts

--- Case 4: Items Group Sequence ---
Initial counts: groups=24, items=100, lines=76
PASS: Case 4.1: group_create_items returns 200 and group_id
After group_create_items: groups=25, items=101, lines=77
PASS: Case 4.2: groups count incremented and items count incremented
PASS: Case 4.3: group_add_items returns 200 and added=1
PASS: Case 4.4: group_remove_item returns 200 and removed_count=1
PASS: Case 4.5: group_delete returns 200 and deleted=true
After group_delete: groups=24, items=100, lines=76
PASS: Case 4.6: All 3 tables restored to exact initial counts

--- Case 5: group_add_items on 'full' group (422) ---
PASS: Case 5.1: group_add_items on full group returned 422
PASS: Case 5.2: Error message matches exact expected message

--- Case 6: group_create with bad JSON (422) ---
PASS: Case 6.1: Bad JSON returned 422
PASS: Case 6.2: Error message equals "بيانات الفواتير غير صحيحة (JSON غير صالح)."

--- Case 7: Idempotency on group_create ---
PASS: Case 7.1: First call returns 200 and group_id
PASS: Case 7.2: Second call with same key returns 200 and same group_id
PASS: Case 7.3: Exactly one group was created in DB
PASS: Case 7.4: Group cleaned up and counts restored

--- Case 8: Forbidden user / wrong methods / invalid part ---
PASS: Case 8.1: ashraqa user without invoices perms -> 403
PASS: Case 8.2: GET on POST part -> 405
PASS: Case 8.3: Invalid part -> 400

=========================================
ALL CONTRACT TESTS PASSED 100% (8/8 Cases)
=========================================
```

---

## ٤. حالة `git status` والتأكيد
ملفات التعديل في `git status --porcelain`:
```text
 M site/invoices.php
 M site/invoices_api.php
 M site/invoices_lib.php
```

فحص ملف `group_lib.php`:
```bash
git diff --stat -- site/group_lib.php
# (مخرجات فارغة — لم يتم لمس الملف إطلاقاً)
```

- الـ commit في worktree: `036ea77`
- الـ cherry-pick في `fagala_store`: `e3e74ef`
