<?php
/** عرض كارت المورد؛ كل القراية والكتابة في المكتبة والـAPI. */
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_module_access('supplier_card');
if (!is_owner() && !can('supplier_card:view')) {http_response_code(403);die('مالكش صلاحية عرض كارت المورد');}
require_once __DIR__.'/supplier_card_lib.php';
sens_page();
$page_title='كارت المورد';$active_module='supplier_card';
$prices=can('supplier_card:prices') && sens_unlocked();
$code=trim((string)($_GET['code'] ?? ''));
$supBoot=['canEdit'=>can('supplier_card:edit') && !view_all_borrowed('supplier_card:edit'),'canPrices'=>$prices,'hasPrices'=>can('supplier_card:prices'),
    'code'=>$code,'nextCode'=>sc_next_code(),'card'=>$code!==''?sc_card($code,$prices):null];
require_once __DIR__.'/includes/header.php';
?>
<style>
/* تخطيط بس؛ كل مكونات الصفحة من المكتبة الموحدة. */
.sc-bar,.sc-head,.sc-badges{display:flex;align-items:center;flex-wrap:wrap;gap:var(--sp-3)}.sc-bar{margin-bottom:var(--sp-4)}.sc-bar .finput{max-width:260px}.sc-head{justify-content:space-between;align-items:start}.sc-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:var(--sp-4);margin-top:var(--sp-4)}.sc-layout>div{min-width:0}.sc-side{display:grid;gap:var(--sp-3);align-content:start}.sc-fields{display:grid;grid-template-columns:repeat(2,minmax(0,300px));gap:var(--sp-3);margin-block:var(--sp-3);max-width:860px}.sc-fields .sc-wide{grid-column:1/-1}.sc-panel{margin-top:var(--sp-4)}.sc-search-dialog{width:min(92vw,900px)}.sc-big{font-size:var(--fs-2xl);font-weight:700}.sc-read{white-space:pre-wrap;overflow-wrap:anywhere}.sc-lines-row{cursor:pointer}
@media(max-width:1100px){.sc-layout{grid-template-columns:minmax(0,1fr)}.sc-side{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.sc-fields,.sc-side{grid-template-columns:minmax(0,1fr)}.sc-bar .finput{max-width:100%}}
</style>
<script id="supBoot" type="application/json"><?= json_encode($supBoot,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<main class="content-container" id="scApp">
<div class="fpage-head"><h1 class="fpage-title">كارت المورد</h1></div>
<div class="sc-bar"><label for="scCodeInput">كود المورد</label><input class="finput" id="scCodeInput" dir="ltr" autocomplete="off" placeholder="اكتب الكود واضغط Enter" value="<?= e($code) ?>"><button class="btn btn-outline" id="scSearchBtn"><i class="ti ti-search"></i> بحث بالاسم</button><?php if($supBoot['canEdit']): ?><button class="btn btn-outline" id="scNewBtn"><i class="ti ti-plus"></i> مورد جديد</button><?php endif; ?></div>
<div class="falert falert-warn" id="scMissing" hidden><span id="scMissingText"></span><?php if($supBoot['canEdit']): ?><button class="btn btn-primary btn-sm" id="scDefine">سجّل مورد جديد</button><?php endif; ?></div>
<div id="scStatus" role="status" hidden></div>
<?php if($supBoot['canEdit']): ?><form class="fcard" id="scCreate" hidden><div class="fpage-head"><h2 class="fcard-title">تسجيل بيانات مورد جديد</h2><button class="btn btn-ghost" type="button" data-cancel>إلغاء ورجوع</button></div><div id="scCreateFields"></div><div class="fcard-actions"><button class="btn btn-primary" type="submit">حفظ بيانات المورد</button><button class="btn btn-ghost" type="button" data-cancel>إلغاء</button></div></form><?php endif; ?>
<div id="scSearchHome" hidden><div id="scSearchView" hidden><div class="ffield"><label for="scSearchInput">اسم المورد أو كوده</label><input class="finput" id="scSearchInput" autocomplete="off" placeholder="اكتب الاسم أو الكود"></div><div id="scResults"></div><div id="scSearchStatus" role="status" hidden></div></div></div>
<div class="fcard" id="scCard" hidden>
<div class="sc-head"><div><div class="sc-badges"><strong id="scCode" class="sc-big" dir="ltr"></strong><span class="fchip" id="scActive"></span><span class="fchip fchip-mute" id="scPayment"></span><span id="scItemCount"></span></div><h1 class="fpage-title" id="scName" dir="auto"></h1></div><div class="fpage-actions"><a class="btn btn-primary" href="purchase_docs.php?type=purchase" target="_blank" rel="noopener">فاتورة شراء جديدة</a><a class="btn btn-outline" id="scPrint" target="_blank" rel="noopener" hidden>كشف حساب (طباعة)</a></div></div>
<div class="sc-layout"><div><nav class="ftabs" id="scTabs" role="tablist">
<?php foreach(['info'=>'بيانات المورد','purchases'=>'فواتير الشراء','returns'=>'المرتجعات','items'=>'الأصناف المورّدة','statement'=>'كشف الحساب'] as $key=>$label): ?><button class="ftab <?= $key==='info'?'is-active':'' ?>" type="button" role="tab" aria-selected="<?= $key==='info'?'true':'false' ?>" data-tab="<?= $key ?>" data-label="<?= $label ?>"><?= $label ?></button><?php endforeach; ?>
</nav><section class="sc-panel" data-panel="info"><div id="scInfo"></div></section>
<?php foreach(['purchases','returns','items','statement'] as $key): ?><section class="sc-panel" data-panel="<?= $key ?>" hidden><?php if($key!=='items'): ?><form class="ffilters" data-period="<?= $key ?>"><div class="ffield"><label>من</label><input class="finput" type="date" name="from"></div><div class="ffield"><label>لحد</label><input class="finput" type="date" name="to"></div><button class="btn btn-outline" type="submit">عرض</button></form><?php endif; ?><div id="scData-<?= $key ?>"></div></section><?php endforeach; ?>
</div><aside class="sc-side" id="scSidebar"></aside></div></div>
</main>
<script src="assets/supplier_card.js?v=2" defer></script>
<?php require_once __DIR__.'/includes/footer.php'; ?>
