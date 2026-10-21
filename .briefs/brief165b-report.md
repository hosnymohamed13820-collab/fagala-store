# تقرير بريف ١٦٥ب — `invoices_api.php` مرحلة (أ٢): القايمة والفاتورة والمجموعة والخصم (قراية)

## ١. ملخص التنفيذ
تم بحمد الله استخراج كتلة تحميل بيانات العرض بالكامل من `site/invoices.php` (الأسطر من ~١٣٦٧ إلى ~١٦٢٢) وتقسيمها إلى **٤ دوال متخصصة** في `site/invoices_lib.php`:
1. `inv_load_open_invoice()`: تحميل الفاتورة المفتوحة بكل ملحقاتها (أصناف مدمجة، سجل التوزيع، أرصدة المخازن، أسعار البيع، علامات الأصناف، أرصدة إشراقة).
2. `inv_load_group()`: تحميل بيانات التصفح بالمجموعات (كروت المجموعات أو تفاصيل المجموعة المفتوحة وأصنافها وأرصدتها وأسعارها).
3. `inv_load_discount()`: تحميل بيانات تاب الخصم (الفواتير، هيدر وبنود الفاتورة المحددة، وحساب الخصم الصافي والنسبي).
4. `inv_load_list()`: تحميل قائمة الفواتير المرحّلة أو غير المرحّلة وحساب عدد أصناف كل فاتورة، التجميع حسب الفرع، وحالات الفواتير وملاحظاتها.

وتم ربط الدوال الأربع في `site/invoices_api.php` عبر الأجزاء: `list` و `invoice` و `group` و `discount` (جميعها GET ومحمية بـ `require_permission('invoices')`).

---

## ٢. قائمة المتغيرات التي تملاها الدوال بالتفصيل

### أ) دالة `inv_load_open_invoice(PDO $pdo, int|string $openInvoiceId, ?string $openSuffix = null, ?int $openCb = null, ?int $openDt = null, int $uid = 0): array`
| المتغيّر | النوع | الوصف ومصدر البيانات |
|---|---|---|
| `openInvoice` | `array\|null` | بيانات هيدر الفاتورة من `sqlsrv_fetch_invoice_header` مع ضمان وجود `company_branch` و `doc_type` |
| `isReturnDoc` | `bool` | هل المستند مردود شراء (`doc_type === 6`) |
| `isBranchInv` | `bool` | هل الفاتورة تتبع فرع آخر خارج الإدارة (`!grp_is_admin_inv`) |
| `noDistDoc` | `bool` | هل الفاتورة مستثناة من التوزيع (`isReturnDoc || isBranchInv`) |
| `branchName` | `string` | اسم الفرع من جدول `branches` إذا كانت فاتورة فرع |
| `items` | `array` | بنود الفاتورة مدمجة الأصناف المتكررة بـ `merge_invoice_items(sqlsrv_fetch_invoice_items)` |
| `totalQty` | `float` | إجمالي كميات بنود الفاتورة المدمجة |
| `distRecord` | `array\|null` | سجل التوزيع من `ensure_distribution_record` (لحالات `status`, `status_manual`, `user_notes`) |
| `distId` | `int` | معرف سجل التوزيع |
| `branchTotals` | `array` | إجمالي الكميات الموزعة لكل فرع مع خصم الأصناف المستبعدة أو اليتيمة |
| `orphanCodes` | `array` | الأكواد الموزعة التي حُذفت من الفاتورة الأصلية |
| `distByItem` | `array` | إجمالي الكميات الموزعة لكل كود صنف |
| `pendingItems` | `array` | الأصناف التي لم يكتمل توزيعها بعد |
| `salePrices` | `array` | أسعار البيع للأصناف من `resolve_invoice_sale_prices` |
| `itemFlags` | `array` | علامات الأصناف من `ifl_load` |
| `skipMap` | `array` | خريطة الأصناف المستثناة من التقرير من `ifl_skip_map` |
| `allBalances` | `array` | أرصدة الأصناف بجميع المخازن من `sqlsrv_fetch_item_balances_all` |
| `storeNames` | `array` | أسماء المخازن من `sqlsrv_fetch_store_names` |
| `itemBalances` | `array` | رصيد مخزن الإدارة الرئيسي للأصناف |
| `ashraqaBalances` | `array` | أرصدة إشراقة للأصناف من `ashraqa_fetch_balances` |
| `ashraqaSync` | `?string` | وقت آخر مزامنة لإشراقة من `ashraqa_last_sync` |

