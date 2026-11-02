(function(){
    'use strict';
    const IM_BOOT=JSON.parse(document.getElementById('imBoot').textContent);
    const state={q:'',page:1,item:null,options:{},missing:'',dialog:null,expectedCode:''};
    const $=id=>document.getElementById(id);
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const val=v=>(v===null||v===undefined||v==='')?'—':esc(v);
    const money=v=>(v===null||v===undefined)?'—':Number(v).toFixed(2);
    const checked=v=>Number(v)===1?'checked':'';
    function status(id,msg,type=''){const el=$(id);if(!el)return;el.textContent=msg;el.hidden=!msg;el.className='falert falert-'+(type==='err'?'neg':type==='ok'?'pos':type==='warn'?'warn':'info');}
    function debounce(fn,ms){let t;return function(){clearTimeout(t);const a=arguments;t=setTimeout(()=>fn.apply(null,a),ms);};}
    // تصنيفات فورم «صنف جديد» — الـid في الصفحة ← نوع التصنيف.
    const CAT_IDS={imCreateCat:'section',imCreateDesc:'description',imCreateType:'type',imCreateStage:'stage',imCreateCompany:'company'};
    // كود التصنيف = item_classes.id. القسم بالذات بيتعرض رقمين زي im_next_sys_code (05 مش 5).
    const catCodeOf=(kind,id)=>{const n=Number(id);if(!Number.isFinite(n)||n<=0)return '';return kind==='section'?String(n).padStart(2,'0'):String(n);};
    // البحث برقم: 5 و05 و005 كلهم يوصلوا لنفس التصنيف.
    function catOption(kind,raw){const rows=state.options[kind]||[];const t=String(raw??'').trim();if(!/^\d+$/.test(t))return null;const n=Number(t);return rows.find(c=>Number(c.id)===n)||null;}
    function catBad(input,on){const wrap=input.closest('.im-cat-code-wrap');const mark=wrap?wrap.querySelector('.im-cat-err'):null;if(mark)mark.hidden=!on;input.classList.toggle('im-bad',on);if(on)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');}
    async function loadCategories(){const r=await apiFetch('item_master_api.php?part=categories');state.options=r.data.options||{};fillCats();}
    function kindOptions(kind,selected,selectedName){const rows=state.options[kind]||[];const empty=kind==='section'?'من غير قسم':'من غير تحديد';const has=rows.some(c=>String(c.id)===String(selected));let html='<option value="">'+empty+'</option>'+rows.map(c=>'<option value="'+Number(c.id)+'" '+(String(c.id)===String(selected)?'selected':'')+'>'+esc(c.name)+'</option>').join('');if(selected!==null&&selected!==undefined&&selected!==''&&!has)html+='<option value="'+Number(selected)+'" selected>'+esc(selectedName||('#'+selected))+' — موقوف</option>';return html;}
    function fillCats(){Object.keys(CAT_IDS).forEach(id=>{const el=$(id);if(!el)return;const kind=CAT_IDS[id];
        // خانة الكود بتتحط مرة واحدة قبل الـselect جوه صف واحد، والـreset بيفضّيها بعد كده.
        let pair=el.closest('.im-cat-pair');
        if(!pair){
            const input=document.createElement('input');input.type='text';input.className='finput im-cat-code';input.dir='ltr';input.placeholder='كود';input.autocomplete='off';input.setAttribute('inputmode','numeric');input.setAttribute('aria-label','كود التصنيف');input.dataset.catCode='';input.dataset.catKind=kind;
            const mark=document.createElement('i');mark.className='ti ti-alert-circle im-cat-err';mark.setAttribute('aria-hidden','true');mark.hidden=true;
            const wrap=document.createElement('span');wrap.className='im-cat-code-wrap';wrap.appendChild(input);wrap.appendChild(mark);
            pair=document.createElement('div');pair.className='im-cat-pair';
            el.parentElement.insertBefore(pair,el);pair.appendChild(wrap);pair.appendChild(el);
        }
        const input=pair.querySelector('[data-cat-code]');if(input){input.value='';catBad(input,false);}
        el.dataset.catKind=kind;el.innerHTML=kindOptions(kind,'');
    });}
    // اختيار من الـselect ← خانة الكود تتحدّث بنفس الكود المعروض.
    function syncCatCode(sel){const kind=sel.dataset.catKind||CAT_IDS[sel.id]||'';const pair=sel.closest('.im-cat-pair');const input=pair?pair.querySelector('[data-cat-code]'):null;if(!input)return;input.value=catCodeOf(kind,sel.value);catBad(input,false);}
    // كتابة كود + Enter أو blur: نفس اللي بيحصل لما تختار من الـselect بالظبط.
    // الحفظ بيتنده من الـselect نفسه مرة واحدة — مش من خانة الكود (منع الحفظ مرتين).
    function commitCatCode(input){const kind=input.dataset.catKind;const pair=input.closest('.im-cat-pair');const sel=pair?pair.querySelector('select'):null;const raw=(input.value||'').trim();
        catBad(input,false);
        if(raw===''){input.value='';return;}
        const row=catOption(kind,raw);
        if(!row){catBad(input,true);return;}
        input.value=catCodeOf(kind,row.id);
        if(!sel||String(sel.value)===String(row.id))return;
        sel.value=String(row.id);
        if(sel.closest('#imCreate'))sel.dispatchEvent(new Event('change',{bubbles:true}));
        else saveMain(sel);
    }
    async function search(page=1){const q=$('imSearch').value.trim();state.q=q;state.page=page;if(!q){$('imResults').innerHTML='';$('imPager').innerHTML='';return;}status('imSearchStatus','جاري البحث…');try{const r=await apiFetch('item_master_api.php?part=search&q='+encodeURIComponent(q)+'&page='+page);renderResults(r.data);status('imSearchStatus',r.data.total?'':'مفيش نتايج');}catch(e){status('imSearchStatus',e.message,'err');}}
    function renderResults(d){
        $('imResults').innerHTML=d.rows.length?'<div class="ftable-wrap"><table class="ftable" id="imSearchTable"><thead><tr><th>الكود</th><th>الاسم</th><th>القسم</th><th class="num">سعر البيع</th><th class="num">الرصيد الكلي</th></tr></thead><tbody>'+d.rows.map(r=>'<tr role="button" tabindex="0" aria-expanded="false" data-source="'+esc(r.source)+'" data-id="'+Number(r.id)+'"><td dir="ltr">'+esc(r.code)+'</td><td class="name" title="'+esc(r.name1)+'">'+esc(r.name1)+'</td><td>'+val(r.category_name)+'</td><td class="num">'+money(r.sale_price)+'</td><td class="num">'+Number(r.balance)+'</td></tr>').join('')+'</tbody></table></div>':'';
        // المكتبة مافيهاش شكل صف قابل للضغط؛ المؤشر سلوك للصف كله.
        $('imResults').querySelectorAll('tr[role="button"]').forEach(row=>{row.style.cursor='pointer';});
        $('imPager').innerHTML=d.pages>1?'<button class="btn btn-outline" data-page="'+Math.max(1,d.page-1)+'" '+(d.page<=1?'disabled':'')+'>السابق</button><span>'+Number(d.page)+' / '+Number(d.pages)+' · الفلتر على الصفحة الحالية بس</span><button class="btn btn-outline" data-page="'+Math.min(d.pages,d.page+1)+'" '+(d.page>=d.pages?'disabled':'')+'>التالي</button>':'';
    }
    function showView(view){$('imCard').hidden=view!=='card';if($('imCreate'))$('imCreate').hidden=view!=='create';if(view==='card')$('imMissing').hidden=true;}
    function backToSearch(){showView(state.item?'card':'search');$('imMissing').hidden=true;$('imLookup').focus();}
    function newItem(barcode=''){
        showView('create');
        $('imMissing').hidden=true;
        $('imCreate').reset();
        status('imCreateStatus','');
        fillCats();
        if($('imCreateCode'))$('imCreateCode').value='اختار القسم الأول';
        state.expectedCode='';
        $('imCreate').elements.barcode.value=barcode;
        $('imCreate').elements.name1.focus();
    }
    async function updateNextCode(){
        const codeEl=$('imCreateCode');
        if(!codeEl)return;
        const catEl=$('imCreateCat');
        const sectionId=catEl?catEl.value.trim():'';
        if(!sectionId){
            codeEl.value='اختار القسم الأول';
            state.expectedCode='';
            return;
        }
        codeEl.value='…';
        try{
            const r=await apiFetch('item_master_api.php?action=next_code&section='+encodeURIComponent(sectionId));
            if(catEl&&catEl.value.trim()===sectionId){
                codeEl.value=r.data.code;
                state.expectedCode=r.data.code;
            }
        }catch(e){
            if(catEl&&catEl.value.trim()===sectionId){
                codeEl.value='تعذّر جلب الكود';
                state.expectedCode='';
            }
        }
    }
    function closeSearch(){if(state.dialog)state.dialog.querySelector('.fg-dlg-ok').click();}
    async function nameSearch(){
        if(state.dialog)return;
        const done=window.fgAlert('ابحث باسم الصنف','بحث بالاسم');
        await Promise.resolve();await Promise.resolve();
        const dialog=document.querySelector('.fg-dlg-bg:last-of-type .fg-dlg');
        if(!dialog)return;
        state.dialog=dialog;dialog.classList.add('im-search-dialog');
        const host=dialog.querySelector('.fg-dlg-msg');host.textContent='';host.appendChild($('imSearchView'));
        $('imSearchView').hidden=false;$('imSearch').focus();
        await done;$('imSearchView').hidden=true;$('imSearchHome').appendChild($('imSearchView'));state.dialog=null;
    }
    async function lookup(){const code=$('imLookup').value.trim();if(!code)return;status('imLookupStatus','جاري البحث…');try{
        const r=await apiFetch('item_master_api.php?part=lookup&code='+encodeURIComponent(code));
        if(r.data.match){$('imMissing').hidden=true;await openItem(r.data.match.source,r.data.match.id);}
        else{state.missing=code;$('imMissingText').textContent='الكود '+code+' مش متعرّف عندنا';$('imMissing').hidden=false;}
        status('imLookupStatus','');
    }catch(e){status('imLookupStatus',e.message,'err');}}
    async function openItem(source,id){status('imSearchStatus','جاري تحميل الكارت…');try{const r=await apiFetch('item_master_api.php?part=detail&source='+encodeURIComponent(source)+'&id='+id);if(!r.data.item)throw new Error('الصنف مش موجود');state.item=r.data.item;renderCard();showView('card');closeSearch();history.replaceState(null,'','?source='+encodeURIComponent(source)+'&id='+Number(id));$('imResults').querySelectorAll('[data-source]').forEach(row=>row.setAttribute('aria-expanded',row.dataset.source===source&&Number(row.dataset.id)===Number(id)?'true':'false'));status('imSearchStatus','');status('imCardStatus','');}catch(e){status($('imCard').hidden?'imSearchStatus':'imCardStatus',e.message,'err');}}
    function readField(label,value){return '<div class="ffield"><label>'+esc(label)+'</label><div class="im-value" dir="auto">'+esc(value===null||value===undefined||value===''?'—':value)+'</div></div>';}
    function field(label,key,value,type='text',opts=''){if(state.item.readonly||!IM_BOOT.canEdit)return readField(label,type==='check'?(value==null?'—':Number(value)?'آه':'لأ'):value);if(type==='select')return '<div class="ffield"><label>'+esc(label)+'</label><select class="fselect" data-field="'+esc(key)+'" data-old="'+esc(value??'')+'">'+opts+'</select></div>';if(type==='check')return '<div class="ffield"><label>'+esc(label)+'</label><input type="checkbox" data-field="'+esc(key)+'" data-old="'+Number(value||0)+'" '+checked(value)+'></div>';return '<div class="ffield"><label>'+esc(label)+'</label><input class="finput" dir="auto" type="'+type+'" data-field="'+esc(key)+'" data-old="'+esc(value??'')+'" value="'+esc(value??'')+'"></div>';}
    function catField(label,kind,key){const x=state.item,m=x.main,id=m[key],name=(m.class_names||{})[kind]||'',code=catCodeOf(kind,id);
        // عرض بس: الكود يظهر جنب اسم التصنيف.
        if(x.readonly||!IM_BOOT.canEdit){const txt=x.readonly&&kind!=='section'&&!name?'لسه مااتسحبتش من True':(name||(id?'#'+id:'—'));return '<div class="ffield"><label>'+esc(label)+'</label><div class="im-value" dir="auto">'+(code?'<span class="fchip fchip-mute im-cat-code-chip" dir="ltr">'+esc(code)+'</span> ':'')+esc(txt)+'</div></div>';}
        return '<div class="ffield"><label>'+esc(label)+'</label><div class="im-cat-pair">'
            +'<span class="im-cat-code-wrap"><input class="finput im-cat-code" type="text" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="كود" aria-label="كود '+esc(label)+'" data-cat-code="'+esc(key)+'" data-cat-kind="'+kind+'" value="'+esc(code)+'"><i class="ti ti-alert-circle im-cat-err" aria-hidden="true" hidden></i></span>'
            +'<select class="fselect" data-field="'+esc(key)+'" data-old="'+esc(id??'')+'" data-cat-kind="'+kind+'">'+kindOptions(kind,id,name)+'</select>'
            +'</div></div>';}
    function renderCard(){const x=state.item,m=x.main,base=x.codes.find(c=>Number(c.code_type)===0)||{},code=x.source==='true'?m.true_item_id:m.sys_code;
        $('imSource').textContent=x.source==='true'?'True':'الموقع';$('imSource').className='fchip '+(x.source==='true'?'fchip-mute':'fchip-pos');$('imTitle').textContent=m.name1;$('imCode').textContent=code||'—';
        $('imCategory').textContent=(m.class_names?.section||m.category_name||'').trim();if(m.true_category_name&&m.true_category_name!==m.category_name)$('imCategory').textContent+=' | True: '+m.true_category_name;$('imCategory').hidden=!$('imCategory').textContent;$('imDescription').textContent=(m.class_names?.description||'').trim();$('imDescription').hidden=!$('imDescription').textContent;$('imCodeCount').textContent=x.codes.length+' كود';
        $('imMovement').hidden=!IM_BOOT.canMovement;if(IM_BOOT.canMovement)$('imMovement').href='reports.php?type=item_movement&item_id='+encodeURIComponent(m.true_item_id||m.id);
        $('imLabels').hidden=!IM_BOOT.canLabels||x.source!=='true'||!base.code;if(!$('imLabels').hidden)$('imLabels').href='labels_print.php?ids='+encodeURIComponent(JSON.stringify([{code:base.code,copies:1}]));
        const type=x.source==='true'?'مخزني':m.item_type==='service'?'غير مخزني':m.item_type==='stock'?'مخزني':'—';
        const unitName=x.source==='true'?(base.unit_name||'قطعة'):(m.base_unit||base.unit_name);
        const typeField=x.readonly||!IM_BOOT.canEdit?readField('نوع الصنف',type):field('نوع الصنف','item_type',m.item_type,'select','<option value="stock" '+(m.item_type==='stock'?'selected':'')+'>مخزني</option><option value="service" '+(m.item_type==='service'?'selected':'')+'>غير مخزني</option>');
        const name=field('اسم الصنف','name1',m.name1).replace('class="ffield"','class="ffield im-name"').replace('class="finput"','class="finput im-name-value"').replace('class="im-value"','class="im-value im-name-value"');
        $('imData').innerHTML='<div class="im-fields im-code-fields">'+readField('كود الصنف',code).replace('كود الصنف</label>','كود الصنف <span class="fchip fchip-mute">تلقائي</span></label>')+readField('الباركود',base.code)+'</div>'+name+'<div class="im-fields im-small">'+readField('التكلفة',x.cost_unlocked?(base.cost??x.sidebar?.piece_cost??'—'):'مقفولة')+readField('سعر البيع',money(base.site_price??base.true_price))+typeField+field('الوحدة','base_unit',unitName)+'</div><p class="fpage-sub im-note">مخزني = ليه رصيد بيزيد وينقص. غير مخزني (خدمات زي التدبيس) = مالوش رصيد</p><h3 class="fcard-title">التصنيفات</h3><div class="im-fields im-cats">'+catField('القسم','section','cat_is1_id')+catField('الوصف','description','cat_description_id')+catField('النوع','type','cat_type_id')+catField('المرحلة','stage','cat_stage_id')+catField('الشركات','company','cat_company_id')+'</div>';
        $('imCompany').innerHTML='<h3 class="fcard-title">بيانات الصنف</h3><div class="fgrid fgrid-3">'+readField('تكلفة/أساسي',x.cost_unlocked?(base.cost??x.sidebar?.piece_cost):'مقفولة')+readField('بيانات الوحدة الأساسية',(unitName||'—')+' · معامل '+(base.unit_factor||1))+'<div class="ffield"><label>بيانات آخر حركة</label>'+(IM_BOOT.canMovement?'<a href="'+esc($('imMovement').href)+'">افتح حركة الصنف</a>':'—')+'</div></div><h3 class="fcard-title">بيانات المخزون</h3><div class="fgrid fgrid-3">'+readField('الحد الأدنى',m.min_qty)+readField('الحد الأعلى',m.max_qty)+'</div><h3 class="fcard-title">بيانات الضرائب والخصومات</h3>'+readField('خصم مسموح به %',m.max_discount_pct)+'<div class="falert falert-info">نفس تقسيم ترو. الحد الأدنى والأعلى والخصم المسموح لسه مش مربوطين بالنواقص ونقطة البيع.</div>';
        renderCodes();renderPricing();renderSuppliers();renderSidebar();
    }
    function renderSidebar(){const x=state.item,d=x.sidebar||{},base=x.codes[0]||{},large=d.largest||base,p=d.purchase,stock=x.main.item_type!=='service';
        const names={hq:'الإدارة',zahraa:'الفجالة',bonjour:'بونجور',degla:'دجلة',abc:'اي بي سي'};
        const num=v=>v==null?'—':Number(v).toLocaleString('en-US',{maximumFractionDigits:3});
        const row=(k,v)=>'<div><span>'+esc(k)+'</span><span>'+v+'</span></div>';
        const card=(title,body)=>'<div class="fcard"><h3 class="fcard-title">'+title+'</h3>'+body+'</div>';
        const locked=x.cost_unlocked?money(p?.unit_price):'مقفول';
        $('imSidebar').innerHTML=(stock?card('الرصيد الكلي','<div class="im-big">'+num(d.balance)+' <small>قطعة</small></div><p class="fpage-sub">≈ '+num(Number(d.balance||0)/Number(large.unit_factor||1))+' '+val(large.unit_name)+'</p><div class="im-rows">'+(d.balances||[]).map(r=>row(names[r.branch]||r.branch,num(r.balance))).join('')+'</div>'):'')+
            card('آخر شراء','<div class="im-rows">'+row('المورد',val(p?.supplier_name))+row('التاريخ',val(p?.purchase_date))+row('السعر',locked+' / '+val(p?.unit_name))+row('تكلفة القطعة',x.cost_unlocked?num(d.piece_cost):'مقفولة')+'</div>')+
            card('مبيعات آخر 30 يوم','<div class="im-big">'+num(d.sold)+' <small>قطعة</small></div><div class="im-rows">'+(d.sales||[]).map(r=>row(names[r.branch]||r.branch,num(r.pieces))).join('')+(stock?row('الرصيد يكفي',d.days==null?'—':'≈ '+num(d.days)+' يوم'):'')+'</div>')+
            card('سعر البيع','<div class="im-rows">'+row((base.unit_name||'الأساسي')+' ('+(base.code||'—')+')',money(base.site_price??base.true_price))+row((large.unit_name||'أكبر وحدة')+' ('+(large.code||'—')+')',money(large.site_price??large.true_price))+row('هامش أكبر وحدة',x.cost_unlocked?(d.margin==null?'—':Number(d.margin).toFixed(1)+'%'):'مقفول')+'</div>');
    }
    function codeInput(row,key,type='text'){const raw=row[key],v=raw??'';if(state.item.readonly||!IM_BOOT.canEdit)return type==='type'?(Number(v)===0?'أساسي':'فرعي'):type==='check'?(raw==null?'—':Number(v)?'آه':'لأ'):val(v);if(type==='check')return '<input  type="checkbox" data-code-key="'+key+'" data-old="'+Number(v||0)+'" '+checked(v)+'>';if(type==='type')return '<select class="fselect" data-code-key="code_type" data-old="'+Number(v)+'"><option value="0" '+(Number(v)===0?'selected':'')+'>أساسي</option><option value="1" '+(Number(v)===1?'selected':'')+'>فرعي</option></select>';return '<input class="finput" type="'+type+'" data-code-key="'+key+'" data-old="'+esc(v)+'" value="'+esc(v)+'">';}
    function renderCodes(){const rows=state.item.codes.map(r=>'<tr data-code-id="'+(r.id||0)+'"><td>'+codeInput(r,'code')+'</td><td>'+codeInput(r,'name1')+'</td><td>'+codeInput(r,'name2')+'</td><td>'+codeInput(r,'code_type','type')+'</td><td>'+codeInput(r,'unit_name')+'</td><td>'+codeInput(r,'unit_type')+'</td><td class="num">'+codeInput(r,'unit_factor','number')+'</td><td>'+codeInput(r,'whole_qty','check')+'</td><td>'+codeInput(r,'open_price','check')+'</td><td class="num">'+codeInput(r,'prep_days','number')+'</td><td>'+codeInput(r,'display_screen')+'</td><td class="num">'+codeInput(r,'display_order','number')+'</td><td>'+codeInput(r,'is_purchase_code','check')+'</td><td>'+codeInput(r,'is_sale_code','check')+'</td></tr>').join('');$('imCodes').innerHTML='<div class="ftable-wrap"><table class="ftable"><thead><tr><th>الكود</th><th>الاسم 1</th><th>الاسم 2</th><th>نوع الكود</th><th>الوحدة</th><th>نوع الوحدة</th><th class="num">م.التحويل</th><th>كميات صحيحة</th><th>سعر مفتوح</th><th class="num">مدة التجهيز</th><th>شاشة عرض</th><th class="num">ترتيب</th><th>كود شراء</th><th>كود بيع</th></tr></thead><tbody>'+rows+'</tbody></table></div>'+(!state.item.readonly?'<div class="fgrid fgrid-3">'+field('ملغى','is_cancelled',state.item.main.is_cancelled,'check')+field('تصنيف سعر البيع','sale_price_class',state.item.main.sale_price_class,'select','<option value="code" '+(state.item.main.sale_price_class==='code'?'selected':'')+'>كود</option><option value="company" '+(state.item.main.sale_price_class==='company'?'selected':'')+'>شركة</option>')+'</div>':'')+(state.item.readonly||!IM_BOOT.canEdit?'':'<button type="button" class="btn btn-outline" id="imAddCode"><i class="ti ti-plus"></i> إضافة كود</button>');}
    function renderPricing(){
        const prices=state.item.codes.map(r=>'<tr><td dir="ltr">'+esc(r.code)+'</td><td dir="auto" class="name" title="'+esc(r.name1)+'">'+esc(r.name1)+'</td><td>'+val(r.unit_name)+'</td><td class="num">'+Number(r.unit_factor||1)+'</td><td class="num">'+(IM_BOOT.canPrices?'<input class="finput" type="number" min="0" step="0.01" data-price-code="'+esc(r.code)+'" value="'+esc(r.site_price??r.true_price??'')+'"><input class="finput" data-price-reason="'+esc(r.code)+'" placeholder="السبب (اختياري)">':money(r.site_price??r.true_price))+'</td><td>مستهلك</td><td>'+(r.site_price!=null?'سعر الموقع الحالي':'سعر True الحالي')+'</td></tr>').join('');
        const history=state.item.history.map(h=>'<tr><td>'+esc(h.created_at)+'</td><td dir="ltr">'+esc(h.code)+'</td><td class="num">'+money(h.old_price)+'</td><td class="num">'+money(h.new_price)+'</td><td>'+val(h.user_name)+'</td><td>'+val(h.reason)+'</td></tr>').join('');
        $('imPricing').innerHTML='<div class="ftable-wrap"><table class="ftable"><thead><tr><th>الكود</th><th>الاسم</th><th>الوحدة</th><th class="num">م.التحويل</th><th class="num">سعر الوحدة</th><th>نوع السعر</th><th>التاريخ</th></tr></thead><tbody>'+prices+'</tbody></table></div><h3 class="fcard-title">سجل تغيير الأسعار</h3>'+(history?'<div class="ftable-wrap"><table class="ftable"><thead><tr><th>التاريخ</th><th>الكود</th><th class="num">السعر القديم</th><th class="num">السعر الجديد</th><th>مين غيّره</th><th>السبب</th></tr></thead><tbody>'+history+'</tbody></table></div>':'<div class="falert">مفيش أي تغيير سعر من الموقع للصنف ده لسه. أي تغيير بعد كده يتسجّل هنا بالتاريخ والسعر القديم والجديد ومين غيّره.</div>');
    }
    function renderSuppliers(){const rows=state.item.suppliers.map((r,i)=>'<tr><td dir="ltr">'+val(r.item_code)+'</td><td>'+(i===0?'<span class="fchip fchip-pos">آخر مورد</span> ':'')+esc(r.supplier_name)+'</td><td>'+esc(r.supplier_type)+'</td><td class="num">'+money(r.unit_price)+'</td><td class="num">'+Number(r.unit_factor||1)+'</td><td>'+val(r.unit_name)+'</td><td class="num">'+Number(r.quantity||0)+'</td><td>'+esc(r.purchase_date||'')+'</td></tr>').join('');$('imSuppliers').innerHTML=rows?'<div class="ftable-wrap"><table class="ftable"><thead><tr><th>الكود</th><th>المورد</th><th>نوع المورد</th><th class="num">آخر سعر شراء</th><th class="num">معامل التحويل</th><th>الوحدة</th><th class="num">الكمية</th><th>تاريخ شراء</th></tr></thead><tbody>'+rows+'</tbody></table></div>':'<p class="fpage-sub">مفيش مشتريات مسجلة للصنف.</p>'; }
    async function saveMain(el){if(!state.item||state.item.readonly)return;const value=el.type==='checkbox'?(el.checked?1:0):el.value;if(String(value)===String(el.dataset.old))return;status('imCardStatus','جاري الحفظ…');try{await apiFetch('item_master_api.php?part=update',{method:'POST',body:{id:state.item.main.id,field:el.dataset.field,value:value}});el.dataset.old=String(value);state.item.main[el.dataset.field]=value;renderSidebar();status('imCardStatus','اتحفظ','ok');}catch(e){status('imCardStatus',e.message,'err');}}
    function rowPayload(tr){const o={item_id:state.item.main.id,code_id:Number(tr.dataset.codeId||0)};tr.querySelectorAll('[data-code-key]').forEach(el=>o[el.dataset.codeKey]=el.type==='checkbox'?(el.checked?1:0):el.value);return o;}
    async function saveCode(el){const now=el.type==='checkbox'?(el.checked?1:0):el.value;if(String(now)===String(el.dataset.old))return;const tr=el.closest('tr');status('imCardStatus','جاري حفظ الكود…');try{const r=await apiFetch('item_master_api.php?part=code_save',{method:'POST',body:rowPayload(tr)});tr.dataset.codeId=r.data.id;el.dataset.old=String(now);status('imCardStatus','اتحفظ','ok');}catch(e){status('imCardStatus',e.message,'err');}}
    async function savePrice(el){const code=el.dataset.priceCode,reason=document.querySelector('[data-price-reason="'+CSS.escape(code)+'"]');status('imCardStatus','جاري حفظ السعر…');try{await apiFetch('item_master_api.php?part=price',{method:'POST',body:{code:code,price:el.value,reason:reason?reason.value:''}});status('imCardStatus','السعر اتحفظ في الموقع واتضاف للسجل','ok');await openItem(state.item.source,state.item.main.id);}catch(e){status('imCardStatus',e.message,'err');}}
    async function createItem(ev){ev.preventDefault();const f=ev.currentTarget;if(!f.reportValidity())return;const body={sale_price_class:'code'};
        ['name1','cat_is1_id','cat_description_id','cat_type_id','cat_stage_id','cat_company_id','item_type','base_unit'].forEach(k=>body[k]=f.elements[k]?f.elements[k].value:'');
        const base={code:f.elements.barcode.value.trim(),name1:body.name1,code_type:0,unit_name:body.base_unit,unit_factor:1,whole_qty:1,is_purchase_code:1,is_sale_code:1};
        if(IM_BOOT.canPrices&&f.elements.price&&f.elements.price.value!=='')base.price=f.elements.price.value;
        body.codes=[base];status('imCreateStatus','جاري إنشاء الصنف…');const submit=f.querySelector('[type="submit"]');submit.disabled=true;
        const expectedCode=state.expectedCode||($('imCreateCode')?$('imCreateCode').value.trim():'');
        try{
            const r=await apiFetch('item_master_api.php?part=create',{method:'POST',body});
            const savedCode=r.data.code||'';
            const isDiff=Boolean(expectedCode && savedCode && savedCode!==expectedCode && expectedCode!=='اختار القسم الأول');
            const msg=isDiff
                ? ('اتسجّل بكود '+savedCode+' بدل '+expectedCode+' — حد سجّل صنف في نفس القسم قبلك')
                : ('الصنف اتعمل بكود '+savedCode);
            const toastType=isDiff?'warn':'ok';
            const toast=(window.ui&&window.ui.toast)||window.fgToast;
            if(typeof toast==='function')toast(msg,toastType,isDiff?8000:3500);
            status('imCreateStatus',msg,isDiff?'warn':'ok');
            await openItem('site',r.data.id);
            status('imCardStatus',msg,isDiff?'warn':'ok');
        }catch(e){status('imCreateStatus',e.message,'err');}finally{submit.disabled=false;}
    }
    $('imLookup').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();lookup();}});
    $('imNameSearch').addEventListener('click',nameSearch);
    if($('imDefine'))$('imDefine').addEventListener('click',()=>newItem(state.missing));
    // الحوار الموحّد بيمسك Enter؛ البحث له أولوية قبل معالج الحوار.
    document.addEventListener('keydown',e=>{if(!state.dialog)return;
        if(e.key==='Tab'&&state.dialog.contains(e.target)){
            const controls=Array.from(state.dialog.querySelectorAll('input,select,button,a[href],[tabindex="0"]')).filter(el=>!el.disabled&&el.offsetParent!==null);
            if(controls.length){e.preventDefault();e.stopImmediatePropagation();const index=controls.indexOf(document.activeElement);controls[(index+(e.shiftKey?-1:1)+controls.length)%controls.length].focus();}return;
        }
        if(!$('imSearchView').contains(e.target)||!['Enter',' '].includes(e.key))return;
        const row=e.target.closest('tr[data-source]');if(row){e.preventDefault();e.stopImmediatePropagation();openItem(row.dataset.source,Number(row.dataset.id));}
        else if(e.key==='Enter'&&e.target===$('imSearch')){e.preventDefault();e.stopImmediatePropagation();search(1);}
        else if(e.key==='Enter'&&e.target.closest('button,a')){e.preventDefault();e.stopImmediatePropagation();e.target.closest('button,a').click();}
    },true);
    $('imSearchBtn').addEventListener('click',()=>search(1));$('imSearch').addEventListener('input',debounce(()=>search(1),300));$('imSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search(1);}});
    $('imResults').addEventListener('click',e=>{const row=e.target.closest('tr[data-source]');if(row)openItem(row.dataset.source,Number(row.dataset.id));});
    $('imResults').addEventListener('keydown',e=>{if(e.key!=='Enter'&&e.key!==' ')return;const row=e.target.closest('tr[data-source]');if(row){e.preventDefault();openItem(row.dataset.source,Number(row.dataset.id));}});$('imPager').addEventListener('click',e=>{const b=e.target.closest('[data-page]');if(b)search(Number(b.dataset.page));});
    $('imTabs').addEventListener('click',e=>{const b=e.target.closest('[data-tab]');if(!b)return;document.querySelectorAll('#imTabs .ftab').forEach(x=>{const on=x===b;x.classList.toggle('is-active',on);x.setAttribute('aria-selected',String(on));});document.querySelectorAll('[data-panel]').forEach(x=>x.hidden=x.dataset.panel!==b.dataset.tab);});
    document.querySelectorAll('[data-back]').forEach(b=>b.addEventListener('click',backToSearch));
    $('imCard').addEventListener('blur',e=>{if(e.target.matches('[data-field]'))saveMain(e.target);else if(e.target.matches('[data-code-key]'))saveCode(e.target);else if(e.target.matches('[data-price-code]'))savePrice(e.target);else if(e.target.matches('[data-cat-code]'))commitCatCode(e.target);},true);
    $('imCard').addEventListener('change',e=>{if(e.target.matches('select[data-field]'))syncCatCode(e.target);else if(e.target.type==='checkbox'&&e.target.matches('[data-field]'))saveMain(e.target);});
    $('imCard').addEventListener('keydown',e=>{const cat=e.target.closest('[data-cat-code]');if(cat&&e.key==='Enter'){e.preventDefault();commitCatCode(cat);}});
    $('imCard').addEventListener('click',e=>{if(e.target.closest('#imAddCode')){state.item.codes.push({id:0,code:'',name1:state.item.main.name1,name2:'',code_type:1,unit_name:state.item.main.base_unit||'قطعة',unit_type:'sub',unit_factor:1,whole_qty:1,open_price:0,prep_days:0,display_screen:'',display_order:0,is_purchase_code:1,is_sale_code:1});renderCodes();}});
    if(IM_BOOT.canEdit){
        $('imNewBtn').addEventListener('click',()=>newItem());
        $('imCreate').addEventListener('submit',createItem);
        if($('imCreateCat'))$('imCreateCat').addEventListener('change',updateNextCode);
        // خانة كود التصنيف في «صنف جديد»: Enter أو blur، والاختيار من الـselect بيحدّثها.
        $('imCreate').addEventListener('keydown',e=>{const cat=e.target.closest('[data-cat-code]');if(cat&&e.key==='Enter'){e.preventDefault();commitCatCode(cat);}});
        $('imCreate').addEventListener('blur',e=>{if(e.target.matches('[data-cat-code]'))commitCatCode(e.target);},true);
        $('imCreate').addEventListener('change',e=>{if(e.target.matches('select[data-cat-kind]'))syncCatCode(e.target);});
    }
    // 🔴 النداء هنا مايتنفذش وقت قراية السكربت: `apiFetch` في assets/app.js
    //    اللي بيتحمّل في الفوتر؛ نستنى اكتمال التحميل قبل أي نداء.
    document.addEventListener('DOMContentLoaded', async () => {
        // التصنيفات الأول: الـselect بيرسم من state.options، فلو رندرنا الكارت
        // قبل ما توصل يبقى فيه القيمة الحالية بس والكود مايغيّرش حاجة.
        try{await loadCategories();}catch(e){status('imSearchStatus',e.message,'err');}
        if(IM_BOOT.item){state.item=IM_BOOT.item;renderCard();showView('card');}
        const params=new URLSearchParams(location.search),source=params.get('source'),id=Number(params.get('id'));
        if(!IM_BOOT.item&&(source==='true'||source==='site')&&id>0)openItem(source,id);
    });
})();
