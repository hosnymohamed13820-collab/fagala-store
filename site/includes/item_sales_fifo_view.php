<?php
// عرض بس؛ الداتا كلها بتيجي من الـAPI.
require_once __DIR__ . '/ui.php';
?>
<section id="fifoRoot" aria-label="أرباح الموردين">
    <div class="ffilters">
        <span id="fifoStatus" role="status">آخر حساب: بنحمّل…</span>
        <?= ui_btn('إعادة الحساب', ['attrs'=>['id'=>'fifoRebuild'],'icon'=>'calculator']) ?>
    </div>
    <nav class="ftabs" aria-label="عروض أرباح الموردين">
        <button type="button" class="ftab is-active" data-fifo-report="supplier_invoices">فواتير مورد</button>
        <button type="button" class="ftab" data-fifo-report="store_profit">أرباح مخزن</button>
        <button type="button" class="ftab" data-fifo-report="suppliers_profit">أرباح الموردين</button>
    </nav>
    <form class="ffilters" id="fifoFilters">
        <?= ui_field('المورد', '<select class="fselect" id="fifoSupplier" name="supplier_id"><option value="">اختار مورد</option></select>', ['attrs'=>['id'=>'fifoSupplierField']]) ?>
        <?= ui_field('المخزن', ui_select('store',['all'=>'كل المخازن'],'all',['id'=>'fifoStore']), ['attrs'=>['id'=>'fifoStoreField','hidden'=>true,'style'=>'display:none']]) ?>
        <?= ui_field('طريقة العرض', ui_select('group',['item'=>'حسب الصنف','category'=>'حسب التصنيف'],'item'), ['attrs'=>['id'=>'fifoGroupField','hidden'=>true,'style'=>'display:none']]) ?>
        <?= ui_field('من', ui_input('from',['type'=>'date','value'=>'2026-06-27','attrs'=>['min'=>'2026-06-27']])) ?>
        <?= ui_field('إلى', ui_input('to',['type'=>'date','value'=>date('Y-m-d')])) ?>
        <?= ui_btn('عرض',['type'=>'submit','icon'=>'filter']) ?>
        <?= ui_btn('تصدير إكسيل',['attrs'=>['id'=>'fifoExport'],'icon'=>'download']) ?>
    </form>
    <div id="fifoMessage" role="status"></div>
    <div id="fifoTotals" class="fkpis"></div>
    <p id="fifoNotes"></p>
    <div id="fifoResults"></div>
    <div class="ffilters" id="fifoPages" hidden style="display:none">
        <?= ui_btn('السابق',['attrs'=>['id'=>'fifoPrev']]) ?>
        <span id="fifoPageLabel"></span>
        <?= ui_btn('التالي',['attrs'=>['id'=>'fifoNext']]) ?>
    </div>
</section>
<script src="assets/item_sales_fifo.js?v=6" defer></script>