### ب) دالة `inv_load_group(?int $grpOpenId = null): array`
| المتغيّر | النوع | الوصف ومصدر البيانات |
|---|---|---|
| `grpOpenId` | `int` | معرف المجموعة المطلوبة |
| `grpCurrent` | `array\|null` | بيانات المجموعة من `group_get` إذا وُجدت |
| `grpData` | `array\|null` | تفاصيل وأصناف وفواتير المجموعة من `group_load` |
| `grpCards` | `array` | كروت المجموعات المتاحة من `group_list` (في حالة عدم فتح مجموعة محددة) |
| `allBalances` | `array` | أرصدة أصناف المجموعة بكل المخازن |
| `itemBalances` | `array` | أرصدة أصناف المجموعة بمخزن الإدارة |
| `ashraqaBalances` | `array` | أرصدة أصناف المجموعة في إشراقة |
| `ashraqaSync` | `?string` | وقت آخر مزامنة لإشراقة |
| `salePrices` | `array` | أسعار بيع أصناف المجموعة من `group_resolve_prices` |
| `itemFlags` | `array` | علامات الأصناف لمجموعات التوزيع المرتبطة |
| `skipMap` | `array` | خريطة الأصناف المستبعدة لأصناف المجموعة |

### ج) دالة `inv_load_discount(?string $discInvId = null, ?string $discSuffix = null, ?float $discNetOverride = null, array $discItemPct = []): array`
| المتغيّر | النوع | الوصف ومصدر البيانات |
|---|---|---|
| `discUnposted` | `array` | الفواتير غير المرحّلة لاختيار الخصم منها |
| `discPosted` | `array` | الفواتير المرحّلة |
| `invoices` | `array` | دمج الفواتير غير المرحلة والمرحلة |
| `discInvId` | `?string` | معرف الفاتورة المطلوب حساب خصمها |
| `discSuffix` | `?string` | لاحقة الفاتورة |
| `discNetOverride` | `?float` | الصافي المطلوب تجاوزه |
| `discItemPct` | `array` | نسب الخصم المخصصة لبعض الأصناف |
| `discHeader` | `array\|null` | هيدر الفاتورة من `sqlsrv_fetch_invoice_header` |
| `discItems` | `array` | بنود الفاتورة الخام بدون دمج من `sqlsrv_fetch_invoice_items` |
| `discCalc` | `array\|null` | نتائج حساب الخصم من `calc_invoice_discount` |

### د) دالة `inv_load_list(string $tab = 'posted', string $invScope = 'admin'): array`
| المتغيّر | النوع | الوصف ومصدر البيانات |
|---|---|---|
| `invoices` | `array` | قائمة الفواتير من `sqlsrv_fetch_unposted_invoices` أو `sqlsrv_fetch_invoices(2026)` |
| `itemCountMap` | `array` | خريطة عدد الأصناف لكل فاتورة باستعلام مجمّع واحد عبر `hq_unposted_lines` أو `hq_invoice_lines` |
| `invoiceGroups` | `array` | تجميع الفواتير حسب الفرع وفلترتها حسب النطاق (`admin`, `branches`, `all`) |
| `adminCount` | `int` | عدد فواتير الإدارة |
| `branchCount` | `int` | عدد فواتير الفروع الأخرى |
| `invStatuses` | `array` | حالات الفواتير باستعلام واحد مجمع بـ `get_invoice_statuses` |
| `invNotes` | `array` | الفواتير التي تحتوي على ملاحظات بـ `get_invoices_with_notes` |

