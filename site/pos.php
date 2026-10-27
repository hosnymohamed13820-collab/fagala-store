<?php
/**
 * pos.php — شاشة نقطة البيع (عرض فقط، الداتا والكتابة من الـAPI).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('pos');
if (!can('pos:sell') && !is_real_owner()) {
    http_response_code(403);
    exit('مالكش صلاحية البيع في نقطة البيع.');
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
    color: var(--accent);
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
.pos-payment-net-hero {
    text-align: center;
    padding: var(--sp-2);
    margin-bottom: var(--sp-3);
    background: var(--surface-soft);
    border-radius: var(--r-md);
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
      data-can-drawer="<?= (can('pos:drawer') || is_real_owner()) ? '1' : '0' ?>"
      data-can-held-cancel="<?= (can('pos:held_cancel') || is_real_owner()) ? '1' : '0' ?>"
      data-can-devices="<?= (can('pos:devices') || is_real_owner()) ? '1' : '0' ?>">

    <div class="pos-top-bar">
        <div class="pos-top-info">
            <span id="posBranchBadge" class="fchip fchip-info">فرع: --</span>
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
            <?php if (can('pos:drawer') || is_real_owner()): ?>
                <button type="button" class="btn btn-sm btn-ghost" id="posBtnOpenDrawer">
                    <i class="ti ti-cash"></i> فتح الدرج
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-success" id="posBtnOpenSession" style="display:none;">
                <i class="ti ti-plus"></i> فتح وردية
            </button>
        </div>
    </div>

    <div id="posAlert"></div>

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
                    <button type="button" class="btn btn-sm btn-ghost" id="posBtnRemoveCustomer" title="إلغاء العميل" style="padding:0 4px; min-width:auto;">
                        <i class="ti ti-x"></i>
                    </button>
                </span>
            </div>

            <div class="ftable-wrap pos-cart-table-wrap">
                <table class="ftable" id="posCartTable">
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
                    <button type="button" class="btn btn-primary" id="posBtnPay" style="width:100%; justify-content:center; padding:12px; font-size:var(--fs-lg);" disabled>
                        <i class="ti ti-cash"></i> دفع وإنهاء (F10)
                    </button>
                    <div style="display:flex; gap:var(--sp-2); width:100%;">
                        <button type="button" class="btn btn-outline" id="posBtnInvoiceDiscount" style="flex:1; justify-content:center;">
                            <i class="ti ti-discount-2"></i> خصم فاتورة (F4)
                        </button>
                        <button type="button" class="btn btn-ghost" id="posBtnClearCart" style="color:var(--neg);" title="تفريغ السلة">
                            <i class="ti ti-trash"></i> مسح
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- شريط الاختصارات بالأسفل -->
    <div class="pos-shortcuts-bar">
        <span><span class="pos-shortcut-key">F2</span> بحث / مسح</span>
        <span><span class="pos-shortcut-key">F4</span> خصم فاتورة</span>
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

    <!-- نافذة الدفع (F10) -->
    <div class="fg-dlg-bg" id="posModalPayment" style="display:none;" role="dialog" aria-modal="true">
        <div class="fg-dlg" style="width: min(92vw, 560px);">
            <div id="posPaymentViewNormal">
                <h3 class="fg-dlg-title"><i class="ti ti-cash"></i> إنهاء ودفع الفاتورة</h3>

                <div class="pos-payment-net-hero">
                    <div style="font-size:var(--fs-sm); color:var(--text-muted);">صافي الفاتورة</div>
                    <div style="font-size:var(--fs-3xl); font-weight:700; color:var(--accent);">
                        <span id="posPayModalNet">0.00</span> ج.م
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
                    <button type="button" class="btn btn-outline" disabled title="الطباعة قريباً في بريف ٢٤٤">
                        <i class="ti ti-printer"></i> طباعة (قريباً)
                    </button>
                    <button type="button" class="btn btn-primary" id="posBtnNewSale">
                        <i class="ti ti-plus"></i> فاتورة جديدة (Enter / Esc)
                    </button>
                </div>
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
            <h3 class="fg-dlg-title"><i class="ti ti-lock"></i> اعتماد مشرف للخصم</h3>
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
                scanInput.focus();
                scanInput.select();
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
            showAlert('info', 'الجهاز جاهز لكن مفيش وردية مفتوحة — افتح وردية لبدء البيع.');
        } else {
            sessionBadge.className = 'fchip fchip-pos';
            sessionBadge.textContent = 'وردية مفتوحة #' + context.session.id;
            openSessionBtn.style.display = 'none';
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
    function renderCart() {
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
                unitHtml = '<select class="fselect fselect-sm" data-idx="' + idx + '" data-action="change-unit">' +
                    item.codes.map(function (c) {
                        const sel = (c.code === item.code) ? ' selected' : '';
                        return '<option value="' + ui.esc(c.code) + '"' + sel + '>' + ui.esc(c.unit_name) + '</option>';
                    }).join('') +
                    '</select>';
            } else {
                unitHtml = '<span>' + ui.esc(item.unit_name || 'قطعة') + '</span>';
            }

            const bal = Number(item.balance || 0);
            return '<tr data-idx="' + idx + '">' +
                '<td class="num">' + ui.esc(item.code) + '</td>' +
                '<td><span class="pos-item-name" title="' + ui.esc(item.name) + '">' + ui.esc(item.name) + '</span></td>' +
                '<td>' + unitHtml + '</td>' +
                '<td class="num">' +
                    '<div class="pos-qty-wrap">' +
                        '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="qty-minus" style="padding:0 6px;">-</button>' +
                        '<input type="number" min="0.001" step="any" class="finput finput-sm num pos-qty-input" data-idx="' + idx + '" data-action="qty-input" value="' + Number(item.qty) + '">' +
                        '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="qty-plus" style="padding:0 6px;">+</button>' +
                    '</div>' +
                '</td>' +
                '<td class="num">' + Number(item.unit_price).toFixed(2) + '</td>' +
                '<td class="num">' +
                    '<input type="text" class="finput finput-sm num pos-disc-input" data-idx="' + idx + '" data-action="disc-input" value="' + ui.esc(String(item.line_discount || '0')) + '">' +
                '</td>' +
                '<td class="num" style="font-weight:700;">' + Number(item.net || 0).toFixed(2) + '</td>' +
                '<td class="num" style="color:var(--ink-500); font-size:var(--fs-xs);">' + bal + '</td>' +
                '<td>' +
                    '<button type="button" class="btn btn-sm btn-ghost" data-idx="' + idx + '" data-action="remove" style="color:var(--neg);" title="شيل السطر">' +
                        '<i class="ti ti-trash"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        computeTotals();
    }

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
                    qty: removed.qty,
                    price: removed.unit_price
                }
            }).catch(function () {});
        }
    }

    function clearCart(silent) {
        if (!cart.length) return;
        const count = cart.length;
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
                    lines_count: count
                }
            }).catch(function () {});
        }
    }

    document.getElementById('posBtnClearCart').addEventListener('click', function () {
        if (!cart.length) return;
        clearCart(false);
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
                    '<button type="button" class="btn btn-sm btn-ghost" data-field="remove" data-idx="' + idx + '" style="color:var(--neg);"><i class="ti ti-x"></i></button>' :
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
            // نجاح: عرض شاشة التأكيد
            paymentViewNormal.style.display = 'none';
            paymentViewSuccess.style.display = 'block';
            document.getElementById('posSuccessReceiptNo').textContent = 'رقم الإيصال: ' + (data.receipt_no || '');
            document.getElementById('posSuccessChangeBox').textContent = 'الباقي: ' + Number(data.change_due || 0).toFixed(2) + ' ج.م';

            // تفريغ السلة في الذاكرة بدون حدث حذف
            clearCart(true);
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
    // اعتماد المشرف (سقف الخصم)
    // ==========================================
    const modalSupervisor = document.getElementById('posModalSupervisor');
    const superUser = document.getElementById('posSuperUser');
    const superPass = document.getElementById('posSuperPass');
    const supervisorAlert = document.getElementById('posSupervisorAlert');
    let pendingDiscountPct = 0;

    function promptSupervisorApproval(pct) {
        pendingDiscountPct = pct;
        supervisorAlert.innerHTML = '';
        superUser.value = '';
        superPass.value = '';
        document.getElementById('posSupervisorWarning').textContent =
            'نسبة الخصم (' + pct + '%) أكبر من سقف الكاشير المعتمد. مطلوب اعتماد مشرف أو مالك.';
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
                approval_type: 'discount',
                value: pendingDiscountPct,
                username: u,
                password: p
            }
        })
        .then(function (res) {
            approvalToken = res.data ? res.data.approval_token : null;
            closeModal(modalSupervisor);
            showAlert('pos', 'تم اعتماد الخصم بواسطة: ' + (res.data ? res.data.approved_by : 'المشرف'));
            openPaymentModal();
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
                                '<button type="button" class="btn btn-sm btn-ghost" data-action="cancel-held" data-id="' + Number(d.id) + '" style="color:var(--neg);">' +
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
    // اختصارات الكيبورد العامة (F2, F4, F8, F9, F10, Esc, Del, +, -)
    // ==========================================
    document.addEventListener('keydown', function (e) {
        // لو المستخدم بيكتب في خانة نص جوه نافذة، نتجاهل بعض الاختصارات
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isTyping = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select';
        const openDlg = document.querySelector('.fg-dlg-bg[style*="display: flex"]');

        if (e.key === 'F2') {
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
