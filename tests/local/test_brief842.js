/** اختبارات رجوع لبريف ٨٤٢: نفس دوال الصفحة، ببدائل صغيرة للتاب والحفظ والـDOM. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('site/assets/purchase_docs.js', 'utf8');
function actual(name) {
  const start = source.search(new RegExp('  (?:async )?function ' + name + '\\('));
  assert(start >= 0, name);
  const rest = source.slice(start + 2);
  const end = rest.search(/\n  (?:async )?function /);
  return end < 0 ? rest : rest.slice(0, end);
}
async function main() {
  let release, saved = false, closed = false, url = '', saveCalls = 0;
  const tab = { opener:{}, location:{replace(value) { url=value; }}, close() { closed=true; } };
  const context = vm.createContext({
    purchaseLoading:false, purchaseSaveDirty:true, purchaseSaving:null, purchaseNavigating:false,
    purchaseDocType:'purchase', window:{open:()=>tab}, location:{href:''},
    pe:()=>({querySelectorAll:()=>[]}), purchaseMessage:message=>{context.error=message;},
    purchaseSaveNow:async force=>{assert(force); saveCalls++; await new Promise(resolve=>{release=resolve;}); saved=true; context.purchaseSaveDirty=false;}
  });
  vm.runInContext(actual('purchaseBeforeNavigate')+'\n'+actual('purchaseNavigate')+'\n'+actual('purchaseSetType'),context);
  const going=context.purchaseSetType('purchase_return');
  assert.equal(url,''); assert.equal(context.purchaseDocType,'purchase'); assert.equal(saveCalls,1);
  release(); await going;
  assert(saved); assert.equal(url,'purchase_docs.php?type=purchase_return'); assert.equal(context.purchaseDocType,'purchase');
  context.purchaseSaveDirty=true; url=''; closed=false;
  context.purchaseSaveNow=async()=>{throw Error('فشل الحفظ');};
  await context.purchaseSetType('purchase_return');
  assert.equal(url,''); assert(closed); assert.equal(context.error,'فشل الحفظ'); assert(context.purchaseSaveDirty);
  context.purchaseSaving=Promise.resolve(); context.purchaseSaveDirty=false;
  context.purchaseSaveNow=async()=>{saveCalls++;context.purchaseSaving=null;};
  await context.purchaseSetType('purchase'); assert.equal(saveCalls,2);
  context.pe=()=>({querySelectorAll:()=>[{value:'كود لسه ماتثبتش'}]}); url='';
  await context.purchaseSetType('purchase_return'); assert.equal(url,''); assert.match(context.error,/Enter/);
  console.log('نجح: التنقّل بيستنى الحفظ، وفشله بيقفّل التاب، والنوع ثابت، والكود غير المثبت مابيضيعش');

  const makeRow = hidden => ({hidden, classList:{contains:()=>false}, inputs:{}, querySelector(selector){return this.inputs[selector]||null;}});
  const rows=[makeRow(false),makeRow(true),makeRow(false),makeRow(false)];
  let focused='';
  rows.forEach((row,index)=>['.line-qty','.line-cost','.line-discount','.line-discount-amount'].forEach(selector=>{
    row.inputs[selector]={matches:s=>s===selector,closest:()=>row,focus:()=>{focused=index+selector;},select:()=>{},disabled:false};
  }));
  delete rows[2].inputs['.line-cost'];
  context.pe=()=>({querySelectorAll:()=>rows}); context.isEnterKey=event=>event.key==='Enter'; context.purchaseMergeLine=()=>false;
  vm.runInContext(actual('purchaseGridKey'),context);
  function key(name,input) {
    let prevented=false, stopped=false;
    context.purchaseGridKey({key:name,preventDefault:()=>{prevented=true;},stopPropagation:()=>{stopped=true;}},input);
    assert(prevented); assert(stopped);
  }
  key('Enter',rows[0].inputs['.line-qty']); assert.equal(focused,'2.line-qty');
  key('ArrowDown',rows[0].inputs['.line-cost']); assert.equal(focused,'3.line-cost');
  key('ArrowLeft',rows[0].inputs['.line-qty']); assert.equal(focused,'0.line-cost');
  key('ArrowRight',rows[0].inputs['.line-cost']); assert.equal(focused,'0.line-qty');
  focused=''; key('ArrowDown',rows[3].inputs['.line-qty']); assert.equal(focused,'');
  console.log('نجح: Enter في نفس العمود، تخطي الصف المخفي والخانة الناقصة، أسهم RTL، ومنع تغيير number عند آخر صف');

  const controls={purchaseDocId:{value:'42'},purchaseType:{value:'purchase_return'},purchaseBranch:{value:'6'},purchaseDate:{value:'2026-11-01'},purchaseRef:{value:''},purchaseOrigin:{value:''},purchaseNotes:{value:''}};
  context.pe=id=>controls[id];context.purchaseResolveSupplier=()=>1;context.purchaseDiscount=()=>null;context.purchaseRows=()=>[];context.purchaseRemoved=[];
  vm.runInContext(actual('purchasePayload'),context);
  assert.equal(context.purchasePayload('save').doc_type,'purchase');
  console.log('نجح: الحفظ بياخد النوع من المستند حتى لو قيمة الخانة المخفية اتغيّرت');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
