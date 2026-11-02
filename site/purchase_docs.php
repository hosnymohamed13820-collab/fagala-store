<?php
/** purchase_docs.php — شاشة فاتورة شراء أو مرتجع مورد كاملة. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/labels_lib.php';

require_login();
require_module_access('purchase_docs');
sens_page();

$owner = is_real_owner();
$userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
$initialId = max(0, (int) ($_GET['id'] ?? 0));
$initialType = ($_GET['type'] ?? '') === 'purchase_return' ? 'purchase_return' : 'purchase';
$branchMissing = !$owner && $userBranch <= 0;
$canEditDate = $owner || (string) setting('purchase_date_editable', '1') !== '0';

if (!defined('PURCHASE_VIEW_ONLY')) define('PURCHASE_VIEW_ONLY', true);
require_once __DIR__ . '/purchase_docs_api.php';
$pdBoot = ['options'=>purchase_view_options(db(), $userBranch), 'document'=>null];
if ($initialId) {
    $pdBoot['document'] = purchase_view_doc(db(), $initialId);
    $initialType = $pdBoot['document']['doc']['doc_type'];
}

$pageTitle = $initialType === 'purchase_return' ? 'مرتجع مورد' : 'فاتورة شراء';
$active_module = 'purchase_docs';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.purchase-shell { display:flex; flex-direction:column; gap:var(--sp-2); max-width:1680px; margin:auto; padding-bottom:calc(var(--sp-4) * 10); }
.purchase-head, .purchase-title, .purchase-title-meta, .purchase-actions, .purchase-lines-head { display:flex; align-items:center; gap:var(--sp-2); flex-wrap:wrap; }
.purchase-head, .purchase-lines-head { justify-content:space-between; }
.purchase-head-fields { display:flex; align-items:end; gap:var(--sp-2); flex-wrap:wrap; }
.purchase-head-fields .ffield { flex:0 1 140px; }
.purchase-head-fields .purchase-supplier-code { flex-basis:175px; }
.purchase-head-fields .purchase-supplier { flex:2 1 280px; max-width:480px; }
.purchase-head-fields .purchase-notes { flex:1 1 280px; max-width:480px; }
.purchase-head-fields .purchase-type-field { flex-basis:auto; }
.purchase-head-fields .purchase-date { flex-basis:130px; }
.purchase-table-wrap { overflow:auto; }
.purchase-table { width:100%; table-layout:fixed; min-width:1300px; }
.purchase-table th, .purchase-table td { vertical-align:middle; }
.purchase-table .finput { width:100%; min-width:0; text-align:center; }
.purchase-table .line-name, .purchase-table .line-code-text { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.purchase-head-fields .finput, .purchase-head-fields .fselect { text-align:center; }
.purchase-summary-wrap { position:sticky; bottom:0; z-index:var(--z-dropdown); margin:0; }
.purchase-summary-wrap .purchase-totals { display:flex; align-items:center; justify-content:space-between; gap:var(--sp-3); flex-wrap:wrap; }
.purchase-total { display:flex; flex-direction:column; align-items:center; gap:var(--sp-1); }
.purchase-total .finput { width:82px; }
.purchase-shortcuts { display:flex; justify-content:center; gap:var(--sp-3); flex-wrap:wrap; font-size:var(--fs-xs); }
.purchase-search-results { max-height:50vh; overflow:auto; }
.purchase-search-results .btn { display:block; width:100%; text-align:right; white-space:normal; }
.purchase-origin[hidden], .purchase-event-list[hidden] { display:none; }
.purchase-event-list { font-size:var(--fs-xs); line-height:1.9; }
@media (max-width:600px) { .purchase-summary-wrap { position:static; } .purchase-shell { padding-bottom:var(--sp-2); } .purchase-head-fields .ffield { flex:1 1 100%; } .purchase-shortcuts { display:none; } .purchase-actions .btn { min-height:40px; } }
</style>

<main class="purchase-shell print-doc">
  <div class="fcard">
    <div class="purchase-head purchase-no-print">
      <div class="purchase-title">
        <i class="ti ti-package-import"></i>
        <h2 id="purchaseTitle" class="fpage-title fchip <?= $initialType === 'purchase_return' ? 'fchip-neg' : 'fchip-accent' ?>"><?= $initialType === 'purchase_return' ? 'مرتجع مورد جديد' : 'فاتورة شراء جديدة' ?></h2>
        <div class="purchase-title-meta">
          <span id="purchaseStatus" class="fchip fchip-warn">مسودة</span>
          <span id="purchaseDocNo" class="fchip fchip-mute">جديدة</span>
        </div>
      </div>
      <div class="purchase-actions">
        <button id="purchasePending" class="btn btn-outline" type="button">المعلّق (<span id="purchasePendingCount">0</span>)</button>
        <?php if (can('labels')): ?><button id="purchaseLabelsBtn" class="btn btn-outline" type="button"><i class="ti ti-printer"></i> طباعة ليبل</button><?php endif; ?>
        <?php if (can('purchase_docs:reports')): ?><a id="purchaseReport" class="btn btn-outline" href="reports.php?type=purchase_docs&amp;doc_type=<?= $initialType ?>"><?= $initialType === 'purchase_return' ? 'تقرير المرتجعات' : 'تقرير المشتريات' ?></a><?php endif; ?>
        <a class="btn btn-ghost" href="index.php">رجوع للرئيسية</a>
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
        <div class="ffield purchase-type-field">
          <label>نوع الفاتورة</label>
          <div class="ftabs" id="purchaseTypeTabs" aria-label="نوع المستند">
            <a href="purchase_docs.php?type=purchase" target="_blank" rel="noopener" class="ftab <?= $initialType === 'purchase' ? 'is-active' : '' ?>" data-type="purchase">شراء</a>
            <a href="purchase_docs.php?type=purchase_return" target="_blank" rel="noopener" class="ftab <?= $initialType === 'purchase_return' ? 'is-active' : '' ?>" data-type="purchase_return">مرتجع مورد</a>
          </div>
        </div>
        <label class="ffield purchase-supplier-code">كود المورد (Enter للتثبيت)
          <input class="finput finput-sm num" id="purchaseSupplierCode" maxlength="20" autocomplete="off" data-edit>
        </label>
        <label class="ffield purchase-supplier">اسم المورد (Ctrl + ↓ للقايمة)
          <input class="finput finput-sm" id="purchaseSupplier" autocomplete="off" placeholder="اكتب اسم المورد" data-edit>
        </label>
        <label class="ffield">فرع الاستلام
          <select class="fselect finput-sm" id="purchaseBranch" data-edit></select>
        </label>
        <label class="ffield purchase-date">تاريخ الفاتورة
          <input class="finput finput-sm" id="purchaseDate" type="date" value="<?= date('Y-m-d') ?>" <?= $canEditDate ? 'data-edit' : 'readonly' ?>>
          <?php if (!$canEditDate): ?><span class="fpage-sub">تغيير التاريخ مقفول من الإعدادات</span><?php endif; ?>
        </label>
        <label class="ffield">رقم فاتورة المورد
          <input class="finput finput-sm" id="purchaseRef" maxlength="100" data-edit>
        </label>
        <label id="purchaseOriginWrap" class="ffield purchase-origin" <?= $initialType === 'purchase' ? 'hidden' : '' ?>>مرتجع على فاتورة
          <select class="fselect" id="purchaseOrigin" data-edit><option value="">مرتجع من غير أصل</option></select>
        </label>
        <label class="ffield purchase-notes">ملاحظات
          <input class="finput finput-sm" id="purchaseNotes" maxlength="1000" data-edit>
        </label>
        <label id="purchaseReturnReasonWrap" class="ffield purchase-notes" <?= $initialType === 'purchase' ? 'hidden' : '' ?>>سبب المرتجع
          <input class="finput finput-sm" id="purchaseReturnReason" maxlength="255" data-edit>
        </label>
    </div>
  </div>

  <div class="fcard purchase-lines-panel">
    <div class="purchase-lines-head">
      <button id="purchaseFind" class="btn btn-sm btn-outline purchase-no-print" type="button"><i class="ti ti-search"></i> بحث صنف (F2)</button>
      <span class="purchase-barcode-hint purchase-no-print fpage-sub">اكتب أو امسح الباركود واضغط Enter</span>
      <button id="purchaseClear" class="btn btn-sm btn-ghost purchase-no-print" type="button" data-edit><i class="ti ti-trash"></i> مسح الفاتورة</button>
    </div>
    <div class="ftable-wrap purchase-table-wrap">
      <table class="ftable purchase-table" data-xt-off>
        <colgroup>
          <col style="width:38px"><col style="width:44px" class="purchase-no-print"><col style="width:170px"><col>
          <col style="width:80px"><col style="width:75px"><col style="width:90px"><col style="width:100px">
          <col style="width:85px"><col style="width:90px"><col style="width:95px"><col style="width:110px"><col style="width:40px" class="purchase-no-print">
        </colgroup>
        <thead><tr>
          <th class="num">#</th><th class="purchase-no-print">كاميرا</th><th>الكود</th><th>اسم الصنف</th><th>الوحدة</th>
          <th class="num">الرصيد</th><th class="num">الكمية</th><th class="num">التكلفة</th><th class="num">خصم %</th>
          <th class="num">خصم قيمة</th><th class="num">صافي التكلفة</th><th class="num">الإجمالي</th><th class="purchase-no-print"></th>
        </tr></thead>
        <tbody id="purchaseLines"></tbody>
      </table>
    </div>
    <div class="purchase-summary-wrap fcard purchase-no-print">
    <div class="purchase-shortcuts purchase-no-print">
      <span><kbd>Alt+1</kbd> شراء</span>
      <span><kbd>Alt+2</kbd> مرتجع</span>
      <span><kbd>Insert / Ctrl+N</kbd> الصف الفاضي</span>
      <span><kbd>Ctrl+Delete</kbd> مسح الصف</span>
      <span><kbd>F2</kbd> بحث صنف</span>
      <span><kbd>Esc</kbd> قفل البحث</span>
    </div>
      <div class="purchase-totals">
        <div class="purchase-total"><span>عدد الأصناف / القطع</span><strong><span id="purchaseItemsCount">0</span> أصناف · <span id="purchasePieces">0</span> قطعة</strong></div>
        <div class="purchase-total"><span>الإجمالي</span><strong id="purchaseTotalGross">0.00</strong></div>
        <div class="purchase-total"><span>خصم الأصناف</span><strong id="purchaseLineDiscount">0.00</strong></div>
        <div class="purchase-total"><span>خصم الفاتورة</span><div class="purchase-actions">
          <label>% <input id="purchaseDiscountPct" class="finput finput-sm num" type="number" min="0" max="100" step="0.0001" data-edit></label>
          <label>ج.م <input id="purchaseDiscountAmount" class="finput finput-sm num" type="number" min="0" step="0.0001" data-edit></label>
        </div></div>
        <div class="purchase-total"><span>الصافي النهائي للمورد</span><strong class="fchip fchip-pos"><span id="purchaseGrand">0.00</span> ج.م</strong><span id="purchaseGross" hidden>0.00</span></div>
      <div class="purchase-actions">
        <button id="purchasePrint" class="btn btn-outline" type="button"><i class="ti ti-printer"></i> طباعة</button>
        <span id="purchaseSaveState" class="purchase-save-state"></span>
        <?php if (can('purchase_docs:post')): ?><button id="purchasePost" class="btn btn-success" type="button">ترحيل</button><?php endif; ?>
        <?php if (can('purchase_docs:cancel')): ?><button id="purchaseCancel" class="btn btn-danger" type="button" hidden>إلغاء</button><?php endif; ?>
        <?php if (can('purchase_docs:create')): ?><button id="purchaseNew" class="btn btn-primary" type="button"><i class="ti ti-plus"></i> فاتورة جديدة</button><?php endif; ?>
      </div>
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

<div id="purchaseDraftModal" class="purchase-no-print" hidden>
  <div class="fg-dlg" role="dialog" aria-modal="true" aria-labelledby="purchaseDraftTitle" tabindex="-1">
    <h3 id="purchaseDraftTitle" class="fg-dlg-title">المستندات المعلّقة</h3>
    <div class="ftable-wrap purchase-search-results"><table class="ftable" id="purchaseDraftTable">
      <thead><tr><th>الرقم</th><th>المورد</th><th>التاريخ</th><th class="num">عدد الأصناف</th><th class="num">الإجمالي</th><th>آخر تعديل</th></tr></thead>
      <tbody id="purchaseDraftRows"></tbody>
    </table></div>
    <p id="purchaseDraftMessage" class="fpage-sub"></p>
    <div class="fg-dlg-actions"><button id="purchaseDraftPrev" class="btn btn-outline" type="button">السابق</button><button id="purchaseDraftNext" class="btn btn-outline" type="button">التالي</button><button id="purchaseDraftClose" class="btn btn-secondary" type="button">رجوع</button></div>
  </div>
</div>

<?php if (can('labels')): ?>
<div id="purchaseLabelsModal" class="purchase-no-print" hidden>
  <div class="fg-dlg" role="dialog" aria-modal="true" aria-labelledby="purchaseLabelsTitle" tabindex="-1" style="width:min(94vw, 680px);">
    <h3 id="purchaseLabelsTitle" class="fg-dlg-title"><i class="ti ti-printer"></i> طباعة ليبلز الفاتورة</h3>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--sp-2); gap:var(--sp-2); flex-wrap:wrap;">
      <div style="display:flex; gap:var(--sp-2);">
        <button type="button" class="btn btn-sm btn-outline" id="purchaseLabelsSelectAll">اختيار الكل</button>
        <button type="button" class="btn btn-sm btn-ghost" id="purchaseLabelsDeselectAll">شيل الكل</button>
      </div>
      <span id="purchaseLabelsSummary" class="fpage-sub"></span>
    </div>
    <div class="ftable-wrap" style="max-height:55vh; overflow-y:auto;">
      <table class="ftable" id="purchaseLabelsTable">
        <thead>
          <tr>
            <th style="width:44px;"><input type="checkbox" id="purchaseLabelsMasterCheck" aria-label="اختيار الكل"></th>
            <th style="width:140px;">الكود</th>
            <th>اسم الصنف</th>
            <th class="num" style="width:140px;">عدد الليبلز</th>
          </tr>
        </thead>
        <tbody id="purchaseLabelsRows"></tbody>
      </table>
    </div>
    <div id="purchaseLabelsError" class="falert falert-neg" style="margin-top:var(--sp-2);" hidden></div>
    <div class="fg-dlg-actions" style="margin-top:var(--sp-3);">
      <button id="purchaseLabelsPrint" class="btn btn-primary" type="button"><i class="ti ti-printer"></i> اطبع</button>
      <button id="purchaseLabelsClose" class="btn btn-secondary" type="button">رجوع</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script type="application/json" id="pdBoot"><?= json_encode($pdBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
window.PURCHASE_CONFIG = {
  csrf: <?= json_encode(csrf_token()) ?>,
  initialId: <?= $initialId ?>,
  initialType: <?= json_encode($initialType) ?>,
  owner: <?= $owner ? 'true' : 'false' ?>,
  canCreate: <?= can('purchase_docs:create') ? 'true' : 'false' ?>,
  canEditDate: <?= $canEditDate ? 'true' : 'false' ?>,
  canLabels: <?= can('labels') ? 'true' : 'false' ?>,
  labelsCols: <?= (int) (labels_settings()['cols'] ?? 1) ?>,
  labelsMax: <?= (int) LABELS_MAX ?>
};
</script>
<script src="assets/barcode_scanner.js?v=9"></script>
<script src="assets/purchase_docs.js?v=4" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
