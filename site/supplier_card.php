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
    'print'=>isset($_GET['print']),'code'=>$code,'nextCode'=>sc_next_code(),'card'=>$code!==''?sc_card($code,$prices):null];
require_once __DIR__.'/includes/header.php';
?>
<style>
/* تخطيط بس؛ كل مكونات الصفحة من المكتبة الموحدة. */
#scApp{max-width:1280px;margin-inline:auto;width:100%;box-sizing:border-box}
.sc-bar,.sc-badges,.sc-actions{display:flex;align-items:center;flex-wrap:wrap;gap:var(--sp-2)}.sc-bar{gap:var(--sp-3);margin-bottom:var(--sp-4)}.sc-bar .finput{flex:1;min-width:0;width:auto}.sc-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:var(--sp-4);align-items:start}.sc-title{display:grid;gap:var(--sp-1)}.sc-title .fpage-title{margin:0;font-size:var(--sp-6)}.sc-badges{font-size:var(--fs-sm);color:var(--ink-500)}.sc-code{font-size:var(--fs-xl);font-weight:700;color:var(--accent-text);font-variant-numeric:tabular-nums}
.sc-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:var(--sp-4);margin-top:var(--sp-4)}.sc-layout>div,.sc-side{min-width:0}.sc-side{display:grid;gap:var(--sp-3);align-content:start}.sc-side-title{margin-top:0;font-weight:600;margin-bottom:var(--sp-2);font-size:var(--fs-base);color:var(--ink-500)}.sc-big{font-size:calc(var(--fs-3xl) - 2px);font-weight:800;color:var(--ink-900);font-variant-numeric:tabular-nums}.sc-big small{font-size:var(--fs-md);font-weight:400;color:var(--ink-500)}.sc-due{color:var(--warn)}.sc-accent{color:var(--accent)}.sc-rows{display:grid;gap:calc(var(--sp-2) - 1px);margin-top:var(--sp-3);font-size:var(--fs-base)}.sc-rows>div{display:flex;justify-content:space-between;gap:var(--sp-2);font-variant-numeric:tabular-nums}.sc-rows>div>span:first-child{color:var(--ink-500)}.sc-rows>div>span:last-child{text-align:end;overflow-wrap:anywhere}.sc-side .sc-badges{margin-top:var(--sp-2)}
.sc-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3);margin-block:var(--sp-3)}.sc-fields .sc-wide{grid-column:1/-1}.sc-fields-top{grid-template-columns:minmax(0,160px) minmax(0,180px);margin-top:0;text-align:center}.sc-fields-contact{grid-template-columns:repeat(4,minmax(0,1fr));text-align:center}.sc-fields-location{grid-template-columns:minmax(0,2fr) minmax(0,1fr)}.sc-val{min-width:0;min-height:calc(var(--sp-5) + var(--sp-5));padding:var(--sp-2) var(--sp-3);border:1px solid var(--line-soft);border-radius:var(--r-sm);background:var(--bg);color:var(--ink-900);font-weight:600;box-sizing:border-box;white-space:pre-wrap;overflow-wrap:anywhere}.sc-name-val{font-size:var(--sp-5);font-weight:700;padding:var(--sp-3)}.sc-notes{line-height:1.6;color:var(--ink-700)}.sc-sec{margin-top:var(--sp-4);margin-bottom:var(--sp-2);font-weight:700;color:var(--ink-700);font-size:var(--fs-base)}.sc-panel{margin-top:var(--sp-4)}#scInfo{position:relative}.sc-edit-action{position:absolute;inset-inline-end:0;top:0}.sc-search-dialog{width:min(92vw,900px)}.sc-lines-row{cursor:pointer}
@media(max-width:1000px){.sc-head{grid-template-columns:minmax(0,1fr)}.sc-fields-contact{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.sc-layout{grid-template-columns:minmax(0,1fr)}.sc-side{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.sc-fields,.sc-side{grid-template-columns:minmax(0,1fr)}.sc-edit-action{position:static;margin-bottom:var(--sp-3)}}
@media print{.sc-bar,.sc-head,.sc-side,#scTabs,#scApp>.fpage-head{display:none}.sc-layout{display:block}}
</style>
<script id="supBoot" type="application/json"><?= json_encode($supBoot,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<main class="content-container" id="scApp">
<div class="fpage-head"><h1 class="fpage-title">كارت المورد</h1></div>
<div class="sc-bar"><label for="scCodeInput">كود المورد</label><input class="finput" id="scCodeInput" dir="ltr" autocomplete="off" placeholder="اكتب الكود واضغط Enter" value="<?= e($code) ?>"><button class="btn btn-outline" id="scSearchBtn"><i class="ti ti-search"></i> بحث بالاسم</button><?php if($supBoot['canEdit'] && SC_ALLOW_CREATE): ?><button class="btn btn-outline" id="scNewBtn"><i class="ti ti-plus"></i> مورد جديد</button><?php endif; ?></div>
<div class="falert falert-warn" id="scMissing" hidden><span id="scMissingText"></span><?php if($supBoot['canEdit'] && SC_ALLOW_CREATE): ?><button class="btn btn-primary btn-sm" id="scDefine">سجّل مورد جديد</button><?php endif; ?></div>
<div id="scStatus" role="status" hidden></div>
<?php if($supBoot['canEdit'] && SC_ALLOW_CREATE): ?><form class="fcard" id="scCreate" hidden><div class="fpage-head"><h2 class="fcard-title">تسجيل بيانات مورد جديد</h2><button class="btn btn-ghost" type="button" data-cancel>إلغاء ورجوع</button></div><div id="scCreateFields"></div><div class="fcard-actions"><button class="btn btn-primary" type="submit">حفظ بيانات المورد</button><button class="btn btn-ghost" type="button" data-cancel>إلغاء</button></div></form><?php endif; ?>
<div id="scSearchHome" hidden><div id="scSearchView" hidden><div class="ffield"><label for="scSearchInput">اسم المورد أو كوده</label><input class="finput" id="scSearchInput" autocomplete="off" placeholder="اكتب الاسم أو الكود"></div><div id="scResults"></div><div id="scSearchStatus" role="status" hidden></div></div></div>
<div class="fcard" id="scCard" hidden>
<div class="sc-head"><div class="sc-title"><div class="sc-badges"><strong id="scCode" class="sc-code" dir="ltr"></strong><span class="fchip" id="scActive"></span><span class="fchip" id="scPayment"></span><span class="fchip fchip-mute" id="scClassification">—</span><span id="scItemCount"></span></div><h1 class="fpage-title" id="scName" dir="auto"></h1></div><div class="sc-actions"><a class="btn btn-primary" href="purchase_docs.php?type=purchase" target="_blank" rel="noopener">فاتورة شراء جديدة</a><a class="btn btn-outline" id="scPrint" target="_blank" rel="noopener" hidden>كشف حساب (طباعة)</a></div></div>
<div class="sc-layout"><div><nav class="ftabs" id="scTabs" role="tablist">
<?php foreach(['info'=>'بيانات المورد','purchases'=>'فواتير الشراء','returns'=>'المرتجعات','items'=>'الأصناف المورّدة','statement'=>'كشف الحساب'] as $key=>$label): ?><button class="ftab <?= $key==='info'?'is-active':'' ?>" type="button" role="tab" aria-selected="<?= $key==='info'?'true':'false' ?>" data-tab="<?= $key ?>" data-label="<?= $label ?>"><?= $label ?></button><?php endforeach; ?>
</nav><section class="sc-panel" data-panel="info"><div id="scInfo"></div></section>
<?php foreach(['purchases','returns','items','statement'] as $key): ?><section class="sc-panel" data-panel="<?= $key ?>" hidden><?php if($key!=='items'): ?><form class="ffilters" data-period="<?= $key ?>"><div class="ffield"><label>من</label><input class="finput" type="date" name="from"></div><div class="ffield"><label>لحد</label><input class="finput" type="date" name="to"></div><button class="btn btn-outline" type="submit">عرض</button></form><?php endif; ?><div id="scData-<?= $key ?>"></div></section><?php endforeach; ?>
</div><aside class="sc-side" id="scSidebar"></aside></div></div>
</main>
<script src="assets/supplier_card.js?v=6" defer></script>
<?php require_once __DIR__.'/includes/footer.php'; ?>
