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
    'canLabels' => can('labels'),
];
require_once __DIR__ . '/item_master_lib.php';
$source = (string)($_GET['source'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$imBoot['item'] = im_detail($source, $id);
$imBoot['costUnlocked'] = sens_unlocked();
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* تخطيط النموذج بس؛ شكل الخانات والكروت والألوان من المكتبة الموحّدة. */
.im-bar,.im-head,.im-badges{display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap}.im-bar{margin-bottom:var(--sp-4)}.im-bar .finput{flex:1;min-width:140px}.im-head{justify-content:space-between;align-items:start}.im-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:var(--sp-4);margin-top:var(--sp-4)}.im-layout>div{min-width:0}.im-side .fcard{margin-bottom:var(--sp-3)}.im-fields{display:grid;gap:var(--sp-3);margin-top:var(--sp-3)}.im-code-fields{grid-template-columns:140px 260px;justify-content:start;text-align:center}.im-name{max-width:860px;margin-top:var(--sp-3)}.im-name-value{font-size:var(--fs-xl);font-weight:700}.im-small{grid-template-columns:repeat(4,140px);max-width:860px;justify-content:space-between;text-align:center}.im-small .finput,.im-small .fselect,.im-code-fields .finput{text-align:center;text-align-last:center}.im-cats{grid-template-columns:repeat(5,minmax(0,1fr))}.im-rows>div{display:flex;justify-content:space-between;gap:var(--sp-2);margin-top:var(--sp-2)}.im-big{font-size:var(--fs-2xl);font-weight:700;font-variant-numeric:tabular-nums}.im-panel{margin-top:var(--sp-4)}.im-search-dialog{width:min(92vw,900px)}
@media(max-width:1100px){.im-layout{grid-template-columns:minmax(0,1fr)}.im-side{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3)}}@media(max-width:650px){.im-small,.im-cats{grid-template-columns:repeat(2,minmax(0,1fr))}.im-code-fields{grid-template-columns:140px minmax(0,260px)}.im-side{display:block}}
.im-value{padding:9px 12px;border:1px solid var(--line-soft);border-radius:var(--r-md);background:var(--bg);color:var(--ink-900);min-height:40px;box-sizing:border-box}.im-note{white-space:normal;overflow:visible;text-overflow:clip;overflow-wrap:anywhere}.im-badges>[hidden]{display:none}
</style>
<script type="application/json" id="imBoot"><?= json_encode($imBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<main class="content-container" id="imApp">
<div class="fpage-head"><h1 class="fpage-title">كارت الصنف</h1></div>
<div class="im-bar">
    <label for="imLookup">كود الصنف</label><input class="finput" id="imLookup" dir="ltr" autocomplete="off" placeholder="امسح أو اكتب الكود واضغط Enter">
    <button type="button" class="btn btn-outline" id="imNameSearch"><i class="ti ti-search"></i> بحث بالاسم</button>
    <?php if ($imBoot['canEdit']): ?><button type="button" class="btn btn-outline" id="imNewBtn"><i class="ti ti-plus"></i> صنف جديد</button><?php endif; ?>
</div>
<div class="falert falert-warn" id="imMissing" hidden><span id="imMissingText"></span><?php if ($imBoot['canEdit']): ?><button class="btn btn-primary btn-sm" id="imDefine">عرّف الصنف ده</button><?php endif; ?></div>
<div id="imLookupStatus" role="status" hidden></div>
<div id="imSearchHome" hidden></div>
<div id="imSearchView" hidden>
    <h2 class="fcard-title">بحث الأصناف</h2>
    <div class="ffilters">
        <div class="ffield"><label for="imSearch">اسم الصنف</label><input class="finput" id="imSearch" autocomplete="off" placeholder="اسم الصنف — الكلمات بأي ترتيب"></div>
        <button type="button" class="btn btn-primary" id="imSearchBtn">بحث</button>
        <?php if ($imBoot['canClasses']): ?><a class="btn btn-outline" href="item_classes.php">الأقسام والتصنيفات</a><?php endif; ?>
        <?php if (can_module('sale_prices')): ?><a class="btn btn-outline" href="item_prices.php">أسعار البيع</a><?php endif; ?>
    </div>
    <div id="imResults"></div>
    <div id="imPager" class="fcard-actions"></div>
    <div id="imSearchStatus" role="status" hidden></div>
</div>
    <?php if ($imBoot['canEdit']): ?>
    <form id="imCreate" class="fcard" novalidate hidden>
        <div class="fpage-head"><h2 class="fcard-title">تعريف صنف جديد</h2><button type="button" class="btn btn-ghost" data-back>إلغاء</button></div>
        <div class="im-fields im-code-fields">
            <div class="ffield"><label>كود الصنف <span class="fchip fchip-mute">تلقائي</span></label><input class="finput" readonly value="هيتولّد لوحده بعد الحفظ"></div>
            <div class="ffield"><label>الباركود (اختياري)</label><input class="finput" name="barcode" dir="ltr"></div>
        </div>
        <div class="ffield im-name"><label>اسم الصنف</label><input class="finput im-name-value" name="name1" dir="auto" required></div>
        <div class="im-fields im-small">
            <div class="ffield"><label>التكلفة</label><input class="finput" readonly value="—" title="التكلفة بتظهر من آخر شراء"></div>
            <div class="ffield"><label>سعر البيع</label><input class="finput" name="price" type="number" min="0" step="0.01" <?= $imBoot['canPrices'] ? '' : 'disabled' ?>></div>
            <div class="ffield"><label>نوع الصنف</label><select class="fselect" name="item_type"><option value="stock">مخزني</option><option value="service">غير مخزني</option></select></div>
            <div class="ffield"><label>الوحدة</label><input class="finput" name="base_unit" value="قطعة"></div>
        </div>
        <p class="fpage-sub im-note">مخزني = ليه رصيد بيزيد وينقص. غير مخزني (خدمات زي التدبيس) = مالوش رصيد</p>
        <h3 class="fcard-title">التصنيفات</h3><div class="im-fields im-cats">
            <div class="ffield"><label>القسم</label><select class="fselect" name="cat_is1_id" id="imCreateCat"></select></div>
            <div class="ffield"><label>الوصف</label><select class="fselect" name="cat_description_id" id="imCreateDesc"></select></div>
            <div class="ffield"><label>النوع</label><select class="fselect" name="cat_type_id" id="imCreateType"></select></div>
            <div class="ffield"><label>المرحلة</label><select class="fselect" name="cat_stage_id" id="imCreateStage"></select></div>
            <div class="ffield"><label>الشركات</label><select class="fselect" name="cat_company_id" id="imCreateCompany"></select></div>
        </div>
        <div class="fcard-actions"><button type="submit" class="btn btn-primary">حفظ الصنف</button></div>
        <div id="imCreateStatus" role="status" hidden></div>
    </form>
    <?php endif; ?>
<div class="fcard" id="imCard" hidden>
    <div class="im-head">
        <div><div class="im-badges"><strong class="im-big" id="imCode" dir="ltr"></strong><span class="fchip" id="imSource"></span><span class="fchip fchip-mute" id="imCategory"></span><span class="fchip fchip-mute" id="imDescription"></span><span class="fchip fchip-mute" id="imCodeCount"></span></div><h1 class="fpage-title" id="imTitle" dir="auto"></h1></div>
        <div class="fpage-actions"><a class="btn btn-outline" id="imMovement" hidden>حركة الصنف</a><a class="btn btn-outline" id="imLabels" target="_blank" rel="noopener" hidden>باركود</a></div>
    </div>
    <div class="im-layout"><div>
    <div class="ftabs" id="imTabs" role="tablist">
        <button class="ftab is-active" type="button" role="tab" aria-selected="true" data-tab="data">بيانات الصنف</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="codes">أكواد ووحدات الصنف</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="company">بيانات صنف/شركة</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="pricing">التسعير</button>
        <button class="ftab" type="button" role="tab" aria-selected="false" data-tab="suppliers">موردين الصنف</button>
    </div>
    <section class="im-panel" data-panel="data" role="tabpanel"><div id="imData"></div></section>
    <section class="im-panel" data-panel="codes" role="tabpanel" hidden><div id="imCodes"></div></section>
    <section class="im-panel" data-panel="company" role="tabpanel" hidden><div id="imCompany"></div></section>
    <section class="im-panel" data-panel="pricing" role="tabpanel" hidden><div id="imPricing"></div></section>
    <section class="im-panel" data-panel="suppliers" role="tabpanel" hidden><div id="imSuppliers"></div></section>
    </div><aside class="im-side" id="imSidebar"></aside></div>
    <div id="imCardStatus" role="status" hidden></div>
</div>
</main>
<script src="assets/item_master.js?v=839b" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
