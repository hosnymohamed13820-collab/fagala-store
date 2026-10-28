<?php
/**
 * pos.php — شاشة نقطة البيع (عرض فقط، الداتا والكتابة من الـAPI).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
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
.pos-cart-table-wrap {
    max-height: calc(100vh - 300px);
    min-height: 240px;
    overflow-y: auto;
}
.pos-item-name {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: inline-block;
    vertical-align: middle;
}
.pos-qty-wrap {
    display: flex;
    align-items: center;
    gap: 4px;
}
.pos-qty-input {
    width: 60px;
    text-align: center;
}
.pos-disc-input {
    width: 65px;
    text-align: center;
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
.pos-shortcuts-bar {
    position: fixed;
    bottom: 0;
    right: 0;
    left: 0;
    z-index: var(--z-sticky);
    background: var(--surface);
    border-top: 1px solid var(--line);
    padding: 6px 16px;
    display: flex;
    gap: var(--sp-3);
    flex-wrap: wrap;
    justify-content: center;
    font-size: var(--fs-xs);
    color: var(--text-muted);
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
.pos-payment-row {
    display: grid;
    grid-template-columns: 120px 1fr 1fr auto;
    gap: var(--sp-2);
    align-items: center;
    margin-bottom: var(--sp-2);
}
@media (max-width: 600px) {
    .pos-payment-row {
        grid-template-columns: 1fr;
    }
}
</style>

<main class="content-container" id="posContainer"
      data-can-sell="<?= (can('pos:sell') || is_real_owner()) ? '1' : '0' ?>"
      data-can-return-ref="<?= (can('pos:return_ref') || is_real_owner()) ? '1' : '0' ?>"
      data-can-return-general="<?= (can('pos:return_general') || is_real_owner()) ? '1' : '0' ?>"
      data-can-drawer="<?= (can('pos:drawer') || is_real_owner()) ? '1' : '0' ?>"
      data-can-held-cancel="<?= (can('pos:held_cancel') || is_real_owner()) ? '1' : '0' ?>"
      data-can-devices="<?= (can('pos:devices') || is_real_owner()) ? '1' : '0' ?>">

    <div class="pos-top-bar">
        <div class="pos-top-info">
            <span id="posBranchBadge" class="fchip fchip-pos">فرع: --</span>
            <span id="posDeviceBadge" class="fchip fchip-mute">جهاز: --</span>
            <span id="posSessionBadge" class="fchip fchip-warn">الوردية: --</span>
            <button type="button" class="btn btn-sm btn-ghost" id="posBtnSwitchDevice" title="تغيير الجهاز">
                <i class="ti ti-device-desktop"></i> تغيير الجهاز
            </button>
        </div>
        <div class="pos-top-actions">
            <button type="button" class="btn btn-sm btn-outline" id="posBtnCustomer">
                <i class="ti ti-users"></i> عميل
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnHeldList">
                <i class="ti ti-clock"></i> المعلّق (F9)
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnHold">
                <i class="ti ti-bookmark"></i> تعليق (F8)
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnSessionInvoices" title="فواتير الوردية">
                <i class="ti ti-file-invoice"></i> فواتير الوردية
            </button>
            <button type="button" class="btn btn-sm btn-outline" id="posBtnPrintLast" style="display:none;" title="طباعة آخر إيصال">
                <i class="ti ti-printer"></i> آخر إيصال
            </button>
            <?php if (can('pos:drawer') || is_real_owner()): ?>
                <button type="button" class="btn btn-sm btn-ghost" id="posBtnOpenDrawer">
                    <i class="ti ti-cash"></i> فتح الدرج
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-success" id="posBtnOpenSession" style="display:none;">
                <i class="ti ti-plus"></i> فتح وردية
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="posBtnCloseSession" style="display:none;">
                <i class="ti ti-lock"></i> تقفيل الوردية
            </button>
            <?php if (can('pos:reports') || is_real_owner()): ?>
                <a class="btn btn-sm btn-outline" href="pos_reports.php"><i class="ti ti-chart-bar"></i> تقارير نقطة البيع</a>
            <?php endif; ?>
        </div>
    </div>

    <div id="posAlert"></div>

    <!-- تابات أوضاع نقطة البيع -->
    <div class="ftabs" id="posModeTabs" style="margin-bottom: var(--sp-2);">
        <button type="button" class="ftab is-active" data-mode="sale" id="posTabSale">
            <i class="ti ti-shopping-cart"></i> بيع (F1)
        </button>
        <button type="button" class="ftab" data-mode="return" id="posTabReturn">
            <i class="ti ti-arrow-back-up"></i> مرتجع (F6)
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

    <!-- وضع البيع (الافتراضي) -->
    <div id="posViewSale">
        <div class="pos-layout" id="posMainLayout">
            <!-- عمود السلة والمسح -->
            <div class="pos-cart-panel">
                <div class="pos-scan-box">
                    <div class="pos-scan-input-wrap">
                        <input type="text" class="finput" id="posScanInput"
                               placeholder="امسح الباركود أو اكتب كود/اسم الصنف (F2)..."
                               autocomplete="off" autofocus>
                        <div id="posSearchResults" class="pos-search-dropdown" style="display:none;"></div>
                    </div>
                    <button type="button" class="btn btn-outline" id="posBtnCamera" title="مسح بالكاميرا">
                        <i class="ti ti-camera"></i>
                    </button>
                </div>

                <div id="posCustomerBar" style="display:none;">
                    <span class="pos-customer-badge">
                        <i class="ti ti-user-check"></i>
                        <strong id="posCustomerName">عميل نقدي</strong>
                        <button type="button" class="btn btn-sm btn-ghost" id="posBtnRemoveCustomer" title="إلغاء العميل">
                            <i class="ti ti-x"></i>
                        </button>
                    </span>
                </div>

                <div class="ftable-wrap pos-cart-table-wrap">
                    <table class="ftable" id="posCartTable" data-xt-off>
                        <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>الوحدة</th>
                                <th class="num">الكمية</th>
                                <th class="num">السعر</th>
                                <th class="num">الخصم</th>
                                <th class="num">الصافي</th>
                                <th class="num" style="color:var(--ink-500);">رصيد</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="posCartBody"></tbody>
                    </table>
                </div>
                <div id="posCartEmpty"></div>
            </div>

            <!-- عمود الإجماليات والدفع -->
            <div class="pos-totals-panel">
                <div class="fcard">
                    <div class="pos-net-box">
                        <div class="pos-net-title">الصافي المطلوب</div>
                        <div class="pos-net-val">
                            <span id="posNetDisplay">0.00</span>
                            <span class="pos-net-currency">ج.م</span>
                        </div>
                    </div>

                    <div class="pos-summary-list">
                        <div class="pos-summary-row">
                            <span class="pos-label">الإجمالي</span>
                            <span class="pos-val" id="posGrossDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">خصم السطور</span>
                            <span class="pos-val" id="posLineDiscDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">خصم الفاتورة</span>
                            <span class="pos-val" id="posInvDiscDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">إجمالي الخصم</span>
                            <span class="pos-val" id="posTotalDiscDisplay">0.00</span>
                        </div>
                        <div class="pos-summary-row">
                            <span class="pos-label">عدد الأصناف / القطع</span>
                            <span class="pos-val"><span id="posLinesCount">0</span> / <span id="posPiecesCount">0</span></span>
                        </div>
                    </div>

                    <div class="fcard-actions" style="flex-direction:column; gap:var(--sp-2); margin-top:var(--sp-3);">
                        <button type="button" class="btn btn-primary" id="posBtnPay" style="width:100%;" disabled>
                            <i class="ti ti-cash"></i> دفع وإنهاء (F10)
                        </button>
                        <div style="display:flex; gap:var(--sp-2); width:100%;">
                            <button type="button" class="btn btn-outline" id="posBtnInvoiceDiscount" style="flex:1; justify-content:center;">
                                <i class="ti ti-discount-2"></i> خصم فاتورة (F4)
                            </button>
                            <button type="button" class="btn btn-ghost" id="posBtnClearCart" title="تفريغ السلة">
                                <i class="ti ti-trash"></i> مسح
                            </button>
                        </div>
                    </div>
                </div>
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
                    <table class="ftable" id="posReturnLinesTable" data-xt-off>
                        <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>الوحدة</th>
                                <th class="num">المباع</th>
                                <th class="num">مردود</th>
                                <th class="num">المتاح</th>
                                <th class="num" style="width:90px;">المرتجع</th>
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

                <div class="pos-scan-box">
                    <div class="pos-scan-input-wrap">
                        <input type="text" class="finput" id="posGenScanInput"
                               placeholder="امسح الباركود أو اكتب كود/اسم الصنف لإضافته للمرتجع..."
                               autocomplete="off">
                        <div id="posGenSearchResults" class="pos-search-dropdown" style="display:none;"></div>
                    </div>
                </div>

                <div class="ftable-wrap pos-cart-table-wrap">
                    <table class="ftable" id="posGenCartTable" data-xt-off>
                        <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الاسم</th>
                                <th>الوحدة</th>
                                <th class="num">الكمية</th>
                                <th class="num">سعر الوحدة</th>
                                <th class="num">الصافي</th>
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
                    <div class="pos-scan-box" style="margin-bottom:var(--sp-2);">
                        <div class="pos-scan-input-wrap">
                            <input type="text" class="finput" id="posExchScanInput"
                                   placeholder="امسح أو ابحث عن صنف لإضافته للبيع الجديد...">
                            <div id="posExchSearchResults" class="pos-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="ftable-wrap" style="max-height:220px; overflow-y:auto;">
                        <table class="ftable" id="posExchSaleTable" data-xt-off>
                            <thead>
                                <tr>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th>الوحدة</th>
                                    <th class="num">الكمية</th>
                                    <th class="num">السعر</th>
                                    <th class="num">الصافي</th>
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
        <span><span class="pos-shortcut-key">F2</span> بحث / مسح</span>
        <span><span class="pos-shortcut-key">F4</span> خصم</span>
        <span><span class="pos-shortcut-key">F6</span> مرتجع</span>
        <span><span class="pos-shortcut-key">F8</span> تعليق</span>
        <span><span class="pos-shortcut-key">F9</span> المعلّق</span>
        <span><span class="pos-shortcut-key">F10</span> دفع</span>
        <span><span class="pos-shortcut-key">Del</span> حذف السطر</span>
        <span><span class="pos-shortcut-key">+</span> / <span class="pos-shortcut-key">-</span> الكمية</span>
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
            <div id="posCloseExpected"></div>
            <div class="falert falert-warn" id="posCloseHeldWarning" style="display:none;"></div>
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

    <!-- نافذة الدفع (F10) -->
    <div class="fg-dlg-bg" id="posModalPayment" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 560px);">
            <div id="posPaymentViewNormal">
                <h3 class="fg-dlg-title"><i class="ti ti-cash"></i> إنهاء ودفع الفاتورة</h3>

                <div class="fkpis">
                    <div class="fkpi">
                        <span>صافي الفاتورة</span>
                        <strong><span id="posPayModalNet">0.00</span> ج.م</strong>
                    </div>
                </div>

                <div id="posPaymentModalAlert"></div>

                <div style="margin-bottom:var(--sp-2);">
                    <div style="font-weight:600; font-size:var(--fs-sm); margin-bottom:6px;">طرق الدفع (أقصى ٣ طرق):</div>
                    <div id="posPaymentRowsContainer"></div>
                    <button type="button" class="btn btn-sm btn-ghost" id="posBtnAddPaymentRow">
                        <i class="ti ti-plus"></i> إضافة طريقة دفع
                    </button>
                </div>

                <div id="posChangeDueBox" class="pos-change-box">
                    الباقي: 0.00 ج.م
                </div>

                <div class="fg-dlg-actions" style="margin-top:var(--sp-3);">
                    <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnConfirmFinalize" disabled>
                        <i class="ti ti-check"></i> إنهاء وحفظ الفاتورة
                    </button>
                    <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnClosePaymentModal">رجوع (Esc)</button>
                </div>
            </div>

            <!-- عرض النجاح بعد الدفع -->
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

    <!-- نافذة خصم الفاتورة (F4) -->
    <div class="fg-dlg-bg" id="posModalInvoiceDiscount" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 420px);">
            <h3 class="fg-dlg-title"><i class="ti ti-discount-2"></i> خصم على إجمالي الفاتورة</h3>
            <div class="fg-dlg-msg">
                <div class="ffield">
                    <label class="flabel" for="posInvDiscInput">قيمة الخصم (اكتب مبلغ مثل 10 أو نسبة مثل 10%):</label>
                    <input type="text" class="finput num" id="posInvDiscInput" placeholder="مثال: 5 أو 10%">
                </div>
            </div>
            <div class="fg-dlg-actions">
                <button type="button" class="fg-dlg-btn fg-dlg-ok" id="posBtnApplyInvDiscount">تطبيق الخصم</button>
                <button type="button" class="fg-dlg-btn fg-dlg-cancel" id="posBtnCloseInvDiscount">إلغاء</button>
            </div>
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
    let cart = []; // عناصر السلة
    let invoiceDiscount = 0;
    let currentCustomer = null;
    let currentDocId = null; // للمعلّق
    let currentClientUuid = '';
    let approvalToken = null;
    let isSubmitting = false;

    // عناصر الصفحة الأساسية
    const alertBox = document.getElementById('posAlert');
    const scanInput = document.getElementById('posScanInput');
    const searchDropdown = document.getElementById('posSearchResults');
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
    const customerBar = document.getElementById('posCustomerBar');
    const customerNameEl = document.getElementById('posCustomerName');

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
            return;
        }
        alertBox.innerHTML = ui.alert(kind, msg);
    }

    function clearAlert() {
        alertBox.innerHTML = '';
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
            if (scanInput && !document.querySelector('.fg-dlg-bg[style*="display: flex"]')) {
                scanInput.value = '';
                scanInput.focus();
            }
        }, 60);
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
    function initContext() {
        clearAlert();
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

        if (context.ready === false) {
            showAlert('warn', 'الفرع لسه على True — البيع مقفول');
            scanInput.disabled = true;
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
            scanInput.disabled = false;
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
        window.apiFetch('pos_devices_api.php?part=list')
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
                           ui.esc(d.name) + ' (' + ui.esc(d.code) + ')</option>';
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
            initContext();
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
    let closeSummary = null;

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
        const exp = closeSummary.expected;
        const totalDiff = counted.cash + counted.visa + counted.instapay - Number(exp.total);
        document.getElementById('posCloseDifferences').innerHTML =
            '<table class="ftable" data-xt-off><thead><tr><th>الطريقة</th><th class="num">المتوقع</th><th class="num">المعدود</th><th class="num">الفرق</th></tr></thead><tbody>' +
            ['cash', 'visa', 'instapay'].map(function (method) {
                const names = { cash: 'كاش', visa: 'فيزا', instapay: 'إنستا باي' };
                const diff = counted[method] - Number(exp[method]);
                return '<tr><td>' + names[method] + '</td><td class="num">' + Number(exp[method]).toFixed(2) + '</td><td class="num">' + counted[method].toFixed(2) + '</td><td class="num">' + diff.toFixed(2) + '</td></tr>';
            }).join('') +
            '<tr><td><strong>الإجمالي</strong></td><td class="num"><strong>' + Number(exp.total).toFixed(2) + '</strong></td><td class="num"><strong>' + (counted.cash + counted.visa + counted.instapay).toFixed(2) + '</strong></td><td class="num"><strong>' + totalDiff.toFixed(2) + '</strong></td></tr>' +
            '</tbody></table>';
    }

    closeSessionBtn.addEventListener('click', function () {
        closeAlert.innerHTML = '';
        window.apiFetch('pos_api.php?part=session_summary&device_uuid=' + encodeURIComponent(deviceUuid))
            .then(function (res) {
                closeSummary = res.data || {};
                const exp = closeSummary.expected || {};
                document.getElementById('posCloseExpected').innerHTML = ui.alert('info',
                    'المتوقع: كاش ' + Number(exp.cash || 0).toFixed(2) +
                    ' · فيزا ' + Number(exp.visa || 0).toFixed(2) +
                    ' · إنستا باي ' + Number(exp.instapay || 0).toFixed(2)) +
                    ui.alert('info', 'الافتتاحي (بيفضل في الدرج): ' +
                        Number((closeSummary.session || {}).opening_cash || 0).toFixed(2)) +
                    ui.alert('info', 'الكاش المطلوب توريده: ' + Number(exp.cash_sales || 0).toFixed(2));
                closeCashBody.innerHTML = (closeSummary.denominations || []).map(function (denom) {
                    return '<tr><td class="num">' + Number(denom).toFixed(2) + '</td>' +
                        '<td class="num"><input class="finput num" data-denom="' + ui.esc(String(denom)) + '" type="number" min="0" step="1" value="0"></td>' +
                        '<td class="num pos-close-subtotal">0.00</td></tr>';
                }).join('');
                closePaymentsBody.innerHTML = '';
                closePaymentRow('visa');
                closePaymentRow('instapay');
                const held = closeSummary.held_docs || [];
                const warning = document.getElementById('posCloseHeldWarning');
                if (held.length) {
                    warning.style.display = 'block';
                    warning.innerHTML = 'فيه ' + held.length + ' فاتورة معلقة مفتوحة في الوردية: ' +
                        held.map(function (doc) { return '#' + ui.esc(String(doc.id)); }).join(' · ') +
                        '. التقفيل مسموح، والفواتير هتفضل معلقة.';
                } else {
                    warning.style.display = 'none';
                    warning.innerHTML = '';
                }
                renderCloseDiffs();
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
            showAlert('pos', 'تم تقفيل الوردية وإرسالها للمراجعة. رقم التقفيل #' + Number(res.data.shift_id) +
                ' · الكاش المطلوب توريده ' + Number(res.data.cash_to_deposit).toFixed(2));
            initContext();
        }).catch(function (err) {
            closeAlert.innerHTML = ui.alert('neg', err.message || 'تعذر تقفيل الوردية.');
        }).finally(function () { btn.disabled = false; });
    });

    // ==========================================
    // حسابات السلة والخصم (sale_compute)
    // ==========================================
    function parseDiscount(disc, base) {
        if (!disc) return 0;
        if (typeof disc === 'number') return Math.min(base, Math.max(0, disc));
        const s = String(disc).trim();
        if (s.endsWith('%')) {
            const pct = parseFloat(s.slice(0, -1)) || 0;
            return Math.min(base, Math.max(0, Math.round(base * pct) / 100));
        }
        const n = parseFloat(s) || 0;
        return Math.min(base, Math.max(0, n));
    }

    function computeTotals() {
        let gross = 0;
        let lineDiscountTotal = 0;
        let piecesCount = 0;

        cart.forEach(function (line) {
            const qty = Number(line.qty) || 0;
            const price = Number(line.unit_price) || 0;
            const factor = Number(line.unit_factor) || 1;
            const lineGross = Math.round(qty * price * 100) / 100;
            const lineDisc = parseDiscount(line.line_discount, lineGross);
            line.gross = lineGross;
            line.line_discount_amount = lineDisc;
            line.net = Math.round((lineGross - lineDisc) * 100) / 100;
            line.pieces = Math.round(qty * factor * 1000) / 1000;

            gross = Math.round((gross + lineGross) * 100) / 100;
            lineDiscountTotal = Math.round((lineDiscountTotal + lineDisc) * 100) / 100;
            piecesCount += line.pieces;
        });

        const afterLine = Math.round((gross - lineDiscountTotal) * 100) / 100;
        const invDiscAmount = parseDiscount(invoiceDiscount, afterLine);
        const net = Math.round((afterLine - invDiscAmount) * 100) / 100;
        const totalDiscount = Math.round((lineDiscountTotal + invDiscAmount) * 100) / 100;
        const discountPct = gross > 0 ? Math.round((totalDiscount / gross) * 100000) / 1000 : 0;

        netDisplay.textContent = net.toFixed(2);
        grossDisplay.textContent = gross.toFixed(2);
        lineDiscDisplay.textContent = lineDiscountTotal.toFixed(2);
        invDiscDisplay.textContent = invDiscAmount.toFixed(2);
        totalDiscDisplay.textContent = totalDiscount.toFixed(2);
        linesCountEl.textContent = String(cart.length);
        piecesCountEl.textContent = String(piecesCount);

        payBtn.disabled = (cart.length === 0 || (context && context.ready === false));

        return {
            gross: gross,
            line_discount: lineDiscountTotal,
            invoice_discount: invDiscAmount,
            net: net,
            discount_pct: discountPct
        };
    }

    // ==========================================
    // رسم السلة والتفاعل
    // ==========================================
    let pendingCartFocus = null;

    function renderCart() {
        const active = document.activeElement;
        const activeRow = active && active.closest ? active.closest('tr[data-line]') : null;
        const savedFocus = pendingCartFocus || (activeRow && active.getAttribute('data-cart-field') ? {
            line: activeRow.getAttribute('data-line'),
            field: active.getAttribute('data-cart-field')
        } : null);
        pendingCartFocus = null;

        if (!cart.length) {
            cartBody.innerHTML = '';
            cartEmpty.innerHTML = ui.empty('السلة فاضية — امسح كود أو ابحث عن صنف');
            computeTotals();
            return;
        }
        cartEmpty.innerHTML = '';

        cartBody.innerHTML = cart.map(function (item, idx) {
            const hasMultipleUnits = item.codes && item.codes.length > 1;
            let unitHtml = '';
            if (hasMultipleUnits) {
                unitHtml = '<select class="fselect fselect-sm" data-idx="' + idx + '" data-action="change-unit" data-cart-field="unit">' +
                    item.codes.map(function (c) {
                        const sel = (c.code === item.code) ? ' selected' : '';
                        return '<option value="' + ui.esc(c.code) + '"' + sel + '>' + ui.esc(c.unit_name) + '</option>';
                    }).join('') +
                    '</select>';
            } else {
                unitHtml = '<span>' + ui.esc(item.unit_name || 'قطعة') + '</span>';
            }

            const bal = Number(item.balance || 0);
            return '<tr data-idx="' + idx + '" data-line="' + idx + '">' +
                '<td class="num">' + ui.esc(item.code) + '</td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(item.name) + '">' + ui.esc(item.name) + '</span></td>' +
                '<td>' + unitHtml + '</td>' +
                '<td class="num">' +
                    '<div class="pos-qty-wrap">' +
                        '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="qty-minus">-</button>' +
                        '<input type="number" min="0.001" step="any" class="finput finput-sm num pos-qty-input" data-idx="' + idx + '" data-action="qty-input" data-cart-field="qty" value="' + Number(item.qty) + '">' +
                        '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="qty-plus">+</button>' +
                    '</div>' +
                '</td>' +
                '<td class="num">' + Number(item.unit_price).toFixed(2) + '</td>' +
                '<td class="num">' +
                    '<input type="text" class="finput finput-sm num pos-disc-input" data-idx="' + idx + '" data-action="disc-input" data-cart-field="discount" value="' + ui.esc(String(item.line_discount || '0')) + '">' +
                '</td>' +
                '<td class="num" style="font-weight:700;">' + Number(item.net || 0).toFixed(2) + '</td>' +
                '<td class="num" style="color:var(--ink-500); font-size:var(--fs-xs);">' + bal + '</td>' +
                '<td>' +
                    '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="remove" title="شيل السطر">' +
                        '<i class="ti ti-trash"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        computeTotals();

        if (savedFocus) {
            const focusEl = cartBody.querySelector(
                'tr[data-line="' + savedFocus.line + '"] [data-cart-field="' + savedFocus.field + '"]'
            );
            if (focusEl) focusEl.focus();
        }
    }

    // Tab بعد تعديل خانة يكمّل في خانة السلة اللي بعدها في نفس السطر.
    cartBody.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !e.target.matches('[data-cart-field]')) return;
        const row = e.target.closest('tr[data-line]');
        const fields = row ? Array.from(row.querySelectorAll('[data-cart-field]')) : [];
        const current = fields.indexOf(e.target);
        const next = fields[current + (e.shiftKey ? -1 : 1)];
        if (row && next) {
            e.preventDefault();
            pendingCartFocus = {
                line: row.getAttribute('data-line'),
                field: next.getAttribute('data-cart-field')
            };
            e.target.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });

    // أحداث جدول السلة (تغيير كمية / وحدة / خصم / حذف)
    cartBody.addEventListener('change', function (e) {
        const action = e.target.getAttribute('data-action');
        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
        if (isNaN(idx) || !cart[idx]) return;
        const item = cart[idx];

        if (action === 'change-unit') {
            const newCode = e.target.value;
            const matched = item.codes.find(function (c) { return c.code === newCode; });
            if (matched) {
                item.code = matched.code;
                item.unit_name = matched.unit_name;
                item.unit_factor = matched.unit_factor;
                item.unit_price = Number(matched.price || 0);
                renderCart();
            }
        } else if (action === 'qty-input') {
            const q = parseFloat(e.target.value) || 1;
            item.qty = Math.max(0.001, q);
            renderCart();
        } else if (action === 'disc-input') {
            item.line_discount = e.target.value.trim() || '0';
            renderCart();
        }
    });

    cartBody.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const action = btn.getAttribute('data-action');
        const idx = parseInt(btn.getAttribute('data-idx'), 10);
        if (isNaN(idx) || !cart[idx]) return;
        const item = cart[idx];

        if (action === 'qty-plus') {
            item.qty = Math.round((Number(item.qty) + 1) * 1000) / 1000;
            renderCart();
        } else if (action === 'qty-minus') {
            if (Number(item.qty) > 1) {
                item.qty = Math.round((Number(item.qty) - 1) * 1000) / 1000;
                renderCart();
            } else {
                removeCartLine(idx);
            }
        } else if (action === 'remove') {
            removeCartLine(idx);
        }
    });

    function removeCartLine(idx) {
        if (!cart[idx]) return;
        const removed = cart[idx];
        cart.splice(idx, 1);
        renderCart();
        refocusScan();

        // حدث إزالة سطر في الخلفية (قرار ١٢)
        if (context && context.session) {
            window.apiFetch('pos_api.php?part=cart_event', {
                method: 'POST',
                body: {
                    device_uuid: deviceUuid,
                    event_type: 'line_removed',
                    item_id: removed.item_id,
                    code: removed.code,
                    name: removed.name,
                    qty: removed.qty,
                    price: removed.unit_price
                }
            }).catch(function () {});
        }
    }

    function clearCart(silent) {
        if (!cart.length) return;
        const count = cart.length;
        const clearedLines = cart.map(function (line) {
            return {
                item_id: line.item_id,
                code: line.code,
                name: line.name,
                qty: line.qty,
                price: line.unit_price
            };
        });
        cart = [];
        invoiceDiscount = 0;
        currentCustomer = null;
        currentDocId = null;
        currentClientUuid = '';
        approvalToken = null;
        updateCustomerBadge();
        renderCart();
        refocusScan();

        if (!silent && context && context.session) {
            window.apiFetch('pos_api.php?part=cart_event', {
                method: 'POST',
                body: {
                    device_uuid: deviceUuid,
                    event_type: 'cart_cleared',
                    lines_count: count,
                    lines: clearedLines
                }
            }).catch(function () {});
        }
    }

    document.getElementById('posBtnClearCart').addEventListener('click', function () {
        if (!cart.length) return;
        fgConfirm('متأكد إنك عايز تفضّي السلة؟', {
            title: 'تفريغ السلة',
            okText: 'تفريغ',
            cancelText: 'رجوع',
            danger: true
        }).then(function (confirmed) {
            if (confirmed) clearCart(false);
        });
    });

    // ==========================================
    // البحث وإضافة صنف (سكانر أو نص)
    // ==========================================
    function addItemToCart(item, selectedCode) {
        const codeInfo = selectedCode || (item.codes && item.codes[0]) || {
            code: '0',
            unit_name: 'قطعة',
            unit_factor: 1,
            price: 0
        };

        // فحص لو الصنف موجود في السلة بنفس الكود والوحدة
        const existing = cart.find(function (line) {
            return line.code === codeInfo.code;
        });

        if (existing) {
            existing.qty = Math.round((Number(existing.qty) + 1) * 1000) / 1000;
        } else {
            cart.push({
                item_id: item.item_id,
                name: item.name,
                source: item.source,
                balance: item.balance,
                code: codeInfo.code,
                unit_name: codeInfo.unit_name || 'قطعة',
                unit_factor: codeInfo.unit_factor || 1,
                unit_price: Number(codeInfo.price || 0),
                qty: 1,
                line_discount: 0,
                codes: item.codes || [codeInfo]
            });
        }

        clearAlert();
        renderCart();
        searchDropdown.style.display = 'none';
        scanInput.value = '';
        refocusScan();
    }

    function handleScanLookup(val) {
        val = val.trim();
        if (!val) return;
        searchDropdown.style.display = 'none';

        if (!deviceUuid) {
            showAlert('warn', 'اختار الجهاز أولاً.');
            promptDeviceSelection();
            return;
        }

        // محاولة مطابقة كود مباشر أولاً
        window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&code=' + encodeURIComponent(val))
            .then(function (res) {
                const items = (res.data && res.data.items) ? res.data.items : [];
                if (items.length === 1) {
                    const matchedCode = items[0].codes.find(function (c) { return c.code === val; }) || items[0].codes[0];
                    addItemToCart(items[0], matchedCode);
                    return;
                }
                // لو مفيش كود مباشر، بحث بالاسم
                handleTextSearch(val);
            })
            .catch(function () {
                handleTextSearch(val);
            });
    }

    function handleTextSearch(val) {
        window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&q=' + encodeURIComponent(val))
            .then(function (res) {
                const items = (res.data && res.data.items) ? res.data.items : [];
                if (!items.length) {
                    showAlert('warn', 'الصنف مش موجود: ' + val);
                    return;
                }
                if (items.length === 1 && items[0].codes && items[0].codes.length === 1) {
                    addItemToCart(items[0], items[0].codes[0]);
                    return;
                }
                // عرض قايمة نتايج لاختيار الصنف
                renderSearchResults(items);
            })
            .catch(function (err) {
                showAlert('neg', err.message || 'تعذر البحث عن الصنف.');
            });
    }

    let searchItems = [];
    let selectedSearchIdx = 0;

    function renderSearchResults(items) {
        searchItems = items;
        selectedSearchIdx = 0;
        searchDropdown.innerHTML = items.map(function (it, idx) {
            const firstCode = (it.codes && it.codes[0]) ? it.codes[0] : {};
            const p = Number(firstCode.price || 0).toFixed(2);
            return '<div class="pos-search-item' + (idx === 0 ? ' is-selected' : '') + '" data-idx="' + idx + '">' +
                '<div>' +
                    '<strong>' + ui.esc(it.name) + '</strong>' +
                    '<div style="font-size:var(--fs-xs); color:var(--text-muted);">' +
                        'كود: ' + ui.esc(firstCode.code || '') + ' · وحدة: ' + ui.esc(firstCode.unit_name || 'قطعة') +
                    '</div>' +
                '</div>' +
                '<div style="text-align:left;">' +
                    '<strong class="num">' + p + ' ج.م</strong>' +
                    '<div style="font-size:var(--fs-xs); color:var(--ink-500);">رصيد: ' + Number(it.balance || 0) + '</div>' +
                '</div>' +
            '</div>';
        }).join('');
        searchDropdown.style.display = 'block';
    }

    searchDropdown.addEventListener('click', function (e) {
        const itemEl = e.target.closest('.pos-search-item');
        if (!itemEl) return;
        const idx = parseInt(itemEl.getAttribute('data-idx'), 10);
        if (searchItems[idx]) {
            addItemToCart(searchItems[idx], searchItems[idx].codes ? searchItems[idx].codes[0] : null);
        }
    });

    scanInput.addEventListener('keydown', function (e) {
        if (isEnter(e)) {
            e.preventDefault();
            if (searchDropdown.style.display === 'block' && searchItems[selectedSearchIdx]) {
                addItemToCart(searchItems[selectedSearchIdx], searchItems[selectedSearchIdx].codes ? searchItems[selectedSearchIdx].codes[0] : null);
            } else {
                handleScanLookup(this.value);
            }
            return;
        }

        if (searchDropdown.style.display === 'block' && searchItems.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedSearchIdx = (selectedSearchIdx + 1) % searchItems.length;
                updateSearchSelection();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedSearchIdx = (selectedSearchIdx - 1 + searchItems.length) % searchItems.length;
                updateSearchSelection();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                searchDropdown.style.display = 'none';
            }
        }
    });

    function updateSearchSelection() {
        const list = searchDropdown.querySelectorAll('.pos-search-item');
        list.forEach(function (el, idx) {
            el.classList.toggle('is-selected', idx === selectedSearchIdx);
            if (idx === selectedSearchIdx) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    // مسح بالكاميرا (openBarcodeScanner)
    document.getElementById('posBtnCamera').addEventListener('click', function () {
        if (typeof window.openBarcodeScanner === 'function') {
            window.openBarcodeScanner('posScanInput', { keepOpen: true });
        } else {
            showAlert('warn', 'مكتبة السكانر مش محملة.');
        }
    });

    // ==========================================
    // خصم الفاتورة (F4)
    // ==========================================
    const modalInvDiscount = document.getElementById('posModalInvoiceDiscount');
    const invDiscInput = document.getElementById('posInvDiscInput');

    function promptInvoiceDiscount() {
        invDiscInput.value = invoiceDiscount ? String(invoiceDiscount) : '';
        openModal(modalInvDiscount);
        setTimeout(function () { invDiscInput.focus(); invDiscInput.select(); }, 100);
    }

    document.getElementById('posBtnInvoiceDiscount').addEventListener('click', promptInvoiceDiscount);
    document.getElementById('posBtnCloseInvDiscount').addEventListener('click', function () {
        closeModal(modalInvDiscount);
    });

    document.getElementById('posBtnApplyInvDiscount').addEventListener('click', function () {
        invoiceDiscount = invDiscInput.value.trim() || 0;
        closeModal(modalInvDiscount);
        computeTotals();
    });

    invDiscInput.addEventListener('keydown', function (e) {
        if (isEnter(e)) {
            e.preventDefault();
            document.getElementById('posBtnApplyInvDiscount').click();
        }
    });

    // ==========================================
    // الدفع والإنهاء (F10)
    // ==========================================
    const modalPayment = document.getElementById('posModalPayment');
    const payModalNet = document.getElementById('posPayModalNet');
    const paymentRowsContainer = document.getElementById('posPaymentRowsContainer');
    const changeDueBox = document.getElementById('posChangeDueBox');
    const confirmFinalizeBtn = document.getElementById('posBtnConfirmFinalize');
    const paymentModalAlert = document.getElementById('posPaymentModalAlert');
    const paymentViewNormal = document.getElementById('posPaymentViewNormal');
    const paymentViewSuccess = document.getElementById('posPaymentViewSuccess');

    let paymentRows = [];

    function openPaymentModal() {
        if (!cart.length) return;
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

        paymentViewNormal.style.display = 'block';
        paymentViewSuccess.style.display = 'none';
        paymentModalAlert.innerHTML = '';
        confirmFinalizeBtn.disabled = false;
        payModalNet.textContent = totals.net.toFixed(2);

        paymentRows = [
            { method: 'cash', amount: totals.net.toFixed(2), reference: '' }
        ];
        renderPaymentRows();
        openModal(modalPayment);

        setTimeout(function () {
            const firstAmt = paymentRowsContainer.querySelector('input[type="number"]');
            if (firstAmt) { firstAmt.focus(); firstAmt.select(); }
        }, 100);
    }

    function renderPaymentRows() {
        const totals = computeTotals();
        let totalPaid = 0;
        let totalCash = 0;

        paymentRowsContainer.innerHTML = paymentRows.map(function (row, idx) {
            const amt = parseFloat(row.amount) || 0;
            totalPaid += amt;
            if (row.method === 'cash') totalCash += amt;

            return '<div class="pos-payment-row" data-idx="' + idx + '">' +
                '<select class="fselect" data-field="method" data-idx="' + idx + '">' +
                    '<option value="cash"' + (row.method === 'cash' ? ' selected' : '') + '>كاش</option>' +
                    '<option value="visa"' + (row.method === 'visa' ? ' selected' : '') + '>فيزا</option>' +
                    '<option value="instapay"' + (row.method === 'instapay' ? ' selected' : '') + '>إنستا باي</option>' +
                '</select>' +
                '<input type="number" step="any" min="0" class="finput num" data-field="amount" data-idx="' + idx + '" value="' + (row.amount || '') + '" placeholder="المبلغ">' +
                '<input type="text" class="finput" data-field="reference" data-idx="' + idx + '" value="' + ui.esc(row.reference || '') + '" placeholder="مرجع اختياري">' +
                (paymentRows.length > 1 ?
                    '<button type="button" class="btn btn-sm btn-ghost" data-field="remove" data-idx="' + idx + '"><i class="ti ti-x"></i></button>' :
                    '<span></span>') +
            '</div>';
        }).join('');

        const change = Math.round((totalPaid - totals.net) * 100) / 100;
        if (totalPaid < totals.net) {
            changeDueBox.className = 'pos-change-box is-due-remain';
            changeDueBox.textContent = 'متبقي مطلوب: ' + (totals.net - totalPaid).toFixed(2) + ' ج.م';
            confirmFinalizeBtn.disabled = true;
        } else {
            changeDueBox.className = 'pos-change-box';
            changeDueBox.textContent = 'الباقي: ' + change.toFixed(2) + ' ج.م';
            confirmFinalizeBtn.disabled = false;
        }

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
            const change = Math.round((totalPaid - totals.net) * 100) / 100;
            if (totalPaid < totals.net) {
                changeDueBox.className = 'pos-change-box is-due-remain';
                changeDueBox.textContent = 'متبقي مطلوب: ' + (totals.net - totalPaid).toFixed(2) + ' ج.م';
                confirmFinalizeBtn.disabled = true;
            } else {
                changeDueBox.className = 'pos-change-box';
                changeDueBox.textContent = 'الباقي: ' + change.toFixed(2) + ' ج.م';
                confirmFinalizeBtn.disabled = false;
            }
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
        closeModal(modalPayment);
    });

    // إرسال الفاتورة والإنهاء
    confirmFinalizeBtn.addEventListener('click', function () {
        if (isSubmitting) return;
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

        if (totalPaid < totals.net) {
            paymentModalAlert.innerHTML = ui.alert('neg', 'المدفوع أقل من الصافي المطلوب.');
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
            paymentViewNormal.style.display = 'none';
            paymentViewSuccess.style.display = 'block';
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
                        balance: 0,
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
    }

    function updateCustomerBadge() {
        if (currentCustomer) {
            customerBar.style.display = 'block';
            customerNameEl.textContent = 'عميل: ' + currentCustomer.name + (currentCustomer.phone ? ' (' + currentCustomer.phone + ')' : '');
        } else {
            customerBar.style.display = 'none';
        }
    }

    document.getElementById('posBtnRemoveCustomer').addEventListener('click', function () {
        currentCustomer = null;
        updateCustomerBadge();
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

    if (btnOpenDrawer) {
        btnOpenDrawer.addEventListener('click', function () {
            drawerAlert.innerHTML = '';
            drawerReasonInput.value = '';
            openModal(modalDrawer);
            setTimeout(function () { drawerReasonInput.focus(); }, 100);
        });
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
        .then(function () {
            closeModal(modalDrawer);
            showAlert('pos', 'تم تسجيل فتح الدرج بنجاح.');
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
    let currentMode = 'sale';
    const viewSale = document.getElementById('posViewSale');
    const viewReturn = document.getElementById('posViewReturn');
    const viewReturnGeneral = document.getElementById('posViewReturnGeneral');
    const viewExchange = document.getElementById('posViewExchange');
    const modeTabs = document.getElementById('posModeTabs');

    function switchMode(mode) {
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
                '<td class="num">' +
                    (isAvail ? '<input type="number" class="finput pos-qty-input" data-ret-idx="' + idx + '" min="0" max="' + l.available_qty + '" step="1" value="' + l.return_qty + '">' : '<span style="color:var(--text-muted);">تم رده</span>') +
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
    const genScanInput = document.getElementById('posGenScanInput');
    const genSearchResults = document.getElementById('posGenSearchResults');
    const genCartBody = document.getElementById('posGenCartBody');
    const genNetDisplay = document.getElementById('posGenNetDisplay');
    const genPaymentRows = document.getElementById('posGenPaymentRows');
    const btnConfirmGenReturn = document.getElementById('posBtnConfirmGenReturn');

    let genSearchTimer = null;
    if (genScanInput) {
        genScanInput.addEventListener('input', function () {
            clearTimeout(genSearchTimer);
            const val = this.value.trim();
            if (!val) {
                genSearchResults.style.display = 'none';
                return;
            }
            genSearchTimer = setTimeout(function () {
                window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&q=' + encodeURIComponent(val))
                    .then(function (res) {
                        const items = res.data && res.data.items ? res.data.items : [];
                        if (items.length === 0) {
                            genSearchResults.innerHTML = '<div style="padding:var(--sp-2); color:var(--text-muted); font-size:var(--fs-sm);">مفيش نتائج</div>';
                            genSearchResults.style.display = 'block';
                            return;
                        }
                        genSearchResults.innerHTML = items.map(function (it, i) {
                            const codeObj = it.codes && it.codes[0] ? it.codes[0] : { code: '', price: 0, unit_name: 'قطعة' };
                            return '<div class="pos-search-item" data-gen-item-idx="' + i + '">' +
                                '<div><strong>' + ui.esc(it.name) + '</strong><br><small style="color:var(--text-muted);">كود: ' + ui.esc(codeObj.code) + '</small></div>' +
                                '<div style="text-align:left; font-weight:600;">' + Number(codeObj.price || 0).toFixed(2) + ' ج.م</div>' +
                            '</div>';
                        }).join('');
                        genSearchResults.style.display = 'block';
                        genSearchResults._items = items;
                    })
                    .catch(function () {});
            }, 250);
        });

        genScanInput.addEventListener('keydown', function (e) {
            if (isEnter(e)) {
                e.preventDefault();
                const val = this.value.trim();
                if (!val) return;
                window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&code=' + encodeURIComponent(val))
                    .then(function (res) {
                        const items = res.data && res.data.items ? res.data.items : [];
                        if (items.length > 0) {
                            addGenCartItem(items[0]);
                            genScanInput.value = '';
                            genSearchResults.style.display = 'none';
                            genScanInput.focus();
                        }
                    })
                    .catch(function () {});
            }
        });
    }

    if (genSearchResults) {
        genSearchResults.addEventListener('click', function (e) {
            const el = e.target.closest('[data-gen-item-idx]');
            if (!el) return;
            const idx = parseInt(el.getAttribute('data-gen-item-idx'), 10);
            const items = genSearchResults._items || [];
            if (items[idx]) {
                addGenCartItem(items[idx]);
                genScanInput.value = '';
                genSearchResults.style.display = 'none';
                genScanInput.focus();
            }
        });
    }

    function addGenCartItem(item) {
        const c = item.codes && item.codes[0] ? item.codes[0] : { code: '', price: 0, unit_name: 'قطعة' };
        const existing = genCart.find(function (x) { return x.code === c.code; });
        if (existing) {
            existing.qty = Math.round((existing.qty + 1) * 1000) / 1000;
        } else {
            genCart.push({
                item_id: item.item_id,
                name: item.name,
                code: c.code,
                unit_name: c.unit_name || 'قطعة',
                qty: 1,
                unit_price: Number(c.price || 0)
            });
        }
        renderGenCart();
    }

    function renderGenCart() {
        if (!genCartBody) return;
        let total = 0;
        genCartBody.innerHTML = genCart.map(function (it, idx) {
            const lineNet = Math.round(it.qty * it.unit_price * 100) / 100;
            total = Math.round((total + lineNet) * 100) / 100;
            return '<tr>' +
                '<td><code>' + ui.esc(it.code) + '</code></td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(it.name) + '">' + ui.esc(it.name) + '</span></td>' +
                '<td>' + ui.esc(it.unit_name) + '</td>' +
                '<td class="num"><input type="number" class="finput pos-qty-input" data-gencart-idx="' + idx + '" min="1" step="1" value="' + it.qty + '"></td>' +
                '<td class="num">' + it.unit_price.toFixed(2) + '</td>' +
                '<td class="num"><strong>' + lineNet.toFixed(2) + '</strong></td>' +
                '<td><button type="button" class="btn btn-sm btn-ghost" data-gen-del="' + idx + '" title="حذف"><i class="ti ti-trash"></i></button></td>' +
            '</tr>';
        }).join('');

        genTotals.net = total;
        if (genNetDisplay) genNetDisplay.textContent = total.toFixed(2);

        if (genPaymentRows) {
            genPaymentRows.innerHTML = '<div style="display:flex; gap:var(--sp-2); align-items:center;">' +
                '<select class="fselect" id="posGenMethodSelect" style="flex:1;">' +
                    '<option value="cash" selected>كاش</option>' +
                    '<option value="visa">فيزا</option>' +
                    '<option value="instapay">إنستا باي</option>' +
                '</select>' +
                '<input type="number" class="finput" value="' + total.toFixed(2) + '" step="0.01" style="width:110px;" readonly>' +
            '</div>';
        }

        if (btnConfirmGenReturn) {
            btnConfirmGenReturn.disabled = (total <= 0);
        }
    }

    if (genCartBody) {
        genCartBody.addEventListener('input', function (e) {
            const inp = e.target.closest('input[data-gencart-idx]');
            if (!inp) return;
            const idx = parseInt(inp.getAttribute('data-gencart-idx'), 10);
            let val = parseFloat(inp.value) || 1;
            if (val <= 0) val = 1;
            genCart[idx].qty = val;
            renderGenCart();
        });

        genCartBody.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-gen-del]');
            if (!btn) return;
            const idx = parseInt(btn.getAttribute('data-gen-del'), 10);
            genCart.splice(idx, 1);
            renderGenCart();
        });
    }

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

    const exchScanInput = document.getElementById('posExchScanInput');
    const exchSearchResults = document.getElementById('posExchSearchResults');
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
                '<td class="num">' +
                    (isAvail ? '<input type="number" class="finput pos-qty-input" data-exch-ret-idx="' + idx + '" min="0" max="' + l.available_qty + '" step="1" value="' + l.return_qty + '">' : '<span style="color:var(--text-muted);">تم رده</span>') +
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

    let exchSearchTimer = null;
    if (exchScanInput) {
        exchScanInput.addEventListener('input', function () {
            clearTimeout(exchSearchTimer);
            const val = this.value.trim();
            if (!val) {
                exchSearchResults.style.display = 'none';
                return;
            }
            exchSearchTimer = setTimeout(function () {
                window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&q=' + encodeURIComponent(val))
                    .then(function (res) {
                        const items = res.data && res.data.items ? res.data.items : [];
                        if (items.length === 0) {
                            exchSearchResults.innerHTML = '<div style="padding:var(--sp-2); color:var(--text-muted); font-size:var(--fs-sm);">مفيش نتائج</div>';
                            exchSearchResults.style.display = 'block';
                            return;
                        }
                        exchSearchResults.innerHTML = items.map(function (it, i) {
                            const codeObj = it.codes && it.codes[0] ? it.codes[0] : { code: '', price: 0, unit_name: 'قطعة' };
                            return '<div class="pos-search-item" data-exch-item-idx="' + i + '">' +
                                '<div><strong>' + ui.esc(it.name) + '</strong><br><small style="color:var(--text-muted);">كود: ' + ui.esc(codeObj.code) + '</small></div>' +
                                '<div style="text-align:left; font-weight:600;">' + Number(codeObj.price || 0).toFixed(2) + ' ج.م</div>' +
                            '</div>';
                        }).join('');
                        exchSearchResults.style.display = 'block';
                        exchSearchResults._items = items;
                    })
                    .catch(function () {});
            }, 250);
        });

        exchScanInput.addEventListener('keydown', function (e) {
            if (isEnter(e)) {
                e.preventDefault();
                const val = this.value.trim();
                if (!val) return;
                window.apiFetch('pos_api.php?part=lookup&device_uuid=' + encodeURIComponent(deviceUuid) + '&code=' + encodeURIComponent(val))
                    .then(function (res) {
                        const items = res.data && res.data.items ? res.data.items : [];
                        if (items.length > 0) {
                            addExchSaleItem(items[0]);
                            exchScanInput.value = '';
                            exchSearchResults.style.display = 'none';
                            exchScanInput.focus();
                        }
                    })
                    .catch(function () {});
            }
        });
    }

    if (exchSearchResults) {
        exchSearchResults.addEventListener('click', function (e) {
            const el = e.target.closest('[data-exch-item-idx]');
            if (!el) return;
            const idx = parseInt(el.getAttribute('data-exch-item-idx'), 10);
            const items = exchSearchResults._items || [];
            if (items[idx]) {
                addExchSaleItem(items[idx]);
                exchScanInput.value = '';
                exchSearchResults.style.display = 'none';
                exchScanInput.focus();
            }
        });
    }

    function addExchSaleItem(item) {
        const c = item.codes && item.codes[0] ? item.codes[0] : { code: '', price: 0, unit_name: 'قطعة' };
        const existing = exchSaleCart.find(function (x) { return x.code === c.code; });
        if (existing) {
            existing.qty = Math.round((existing.qty + 1) * 1000) / 1000;
        } else {
            exchSaleCart.push({
                item_id: item.item_id,
                name: item.name,
                code: c.code,
                unit_name: c.unit_name || 'قطعة',
                qty: 1,
                unit_price: Number(c.price || 0)
            });
        }
        renderExchSaleCart();
        computeExchangeTotals();
    }

    function renderExchSaleCart() {
        if (!exchSaleBody) return;
        let total = 0;
        exchSaleBody.innerHTML = exchSaleCart.map(function (it, idx) {
            const lineNet = Math.round(it.qty * it.unit_price * 100) / 100;
            total = Math.round((total + lineNet) * 100) / 100;
            return '<tr>' +
                '<td><code>' + ui.esc(it.code) + '</code></td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(it.name) + '">' + ui.esc(it.name) + '</span></td>' +
                '<td>' + ui.esc(it.unit_name) + '</td>' +
                '<td class="num"><input type="number" class="finput pos-qty-input" data-exchsale-idx="' + idx + '" min="1" step="1" value="' + it.qty + '"></td>' +
                '<td class="num">' + it.unit_price.toFixed(2) + '</td>' +
                '<td class="num"><strong>' + lineNet.toFixed(2) + '</strong></td>' +
                '<td><button type="button" class="btn btn-sm btn-ghost" data-exch-del="' + idx + '" title="حذف"><i class="ti ti-trash"></i></button></td>' +
            '</tr>';
        }).join('');
    }

    if (exchSaleBody) {
        exchSaleBody.addEventListener('input', function (e) {
            const inp = e.target.closest('input[data-exchsale-idx]');
            if (!inp) return;
            const idx = parseInt(inp.getAttribute('data-exchsale-idx'), 10);
            let val = parseFloat(inp.value) || 1;
            if (val <= 0) val = 1;
            exchSaleCart[idx].qty = val;
            renderExchSaleCart();
            computeExchangeTotals();
        });

        exchSaleBody.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-exch-del]');
            if (!btn) return;
            const idx = parseInt(btn.getAttribute('data-exch-del'), 10);
            exchSaleCart.splice(idx, 1);
            renderExchSaleCart();
            computeExchangeTotals();
        });
    }

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
            saleNet = Math.round((saleNet + (it.qty * it.unit_price)) * 100) / 100;
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
                            lines: exchSaleCart.map(function (c) { return { code: c.code, qty: c.qty, line_discount: 0 }; })
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
    // اختصارات الكيبورد العامة (F1, F2, F4, F6, F8, F9, F10, Esc, Del, +, -)
    // ==========================================
    document.addEventListener('keydown', function (e) {
        // لو المستخدم بيكتب في خانة نص جوه نافذة، نتجاهل بعض الاختصارات
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isTyping = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select';
        const openDlg = document.querySelector('.fg-dlg-bg[style*="display: flex"]');

        if (openDlg && (e.key === 'F8' || e.key === 'F9' || e.key === 'F10')) {
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
            switchMode('sale');
        } else if (e.key === 'F6') {
            e.preventDefault();
            switchMode('return');
        } else if (e.key === 'F2') {
            e.preventDefault();
            refocusScan();
        } else if (e.key === 'F4') {
            e.preventDefault();
            promptInvoiceDiscount();
        } else if (e.key === 'F8') {
            e.preventDefault();
            holdCurrentCart();
        } else if (e.key === 'F9') {
            e.preventDefault();
            openHeldListModal();
        } else if (e.key === 'F10') {
            e.preventDefault();
            openPaymentModal();
        } else if (e.key === 'Escape') {
            if (openDlg) {
                e.preventDefault();
                closeModal(openDlg);
            } else if (searchDropdown.style.display === 'block') {
                e.preventDefault();
                searchDropdown.style.display = 'none';
            }
        } else if (!openDlg && !isTyping) {
            if (e.key === 'Delete' && cart.length > 0) {
                e.preventDefault();
                removeCartLine(cart.length - 1);
            } else if ((e.key === '+' || e.key === '=') && cart.length > 0) {
                e.preventDefault();
                const last = cart[cart.length - 1];
                last.qty = Math.round((Number(last.qty) + 1) * 1000) / 1000;
                renderCart();
            } else if ((e.key === '-' || e.key === '_') && cart.length > 0) {
                e.preventDefault();
                const last = cart[cart.length - 1];
                if (Number(last.qty) > 1) {
                    last.qty = Math.round((Number(last.qty) - 1) * 1000) / 1000;
                    renderCart();
                }
            }
        }
    });

    // تشغيل الصفحة عند التحميل
    initContext();
    renderCart();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
