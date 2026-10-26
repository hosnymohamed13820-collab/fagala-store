<?php
/** كارت الصنف — صفحة عرض فقط، كل الداتا والكتابة من item_master_api.php. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('item_master');
if (!can('item_master:view')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl"><h2>غير مصرّح</h2><p>مالكش صلاحية عرض كارت الصنف.</p></div>');
}

$page_title = 'كارت الصنف';
$active_module = 'item_master';
$imBoot = [
    'canEdit' => can('item_master:edit'),
    'canPrices' => can('item_master:prices'),
    'canMovement' => can('reports:item_movement'),
];
require_once __DIR__ . '/includes/header.php';
?>
<style>
.im-banner{padding:12px 14px;border:1px solid var(--warn);background:var(--warn-soft);border-radius:10px;margin-bottom:14px}
.im-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.im-tools input{flex:1;min-width:220px}
.im-input{padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;max-width:100%}
.im-results{margin-top:10px;border:1px solid var(--line);border-radius:10px;overflow:hidden}.im-result{display:flex;gap:10px;align-items:center;padding:11px 13px;border-bottom:1px solid var(--line-soft);cursor:pointer}.im-result:last-child{border-bottom:0}.im-result:hover{background:var(--accent-soft)}
.im-result-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.im-code{direction:ltr;unicode-bidi:isolate;color:var(--ink-500);font-variant-numeric:tabular-nums}
.im-pager{display:flex;justify-content:center;gap:8px;align-items:center;margin-top:10px}.im-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0 10px}
.im-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:8px}.im-tab{border:1px solid var(--line);background:var(--surface);color:var(--text);padding:9px 12px;border-radius:8px;cursor:pointer;font:inherit}.im-tab.active{background:var(--accent);color:var(--fill-strong-fg);border-color:var(--accent)}
.im-panel{display:none;padding-top:14px}.im-panel.active{display:block}.im-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.im-field{display:flex;flex-direction:column;gap:5px}.im-field label{font-size:12px;color:var(--ink-500)}.im-value{min-height:40px;padding:10px 12px;border:1px solid var(--line-soft);border-radius:8px;background:var(--bg)}
.im-table-wrap{max-width:100%;overflow:auto;border:1px solid var(--line);border-radius:10px}.im-status{min-height:22px;margin-top:8px;color:var(--ink-500)}.im-status.err{color:var(--neg)}.im-status.ok{color:var(--pos)}
.im-price-diff{color:var(--warn);font-weight:700}.im-muted{color:var(--ink-400)}.im-check{width:18px;height:18px}.im-create{margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
.im-create-codes{display:grid;gap:8px}.im-create-row{display:grid;grid-template-columns:2fr 2fr 1fr 1fr 1fr;gap:8px}.im-note{font-size:12px;color:var(--ink-500)}
@media(max-width:700px){.im-create-row{grid-template-columns:1fr 1fr}.im-create-row .wide{grid-column:1/-1}.im-tab{flex:1 1 45%}.im-field{min-width:0}.im-table-wrap{font-size:12px}}
</style>

<div class="im-banner">كل بيانات True هنا للعرض من المرآة المحلية بس. الموقع مش بيكتب في True نهائي.</div>

<div class="card">
    <div class="section-title"><i class="ti ti-search"></i> بحث الأصناف</div>
    <div class="im-tools">
        <input class="im-input" id="imSearch" autocomplete="off" placeholder="باركود أو اسم الصنف — الكلمات بأي ترتيب">
        <button type="button" class="btn btn-primary" id="imSearchBtn">بحث</button>
        <?php if ($imBoot['canEdit']): ?><button type="button" class="btn btn-outline" id="imNewBtn"><i class="ti ti-plus"></i> تعريف صنف جديد</button><?php endif; ?>
    </div>
    <div id="imResults"></div>
    <div id="imPager" class="im-pager"></div>
    <div id="imSearchStatus" class="im-status"></div>

    <?php if ($imBoot['canEdit']): ?>
    <form id="imCreate" class="im-create" hidden>
        <div class="section-title">تعريف صنف جديد</div>
        <div class="im-grid">
            <div class="im-field"><label>الاسم 1</label><input class="im-input" name="name1" required></div>
            <div class="im-field"><label>القسم</label><select class="im-input" name="cat_is1_id" id="imCreateCat"><option value="">من غير قسم</option></select></div>
            <div class="im-field"><label>تصنيف سعر البيع</label><select class="im-input" name="sale_price_class"><option value="code">كود</option><option value="company">شركة</option></select></div>
            <div class="im-field"><label>نوع الصنف</label><input class="im-input" name="item_type" value="stock"></div>
            <div class="im-field"><label>الوحدة الأساسية</label><input class="im-input" name="base_unit" value="قطعة"></div>
        </div>
        <div class="section-title" style="margin-top:14px">الأكواد والوحدات</div>
        <div id="imCreateCodes" class="im-create-codes"></div>
        <div class="im-tools" style="margin-top:10px">
            <button type="button" class="btn btn-outline" id="imCreateAdd"><i class="ti ti-plus"></i> كود فرعي</button>
            <button type="submit" class="btn btn-primary">إنشاء الصنف</button>
        </div>
        <div id="imCreateStatus" class="im-status"></div>
    </form>
    <?php endif; ?>
</div>

<div class="card" id="imCard" hidden>
    <div class="im-head">
        <div><span class="fchip" id="imSource"></span> <strong id="imTitle"></strong> <span class="im-code" id="imId"></span></div>
        <a class="btn btn-outline" id="imMovement" hidden>حركة الصنف</a>
    </div>
    <div class="im-tabs" role="tablist">
        <button class="im-tab active" type="button" data-tab="data">بيانات الصنف</button>
        <button class="im-tab" type="button" data-tab="codes">أكواد ووحدات الصنف</button>
        <button class="im-tab" type="button" data-tab="company">بيانات صنف/شركة</button>
        <button class="im-tab" type="button" data-tab="pricing">التسعير</button>
        <button class="im-tab" type="button" data-tab="suppliers">موردين الصنف</button>
    </div>
    <section class="im-panel active" data-panel="data"><div id="imData" class="im-grid"></div></section>
    <section class="im-panel" data-panel="codes"><div id="imCodes"></div></section>
    <section class="im-panel" data-panel="company"><div id="imCompany" class="im-grid"></div></section>
    <section class="im-panel" data-panel="pricing"><div id="imPricing"></div></section>
    <section class="im-panel" data-panel="suppliers"><div id="imSuppliers"></div></section>
    <div id="imCardStatus" class="im-status"></div>
</div>

<script>
const IM_BOOT = <?= json_encode($imBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function(){
    'use strict';
    const state={q:'',page:1,item:null,categories:[]};
    const $=id=>document.getElementById(id);
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const val=v=>(v===null||v===undefined||v==='')?'غير متاح في المرآة':esc(v);
    const money=v=>(v===null||v===undefined)?'—':Number(v).toFixed(2);
    const checked=v=>Number(v)===1?'checked':'';
    const disabled=()=>state.item&&state.item.readonly?'disabled':'';
    function status(id,msg,type=''){const el=$(id);if(!el)return;el.textContent=msg;el.className='im-status '+type;}
    function debounce(fn,ms){let t;return function(){clearTimeout(t);const a=arguments;t=setTimeout(()=>fn.apply(null,a),ms);};}
    async function loadCategories(){const r=await apiFetch('item_master_api.php?part=categories');state.categories=r.data.rows||[];fillCats();}
    function catOptions(selected){return '<option value="">من غير قسم</option>'+state.categories.map(c=>'<option value="'+Number(c.is1_id)+'" '+(String(c.is1_id)===String(selected)?'selected':'')+'>'+esc(c.name)+'</option>').join('');}
    function fillCats(){const el=$('imCreateCat');if(el)el.innerHTML=catOptions('');}
    async function search(page=1){const q=$('imSearch').value.trim();state.q=q;state.page=page;if(!q){$('imResults').innerHTML='';$('imPager').innerHTML='';return;}status('imSearchStatus','جاري البحث…');try{const r=await apiFetch('item_master_api.php?part=search&q='+encodeURIComponent(q)+'&page='+page);renderResults(r.data);status('imSearchStatus',r.data.total?'':'مفيش نتايج');}catch(e){status('imSearchStatus',e.message,'err');}}
    function renderResults(d){$('imResults').innerHTML=d.rows.length?'<div class="im-results">'+d.rows.map(r=>'<div class="im-result" data-source="'+esc(r.source)+'" data-id="'+Number(r.id)+'"><span class="fchip '+(r.source==='true'?'fchip-mute':'fchip-pos')+'">'+(r.source==='true'?'True':'الموقع')+'</span><span class="im-result-name" title="'+esc(r.name1)+'">'+esc(r.name1)+'</span><span class="im-code">'+esc(r.code)+'</span></div>').join('')+'</div>':'';$('imPager').innerHTML=d.pages>1?'<button class="btn btn-outline" data-page="'+Math.max(1,d.page-1)+'" '+(d.page<=1?'disabled':'')+'>السابق</button><span>'+Number(d.page)+' / '+Number(d.pages)+'</span><button class="btn btn-outline" data-page="'+Math.min(d.pages,d.page+1)+'" '+(d.page>=d.pages?'disabled':'')+'>التالي</button>':'';}
    async function openItem(source,id){status('imCardStatus','جاري تحميل الكارت…');try{const r=await apiFetch('item_master_api.php?part=detail&source='+encodeURIComponent(source)+'&id='+id);if(!r.data.item)throw new Error('الصنف مش موجود');state.item=r.data.item;renderCard();$('imCard').hidden=false;$('imCard').scrollIntoView({behavior:'smooth',block:'start'});status('imCardStatus','');}catch(e){status('imSearchStatus',e.message,'err');}}
    function field(label,key,value,type='text',opts=''){if(state.item.readonly)return '<div class="im-field"><label>'+esc(label)+'</label><div class="im-value">'+val(value)+'</div></div>';if(type==='select')return '<div class="im-field"><label>'+esc(label)+'</label><select class="im-input" data-field="'+esc(key)+'" data-old="'+esc(value??'')+'">'+opts+'</select></div>';if(type==='check')return '<div class="im-field"><label>'+esc(label)+'</label><input class="im-check" type="checkbox" data-field="'+esc(key)+'" data-old="'+Number(value||0)+'" '+checked(value)+'></div>';return '<div class="im-field"><label>'+esc(label)+'</label><input class="im-input" type="'+type+'" data-field="'+esc(key)+'" data-old="'+esc(value??'')+'" value="'+esc(value??'')+'"></div>';}
    function renderCard(){const x=state.item,m=x.main;$('imSource').textContent=x.source==='true'?'True':'الموقع';$('imSource').className='fchip '+(x.source==='true'?'fchip-mute':'fchip-pos');$('imTitle').textContent=m.name1;$('imId').textContent='#'+m.id;if(IM_BOOT.canMovement){$('imMovement').hidden=false;$('imMovement').href='reports.php?type=item_movement&item_id='+encodeURIComponent(m.true_item_id||m.id);}else $('imMovement').hidden=true;
        $('imData').innerHTML=field('الاسم 1','name1',m.name1)+field('تصنيف سعر البيع','sale_price_class',m.sale_price_class,'select','<option value="code" '+(m.sale_price_class==='code'?'selected':'')+'>كود</option><option value="company" '+(m.sale_price_class==='company'?'selected':'')+'>شركة</option>')+field('نوع الصنف','item_type',m.item_type)+field('ملغى','is_cancelled',m.is_cancelled,'check')+field('الوحدة الأساسية','base_unit',m.base_unit)+field('سعر مفتوح','open_price',m.open_price,'check')+field('القسم','cat_is1_id',m.cat_is1_id,'select',catOptions(m.cat_is1_id))+field('الوصف','cat_description_id',m.cat_description_id,'number')+field('النوع','cat_type_id',m.cat_type_id,'number')+field('المرحلة','cat_stage_id',m.cat_stage_id,'number')+field('الشركات','cat_company_id',m.cat_company_id,'number');
        $('imCompany').innerHTML=field('الحد الأدنى','min_qty',m.min_qty,'number')+field('الحد الأعلى','max_qty',m.max_qty,'number')+field('خصم مسموح به %','max_discount_pct',m.max_discount_pct,'number')+'<div class="im-field"><label>تكلفة/أساسي</label><div class="im-value">'+(x.cost_unlocked?money((x.codes[0]||{}).cost):'مقفولة')+'</div></div><div class="im-field"><label>بيانات آخر حركة</label><div class="im-value">من تقرير حركة الصنف</div></div>';
        renderCodes();renderPricing();renderSuppliers();
    }
    function codeInput(row,key,type='text'){const v=row[key]??'';if(state.item.readonly)return val(v);if(type==='check')return '<input class="im-check" type="checkbox" data-code-key="'+key+'" data-old="'+Number(v||0)+'" '+checked(v)+'>';if(type==='type')return '<select class="im-input" data-code-key="code_type" data-old="'+Number(v)+'"><option value="0" '+(Number(v)===0?'selected':'')+'>أساسي</option><option value="1" '+(Number(v)===1?'selected':'')+'>فرعي</option></select>';return '<input class="im-input" type="'+type+'" data-code-key="'+key+'" data-old="'+esc(v)+'" value="'+esc(v)+'">';}
    function renderCodes(){const rows=state.item.codes.map(r=>'<tr data-code-id="'+(r.id||0)+'"><td>'+codeInput(r,'code')+'</td><td>'+codeInput(r,'name1')+'</td><td>'+codeInput(r,'name2')+'</td><td>'+codeInput(r,'code_type','type')+'</td><td>'+codeInput(r,'unit_name')+'</td><td>'+codeInput(r,'unit_type')+'</td><td class="num">'+codeInput(r,'unit_factor','number')+'</td><td>'+codeInput(r,'whole_qty','check')+'</td><td>'+codeInput(r,'open_price','check')+'</td><td class="num">'+codeInput(r,'prep_days','number')+'</td><td>'+codeInput(r,'display_screen')+'</td><td class="num">'+codeInput(r,'display_order','number')+'</td><td>'+codeInput(r,'is_purchase_code','check')+'</td><td>'+codeInput(r,'is_sale_code','check')+'</td></tr>').join('');$('imCodes').innerHTML='<div class="im-table-wrap"><table class="ftable" data-xt-off><thead><tr><th>الكود</th><th>الاسم 1</th><th>الاسم 2</th><th>النوع</th><th>الوحدة</th><th>نوع الوحدة</th><th class="num">المعامل</th><th>كميات صحيحة</th><th>سعر مفتوح</th><th class="num">التجهيز</th><th>شاشة عرض</th><th class="num">الترتيب</th><th>شراء</th><th>بيع</th></tr></thead><tbody>'+rows+'</tbody></table></div>'+(state.item.readonly||!IM_BOOT.canEdit?'':'<button type="button" class="btn btn-outline" id="imAddCode" style="margin-top:10px"><i class="ti ti-plus"></i> إضافة كود</button>');}
    function renderPricing(){const prices=state.item.codes.map(r=>'<tr><td class="im-code">'+esc(r.code)+'</td><td>'+esc(r.name1)+'</td><td>'+val(r.unit_name)+'</td><td class="num">'+Number(r.unit_factor||1)+'</td><td class="num">'+money(r.true_price)+'</td><td class="num '+(r.site_price!==null&&r.true_price!==null&&Number(r.site_price)!==Number(r.true_price)?'im-price-diff':'')+'">'+money(r.site_price)+'</td><td>'+(IM_BOOT.canPrices?'<input class="im-input" type="number" min="0" step="0.01" data-price-code="'+esc(r.code)+'" value="'+esc(r.site_price??r.true_price??'')+'" placeholder="سعر الموقع"><input class="im-input" data-price-reason="'+esc(r.code)+'" placeholder="السبب (اختياري)">':'—')+'</td></tr>').join('');const history=state.item.history.map(h=>'<tr><td class="im-code">'+esc(h.code)+'</td><td class="num">'+money(h.old_price)+'</td><td class="num">'+money(h.new_price)+'</td><td class="num">'+money(h.true_price)+'</td><td>'+esc(h.reason||'')+'</td><td>'+esc(h.user_name||'')+'</td><td>'+esc(h.created_at)+'</td></tr>').join('');$('imPricing').innerHTML='<div class="im-table-wrap"><table class="ftable" data-xt-off><thead><tr><th>الكود</th><th>الاسم</th><th>الوحدة</th><th class="num">المعامل</th><th class="num">سعر True</th><th class="num">سعر الموقع</th><th>تغيير السعر</th></tr></thead><tbody>'+prices+'</tbody></table></div><div class="section-title" style="margin-top:16px">سجل الأسعار</div><div class="im-table-wrap"><table class="ftable"><thead><tr><th>الكود</th><th class="num">القديم</th><th class="num">الجديد</th><th class="num">سعر True</th><th>السبب</th><th>المستخدم</th><th>التاريخ</th></tr></thead><tbody>'+history+'</tbody></table></div>';}
    function renderSuppliers(){const rows=state.item.suppliers.map((r,i)=>'<tr><td>'+(i===0?'<span class="fchip fchip-pos">آخر مورد</span> ':'')+esc(r.supplier_name)+'</td><td class="num">'+money(r.unit_price)+'</td><td class="num">'+Number(r.unit_factor||1)+'</td><td>'+val(r.unit_name)+'</td><td class="num">'+Number(r.quantity||0)+'</td><td>'+esc(r.purchase_date||'')+'</td></tr>').join('');$('imSuppliers').innerHTML=rows?'<div class="im-table-wrap"><table class="ftable"><thead><tr><th>المورد</th><th class="num">آخر سعر شراء</th><th class="num">المعامل</th><th>الوحدة</th><th class="num">الكمية</th><th>تاريخ الشراء</th></tr></thead><tbody>'+rows+'</tbody></table></div>':'<div class="im-muted">مفيش مشتريات مسجلة للصنف.</div>';}
    async function saveMain(el){if(!state.item||state.item.readonly)return;const value=el.type==='checkbox'?(el.checked?1:0):el.value;if(String(value)===String(el.dataset.old))return;status('imCardStatus','جاري الحفظ…');try{await apiFetch('item_master_api.php?part=update',{method:'POST',body:{id:state.item.main.id,field:el.dataset.field,value:value}});el.dataset.old=String(value);state.item.main[el.dataset.field]=value;status('imCardStatus','اتحفظ','ok');}catch(e){status('imCardStatus',e.message,'err');}}
    function rowPayload(tr){const o={item_id:state.item.main.id,code_id:Number(tr.dataset.codeId||0)};tr.querySelectorAll('[data-code-key]').forEach(el=>o[el.dataset.codeKey]=el.type==='checkbox'?(el.checked?1:0):el.value);return o;}
    async function saveCode(el){const now=el.type==='checkbox'?(el.checked?1:0):el.value;if(String(now)===String(el.dataset.old))return;const tr=el.closest('tr');status('imCardStatus','جاري حفظ الكود…');try{const r=await apiFetch('item_master_api.php?part=code_save',{method:'POST',body:rowPayload(tr)});tr.dataset.codeId=r.data.id;el.dataset.old=String(now);status('imCardStatus','اتحفظ','ok');}catch(e){status('imCardStatus',e.message,'err');}}
    async function savePrice(el){const code=el.dataset.priceCode,reason=document.querySelector('[data-price-reason="'+CSS.escape(code)+'"]');status('imCardStatus','جاري حفظ السعر…');try{await apiFetch('item_master_api.php?part=price',{method:'POST',body:{code:code,price:el.value,reason:reason?reason.value:''}});status('imCardStatus','السعر اتحفظ في الموقع واتضاف للسجل','ok');await openItem(state.item.source,state.item.main.id);}catch(e){status('imCardStatus',e.message,'err');}}
    function createRow(base){const div=document.createElement('div');div.className='im-create-row';div.innerHTML='<input class="im-input wide" data-new="code" placeholder="الكود" required><input class="im-input wide" data-new="name1" placeholder="اسم الكود"><select class="im-input" data-new="code_type"><option value="'+(base?'0':'1')+'">'+(base?'أساسي':'فرعي')+'</option>'+(base?'':'<option value="0">أساسي</option>')+'</select><input class="im-input" data-new="unit_name" value="قطعة" placeholder="الوحدة"><input class="im-input" data-new="unit_factor" type="number" min="0.0001" step="0.0001" value="1" placeholder="المعامل"><input class="im-input" data-new="price" type="number" min="0" step="0.01" placeholder="السعر">';$('imCreateCodes').appendChild(div);}
    async function createItem(ev){ev.preventDefault();const f=ev.currentTarget,codes=[];f.querySelectorAll('.im-create-row').forEach(row=>{const c={whole_qty:1,is_purchase_code:1,is_sale_code:1};row.querySelectorAll('[data-new]').forEach(el=>c[el.dataset.new]=el.value);codes.push(c);});const body={name1:f.elements.name1.value,cat_is1_id:f.elements.cat_is1_id.value,sale_price_class:f.elements.sale_price_class.value,item_type:f.elements.item_type.value,base_unit:f.elements.base_unit.value,codes:codes};status('imCreateStatus','جاري إنشاء الصنف…');try{const r=await apiFetch('item_master_api.php?part=create',{method:'POST',body:body});status('imCreateStatus','الصنف اتعمل','ok');f.hidden=true;await openItem('site',r.data.id);await search(1);}catch(e){status('imCreateStatus',e.message,'err');}}
    $('imSearchBtn').addEventListener('click',()=>search(1));$('imSearch').addEventListener('input',debounce(()=>search(1),300));$('imSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search(1);}});
    $('imResults').addEventListener('click',e=>{const row=e.target.closest('.im-result');if(row)openItem(row.dataset.source,Number(row.dataset.id));});$('imPager').addEventListener('click',e=>{const b=e.target.closest('[data-page]');if(b)search(Number(b.dataset.page));});
    document.querySelector('.im-tabs').addEventListener('click',e=>{const b=e.target.closest('[data-tab]');if(!b)return;document.querySelectorAll('.im-tab').forEach(x=>x.classList.toggle('active',x===b));document.querySelectorAll('.im-panel').forEach(x=>x.classList.toggle('active',x.dataset.panel===b.dataset.tab));});
    $('imCard').addEventListener('blur',e=>{if(e.target.matches('[data-field]'))saveMain(e.target);else if(e.target.matches('[data-code-key]'))saveCode(e.target);else if(e.target.matches('[data-price-code]'))savePrice(e.target);},true);$('imCard').addEventListener('change',e=>{if(e.target.type==='checkbox'&&e.target.matches('[data-field]'))saveMain(e.target);});
    $('imCard').addEventListener('click',e=>{if(e.target.closest('#imAddCode')){state.item.codes.push({id:0,code:'',name1:state.item.main.name1,name2:'',code_type:1,unit_name:state.item.main.base_unit||'قطعة',unit_type:'sub',unit_factor:1,whole_qty:1,open_price:0,prep_days:0,display_screen:'',display_order:0,is_purchase_code:1,is_sale_code:1});renderCodes();}});
    if(IM_BOOT.canEdit){$('imNewBtn').addEventListener('click',()=>{$('imCreate').hidden=!$('imCreate').hidden;if(!$('imCreateCodes').children.length)createRow(true);});$('imCreateAdd').addEventListener('click',()=>createRow(false));$('imCreate').addEventListener('submit',createItem);}
    loadCategories().catch(e=>status('imSearchStatus',e.message,'err'));
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
