/* تقسيم سريع؛ كل الكتابة من API واحد والحفظ أول الاختيار. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const boot=JSON.parse(document.getElementById('icBoot').textContent);
    const $=id=>document.getElementById(id), esc=window.ui.esc;
    let table=$('icTable');
    const host=table.closest('.ica-scroll'), template=table.cloneNode(true);
    const kinds=['section','description','type','stage','company'];
    const kindLabels={section:'القسم',description:'الوصف',type:'النوع',stage:'المرحلة',company:'الشركات'};
    let data=boot, selected=new Set(), bulkClass=null, busy=false, request=0;
    const num=n=>Number(n).toLocaleString('en-US',{maximumFractionDigits:3});
    function message(text,negative=false){$('icMessage').textContent=text;$('icMessage').hidden=!text;$('icMessage').className='falert falert-'+(negative?'neg':'info');}
    function query(page){
        const q=new URLSearchParams(new FormData($('icFilters')));
        q.set('no_section',$('icFilters').elements.no_section.checked?'1':'0');
        q.set('sold90',$('icFilters').elements.sold90.checked?'1':'0');q.set('page',page);return q;
    }
    function selection(){
        $('icBulk').hidden=!selected.size;$('icSelected').textContent='اتعلّم '+selected.size+' صنف';
        table.querySelectorAll('tbody tr[data-id]').forEach(tr=>{const on=selected.has(Number(tr.dataset.id));tr.classList.toggle('frow-selected',on);tr.querySelector('.ic-check').checked=on;});
        const visible=Array.from(table.querySelectorAll('tbody tr[data-id]')).filter(tr=>!tr.classList.contains('xt-out')&&!tr.hidden);
        const checked=visible.filter(tr=>selected.has(Number(tr.dataset.id))).length;
        $('icAll').checked=checked>0&&checked===visible.length;$('icAll').indeterminate=checked>0&&checked<visible.length;
    }
    function render(){
        const xt=table.__xt;
        if(xt && data.rows.length && (xt.q||Object.keys(xt.picks).length)){
            // نفس استرجاع المكتبة مرة واحدة؛ الفلتر يفضل جوه الشاشة بعد الحفظ.
            try{sessionStorage.setItem('xt-restore:'+location.pathname,JSON.stringify([{idx:0,cols:xt.cols,q:xt.q,picks:xt.picks}]));}catch(e){}
        }
        table.querySelectorAll('input[data-kind]').forEach(el=>el.fcombo?.destroy());
        if(xt){const fresh=template.cloneNode(true);table.replaceWith(fresh);table=fresh;}
        table.tBodies[0].innerHTML=data.rows.map(r=>'<tr data-id="'+r.item_id+'"><td><input type="checkbox" class="ic-check" aria-label="علّم '+esc(r.name)+'"></td><td>'+esc(r.code)+'</td><td class="fcell-name" title="'+esc(r.name)+'">'+esc(r.name)+'</td><td class="num">'+num(r.sold)+'</td><td class="num">'+num(r.balance)+'</td>'+kinds.map(kind=>{
            const c=r.classes[kind];return '<td><input class="finput finput-sm finput-cell" data-kind="'+kind+'" placeholder="اختار…" aria-label="'+esc(kindLabels[kind])+'" value="'+esc(c?.name||'')+'">'+(c?.source==='true'?window.ui.chip('True','mute'):'')+'</td>';
        }).join('')+'<td>'+(r.suggestion?'<button class="btn btn-ghost btn-sm ic-suggest">شبه: '+esc(r.suggestion.name)+'</button>':'—')+'</td><td>'+window.ui.chip(r.status==='pending'?'مستني مراجعة':r.status==='approved'?'متراجع':'بدون تقسيم',r.status==='pending'?'warn':r.status==='approved'?'pos':'mute')+'</td><td>'+esc(r.assigned_name||'—')+'</td></tr>').join('');
        if(!data.rows.length)table.tBodies[0].innerHTML='<tr data-xt-ignore><td colspan="13">'+window.ui.empty('مفيش أصناف بالفلاتر دي')+'</td></tr>';
        table.querySelectorAll('input[data-kind]').forEach(input=>{
            const id=Number(input.closest('tr').dataset.id),kind=input.dataset.kind;
            window.ui.combo(input,data.options[kind]||[],{onPick:o=>write({action:'assign',item_ids:[id],kind,class_id:o.id})});
        });
        $('icRemaining').textContent='باقي '+num(data.remaining)+' صنف من غير قسم';
        $('icPage').textContent='صفحة '+data.page+' من '+data.pages+' · '+num(data.total)+' صنف';
        $('icPrev').disabled=data.page<=1||busy;$('icNext').disabled=data.page>=data.pages||busy;selection();
        if(window.xtableScan)window.xtableScan(host);
    }
    async function load(page=1){
        const ticket=++request;
        try {const result=await window.apiFetch('item_classify_api.php?'+query(page));if(ticket!==request)return;data=result.data;selected.clear();render();}
        catch(e){message(e.message||'مش قادرين نجيب الأصناف دلوقتي',true);}
    }
    function lock(on){busy=on;$('icFilters').querySelectorAll('button,input,select').forEach(el=>el.disabled=on);$('icBulk').querySelectorAll('button,input,select').forEach(el=>el.disabled=on);table.querySelectorAll('input,button').forEach(el=>el.disabled=on);$('icPrev').disabled=on||data.page<=1;$('icNext').disabled=on||data.page>=data.pages;}
    async function write(payload){
        if(busy)return;
        ++request;
        const focused=document.activeElement, focusId=focused.closest('tr[data-id]')?.dataset.id, focusKind=focused.dataset.kind;
        lock(true);message('بيتحفظ…');
        try {
            const saved=await window.apiFetch('item_classify_api.php',{method:'POST',body:payload});
            // الصف يفضل مكانه بعد الحفظ، حتى لو فلتر «مالوش قسم» شغّال.
            const fresh=saved.data, byId=new Map(fresh.rows.map(r=>[Number(r.item_id),r]));
            data.rows.forEach(r=>{
                if(!payload.item_ids.includes(Number(r.item_id)))return;
                if(byId.has(Number(r.item_id))){Object.assign(r,byId.get(Number(r.item_id)));return;}
            });
            data.remaining=fresh.remaining;
            message('اتحفظ التقسيم عندنا');
        }catch(e){message(e.message||'التقسيم ما اتحفظش، جرّب تاني',true);}
        finally{lock(false);render();if(focusId&&focusKind){const el=table.querySelector('tr[data-id="'+focusId+'"] input[data-kind="'+focusKind+'"]');if(el&&!el.closest('.xt-out'))el.focus();}}
    }
    function bulkOptions(){bulkClass=null;$('icClass').value='';window.ui.combo($('icClass'),data.options[$('icKind').value]||[],{onPick:o=>{bulkClass=o;}});}
    host.addEventListener('change',e=>{
        if(e.target.classList.contains('ic-check')){const id=Number(e.target.closest('tr').dataset.id);if(e.target.checked)selected.add(id);else selected.delete(id);selection();}
        if(e.target.id==='icAll'){table.querySelectorAll('tbody tr[data-id]').forEach(tr=>{if(tr.classList.contains('xt-out')||tr.hidden)return;const id=Number(tr.dataset.id);if(e.target.checked)selected.add(id);else selected.delete(id);});selection();}
    });
    host.addEventListener('click',e=>{const b=e.target.closest('.ic-suggest');if(!b||busy)return;const r=data.rows.find(r=>Number(r.item_id)===Number(b.closest('tr').dataset.id));write({action:'assign',item_ids:[Number(r.item_id)],kind:'section',class_id:r.suggestion.id});});
    host.addEventListener('focusin',e=>{if(e.target.matches('input[data-kind]'))e.target.dataset.editing='';});
    host.addEventListener('input',e=>{if(e.target.matches('input[data-kind]'))e.target.dataset.editing='1';});
    host.addEventListener('keydown',e=>{
        if(e.key==='Enter'&&e.target.matches('input[data-kind]'))e.target.dataset.editing='1';
        // Space يعلّم الصف في وضع التنقّل، ويفضل حرف عادي أثناء الكتابة.
        const combo=e.target.matches('input[data-kind]');
        if(e.key===' '&&(!combo||e.ctrlKey||(!e.target.dataset.editing&&e.target.getAttribute('aria-expanded')!=='true'))){
            const tr=e.target.closest('tr[data-id]');if(!tr)return;e.preventDefault();const id=Number(tr.dataset.id);if(selected.has(id))selected.delete(id);else selected.add(id);selection();
        }
    },true);
    $('icUnselect').addEventListener('click',()=>{selected.clear();selection();});
    $('icKind').addEventListener('change',bulkOptions);
    $('icApply').addEventListener('click',()=>{if(!bulkClass){message('اختار التصنيف الأول',true);return;}write({action:'assign',item_ids:[...selected],kind:$('icKind').value,class_id:bulkClass.id});});
    $('icApprove')?.addEventListener('click',()=>write({action:'approve',item_ids:[...selected]}));
    $('icPrev').addEventListener('click',()=>load(data.page-1));$('icNext').addEventListener('click',()=>load(data.page+1));
    let timer;
    $('icFilters').addEventListener('submit',e=>{e.preventDefault();if(!busy)load();});
    $('icFilters').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{if(!busy)load();},300);});
    $('icFilters').addEventListener('reset',()=>setTimeout(()=>{if(!busy)load();},0));
    $('icUser').value=boot.filters.assigned_by||'';$('icStatus').value=boot.filters.status||'';
    render();bulkOptions();
});
