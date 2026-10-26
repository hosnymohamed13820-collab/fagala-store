<?php
/** شاشة الأسعار الجماعية — عرض فقط، كل الداتا والكتابة من الـAPI. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('sale_prices');
if (!can('sale_prices:view')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl"><h2>غير مصرّح</h2><p>مالكش صلاحية عرض أسعار البيع.</p></div>');
}
$ippCanApply = can('sale_prices:apply') && !view_all_borrowed('sale_prices:apply');
$page_title = 'أسعار البيع';
$active_module = 'sale_prices';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.ipp-banner{padding:12px 14px;border:1px solid var(--warn);background:var(--warn-soft);border-radius:10px;margin-bottom:14px}
.ipp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.ipp-field{display:flex;flex-direction:column;gap:5px}.ipp-field label{font-size:12px;color:var(--ink-500)}
.ipp-input{padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;max-width:100%}.ipp-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}
.ipp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:14px 0}.ipp-stat{padding:12px;border:1px solid var(--line-soft);border-radius:9px;background:var(--bg)}.ipp-stat span{display:block;font-size:12px;color:var(--ink-500)}.ipp-stat strong{font-size:19px;font-variant-numeric:tabular-nums}
.ipp-wrap{overflow:auto;border:1px solid var(--line);border-radius:10px}.ipp-code{direction:ltr;unicode-bidi:isolate;font-variant-numeric:tabular-nums}.ipp-pager{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:10px}.ipp-status{min-height:22px;margin-top:8px;color:var(--ink-500)}.ipp-status.err{color:var(--neg)}.ipp-status.ok{color:var(--pos)}
.ipp-section-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.ipp-hidden{display:none}.ipp-checkbox{display:flex;align-items:center;gap:7px;min-height:42px}.ipp-checkbox input{width:18px;height:18px}
</style>

<div class="ipp-banner"><strong>قاعدة الحساب:</strong> السعر الأساسي هو سعر الموقع لو موجود، وإلا سعر True. التطبيق بيغيّر سعر الموقع بس ومابيلمسش True ولا أي شاشة بيع تانية.</div>

<div class="card">
    <div class="ipp-section-head"><div class="section-title">معاينة تغيير الأسعار</div><?php if (can_module('item_master')): ?><a class="btn btn-outline" href="item_master.php">كارت الصنف</a><?php endif; ?></div>
    <form id="ippForm">
        <div class="ipp-grid">
            <div class="ipp-field"><label>القسم</label><select class="ipp-input" name="category_id" id="ippCategory"><option value="0">كل الأقسام</option></select></div>
            <div class="ipp-field"><label>آخر مورد اشترينا منه</label><select class="ipp-input" name="supplier" id="ippSupplier"><option value="">كل الموردين</option></select></div>
            <div class="ipp-field"><label>مصدر الصنف</label><select class="ipp-input" name="source"><option value="all">الكل</option><option value="true">True</option><option value="site">الموقع</option></select></div>
            <div class="ipp-field"><label>بحث بالاسم أو الكود</label><input class="ipp-input" name="search" autocomplete="off"></div>
            <div class="ipp-field"><label>الأسعار</label><label class="ipp-checkbox"><input type="checkbox" name="zero_only" value="1"> سعره صفر بس</label></div>
            <div class="ipp-field"><label>نوع العملية</label><select class="ipp-input" name="operation_type"><option value="percent">نسبة</option><option value="amount">مبلغ</option></select></div>
            <div class="ipp-field"><label>الاتجاه</label><select class="ipp-input" name="direction"><option value="increase">زيادة</option><option value="decrease">نقص</option></select></div>
            <div class="ipp-field"><label>القيمة</label><input class="ipp-input" name="value" type="number" min="0.0001" step="0.0001" required></div>
            <div class="ipp-field"><label>التقريب</label><select class="ipp-input" name="rounding"><option value="none">من غير (قرشين)</option><option value="0.25">أقرب ربع جنيه</option><option value="0.5">أقرب نص جنيه</option><option value="1">أقرب جنيه</option></select></div>
        </div>
        <div class="ipp-actions"><button class="btn btn-primary" type="submit" id="ippPreviewBtn">معاينة</button></div>
    </form>
    <div id="ippStatus" class="ipp-status"></div>
</div>

<div class="card ipp-hidden" id="ippPreviewCard">
    <div class="ipp-section-head"><div class="section-title">نتيجة المعاينة</div><div class="ipp-actions"><a class="btn btn-outline" id="ippExport">تنزيل إكسيل</a><?php if ($ippCanApply): ?><button class="btn btn-success" type="button" id="ippApply">تطبيق نفس المعاينة</button><?php endif; ?></div></div>
    <div class="ipp-summary" id="ippSummary"></div>
    <div class="ipp-wrap"><table class="ftable" data-xt-off><thead><tr><th>الكود</th><th>الصنف</th><th>المصدر</th><th>القسم</th><th>آخر مورد</th><th>الأساس</th><th class="num">True</th><th class="num">القديم</th><th class="num">الجديد</th><th class="num">الفرق</th><th>النتيجة</th></tr></thead><tbody id="ippRows"></tbody></table></div>
    <div class="ipp-pager" id="ippPager"></div><div class="ipp-status" id="ippApplyStatus"></div>
</div>

<div class="card">
    <div class="section-title">الدفعات السابقة</div>
    <div class="ipp-wrap"><table class="ftable" data-xt-off><thead><tr><th>الدفعة</th><th>العملية</th><th class="num">القيمة</th><th>التقريب</th><th class="num">السطور</th><th class="num">اتطبق</th><th class="num">اتخطى</th><th>بواسطة</th><th>التاريخ</th><th></th></tr></thead><tbody id="ippBatches"></tbody></table></div>
    <div class="ipp-pager" id="ippBatchPager"></div><div class="ipp-status" id="ippBatchStatus"></div>
</div>

<div class="card ipp-hidden" id="ippBatchCard">
    <div class="section-title" id="ippBatchTitle">تفاصيل الدفعة</div>
    <div class="ipp-wrap"><table class="ftable" data-xt-off><thead><tr><th>الكود</th><th class="num">القديم</th><th class="num">المقترح</th><th class="num">True وقتها</th><th>النتيجة</th><th>سبب التخطي</th></tr></thead><tbody id="ippBatchRows"></tbody></table></div>
    <div class="ipp-pager" id="ippBatchLinePager"></div>
</div>

<script>
(function(){
    'use strict';
    const state={token:'',previewPage:1,batchId:0};
    const $=id=>document.getElementById(id);
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const money=v=>(v===null||v===undefined||v==='')?'—':Number(v).toFixed(2);
    function status(id,msg,type=''){const el=$(id);el.textContent=msg;el.className='ipp-status '+type;}
    function pager(id,page,pages,kind){$(id).innerHTML=pages>1?'<button class="btn btn-outline" data-kind="'+kind+'" data-page="'+Math.max(1,page-1)+'" '+(page<=1?'disabled':'')+'>السابق</button><span>'+page+' / '+pages+'</span><button class="btn btn-outline" data-kind="'+kind+'" data-page="'+Math.min(pages,page+1)+'" '+(page>=pages?'disabled':'')+'>التالي</button>':'';}
    async function meta(){const r=await apiFetch('item_prices_api.php?part=meta');$('ippCategory').insertAdjacentHTML('beforeend',r.data.categories.map(x=>'<option value="'+Number(x.is1_id)+'">'+esc(x.name)+'</option>').join(''));$('ippSupplier').insertAdjacentHTML('beforeend',r.data.suppliers.map(x=>'<option value="'+esc(x)+'">'+esc(x)+'</option>').join(''));}
    function formBody(){const f=new FormData($('ippForm'));return {category_id:f.get('category_id'),supplier:f.get('supplier'),source:f.get('source'),search:f.get('search'),zero_only:f.get('zero_only')?1:0,operation_type:f.get('operation_type'),direction:f.get('direction'),value:f.get('value'),rounding:f.get('rounding')};}
    async function makePreview(ev){ev.preventDefault();const b=$('ippPreviewBtn');b.disabled=true;status('ippStatus','جاري تجهيز المعاينة…');try{const r=await apiFetch('item_prices_api.php?part=preview',{method:'POST',body:formBody()});renderPreview(r.data);status('ippStatus','المعاينة جاهزة وماغيّرتش أي سعر.','ok');}catch(e){status('ippStatus',e.message,'err');}finally{b.disabled=false;}}
    function renderPreview(d){state.token=d.token;state.previewPage=d.page;$('ippPreviewCard').classList.remove('ipp-hidden');$('ippExport').href='item_prices_api.php?part=export&token='+encodeURIComponent(d.token);const s=d.summary;$('ippSummary').innerHTML=[['عدد الأصناف',s.count],['جاهز',s.ready],['متخطّي',s.skipped],['إجمالي القديم',money(s.old_total)],['إجمالي الجديد',money(s.new_total)],['الفرق',money(s.diff_total)]].map(x=>'<div class="ipp-stat"><span>'+x[0]+'</span><strong>'+x[1]+'</strong></div>').join('');$('ippRows').innerHTML=d.rows.map(r=>'<tr><td class="ipp-code">'+esc(r.code)+'</td><td title="'+esc(r.item_name)+'">'+esc(r.item_name)+'</td><td>'+esc(r.item_source==='true'?'True':'الموقع')+'</td><td>'+esc(r.category_name||'—')+'</td><td>'+esc(r.supplier_name||'—')+'</td><td>'+esc(r.base_source==='site'?'الموقع':(r.base_source==='true'?'True':'صفر'))+'</td><td class="num">'+money(r.true_price)+'</td><td class="num">'+money(r.old_price)+'</td><td class="num">'+money(r.proposed_price)+'</td><td class="num">'+money(r.difference)+'</td><td><span class="fchip '+(r.result==='ready'?'fchip-pos':'fchip-warn')+'">'+esc(r.skip_reason||'جاهز')+'</span></td></tr>').join('');const apply=$('ippApply');if(apply)apply.disabled=Number(s.ready)===0;pager('ippPager',d.page,d.pages,'preview');}
    async function previewPage(page){try{const r=await apiFetch('item_prices_api.php?part=preview&token='+encodeURIComponent(state.token)+'&page='+page);renderPreview(r.data);}catch(e){status('ippApplyStatus',e.message,'err');}}
    async function applyPreview(){if(!state.token||!confirm('تطبيق نفس الأسعار اللي ظهرت في المعاينة؟'))return;const b=$('ippApply');b.disabled=true;status('ippApplyStatus','جاري تطبيق الدفعة…');try{const r=await apiFetch('item_prices_api.php?part=apply',{method:'POST',body:{token:state.token}});status('ippApplyStatus','الدفعة #'+r.data.id+' اتطبقت: '+Number(r.data.applied_count||0)+'، واتخطى '+Number(r.data.skipped_count||0)+'.','ok');state.token='';$('ippExport').removeAttribute('href');await batches(1);await batchDetail(Number(r.data.id),1);}catch(e){status('ippApplyStatus',e.message,'err');b.disabled=false;}}
    async function batches(page){status('ippBatchStatus','جاري تحميل الدفعات…');try{const r=await apiFetch('item_prices_api.php?part=batches&page='+page);$('ippBatches').innerHTML=r.data.rows.map(x=>'<tr><td>#'+Number(x.id)+'</td><td>'+(x.direction==='increase'?'زيادة ':'نقص ')+(x.operation_type==='percent'?'نسبة':'مبلغ')+'</td><td class="num">'+Number(x.operation_value)+'</td><td>'+esc(x.rounding_rule==='none'?'من غير':x.rounding_rule)+'</td><td class="num">'+Number(x.line_count)+'</td><td class="num">'+Number(x.applied_count||0)+'</td><td class="num">'+Number(x.skipped_count||0)+'</td><td>'+esc(x.created_by_name||'')+'</td><td>'+esc(x.created_at)+'</td><td><button class="btn btn-outline" data-batch="'+Number(x.id)+'">فتح</button></td></tr>').join('');pager('ippBatchPager',r.data.page,r.data.pages,'batches');status('ippBatchStatus',r.data.total?'':'مفيش دفعات');}catch(e){status('ippBatchStatus',e.message,'err');}}
    async function batchDetail(id,page){state.batchId=id;try{const r=await apiFetch('item_prices_api.php?part=batch&id='+id+'&page='+page);$('ippBatchCard').classList.remove('ipp-hidden');$('ippBatchTitle').textContent='تفاصيل الدفعة #'+id;$('ippBatchRows').innerHTML=r.data.rows.map(x=>'<tr><td class="ipp-code">'+esc(x.code)+'</td><td class="num">'+money(x.old_price)+'</td><td class="num">'+money(x.proposed_price)+'</td><td class="num">'+money(x.true_price_at_preview)+'</td><td><span class="fchip '+(x.result==='applied'?'fchip-pos':'fchip-warn')+'">'+(x.result==='applied'?'اتطبق':'اتخطى')+'</span></td><td>'+esc(x.skip_reason||'')+'</td></tr>').join('');pager('ippBatchLinePager',r.data.page,r.data.pages,'batch_lines');$('ippBatchCard').scrollIntoView({behavior:'smooth',block:'start'});}catch(e){status('ippBatchStatus',e.message,'err');}}
    document.addEventListener('click',e=>{const p=e.target.closest('[data-kind][data-page]');if(p){const n=Number(p.dataset.page);if(p.dataset.kind==='preview')previewPage(n);else if(p.dataset.kind==='batches')batches(n);else batchDetail(state.batchId,n);return;}const b=e.target.closest('[data-batch]');if(b)batchDetail(Number(b.dataset.batch),1);});
    $('ippForm').addEventListener('submit',makePreview);const apply=$('ippApply');if(apply)apply.addEventListener('click',applyPreview);
    // 🔴 التحميل الأول بعد DOMContentLoaded: `apiFetch` في assets/app.js بيتحمّل
    //    في الفوتر بعد البلوك ده (بريف ٢٣٥).
    document.addEventListener('DOMContentLoaded', () => {
        meta().catch(e=>status('ippStatus',e.message,'err'));batches(1);
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
