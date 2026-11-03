<?php
/**
 * pos.php — شاشة نقطة البيع (عرض فقط، الداتا والكتابة من الـAPI).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sale_lib.php';
require_module_access('pos');
if (!can('pos:sell') && !can('pos:return_ref') && !can('pos:return_general') && !is_real_owner()) {
    http_response_code(403);
    exit('مالكش صلاحية في نقطة البيع.');
}

$page_title = 'نقطة البيع';
$active_module = 'pos';
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* إخفاء التنبيهات العامة في شاشة نقطة البيع فقط (بريف 827e) */
#agtNag,
#pushNag,
#shrNag {
    display: none !important;
    margin: 0 !important;
    padding: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
}

.layout {
    min-height: auto;
}

.pos-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: var(--sp-3);
    align-items: start;
    margin-bottom: 50px;
}
@media (max-width: 960px) {
    .pos-layout {
        grid-template-columns: minmax(0, 1fr);
    }
}
/* سطر معلومات واحد (بريف 827e) */
.pos-info-bar {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    flex-wrap: wrap;
    margin-bottom: var(--sp-2);
}
/* سطر زراير واحد: تابات + عمليات (بريف 827e) */
.pos-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-2);
    flex-wrap: wrap;
    margin-bottom: var(--sp-2);
}
.pos-actions-btns {
    display: flex;
    align-items: center;
    gap: var(--sp-1);
    flex-wrap: wrap;
}
/* سطر أزرار فوق الجدول (بريف 827e) */
.pos-table-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-2);
    margin-bottom: var(--sp-1);
    flex-wrap: wrap;
}
.pos-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-2);
    flex-wrap: wrap;
    margin-bottom: var(--sp-2);
}
.pos-top-info {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    flex-wrap: wrap;
}
.pos-top-actions {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    flex-wrap: wrap;
}
.pos-cart-panel {
    display: flex;
    flex-direction: column;
    gap: var(--sp-2);
}
.pos-scan-box {
    position: relative;
    display: flex;
    gap: var(--sp-2);
    align-items: center;
}
.pos-scan-input-wrap {
    flex: 1;
    position: relative;
}
.pos-search-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    left: 0;
    z-index: var(--z-dropdown);
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-lg);
    max-height: 280px;
    overflow-y: auto;
    margin-top: 4px;
}
.pos-search-item {
    padding: var(--sp-2);
    border-bottom: 1px solid var(--line-soft);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.pos-search-item.is-selected,
.pos-search-item:hover {
    background: var(--accent-soft);
}
.pos-customer-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--sp-1);
    background: var(--surface-soft);
    border: 1px solid var(--line);
    border-radius: var(--r-pill);
    padding: 2px 10px;
    font-size: var(--fs-sm);
}
/* الجدول ياخد اللي فاضل من الشاشة (بريف 827f) — حساب ديناميكي بـJS والـCSS fallback */
.pos-cart-table-wrap {
    height: calc(100vh - 380px);
    min-height: 240px;
    overflow-y: auto;
    overflow-x: auto;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    margin-bottom: var(--sp-2);
}
.pos-cart-table-wrap .table-scroll {
    display: contents;
}
.pos-cart-table-wrap thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--bg);
    padding: var(--sp-2) var(--sp-2);
    white-space: nowrap;
}
.pos-cart-table-wrap td {
    padding: var(--sp-1) var(--sp-2);
    white-space: nowrap;
}
.pos-cart-table-wrap td.fcell {
    padding: 2px 4px;
}
#posViewExchange .pos-cart-panel, #posViewExchange .fcard { min-width:0; }
#posExchSaleTable { min-width:1110px; }
#posExchSaleTable th:nth-child(4), #posExchSaleTable td:nth-child(4) { min-width:180px; }
.pos-row-btn {
    padding: 2px 6px;
    min-width: 0;
}
@media (max-width: 600px) {
    .pos-cart-table-wrap {
        height: auto;
        max-height: 55vh;
        min-height: 240px;
    }
}
.pos-item-name {
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    vertical-align: middle;
}
.pos-qty-wrap {
    display: flex;
    align-items: center;
    gap: 4px;
}
.pos-totals-panel {
    position: sticky;
    top: var(--sp-3);
    display: flex;
    flex-direction: column;
    gap: var(--sp-2);
}
.pos-net-box {
    text-align: center;
    padding: var(--sp-3);
    background: var(--surface-soft);
    border-radius: var(--r-lg);
    border: 1px solid var(--line);
}
.pos-net-title {
    font-size: var(--fs-sm);
    color: var(--text-muted);
    margin-bottom: 4px;
}
.pos-net-val {
    font-size: var(--fs-3xl);
    font-weight: 700;
    color: var(--accent-text);
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.pos-net-currency {
    font-size: var(--fs-sm);
    color: var(--text-muted);
    margin-right: 4px;
}
.pos-summary-list {
    display: flex;
    flex-direction: column;
    gap: var(--sp-2);
    padding: var(--sp-2) 0;
    border-top: 1px solid var(--line-soft);
    border-bottom: 1px solid var(--line-soft);
}
.pos-summary-row {
    display: flex;
    justify-content: space-between;
    font-size: var(--fs-md);
}
.pos-summary-row .pos-val {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}
/* شريط الإجماليات الثابت بالأسفل — أقسام منظمة بخط فاصل رأسي (بريف 827g) */
.pos-totals-bar {
    position: sticky;
    bottom: 0;
    z-index: 10;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    padding: var(--sp-2) var(--sp-3);
    margin-bottom: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.04);
}
.pos-tot-sec {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 0 var(--sp-3);
    border-inline-start: 1px solid var(--line-soft);
    min-height: 52px;
}
.pos-tot-sec:first-child {
    border-inline-start: none;
    padding-inline-start: 0;
}
.pos-tot-title {
    font-size: var(--fs-sm);
    color: var(--ink-500);
    font-weight: 500;
    margin-bottom: 2px;
}
.pos-tot-value {
    font-size: var(--fs-lg);
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: var(--ink-900);
    line-height: 1.2;
}
.pos-tot-inputs {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
}
.pos-input-affix-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    width: 100px;
}
.pos-input-affix-wrap .pos-affix {
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: var(--fs-xs);
    color: var(--ink-500);
    pointer-events: none;
    font-weight: 500;
}
.pos-tot-net {
    background: var(--surface-soft);
    border-radius: var(--r-md);
    padding: var(--sp-2) var(--sp-4);
    border-inline-start: none;
    align-items: flex-end;
}
.pos-tot-net .pos-tot-title {
    color: var(--ink-700);
    font-weight: 600;
}
.pos-net-val-wrap {
    display: flex;
    align-items: baseline;
    gap: 4px;
}
.pos-net-val {
    font-size: var(--fs-3xl);
    font-weight: 700;
    color: var(--pos);
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.pos-net-currency {
    font-size: var(--fs-xs);
    color: var(--ink-500);
}
@media (max-width: 600px) {
    .pos-totals-bar {
        flex-wrap: wrap;
        gap: var(--sp-2);
    }
    .pos-tot-sec {
        border-inline-start: none;
        padding: var(--sp-1) 0;
    }
    .pos-tot-net {
        width: 100%;
        align-items: center;
        text-align: center;
        justify-content: center;
    }
}
.pos-shortcuts-bar {
    position: fixed;
    bottom: 0;
    right: 0;
    left: 0;
    z-index: var(--z-sticky);
    background: var(--surface);
    border-top: 1px solid var(--line);
    padding: 4px 12px;
    display: flex;
    gap: var(--sp-2);
    flex-wrap: wrap;
    justify-content: center;
    font-size: var(--fs-xs);
    color: var(--text-muted);
    min-height: 28px;
    align-items: center;
}
.pos-shortcut-key {
    display: inline-block;
    padding: 1px 5px;
    background: var(--line-soft);
    border-radius: var(--r-xs);
    font-weight: 600;
    color: var(--text);
    margin-left: 2px;
}
.pos-change-box {
    text-align: center;
    padding: var(--sp-3);
    border-radius: var(--r-md);
    background: var(--pos-soft);
    color: var(--pos);
    font-weight: 700;
    font-size: var(--fs-xl);
    margin-top: var(--sp-2);
    font-variant-numeric: tabular-nums;
}
.pos-change-box.is-due-remain {
    background: var(--warn-soft);
    color: var(--warn);
}
</style>

<main class="content-container" id="posContainer"
      data-can-sell="<?= (can('pos:sell') || is_real_owner()) ? '1' : '0' ?>"
      data-can-return-ref="<?= (can('pos:return_ref') || is_real_owner()) ? '1' : '0' ?>"
      data-can-return-general="<?= (can('pos:return_general') || is_real_owner()) ? '1' : '0' ?>"
      data-can-drawer="<?= (can('pos:drawer') || is_real_owner()) ? '1' : '0' ?>"
      data-can-held-cancel="<?= (can('pos:held_cancel') || is_real_owner()) ? '1' : '0' ?>"
      data-can-shift-expected="<?= sale_shift_expected_visible() ? '1' : '0' ?>"
      data-can-devices="<?= (can('pos:devices') || is_real_owner()) ? '1' : '0' ?>">

    <!-- سطر معلومات واحد (بريف 827e): شارات fchip صغيرة — الفرع · الجهاز · الوردية · التاريخ · البائع — وزرار العميل -->
    <div class="pos-info-bar">
        <span id="posBranchBadge" class="fchip fchip-mute">فرع: --</span>
        <span id="posDeviceBadge" class="fchip fchip-mute">جهاز: --</span>
        <button type="button" class="btn btn-sm btn-ghost" id="posBtnSwitchDevice" title="تغيير الجهاز">
            <i class="ti ti-device-desktop"></i>
        </button>
        <span id="posSessionBadge" class="fchip fchip-warn">الوردية: --</span>
        <span id="posDateBadge" class="fchip fchip-mute">تاريخ: --</span>
        <span id="posSellerBadge" class="fchip fchip-mute">بائع: --</span>
        <button type="button" class="btn btn-sm btn-outline" id="posBtnCustomer">
            <i class="ti ti-users"></i> <span id="posCustomerName">نقدي</span>
        </button>
        <button type="button" class="btn btn-sm btn-ghost" id="posBtnRemoveCustomer" title="إلغاء العميل" style="display:none;">
            <i class="ti ti-x"></i>
        </button>
        <!-- الخانات الأصلية مخفية للحفاظ على التوافق مع أي سكريبتات قائمة -->
        <input type="hidden" id="posInvoiceBranch">
        <input type="hidden" id="posInvoiceDevice">
        <input type="hidden" id="posInvoiceDate">
        <input type="hidden" id="posInvoiceSeller">
        <div id="posCustomerBar" style="display:none;"></div>
        <?php if (can('pos:reports') || is_real_owner()): ?>
            <a class="btn btn-sm btn-ghost" href="pos_reports.php" style="margin-inline-start:auto;" title="تقارير نقطة البيع"><i class="ti ti-chart-bar"></i> تقارير</a>
        <?php endif; ?>
    </div>

    <div id="posAlert"></div>

    <!-- سطر زراير واحد (بريف 827e): تابات أوضاع نقطة البيع + أزرار العمليات -->
    <div class="pos-actions-bar">
        <div class="ftabs" id="posModeTabs">
            <button type="button" class="ftab is-active" data-mode="sale" id="posTabSale">
                <i class="ti ti-shopping-cart"></i> بيع (F1)
            </button>
            <button type="button" class="ftab" data-mode="return" id="posTabReturn">
                <i class="ti ti-arrow-back-up"></i> مرتجع (F7)
            </button>
            <?php if (can('pos:return_general') || is_real_owner()): ?>
            <button type="button" class="ftab" data-mode="return_general" id="posTabReturnGeneral">
                <i class="ti ti-arrow-down-left"></i> مرتجع عام
            </button>
            <?php endif; ?>
            <button type="button" class="ftab" data-mode="exchange" id="posTabExchange">
                <i class="ti ti-transfer"></i> استبدال
            </button>
        </div>
        <div class="pos-actions-btns">
            <button type="button" class="btn btn-sm btn-outline" id="posBtnHold">
                <i class="ti ti-bookmark"></i> تعليق (F8)
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnHeldList">
                <i class="ti ti-clock"></i> المعلّق (F9)
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnSessionInvoices" title="فواتير الوردية">
                <i class="ti ti-file-invoice"></i> فواتير الوردية
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnPrintLast" style="display:none;" title="طباعة آخر إيصال">
                <i class="ti ti-printer"></i> آخر إيصال
            </button>
            <?php if (can('pos:drawer') || is_real_owner()): ?>
                <button type="button" class="btn btn-sm btn-ghost" id="posBtnOpenDrawer">
                    <i class="ti ti-cash"></i> فتح الدرج (F3)
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-success" id="posBtnOpenSession" style="display:none;">
                <i class="ti ti-plus"></i> فتح وردية
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="posBtnCloseSession" style="display:none;">
                <i class="ti ti-lock"></i> تقفيل الوردية
            </button>
        </div>
    </div>

    <!-- وضع البيع (الافتراضي) -->
    <div id="posViewSale">
        <div id="posSaleItemsPanel">
            <!-- سطر صغير فوق الجدول: أزرار العمليات (بريف 827e) -->
            <div class="pos-table-toolbar">
                <div style="display:flex; align-items:center; gap:var(--sp-2);">
                    <button type="button" class="btn btn-sm btn-outline" id="posBtnOpenItemSearch">
                        <i class="ti ti-search"></i> بحث صنف (F2)
                    </button>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-ghost" id="posBtnClearCart">
                        <i class="ti ti-trash"></i> مسح الفاتورة
                    </button>
                </div>
            </div>

            <!-- الجدول ياخد باقي الشاشة بعرض أعمدة ثابت (بريف 827g) -->
            <div class="ftable-wrap pos-cart-table-wrap">
                <table class="ftable" id="posCartTable" data-xt-off style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 40px;">
                        <col style="width: 48px;">
                        <col style="width: 150px;">
                        <col style="width: auto;">
                        <col style="width: 70px;">
                        <col style="width: 70px;">
                        <col style="width: 90px;">
                        <col style="width: 90px;">
                        <col style="width: 100px;">
                        <col style="width: 90px;">
                        <col style="width: 100px;">
                        <col style="width: 44px;">
                    </colgroup>
                    <thead><tr>
                        <th class="num">#</th><th>كاميرا</th><th>الكود</th><th>الاسم</th><th>الوحدة</th>
                        <th class="num">الرصيد</th><th class="num">الكمية</th><th class="num">السعر</th>
                        <th class="num">الإجمالي</th><th class="num">خصم %</th><th class="num">خصم قيمة</th><th></th>
                    </tr></thead>
                    <tbody id="posCartBody"></tbody>
                </table>
            </div>
            <div id="posCartEmpty"></div>

            <!-- شريط الإجماليات الثابت بالأسفل — شكل جديد منظم (بريف 827g) -->
            <div class="pos-totals-bar">
                <div class="pos-tot-sec pos-tot-counts">
                    <span class="pos-tot-title">عدد الأصناف / القطع</span>
                    <strong id="posItemsPiecesDisplay" class="pos-tot-value">0 صنف · 0 قطعة</strong>
                </div>
                <div class="pos-tot-sec pos-tot-gross">
                    <span class="pos-tot-title">الإجمالي</span>
                    <strong id="posGrossDisplay" class="pos-tot-value">0.00</strong>
                </div>
                <div class="pos-tot-sec pos-tot-linedisc">
                    <span class="pos-tot-title">خصم الأصناف</span>
                    <strong id="posLineDiscDisplay" class="pos-tot-value">0.00</strong>
                </div>
                <div class="pos-tot-sec pos-tot-invdisc">
                    <span class="pos-tot-title">خصم الفاتورة</span>
                    <div class="pos-tot-inputs">
                        <div class="pos-input-affix-wrap">
                            <input type="number" class="finput num" id="posInvDiscPct" min="0" step="0.001" value="0">
                            <span class="pos-affix">%</span>
                        </div>
                        <div class="pos-input-affix-wrap">
                            <input type="number" class="finput num" id="posInvDiscAmount" min="0" step="0.01" value="0">
                            <span class="pos-affix">ج.م</span>
                        </div>
                    </div>
                </div>
                <div class="pos-tot-sec pos-tot-net">
                    <span class="pos-tot-title">الصافي</span>
                    <div class="pos-net-val-wrap">
                        <strong id="posNetDisplay" class="pos-net-val">0.00</strong>
                        <span class="pos-net-currency">ج.م</span>
                    </div>
                </div>
            </div>
            <span id="posInvDiscDisplay" style="display:none;">0.00</span>
            <span id="posTotalDiscDisplay" style="display:none;">0.00</span>
            <span id="posLinesCount" style="display:none;">0</span>
            <span id="posPiecesCount" style="display:none;">0</span>
            <button type="button" id="posBtnPay" style="display:none;" disabled></button>
        </div>

        <div id="posPaymentPanel" style="display:none;">
            <div class="fkpis">
                <div class="fkpi"><span>الصافي</span><strong><span id="posPayModalNet" style="font-size:var(--fs-3xl);">0.00</span></strong></div>
                <div class="fkpi"><span>المدفوع</span><strong><span id="posPaidTotal" style="font-size:var(--fs-3xl);">0.00</span></strong></div>
                <div class="fkpi"><span>المتبقي</span><strong><span id="posRemainingTotal" style="font-size:var(--fs-3xl);">0.00</span></strong></div>
            </div>
            <div id="posPaymentModalAlert"></div>
            <div class="ftable-wrap" style="margin-top:var(--sp-2);">
                <table class="ftable" data-xt-off>
                    <thead><tr><th>النوع</th><th class="num">المبلغ</th><th>ملاحظات</th><th></th></tr></thead>
                    <tbody id="posPaymentRowsContainer"></tbody>
                </table>
            </div>
            <div id="posChangeDueBox" class="falert falert-info">المتبقي: 0.00 ج.م</div>
            <div class="fcard-actions" style="margin-top:var(--sp-2);">
                <button type="button" class="btn btn-sm btn-outline" id="posBtnAddPaymentRow"><i class="ti ti-plus"></i> إضافة سطر دفع</button>
                <button type="button" class="btn btn-primary" id="posBtnConfirmFinalize" disabled><i class="ti ti-device-floppy"></i> حفظ وضرب الفاتورة (F12)</button>
                <button type="button" class="btn btn-secondary" id="posBtnClosePaymentModal">رجوع للأصناف (F6)</button>
            </div>
        </div>
    </div>

    <!-- وضع المرتجع على فاتورة (F6) -->
    <div id="posViewReturn" style="display:none;">
        <div class="fcard" style="margin-bottom:var(--sp-2);">
            <div style="display:flex; gap:var(--sp-2); align-items:center;">
                <input type="text" class="finput" id="posReturnSearchInput"
                       placeholder="اكتب أو امسح رقم الإيصال الأصلي (مثال: 1/B238T/2026/1) أو رقم الفاتورة..."
                       autocomplete="off" style="flex:1;">
                <button type="button" class="btn btn-outline" id="posBtnReturnCamera" title="امسح بالكاميرا">
                    <i class="ti ti-camera"></i>
                </button>
                <button type="button" class="btn btn-primary" id="posBtnReturnSearch">
                    <i class="ti ti-search"></i> بحث
                </button>
            </div>
        </div>

        <div id="posReturnDocInfoBox" style="display:none; margin-bottom:var(--sp-2);">
            <div class="fcard">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--sp-2);">
                    <div style="display:flex; align-items:center; gap:var(--sp-2); flex-wrap:wrap;">
                        <span class="fchip fchip-mute" id="posReturnDocReceipt">إيصال: --</span>
                        <span class="fchip fchip-mute" id="posReturnDocDate">تاريخ: --</span>
                        <span class="fchip fchip-mute" id="posReturnDocCashier">كاشير: --</span>
                        <span class="fchip fchip-mute" id="posReturnDocCustomer" style="display:none;">عميل: --</span>
                        <span id="posReturnLateBadge" class="fchip fchip-warn" style="display:none;">
                            <i class="ti ti-alert-triangle"></i> أقدم من ١٤ يوم (يحتاج اعتماد مشرف)
                        </span>
                    </div>
                    <div>
                        <span class="pos-label">صافي الفاتورة الأصلية: </span>
                        <strong id="posReturnDocNet" class="pos-val">0.00</strong> ج.م
                    </div>
                </div>
            </div>
        </div>

        <div class="pos-layout" id="posReturnLayout" style="display:none;">
            <div class="pos-cart-panel">
                <div class="ftable-wrap pos-cart-table-wrap">
                    <table class="ftable" id="posReturnLinesTable" data-xt-off style="table-layout: fixed; width: 100%;">
                        <colgroup>
                            <col style="width: 130px;">
                            <col style="width: auto;">
                            <col style="width: 65px;">
                            <col style="width: 65px;">
                            <col style="width: 65px;">
                            <col style="width: 65px;">
                            <col style="width: 85px;">
                            <col style="width: 85px;">
                            <col style="width: 90px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>الوحدة</th>
                                <th class="num">المباع</th>
                                <th class="num">مردود</th>
                                <th class="num">المتاح</th>
                                <th class="num">المرتجع</th>
                                <th class="num">سعر القطعة</th>
                                <th class="num">الصافي</th>
                            </tr>
                        </thead>
                        <tbody id="posReturnLinesBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="pos-totals-panel">
                <div class="fcard">
                    <div class="pos-net-box">
                        <div class="pos-net-title">صافي المرتجع المسترد</div>
                        <div class="pos-net-val">
                            <span id="posReturnNetDisplay">0.00</span>
                            <span class="pos-net-currency">ج.م</span>
                        </div>
                    </div>

                    <div class="pos-summary-list">
                        <div class="pos-summary-row">
                            <span class="pos-label">إجمالي البنود المرتجعة</span>
                            <span class="pos-val" id="posReturnGrossDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">خصم البنود المردودة</span>
                            <span class="pos-val" id="posReturnDiscDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">طرق الدفع الأصلية</span>
                            <div class="pos-val" id="posReturnOrigPaymentsSummary" style="font-size:var(--fs-xs); color:var(--text-muted); text-align:left;">--</div>
                        </div>
                    </div>

                    <div style="margin-top:var(--sp-2);">
                        <div class="pos-label" style="margin-bottom:4px; font-weight:600;">طريقة رد المبلغ:</div>
                        <div id="posReturnPaymentRows"></div>
                    </div>

                    <div id="posReturnOverrideBox" class="falert falert-warn" style="display:none; margin-top:var(--sp-2);">
                        <i class="ti ti-alert-triangle"></i> طريقة الرد تختلف عن الفاتورة الأصلية. يلزم اعتماد مشرف.
                        <div class="ffield" style="margin-top:4px;">
                            <input type="text" class="finput finput-sm" id="posReturnOverrideReason" placeholder="سبب تغيير طريقة الرد...">
                        </div>
                    </div>

                    <div class="fcard-actions" style="flex-direction:column; gap:var(--sp-2); margin-top:var(--sp-3);">
                        <button type="button" class="btn btn-primary" id="posBtnConfirmReturn" style="width:100%;" disabled>
                            <i class="ti ti-check"></i> إنهاء المرتجع
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- وضع المرتجع العام -->
    <div id="posViewReturnGeneral" style="display:none;">
        <div class="pos-layout">
            <div class="pos-cart-panel">
                <div class="fcard" style="margin-bottom:var(--sp-2);">
                    <div class="ffield">
                        <label class="flabel" for="posGenReturnReason">سبب المرتجع العام (إجباري):</label>
                        <input type="text" class="finput" id="posGenReturnReason"
                               placeholder="اكتب سبب المرتجع العام بالتفصيل..." autocomplete="off">
                    </div>
                </div>

                <div class="ftable-wrap pos-cart-table-wrap">
                    <table class="ftable" id="posGenCartTable" data-xt-off style="table-layout: fixed; width: 100%;">
                        <colgroup>
                            <col style="width: 40px;">
                            <col style="width: 48px;">
                            <col style="width: 150px;">
                            <col style="width: auto;">
                            <col style="width: 70px;">
                            <col style="width: 70px;">
                            <col style="width: 90px;">
                            <col style="width: 90px;">
                            <col style="width: 100px;">
                            <col style="width: 44px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="num">#</th>
                                <th>كاميرا</th>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>الوحدة</th>
                                <th class="num">الرصيد</th>
                                <th class="num">الكمية</th>
                                <th class="num">السعر</th>
                                <th class="num">الإجمالي</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="posGenCartBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="pos-totals-panel">
                <div class="fcard">
                    <div class="pos-net-box">
                        <div class="pos-net-title">صافي المرتجع العام</div>
                        <div class="pos-net-val">
                            <span id="posGenNetDisplay">0.00</span>
                            <span class="pos-net-currency">ج.م</span>
                        </div>
                    </div>

                    <div style="margin-top:var(--sp-2);">
                        <div class="pos-label" style="margin-bottom:4px; font-weight:600;">طريقة رد المبلغ:</div>
                        <div id="posGenPaymentRows"></div>
                    </div>

                    <div class="fcard-actions" style="flex-direction:column; gap:var(--sp-2); margin-top:var(--sp-3);">
                        <button type="button" class="btn btn-primary" id="posBtnConfirmGenReturn" style="width:100%;" disabled>
                            <i class="ti ti-check"></i> إنهاء المرتجع العام
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- وضع الاستبدال -->
    <div id="posViewExchange" style="display:none;">
        <div class="pos-layout">
            <div class="pos-cart-panel" style="gap:var(--sp-3);">
                <!-- جزء المرتجع من فاتورة -->
                <div class="fcard">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--sp-2);">
                        <strong style="color:var(--warn);"><i class="ti ti-arrow-back-up"></i> ١. المرتجع من فاتورة سابقة</strong>
                        <span id="posExchReturnSubtotalChip" class="fchip fchip-warn">صافي المرتجع: 0.00 ج.م</span>
                    </div>
                    <div class="pos-scan-box" style="margin-bottom:var(--sp-2);">
                        <div class="pos-scan-input-wrap">
                            <input type="text" class="finput" id="posExchReceiptInput"
                                   placeholder="رقم إيصال الفاتورة الأصلية...">
                        </div>
                        <button type="button" class="btn btn-outline" id="posBtnExchCamera" title="امسح بالكاميرا">
                            <i class="ti ti-camera"></i>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="posBtnExchSearchDoc">
                            <i class="ti ti-search"></i> بحث
                        </button>
                    </div>
                    <div id="posExchReturnDocInfo" style="display:none; margin-bottom:var(--sp-2); font-size:var(--fs-xs);">
                        <span class="fchip fchip-mute" id="posExchDocReceiptBadge">--</span>
                        <span class="fchip fchip-mute" id="posExchDocDateBadge">--</span>
                    </div>
                    <div class="ftable-wrap" style="max-height:200px; overflow-y:auto;">
                        <table class="ftable" id="posExchReturnLinesTable" data-xt-off>
                            <thead>
                                <tr>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th class="num">المتاح</th>
                                    <th class="num" style="width:80px;">المرتجع</th>
                                    <th class="num">سعر القطعة</th>
                                    <th class="num">الصافي</th>
                                </tr>
                            </thead>
                            <tbody id="posExchReturnLinesBody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- جزء سلة البيع الجديدة -->
                <div class="fcard">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--sp-2);">
                        <strong style="color:var(--accent-text);"><i class="ti ti-shopping-cart"></i> ٢. سلة البيع الجديدة</strong>
                        <span id="posExchSaleSubtotalChip" class="fchip fchip-mute">صافي البيع: 0.00 ج.م</span>
                    </div>
                    <div class="ftable-wrap pos-cart-table-wrap">
                        <table class="ftable" id="posExchSaleTable" data-xt-off style="table-layout: fixed; width: 100%;">
                            <colgroup>
                                <col style="width: 40px;">
                                <col style="width: 36px;">
                                <col style="width: 150px;">
                                <col style="width: auto;">
                                <col style="width: 70px;">
                                <col style="width: 70px;">
                                <col style="width: 90px;">
                                <col style="width: 90px;">
                                <col style="width: 100px;">
                                <col style="width: 70px;">
                                <col style="width: 70px;">
                                <col style="width: 44px;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="num">#</th>
                                    <th>كاميرا</th>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th>الوحدة</th>
                                    <th class="num">الرصيد</th>
                                    <th class="num">الكمية</th>
                                    <th class="num">السعر</th>
                                    <th class="num">الإجمالي</th>
                                    <th class="num">خصم %</th>
                                    <th class="num">خصم قيمة</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="posExchSaleBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ملخص الاستبدال والفرق والدفع -->
            <div class="pos-totals-panel">
                <div class="fcard">
                    <div class="pos-net-box">
                        <div class="pos-net-title" id="posExchDiffTitle">الفرق المطلوب</div>
                        <div class="pos-net-val">
                            <span id="posExchDiffDisplay">0.00</span>
                            <span class="pos-net-currency">ج.م</span>
                        </div>
                        <div id="posExchDiffStatus" class="fchip fchip-mute" style="margin-top:4px;">مفيش فرق</div>
                    </div>

                    <div class="pos-summary-list">
                        <div class="pos-summary-row">
                            <span class="pos-label">صافي البيع الجديد</span>
                            <span class="pos-val" id="posExchSaleNetDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">صافي المرتجع</span>
                            <span class="pos-val" id="posExchRetNetDisplay">0.00</span>
                        </div>
                    </div>

                    <!-- دفعات الفرق -->
                    <div id="posExchPaymentsWrap" style="margin-top:var(--sp-2); display:none;">
                        <div class="pos-label" id="posExchPaymentLabel" style="margin-bottom:4px; font-weight:600;">دفع الفرق:</div>
                        <div id="posExchPaymentRows"></div>
                    </div>

                    <div class="fcard-actions" style="flex-direction:column; gap:var(--sp-2); margin-top:var(--sp-3);">
                        <button type="button" class="btn btn-primary" id="posBtnConfirmExchange" style="width:100%;" disabled>
                            <i class="ti ti-check"></i> إنهاء الاستبدال
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- شريط الاختصارات بالأسفل -->
    <div class="pos-shortcuts-bar">
        <span><span class="pos-shortcut-key">F1</span> بيع</span>
        <span><span class="pos-shortcut-key">F2</span> بحث صنف</span>
        <span><span class="pos-shortcut-key">F3</span> فتح الدرج</span>
        <span><span class="pos-shortcut-key">F4</span> خصم الفاتورة %</span>
        <span><span class="pos-shortcut-key">F6</span> الدفع / الأصناف</span>
        <span><span class="pos-shortcut-key">F7</span> مرتجع</span>
        <span><span class="pos-shortcut-key">F8</span> تعليق</span>
        <span><span class="pos-shortcut-key">F9</span> المعلّق</span>
        <span><span class="pos-shortcut-key">F12 / Ctrl+S</span> حفظ</span>
        <span><span class="pos-shortcut-key">Insert</span> الصف الفاضي</span>
        <span><span class="pos-shortcut-key">Ctrl+N</span> الصف الفاضي (PWA)</span>
        <span><span class="pos-shortcut-key">Ctrl+Delete</span> مسح الصف</span>
        <span><span class="pos-shortcut-key">Esc</span> رجوع</span>
    </div>

    <!-- نافذة اختيار الجهاز -->
    <div class="fg-dlg-bg" id="posModalDevice" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 440px);">
            <h3 class="fg-dlg-title"><i class="ti ti-device-desktop"></i> اختيار جهاز نقطة البيع</h3>
            <div id="posDeviceModalAlert"></div>
            <div class="fg-dlg-msg" id="posDeviceSelectSection">
                <div class="ffield">
                    <label class="flabel" for="posDeviceSelect">اختار الجهاز المربوط بالمتصفح ده:</label>
                    <select class="fselect" id="posDeviceSelect"></select>
                </div>
            </div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnSaveDevice">حفظ الجهاز</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseDeviceModal">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- نافذة فتح وردية -->
    <div class="fg-dlg-bg" id="posModalSession" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 420px);">
            <h3 class="fg-dlg-title"><i class="ti ti-player-play"></i> فتح وردية جديدة</h3>
            <div id="posSessionModalAlert"></div>
            <div class="fg-dlg-msg">
                <div class="ffield">
                    <label class="flabel" for="posOpeningCashInput">رصيد البداية في الدرج (العهدة الافتتاحية):</label>
                    <input type="number" step="0.5" min="0" class="finput num" id="posOpeningCashInput" value="0.00">
                </div>
            </div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnConfirmOpenSession">فتح الوردية</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseSessionModal">إلغاء</button>
            </div>
        </div>
    </div>

    <!-- نافذة تقفيل الوردية -->
    <div class="fg-dlg-bg" id="posModalCloseSession" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width:min(96vw,900px);max-height:92vh;overflow:auto;">
            <h3 class="fg-dlg-title"><i class="ti ti-lock"></i> تقفيل الوردية</h3>
            <div id="posCloseSessionAlert"></div>
            <?php if (sale_shift_expected_visible()): ?>
            <div class="ffield" id="posCloseDetailsWrap">
                <label for="posCloseShowDetails" style="display:flex;align-items:center;gap:var(--sp-1);cursor:pointer;">
                    <input type="checkbox" id="posCloseShowDetails"> اعرض تفاصيل الوردية
                </label>
            </div>
            <?php endif; ?>
            <div id="posCloseExpected"></div>
            <h4>عد فئات الكاش</h4>
            <div class="ftable-wrap">
                <table class="ftable" data-xt-off>
                    <thead><tr><th class="num">الفئة</th><th class="num">العدد</th><th class="num">الإجمالي</th></tr></thead>
                    <tbody id="posCloseCashBody"></tbody>
                </table>
            </div>
            <h4>مطابقة فيزا وإنستا باي</h4>
            <div class="ftable-wrap">
                <table class="ftable" data-xt-off>
                    <thead><tr><th>الطريقة</th><th>المرجع</th><th class="num">المبلغ</th><th></th></tr></thead>
                    <tbody id="posClosePaymentsBody"></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnAddClosePayment">إضافة سطر مطابقة</button>
            <div id="posCloseDifferences" style="margin-top:var(--sp-3);"></div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnConfirmCloseSession">تأكيد التقفيل وإرساله للمراجعة</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCancelCloseSession">إلغاء</button>
            </div>
        </div>
    </div>

    <!-- شاشة نجاح تسجيل الفاتورة -->
    <div class="fg-dlg-bg" id="posModalPayment" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 560px);">
            <div id="posPaymentViewSuccess" style="display:none; text-align:center;">
                <div style="font-size:48px; color:var(--pos); margin-bottom:var(--sp-2);">
                    <i class="ti ti-circle-check"></i>
                </div>
                <h3 class="fg-dlg-title" style="margin-bottom:4px;">تم تسجيل الفاتورة بنجاح</h3>
                <div style="font-size:var(--fs-xl); font-weight:700; margin-bottom:var(--sp-2);" id="posSuccessReceiptNo">
                    --
                </div>
                <div class="pos-change-box" id="posSuccessChangeBox" style="margin-bottom:var(--sp-3);">
                    الباقي: 0.00 ج.م
                </div>
                <div class="fg-dlg-actions" style="justify-content:center;">
                    <button type="button" class="btn btn-outline" id="posBtnPrintReceipt">
                        <i class="ti ti-printer"></i> طباعة الإيصال
                    </button>
                    <button type="button" class="btn btn-primary" id="posBtnNewSale">
                        <i class="ti ti-plus"></i> فاتورة جديدة (Enter / Esc)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة فواتير الوردية -->
    <div class="fg-dlg-bg" id="posModalSessionInvoices" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(95vw, 760px);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--sp-2);">
                <h3 class="fg-dlg-title" style="margin:0;"><i class="ti ti-file-invoice"></i> فواتير الوردية الحالية</h3>
                <button type="button" class="btn btn-sm btn-ghost" id="posBtnCloseSessionInvoices">✕</button>
            </div>
            <div id="posSessionInvoicesAlert"></div>
            <div class="ftable-wrap" style="max-height:360px; overflow-y:auto; margin-top:var(--sp-2);">
                <table class="ftable" data-xt-off>
                    <thead>
                        <tr>
                            <th>رقم الإيصال</th>
                            <th>النوع</th>
                            <th>الوقت</th>
                            <th>الصافي</th>
                            <th>الكاشير</th>
                            <th>إعادة الطباعة</th>
                        </tr>
                    </thead>
                    <tbody id="posSessionInvoicesBody"></tbody>
                </table>
            </div>
            <div id="posSessionInvoicesEmpty"></div>
            <div class="fg-dlg-actions" style="margin-top:var(--sp-3);">
                <button type="button" class="btn btn-secondary" id="posBtnCloseSessionInvoicesAction">إغلاق (Esc)</button>
            </div>
        </div>
    </div>

    <!-- نافذة البحث عن صنف -->
    <div class="fg-dlg-bg" id="posModalItemSearch" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width:min(96vw,920px);max-height:92vh;overflow:auto;">
            <h3 class="fg-dlg-title"><i class="ti ti-search"></i> بحث عن صنف</h3>
            <div class="fgrid fgrid-3">
                <div class="ffield"><label for="posItemSearchInput">كلمة البحث</label><input class="finput" id="posItemSearchInput" autocomplete="off"></div>
                <div class="ffield"><label>البحث في</label><div class="ftabs" id="posSearchFieldTabs"><button type="button" class="ftab is-active" data-value="name">الاسم</button><button type="button" class="ftab" data-value="code">الكود</button></div></div>
                <div class="ffield"><label>طريقة المطابقة</label><div class="ftabs" id="posSearchModeTabs"><button type="button" class="ftab" data-value="start">بداية</button><button type="button" class="ftab is-active" data-value="middle">وسط</button><button type="button" class="ftab" data-value="end">نهاية</button></div></div>
            </div>
            <div id="posItemSearchAlert"></div>
            <div class="ftable-wrap" style="margin-top:var(--sp-2);">
                <table class="ftable" data-xt-off>
                    <thead><tr><th>الكود</th><th>الاسم</th><th class="num">السعر</th><th>الوحدة</th><th class="num">المعامل</th><th class="num">الرصيد</th></tr></thead>
                    <tbody id="posItemSearchBody"></tbody>
                </table>
            </div>
            <div id="posItemSearchEmpty"></div>
            <div class="fg-dlg-actions" style="margin-top:var(--sp-2);"><button type="button" class="btn btn-secondary" id="posBtnCloseItemSearch">إغلاق (Esc)</button></div>
        </div>
    </div>

    <!-- نافذة اعتماد المشرف للخصم الزائد -->
    <div class="fg-dlg-bg" id="posModalSupervisor" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 440px);">
            <h3 class="fg-dlg-title" id="posSupervisorTitle"><i class="ti ti-lock"></i> اعتماد مشرف</h3>
            <div class="falert falert-warn" id="posSupervisorWarning">
                الخصم الإجمالي يتجاوز سقف الكاشير. مطلوب اعتماد مدير أو مشرف.
            </div>
            <div id="posSupervisorAlert"></div>
            <div class="fg-dlg-msg">
                <div class="ffield">
                    <label class="flabel" for="posSuperUser">اسم المشرف / المدير:</label>
                    <input type="text" class="finput" id="posSuperUser" autocomplete="off">
                </div>
                <div class="ffield" style="margin-top:var(--sp-2);">
                    <label class="flabel" for="posSuperPass">كلمة المرور:</label>
                    <input type="password" class="finput" id="posSuperPass" autocomplete="new-password">
                </div>
            </div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnSubmitSupervisor">اعتماد ومتابعة</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseSupervisor">إلغاء</button>
            </div>
        </div>
    </div>

    <!-- نافذة الفواتير المعلقة (F9) -->
    <div class="fg-dlg-bg" id="posModalHeld" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(94vw, 760px);">
            <h3 class="fg-dlg-title"><i class="ti ti-clock"></i> الفواتير المعلّقة في الفرع</h3>
            <div id="posHeldAlert"></div>
            <div class="ftable-wrap" style="max-height:360px; overflow-y:auto;">
                <table class="ftable" id="posHeldTable">
                    <thead>
                        <tr>
                            <th>رقم</th>
                            <th>الوقت</th>
                            <th>الكاشير</th>
                            <th>الجهاز</th>
                            <th class="num">الصافي</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="posHeldBody"></tbody>
                </table>
            </div>
            <div id="posHeldEmpty"></div>
            <div class="fg-dlg-actions" style="margin-top:var(--sp-3);">
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseHeldModal">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- نافذة اختيار وإضافة عميل -->
    <div class="fg-dlg-bg" id="posModalCustomer" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 540px);">
            <h3 class="fg-dlg-title"><i class="ti ti-users"></i> ربط الفاتورة بعميل</h3>
            <div id="posCustModalAlert"></div>
            <div class="ffield" style="margin-bottom:var(--sp-2);">
                <input type="text" class="finput" id="posCustSearchInput" placeholder="ابحث برقم الموبايل أو اسم العميل...">
            </div>
            <div style="max-height:180px; overflow-y:auto; border:1px solid var(--line); border-radius:var(--r-md); margin-bottom:var(--sp-3);" id="posCustResultsWrap">
                <div id="posCustResultsList"></div>
            </div>

            <div style="border-top:1px solid var(--line-soft); padding-top:var(--sp-2);">
                <div style="font-weight:600; font-size:var(--fs-sm); margin-bottom:var(--sp-1);">أو إضافة عميل سريع:</div>
                <div class="fgrid fgrid-2">
                    <div class="ffield">
                        <input type="text" class="finput" id="posNewCustName" placeholder="اسم العميل">
                    </div>
                    <div class="ffield">
                        <input type="tel" class="finput num" id="posNewCustPhone" placeholder="رقم الموبايل" dir="ltr">
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline" id="posBtnQuickAddCust" style="margin-top:var(--sp-2);">
                    <i class="ti ti-user-plus"></i> إضافة واختيار
                </button>
            </div>

            <div class="fg-dlg-actions" style="margin-top:var(--sp-3);">
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseCustModal">إلغاء</button>
            </div>
        </div>
    </div>

    <!-- نافذة فتح الدرج -->
    <div class="fg-dlg-bg" id="posModalDrawer" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 420px);">
            <h3 class="fg-dlg-title"><i class="ti ti-cash"></i> فتح الدرج</h3>
            <div id="posDrawerAlert"></div>
            <div class="fg-dlg-msg">
                <div class="ffield">
                    <label class="flabel" for="posDrawerReason">سبب فتح الدرج (إجباري):</label>
                    <input type="text" class="finput" id="posDrawerReason" placeholder="مثال: فكة / تسليم إيداع">
                </div>
            </div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnConfirmDrawer">فتح الدرج</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseDrawer">إلغاء</button>
            </div>
        </div>
    </div>
