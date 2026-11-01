<?php
/** كارت الصنف — صفحة عرض فقط، كل الداتا والكتابة من item_master_api.php. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('item_master');
if (!can('item_master:view')) {
    http_response_code(403);
    die('مالكش صلاحية عرض كارت الصنف.');
}

$page_title = 'كارت الصنف';
$active_module = 'item_master';
$imBoot = [
    'canEdit' => can('item_master:edit'),
    'canPrices' => can('item_master:prices'),
    'canClasses' => can('item_master:classes'),
    'canMovement' => can('reports:item_movement'),
];
require_once __DIR__ . '/includes/header.php';
?>
<main class="content-container" id="imApp" data-boot='<?= htmlspecialchars(json_encode($imBoot, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
<div class="fpage-head"><h1 class="fpage-title">كارت الصنف</h1></div>
<div class="falert falert-warn">كل بيانات True هنا للعرض من المرآة المحلية بس. الموقع مش بيكتب في True نهائي.</div>
<div class="fcard" id="imSearchView">
    <h2 class="fcard-title">بحث الأصناف</h2>
    <div class="ffilters">
        <div class="ffield"><label for="imSearch">الكود أو اسم الصنف</label><input class="finput" id="imSearch" autocomplete="off" placeholder="باركود أو اسم الصنف — الكلمات بأي ترتيب"></div>
        <button type="button" class="btn btn-primary" id="imSearchBtn">بحث</button>
        <?php if ($imBoot['canClasses']): ?><a class="btn btn-outline" href="item_classes.php">الأقسام والتصنيفات</a><?php endif; ?>
        <?php if (can_module('sale_prices')): ?><a class="btn btn-outline" href="item_prices.php">أسعار البيع</a><?php endif; ?>
        <?php if ($imBoot['canEdit']): ?><button type="button" class="btn btn-outline" id="imNewBtn">تعريف صنف جديد</button><?php endif; ?>
    </div>
    <div id="imResults"></div>
    <div id="imPager" class="fcard-actions"></div>
    <div id="imSearchStatus" role="status" hidden></div>
</div>
    <?php if ($imBoot['canEdit']): ?>
    <form id="imCreate" class="fcard" novalidate hidden>
        <div class="fpage-head"><h2 class="fcard-title">تعريف صنف جديد</h2><button type="button" class="btn btn-outline" data-back>رجوع للبحث</button></div><div class="ftabs" id="imCreateTabs" role="tablist"><button type="button" class="ftab is-active" role="tab" aria-selected="true" data-create-tab="data">بيانات الصنف</button><button type="button" class="ftab" role="tab" aria-selected="false" data-create-tab="codes">أكواد ووحدات الصنف</button></div>
        <section data-create-panel="data"><div class="fgrid fgrid-4">
            <div class="ffield"><label>الاسم 1</label><input class="finput" name="name1" required></div>
            <div class="ffield"><label>القسم</label><select class="fselect" name="cat_is1_id" id="imCreateCat"><option value="">من غير قسم</option></select></div>
            <div class="ffield"><label>الوصف</label><select class="fselect" name="cat_description_id" id="imCreateDesc"></select></div>
            <div class="ffield"><label>النوع</label><select class="fselect" name="cat_type_id" id="imCreateType"></select></div>
            <div class="ffield"><label>المرحلة</label><select class="fselect" name="cat_stage_id" id="imCreateStage"></select></div>
            <div class="ffield"><label>الشركات</label><select class="fselect" name="cat_company_id" id="imCreateCompany"></select></div>
            <div class="ffield"><label>تصنيف سعر البيع</label><select class="fselect" name="sale_price_class"><option value="code">كود</option><option value="company">شركة</option></select></div>
            <div class="ffield"><label>نوع الصنف</label><input class="finput" name="item_type" value="stock"></div>
            <div class="ffield"><label>الوحدة الأساسية</label><input class="finput" name="base_unit" value="قطعة"></div>
        </div>
        </section>
        <div id="imCreateCodes" data-create-panel="codes" hidden></div>
        <div class="fcard-actions">
            <button type="button" class="btn btn-outline" id="imCreateAdd"><i class="ti ti-plus"></i> كود فرعي</button>
            <button type="submit" class="btn btn-primary">إنشاء الصنف</button>
        </div>
        <div id="imCreateStatus" role="status" hidden></div>
    </form>
    <?php endif; ?>
<div class="fcard" id="imCard" hidden>
    <div class="fpage-head">
        <div><strong id="imCode" dir="ltr"></strong> <strong id="imTitle"></strong> <span class="fchip" id="imSource"></span></div>
        <div class="fpage-actions"><a class="btn btn-outline" id="imMovement" hidden>حركة الصنف</a><button type="button" class="btn btn-outline" data-back>رجوع للبحث</button></div>
    </div>
    <div class="ftabs" id="imTabs" role="tablist">
        <button class="ftab is-active" type="button" role="tab" aria-selected="true" data-tab="data">بيانات الصنف</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="codes">أكواد ووحدات الصنف</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="company">بيانات صنف/شركة</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="pricing">التسعير</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="suppliers">موردين الصنف</button>
    </div>
    <section data-panel="data" role="tabpanel"><div id="imData"></div></section>
    <section data-panel="codes" role="tabpanel" hidden><div id="imCodes"></div></section>
    <section data-panel="company" role="tabpanel" hidden><div id="imCompany"></div></section>
    <section data-panel="pricing" role="tabpanel" hidden><div id="imPricing"></div></section>
    <section data-panel="suppliers" role="tabpanel" hidden><div id="imSuppliers"></div></section>
    <div id="imCardStatus" role="status" hidden></div>
</div>
</main>
<script src="assets/item_master.js?v=838" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
