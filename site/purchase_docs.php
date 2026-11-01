<?php
/** purchase_docs.php — شاشة فاتورة شراء أو مرتجع مورد كاملة. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';

require_login();
require_module_access('purchase_docs');
sens_page();

$owner = is_real_owner();
$userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
$initialId = max(0, (int) ($_GET['id'] ?? 0));
$initialType = ($_GET['type'] ?? '') === 'purchase_return' ? 'purchase_return' : 'purchase';
$branchMissing = !$owner && $userBranch <= 0;
$canEditDate = $owner || (string) setting('purchase_date_editable', '1') !== '0';

$pageTitle = $initialType === 'purchase_return' ? 'مرتجع مورد' : 'فاتورة شراء';
$active_module = 'purchase_docs';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.purchase-shell { display:flex; flex-direction:column; gap:var(--sp-3); min-height:calc(100vh - 130px); }
.purchase-head { display:flex; justify-content:space-between; align-items:center; gap:var(--sp-3); flex-wrap:wrap; }
.purchase-title { display:flex; align-items:center; gap:var(--sp-2); }
.purchase-title-meta { display:inline-flex; gap:var(--sp-2); align-items:center; }
.purchase-actions { display:flex; gap:var(--sp-2); align-items:center; flex-wrap:wrap; }
.purchase-save-state { min-width:80px; font-size:var(--fs-xs); color:var(--ink-500); }
.purchase-save-state.is-ok { color:var(--pos); }
.purchase-save-state.is-bad { color:var(--neg); }
.purchase-head-fields { display:flex; flex-direction:column; gap:var(--sp-3); margin-top:var(--sp-3); }
.purchase-head-row-1 { display:grid; grid-template-columns:auto 1.5fr 1fr 1fr; gap:var(--sp-3); align-items:end; }
.purchase-head-row-2 { display:grid; grid-template-columns:1fr 1fr 2fr; gap:var(--sp-3); align-items:end; }
.purchase-lines-panel { flex:1; display:flex; flex-direction:column; min-height:360px; }
.purchase-lines-head { display:flex; justify-content:space-between; align-items:center; gap:var(--sp-3); margin-bottom:var(--sp-2); }
.purchase-table-wrap { overflow:auto; flex:1; }
.purchase-table { width:100%; table-layout:fixed; }
.purchase-table th, .purchase-table td { vertical-align:middle; }
.purchase-table .line-index { text-align:center; color:var(--ink-500); }
.purchase-code-wrap { display:flex; align-items:center; gap:var(--sp-1); }
.purchase-code-wrap .finput { min-width:0; width:100%; }
.purchase-code-wrap .btn { flex:0 0 auto; }
.purchase-table .finput { width:100%; min-width:0; }
.purchase-table .line-name, .purchase-table .line-code-text { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.purchase-table .line-total { font-variant-numeric:tabular-nums; font-weight:700; }
.purchase-shortcuts { display:flex; gap:var(--sp-3); flex-wrap:wrap; padding:var(--sp-2) var(--sp-1); font-size:var(--fs-xs); color:var(--ink-500); }
.purchase-shortcuts kbd { font:inherit; font-weight:700; }
.purchase-summary-wrap { margin-top:var(--sp-3); }
.purchase-search-results { max-height:50vh; overflow:auto; }
.purchase-search-results .btn { display:block; width:100%; text-align:right; white-space:normal; }
.purchase-origin[hidden], .purchase-event-list[hidden] { display:none; }
.purchase-event-list { font-size:var(--fs-xs); line-height:1.9; color:var(--ink-500); }
@media (max-width:1100px) { .purchase-table { min-width:1020px; } }
@media (max-width:900px) { .purchase-head-row-1, .purchase-head-row-2 { grid-template-columns:1fr 1fr; } }
@media (max-width:600px) { .purchase-head-row-1, .purchase-head-row-2 { grid-template-columns:1fr; } }
</style>

<main class="purchase-shell print-doc">
  <div class="fcard">
    <div class="purchase-head purchase-no-print">
      <div class="purchase-title">
        <i class="ti ti-package-import"></i>
        <h2 id="purchaseTitle"><?= $initialType === 'purchase_return' ? 'مرتجع مورد جديد' : 'فاتورة شراء جديدة' ?></h2>
        <div class="purchase-title-meta">
          <span id="purchaseDocNo" class="fchip fchip-mute">جديدة</span>
          <span id="purchaseStatus" class="fchip fchip-warn">مسودة</span>
        </div>
      </div>
      <div class="purchase-actions">
        <span id="purchaseSaveState" class="purchase-save-state"></span>
        <?php if (can('purchase_docs:post')): ?><button id="purchasePost" class="btn btn-success" type="button">ترحيل</button><?php endif; ?>
        <?php if (can('purchase_docs:cancel')): ?><button id="purchaseCancel" class="btn btn-danger" type="button" hidden>إلغاء</button><?php endif; ?>
        <button id="purchasePrint" class="btn btn-outline" type="button"><i class="ti ti-printer"></i> طباعة</button>
        <?php if (can('purchase_docs:create')): ?><button id="purchaseNew" class="btn btn-primary" type="button"><i class="ti ti-plus"></i> فاتورة جديدة</button><?php endif; ?>
        <?php if (can('purchase_docs:reports')): ?><a class="btn btn-outline" href="reports.php?type=purchase_docs">كل الفواتير ← التقارير</a><?php endif; ?>
      </div>
    </div>

    <?php if ($branchMissing): ?><div class="falert falert-neg">حسابك مش مربوط بفرع، مش هينفع تحفظ المستند.</div><?php endif; ?>
    <div id="purchaseReadonlyNote" class="falert falert-info" hidden>المستند ده للقراءة بس.</div>
    <div id="purchaseMessage"></div>

    <!-- هيدر الطباعة فقط -->
    <div class="print-doc-header print-only">
      <div class="print-doc-header-row">
        <div>
          <strong style="font-size:16px;"><?= htmlspecialchars((string) setting('store_name', 'الفجالة ستور'), ENT_QUOTES, 'UTF-8') ?></strong>
          <span style="margin-inline-start:12px; font-weight:700;" id="printDocTitle"><?= $initialType === 'purchase_return' ? 'مرتجع مورد' : 'فاتورة شراء' ?></span>
          <span style="margin-inline-start:8px;" id="printDocNo">جديدة</span>
        </div>
      </div>
      <div class="print-doc-meta-row">
        <span><strong>التاريخ:</strong> <span id="printDocDate"><?= date('Y-m-d') ?></span></span>
        <span><strong>المورد:</strong> <span id="printSupplier">—</span></span>
        <span><strong>الفرع:</strong> <span id="printBranch">—</span></span>
        <span id="printRefWrap" hidden><strong>رقم فاتورة المورد:</strong> <span id="printRef"></span></span>
      </div>
    </div>

    <input id="purchaseDocId" type="hidden" value="<?= $initialId ?>">
    <input id="purchaseSupplierId" type="hidden">
    <input id="purchaseType" type="hidden" value="<?= htmlspecialchars($initialType, ENT_QUOTES, 'UTF-8') ?>">

    <div class="purchase-head-fields">
      <div class="purchase-head-row-1">
        <div class="ffield">
          <label>النوع</label>
          <div class="ftabs" id="purchaseTypeTabs" role="tablist" aria-label="نوع المستند">
            <button type="button" class="ftab <?= $initialType === 'purchase' ? 'is-active' : '' ?>" data-type="purchase" data-edit>شراء</button>
            <button type="button" class="ftab <?= $initialType === 'purchase_return' ? 'is-active' : '' ?>" data-type="purchase_return" data-edit>مرتجع مورد</button>
          </div>
        </div>
        <label class="ffield">المورد (بحث)
          <input class="finput" id="purchaseSupplier" list="purchaseSupplierList" autocomplete="off" placeholder="اكتب اسم المورد" data-edit>
          <datalist id="purchaseSupplierList"></datalist>
        </label>
        <label class="ffield">الفرع
          <select class="fselect" id="purchaseBranch" data-edit></select>
        </label>
        <label class="ffield">التاريخ
          <input class="finput" id="purchaseDate" type="date" value="<?= date('Y-m-d') ?>" <?= $canEditDate ? 'data-edit' : 'readonly' ?>>
          <?php if (!$canEditDate): ?>
            <span class="fpage-sub" style="font-size:11px; margin-top:2px;">تغيير التاريخ مقفول من الإعدادات</span>
          <?php endif; ?>
        </label>
      </div>

      <div class="purchase-head-row-2">
        <label class="ffield">رقم فاتورة المورد
          <input class="finput" id="purchaseRef" maxlength="100" data-edit>
        </label>
        <label id="purchaseOriginWrap" class="ffield purchase-origin" hidden>مرتجع على فاتورة
          <select class="fselect" id="purchaseOrigin" data-edit><option value="">مرتجع من غير أصل</option></select>
        </label>
        <label class="ffield purchase-notes">ملاحظات
          <input class="finput" id="purchaseNotes" maxlength="1000" data-edit>
        </label>
      </div>
    </div>
  </div>

  <div class="fcard purchase-lines-panel">
    <div class="purchase-lines-head">
      <h3 class="fcard-title" style="margin:0;">الأصناف</h3>
      <span class="purchase-barcode-hint purchase-no-print fpage-sub">اكتب أو امسح الباركود واضغط Enter</span>
    </div>
    <div class="ftable-wrap purchase-table-wrap">
      <table class="ftable purchase-table">
        <colgroup>
          <col style="width:30px">
          <col style="width:120px">
          <col>
          <col style="width:74px">
          <col style="width:65px">
          <col style="width:72px">
          <col style="width:80px">
          <col style="width:62px">
          <col style="width:90px">
          <col style="width:86px">
          <col style="width:76px" class="purchase-no-print">
        </colgroup>
        <thead>
          <tr>
            <th>#</th>
            <th>الكود</th>
            <th>الاسم</th>
            <th>الوحدة</th>
            <th class="num">الرصيد</th>
            <th class="num">الكمية</th>
            <th class="num">التكلفة</th>
            <th class="num">خصم %</th>
            <th class="num">صافي سعر التكلفة</th>
            <th class="num">الإجمالي</th>
            <th class="purchase-no-print"></th>
          </tr>
        </thead>
        <tbody id="purchaseLines"></tbody>
      </table>
    </div>
    <div class="purchase-shortcuts purchase-no-print">
      <span><kbd>Alt+1</kbd> شراء</span>
      <span><kbd>Alt+2</kbd> مرتجع</span>
      <span><kbd>Insert / Ctrl+N</kbd> الصف الفاضي</span>
      <span><kbd>Ctrl+Delete</kbd> مسح الصف</span>
      <span><kbd>F2</kbd> بحث صنف</span>
      <span><kbd>Esc</kbd> قفل البحث</span>
    </div>
    <div class="purchase-summary-wrap">
      <div class="fkpis">
        <div class="fkpi"><span>عدد الأصناف</span><strong id="purchaseItemsCount">0</strong></div>
        <div class="fkpi"><span>إجمالي القطع</span><strong id="purchasePieces">0</strong></div>
        <div class="fkpi"><span>قبل الخصم</span><strong id="purchaseGross">0.00</strong></div>
        <div class="fkpi">
          <span>خصم الفاتورة %</span>
          <input id="purchaseDiscountPct" class="finput finput-sm num" type="number" min="0" max="100" step="0.0001" data-edit style="width:100%; margin-top:4px;">
        </div>
        <div class="fkpi">
          <span>خصم الفاتورة قيمة</span>
          <input id="purchaseDiscountAmount" class="finput finput-sm num" type="number" min="0" step="0.0001" data-edit style="width:100%; margin-top:4px;">
        </div>
        <div class="fkpi"><span>الصافي</span><strong id="purchaseGrand">0.00</strong></div>
      </div>
    </div>
    <div class="print-summary print-only">
      <span>عدد الأصناف: <strong id="printItemsCount">0</strong></span>
      <span>إجمالي القطع: <strong id="printPieces">0</strong></span>
      <span>قبل الخصم: <strong id="printGross">0.00</strong></span>
      <span>الخصم: <strong id="printDiscount">0.00</strong></span>
      <span>الصافي: <strong id="printGrand">0.00</strong></span>
    </div>
  </div>

  <div id="purchaseEvents" class="fcard purchase-event-list" hidden></div>
</main>

<div id="purchaseSearch" class="purchase-search-bg purchase-no-print" hidden>
  <div class="fg-dlg" role="dialog" aria-modal="true" aria-labelledby="purchaseSearchTitle">
    <h3 id="purchaseSearchTitle" class="fg-dlg-title">بحث الأصناف</h3>
    <label class="ffield">اسم الصنف<input id="purchaseSearchTerm" class="finput" autocomplete="off"></label>
    <div id="purchaseSearchResults" class="purchase-search-results"></div>
    <div class="fg-dlg-actions"><button id="purchaseSearchClose" class="btn btn-secondary" type="button">رجوع</button></div>
  </div>
</div>

<script>
window.PURCHASE_CONFIG = {
  csrf: <?= json_encode(csrf_token()) ?>,
  initialId: <?= $initialId ?>,
  initialType: <?= json_encode($initialType) ?>,
  owner: <?= $owner ? 'true' : 'false' ?>,
  canCreate: <?= can('purchase_docs:create') ? 'true' : 'false' ?>,
  canEditDate: <?= $canEditDate ? 'true' : 'false' ?>
};
</script>
<script src="assets/barcode_scanner.js?v=9"></script>
<script src="assets/purchase_docs.js?v=1" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