</main>

<script src="assets/barcode_scanner.js?v=4"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.ui;

    // حالة التطبيق
    let deviceUuid = '';
    let context = null;
    let cart = []; // سطور الفاتورة المضروبة؛ الصف الفاضي بيتترسم لوحده
    let invoiceDiscount = {type: 'amount', value: 0};
    let currentCustomer = null;
    let currentDocId = null; // للمعلّق
    let currentClientUuid = '';
    let approvalToken = null;
    let isSubmitting = false;
    let restoredDraftKey = '';
    let quantityValidationTimer = null;

    // عناصر الصفحة الأساسية
    const alertBox = document.getElementById('posAlert');
    const cartBody = document.getElementById('posCartBody');
    const cartEmpty = document.getElementById('posCartEmpty');
    const netDisplay = document.getElementById('posNetDisplay');
    const grossDisplay = document.getElementById('posGrossDisplay');
    const lineDiscDisplay = document.getElementById('posLineDiscDisplay');
    const invDiscDisplay = document.getElementById('posInvDiscDisplay');
    const totalDiscDisplay = document.getElementById('posTotalDiscDisplay');
    const linesCountEl = document.getElementById('posLinesCount');
    const piecesCountEl = document.getElementById('posPiecesCount');
    const payBtn = document.getElementById('posBtnPay');
    const branchBadge = document.getElementById('posBranchBadge');
    const deviceBadge = document.getElementById('posDeviceBadge');
    const sessionBadge = document.getElementById('posSessionBadge');
    const openSessionBtn = document.getElementById('posBtnOpenSession');
    const closeSessionBtn = document.getElementById('posBtnCloseSession');
    const customerNameEl = document.getElementById('posCustomerName');
    const removeCustomerBtn = document.getElementById('posBtnRemoveCustomer');
    const saleItemsPanel = document.getElementById('posSaleItemsPanel');
    const paymentPanel = document.getElementById('posPaymentPanel');
    const invDiscPctInput = document.getElementById('posInvDiscPct');
    const invDiscAmountInput = document.getElementById('posInvDiscAmount');
    let paymentPanelOpen = false;

    // ==========================================
    // السلة الهدف (بريف 827k) — نفس دوال البيع تخدم التابات التلاتة
    // ==========================================
    // كل تاب عنده object بنفس الشكل: cart (getter/setter على المصفوفة) + tbody
    // + خيارات الشكل. الدوال المشتركة بتاخد الـstore بدل ما تلمس global cart.
    const saleStore = {
        key: 'sale',
        get cart() { return cart; },
        set cart(v) { cart = v; },
        tbody: cartBody,
        cartEmpty: cartEmpty,
        discounts: true,
        stockGuard: true,
        codeIdPrefix: 'posCodeInput_',
        nameIdPrefix: 'posNameInput_',
        onChange: function () { saveDraft(); },
        onTotals: function () { computeTotals(); }
    };

    // السلة الظاهرة دلوقتي حسب التاب المفتوح
    function activeCartStore() {
        if (currentMode === 'return_general' && typeof genStore !== 'undefined') return genStore;
        if (currentMode === 'exchange' && typeof exchStore !== 'undefined') return exchStore;
        return saleStore;
    }

    // قراءة المعرف من الرابط أو التخزين المحلي
    try {
        const urlParams = new URLSearchParams(window.location.search);
        deviceUuid = urlParams.get('device_uuid') || localStorage.getItem('pos_device_uuid') || '';
    } catch (e) {
        deviceUuid = '';
    }

    function showAlert(kind, msg) {
        if (!msg) {
            alertBox.innerHTML = '';
            scheduleAdjustCartTableHeight();
            return;
        }
        alertBox.innerHTML = ui.alert(kind, msg);
        scheduleAdjustCartTableHeight();
    }

    function clearAlert() {
        alertBox.innerHTML = '';
        scheduleAdjustCartTableHeight();
    }

    function openModal(el) {
        if (!el) return;
        document.documentElement.classList.add('fg-dlg-open');
        el.style.display = 'flex';
    }

    function closeModal(el) {
        if (!el) return;
        el.style.display = 'none';
        if (!document.querySelector('.fg-dlg-bg[style*="display: flex"]')) {
            document.documentElement.classList.remove('fg-dlg-open');
        }
        refocusScan();
    }

    function refocusScan() {
        setTimeout(function () {
            if (!paymentPanelOpen && !document.querySelector('.fg-dlg-bg[style*="display: flex"]')) {
                const store = activeCartStore();
                if (!store || !store.tbody) return;
                const codeInput = store.tbody.querySelector('tr[data-empty-row="1"] [data-cart-field="code"]');
                if (codeInput) codeInput.focus();
            }
        }, 60);
    }

    // حساب ارتفاع جدول السلة ديناميكياً ليملأ باقي الشاشة تماماً بدون scroll (بريف 827f)
    function adjustCartTableHeight() {
        if (window.innerWidth <= 600) {
            document.querySelectorAll('.pos-cart-table-wrap').forEach(function (wrap) {
                wrap.style.height = '';
            });
            return;
        }
        const wraps = document.querySelectorAll('.pos-cart-table-wrap');
        wraps.forEach(function (wrap) {
            if (!wrap.offsetParent) return;
            const wrapRect = wrap.getBoundingClientRect();
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
            const wrapTop = wrapRect.top + scrollTop;

            let totalsBarHeight = 0;
            const parentView = wrap.closest('#posViewSale, #posViewReturn, #posViewReturnGeneral, #posViewExchange');
            const totalsBar = parentView ? parentView.querySelector('.pos-totals-bar') : null;
            if (totalsBar && totalsBar.offsetParent !== null) {
                totalsBarHeight = totalsBar.getBoundingClientRect().height || 0;
            }

            let shortcutsHeight = 0;
            const shortcutsBar = document.querySelector('.pos-shortcuts-bar');
            if (shortcutsBar && getComputedStyle(shortcutsBar).display !== 'none') {
                shortcutsHeight = shortcutsBar.getBoundingClientRect().height || 28;
            }

            const marginsAndGaps = 18;
            // شاشات المرتجع العام والاستبدال جوه .pos-layout اللي تحته هامش سفلي
            let layoutGap = 0;
            const layoutEl = wrap.closest('.pos-layout');
            if (layoutEl) {
                const mb = parseFloat(getComputedStyle(layoutEl).marginBottom);
                layoutGap = isNaN(mb) ? 0 : mb;
            }
            let exchangeTail = 0;
            if (parentView && parentView.id === 'posViewExchange' && layoutEl) {
                exchangeTail = Math.max(0, layoutEl.getBoundingClientRect().bottom - wrapRect.bottom);
            }
            let availableHeight = window.innerHeight - wrapTop - totalsBarHeight - shortcutsHeight - marginsAndGaps - layoutGap - exchangeTail;
            if (availableHeight < 240) availableHeight = 240;
            wrap.style.height = Math.floor(availableHeight) + 'px';
        });
    }

    window.addEventListener('resize', adjustCartTableHeight);
    window.addEventListener('load', adjustCartTableHeight);

    // تنبيه فوق الجدول بيزوّد ارتفاعه — لازم نعيد حساب ارتفاع الجدول بعده (بريف 827j)
    var cartHeightFrame = 0;
    function scheduleAdjustCartTableHeight() {
        if (cartHeightFrame) return;
        cartHeightFrame = window.requestAnimationFrame(function () {
            cartHeightFrame = 0;
            adjustCartTableHeight();
        });
    }

    function isEnter(e) {
        if (typeof window.isEnterKey === 'function') {
            return window.isEnterKey(e);
        }
        if (e.keyCode === 229 || e.which === 229 || e.isComposing) return false;
        return e.key === 'Enter' || e.keyCode === 13;
    }

    // ==========================================
    // تهيئة الجهاز وسياق العمل
    // ==========================================
    function initContext(keepAlert) {
        if (!keepAlert) clearAlert();
        if (!deviceUuid) {
            promptDeviceSelection();
            return;
        }

        window.apiFetch('pos_api.php?part=context&device_uuid=' + encodeURIComponent(deviceUuid))
            .then(function (res) {
                context = res.data || {};
                renderContextInfo();
            })
            .catch(function (err) {
                if (err.http === 404 || err.code === 'not_found') {
                    showAlert('warn', 'الجهاز ده مش متسجّل — كلّم المدير.');
                    promptDeviceSelection();
                } else if (err.http === 409) {
                    showAlert('neg', err.message || 'الجهاز متوقف أو به تعارض.');
                } else {
                    showAlert('neg', err.message || 'تعذر تحميل سياق الجهاز.');
                }
            });
    }

    function renderContextInfo() {
        if (!context) return;
        branchBadge.textContent = 'فرع: ' + (context.branch ? context.branch.name : '--');
        deviceBadge.textContent = 'جهاز: ' + (context.device ? context.device.name + ' (' + context.device.code + ')' : '--');
        const invBranch = document.getElementById('posInvoiceBranch');
        if (invBranch) invBranch.value = context.branch ? context.branch.name : '--';
        const invDev = document.getElementById('posInvoiceDevice');
        if (invDev) invDev.value = context.device ? context.device.name + ' (' + context.device.code + ')' : '--';
        const invDate = document.getElementById('posInvoiceDate');
        if (invDate) invDate.value = context.session ? context.session.business_date : new Date().toLocaleDateString('en-CA');
        const invSeller = document.getElementById('posInvoiceSeller');
        if (invSeller) invSeller.value = context.session ? (context.session.cashier_name || '--') : '--';

        const sellerBadge = document.getElementById('posSellerBadge');
        if (sellerBadge) {
            sellerBadge.textContent = 'بائع: ' + (context.session ? (context.session.cashier_name || '--') : '--');
        }

        const dateBadge = document.getElementById('posDateBadge');
        if (dateBadge) {
            const todayStr = (function () {
                const d = new Date();
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            })();
            const bizDate = (context.session && context.session.business_date) ? context.session.business_date : todayStr;
            if (context.session && context.session.business_date && context.session.business_date < todayStr) {
                const parts = context.session.business_date.split('-');
                const dayMonth = (parts.length === 3) ? (parts[2] + '/' + parts[1]) : context.session.business_date;
                dateBadge.className = 'fchip fchip-warn';
                dateBadge.textContent = 'وردية من ' + dayMonth;
                dateBadge.title = 'تنبيه: وردية عمل سابقة بتاريخ ' + context.session.business_date;
            } else {
                dateBadge.className = 'fchip fchip-mute';
                dateBadge.textContent = 'تاريخ: ' + bizDate;
                dateBadge.title = 'تاريخ يوم العمل';
            }
        }

        if (context.ready === false) {
            showAlert('warn', 'الفرع لسه على True — البيع مقفول');
            payBtn.disabled = true;
            sessionBadge.className = 'fchip fchip-neg';
            sessionBadge.textContent = 'البيع مقفول';
            return;
        }

        if (!context.session) {
            sessionBadge.className = 'fchip fchip-warn';
            sessionBadge.textContent = 'مفيش وردية';
            openSessionBtn.style.display = 'inline-flex';
            closeSessionBtn.style.display = 'none';
            showAlert('info', 'الجهاز جاهز لكن مفيش وردية مفتوحة — افتح وردية لبدء البيع.');
        } else {
            sessionBadge.className = 'fchip fchip-pos';
            sessionBadge.textContent = 'وردية مفتوحة #' + context.session.id;
            openSessionBtn.style.display = 'none';
            closeSessionBtn.style.display = 'inline-flex';
            restoreDraft();
        }
        refocusScan();
    }

    // ==========================================
    // اختيار الجهاز من القائمة
    // ==========================================
    const modalDevice = document.getElementById('posModalDevice');
    const deviceSelect = document.getElementById('posDeviceSelect');
    const deviceModalAlert = document.getElementById('posDeviceModalAlert');

    function promptDeviceSelection() {
        deviceModalAlert.innerHTML = '';
        window.apiFetch('pos_devices_api.php?part=list_all')
            .then(function (res) {
                const devs = (res.data && res.data.devices) ? res.data.devices : [];
                if (!devs.length) {
                    deviceModalAlert.innerHTML = ui.alert('warn', 'الجهاز ده مش متسجّل — كلّم المدير.');
                    deviceSelect.innerHTML = '<option value="">مفيش أجهزة مسجلة</option>';
                    openModal(modalDevice);
                    return;
                }
                deviceSelect.innerHTML = devs.map(function (d) {
                    const sel = (d.device_uuid === deviceUuid) ? ' selected' : '';
                    return '<option value="' + ui.esc(d.device_uuid) + '"' + sel + '>' +
                           ui.esc(d.branch_name || '--') + ' — ' + ui.esc(d.name) + ' (' + ui.esc(d.code) + ')</option>';
                }).join('');
                openModal(modalDevice);
            })
            .catch(function () {
                deviceModalAlert.innerHTML = ui.alert('warn', 'الجهاز ده مش متسجّل — كلّم المدير.');
                deviceSelect.innerHTML = '<option value="">غير متاح</option>';
                openModal(modalDevice);
            });
    }

    document.getElementById('posBtnSaveDevice').addEventListener('click', function () {
        const val = deviceSelect.value;
        if (!val) {
            deviceModalAlert.innerHTML = ui.alert('warn', 'اختار جهاز الأول.');
            return;
        }
        deviceUuid = val;
        try {
            localStorage.setItem('pos_device_uuid', val);
        } catch (e) {}
        closeModal(modalDevice);
        initContext();
    });

    document.getElementById('posBtnCloseDeviceModal').addEventListener('click', function () {
        closeModal(modalDevice);
    });

    document.getElementById('posBtnSwitchDevice').addEventListener('click', function () {
        promptDeviceSelection();
    });

    // ==========================================
    // فتح وردية
    // ==========================================
    const modalSession = document.getElementById('posModalSession');
    const openingCashInput = document.getElementById('posOpeningCashInput');
    const sessionModalAlert = document.getElementById('posSessionModalAlert');

    openSessionBtn.addEventListener('click', function () {
        sessionModalAlert.innerHTML = '';
        openingCashInput.value = '0.00';
        openModal(modalSession);
        setTimeout(function () { openingCashInput.focus(); openingCashInput.select(); }, 100);
    });

    document.getElementById('posBtnCloseSessionModal').addEventListener('click', function () {
        closeModal(modalSession);
    });

    document.getElementById('posBtnConfirmOpenSession').addEventListener('click', function () {
        const cash = parseFloat(openingCashInput.value) || 0;
        if (cash < 0) {
            sessionModalAlert.innerHTML = ui.alert('neg', 'العهدة مينفعش تكون سالبة.');
            return;
        }
        this.disabled = true;
        window.apiFetch('pos_api.php?part=session_open', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                opening_cash: cash
            }
        })
        .then(function (res) {
            closeModal(modalSession);
            showAlert('pos', 'تم فتح الوردية بنجاح.');
            initContext(true);
        })
        .catch(function (err) {
            sessionModalAlert.innerHTML = ui.alert('neg', err.message || 'تعذر فتح الوردية.');
        })
        .finally(function () {
            document.getElementById('posBtnConfirmOpenSession').disabled = false;
        });
    });

    // ==========================================
    // تقفيل الوردية
    // ==========================================
    const modalCloseSession = document.getElementById('posModalCloseSession');
    const closeAlert = document.getElementById('posCloseSessionAlert');
    const closeCashBody = document.getElementById('posCloseCashBody');
    const closePaymentsBody = document.getElementById('posClosePaymentsBody');
    const closeExpectedBox = document.getElementById('posCloseExpected');
    const closeDifferencesBox = document.getElementById('posCloseDifferences');
    const detailsToggle = document.getElementById('posCloseShowDetails');
    const canSeeShiftExpected = document.getElementById('posContainer').getAttribute('data-can-shift-expected') === '1';
    const CLOSE_DETAILS_KEY = 'pos_shift_show_details';
    let closeShowDetails = false;
    let closeSummary = null;

    // مفتاح «اعرض تفاصيل الوردية» — افتراضي مقفول والاختيار بيتفتكر على الجهاز.
    if (canSeeShiftExpected && detailsToggle) {
        try {
            closeShowDetails = localStorage.getItem(CLOSE_DETAILS_KEY) === '1';
        } catch (e) {
            closeShowDetails = false;
        }
        detailsToggle.checked = closeShowDetails;
        detailsToggle.addEventListener('change', function () {
            closeShowDetails = detailsToggle.checked;
            try {
                localStorage.setItem(CLOSE_DETAILS_KEY, closeShowDetails ? '1' : '0');
            } catch (e) {}
            renderCloseView();
        });
    }

    function closeDetailsVisible() {
        return canSeeShiftExpected && closeShowDetails && !!(closeSummary && closeSummary.expected);
    }

    function showHeldBlockedAlert(message) {
        alertBox.innerHTML = '<div class="falert falert-neg">' +
            '<i class="ti ti-alert-circle"></i>' +
            '<div>' + ui.esc(message) +
            ' <button type="button" class="btn btn-sm btn-outline" data-open-held-list>' +
            '<i class="ti ti-clock"></i> الفواتير المعلّقة (F9)</button></div></div>';
    }

    function showDrawerBlockedAlert() {
        alertBox.innerHTML = '<div class="falert falert-warn">' +
            '<i class="ti ti-alert-triangle"></i>' +
            '<div>' + ui.esc('الكود 0 «فتح درج» مش بيتباع هنا — استخدم زرار «فتح الدرج» واكتب السبب.') +
            ' <button type="button" class="btn btn-sm btn-outline" data-open-drawer>' +
            '<i class="ti ti-cash"></i> فتح الدرج (F3)</button></div></div>';
    }

    alertBox.addEventListener('click', function (event) {
        if (event.target.closest('[data-open-held-list]')) {
            openHeldListModal();
        }
        if (event.target.closest('[data-open-drawer]')) {
            openDrawerModal();
        }
    });

    function renderCloseExpected() {
        if (!closeExpectedBox) return;
        if (!closeDetailsVisible()) {
            closeExpectedBox.innerHTML = '';
            return;
        }
        const exp = closeSummary.expected || {};
        const session = closeSummary.session || {};
        closeExpectedBox.innerHTML = ui.alert('info',
            'المتوقع: كاش ' + Number(exp.cash || 0).toFixed(2) +
            ' · فيزا ' + Number(exp.visa || 0).toFixed(2) +
            ' · إنستا باي ' + Number(exp.instapay || 0).toFixed(2)) +
            (Object.prototype.hasOwnProperty.call(session, 'opening_cash') ? ui.alert('info', 'الافتتاحي (بيفضل في الدرج): ' +
                Number(session.opening_cash).toFixed(2)) : '') +
            ui.alert('info', 'الكاش المطلوب توريده: ' + Number(exp.cash_sales || 0).toFixed(2));
    }

    function closePaymentRow(method) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="fselect pos-close-method">' +
            '<option value="visa"' + (method === 'visa' ? ' selected' : '') + '>فيزا</option>' +
            '<option value="instapay"' + (method === 'instapay' ? ' selected' : '') + '>إنستا باي</option>' +
            '</select></td>' +
            '<td><input class="finput pos-close-ref" type="text" maxlength="100" placeholder="رقم المرجع"></td>' +
            '<td class="num"><input class="finput num pos-close-amount" type="number" min="0" step="0.01" value="0"></td>' +
            '<td><button type="button" class="btn btn-sm btn-ghost pos-close-remove">حذف</button></td>';
        closePaymentsBody.appendChild(tr);
    }

    function renderCloseDiffs() {
        if (!closeSummary) return;
        let cash = 0;
        closeCashBody.querySelectorAll('[data-denom]').forEach(function (input) {
            cash += Number(input.dataset.denom) * Math.max(0, parseInt(input.value || '0', 10));
            const subtotal = input.closest('tr').querySelector('.pos-close-subtotal');
            subtotal.textContent = (Number(input.dataset.denom) * Math.max(0, parseInt(input.value || '0', 10))).toFixed(2);
        });
        const counted = { cash: cash, visa: 0, instapay: 0 };
        closePaymentsBody.querySelectorAll('tr').forEach(function (tr) {
            const method = tr.querySelector('.pos-close-method').value;
            counted[method] += Math.max(0, Number(tr.querySelector('.pos-close-amount').value || 0));
        });
        if (!closeDetailsVisible()) {
            closeDifferencesBox.innerHTML = '';
            return;
        }
        const exp = closeSummary.expected;
        const totalDiff = counted.cash + counted.visa + counted.instapay - Number(exp.total);
        closeDifferencesBox.innerHTML =
            '<table class="ftable" data-xt-off><thead><tr><th>الطريقة</th><th class="num">المتوقع</th><th class="num">المعدود</th><th class="num">الفرق</th></tr></thead><tbody>' +
            ['cash', 'visa', 'instapay'].map(function (method) {
                const names = { cash: 'كاش', visa: 'فيزا', instapay: 'إنستا باي' };
                const diff = counted[method] - Number(exp[method]);
                return '<tr><td>' + names[method] + '</td><td class="num">' + Number(exp[method]).toFixed(2) + '</td><td class="num">' + counted[method].toFixed(2) + '</td><td class="num">' + diff.toFixed(2) + '</td></tr>';
            }).join('') +
            '<tr><td><strong>الإجمالي</strong></td><td class="num"><strong>' + Number(exp.total).toFixed(2) + '</strong></td><td class="num"><strong>' + (counted.cash + counted.visa + counted.instapay).toFixed(2) + '</strong></td><td class="num"><strong>' + totalDiff.toFixed(2) + '</strong></td></tr>' +
            '</tbody></table>';
    }

    function renderCloseView() {
        renderCloseExpected();
        renderCloseDiffs();
    }

    closeSessionBtn.addEventListener('click', function () {
        closeAlert.innerHTML = '';
        window.apiFetch('pos_api.php?part=session_summary&device_uuid=' + encodeURIComponent(deviceUuid))
            .then(function (res) {
                closeSummary = res.data || {};
                const held = closeSummary.held_docs || [];
                if (held.length) {
                    showHeldBlockedAlert('فيه ' + held.length + ' فاتورة معلّقة — استرجعها وكمّلها أو الغيها الأول.');
                    return;
                }
                closeCashBody.innerHTML = (closeSummary.denominations || []).map(function (denom) {
                    return '<tr><td class="num">' + Number(denom).toFixed(2) + '</td>' +
                        '<td class="num"><input class="finput num" data-denom="' + ui.esc(String(denom)) + '" type="number" min="0" step="1" value="0"></td>' +
                        '<td class="num pos-close-subtotal">0.00</td></tr>';
                }).join('');
                closePaymentsBody.innerHTML = '';
                closePaymentRow('visa');
                closePaymentRow('instapay');
                renderCloseView();
                openModal(modalCloseSession);
            })
            .catch(function (err) { showAlert('neg', err.message || 'تعذر تحميل ملخص الوردية.'); });
    });

    closeCashBody.addEventListener('input', renderCloseDiffs);
    closePaymentsBody.addEventListener('input', renderCloseDiffs);
    closePaymentsBody.addEventListener('change', renderCloseDiffs);
    closePaymentsBody.addEventListener('click', function (event) {
        const btn = event.target.closest('.pos-close-remove');
        if (!btn) return;
        btn.closest('tr').remove();
        renderCloseDiffs();
    });
    document.getElementById('posBtnAddClosePayment').addEventListener('click', function () {
        closePaymentRow('visa');
        renderCloseDiffs();
    });
    document.getElementById('posBtnCancelCloseSession').addEventListener('click', function () {
        closeModal(modalCloseSession);
    });
    document.getElementById('posBtnConfirmCloseSession').addEventListener('click', function () {
        const btn = this;
        const cashCounts = {};
        closeCashBody.querySelectorAll('[data-denom]').forEach(function (input) {
            cashCounts[input.dataset.denom] = Math.max(0, parseInt(input.value || '0', 10));
        });
        const paymentLines = [];
        closePaymentsBody.querySelectorAll('tr').forEach(function (tr) {
            const amount = Math.max(0, Number(tr.querySelector('.pos-close-amount').value || 0));
            if (amount <= 0) return;
            paymentLines.push({
                method: tr.querySelector('.pos-close-method').value,
                reference: tr.querySelector('.pos-close-ref').value.trim(),
                amount: amount
            });
        });
        btn.disabled = true;
        window.apiFetch('pos_api.php?part=session_close', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                client_uuid: crypto.randomUUID(),
                cash_counts: cashCounts,
                payment_lines: paymentLines
            }
        }).then(function (res) {
            closeModal(modalCloseSession);
            let msg = 'تم تقفيل الوردية وإرسالها للمراجعة. رقم التقفيل #' + Number(res.data.shift_id);
            if (res.data.cash_to_deposit !== undefined) {
                msg += ' · الكاش المطلوب توريده ' + Number(res.data.cash_to_deposit).toFixed(2);
            }
            showAlert('pos', msg);
            initContext(true);
        }).catch(function (err) {
            if (err.code === 'held_open') {
                closeModal(modalCloseSession);
                showHeldBlockedAlert(err.message || 'فيه فاتورة معلّقة — استرجعها وكمّلها أو الغيها الأول.');
                return;
            }
            closeAlert.innerHTML = ui.alert('neg', err.message || 'تعذر تقفيل الوردية.');
        }).finally(function () { btn.disabled = false; });
    });

    // ==========================================
    // حسابات السلة والخصم (sale_compute)
    // ==========================================
    function round2(value) {
        const n = Number(value) || 0;
        return Number(Math.round(Number(n + 'e2')) + 'e-2');
    }
    function normalizeSpaces(value) { return String(value || '').trim().replace(/\s+/g, ' '); }
    function normalizeDiscount(discount) {
        if (discount && typeof discount === 'object') {
            return {type: discount.type === 'percent' ? 'percent' : 'amount', value: Number(discount.value) || 0};
        }
        const text = String(discount || '').trim();
        if (text.endsWith('%')) return {type: 'percent', value: Number(text.slice(0, -1)) || 0};
        return {type: 'amount', value: Number(text) || 0};
    }
    function discountAmount(discount, base) {
        const clean = normalizeDiscount(discount);
        return clean.type === 'percent' ? round2(base * clean.value / 100) : round2(clean.value);
    }

    function draftStorageKey() {
        if (!deviceUuid || !context || !context.session) return '';
        return 'pos_open_invoice_' + deviceUuid + '_' + context.session.id;
    }

    function saveDraft() {
        const key = draftStorageKey();
        if (!key) return;
        try {
            localStorage.setItem(key, JSON.stringify({
                cart: cart,
                invoice_discount: invoiceDiscount,
                customer: currentCustomer,
                doc_id: currentDocId,
                client_uuid: currentClientUuid,
                saved_at: new Date().toISOString()
            }));
        } catch (e) {}
    }

    function clearDraft() {
        const key = draftStorageKey();
        if (!key) return;
        try { localStorage.removeItem(key); } catch (e) {}
        restoredDraftKey = key;
    }

    function restoreDraft() {
        const key = draftStorageKey();
        if (!key || restoredDraftKey === key || cart.length) return;
        restoredDraftKey = key;
        try {
            const raw = localStorage.getItem(key);
            if (!raw) return;
            const draft = JSON.parse(raw);
            if (!draft || !Array.isArray(draft.cart) || !draft.cart.length) return;
            cart = draft.cart.filter(function (line) {
                return line && line.code && Number(line.qty) > 0;
            }).map(function (line) {
                line.qty = Number(line.qty);
                line.unit_price = Number(line.unit_price || 0);
                line.unit_factor = Number(line.unit_factor || 1);
                line.balance = Number(line.balance || 0);
                line.stock_known = line.stock_known !== false;
                line.line_discount = normalizeDiscount(line.line_discount);
                return line;
            });
            if (!cart.length) return;
            invoiceDiscount = normalizeDiscount(draft.invoice_discount);
            currentCustomer = draft.customer || null;
            currentDocId = draft.doc_id || null;
            currentClientUuid = draft.client_uuid || '';
            updateCustomerBadge();
            renderCart({idx:cart.length, field:'code'});
            showAlert('info', 'رجّعنا الفاتورة اللي كانت مفتوحة');
        } catch (e) {}
    }

    function negativeStockAllowed() {
        return !!(context && context.settings && context.settings.stock_allow_negative);
    }

    function availableLineQty(line) {
        const factor = Math.max(0.001, Number(line.unit_factor) || 1);
        return Math.max(0, Number(line.balance) || 0) / factor;
    }

    function lineOverStock(line) {
        if (!line || line.stock_known === false) return false;
        return Number(line.qty || 0) > availableLineQty(line) + 0.0000001;
    }

    function validateStockCart(targetAlert) {
        if (negativeStockAllowed()) return true;
        const bad = cart.find(function (line) { return lineOverStock(line); });
        if (!bad) return true;
        const message = 'الرصيد مش مكفّي (المتاح ' + Number(availableLineQty(bad).toFixed(3)) + ')';
        if (targetAlert) targetAlert.innerHTML = ui.alert('neg', message); else showAlert('warn', message);
        return false;
    }

    function noteInputSpeed(input, event) {
        const now = Number(event.timeStamp) || Date.now();
        const last = Number(input.dataset.scanLast || 0);
        let count = Number(input.dataset.scanCount || 0);
        count = last && now - last > 0 && now - last <= 50 ? count + 1 : 1;
        input.dataset.scanLast = String(now);
        input.dataset.scanCount = String(count);
        input.dataset.scanFast = count >= 4 && String(input.value || '').length >= 4 ? '1' : '0';
        return input.dataset.scanFast === '1';
    }

    function enforceQuantityStock(store, input, idx) {
        if (!store.cart[idx] || !store.stockGuard || negativeStockAllowed() || !lineOverStock(store.cart[idx])) return true;
        const available = availableLineQty(store.cart[idx]);
        showAlert('warn', 'الرصيد مش مكفّي (المتاح ' + Number(available.toFixed(3)) + ')');
        store.cart[idx].qty = available > 0 ? available : Math.max(0.001, Number(input.dataset.qtyBeforeInput) || 1);
        input.value = String(store.cart[idx].qty);
        syncLineInputsFor(store, idx);
        store.onChange();
        return false;
    }

    function routeQuantityBarcode(store, input, idx, raw) {
        if (!raw || input.dataset.barcodeRouted === '1') return;
        input.dataset.barcodeRouted = '1';
        if (store.cart[idx]) {
            store.cart[idx].qty = Math.max(0.001, Number(input.dataset.qtyBeforeInput) || 1);
            syncLineInputsFor(store, idx);
            store.onChange();
        }
        const emptyCode = store.tbody.querySelector('tr[data-empty-row="1"] [data-cart-field="code"]');
        if (!emptyCode) return;
        emptyCode.value = raw;
        emptyCode.dataset.scanFast = '1';
        handleCodeEnter(store, emptyCode, true);
    }

    // حساب سطر واحد — نفس معادلة sale_compute على السيرفر (بريف 827k)
    function computeLineAmounts(line) {
        const lineGross = round2((Number(line.qty) || 0) * (Number(line.unit_price) || 0));
        const clean = normalizeDiscount(line.line_discount);
        clean.value = clean.type === 'percent'
            ? Math.min(100, Math.max(0, clean.value))
            : Math.min(lineGross, Math.max(0, round2(clean.value)));
        const lineDisc = discountAmount(clean, lineGross);
        line.line_discount = clean;
        line.gross = lineGross;
        line.line_discount_amount = lineDisc;
        line.line_discount_pct = clean.type === 'percent'
            ? clean.value
            : (lineGross > 0 ? Math.round(lineDisc / lineGross * 10000) / 100 : 0);
        line.net = round2(lineGross - lineDisc);
        line.pieces = Math.round((Number(line.qty) || 0) * (Number(line.unit_factor) || 1) * 1000) / 1000;
        return line;
    }

    function computeTotals() {
        let gross = 0;
        let lineDiscountTotal = 0;
        let piecesCount = 0;
        cart.forEach(function (line) {
            computeLineAmounts(line);
            gross = round2(gross + line.gross);
            lineDiscountTotal = round2(lineDiscountTotal + line.line_discount_amount);
            piecesCount += line.pieces;
        });
        const afterLine = round2(gross - lineDiscountTotal);
        invoiceDiscount = normalizeDiscount(invoiceDiscount);
        invoiceDiscount.value = invoiceDiscount.type === 'percent'
            ? Math.min(100, Math.max(0, invoiceDiscount.value))
            : Math.min(afterLine, Math.max(0, round2(invoiceDiscount.value)));
        const invDiscAmount = discountAmount(invoiceDiscount, afterLine);
        const invDiscPct = invoiceDiscount.type === 'percent'
            ? invoiceDiscount.value
            : (afterLine > 0 ? Math.round(invDiscAmount / afterLine * 10000) / 100 : 0);
        const net = round2(afterLine - invDiscAmount);
        const totalDiscount = round2(lineDiscountTotal + invDiscAmount);
        const discountPct = gross > 0 ? Math.round(totalDiscount / gross * 100000) / 1000 : 0;

        netDisplay.textContent = net.toFixed(2);
        grossDisplay.textContent = gross.toFixed(2);
        lineDiscDisplay.textContent = lineDiscountTotal.toFixed(2);
        invDiscDisplay.textContent = invDiscAmount.toFixed(2);
        totalDiscDisplay.textContent = totalDiscount.toFixed(2);
        linesCountEl.textContent = String(cart.length);
        piecesCountEl.textContent = String(piecesCount);
        const itemsPiecesDisplay = document.getElementById('posItemsPiecesDisplay');
        if (itemsPiecesDisplay) {
            itemsPiecesDisplay.textContent = cart.length + ' أصناف · ' + piecesCount + ' قطعة';
        }
        if (document.activeElement !== invDiscPctInput) {
            invDiscPctInput.value = invoiceDiscount.type === 'percent'
                ? invoiceDiscount.value
                : invDiscPct.toFixed(2);
        }
        if (document.activeElement !== invDiscAmountInput) {
            invDiscAmountInput.value = invoiceDiscount.type === 'amount'
                ? invoiceDiscount.value
                : invDiscAmount.toFixed(2);
        }
        payBtn.disabled = cart.length === 0 || (context && context.ready === false);
        return {gross: gross, line_discount: lineDiscountTotal, after_line: afterLine,
            invoice_discount: invDiscAmount, net: net, discount_pct: discountPct};
    }

    // ==========================================
    // رسم السلة — نسخة واحدة لكل التابات (بريف 827k)
    // ==========================================
    function renderCartView(store, focus) {
        if (!store || !store.tbody) return;
        const list = store.cart;
        list.forEach(function (line) { computeLineAmounts(line); });
        const rows = list.map(function (item, idx) {
            const stockClass = (store.stockGuard && negativeStockAllowed() && lineOverStock(item)) ? ' class="frow-red"' : '';
            const isPct = item.line_discount && item.line_discount.type === 'percent';
            const pctVal = isPct ? item.line_discount.value : Number(item.line_discount_pct || 0).toFixed(2);
            const amtVal = !isPct && item.line_discount && item.line_discount.type === 'amount'
                ? item.line_discount.value
                : Number(item.line_discount_amount || 0).toFixed(2);
            const discCells = store.discounts
                ? '<td class="num fcell"><input type="number" min="0" max="100" step="any" class="finput finput-cell num" data-idx="' + idx + '" data-cart-field="discount_pct" value="' + pctVal + '"></td>' +
                  '<td class="num fcell"><input type="number" min="0" step="any" class="finput finput-cell num" data-idx="' + idx + '" data-cart-field="discount_amount" value="' + amtVal + '"></td>'
                : '';
            return '<tr data-idx="' + idx + '" data-line="' + idx + '"' + stockClass + '>' +
                '<td class="num">' + (idx + 1) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-ghost pos-row-btn" title="الكود اتقفل بعد ضرب الصنف" disabled><i class="ti ti-camera"></i></button></td>' +
                '<td class="num">' + ui.esc(item.code) + '</td>' +
                '<td class="name"><span class="pos-item-name" title="' + ui.esc(item.name) + '">' + ui.esc(item.name) + '</span></td>' +
                '<td>' + ui.esc(item.unit_name || 'قطعة') + '</td>' +
                '<td class="num">' + Number(item.balance || 0) + '</td>' +
                '<td class="num fcell"><input type="number" min="0.001" step="any" class="finput finput-cell num" data-idx="' + idx + '" data-cart-field="qty" value="' + Number(item.qty) + '"></td>' +
                '<td class="num">' + Number(item.unit_price || 0).toFixed(2) + '</td>' +
                '<td class="num" data-line-gross="' + idx + '">' + Number(item.gross || 0).toFixed(2) + '</td>' +
                discCells +
                '<td><button type="button" class="btn btn-sm btn-ghost pos-row-btn" data-action="remove" data-idx="' + idx + '" title="مسح الصف"><i class="ti ti-trash"></i></button></td>' +
            '</tr>';
        }).join('');
        const emptyIdx = list.length;
        const emptyTds = store.discounts
            ? '<td></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td></td>'
            : '<td></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td></td>';
        store.tbody.innerHTML = rows + '<tr data-empty-row="1" data-line="' + emptyIdx + '">' +
            '<td class="num">' + (emptyIdx + 1) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-ghost pos-row-btn" data-action="camera" data-idx="' + emptyIdx + '" title="مسح بالكاميرا"><i class="ti ti-camera"></i></button></td>' +
            '<td class="fcell"><input class="finput finput-cell num" id="' + store.codeIdPrefix + emptyIdx + '" data-idx="' + emptyIdx + '" data-cart-field="code" autocomplete="off" placeholder="الكود…"></td>' +
            '<td class="fcell"><input class="finput finput-cell" id="' + store.nameIdPrefix + emptyIdx + '" data-idx="' + emptyIdx + '" data-cart-field="name" autocomplete="off" placeholder="اكتب اسم الصنف…"></td>' +
            emptyTds + '</tr>';
        if (store.cartEmpty) store.cartEmpty.innerHTML = '';
        if (store.onTotals) store.onTotals();
        if (focus) {
            const el = store.tbody.querySelector('[data-idx="' + focus.idx + '"][data-cart-field="' + focus.field + '"]');
            if (el) { el.focus(); if (focus.select && el.select) el.select(); }
        }
    }

    function renderCart(focus) {
        renderCartView(saleStore, focus);
    }

    function syncLineInputsFor(store, idx) {
        const line = store.cart[idx];
        if (!line) return;
        computeLineAmounts(line);
        if (store.onTotals) store.onTotals();
        const row = store.tbody.querySelector('tr[data-idx="' + idx + '"]');
        if (!row) return;
        const grossCell = row.querySelector('[data-line-gross]');
        const pct = row.querySelector('[data-cart-field="discount_pct"]');
        const amount = row.querySelector('[data-cart-field="discount_amount"]');
        if (grossCell) grossCell.textContent = Number(line.gross || 0).toFixed(2);
        const isPct = line.line_discount && line.line_discount.type === 'percent';
        if (pct && document.activeElement !== pct) {
            pct.value = isPct ? line.line_discount.value : Number(line.line_discount_pct || 0).toFixed(2);
        }
        if (amount && document.activeElement !== amount) {
            amount.value = !isPct && line.line_discount && line.line_discount.type === 'amount'
                ? line.line_discount.value
                : Number(line.line_discount_amount || 0).toFixed(2);
        }
        row.classList.toggle('frow-red', !!(store.stockGuard && negativeStockAllowed() && lineOverStock(line)));
    }

    function syncLineInputs(idx) {
        syncLineInputsFor(saleStore, idx);
    }

    function removeCartLine(store, idx) {
        if (!store.cart[idx]) return;
        fgConfirm('متأكد إنك عايز تمسح الصف ده؟', {title:'مسح صف', okText:'مسح', cancelText:'رجوع', danger:true})
            .then(function (confirmed) {
                if (!confirmed || !store.cart[idx]) return;
                const removed = store.cart[idx];
                store.cart.splice(idx, 1);
                renderCartView(store, {idx: store.cart.length, field: 'code'});
                store.onChange();
                if (store.key === 'sale' && context && context.session) {
                    window.apiFetch('pos_api.php?part=cart_event', {method:'POST', body:{
                        device_uuid:deviceUuid, event_type:'line_removed', item_id:removed.item_id,
                        code:removed.code, name:removed.name, qty:removed.qty, price:removed.unit_price
                    }}).catch(function () {});
                }
            });
    }

    function clearCart(silent) {
        const count = cart.length;
        const clearedLines = cart.map(function (line) { return {item_id:line.item_id, code:line.code,
            name:line.name, qty:line.qty, price:line.unit_price}; });
        clearDraft();
        cart = [];
        invoiceDiscount = {type:'amount', value:0};
        currentCustomer = null;
        currentDocId = null;
        currentClientUuid = '';
        approvalToken = null;
        paymentRows = [];
        paymentPanelOpen = false;
        saleItemsPanel.style.display = 'block';
        paymentPanel.style.display = 'none';
        updateCustomerBadge();
        renderCart({idx:0, field:'code'});
        if (!silent && count && context && context.session) {
            window.apiFetch('pos_api.php?part=cart_event', {method:'POST', body:{
                device_uuid:deviceUuid, event_type:'cart_cleared', lines_count:count, lines:clearedLines
            }}).catch(function () {});
        }
    }

    document.getElementById('posBtnClearCart').addEventListener('click', function () {
        if (!cart.length) return;
        fgConfirm('متأكد إنك عايز تمسح الفاتورة كلها؟', {title:'مسح الفاتورة', okText:'مسح', cancelText:'رجوع', danger:true})
            .then(function (confirmed) { if (confirmed) clearCart(false); });
    });

    // اسم السطر = اسم الكود (hq_codes.name) ولو فاضي اسم الصنف (بريف 827j)
    function codeDisplayName(codeInfo) {
        return normalizeSpaces((codeInfo && (codeInfo.code_name || codeInfo.name)) || '');
    }

    function lineDisplayName(item, codeInfo) {
        return codeDisplayName(codeInfo) || normalizeSpaces(item.name || '');
    }

    function roundQty(value) {
        return Math.round((Number(value) || 0) * 1000) / 1000;
    }

    // أول صف قبل idx بنفس الكود — مفتاح التجميع الكود مش item_id (بريف 827j)
    function findEarlierLineByCode(store, idx, code) {
        for (let i = 0; i < idx; i++) {
            if (store.cart[i] && String(store.cart[i].code) === String(code)) return i;
        }
        return -1;
    }

    // الكود اللي اتضرب بالظبط أو اللي اتختار من البوب أب؛ codes[0] بس لما الإضافة كانت باسم من غير كود (بريف 827k)
    function addItemToCart(store, item, selectedCode, focusNextCode) {
        const codeInfo = selectedCode || (item.codes && item.codes[0]);
        if (!codeInfo || !codeInfo.code) { showAlert('warn', 'الصنف ده ملوش كود بيع.'); return; }
        // الاسكانر: الكود الموجود في السلة بياخد +1 على طول من غير صف جديد (بريف 827j)
        if (focusNextCode) {
            const dupIdx = store.cart.findIndex(function (line) { return String(line.code) === String(codeInfo.code); });
            if (dupIdx >= 0) {
                const dupLine = store.cart[dupIdx];
                const nextQty = roundQty(Number(dupLine.qty) + 1);
                if (store.stockGuard && !negativeStockAllowed() && nextQty > availableLineQty(dupLine) + 0.0000001) {
                    showAlert('warn', 'الرصيد مش مكفّي (المتاح ' + Number(availableLineQty(dupLine).toFixed(3)) + ')');
                    return;
                }
                dupLine.qty = nextQty;
                clearAlert();
                renderCartView(store, {idx: store.cart.length, field: 'code'});
                store.onChange();
                return;
            }
        }
        const newLine = {item_id:item.item_id, name:lineDisplayName(item, codeInfo), source:item.source, balance:Number(item.balance || 0), stock_known:true,
            code:codeInfo.code, unit_name:codeInfo.unit_name || 'قطعة', unit_factor:codeInfo.unit_factor || 1,
            unit_price:Number(codeInfo.price || 0), qty:1, line_discount:{type:'amount', value:0}, codes:item.codes || [codeInfo]};
        if (store.stockGuard && !negativeStockAllowed() && lineOverStock(newLine)) {
            showAlert('warn', 'الرصيد مش مكفّي (المتاح ' + Number(availableLineQty(newLine).toFixed(3)) + ')');
            return;
        }
        store.cart.push(newLine);
        clearAlert();
        if (modalItemSearch.style.display === 'flex') {
            modalItemSearch.style.display = 'none';
            if (!document.querySelector('.fg-dlg-bg[style*="display: flex"]')) document.documentElement.classList.remove('fg-dlg-open');
        }
        renderCartView(store, focusNextCode
            ? {idx:store.cart.length, field:'code'}
            : {idx:store.cart.length - 1, field:'qty', select:true});
        store.onChange();
    }

    // تجميع الصف المكرر لما تدوس Enter في الكمية (بريف 827j)
    // بيرجّع 'merged' لو اتجمّع · 'blocked' لو الرصيد مش مكفّي · 'none' لو مفيش صف قبله بنفس الكود
    function mergeDuplicateLine(store, idx) {
        const line = store.cart[idx];
        if (!line) return 'none';
        const targetIdx = findEarlierLineByCode(store, idx, line.code);
        if (targetIdx < 0) return 'none';
        const target = store.cart[targetIdx];
        const mergedQty = roundQty(Number(target.qty) + Number(line.qty));
        if (store.stockGuard && !negativeStockAllowed() && mergedQty > availableLineQty(target) + 0.0000001) {
            showAlert('warn', 'الرصيد مش مكفّي (المتاح ' + Number(availableLineQty(target).toFixed(3)) + ')');
            return 'blocked';
        }
        target.qty = mergedQty;
        store.cart.splice(idx, 1);
        clearAlert();
        renderCartView(store, {idx: store.cart.length, field: 'code'});
        store.onChange();
        return 'merged';
    }

    function findExactLookup(items, wanted) {
        let byItemName = null;
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            const codes = item.codes || [];
            for (let j = 0; j < codes.length; j++) {
                const codeName = codeDisplayName(codes[j]);
                if (codeName && normalizeSpaces(codeName).toLocaleLowerCase('ar') === wanted) {
                    return {item: item, code: codes[j]};
                }
            }
            if (!byItemName && codes.length && normalizeSpaces(item.name).toLocaleLowerCase('ar') === wanted) {
                byItemName = {item: item, code: codes[0]};
            }
        }
        return byItemName;
    }

    function lookupByName(store, val, input, scannerInput) {
        if (!deviceUuid) { showAlert('warn', 'اختار الجهاز الأول.'); promptDeviceSelection(); return Promise.resolve(); }
        if (input) input.disabled = true;
        return window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&q=' + encodeURIComponent(val))
            .then(function (res) {
                const items = res && res.data && res.data.items ? res.data.items : [];
                const wanted = normalizeSpaces(val).toLocaleLowerCase('ar');
                const exact = findExactLookup(items, wanted);
                if (exact) {
                    addItemToCart(store, exact.item, exact.code, scannerInput);
                } else {
                    openItemSearch(val, store.cart.length, store);
                }
            })
            .catch(function (err) { showAlert('neg', err.message || 'تعذر البحث عن الصنف.'); })
            .finally(function () { if (input) input.disabled = false; });
    }

    function handleCodeEnter(store, input, forceScanner) {
        const val = normalizeSpaces(input.value);
        const scannerInput = forceScanner === true || input.dataset.scanFast === '1';
        if (!val) return;
        if (!deviceUuid) { showAlert('warn', 'اختار الجهاز الأول.'); promptDeviceSelection(); return; }
        if (val === '0') {
            input.classList.add('is-invalid');
            showDrawerBlockedAlert();
            input.select();
            return;
        }
        input.disabled = true;
        window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&code=' + encodeURIComponent(val))
            .then(function (res) {
                const items = res.data && res.data.items ? res.data.items : [];
                let exactItem = null;
                let exactCode = null;
                items.some(function (item) {
                    const code = (item.codes || []).find(function (c) { return String(c.code) === val; });
                    if (code) { exactItem = item; exactCode = code; return true; }
                    return false;
                });
                if (exactItem) {
                    input.classList.remove('is-invalid');
                    addItemToCart(store, exactItem, exactCode, scannerInput);
                    return null;
                }
                input.classList.add('is-invalid');
                showAlert('neg', 'الكود ' + val + ' مش موجود');
                input.select();
                return null;
            })
            .catch(function (err) { showAlert('neg', err.message || 'تعذر البحث عن الصنف.'); })
            .finally(function () { input.disabled = false; });
    }

    // ==========================================
    // أحداث السلة — نفس المعالجات لكل التابات (بريف 827k)
    // ==========================================
    function bindCartEvents(store) {
        if (!store || !store.tbody) return;

        store.tbody.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('is-invalid')) {
                e.target.classList.remove('is-invalid');
            }
            const field = e.target.getAttribute('data-cart-field');
            const idx = Number(e.target.getAttribute('data-idx'));
            const fastInput = noteInputSpeed(e.target, e);
            if (!store.cart[idx]) return;
            if (field === 'qty') {
                if (!e.target.dataset.qtyBeforeInput) e.target.dataset.qtyBeforeInput = String(store.cart[idx].qty);
                if (fastInput) {
                    store.cart[idx].qty = Math.max(0.001, Number(e.target.dataset.qtyBeforeInput) || 1);
                    syncLineInputsFor(store, idx);
                    return;
                }
                store.cart[idx].qty = Math.max(0.001, Number(e.target.value) || 1);
                clearTimeout(quantityValidationTimer);
                quantityValidationTimer = setTimeout(function () {
                    if (e.target.dataset.scanFast === '1') return;
                    const raw = normalizeSpaces(e.target.value);
                    if (Number(raw) > 9999) routeQuantityBarcode(store, e.target, idx, raw);
                    else enforceQuantityStock(store, e.target, idx);
                }, 80);
            } else if (field === 'discount_pct') {
                store.cart[idx].line_discount = {type:'percent', value:Math.max(0, Number(e.target.value) || 0)};
            } else if (field === 'discount_amount') {
                store.cart[idx].line_discount = {type:'amount', value:Math.max(0, Number(e.target.value) || 0)};
            } else return;
            syncLineInputsFor(store, idx);
            store.onChange();
        });

        store.tbody.addEventListener('focusin', function (e) {
            if (e.target.getAttribute('data-cart-field') !== 'qty') return;
            const idx = Number(e.target.getAttribute('data-idx'));
            if (store.cart[idx]) e.target.dataset.qtyBeforeInput = String(store.cart[idx].qty);
            e.target.dataset.scanLast = '';
            e.target.dataset.scanCount = '0';
            e.target.dataset.scanFast = '0';
        });

        store.tbody.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const idx = Number(btn.getAttribute('data-idx'));
            if (btn.getAttribute('data-action') === 'remove') removeCartLine(store, idx);
            if (btn.getAttribute('data-action') === 'camera') {
                const input = store.tbody.querySelector('tr[data-empty-row="1"] [data-cart-field="code"]');
                if (!input) return;
                if (typeof window.openBarcodeScanner === 'function') window.openBarcodeScanner(input.id, {keepOpen:true});
                else showAlert('warn', 'مكتبة السكانر مش محملة.');
            }
        });

        store.tbody.addEventListener('keydown', function (e) {
            const input = e.target.closest('[data-cart-field]');
            if (!input) return;
            const field = input.getAttribute('data-cart-field');
            const idx = Number(input.getAttribute('data-idx'));
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                moveSameColumn(store, input, e.key === 'ArrowUp' ? -1 : 1);
            } else if (isEnter(e) && field === 'code') {
                e.preventDefault();
                handleCodeEnter(store, input);
            } else if (isEnter(e) && field === 'name') {
                e.preventDefault();
                const val = normalizeSpaces(input.value);
                if (!val) return;
                lookupByName(store, val, input, false);
            } else if (isEnter(e) && field === 'qty') {
                e.preventDefault();
                clearTimeout(quantityValidationTimer);
                const raw = normalizeSpaces(input.value);
                if (input.dataset.scanFast === '1' || Number(raw) > 9999) {
                    routeQuantityBarcode(store, input, idx, raw);
                    return;
                }
                enforceQuantityStock(store, input, idx);
                if (mergeDuplicateLine(store, idx) === 'blocked') {
                    input.select();
                    return;
                }
                const emptyCode = store.tbody.querySelector('tr[data-empty-row="1"] [data-cart-field="code"]');
                if (emptyCode) emptyCode.focus();
            } else if (e.ctrlKey && e.key === 'Delete' && store.cart[idx]) {
                e.preventDefault();
                e.stopPropagation();
                removeCartLine(store, idx);
            }
        });
    }

    function moveSameColumn(store, input, direction) {
        const field = input.getAttribute('data-cart-field');
        const fields = Array.from(store.tbody.querySelectorAll('[data-cart-field="' + field + '"]'));
        const next = fields[fields.indexOf(input) + direction];
        if (next) { next.focus(); if (next.select) next.select(); }
    }

    bindCartEvents(saleStore);

    const modalItemSearch = document.getElementById('posModalItemSearch');
    const itemSearchInput = document.getElementById('posItemSearchInput');
    const itemSearchBody = document.getElementById('posItemSearchBody');
    const itemSearchEmpty = document.getElementById('posItemSearchEmpty');
    const itemSearchAlert = document.getElementById('posItemSearchAlert');
    let itemSearchField = 'name';
    let itemSearchMode = 'middle';
    let itemSearchRows = [];
    let itemSearchSelected = 0;
    let itemSearchTargetRow = 0;
    let itemSearchTargetStore = null;
    let itemSearchTimer = null;

    function openItemSearch(value, targetRow, store) {
        itemSearchField = 'name';
        itemSearchMode = 'middle';
        document.querySelectorAll('#posSearchFieldTabs .ftab').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-value') === itemSearchField);
        });
        document.querySelectorAll('#posSearchModeTabs .ftab').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-value') === itemSearchMode);
        });
        itemSearchTargetStore = store || saleStore;
        itemSearchTargetRow = Number.isFinite(targetRow) ? targetRow : itemSearchTargetStore.cart.length;
        itemSearchInput.value = value || '';
        itemSearchAlert.innerHTML = '';
        openModal(modalItemSearch);
        setTimeout(function () { itemSearchInput.focus(); itemSearchInput.select(); if (itemSearchInput.value) runItemSearch(); }, 80);
    }
    function runItemSearch() {
        const q = normalizeSpaces(itemSearchInput.value);
        if (!q) { itemSearchRows = []; itemSearchBody.innerHTML = ''; itemSearchEmpty.innerHTML = ui.empty('اكتب كلمة البحث'); return; }
        const key = itemSearchField === 'code' ? 'code' : 'q';
        window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&' + key + '=' + encodeURIComponent(q) + '&mode=' + itemSearchMode)
            .then(function (res) {
                itemSearchRows = [];
                (res.data && res.data.items ? res.data.items : []).forEach(function (item) {
                    (item.codes || []).forEach(function (code) {
                        const codeText = String(code.code || '');
                        const codeMatches = itemSearchMode === 'start' ? codeText.startsWith(q)
                            : (itemSearchMode === 'end' ? codeText.endsWith(q) : codeText.includes(q));
                        if (itemSearchField === 'name' || codeMatches) itemSearchRows.push({item:item, code:code});
                    });
                });
                itemSearchSelected = 0;
                renderItemSearchRows();
            }).catch(function (err) { itemSearchAlert.innerHTML = ui.alert('neg', err.message || 'تعذر البحث.'); });
    }
    function renderItemSearchRows() {
        itemSearchBody.innerHTML = itemSearchRows.map(function (row, idx) {
            return '<tr data-search-idx="' + idx + '" class="' + (idx === itemSearchSelected ? 'frow-selected' : '') + '">' +
                '<td class="num">' + ui.esc(row.code.code || '') + '</td><td class="name">' + ui.esc(row.item.name || '') + '</td>' +
                '<td class="num">' + Number(row.code.price || 0).toFixed(2) + '</td><td>' + ui.esc(row.code.unit_name || 'قطعة') + '</td>' +
                '<td class="num">' + Number(row.code.unit_factor || 1) + '</td><td class="num">' + Number(row.item.balance || 0) + '</td></tr>';
        }).join('');
        itemSearchEmpty.innerHTML = itemSearchRows.length ? '' : ui.empty('مفيش نتايج');
    }
    function chooseItemSearch() {
        const row = itemSearchRows[itemSearchSelected];
        const store = itemSearchTargetStore || saleStore;
        if (!row || itemSearchTargetRow !== store.cart.length) return;
        addItemToCart(store, row.item, row.code);
    }
    itemSearchInput.addEventListener('input', function () {
        clearTimeout(itemSearchTimer);
        itemSearchTimer = setTimeout(runItemSearch, 180);
    });
    itemSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!itemSearchRows.length) return;
            itemSearchSelected = Math.max(0, Math.min(itemSearchRows.length - 1, itemSearchSelected + (e.key === 'ArrowDown' ? 1 : -1)));
            renderItemSearchRows();
            const row = itemSearchBody.querySelector('[data-search-idx="' + itemSearchSelected + '"]');
            if (row) row.scrollIntoView({block:'nearest'});
        } else if (isEnter(e)) { e.preventDefault(); chooseItemSearch(); }
    });
    itemSearchBody.addEventListener('click', function (e) {
        const row = e.target.closest('[data-search-idx]');
        if (!row) return;
        itemSearchSelected = Number(row.getAttribute('data-search-idx'));
        chooseItemSearch();
    });
    function bindSearchTabs(id, setter) {
        document.getElementById(id).addEventListener('click', function (e) {
            const tab = e.target.closest('.ftab');
            if (!tab) return;
            this.querySelectorAll('.ftab').forEach(function (el) { el.classList.toggle('is-active', el === tab); });
            setter(tab.getAttribute('data-value'));
            if (itemSearchInput.value) runItemSearch();
        });
    }
    bindSearchTabs('posSearchFieldTabs', function (v) { itemSearchField = v; });
    bindSearchTabs('posSearchModeTabs', function (v) { itemSearchMode = v; });
    document.getElementById('posBtnOpenItemSearch').addEventListener('click', function () { openItemSearch('', cart.length); });
    document.getElementById('posBtnCloseItemSearch').addEventListener('click', function () { closeModal(modalItemSearch); });

    invDiscPctInput.addEventListener('input', function () {
        invoiceDiscount = {type:'percent', value:Math.max(0, Number(this.value) || 0)};
        computeTotals();
        saveDraft();
    });
    invDiscAmountInput.addEventListener('input', function () {
        invoiceDiscount = {type:'amount', value:Math.max(0, Number(this.value) || 0)};
        computeTotals();
        saveDraft();
    });

    // ==========================================
    // لوحة الدفع والإنهاء (F6 / F12)
    // ==========================================
    const modalPayment = document.getElementById('posModalPayment');
    const payModalNet = document.getElementById('posPayModalNet');
    const paymentRowsContainer = document.getElementById('posPaymentRowsContainer');
    const changeDueBox = document.getElementById('posChangeDueBox');
    const confirmFinalizeBtn = document.getElementById('posBtnConfirmFinalize');
    const paymentModalAlert = document.getElementById('posPaymentModalAlert');
    const paymentViewSuccess = document.getElementById('posPaymentViewSuccess');
    const paidTotalEl = document.getElementById('posPaidTotal');
    const remainingTotalEl = document.getElementById('posRemainingTotal');

    let paymentRows = [];

    function openPaymentModal() {
        if (paymentPanelOpen) return;
        if (!cart.length) { showAlert('warn', 'الفاتورة فاضية.'); return; }
        if (!validateStockCart()) return;
        const totals = computeTotals();
        const cap = (context && context.discount_cap_pct) ? Number(context.discount_cap_pct) : 0;

        // التحقق من سقف الخصم واعتماد المشرف
        if (totals.discount_pct > cap + 0.0005 && !approvalToken) {
            promptSupervisorApproval(totals.discount_pct);
            return;
        }

        // توليد client_uuid مرة واحدة للنافذة لمنع التكرار
        if (!currentClientUuid) {
            currentClientUuid = (typeof crypto !== 'undefined' && crypto.randomUUID) ?
                crypto.randomUUID() :
                'cli_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        }

        paymentModalAlert.innerHTML = '';
        payModalNet.textContent = totals.net.toFixed(2);

        paymentRows = [
            { method: 'cash', amount: totals.net.toFixed(2), reference: '' }
        ];
        renderPaymentRows();
        paymentPanelOpen = true;
        saleItemsPanel.style.display = 'none';
        paymentPanel.style.display = 'block';

        setTimeout(function () {
            const firstAmt = paymentRowsContainer.querySelector('input[type="number"]');
            if (firstAmt) { firstAmt.focus(); firstAmt.select(); }
        }, 100);
    }

    function closePaymentPanel() {
        paymentPanelOpen = false;
        paymentPanel.style.display = 'none';
        saleItemsPanel.style.display = 'block';
        adjustCartTableHeight();
        refocusScan();
    }

    function renderPaymentRows() {
        const totals = computeTotals();
        let totalPaid = 0;
        paymentRowsContainer.innerHTML = paymentRows.map(function (row, idx) {
            const amt = parseFloat(row.amount) || 0;
            totalPaid += amt;

            return '<tr data-idx="' + idx + '"><td>' +
                '<select class="fselect" data-field="method" data-idx="' + idx + '">' +
                    '<option value="cash"' + (row.method === 'cash' ? ' selected' : '') + '>كاش</option>' +
                    '<option value="visa"' + (row.method === 'visa' ? ' selected' : '') + '>فيزا</option>' +
                    '<option value="instapay"' + (row.method === 'instapay' ? ' selected' : '') + '>إنستا باي</option>' +
                '</select></td><td class="num">' +
                '<input type="number" step="0.01" min="0" class="finput finput-sm num" data-field="amount" data-idx="' + idx + '" value="' + (row.amount || '') + '"></td><td>' +
                '<input type="text" class="finput finput-sm" data-field="reference" data-idx="' + idx + '" value="' + ui.esc(row.reference || '') + '" placeholder="ملاحظات"></td><td>' +
                (paymentRows.length > 1 ?
                    '<button type="button" class="btn btn-sm btn-ghost" data-field="remove" data-idx="' + idx + '"><i class="ti ti-x"></i></button>' :
                    '<span></span>') +
            '</td></tr>';
        }).join('');

        const remaining = round2(totals.net - totalPaid);
        paidTotalEl.textContent = totalPaid.toFixed(2);
        remainingTotalEl.textContent = remaining.toFixed(2);
        changeDueBox.className = 'falert ' + (Math.abs(remaining) < 0.005 ? 'falert-pos' : 'falert-warn');
        changeDueBox.textContent = 'المتبقي: ' + remaining.toFixed(2) + ' ج.م';
        confirmFinalizeBtn.disabled = Math.abs(remaining) >= 0.005;

        document.getElementById('posBtnAddPaymentRow').style.display =
            (paymentRows.length < 3) ? 'inline-flex' : 'none';
    }

    paymentRowsContainer.addEventListener('input', function (e) {
        const field = e.target.getAttribute('data-field');
        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
        if (isNaN(idx) || !paymentRows[idx]) return;

        if (field === 'amount') {
            paymentRows[idx].amount = e.target.value;
            const totals = computeTotals();
            let totalPaid = 0;
            paymentRows.forEach(function (r) { totalPaid += (parseFloat(r.amount) || 0); });
            const remaining = round2(totals.net - totalPaid);
            paidTotalEl.textContent = totalPaid.toFixed(2);
            remainingTotalEl.textContent = remaining.toFixed(2);
            changeDueBox.className = 'falert ' + (Math.abs(remaining) < 0.005 ? 'falert-pos' : 'falert-warn');
            changeDueBox.textContent = 'المتبقي: ' + remaining.toFixed(2) + ' ج.م';
            confirmFinalizeBtn.disabled = Math.abs(remaining) >= 0.005;
        } else if (field === 'reference') {
            paymentRows[idx].reference = e.target.value;
        }
    });

    paymentRowsContainer.addEventListener('change', function (e) {
        const field = e.target.getAttribute('data-field');
        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
        if (isNaN(idx) || !paymentRows[idx]) return;

        if (field === 'method') {
            paymentRows[idx].method = e.target.value;
            renderPaymentRows();
        }
    });

    paymentRowsContainer.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-field="remove"]');
        if (!btn) return;
        const idx = parseInt(btn.getAttribute('data-idx'), 10);
        if (!isNaN(idx) && paymentRows.length > 1) {
            paymentRows.splice(idx, 1);
            renderPaymentRows();
        }
    });

    paymentRowsContainer.addEventListener('keydown', function (e) {
        if (isEnter(e) && e.target.getAttribute('data-field') === 'amount') {
            e.preventDefault();
            const idx = parseInt(e.target.getAttribute('data-idx'), 10);
            if (paymentRows[idx] && paymentRows[idx].method === 'cash') {
                const totals = computeTotals();
                let otherPaid = 0;
                paymentRows.forEach(function (r, i) {
                    if (i !== idx) otherPaid += (parseFloat(r.amount) || 0);
                });
                const rem = Math.max(0, Math.round((totals.net - otherPaid) * 100) / 100);
                e.target.value = rem.toFixed(2);
                paymentRows[idx].amount = rem.toFixed(2);
                renderPaymentRows();
            }
        }
    });

    document.getElementById('posBtnAddPaymentRow').addEventListener('click', function () {
        if (paymentRows.length >= 3) return;
        const totals = computeTotals();
        let totalPaid = 0;
        paymentRows.forEach(function (r) { totalPaid += (parseFloat(r.amount) || 0); });
        const remain = Math.max(0, Math.round((totals.net - totalPaid) * 100) / 100);

        paymentRows.push({
            method: 'visa',
            amount: remain.toFixed(2),
            reference: ''
        });
        renderPaymentRows();
    });

    payBtn.addEventListener('click', openPaymentModal);
    document.getElementById('posBtnClosePaymentModal').addEventListener('click', function () {
        closePaymentPanel();
    });

    // إرسال الفاتورة والإنهاء
    confirmFinalizeBtn.addEventListener('click', function () {
        if (isSubmitting) return;
        if (!validateStockCart(paymentModalAlert)) return;
        const totals = computeTotals();
        let totalPaid = 0;
        const cleanPayments = [];

        for (let i = 0; i < paymentRows.length; i++) {
            const r = paymentRows[i];
            const amt = parseFloat(r.amount) || 0;
            if (amt <= 0) {
                paymentModalAlert.innerHTML = ui.alert('neg', 'مبلغ الدفعة لازم يكون أكبر من صفر.');
                return;
            }
            totalPaid += amt;
            cleanPayments.push({
                method: r.method,
                amount: amt,
                reference: r.reference ? r.reference.trim() : null
            });
        }

        if (Math.abs(round2(totalPaid - totals.net)) >= 0.005) {
            paymentModalAlert.innerHTML = ui.alert('neg', 'لازم المتبقي يبقى صفر قبل الحفظ.');
            return;
        }

        isSubmitting = true;
        confirmFinalizeBtn.disabled = true;
        paymentModalAlert.innerHTML = '';

        const payload = {
            device_uuid: deviceUuid,
            client_uuid: currentClientUuid,
            doc_id: currentDocId || undefined,
            lines: cart.map(function (line) {
                return {
                    code: line.code,
                    qty: Number(line.qty),
                    line_discount: line.line_discount || 0
                };
            }),
            invoice_discount: invoiceDiscount || 0,
            payments: cleanPayments,
            approval_token: approvalToken || undefined,
            customer_id: currentCustomer ? currentCustomer.id : undefined
        };

        window.apiFetch('pos_api.php?part=finalize', {
            method: 'POST',
            body: payload
        })
        .then(function (res) {
            const data = res.data || {};
            lastDocId = Number(data.doc_id || 0);
            const btnPrintLast = document.getElementById('posBtnPrintLast');
            if (btnPrintLast) btnPrintLast.style.display = 'inline-flex';

            // نجاح: عرض شاشة التأكيد
            paymentViewSuccess.style.display = 'block';
            openModal(modalPayment);
            document.getElementById('posSuccessReceiptNo').textContent = 'رقم الإيصال: ' + (data.receipt_no || '');
            document.getElementById('posSuccessChangeBox').textContent = 'الباقي: ' + Number(data.change_due || 0).toFixed(2) + ' ج.م';

            // تفريغ السلة في الذاكرة بدون حدث حذف
            clearCart(true);
            const btnNew = document.getElementById('posBtnNewSale');
            if (btnNew) btnNew.focus();

            // طباعة تلقائية بعد الحفظ — فشلها لا يوقف البيع
            try {
                triggerPrint(lastDocId, false);
            } catch (e) {
                showAlert('warn', 'الطباعة فشلت — اطبع تاني');
            }
        })
        .catch(function (err) {
            // خطأ: لا يتم تفريغ السلة ويتم الاحتفاظ بـclient_uuid لإعادة المحاولة
            paymentModalAlert.innerHTML = ui.alert('neg', err.message || 'حدث خطأ أثناء حفظ الفاتورة.');
            confirmFinalizeBtn.disabled = false;
        })
        .finally(function () {
            isSubmitting = false;
        });
    });

    document.getElementById('posBtnNewSale').addEventListener('click', function () {
        closeModal(modalPayment);
        closePaymentPanel();
    });

    // ==========================================
    // الطباعة وطباعة آخر إيصال وفواتير الوردية (بريف ٢٤٤)
    // ==========================================
    let lastDocId = null;

    function triggerPrint(docId, isReprint) {
        if (!docId) return;
        window.apiFetch('pos_api.php?part=print', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                doc_id: Number(docId),
                reprint: !!isReprint
            }
        }).then(function (res) {
            const data = res.data || {};
            if (data.mode === 'browser' && data.url) {
                printReceiptUrl(data.url);
            } else if (data.mode === 'queue') {
                showAlert('pos', 'تم إرسال الإيصال إلى طابور الطباعة.');
            }
        }).catch(function (err) {
            showAlert('warn', 'الطباعة فشلت — اطبع تاني');
        });
    }

    function printReceiptUrl(url) {
        let iframe = document.getElementById('posPrintIframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'posPrintIframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }
        iframe.src = url;
    }

    const btnPrintReceipt = document.getElementById('posBtnPrintReceipt');
    if (btnPrintReceipt) {
        btnPrintReceipt.addEventListener('click', function () {
            if (lastDocId) {
                triggerPrint(lastDocId, true);
            }
        });
    }

    const btnPrintLast = document.getElementById('posBtnPrintLast');
    if (btnPrintLast) {
        btnPrintLast.addEventListener('click', function () {
            if (lastDocId) {
                triggerPrint(lastDocId, true);
            }
        });
    }

    // نافذة فواتير الوردية
    const modalSessionInvoices = document.getElementById('posModalSessionInvoices');
    const btnSessionInvoices = document.getElementById('posBtnSessionInvoices');
    const sessionInvoicesBody = document.getElementById('posSessionInvoicesBody');
    const sessionInvoicesEmpty = document.getElementById('posSessionInvoicesEmpty');
    const sessionInvoicesAlert = document.getElementById('posSessionInvoicesAlert');

    function openSessionInvoicesModal() {
        if (!modalSessionInvoices) return;
        sessionInvoicesAlert.innerHTML = '';
        sessionInvoicesBody.innerHTML = '<tr><td colspan="6" style="text-align:center;">جاري التحميل...</td></tr>';
        sessionInvoicesEmpty.innerHTML = '';
        openModal(modalSessionInvoices);

        window.apiFetch('pos_api.php?part=session_invoices&device_uuid=' + encodeURIComponent(deviceUuid))
            .then(function (res) {
                const docs = (res.data && res.data.docs) ? res.data.docs : [];
                renderSessionInvoices(docs);
            })
            .catch(function (err) {
                sessionInvoicesAlert.innerHTML = ui.alert('neg', err.message || 'تعذر تحميل فواتير الوردية.');
                sessionInvoicesBody.innerHTML = '';
            });
    }

    function renderSessionInvoices(docs) {
        if (!docs.length) {
            sessionInvoicesBody.innerHTML = '';
            sessionInvoicesEmpty.innerHTML = ui.empty('مفيش فواتير مكتملة في الوردية الحالية');
            return;
        }
        sessionInvoicesEmpty.innerHTML = '';
        sessionInvoicesBody.innerHTML = docs.map(function (d) {
            const isRet = d.doc_kind === 'return';
            const typeBadge = isRet
                ? '<span class="fchip fchip-warn">مرتجع</span>'
                : '<span class="fchip fchip-pos">بيع</span>';
            const timeStr = d.completed_at ? d.completed_at.slice(11, 16) : '--';
            const reprints = Number(d.reprint_count || 0);
            const reprintBadge = reprints > 0
                ? '<span class="fchip fchip-mute" style="margin-right:4px;">' + reprints + ' نسخة</span>'
                : '';
            return '<tr data-doc-id="' + Number(d.id) + '">' +
                '<td class="num" dir="ltr"><strong>' + ui.esc(d.receipt_no || '') + '</strong>' + reprintBadge + '</td>' +
                '<td>' + typeBadge + '</td>' +
                '<td>' + ui.esc(timeStr) + '</td>' +
                '<td class="num">' + Number(d.net || 0).toFixed(2) + ' ج.م</td>' +
                '<td>' + ui.esc(d.cashier_name || '') + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline pos-reprint-btn" data-doc-id="' + Number(d.id) + '">' +
                    '<i class="ti ti-printer"></i> طباعة' +
                '</button></td>' +
                '</tr>';
        }).join('');
    }

    if (btnSessionInvoices) {
        btnSessionInvoices.addEventListener('click', openSessionInvoicesModal);
    }
    const btnCloseSessionInvoices = document.getElementById('posBtnCloseSessionInvoices');
    if (btnCloseSessionInvoices) {
        btnCloseSessionInvoices.addEventListener('click', function () { closeModal(modalSessionInvoices); });
    }
    const btnCloseSessionInvoicesAction = document.getElementById('posBtnCloseSessionInvoicesAction');
    if (btnCloseSessionInvoicesAction) {
        btnCloseSessionInvoicesAction.addEventListener('click', function () { closeModal(modalSessionInvoices); });
    }

    if (sessionInvoicesBody) {
        sessionInvoicesBody.addEventListener('click', function (e) {
            const btn = e.target.closest('.pos-reprint-btn');
            if (!btn) return;
            const docId = Number(btn.getAttribute('data-doc-id'));
            if (docId > 0) {
                triggerPrint(docId, true);
                btn.disabled = true;
                setTimeout(function () {
                    btn.disabled = false;
                    openSessionInvoicesModal();
                }, 800);
            }
        });
    }

    // ==========================================
    // اعتماد المشرف (سقف الخصم · مرتجع متأخر · طريقة رد)
    // ==========================================
    const modalSupervisor = document.getElementById('posModalSupervisor');
    const superUser = document.getElementById('posSuperUser');
    const superPass = document.getElementById('posSuperPass');
    const supervisorAlert = document.getElementById('posSupervisorAlert');
    let pendingApproval = {
        type: 'discount',
        value: 0,
        onSuccess: null
    };

    function promptSupervisorApproval(opts) {
        if (typeof opts === 'number') {
            opts = {
                type: 'discount',
                value: opts,
                title: 'اعتماد مشرف للخصم',
                warning: 'نسبة الخصم (' + opts + '%) أكبر من سقف الكاشير المعتمد. مطلوب اعتماد مشرف أو مالك.',
                onSuccess: function (tok) {
                    approvalToken = tok;
                    openPaymentModal();
                }
            };
        }
        pendingApproval = {
            type: opts.type || 'discount',
            value: opts.value || 0,
            onSuccess: opts.onSuccess || null
        };
        supervisorAlert.innerHTML = '';
        superUser.value = '';
        superPass.value = '';
        document.getElementById('posSupervisorTitle').innerHTML = '<i class="ti ti-lock"></i> ' + (opts.title || 'اعتماد مشرف');
        document.getElementById('posSupervisorWarning').textContent = opts.warning || 'مطلوب اعتماد مشرف أو مالك.';
        openModal(modalSupervisor);
        setTimeout(function () { superUser.focus(); }, 100);
    }

    document.getElementById('posBtnCloseSupervisor').addEventListener('click', function () {
        closeModal(modalSupervisor);
    });

    document.getElementById('posBtnSubmitSupervisor').addEventListener('click', function () {
        const u = superUser.value.trim();
        const p = superPass.value;
        if (!u || !p) {
            supervisorAlert.innerHTML = ui.alert('warn', 'اكتب اسم المستخدم وكلمة المرور.');
            return;
        }

        this.disabled = true;
        window.apiFetch('pos_api.php?part=approve', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                approval_type: pendingApproval.type,
                value: pendingApproval.value,
                doc_id: pendingApproval.docId || undefined,
                username: u,
                password: p
            }
        })
        .then(function (res) {
            const tok = res.data ? res.data.approval_token : null;
            closeModal(modalSupervisor);
            showAlert('pos', 'تم اعتماد المشرف بنجاح');
            if (typeof pendingApproval.onSuccess === 'function') {
                pendingApproval.onSuccess(tok);
            }
        })
        .catch(function (err) {
            supervisorAlert.innerHTML = ui.alert('neg', err.message || 'فشل اعتماد المشرف.');
        })
        .finally(function () {
            superPass.value = ''; // مسح الباسورد فوراً
            document.getElementById('posBtnSubmitSupervisor').disabled = false;
        });
    });

    // ==========================================
    // تعليق الفاتورة (F8) واسترجاعها (F9)
    // ==========================================
    const modalHeld = document.getElementById('posModalHeld');
    const heldBody = document.getElementById('posHeldBody');
    const heldEmpty = document.getElementById('posHeldEmpty');
    const heldAlert = document.getElementById('posHeldAlert');

    function holdCurrentCart() {
        if (!cart.length) {
            showAlert('warn', 'السلة فاضية — مفيش حاجة تتعلق.');
            return;
        }
        if (!currentClientUuid) {
            currentClientUuid = (typeof crypto !== 'undefined' && crypto.randomUUID) ?
                crypto.randomUUID() :
                'cli_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        }

        window.apiFetch('pos_api.php?part=hold', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                client_uuid: currentClientUuid,
                doc_id: currentDocId || undefined,
                lines: cart.map(function (line) {
                    return {
                        code: line.code,
                        qty: Number(line.qty),
                        line_discount: line.line_discount || 0
                    };
                }),
                invoice_discount: invoiceDiscount || 0,
                customer_id: currentCustomer ? currentCustomer.id : undefined
            }
        })
        .then(function () {
            clearCart(true);
            showAlert('pos', 'تم تعليق الفاتورة بنجاح.');
        })
        .catch(function (err) {
            showAlert('neg', err.message || 'تعذر تعليق الفاتورة.');
        });
    }

    document.getElementById('posBtnHold').addEventListener('click', holdCurrentCart);

    function openHeldListModal() {
        heldAlert.innerHTML = '';
        heldBody.innerHTML = '';
        heldEmpty.innerHTML = '';

        window.apiFetch('pos_api.php?part=held_list&device_uuid=' + encodeURIComponent(deviceUuid))
            .then(function (res) {
                const docs = (res.data && res.data.docs) ? res.data.docs : [];
                if (!docs.length) {
                    heldEmpty.innerHTML = ui.empty('مفيش فواتير معلقة في الفرع');
                    openModal(modalHeld);
                    return;
                }
                const canCancel = document.getElementById('posContainer').getAttribute('data-can-held-cancel') === '1';

                heldBody.innerHTML = docs.map(function (d) {
                    return '<tr data-id="' + Number(d.id) + '">' +
                        '<td class="num">' + Number(d.id) + '</td>' +
                        '<td>' + ui.esc(d.created_at || '') + '</td>' +
                        '<td>' + ui.esc(d.cashier_name || '') + '</td>' +
                        '<td>' + ui.esc(d.device_name || d.device_code || '') + '</td>' +
                        '<td class="num" style="font-weight:700;">' + Number(d.net || 0).toFixed(2) + '</td>' +
                        '<td>' +
                            '<button type="button" class="btn btn-sm btn-primary" data-action="resume-held" data-id="' + Number(d.id) + '">' +
                                '<i class="ti ti-rotate"></i> استرجاع' +
                            '</button> ' +
                            (canCancel ?
                                '<button type="button" class="btn btn-sm btn-ghost" data-action="cancel-held" data-id="' + Number(d.id) + '">' +
                                    '<i class="ti ti-x"></i> إلغاء' +
                                '</button>' : '') +
                        '</td>' +
                    '</tr>';
                }).join('');
                openModal(modalHeld);
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر جلب الفواتير المعلقة.');
            });
    }

    document.getElementById('posBtnHeldList').addEventListener('click', openHeldListModal);
    document.getElementById('posBtnCloseHeldModal').addEventListener('click', function () {
        closeModal(modalHeld);
    });

    heldBody.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const action = btn.getAttribute('data-action');
        const docId = parseInt(btn.getAttribute('data-id'), 10);
        if (!docId) return;

        if (action === 'resume-held') {
            btn.disabled = true;
            window.apiFetch('pos_api.php?part=held_resume', {
                method: 'POST',
                body: {
                    device_uuid: deviceUuid,
                    doc_id: docId
                }
            })
            .then(function (res) {
                const data = res.data || {};
                currentDocId = data.doc_id;
                currentClientUuid = data.client_uuid || '';
                invoiceDiscount = data.invoice_discount || 0;

                // إعادة بناء السلة من السطور المسترجعة
                cart = (data.lines || []).map(function (l) {
                    return {
                        item_id: l.item_id,
                        name: l.item_name || l.code,
                        source: 'held',
                        balance: Number(l.balance || 0),
                        stock_known: false,
                        code: l.code,
                        unit_name: l.unit_name || 'قطعة',
                        unit_factor: l.unit_factor || 1,
                        unit_price: Number(l.unit_price || 0),
                        qty: Number(l.qty || 1),
                        line_discount: l.line_discount || 0,
                        codes: [{ code: l.code, unit_name: l.unit_name || 'قطعة', unit_factor: l.unit_factor || 1, price: l.unit_price }]
                    };
                });

                if (data.customer_id) {
                    loadCustomerById(data.customer_id);
                } else {
                    currentCustomer = null;
                    updateCustomerBadge();
                }

                closeModal(modalHeld);
                renderCart();
                saveDraft();
                showAlert('pos', 'تم استرجاع الفاتورة المعلقة #' + docId);
            })
            .catch(function (err) {
                heldAlert.innerHTML = ui.alert('neg', err.message || 'تعذر استرجاع الفاتورة.');
                btn.disabled = false;
            });
        } else if (action === 'cancel-held') {
            const reason = window.prompt('اكتب سبب إلغاء الفاتورة المعلقة:');
            if (!reason || !reason.trim()) return;

            btn.disabled = true;
            window.apiFetch('pos_api.php?part=held_cancel', {
                method: 'POST',
                body: {
                    device_uuid: deviceUuid,
                    doc_id: docId,
                    reason: reason.trim()
                }
            })
            .then(function () {
                openHeldListModal(); // تحديث القائمة
            })
            .catch(function (err) {
                heldAlert.innerHTML = ui.alert('neg', err.message || 'تعذر إلغاء الفاتورة.');
                btn.disabled = false;
            });
        }
    });

    // ==========================================
    // إدارة العميل (اختياري — قرار ١٩)
    // ==========================================
    const modalCust = document.getElementById('posModalCustomer');
    const custSearchInput = document.getElementById('posCustSearchInput');
    const custResultsList = document.getElementById('posCustResultsList');
    const custModalAlert = document.getElementById('posCustModalAlert');
    const newCustName = document.getElementById('posNewCustName');
    const newCustPhone = document.getElementById('posNewCustPhone');

    document.getElementById('posBtnCustomer').addEventListener('click', function () {
        custModalAlert.innerHTML = '';
        custSearchInput.value = '';
        custResultsList.innerHTML = '<div style="padding:12px; color:var(--text-muted); text-align:center;">اكتب للبحث عن عميل</div>';
        newCustName.value = '';
        newCustPhone.value = '';
        openModal(modalCust);
        setTimeout(function () { custSearchInput.focus(); }, 100);
    });

    document.getElementById('posBtnCloseCustModal').addEventListener('click', function () {
        closeModal(modalCust);
    });

    let custDebounce = null;
    custSearchInput.addEventListener('input', function () {
        clearTimeout(custDebounce);
        const q = this.value.trim();
        if (!q) {
            custResultsList.innerHTML = '<div style="padding:12px; color:var(--text-muted); text-align:center;">اكتب للبحث عن عميل</div>';
            return;
        }
        custDebounce = setTimeout(function () {
            window.apiFetch('customers_api.php?part=search&q=' + encodeURIComponent(q))
                .then(function (res) {
                    const customers = (res.data && res.data.customers) ? res.data.customers : [];
                    if (!customers.length) {
                        custResultsList.innerHTML = '<div style="padding:12px; color:var(--text-muted); text-align:center;">مفيش عميل مطابق</div>';
                        return;
                    }
                    custResultsList.innerHTML = customers.map(function (c) {
                        return '<div class="pos-search-item" data-id="' + Number(c.id) + '" data-name="' + ui.esc(c.name) + '" data-phone="' + ui.esc(c.phone || '') + '">' +
                            '<div>' +
                                '<strong>' + ui.esc(c.name) + '</strong>' +
                                '<div style="font-size:var(--fs-xs); color:var(--text-muted);">' + ui.esc(c.phone || 'بدون موبايل') + '</div>' +
                            '</div>' +
                            '<button type="button" class="btn btn-sm btn-outline">اختيار</button>' +
                        '</div>';
                    }).join('');
                })
                .catch(function () {
                    custResultsList.innerHTML = '<div style="padding:12px; color:var(--neg); text-align:center;">تعذر البحث عن العملاء</div>';
                });
        }, 300);
    });

    custResultsList.addEventListener('click', function (e) {
        const item = e.target.closest('.pos-search-item');
        if (!item) return;
        const id = parseInt(item.getAttribute('data-id'), 10);
        const name = item.getAttribute('data-name');
        const phone = item.getAttribute('data-phone');
        setCustomer({ id: id, name: name, phone: phone });
        closeModal(modalCust);
    });

    document.getElementById('posBtnQuickAddCust').addEventListener('click', function () {
        const name = newCustName.value.trim();
        const phone = newCustPhone.value.trim();
        if (!name) {
            custModalAlert.innerHTML = ui.alert('warn', 'اسم العميل مطلوب.');
            return;
        }

        this.disabled = true;
        window.apiFetch('customers_api.php?part=create', {
            method: 'POST',
            body: {
                name: name,
                phone: phone || null,
                kind: 'walkin'
            }
        })
        .then(function (res) {
            const c = res.data ? res.data.customer : null;
            if (c) {
                setCustomer(c);
                closeModal(modalCust);
                showAlert('pos', 'تم تسجيل العميل واختياره: ' + c.name);
            }
        })
        .catch(function (err) {
            custModalAlert.innerHTML = ui.alert('neg', err.message || 'تعذر إضافة العميل.');
        })
        .finally(function () {
            document.getElementById('posBtnQuickAddCust').disabled = false;
        });
    });

    function setCustomer(c) {
        currentCustomer = c;
        updateCustomerBadge();
        saveDraft();
    }

    function updateCustomerBadge() {
        if (currentCustomer) {
            customerNameEl.textContent = currentCustomer.name + (currentCustomer.phone ? ' (' + currentCustomer.phone + ')' : '');
            removeCustomerBtn.style.display = 'inline-flex';
        } else {
            customerNameEl.textContent = 'نقدي';
            removeCustomerBtn.style.display = 'none';
        }
    }

    document.getElementById('posBtnRemoveCustomer').addEventListener('click', function () {
        currentCustomer = null;
        updateCustomerBadge();
        saveDraft();
    });

    function loadCustomerById(id) {
        window.apiFetch('customers_api.php?part=get&id=' + encodeURIComponent(id))
            .then(function (res) {
                if (res.data && res.data.customer) {
                    setCustomer(res.data.customer);
                }
            })
            .catch(function () {});
    }

    // ==========================================
    // فتح الدرج
    // ==========================================
    const modalDrawer = document.getElementById('posModalDrawer');
    const drawerReasonInput = document.getElementById('posDrawerReason');
    const drawerAlert = document.getElementById('posDrawerAlert');
    const btnOpenDrawer = document.getElementById('posBtnOpenDrawer');

    function openDrawerModal() {
        if (!modalDrawer) return;
        drawerAlert.innerHTML = '';
        drawerReasonInput.value = '';
        openModal(modalDrawer);
        setTimeout(function () { drawerReasonInput.focus(); }, 100);
    }

    if (btnOpenDrawer) {
        btnOpenDrawer.addEventListener('click', openDrawerModal);
    }

    document.getElementById('posBtnCloseDrawer').addEventListener('click', function () {
        closeModal(modalDrawer);
    });

    document.getElementById('posBtnConfirmDrawer').addEventListener('click', function () {
        const reason = drawerReasonInput.value.trim();
        if (!reason) {
            drawerAlert.innerHTML = ui.alert('warn', 'سبب فتح الدرج مطلوب.');
            return;
        }

        this.disabled = true;
        window.apiFetch('pos_api.php?part=drawer_open', {
            method: 'POST',
            body: {
                device_uuid: deviceUuid,
                reason: reason
            }
        })
        .then(function (res) {
            closeModal(modalDrawer);
            showAlert('pos', 'تم تسجيل فتح الدرج بنجاح.');
            const data = (res && res.data) || {};
            if (data.mode === 'browser' && data.url) {
                try {
                    printReceiptUrl(data.url);
                } catch (e) {
                    showAlert('warn', 'الدرج اتسجّل بس الإيصال ماطّبعش، ومفيش مكان لإعادة طباعته حالياً.');
                }
            } else if (data.mode === 'queue') {
                showAlert('pos', 'تم إرسال إيصال فتح الدرج للطابعة');
            }
        })
        .catch(function (err) {
            drawerAlert.innerHTML = ui.alert('neg', err.message || 'تعذر فتح الدرج.');
        })
        .finally(function () {
            document.getElementById('posBtnConfirmDrawer').disabled = false;
        });
    });

    drawerReasonInput.addEventListener('keydown', function (e) {
        if (isEnter(e)) {
            e.preventDefault();
            document.getElementById('posBtnConfirmDrawer').click();
        }
    });

    // ==========================================
    // إدارة الأوضاع (بيع · مرتجع · مرتجع عام · استبدال)
    // ==========================================
    var currentMode = 'sale';
    const viewSale = document.getElementById('posViewSale');
    const viewReturn = document.getElementById('posViewReturn');
    const viewReturnGeneral = document.getElementById('posViewReturnGeneral');
    const viewExchange = document.getElementById('posViewExchange');
    const modeTabs = document.getElementById('posModeTabs');

    function switchMode(mode) {
        if (paymentPanelOpen && mode !== 'sale') closePaymentPanel();
        currentMode = mode;
        if (modeTabs) {
            modeTabs.querySelectorAll('.ftab').forEach(function (tab) {
                tab.classList.toggle('is-active', tab.getAttribute('data-mode') === mode);
            });
        }
        if (viewSale) viewSale.style.display = (mode === 'sale') ? 'block' : 'none';
        if (viewReturn) viewReturn.style.display = (mode === 'return') ? 'block' : 'none';
        if (viewReturnGeneral) viewReturnGeneral.style.display = (mode === 'return_general') ? 'block' : 'none';
        if (viewExchange) viewExchange.style.display = (mode === 'exchange') ? 'block' : 'none';

        adjustCartTableHeight();

        if (mode === 'sale') {
            refocusScan();
        } else if (mode === 'return') {
            const rInput = document.getElementById('posReturnSearchInput');
            if (rInput) setTimeout(function () { rInput.focus(); }, 100);
        } else if (mode === 'return_general') {
            const gInput = document.getElementById('posGenReturnReason');
            if (gInput) setTimeout(function () { gInput.focus(); }, 100);
        } else if (mode === 'exchange') {
            const eInput = document.getElementById('posExchReceiptInput');
            if (eInput) setTimeout(function () { eInput.focus(); }, 100);
        }
    }

    if (modeTabs) {
        modeTabs.addEventListener('click', function (e) {
            const tab = e.target.closest('.ftab');
            if (tab) {
                const m = tab.getAttribute('data-mode');
                if (m) switchMode(m);
            }
        });
    }

    // ==========================================
    // منطق المرتجع على فاتورة (Return Ref)
    // ==========================================
    let returnDoc = null;
    let returnLines = [];
    let returnTotals = { gross: 0, line_discount: 0, net: 0 };
    let returnOriginalPayments = {};
    let returnPaymentMethod = 'cash';
    let lateApprovalToken = null;
    let methodApprovalToken = null;

    const returnSearchInput = document.getElementById('posReturnSearchInput');
    const btnReturnSearch = document.getElementById('posBtnReturnSearch');
    const returnDocInfoBox = document.getElementById('posReturnDocInfoBox');
    const returnLayout = document.getElementById('posReturnLayout');
    const returnLinesBody = document.getElementById('posReturnLinesBody');
    const returnNetDisplay = document.getElementById('posReturnNetDisplay');
    const returnGrossDisplay = document.getElementById('posReturnGrossDisplay');
    const returnDiscDisplay = document.getElementById('posReturnDiscDisplay');
    const returnOrigPaymentsSummary = document.getElementById('posReturnOrigPaymentsSummary');
    const returnPaymentRowsContainer = document.getElementById('posReturnPaymentRows');
    const returnOverrideBox = document.getElementById('posReturnOverrideBox');
    const returnOverrideReason = document.getElementById('posReturnOverrideReason');
    const btnConfirmReturn = document.getElementById('posBtnConfirmReturn');

    function searchReturnDoc(val) {
        val = (val || '').trim();
        if (!val) {
            showAlert('warn', 'اكتب رقم الإيصال أو الفاتورة.');
            return;
        }
        btnReturnSearch.disabled = true;
        showAlert('', '');

        window.apiFetch('pos_api.php?part=doc_find&device_uuid=' + encodeURIComponent(deviceUuid) + '&receipt_no=' + encodeURIComponent(val))
            .then(function (res) {
                if (!res.data || !res.data.doc) {
                    showAlert('warn', 'الفاتورة مش موجودة.');
                    return;
                }
                loadReturnDoc(res.data);
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر جلب الفاتورة.');
                if (returnDocInfoBox) returnDocInfoBox.style.display = 'none';
                if (returnLayout) returnLayout.style.display = 'none';
            })
            .finally(function () {
                btnReturnSearch.disabled = false;
            });
    }

    function loadReturnDoc(data) {
        returnDoc = data.doc;
        returnOriginalPayments = data.payments || {};
        returnPaymentMethod = Object.keys(returnOriginalPayments).reduce(function (best, method) {
            const available = Number(returnOriginalPayments[method].available_refund || 0);
            const bestAvailable = best ? Number(returnOriginalPayments[best].available_refund || 0) : -1;
            return available > bestAvailable ? method : best;
        }, '') || 'cash';
        returnLines = (data.lines || []).map(function (l) {
            return Object.assign({}, l, { return_qty: 0, return_net: 0 });
        });
        lateApprovalToken = null;
        methodApprovalToken = null;

        document.getElementById('posReturnDocReceipt').textContent = 'إيصال: ' + returnDoc.receipt_no;
        document.getElementById('posReturnDocDate').textContent = 'تاريخ: ' + returnDoc.completed_at;
        document.getElementById('posReturnDocCashier').textContent = 'كاشير: ' + (returnDoc.cashier_name || '--');
        const custEl = document.getElementById('posReturnDocCustomer');
        if (returnDoc.customer_name) {
            custEl.textContent = 'عميل: ' + returnDoc.customer_name;
            custEl.style.display = 'inline-flex';
        } else {
            custEl.style.display = 'none';
        }

        const lateBadge = document.getElementById('posReturnLateBadge');
        if (returnDoc.is_late) {
            lateBadge.textContent = 'أقدم من ' + returnDoc.pos_return_days + ' يوم (' + returnDoc.days_since + ' يوم) — محتاج اعتماد مشرف';
            lateBadge.style.display = 'inline-flex';
        } else {
            lateBadge.style.display = 'none';
        }

        document.getElementById('posReturnDocNet').textContent = Number(returnDoc.net).toFixed(2);

        const origSummary = [];
        for (const m in data.payments) {
            const p = data.payments[m];
            const mLabel = m === 'cash' ? 'كاش' : (m === 'visa' ? 'فيزا' : 'إنستا باي');
            origSummary.push(mLabel + ': ' + Number(p.original_amount).toFixed(2) + ' (متاح: ' + Number(p.available_refund).toFixed(2) + ')');
        }
        returnOrigPaymentsSummary.textContent = origSummary.join(' · ') || 'لا توجد دفعات مسجلة';

        returnDocInfoBox.style.display = 'block';
        returnLayout.style.display = 'grid';
        renderReturnLines();
        computeReturnTotals();
        adjustCartTableHeight();
    }

    function renderReturnLines() {
        returnLinesBody.innerHTML = returnLines.map(function (l, idx) {
            const isAvail = l.available_qty > 0;
            return '<tr>' +
                '<td><code>' + ui.esc(l.code) + '</code></td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(l.item_name) + '">' + ui.esc(l.item_name) + '</span></td>' +
                '<td>' + ui.esc(l.unit_name) + '</td>' +
                '<td class="num">' + l.qty + '</td>' +
                '<td class="num">' + l.returned_qty + '</td>' +
                '<td class="num"><strong>' + l.available_qty + '</strong></td>' +
                '<td class="num fcell">' +
                    (isAvail ? '<input type="number" class="finput finput-cell num pos-qty-input" data-ret-idx="' + idx + '" min="0" max="' + l.available_qty + '" step="1" value="' + l.return_qty + '">' : '<span style="color:var(--text-muted);">تم رده</span>') +
                '</td>' +
                '<td class="num">' + Number(l.unit_net).toFixed(2) + '</td>' +
                '<td class="num"><strong id="posRetLineNet_' + idx + '">' + Number(l.return_net).toFixed(2) + '</strong></td>' +
            '</tr>';
        }).join('');
    }

    returnLinesBody.addEventListener('input', function (e) {
        const inp = e.target.closest('input[data-ret-idx]');
        if (!inp) return;
        const idx = parseInt(inp.getAttribute('data-ret-idx'), 10);
        let val = parseFloat(inp.value) || 0;
        if (val < 0) val = 0;
        if (val > returnLines[idx].available_qty) {
            val = returnLines[idx].available_qty;
            inp.value = val;
        }
        returnLines[idx].return_qty = val;
        computeReturnTotals();
    });

    function computeReturnTotals() {
        let totalGross = 0;
        let totalNet = 0;

        returnLines.forEach(function (l, idx) {
            const rq = Number(l.return_qty) || 0;
            if (rq <= 0) {
                l.return_net = 0;
            } else if (Math.abs(rq - l.available_qty) < 0.0001 && Math.abs((Number(l.returned_qty) + rq) - Number(l.qty)) < 0.0001) {
                l.return_net = Math.round((Number(l.final_line_net) - Number(l.returned_net)) * 100) / 100;
            } else {
                l.return_net = Math.round(rq * Number(l.unit_net) * 100) / 100;
            }
            const el = document.getElementById('posRetLineNet_' + idx);
            if (el) el.textContent = Number(l.return_net).toFixed(2);

            const gross = Math.round(rq * Number(l.unit_price) * 100) / 100;
            totalGross = Math.round((totalGross + gross) * 100) / 100;
            totalNet = Math.round((totalNet + l.return_net) * 100) / 100;
        });

        returnTotals.gross = totalGross;
        returnTotals.line_discount = Math.round((totalGross - totalNet) * 100) / 100;
        returnTotals.net = totalNet;

        returnNetDisplay.textContent = totalNet.toFixed(2);
        returnGrossDisplay.textContent = totalGross.toFixed(2);
        returnDiscDisplay.textContent = returnTotals.line_discount.toFixed(2);

        updateReturnPayments(totalNet);
        btnConfirmReturn.disabled = (totalNet <= 0);
    }

    function updateReturnPayments(net) {
        if (!returnDoc) return;
        const currentSelect = document.getElementById('posReturnPaymentMethod');
        if (currentSelect) returnPaymentMethod = currentSelect.value;
        if (net <= 0) {
            returnPaymentRowsContainer.innerHTML = '';
            returnOverrideBox.style.display = 'none';
            return;
        }

        const amtInput = document.getElementById('posReturnPaymentAmount');
        if (amtInput && currentSelect) {
            amtInput.value = net.toFixed(2);
            checkReturnMethodOverride();
            return;
        }

        returnPaymentRowsContainer.innerHTML = '<div style="display:flex; gap:var(--sp-2); align-items:center;">' +
            '<select class="fselect" id="posReturnPaymentMethod" style="flex:1;">' +
                '<option value="cash"' + (returnPaymentMethod === 'cash' ? ' selected' : '') + '>كاش</option>' +
                '<option value="visa"' + (returnPaymentMethod === 'visa' ? ' selected' : '') + '>فيزا</option>' +
                '<option value="instapay"' + (returnPaymentMethod === 'instapay' ? ' selected' : '') + '>إنستا باي</option>' +
            '</select>' +
            '<input type="number" class="finput" id="posReturnPaymentAmount" value="' + net.toFixed(2) + '" step="0.01" style="width:110px;" readonly>' +
        '</div>';

        const methodSelect = document.getElementById('posReturnPaymentMethod');
        if (methodSelect) {
            methodSelect.addEventListener('change', function () {
                returnPaymentMethod = this.value;
                checkReturnMethodOverride();
            });
        }
        checkReturnMethodOverride();
    }

    function checkReturnMethodOverride() {
        if (!returnDoc) return;
        const original = returnOriginalPayments[returnPaymentMethod];
        const available = original ? Number(original.available_refund || 0) : 0;
        returnOverrideBox.style.display = returnTotals.net > available + 0.0001 ? 'block' : 'none';
    }

    if (btnReturnSearch) {
        btnReturnSearch.addEventListener('click', function () {
            searchReturnDoc(returnSearchInput.value);
        });
    }
    const btnReturnCamera = document.getElementById('posBtnReturnCamera');
    if (btnReturnCamera) {
        btnReturnCamera.addEventListener('click', function () {
            if (typeof window.openBarcodeScanner === 'function') {
                window.openBarcodeScanner('posReturnSearchInput');
            } else {
                showAlert('warn', 'سكانر الكاميرا مش متاح.');
            }
        });
    }
    if (returnSearchInput) {
        returnSearchInput.addEventListener('keydown', function (e) {
            if (isEnter(e)) {
                e.preventDefault();
                searchReturnDoc(returnSearchInput.value);
            }
        });
    }

    if (btnConfirmReturn) {
        btnConfirmReturn.addEventListener('click', function () {
            if (returnTotals.net <= 0) return;
            const chosenLines = returnLines.filter(function (l) { return Number(l.return_qty) > 0; }).map(function (l) {
                return { ref_line_id: l.id, qty: Number(l.return_qty) };
            });
            if (chosenLines.length === 0) {
                showAlert('warn', 'حدد كمية صنف واحد على الأقل للمرتجع.');
                return;
            }

            const methodSelect = document.getElementById('posReturnPaymentMethod');
            const payMethod = methodSelect ? methodSelect.value : 'cash';
            const payments = [{ method: payMethod, amount: returnTotals.net }];

            function executeReturn() {
                btnConfirmReturn.disabled = true;
                const clientUuid = crypto.randomUUID();
                window.apiFetch('pos_api.php?part=return_ref', {
                    method: 'POST',
                    body: {
                        device_uuid: deviceUuid,
                        client_uuid: clientUuid,
                        doc_id: returnDoc.id,
                        lines: chosenLines,
                        payments: payments,
                        approval_token: lateApprovalToken || methodApprovalToken,
                        late_approval_token: lateApprovalToken,
                        method_approval_token: methodApprovalToken,
                        method_override_reason: returnOverrideReason ? returnOverrideReason.value.trim() : '',
                        customer_id: returnDoc.customer_id
                    }
                })
                .then(function (res) {
                    const data = res.data || {};
                    lastDocId = Number(data.doc_id || 0);
                    const btnPrintLast = document.getElementById('posBtnPrintLast');
                    if (btnPrintLast) btnPrintLast.style.display = 'inline-flex';
                    showAlert('pos', 'تم حفظ المرتجع بنجاح! رقم الإيصال: ' + (data.receipt_no || '') + ' · صافي المرتجع: ' + Number(data.net || 0).toFixed(2) + ' ج.م');
                    returnDocInfoBox.style.display = 'none';
                    returnLayout.style.display = 'none';
                    returnDoc = null;
                    returnLines = [];
                    returnOriginalPayments = {};
                    returnPaymentMethod = 'cash';
                    if (returnSearchInput) returnSearchInput.value = '';
                    try {
                        triggerPrint(lastDocId, false);
                    } catch (e) {
                        showAlert('warn', 'الطباعة فشلت — اطبع تاني');
                    }
                })
                .catch(function (err) {
                    if (err.message && err.message.indexOf('١٤ يوم') !== -1) {
                        promptSupervisorApproval({
                            type: 'return_late',
                            value: returnTotals.net,
                            docId: returnDoc.id,
                            title: 'اعتماد مرتجع متأخر',
                            warning: err.message,
                            onSuccess: function (tok) {
                                lateApprovalToken = tok;
                                executeReturn();
                            }
                        });
                    } else if (err.message && (err.message.indexOf('طريقة الرد') !== -1 || err.http === 403)) {
                        promptSupervisorApproval({
                            type: 'return_method',
                            value: returnTotals.net,
                            docId: returnDoc.id,
                            title: 'اعتماد تغيير طريقة الرد',
                            warning: err.message || 'طريقة الرد تختلف عن الفاتورة الأصلية. يلزم اعتماد مشرف.',
                            onSuccess: function (tok) {
                                methodApprovalToken = tok;
                                executeReturn();
                            }
                        });
                    } else {
                        showAlert('neg', err.message || 'تعذر إتمام المرتجع.');
                    }
                })
                .finally(function () {
                    btnConfirmReturn.disabled = false;
                });
            }

            executeReturn();
        });
    }

    // ==========================================
    // منطق المرتجع العام (Return General)
    // ==========================================
    let genCart = [];
    let genTotals = { net: 0 };
    const genReasonInput = document.getElementById('posGenReturnReason');
    const genCartBody = document.getElementById('posGenCartBody');
    const genNetDisplay = document.getElementById('posGenNetDisplay');
    const genPaymentRows = document.getElementById('posGenPaymentRows');
    const btnConfirmGenReturn = document.getElementById('posBtnConfirmGenReturn');

    // المرتجع العام مالوش خصم سطر في السيرفر (sale_return_general بتحط line_discount = 0)
    // فالسلة دي من غير أعمدة خصم، ومن غير حارس رصيد — المرتجع بيزوّد المخزون (بريف 827k)
    var genStore = {
        key: 'general',
        get cart() { return genCart; },
        set cart(v) { genCart = v; },
        tbody: genCartBody,
        cartEmpty: null,
        discounts: false,
        stockGuard: false,
        codeIdPrefix: 'posGenCodeInput_',
        nameIdPrefix: 'posGenNameInput_',
        onChange: function () {},
        onTotals: function () { updateGenTotals(); }
    };

    // سطر طريقة رد المبلغ — بيترسم مرة واحدة، والكمية (المبلغ) بتتحدّث لوحدها
    function renderGenPaymentRow() {
        if (!genPaymentRows) return;
        if (document.getElementById('posGenMethodSelect')) return;
        genPaymentRows.innerHTML = '<div style="display:flex; gap:var(--sp-2); align-items:center;">' +
            '<select class="fselect" id="posGenMethodSelect" style="flex:1;">' +
                '<option value="cash" selected>كاش</option>' +
                '<option value="visa">فيزا</option>' +
                '<option value="instapay">إنستا باي</option>' +
            '</select>' +
            '<input type="number" class="finput num" id="posGenRefundAmount" value="0.00" step="0.01" style="width:110px;" readonly>' +
        '</div>';
    }

    function updateGenTotals() {
        let total = 0;
        genCart.forEach(function (it) { total = round2(total + computeLineAmounts(it).net); });
        genTotals.net = total;
        if (genNetDisplay) genNetDisplay.textContent = total.toFixed(2);
        renderGenPaymentRow();
        const refundInput = document.getElementById('posGenRefundAmount');
        if (refundInput) refundInput.value = total.toFixed(2);
        if (btnConfirmGenReturn) btnConfirmGenReturn.disabled = (total <= 0);
    }

    function renderGenCart() {
        renderCartView(genStore);
    }

    bindCartEvents(genStore);
    renderGenCart();

    if (btnConfirmGenReturn) {
        btnConfirmGenReturn.addEventListener('click', function () {
            const reason = genReasonInput ? genReasonInput.value.trim() : '';
            if (!reason) {
                showAlert('warn', 'سبب المرتجع العام إجباري.');
                if (genReasonInput) genReasonInput.focus();
                return;
            }
            if (genCart.length === 0 || genTotals.net <= 0) {
                showAlert('warn', 'سلة المرتجع العام فاضية.');
                return;
            }

            const methodEl = document.getElementById('posGenMethodSelect');
            const method = methodEl ? methodEl.value : 'cash';
            const payments = [{ method: method, amount: genTotals.net }];

            this.disabled = true;
            const clientUuid = crypto.randomUUID();
            window.apiFetch('pos_api.php?part=return_general', {
                method: 'POST',
                body: {
                    device_uuid: deviceUuid,
                    client_uuid: clientUuid,
                    reason: reason,
                    lines: genCart.map(function (c) { return { code: c.code, qty: c.qty }; }),
                    payments: payments
                }
            })
            .then(function (res) {
                const data = res.data || {};
                lastDocId = Number(data.doc_id || 0);
                const btnPrintLast = document.getElementById('posBtnPrintLast');
                if (btnPrintLast) btnPrintLast.style.display = 'inline-flex';
                showAlert('pos', 'تم تسجيل المرتجع العام بنجاح! رقم الإيصال: ' + (data.receipt_no || '') + ' · الصافي: ' + Number(data.net || 0).toFixed(2) + ' ج.م');
                genCart = [];
                if (genReasonInput) genReasonInput.value = '';
                renderGenCart();
                try {
                    triggerPrint(lastDocId, false);
                } catch (e) {
                    showAlert('warn', 'الطباعة فشلت — اطبع تاني');
                }
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر إتمام المرتجع العام.');
            })
            .finally(function () {
                btnConfirmGenReturn.disabled = false;
            });
        });
    }

    // ==========================================
    // منطق الاستبدال (Exchange)
    // ==========================================
    let exchReturnDoc = null;
    let exchReturnLines = [];
    let exchSaleCart = [];
    let exchTotals = { returnNet: 0, saleNet: 0, diff: 0 };
    let exchLateToken = null;
    let exchMethodToken = null;

    const exchReceiptInput = document.getElementById('posExchReceiptInput');
    const btnExchSearchDoc = document.getElementById('posBtnExchSearchDoc');
    const exchReturnLinesBody = document.getElementById('posExchReturnLinesBody');
    const exchReturnSubtotalChip = document.getElementById('posExchReturnSubtotalChip');
    const exchDocInfo = document.getElementById('posExchReturnDocInfo');

    const exchSaleBody = document.getElementById('posExchSaleBody');
    const exchSaleSubtotalChip = document.getElementById('posExchSaleSubtotalChip');

    const exchDiffTitle = document.getElementById('posExchDiffTitle');
    const exchDiffDisplay = document.getElementById('posExchDiffDisplay');
    const exchDiffStatus = document.getElementById('posExchDiffStatus');
    const exchSaleNetDisplay = document.getElementById('posExchSaleNetDisplay');
    const exchRetNetDisplay = document.getElementById('posExchRetNetDisplay');
    const exchPaymentsWrap = document.getElementById('posExchPaymentsWrap');
    const exchPaymentLabel = document.getElementById('posExchPaymentLabel');
    const exchPaymentRows = document.getElementById('posExchPaymentRows');
    const btnConfirmExchange = document.getElementById('posBtnConfirmExchange');

    function searchExchDoc(val) {
        val = (val || '').trim();
        if (!val) {
            showAlert('warn', 'اكتب رقم الإيصال للمرتجع.');
            return;
        }
        btnExchSearchDoc.disabled = true;
        window.apiFetch('pos_api.php?part=doc_find&device_uuid=' + encodeURIComponent(deviceUuid) + '&receipt_no=' + encodeURIComponent(val))
            .then(function (res) {
                if (!res.data || !res.data.doc) {
                    showAlert('warn', 'الفاتورة مش موجودة.');
                    return;
                }
                exchReturnDoc = res.data.doc;
                exchReturnLines = (res.data.lines || []).map(function (l) {
                    return Object.assign({}, l, { return_qty: 0, return_net: 0 });
                });
                exchLateToken = null;
                document.getElementById('posExchDocReceiptBadge').textContent = 'إيصال: ' + exchReturnDoc.receipt_no;
                document.getElementById('posExchDocDateBadge').textContent = 'تاريخ: ' + exchReturnDoc.completed_at;
                exchDocInfo.style.display = 'block';
                renderExchReturnLines();
                computeExchangeTotals();
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر جلب الفاتورة.');
            })
            .finally(function () {
                btnExchSearchDoc.disabled = false;
            });
    }

    if (btnExchSearchDoc) {
        btnExchSearchDoc.addEventListener('click', function () {
            searchExchDoc(exchReceiptInput.value);
        });
    }
    // كاميرا جنب رقم إيصال الاستبدال — نفس طريقة المرتجع بالحرف، وكل زرار مربوط بخانته.
    const btnExchCamera = document.getElementById('posBtnExchCamera');
    if (btnExchCamera) {
        btnExchCamera.addEventListener('click', function () {
            if (typeof window.openBarcodeScanner === 'function') {
                window.openBarcodeScanner('posExchReceiptInput');
            } else {
                showAlert('warn', 'سكانر الكاميرا مش متاح.');
            }
        });
    }
    if (exchReceiptInput) {
        exchReceiptInput.addEventListener('keydown', function (e) {
            if (isEnter(e)) {
                e.preventDefault();
                searchExchDoc(exchReceiptInput.value);
            }
        });
    }

    function renderExchReturnLines() {
        if (!exchReturnLinesBody) return;
        exchReturnLinesBody.innerHTML = exchReturnLines.map(function (l, idx) {
            const isAvail = l.available_qty > 0;
            return '<tr>' +
                '<td><code>' + ui.esc(l.code) + '</code></td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(l.item_name) + '">' + ui.esc(l.item_name) + '</span></td>' +
                '<td class="num"><strong>' + l.available_qty + '</strong></td>' +
                '<td class="num fcell">' +
                    (isAvail ? '<input type="number" class="finput finput-cell num pos-qty-input" data-exch-ret-idx="' + idx + '" min="0" max="' + l.available_qty + '" step="1" value="' + l.return_qty + '">' : '<span style="color:var(--text-muted);">تم رده</span>') +
                '</td>' +
                '<td class="num">' + Number(l.unit_net).toFixed(2) + '</td>' +
                '<td class="num"><strong id="posExchRetLineNet_' + idx + '">' + Number(l.return_net).toFixed(2) + '</strong></td>' +
            '</tr>';
        }).join('');
    }

    if (exchReturnLinesBody) {
        exchReturnLinesBody.addEventListener('input', function (e) {
            const inp = e.target.closest('input[data-exch-ret-idx]');
            if (!inp) return;
            const idx = parseInt(inp.getAttribute('data-exch-ret-idx'), 10);
            let val = parseFloat(inp.value) || 0;
            if (val < 0) val = 0;
            if (val > exchReturnLines[idx].available_qty) {
                val = exchReturnLines[idx].available_qty;
                inp.value = val;
            }
            exchReturnLines[idx].return_qty = val;
            computeExchangeTotals();
        });
    }

    var exchStore = {
        key: 'exchange',
        get cart() { return exchSaleCart; },
        set cart(v) { exchSaleCart = v; },
        tbody: exchSaleBody,
        cartEmpty: null,
        discounts: true,
        stockGuard: true,
        codeIdPrefix: 'posExchCodeInput_',
        nameIdPrefix: 'posExchNameInput_',
        onChange: function () {},
        onTotals: function () { computeExchangeTotals(); }
    };

    function renderExchSaleCart() {
        renderCartView(exchStore);
    }

    bindCartEvents(exchStore);
    renderExchSaleCart();

    function computeExchangeTotals() {
        let retNet = 0;
        exchReturnLines.forEach(function (l, idx) {
            const rq = Number(l.return_qty) || 0;
            if (rq <= 0) {
                l.return_net = 0;
            } else if (Math.abs(rq - l.available_qty) < 0.0001 && Math.abs((Number(l.returned_qty) + rq) - Number(l.qty)) < 0.0001) {
                l.return_net = Math.round((Number(l.final_line_net) - Number(l.returned_net)) * 100) / 100;
            } else {
                l.return_net = Math.round(rq * Number(l.unit_net) * 100) / 100;
            }
            const el = document.getElementById('posExchRetLineNet_' + idx);
            if (el) el.textContent = Number(l.return_net).toFixed(2);
            retNet = Math.round((retNet + l.return_net) * 100) / 100;
        });

        let saleNet = 0;
        exchSaleCart.forEach(function (it) {
            computeLineAmounts(it);
            saleNet = Math.round((saleNet + it.net) * 100) / 100;
        });

        exchTotals.returnNet = retNet;
        exchTotals.saleNet = saleNet;
        const diff = Math.round((saleNet - retNet) * 100) / 100;
        exchTotals.diff = diff;

        if (exchReturnSubtotalChip) exchReturnSubtotalChip.textContent = 'صافي المرتجع: ' + retNet.toFixed(2) + ' ج.م';
        if (exchSaleSubtotalChip) exchSaleSubtotalChip.textContent = 'صافي البيع: ' + saleNet.toFixed(2) + ' ج.م';
        if (exchSaleNetDisplay) exchSaleNetDisplay.textContent = saleNet.toFixed(2);
        if (exchRetNetDisplay) exchRetNetDisplay.textContent = retNet.toFixed(2);
        if (exchDiffDisplay) exchDiffDisplay.textContent = Math.abs(diff).toFixed(2);

        if (diff > 0) {
            exchDiffTitle.textContent = 'المتبقي على العميل';
            exchDiffStatus.textContent = 'العميل يدفع الفرق';
            exchDiffStatus.className = 'fchip fchip-warn';
            exchPaymentsWrap.style.display = 'block';
            exchPaymentLabel.textContent = 'طريقة دفع الفرق:';
            renderExchPaymentRows('pay', diff);
        } else if (diff < 0) {
            exchDiffTitle.textContent = 'المسترد للعميل';
            exchDiffStatus.textContent = 'يرجع للعميل الفرق';
            exchDiffStatus.className = 'fchip fchip-pos';
            exchPaymentsWrap.style.display = 'block';
            exchPaymentLabel.textContent = 'طريقة رد الفرق:';
            renderExchPaymentRows('refund', Math.abs(diff));
        } else {
            exchDiffTitle.textContent = 'الفرق';
            exchDiffStatus.textContent = 'مفيش فرق';
            exchDiffStatus.className = 'fchip fchip-mute';
            exchPaymentsWrap.style.display = 'none';
        }

        btnConfirmExchange.disabled = !(retNet > 0 && saleNet > 0);
    }

    function renderExchPaymentRows(kind, amt) {
        if (!exchPaymentRows) return;
        exchPaymentRows.innerHTML = '<div style="display:flex; gap:var(--sp-2); align-items:center;">' +
            '<select class="fselect" id="posExchPaymentMethod" style="flex:1;">' +
                '<option value="cash" selected>كاش</option>' +
                '<option value="visa">فيزا</option>' +
                '<option value="instapay">إنستا باي</option>' +
            '</select>' +
            '<input type="number" class="finput" id="posExchPaymentAmount" value="' + amt.toFixed(2) + '" step="0.01" style="width:110px;" readonly>' +
        '</div>';
    }

    if (btnConfirmExchange) {
        btnConfirmExchange.addEventListener('click', function () {
            if (exchTotals.returnNet <= 0 || exchTotals.saleNet <= 0) return;
            const chosenRetLines = exchReturnLines.filter(function (l) { return Number(l.return_qty) > 0; }).map(function (l) {
                return { ref_line_id: l.id, qty: Number(l.return_qty) };
            });
            if (chosenRetLines.length === 0) {
                showAlert('warn', 'حدد بنود المرتجع للاستبدال.');
                return;
            }
            if (exchSaleCart.length === 0) {
                showAlert('warn', 'سلة البيع للاستبدال فاضية.');
                return;
            }

            const methodEl = document.getElementById('posExchPaymentMethod');
            const method = methodEl ? methodEl.value : 'cash';
            const payments = (exchTotals.diff !== 0) ? [{ method: method, amount: Math.abs(exchTotals.diff) }] : [];

            function executeExchange() {
                btnConfirmExchange.disabled = true;
                const clientUuid = crypto.randomUUID();
                window.apiFetch('pos_api.php?part=exchange', {
                    method: 'POST',
                    body: {
                        device_uuid: deviceUuid,
                        client_uuid: clientUuid,
                        customer_id: exchReturnDoc ? exchReturnDoc.customer_id : null,
                        return: {
                            doc_id: exchReturnDoc.id,
                            lines: chosenRetLines,
                            approval_token: exchLateToken
                        },
                        sale: {
                            lines: exchSaleCart.map(function (c) {
                                computeLineAmounts(c);
                                return { code: c.code, qty: c.qty, line_discount: Number(c.line_discount_amount || 0) };
                            })
                        },
                        payments: payments,
                        method_approval_token: exchMethodToken
                    }
                })
                .then(function (res) {
                    const data = res.data || {};
                    lastDocId = Number(data.sale_doc_id || data.return_doc_id || 0);
                    const btnPrintLast = document.getElementById('posBtnPrintLast');
                    if (btnPrintLast) btnPrintLast.style.display = 'inline-flex';
                    showAlert('pos', 'تم الاستبدال بنجاح! إيصال المرتجع: ' + (data.return_receipt_no || '') + ' · إيصال البيع: ' + (data.sale_receipt_no || ''));
                    exchReturnDoc = null;
                    exchReturnLines = [];
                    exchSaleCart = [];
                    if (exchReceiptInput) exchReceiptInput.value = '';
                    if (exchDocInfo) exchDocInfo.style.display = 'none';
                    renderExchReturnLines();
                    renderExchSaleCart();
                    computeExchangeTotals();
                    try {
                        triggerPrint(lastDocId, false);
                    } catch (e) {
                        showAlert('warn', 'الطباعة فشلت — اطبع تاني');
                    }
                })
                .catch(function (err) {
                    if (err.message && err.message.indexOf('١٤ يوم') !== -1) {
                        promptSupervisorApproval({
                            type: 'return_late',
                            value: exchTotals.returnNet,
                            docId: exchReturnDoc.id,
                            title: 'اعتماد مرتجع متأخر للاستبدال',
                            warning: err.message,
                            onSuccess: function (tok) {
                                exchLateToken = tok;
                                executeExchange();
                            }
                        });
                    } else if (err.message && (err.message.indexOf('طريقة الرد') !== -1 || err.http === 403)) {
                        promptSupervisorApproval({
                            type: 'return_method',
                            value: Math.abs(exchTotals.diff),
                            docId: exchReturnDoc.id,
                            title: 'اعتماد طريقة رد الفرق للاستبدال',
                            warning: err.message,
                            onSuccess: function (tok) {
                                exchMethodToken = tok;
                                executeExchange();
                            }
                        });
                    } else {
                        showAlert('neg', err.message || 'تعذر إتمام الاستبدال.');
                    }
                })
                .finally(function () {
                    btnConfirmExchange.disabled = false;
                });
            }

            executeExchange();
        });
    }

    // ==========================================
    // اختصارات الكيبورد العامة لشاشة نقطة البيع
    // ==========================================
    document.addEventListener('keydown', function (e) {
        // لو المستخدم بيكتب في خانة نص جوه نافذة، نتجاهل بعض الاختصارات
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isTyping = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select';
        const openDlg = document.querySelector('.fg-dlg-bg[style*="display: flex"]');

        if (e.defaultPrevented) return;
        const isGlobalShortcut = ['F1', 'F2', 'F3', 'F4', 'F6', 'F7', 'F8', 'F9', 'F12', 'Insert'].includes(e.key)
            || (e.ctrlKey && ['s', 'n'].includes(e.key.toLowerCase()))
            || (e.ctrlKey && e.key === 'Delete');
        if (openDlg && isGlobalShortcut) {
            e.preventDefault();
            return;
        }

        if (openDlg === modalPayment && paymentViewSuccess && paymentViewSuccess.style.display === 'block' && isEnter(e)) {
            e.preventDefault();
            const btnNew = document.getElementById('posBtnNewSale');
            if (btnNew) btnNew.click();
            return;
        }

        if (e.key === 'F1') {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            switchMode('sale');
        } else if (e.key === 'F6') {
            e.preventDefault();
            if (currentMode !== 'sale') switchMode('sale');
            if (paymentPanelOpen) closePaymentPanel(); else openPaymentModal();
        } else if (e.key === 'F7') {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            switchMode('return');
        } else if (e.key === 'F2') {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            switchMode('sale');
            openItemSearch('', cart.length);
        } else if (e.key === 'F3') {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            openDrawerModal();
        } else if (e.key === 'F4') {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            switchMode('sale');
            invDiscPctInput.focus();
            invDiscPctInput.select();
        } else if (e.key === 'F8') {
            e.preventDefault();
            holdCurrentCart();
        } else if (e.key === 'F9') {
            e.preventDefault();
            openHeldListModal();
        } else if (e.key === 'F12' || (e.ctrlKey && e.key.toLowerCase() === 's')) {
            e.preventDefault();
            if (!paymentPanelOpen) {
                showAlert('warn', 'راجع الدفع الأول (F6)');
            } else if (confirmFinalizeBtn.disabled) {
                paymentModalAlert.innerHTML = ui.alert('warn', 'لازم المتبقي يبقى صفر قبل الحفظ.');
            } else {
                confirmFinalizeBtn.click();
            }
        } else if (e.key === 'Insert' || (e.ctrlKey && e.key.toLowerCase() === 'n')) {
            e.preventDefault();
            if (paymentPanelOpen) closePaymentPanel();
            switchMode('sale');
            if (document.activeElement && typeof document.activeElement.blur === 'function') {
                const active = document.activeElement;
                const field = active.getAttribute && active.getAttribute('data-cart-field');
                if (field === 'qty') {
                    const idx = Number(active.getAttribute('data-idx'));
                    clearTimeout(quantityValidationTimer);
                    enforceQuantityStock(saleStore, active, idx);
                }
                active.blur();
            }
            refocusScan();
        } else if (e.ctrlKey && e.key === 'Delete') {
            e.preventDefault();
            if (paymentPanelOpen || currentMode !== 'sale') return;
            const row = document.activeElement && document.activeElement.closest ? document.activeElement.closest('tr[data-idx]') : null;
            const idx = row ? Number(row.getAttribute('data-idx')) : cart.length - 1;
            if (cart[idx]) removeCartLine(saleStore, idx);
        } else if (e.key === 'Escape') {
            if (openDlg) {
                e.preventDefault();
                closeModal(openDlg);
            } else if (paymentPanelOpen) {
                e.preventDefault();
                closePaymentPanel();
            }
        }
    });

    // تشغيل الصفحة عند التحميل
    initContext();
    renderCart({idx:0, field:'code'});
    adjustCartTableHeight();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