---

## ٣. نتائج اختبارات العقد (Contract Completeness)

```text
=== STARTING BRIEF 165b CONTRACT TESTS ===

--- Contract Case 1: Page Renders ---
PASS: All 10 page renders match 0 diff (cmp -s)
  - MATCH: case01_tab_posted.html
  - MATCH: case02_tab_unposted.html
  - MATCH: case03_scope_branches.html
  - MATCH: case04_open_purchase_dist.html
  - MATCH: case05_admin_return.html
  - MATCH: case06_branch_inv.html
  - MATCH: case07_tab_group.html
  - MATCH: case08_group_open.html
  - MATCH: case09_discount.html
  - MATCH: case10_discount_pct.html
TOTAL DIFFS: 0

--- Contract Case 2: API Endpoints (list, invoice, group, discount) ---
PASS: part=list (tab=posted, scope=admin) returns 200 and ok=true
PASS: part=list has invoices, invoiceGroups, adminCount, branchCount, invStatuses
PASS: part=list adminCount matches invoices count in groups
PASS: part=list (tab=unposted) returns 200 and ok=true
PASS: part=list (scope=branches) returns 200 and ok=true
PASS: part=list (scope=branches) branchCount matches
PASS: part=invoice (open purchase with dist) returns 200 and ok=true
PASS: part=invoice has openInvoice, items, distRecord, distId, totalQty
PASS: part=invoice (admin return dt=6) returns 200 and ok=true
PASS: part=invoice return has isReturnDoc=true and noDistDoc=true
PASS: part=invoice (branch invoice cb=5) returns 200 and ok=true
PASS: part=invoice branch invoice has isBranchInv=true and noDistDoc=true
PASS: part=group (list) returns 200 and ok=true
PASS: part=group list has grpCards
PASS: part=group&g=1 returns 200 and ok=true
PASS: part=group&g=1 has grpCurrent and grpData with items
PASS: part=discount returns 200 and ok=true
PASS: part=discount has discHeader, discItems, discCalc
PASS: part=discount with dpct returns 200 and ok=true
PASS: part=discount discItemPct parsed properly

--- Contract Case 3: Non-existent invoice ---
PASS: part=invoice with non-existent invoice fails cleanly (404/422)

--- Contract Case 4: ashraqa 403 ---
PASS: ashraqa rejected on part=list (403)
PASS: ashraqa rejected on part=invoice (403)
PASS: ashraqa rejected on part=group (403)
PASS: ashraqa rejected on part=discount (403)

--- Contract Case 5: POST rejection and bad part ---
PASS: POST on list returns 405 Method Not Allowed
PASS: part=xyz returns 400 bad_request

============================================
>>> ALL BRIEF 165b CONTRACT TESTS PASSED 100%! <<<
```

---

## ٤. فحص الديف وحجم التغيير في `site/invoices.php`

- عدد أسطر الديف في `site/invoices.php`: **٢٨١ سطر** (`git diff -- site/invoices.php | grep "^[-+]" | grep -v "^[-+][-+]" | wc -l`).
- التعديل محصور بدقة بين الأسطر ١٣٥٩ و ١٤٤٠ فقط (استبدال الكتلة الداخلية لاستدعاء الدوال الأربع مع الحفاظ التام على إعداد المتغيرات الافتراضية وهيكل الـ `try ... catch`).
- لا يوجد أي تغيير في كود الـ HTML نهائياً.

---

## ٥. حالة Git
- فرع العمل: `ds/brief165b` في مستودع `D:\fagala_wt_ds` (كوميت `1ea6827`).
- تم استيراد الكوميت بنجاح في `D:\fagala_store` (كوميت `e6f99b6`).
- لم يتم المساس بـ `ashraqa/` أو `local/` أو `finance.php`.
