// تشغيل سكربت الصفحة نفسه على DOM صغير، من غير API وقت التحميل أو مكتبات إضافية.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('site/assets/supplier_card.js', 'utf8');
function setup(prices) {
    const elements = new Map(), events = {}, requests = [];
    function el(id) {
        if (!elements.has(id)) elements.set(id, {
            textContent: '', innerHTML: '', hidden: false, value: '', dataset: {}, handlers: {},
            classList: {add() {}, remove() {}, toggle() {}},
            addEventListener(k, fn) {this.handlers[k] = fn;}, setAttribute(k,v) {this[k] = v;},
            focus() {}, reset() {}, querySelector() {return null;}
        });
        return elements.get(id);
    }
    const card = {supplier: {erp_supplier_code: '434', name: 'اسم <script>اختبار</script>', phone: '01012345678', is_active: 1, payment_type: 'credit'}, counts: {purchases: 3, returns: 2, items: 1}, statement_url: 'suppliers.php?supplier=843',
        statement: {from:'2026-06-27',to:'2026-11-02',period:{final_balance:305}}, latest: {net_amount:30,doc_no:843,doc_date:'2026-11-02',branch:'بونجور',item_count:1},recent:{count:3,net:330}};
    el('supBoot').textContent = JSON.stringify({canEdit:true,canPrices:prices,hasPrices:prices,nextCode:'435',card});
    const tabs = ['info','purchases','returns','items','statement'].map(tab => Object.assign(el('tab-'+tab), {dataset:{tab,label:tab}}));
    const panels = tabs.map(t => Object.assign(el('panel-'+t.dataset.tab), {dataset:{panel:t.dataset.tab}}));
    const context = {console,URLSearchParams,setTimeout,clearTimeout,history:{replaceState() {}},
        document:{getElementById:el,addEventListener(k, fn) {events[k] = fn;},querySelectorAll(q) {return q.includes('#scTabs') ? tabs : q === '[data-panel]' ? panels : [];},querySelector() {return null;}},
        window:{apiFetch:async url => {requests.push(url);const part=new URL(url,'http://local/').searchParams.get('part');return {data:part==='find'?null:part==='items'?{rows:[{code:'0055',name:'صنف',quantity:27,cost:10,purchase_date:'2026-11-02'}]}:card};}}
    };
    // الدوال نفسها بتتجرّب؛ التصدير ده في نسخة الاختبار بس.
    const exposed = source.replace(/\}\(\)\);\s*$/, 'globalThis.testSc={fields,table,kpis,loadTab,lookup,toggleRow};}());');
    vm.createContext(context);vm.runInContext(exposed,context);
    return {context,events,requests,el,card};
}
(async () => {
    const locked = setup(false);
    assert.equal(locked.requests.length,0);
    locked.events.DOMContentLoaded();
    assert.equal(locked.requests.length,0);
    assert.equal(locked.el('scCode').textContent,'434');
    assert.equal(locked.el('scName').textContent,locked.card.supplier.name);
    assert(!locked.el('scInfo').innerHTML.includes('<script>'));
    assert(!locked.el('scInfo').innerHTML.includes('credit_limit'));
    assert(locked.el('scPrint').hidden);
    await locked.context.testSc.loadTab('items');
    assert.equal(locked.requests.length,0);
    assert(locked.el('scData-items').innerHTML.includes('صلاحية'));
    assert(!locked.context.testSc.fields({}).includes('name="credit_limit"'));
    console.log('PASS boot renders code/name safely with no API; locked UI has no prices or requests');
    const open = setup(true);open.events.DOMContentLoaded();
    assert(!open.el('scPrint').hidden);
    await open.context.testSc.loadTab('items');
    const html=open.el('scData-items').innerHTML;
    assert(html.includes('data-xt-kpis="#scTable-items"'));
    assert(html.includes('data-xt-sum="quantity"'));
    assert(html.includes('data-v-quantity="27"'));
    assert(html.includes('<th class="num">آخر تكلفة</th>'));
    assert(html.includes('0055'));
    assert(open.context.testSc.fields({}).includes('name="credit_limit"'));
    open.el('scCodeInput').value='99999';await open.context.testSc.lookup();
    assert(!open.el('scMissing').hidden);assert(open.el('scCard').hidden);
    assert(open.el('scMissingText').textContent.includes('99999'));
    console.log('PASS unlocked item table, filter KPIs, numeric columns and missing-code state');
    const row={dataset:{src:'hq',doc:'843:26_10'},attrs:{'aria-expanded':'false'},classList:{toggle() {}},getAttribute(k){return this.attrs[k];},setAttribute(k,v){this.attrs[k]=v;},
        nextElementSibling:{dataset:{},hidden:true,firstElementChild:{textContent:'',innerHTML:''}}};
    let lineCalls=0;open.context.window.apiFetch=async () => {lineCalls++;return {data:{rows:[{code:'0055',name:'صنف',quantity:2,cost:50,total:100}]}};};
    await open.context.testSc.toggleRow(row);await open.context.testSc.toggleRow(row);await open.context.testSc.toggleRow(row);
    assert.equal(lineCalls,1);assert.equal(row.attrs['aria-expanded'],'true');assert(!row.nextElementSibling.hidden);
    assert(row.nextElementSibling.firstElementChild.innerHTML.includes('0055'));
    console.log('PASS whole-row details load once and reopen without a second API request');
})().catch(e=>{console.error(e);process.exitCode=1;});
