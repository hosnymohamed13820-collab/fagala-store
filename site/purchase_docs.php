<?php
/** purchase_docs.php — شاشة فاتورة شراء أو مرتجع مورد كاملة. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';

require_login();
require_module_access('purchase_docs');
sens_page();

$owner = is_real_owner();
$userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
$initialId = max(0, (int) ($_GET['id'] ?? 0));
$initialType = ($_GET['type'] ?? '') === 'purchase_return' ? 'purchase_return' : 'purchase';
$branchMissing = !$owner && $userBranch <= 0;

$pageTitle = $initialType === 'purchase_return' ? 'مرتجع مورد' : 'فاتورة شراء';
$active_module = 'purchase_docs';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.purchase-shell{min-height:calc(100vh - 130px);display:flex;flex-direction:column;gap:12px}
.purchase-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.purchase-title{display:flex;align-items:center;gap:10px}.purchase-title h2{margin:0;font-size:21px}.purchase-title-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.purchase-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.purchase-actions a{text-decoration:none}
.purchase-head-grid{display:grid;grid-template-columns:minmax(150px,.8fr) minmax(220px,1.5fr) minmax(150px,.8fr) minmax(145px,.75fr);gap:10px;align-items:end}
.purchase-head-grid .purchase-notes{grid-column:span 2}.purchase-head-grid .finput,.purchase-head-grid .fselect{width:100%}
.purchase-lines-panel{flex:1;display:flex;flex-direction:column;min-height:360px;padding:0;overflow:hidden}
.purchase-lines-title{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--line-soft)}
.purchase-lines-title h3{margin:0;font-size:16px}.purchase-barcode-hint{font-size:12px;color:var(--ink-500)}
.purchase-table-wrap{overflow:auto;flex:1}.purchase-table{min-width:800px}.purchase-table th,.purchase-table td{vertical-align:middle}.purchase-table .line-index{width:45px;text-align:center;color:var(--ink-500)}
.purchase-table .line-code-cell{width:190px}.purchase-table .line-name-cell{min-width:250px}.purchase-table .line-unit{width:95px}.purchase-table .line-number{width:125px}.purchase-table .line-remove-cell{width:60px;text-align:center}
.purchase-code-wrap{display:flex;align-items:center;gap:5px}.purchase-code-wrap .finput{min-width:0;width:100%}.purchase-code-wrap .btn{flex:0 0 auto}
.purchase-table .finput{width:100%;min-width:0}.purchase-table .line-name{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:360px}.purchase-table .line-total{font-variant-numeric:tabular-nums;font-weight:700}
.purchase-summary{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:20px;align-items:center;flex-wrap:wrap;padding:12px 16px;background:var(--surface);border-top:1px solid var(--line-soft);font-size:13px}.purchase-summary strong{font-size:18px;font-variant-numeric:tabular-nums}
.purchase-save-state{min-width:90px;font-size:12px;color:var(--ink-500)}.purchase-save-state.is-ok{color:var(--pos)}.purchase-save-state.is-bad{color:var(--neg)}
.purchase-origin[hidden],.purchase-event-list[hidden]{display:none}.purchase-event-list{font-size:12px;line-height:1.9;color:var(--ink-500)}
.purchase-readonly-note{margin:0}
@media(max-width:900px){.purchase-head-grid{grid-template-columns:1fr 1fr}.purchase-head-grid .purchase-notes{grid-column:span 2}}
@media(max-width:600px){.purchase-head-grid{grid-template-columns:1fr}.purchase-head-grid .purchase-notes{grid-column:auto}.purchase-summary{justify-content:space-between;gap:10px}.purchase-title h2{font-size:18px}}
@media print{
  @page{size:A4;margin:12mm}
  header,aside,.sidebar,footer,.purchase-no-print,.purchase-save-state{display:none!important}
  body,main,.content{background:var(--surface)!important;margin:0!important;padding:0!important;width:100%!important}
  .purchase-shell{min-height:0;display:block}.card{box-shadow:none!important;border:0!important;padding:0!important}
  .purchase-head-grid{grid-template-columns:repeat(3,1fr);margin:12px 0}.purchase-head-grid .finput,.purchase-head-grid .fselect{border:0!important;padding:2px!important;appearance:none}
  .purchase-lines-panel{min-height:0;overflow:visible}.purchase-table-wrap{overflow:visible}.purchase-table{min-width:0;width:100%;font-size:11px}.purchase-table .finput{border:0;padding:2px;background:transparent}.purchase-table .line-remove-cell{display:none}.purchase-summary{position:static;border-top:1px solid var(--line);padding:10px 0}
}
</style>

<div class="purchase-shell">
  <div class="fcard">
    <div class="purchase-head">
      <div class="purchase-title">
        <i class="ti ti-package-import"></i>
        <h2 id="purchaseTitle"><?= $initialType === 'purchase_return' ? 'مرتجع مورد جديد' : 'فاتورة شراء جديدة' ?></h2>
        <div class="purchase-title-meta">
          <span id="purchaseDocNo" class="fchip fchip-mute">جديدة</span>
          <span id="purchaseStatus" class="fchip fchip-warn">مسودة</span>
        </div>
      </div>
      <div class="purchase-actions purchase-no-print">
        <span id="purchaseSaveState" class="purchase-save-state"></span>
        <?php if (can('purchase_docs:post')): ?><button id="purchasePost" class="btn btn-success" type="button">ترحيل</button><?php endif; ?>
        <?php if (can('purchase_docs:cancel')): ?><button id="purchaseCancel" class="btn btn-danger" type="button" hidden>إلغاء</button><?php endif; ?>
        <button id="purchasePrint" class="btn btn-outline" type="button"><i class="ti ti-printer"></i> طباعة</button>
        <?php if (can('purchase_docs:create')): ?><button id="purchaseNew" class="btn btn-primary" type="button"><i class="ti ti-plus"></i> فاتورة جديدة</button><?php endif; ?>
        <?php if (can('purchase_docs:reports')): ?><a class="btn btn-outline" href="reports.php?type=purchase_docs">كل الفواتير ← التقارير</a><?php endif; ?>
      </div>
    </div>

    <?php if ($branchMissing): ?><div class="alert alert-danger purchase-readonly-note">حسابك مش مربوط بفرع، مش هينفع تحفظ المستند.</div><?php endif; ?>
    <div id="purchaseReadonlyNote" class="alert alert-info purchase-readonly-note" hidden>المستند ده للقراءة بس.</div>
    <div id="purchaseMessage"></div>

    <input id="purchaseDocId" type="hidden" value="<?= $initialId ?>">
    <input id="purchaseSupplierId" type="hidden">
    <div class="fgrid purchase-head-grid">
      <label class="ffield">النوع
        <select class="fselect" id="purchaseType" data-edit>
          <option value="purchase" <?= $initialType === 'purchase' ? 'selected' : '' ?>>شراء</option>
          <option value="purchase_return" <?= $initialType === 'purchase_return' ? 'selected' : '' ?>>مرتجع مورد</option>
        </select>
      </label>
      <label class="ffield">المورد (بحث)
        <input class="finput" id="purchaseSupplier" list="purchaseSupplierList" autocomplete="off" placeholder="اكتب اسم المورد" data-edit>
        <datalist id="purchaseSupplierList"></datalist>
      </label>
      <label class="ffield">الفرع
        <select class="fselect" id="purchaseBranch" data-edit></select>
      </label>
      <label class="ffield">التاريخ
        <input class="finput" id="purchaseDate" type="date" value="<?= date('Y-m-d') ?>" data-edit>
      </label>
      <label class="ffield">رقم فاتورة المورد
        <input class="finput" id="purchaseRef" maxlength="100" data-edit>
      </label>
      <label id="purchaseOriginWrap" class="ffield purchase-origin" hidden>مرتجع على فاتورة
        <select class="fselect" id="purchaseOrigin" data-edit><option value="">مرتجع من غير أصل</option></select>
      </label>
      <label class="ffield purchase-notes">ملاحظات
        <input class="finput" id="purchaseNotes" maxlength="1000" data-edit>
      </label>
    </div>
  </div>

  <div class="fcard purchase-lines-panel">
    <div class="purchase-lines-title">
      <h3>الأصناف</h3>
      <span class="purchase-barcode-hint purchase-no-print">اكتب أو امسح الباركود واضغط Enter</span>
    </div>
    <div class="purchase-table-wrap">
      <table class="ftable purchase-table">
        <thead><tr><th>#</th><th>الكود</th><th>الاسم</th><th>الوحدة</th><th class="num">الكمية</th><th class="num">التكلفة</th><th class="num">الإجمالي</th><th class="purchase-no-print"></th></tr></thead>
        <tbody id="purchaseLines"></tbody>
      </table>
    </div>
    <div class="purchase-summary">
      <span>عدد الأصناف <strong id="purchaseItemsCount">0</strong></span>
      <span>إجمالي القطع <strong id="purchasePieces">0</strong></span>
      <span>الإجمالي <strong id="purchaseGrand">0.00</strong></span>
    </div>
  </div>

  <div id="purchaseEvents" class="fcard purchase-event-list" hidden></div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
const PURCHASE_CSRF=<?= json_encode(csrf_token()) ?>;
const PURCHASE_INITIAL_ID=<?= $initialId ?>;
const PURCHASE_INITIAL_TYPE=<?= json_encode($initialType) ?>;
const PURCHASE_OWNER=<?= $owner ? 'true' : 'false' ?>;
const PURCHASE_CAN_CREATE=<?= can('purchase_docs:create') ? 'true' : 'false' ?>;
let purchaseOptions={branches:[],suppliers:[]};
let purchaseLoading=true;
let purchaseReadonly=false;
let purchaseLineSeq=0;
let purchaseSaveTimer=null;
let purchaseSaving=null;
let purchaseSaveDirty=false;
const pe=id=>document.getElementById(id);

function purchaseEsc(value){return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]))}
function purchaseMessage(text,bad=false){const box=pe('purchaseMessage');box.textContent='';if(!text)return;const div=document.createElement('div');div.className='alert '+(bad?'alert-danger':'alert-success');div.textContent=text;box.appendChild(div)}
function purchaseSaveState(text,kind=''){const el=pe('purchaseSaveState');el.textContent=text;el.className='purchase-save-state'+(kind?' is-'+kind:'')}
async function purchaseApi(url,options={}){const response=await fetch(url,options);const data=await response.json().catch(()=>({ok:false,error:'رد السيرفر مش مفهوم'}));if(!response.ok||!data.ok)throw new Error(data.error||'حصل خطأ');return data.data}
function purchaseNumber(value,digits=3){const number=Number(value)||0;return number.toLocaleString('en-US',{maximumFractionDigits:digits})}
function purchaseSupplierLabel(supplier){return String(supplier.name||'')+' — #'+Number(supplier.id)}
function purchaseResolveSupplier(){const value=pe('purchaseSupplier').value.trim();const supplier=purchaseOptions.suppliers.find(row=>purchaseSupplierLabel(row)===value);pe('purchaseSupplierId').value=supplier?String(supplier.id):'';return supplier?Number(supplier.id):0}
function purchaseSetSupplier(id){const supplier=purchaseOptions.suppliers.find(row=>Number(row.id)===Number(id));pe('purchaseSupplierId').value=supplier?String(supplier.id):'';pe('purchaseSupplier').value=supplier?purchaseSupplierLabel(supplier):''}

async function purchaseLoadOptions(){
  const data=await purchaseApi('purchase_docs_api.php?action=options');
  purchaseOptions=data;
  pe('purchaseSupplierList').innerHTML=data.suppliers.map(s=>'<option value="'+purchaseEsc(purchaseSupplierLabel(s))+'"></option>').join('');
  pe('purchaseBranch').innerHTML=data.branches.map(b=>'<option value="'+Number(b.id)+'">'+purchaseEsc(b.name)+'</option>').join('');
  if(!PURCHASE_OWNER&&data.user_branch)pe('purchaseBranch').value=String(data.user_branch);
}

function purchaseUnitText(factor){const value=Number(factor)||1;return value===1?'قطعة':'وحدة × '+purchaseNumber(value)}
function purchaseLineTemplate(row={}){
  const id=++purchaseLineSeq;
  const itemId=Number(row.item_id)||0;
  const factor=Number(row.unit_factor)||1;
  const qty=row.qty_units===undefined?1:Number(row.qty_units);
  const price=row.unit_price===undefined?0:Number(row.unit_price);
  const discount=Number(row.discount_pct)||0;
  return '<tr class="purchase-line" data-line="'+id+'" data-lookup-code="'+purchaseEsc(itemId?row.code||'':'')+'">'
    +'<td class="line-index"></td>'
    +'<td class="line-code-cell"><div class="purchase-code-wrap"><input id="purchaseCode'+id+'" class="finput finput-sm line-code" value="'+purchaseEsc(row.code||'')+'" autocomplete="off" inputmode="text"><button class="btn btn-sm btn-outline purchase-no-print line-scan" type="button" aria-label="فتح السكانر"><i class="ti ti-camera"></i></button></div><input class="line-item" type="hidden" value="'+itemId+'"><input class="line-factor" type="hidden" value="'+factor+'"><input class="line-discount" type="hidden" value="'+discount+'"></td>'
    +'<td class="line-name-cell"><span class="line-name" title="'+purchaseEsc(row.item_name||'')+'">'+purchaseEsc(row.item_name||'')+'</span></td>'
    +'<td><span class="line-unit">'+(itemId?purchaseEsc(purchaseUnitText(factor)):'—')+'</span></td>'
    +'<td class="num line-number"><input class="finput finput-sm num line-qty" type="number" min="0.001" step="0.001" value="'+purchaseEsc(qty)+'"></td>'
    +'<td class="num line-number"><input class="finput finput-sm num line-cost" type="number" min="0" step="0.0001" value="'+purchaseEsc(price)+'"></td>'
    +'<td class="num line-number"><span class="line-total">0.00</span></td>'
    +'<td class="line-remove-cell purchase-no-print"><button class="btn btn-sm btn-danger line-remove" type="button" aria-label="حذف السطر"><i class="ti ti-trash"></i></button></td></tr>';
}
function purchaseAddLine(row={},focus=false){
  pe('purchaseLines').insertAdjacentHTML('beforeend',purchaseLineTemplate(row));
  const line=pe('purchaseLines').lastElementChild;
  purchaseBindLine(line);
  purchaseCalc();
  if(focus)line.querySelector('.line-code').focus();
  return line;
}
function purchaseBindLine(line){
  const code=line.querySelector('.line-code');
  code.addEventListener('keydown',event=>{if(isEnterKey(event)){event.preventDefault();purchaseLookup(line)}});
  code.addEventListener('blur',()=>purchaseLookup(line));
  line.querySelector('.line-scan').addEventListener('click',()=>openBarcodeScanner(code.id));
  line.querySelectorAll('.line-qty,.line-cost').forEach(input=>{
    input.addEventListener('input',()=>{purchaseCalc();purchaseQueueSave()});
    input.addEventListener('blur',()=>purchaseQueueSave(true));
    input.addEventListener('keydown',event=>purchaseGridKey(event,input));
  });
  line.querySelector('.line-remove').addEventListener('click',()=>{line.remove();purchaseEnsureBlank();purchaseCalc();purchaseQueueSave(true)});
}
function purchaseBoundLines(){return [...pe('purchaseLines').querySelectorAll('.purchase-line')].filter(line=>Number(line.querySelector('.line-item').value)>0)}
function purchaseEnsureBlank(){
  if(purchaseReadonly)return;
  const lines=[...pe('purchaseLines').querySelectorAll('.purchase-line')];
  const blanks=lines.filter(line=>!Number(line.querySelector('.line-item').value));
  blanks.slice(1).forEach(line=>line.remove());
  if(!blanks.length)purchaseAddLine();
  purchaseRenumber();
}
function purchaseRenumber(){[...pe('purchaseLines').children].forEach((line,index)=>{line.querySelector('.line-index').textContent=String(index+1)})}
function purchaseFocusBarcode(){const blank=[...pe('purchaseLines').children].find(line=>!Number(line.querySelector('.line-item').value));blank?.querySelector('.line-code').focus()}

async function purchaseLookup(line){
  if(purchaseReadonly||!line.isConnected)return;
  const input=line.querySelector('.line-code');
  const code=input.value.trim();
  if(!code)return;
  if(line.dataset.lookupCode===code&&Number(line.querySelector('.line-item').value)>0)return;
  try{
    const supplierId=purchaseResolveSupplier();
    const data=await purchaseApi('purchase_docs_api.php?action=code&code='+encodeURIComponent(code)+'&supplier_id='+supplierId);
    const foundCode=String(data.item.code||code);
    const duplicate=purchaseBoundLines().find(other=>other!==line&&other.querySelector('.line-code').value.trim()===foundCode);
    if(duplicate){
      const qty=duplicate.querySelector('.line-qty');
      qty.value=String((Number(qty.value)||0)+(Number(line.querySelector('.line-qty').value)||1));
      line.remove();
    }else{
      line.dataset.lookupCode=String(data.item.code||code);
      input.value=String(data.item.code||code);
      line.querySelector('.line-item').value=String(Number(data.item.item_id));
      line.querySelector('.line-factor').value=String(Number(data.item.unit_factor)||1);
      line.querySelector('.line-name').textContent=String(data.item.name||data.item.code||'');
      line.querySelector('.line-name').title=String(data.item.name||data.item.code||'');
      line.querySelector('.line-unit').textContent=purchaseUnitText(data.item.unit_factor);
      if(Number(line.querySelector('.line-cost').value)===0&&data.item.last_purchase_price!==null)line.querySelector('.line-cost').value=String(data.item.last_purchase_price);
    }
    purchaseEnsureBlank();purchaseCalc();purchaseQueueSave();purchaseFocusBarcode();purchaseMessage('');
  }catch(error){purchaseMessage(error.message,true);input.focus();input.select()}
}

function purchaseGridKey(event,input){
  const enter=isEnterKey(event);
  if(!enter&&!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key))return;
  const line=input.closest('.purchase-line');
  const rows=[...pe('purchaseLines').querySelectorAll('.purchase-line')];
  const columns=['.line-code','.line-qty','.line-cost'];
  const column=columns.findIndex(selector=>input.matches(selector));
  const row=rows.indexOf(line);
  let target=null;
  if(event.key==='ArrowUp'&&row>0)target=rows[row-1].querySelector(columns[column]);
  else if(event.key==='ArrowDown'&&row<rows.length-1)target=rows[row+1].querySelector(columns[column]);
  else if(event.key==='ArrowLeft'&&column<columns.length-1)target=line.querySelector(columns[column+1]);
  else if(event.key==='ArrowRight'&&column>0)target=line.querySelector(columns[column-1]);
  else if(enter){target=column<columns.length-1?line.querySelector(columns[column+1]):rows[row+1]?.querySelector('.line-code')}
  if(target){event.preventDefault();target.focus();target.select()}
}

function purchaseRows(){return purchaseBoundLines().map(line=>({
  code:line.querySelector('.line-code').value.trim(),
  item_id:Number(line.querySelector('.line-item').value),
  unit_factor:Number(line.querySelector('.line-factor').value),
  qty_units:Number(line.querySelector('.line-qty').value),
  unit_price:Number(line.querySelector('.line-cost').value),
  discount_pct:Number(line.querySelector('.line-discount').value)||0
}))}
function purchaseCalc(){
  let grand=0,pieces=0;
  purchaseBoundLines().forEach(line=>{
    const qty=Number(line.querySelector('.line-qty').value)||0;
    const cost=Number(line.querySelector('.line-cost').value)||0;
    const discount=Number(line.querySelector('.line-discount').value)||0;
    const total=Math.round(qty*cost*(1-discount/100)*100)/100;
    pieces+=qty*(Number(line.querySelector('.line-factor').value)||1);grand+=total;
    line.querySelector('.line-total').textContent=total.toFixed(2);
  });
  pe('purchaseItemsCount').textContent=String(purchaseBoundLines().length);
  pe('purchasePieces').textContent=purchaseNumber(pieces);
  pe('purchaseGrand').textContent=grand.toFixed(2);
  purchaseRenumber();
}
function purchasePayload(action){return {action,doc_id:Number(pe('purchaseDocId').value)||null,doc_type:pe('purchaseType').value,supplier_id:purchaseResolveSupplier(),branch_id:Number(pe('purchaseBranch').value),doc_date:pe('purchaseDate').value,ref_text:pe('purchaseRef').value.trim(),orig_doc_id:Number(pe('purchaseOrigin').value)||null,notes:pe('purchaseNotes').value.trim(),lines:purchaseRows()}}
function purchaseQueueSave(immediate=false){
  if(purchaseLoading||purchaseReadonly)return;
  purchaseSaveDirty=true;clearTimeout(purchaseSaveTimer);
  purchaseSaveState('فيه تغييرات…');
  purchaseSaveTimer=setTimeout(()=>purchaseSaveNow(false),immediate?0:800);
}
async function purchaseSaveNow(force=false){
  clearTimeout(purchaseSaveTimer);
  if(purchaseReadonly)return;
  if(purchaseSaving){purchaseSaveDirty=true;await purchaseSaving;if(force||purchaseSaveDirty)return purchaseSaveNow(force);return}
  if(!purchaseSaveDirty&&!force)return;
  const payload=purchasePayload('save');
  if(!payload.supplier_id||!payload.branch_id||!payload.lines.length){if(force)throw new Error('كمّل المورد والفرع والأصناف الأول');return}
  purchaseSaveDirty=false;purchaseSaveState('بيتحفظ…');
  purchaseSaving=purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify(payload)});
  let failed=false;
  try{
    const data=await purchaseSaving;
    pe('purchaseDocId').value=String(data.doc_id);
    pe('purchaseDocNo').textContent='مسودة-'+data.doc_id;
    pe('purchaseType').disabled=true;
    history.replaceState(null,'','purchase_docs.php?id='+data.doc_id);
    purchaseSaveState('اتحفظ ✓','ok');
  }catch(error){failed=true;purchaseSaveDirty=true;purchaseSaveState('الحفظ وقف','bad');purchaseMessage(error.message,true);if(force)throw error}
  finally{purchaseSaving=null}
  if(purchaseSaveDirty&&!failed)return purchaseSaveNow(force);
}

async function purchaseLoadOrigins(selected=0){
  const wrap=pe('purchaseOriginWrap');
  const isReturn=pe('purchaseType').value==='purchase_return';
  wrap.hidden=!isReturn;
  if(!isReturn){pe('purchaseOrigin').value='';return}
  const supplierId=purchaseResolveSupplier();
  const branchId=Number(pe('purchaseBranch').value);
  if(!supplierId||!branchId){pe('purchaseOrigin').innerHTML='<option value="">مرتجع من غير أصل</option>';return}
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=origins&branch_id='+branchId+'&supplier_id='+supplierId);
    pe('purchaseOrigin').innerHTML='<option value="">مرتجع من غير أصل</option>'+data.origins.map(origin=>'<option value="'+Number(origin.id)+'">'+purchaseEsc(origin.doc_year+'/'+origin.doc_no+' — '+(origin.ref_text||'من غير رقم'))+'</option>').join('');
    if(selected){
      if(![...pe('purchaseOrigin').options].some(option=>Number(option.value)===selected))pe('purchaseOrigin').insertAdjacentHTML('beforeend','<option value="'+selected+'">فاتورة الأصل #'+selected+'</option>');
      pe('purchaseOrigin').value=String(selected);
    }
  }catch(error){purchaseMessage(error.message,true)}
}
async function purchaseFillOrigin(){
  const id=Number(pe('purchaseOrigin').value);if(!id)return purchaseQueueSave(true);
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id='+id);
    purchaseLoading=true;pe('purchaseLines').textContent='';
    data.lines.forEach(line=>purchaseAddLine({code:line.code,item_id:line.item_id,item_name:line.item_name,unit_factor:line.unit_factor,qty_units:Number(line.available_pieces)/Number(line.unit_factor||1),unit_price:line.unit_price,discount_pct:0}));
    purchaseLoading=false;purchaseEnsureBlank();purchaseCalc();purchaseQueueSave(true);purchaseFocusBarcode();
  }catch(error){purchaseLoading=false;purchaseMessage(error.message,true)}
}

function purchaseStatus(status){
  const chip=pe('purchaseStatus');
  chip.textContent=status==='posted'?'مترحلة':(status==='cancelled'?'ملغية':'مسودة');
  chip.className='fchip '+(status==='posted'?'fchip-pos':(status==='cancelled'?'fchip-neg':'fchip-warn'));
}
function purchaseSetReadonly(readonly,status='draft'){
  purchaseReadonly=readonly;
  pe('purchaseReadonlyNote').hidden=!readonly;
  document.querySelectorAll('[data-edit],.purchase-line input,.purchase-line button').forEach(control=>{control.disabled=readonly});
  if(!PURCHASE_OWNER)pe('purchaseBranch').disabled=true;
  pe('purchasePost')?.toggleAttribute('hidden',readonly);
  pe('purchaseCancel')?.toggleAttribute('hidden',status!=='posted');
}
function purchaseRenderEvents(events){
  const box=pe('purchaseEvents');
  if(!events.length){box.hidden=true;box.textContent='';return}
  box.hidden=false;
  box.innerHTML='<strong>سجل المستند</strong>'+events.map(event=>'<div>'+purchaseEsc(event.at)+' — '+purchaseEsc(event.event)+' — '+purchaseEsc(event.user_name||'النظام')+'</div>').join('');
}
async function purchaseOpen(id){
  const data=await purchaseApi('purchase_docs_api.php?action=doc&id='+id);
  const doc=data.doc;
  pe('purchaseDocId').value=String(doc.id);pe('purchaseType').value=doc.doc_type;purchaseSetSupplier(doc.supplier_id);pe('purchaseBranch').value=String(doc.branch_id);pe('purchaseDate').value=doc.doc_date;pe('purchaseRef').value=doc.ref_text||'';pe('purchaseNotes').value=doc.notes||'';
  pe('purchaseLines').textContent='';data.lines.forEach(line=>purchaseAddLine(line));
  await purchaseLoadOrigins(Number(doc.orig_doc_id)||0);
  const label=doc.doc_type==='purchase'?'فاتورة شراء':'مرتجع مورد';
  pe('purchaseTitle').textContent=label;pe('purchaseDocNo').textContent=doc.doc_no?doc.doc_year+'/'+doc.doc_no:'مسودة-'+doc.id;purchaseStatus(doc.status);purchaseRenderEvents(data.events||[]);
  const readonly=doc.status!=='draft'||!PURCHASE_CAN_CREATE;purchaseSetReadonly(readonly,doc.status);pe('purchaseType').disabled=true;if(!readonly)purchaseEnsureBlank();purchaseCalc();
}
function purchaseNew(){location.href='purchase_docs.php'}
async function purchasePostDoc(){
  try{await purchaseSaveNow(true);const id=Number(pe('purchaseDocId').value);if(!id)return;if(!confirm('متأكد إنك عايز ترحّل المستند؟'))return;await purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify({action:'post',doc_id:id})});location.href='purchase_docs.php?id='+id}catch(error){purchaseMessage(error.message,true)}
}
async function purchaseCancelDoc(){
  const reason=prompt('اكتب سبب الإلغاء');if(!reason||!reason.trim())return;
  try{const id=Number(pe('purchaseDocId').value);await purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify({action:'cancel',doc_id:id,reason:reason.trim()})});location.href='purchase_docs.php?id='+id}catch(error){purchaseMessage(error.message,true)}
}

async function purchaseInit(){
  try{
    await purchaseLoadOptions();
    if(PURCHASE_INITIAL_ID){await purchaseOpen(PURCHASE_INITIAL_ID)}else{
      pe('purchaseType').value=PURCHASE_INITIAL_TYPE;purchaseStatus('draft');await purchaseLoadOrigins();purchaseEnsureBlank();purchaseSetReadonly(!PURCHASE_CAN_CREATE,'draft');purchaseFocusBarcode();
    }
  }catch(error){purchaseMessage(error.message,true);purchaseSetReadonly(true,'draft')}
  finally{purchaseLoading=false}
}

['purchaseRef','purchaseNotes','purchaseDate'].forEach(id=>{pe(id).addEventListener('input',()=>purchaseQueueSave());pe(id).addEventListener('blur',()=>purchaseQueueSave(true))});
pe('purchaseSupplier').addEventListener('input',()=>{purchaseResolveSupplier();purchaseQueueSave()});
pe('purchaseSupplier').addEventListener('change',()=>{purchaseResolveSupplier();purchaseLoadOrigins();purchaseQueueSave(true)});
pe('purchaseBranch').addEventListener('change',()=>{purchaseLoadOrigins();purchaseQueueSave(true)});
pe('purchaseType').addEventListener('change',()=>{purchaseLoadOrigins();purchaseQueueSave(true)});
pe('purchaseOrigin').addEventListener('change',purchaseFillOrigin);
pe('purchasePost')?.addEventListener('click',purchasePostDoc);
pe('purchaseCancel')?.addEventListener('click',purchaseCancelDoc);
pe('purchasePrint').addEventListener('click',()=>window.print());
pe('purchaseNew')?.addEventListener('click',purchaseNew);
purchaseInit();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
