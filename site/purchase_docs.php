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
.purchase-table-wrap{overflow:auto;flex:1;min-width:0}.purchase-shell,.purchase-lines-panel{min-width:0}
.purchase-table{width:100%;table-layout:fixed}.purchase-table th,.purchase-table td{vertical-align:middle}.purchase-table .line-index{text-align:center;color:var(--ink-500)}
.purchase-code-wrap{display:flex;align-items:center;gap:2px}.purchase-code-wrap .finput{min-width:0;width:100%}.purchase-code-wrap .btn{flex:0 0 auto}
.purchase-table .finput{width:100%;min-width:0}.purchase-table .line-name,.purchase-table .line-code-text{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.purchase-table .line-total{font-variant-numeric:tabular-nums;font-weight:700}
.purchase-summary{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--line-soft);font-size:13px}.purchase-summary strong{font-size:18px;font-variant-numeric:tabular-nums}.purchase-summary .ffield{width:125px}
.purchase-shortcuts{display:flex;gap:12px;flex-wrap:wrap;padding:8px 16px;font-size:12px;color:var(--ink-500)}.purchase-shortcuts kbd{font:inherit;font-weight:700}
.purchase-search-bg[hidden]{display:none}.purchase-search-bg .fg-dlg{width:min(92vw,720px)}.purchase-search-results{max-height:50vh;overflow:auto}.purchase-search-results .btn{display:block;width:100%;text-align:right;white-space:normal}
@media(max-width:1100px){.purchase-table{min-width:1020px}}
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
    <div class="ftable-wrap purchase-table-wrap">
      <table class="ftable purchase-table">
        <colgroup><col style="width:30px"><col style="width:120px"><col><col style="width:74px"><col style="width:65px"><col style="width:72px"><col style="width:80px"><col style="width:62px"><col style="width:90px"><col style="width:86px"><col style="width:76px"></colgroup>
        <thead><tr><th>#</th><th>الكود</th><th>الاسم</th><th>الوحدة</th><th class="num">الرصيد</th><th class="num">الكمية</th><th class="num">التكلفة</th><th class="num">خصم %</th><th class="num">صافي سعر التكلفة</th><th class="num">الإجمالي</th><th class="purchase-no-print"></th></tr></thead>
        <tbody id="purchaseLines"></tbody>
      </table>
    </div>
    <div class="purchase-shortcuts purchase-no-print"><span><kbd>Insert / Ctrl+N</kbd> الصف الفاضي</span><span><kbd>Ctrl+Delete</kbd> مسح الصف</span><span><kbd>F2</kbd> بحث صنف</span><span><kbd>Esc</kbd> قفل البحث</span></div>
    <div class="purchase-summary">
      <span>عدد الأصناف <strong id="purchaseItemsCount">0</strong></span>
      <span>إجمالي القطع <strong id="purchasePieces">0</strong></span>
      <span>الإجمالي قبل الخصم <strong id="purchaseGross">0.00</strong></span>
      <label class="ffield">خصم الفاتورة % <input id="purchaseDiscountPct" class="finput finput-sm num" type="number" min="0" max="100" step="0.0001" data-edit></label>
      <label class="ffield">خصم الفاتورة قيمة <input id="purchaseDiscountAmount" class="finput finput-sm num" type="number" min="0" step="0.0001" data-edit></label>
      <div class="fkpi"><span>الصافي</span><strong id="purchaseGrand">0.00</strong></div>
    </div>
  </div>

  <div id="purchaseEvents" class="fcard purchase-event-list" hidden></div>
</div>

<div id="purchaseSearch" class="purchase-search-bg purchase-no-print" hidden>
  <div class="fg-dlg" role="dialog" aria-modal="true" aria-labelledby="purchaseSearchTitle">
    <h3 id="purchaseSearchTitle" class="fg-dlg-title">بحث الأصناف</h3>
    <label class="ffield">اسم الصنف<input id="purchaseSearchTerm" class="finput" autocomplete="off"></label>
    <div id="purchaseSearchResults" class="purchase-search-results"></div>
    <div class="fg-dlg-actions"><button id="purchaseSearchClose" class="btn btn-secondary" type="button">رجوع</button></div>
  </div>
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
let purchaseDiscountType=null;
let purchaseOriginCosts={};
let purchaseRemoved=[];
let purchaseSearchLine=null,purchaseSearchItems=[],purchaseSearchIndex=0,purchaseSearchRequest=0,purchaseBalanceRequest=0;
let purchaseSearchTimer=null,purchaseBalanceTimer=null;
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
  return '<tr class="purchase-line" data-line="'+id+'" data-lookup-code="'+purchaseEsc(itemId?row.code||'':'')+'">'
    +'<td class="line-index"></td>'
    +'<td class="fcell line-code-cell"><div class="purchase-code-wrap"><input id="purchaseCode'+id+'" class="finput finput-cell num line-code" value="'+purchaseEsc(row.code||'')+'" autocomplete="off" inputmode="text"><button class="btn btn-sm btn-outline purchase-no-print line-scan" type="button" aria-label="فتح السكانر"><i class="ti ti-camera"></i></button></div><input class="line-item" type="hidden" value="'+itemId+'"><input class="line-factor" type="hidden" value="'+factor+'"></td>'
    +'<td class="fcell line-name-cell">'+(itemId?'<span class="line-name" title="'+purchaseEsc(row.item_name||'')+'">'+purchaseEsc(row.item_name||'')+'</span>':'<input class="finput finput-cell line-name-input" autocomplete="off" placeholder="اكتب اسم الصنف…">')+'</td>'
    +purchaseLineDetails(row)+'</tr>';
}
function purchaseLineDetails(row={}){
  if(!Number(row.item_id))return '<td></td><td class="num line-balance"></td>'+ '<td></td>'.repeat(6);
  const factor=Number(row.unit_factor)||1;
  const qty=row.qty_units===undefined?1:Number(row.qty_units);
  const price=row.unit_price===undefined?0:Number(row.unit_price);
  const discount=Number(row.discount_pct)||0;
  return '<td><span class="line-unit">'+purchaseEsc(purchaseUnitText(factor))+'</span></td>'
    +'<td class="num line-balance">—</td>'
    +'<td class="num fcell line-number"><input class="finput finput-cell num line-qty" type="number" min="0.001" step="0.001" value="'+purchaseEsc(qty)+'"></td>'
    +'<td class="num fcell line-number"><input class="finput finput-cell num line-cost" type="number" min="0" step="0.0001" value="'+purchaseEsc(price)+'"></td>'
    +'<td class="num fcell line-number"><input class="finput finput-cell num line-discount" type="number" min="0" max="100" step="0.001" value="'+purchaseEsc(discount)+'"></td>'
    +'<td class="num line-number"><span class="line-net">0.00</span></td>'
    +'<td class="num line-number"><span class="line-total">0.00</span></td>'
    +'<td class="line-remove-cell purchase-no-print"><button class="btn btn-sm btn-danger line-remove" type="button" aria-label="حذف السطر"><i class="ti ti-trash"></i></button></td>';
}
function purchaseAddLine(row={},focus=false){
  pe('purchaseLines').insertAdjacentHTML('beforeend',purchaseLineTemplate(row));
  const line=pe('purchaseLines').lastElementChild;
  if(Number(row.item_id))purchaseLockCode(line);
  purchaseBindLine(line);
  purchaseQueueBalances();
  if(row.line_total!==undefined){line.dataset.savedTotal=String(row.line_total);line.dataset.savedNet=String(row.net_unit_cost)}
  purchaseCalc();
  if(focus)line.querySelector('.line-code,.line-qty').focus();
  return line;
}
function purchaseCode(line){return line.dataset.lookupCode||line.querySelector('.line-code')?.value.trim()||''}
function purchaseLockCode(line){
  const input=line.querySelector('.line-code');if(!input)return;
  const text=document.createElement('span');text.className='line-code-text num';text.textContent=purchaseCode(line);text.title=text.textContent;
  input.closest('.purchase-code-wrap').replaceWith(text);
}
function purchaseBindLine(line){
  const code=line.querySelector('.line-code');
  if(code){
    code.addEventListener('keydown',event=>{if(isEnterKey(event)){event.preventDefault();purchaseLookup(line,undefined,!event.isTrusted||code.dataset.scanFast==='1')}});
    code.addEventListener('input',event=>{
      code.classList.remove('is-invalid');
      const now=Number(event.timeStamp)||Date.now(),last=Number(code.dataset.scanLast||0);
      const count=now-last<80?Number(code.dataset.scanCount||0)+1:1;
      code.dataset.scanLast=String(now);code.dataset.scanCount=String(count);code.dataset.scanFast=count>=3&&code.value.length>=3?'1':'0';
    });
    line.querySelector('.line-scan').addEventListener('click',()=>openBarcodeScanner(code.id));
    line.querySelector('.line-name-input').addEventListener('keydown',event=>{if(isEnterKey(event)){event.preventDefault();purchaseLookupName(line)}});
  }
  line.querySelectorAll('.line-qty,.line-cost,.line-discount').forEach(input=>{
    input.addEventListener('input',()=>{purchaseCalc();purchaseQueueSave()});
    input.addEventListener('blur',()=>purchaseQueueSave(true));
    input.addEventListener('keydown',event=>purchaseGridKey(event,input));
  });
  line.querySelector('.line-remove')?.addEventListener('click',()=>purchaseRemoveLine(line));
}
async function purchaseRemoveLine(line){
  if(purchaseReadonly||!line?.isConnected)return;
  if(!await fgConfirm('متأكد إنك عايز تمسح الصف ده؟',{title:'مسح صف',okText:'مسح',cancelText:'رجوع',danger:true}))return;
  if(purchaseReadonly||!line.isConnected)return;
  if(Number(line.querySelector('.line-item').value))purchaseRemoved.push({code:purchaseCode(line),qty_units:Number(line.querySelector('.line-qty').value)});
  line.remove();purchaseEnsureBlank();purchaseCalc();purchaseQueueSave(true);purchaseFocusBarcode();
}
function purchaseMergeLine(line){
  const rows=purchaseBoundLines(),index=rows.indexOf(line);
  const old=rows.slice(0,index).find(other=>purchaseCode(other)===purchaseCode(line));
  if(!old)return false;
  old.querySelector('.line-qty').value=String(purchaseRound(Number(old.querySelector('.line-qty').value)+Number(line.querySelector('.line-qty').value),3));
  line.remove();purchaseEnsureBlank();purchaseCalc();purchaseQueueSave(true);purchaseFocusBarcode();return true;
}
function purchaseQueueBalances(){clearTimeout(purchaseBalanceTimer);purchaseBalanceTimer=setTimeout(purchaseBalances,60)}
async function purchaseBalances(){
  const request=++purchaseBalanceRequest,branch=Number(pe('purchaseBranch').value),codes=[...new Set(purchaseBoundLines().map(purchaseCode))];
  if(!branch||!codes.length)return;
  purchaseBoundLines().forEach(line=>line.querySelector('.line-balance').textContent='…');
  try{
    const query=new URLSearchParams({action:'balances',branch_id:branch});codes.forEach(code=>query.append('codes[]',code));
    const data=await purchaseApi('purchase_docs_api.php?'+query);
    if(request!==purchaseBalanceRequest||branch!==Number(pe('purchaseBranch').value))return;
    purchaseBoundLines().forEach(line=>{line.querySelector('.line-balance').textContent=data.balances[purchaseCode(line)]===undefined?'—':purchaseNumber(data.balances[purchaseCode(line)])});
  }catch(error){if(request===purchaseBalanceRequest){purchaseBoundLines().forEach(line=>line.querySelector('.line-balance').textContent='—');purchaseMessage(error.message,true)}}
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

async function purchaseLookup(line,chosenCode,scanner=false){
  if(purchaseReadonly||!line.isConnected||Number(line.querySelector('.line-item').value)||line.dataset.busy)return;
  const input=line.querySelector('.line-code'),code=chosenCode??input.value.trim();
  if(!code)return;
  line.dataset.busy='1';
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=code&code='+encodeURIComponent(code)+'&supplier_id='+purchaseResolveSupplier());
    if(!line.isConnected||purchaseReadonly)return;
    if(purchaseHasOrigin()&&purchaseOriginCosts[Number(data.item.item_id)]===undefined)throw new Error('الصنف مش موجود في فاتورة الأصل');
    const foundCode=String(data.item.code||code);
    const duplicate=purchaseBoundLines().find(other=>purchaseCode(other)===foundCode);
    if(scanner&&duplicate){duplicate.querySelector('.line-qty').value=String(purchaseRound(Number(duplicate.querySelector('.line-qty').value)+1,3));line.remove()}
    else{
      line.dataset.lookupCode=foundCode;
      line.querySelector('.line-item').value=String(Number(data.item.item_id));
      line.querySelector('.line-factor').value=String(Number(data.item.unit_factor)||1);
      line.querySelector('.line-name-cell').innerHTML='<span class="line-name" title="'+purchaseEsc(data.item.name||foundCode)+'">'+purchaseEsc(data.item.name||foundCode)+'</span>';
      while(line.cells.length>3)line.deleteCell(3);
      line.insertAdjacentHTML('beforeend',purchaseLineDetails({item_id:data.item.item_id,unit_factor:data.item.unit_factor}));
      if(Number(line.querySelector('.line-cost').value)===0&&data.item.last_purchase_price!==null)line.querySelector('.line-cost').value=String(data.item.last_purchase_price);
      purchaseLockCode(line);
      purchaseBindLine(line);
    }
    purchaseEnsureBlank();purchaseCalc();purchaseQueueBalances();purchaseQueueSave();purchaseMessage('');
    if(scanner){const blank=[...pe('purchaseLines').children].find(row=>!Number(row.querySelector('.line-item').value));const next=blank?.querySelector('.line-code');if(next){next.id=input.id;next.focus()}}else{const qty=line.querySelector('.line-qty');qty.focus();qty.select()}
  }catch(error){purchaseMessage(error.message,true);input.classList.add('is-invalid');input.focus();input.select()}
  finally{delete line.dataset.busy}
}
async function purchaseLookupName(line){
  const name=line.querySelector('.line-name-input')?.value.trim();if(!name)return;
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=code&name='+encodeURIComponent(name));
    if(data.item)await purchaseLookup(line,data.item.code);else purchaseOpenSearch(line,name);
  }catch(error){purchaseMessage(error.message,true)}
}
function purchaseOpenSearch(line,text=''){
  if(purchaseReadonly)return;
  purchaseSearchLine=line;pe('purchaseSearch').classList.add('fg-dlg-bg');pe('purchaseSearch').hidden=false;document.documentElement.classList.add('fg-dlg-open');
  pe('purchaseSearchTerm').value=text;pe('purchaseSearchTerm').focus();purchaseRunSearch();
}
function purchaseCloseSearch(){
  ++purchaseSearchRequest;clearTimeout(purchaseSearchTimer);pe('purchaseSearch').hidden=true;pe('purchaseSearch').classList.remove('fg-dlg-bg');document.documentElement.classList.remove('fg-dlg-open');
  if(purchaseSearchLine?.isConnected)(purchaseSearchLine.querySelector('.line-name-input')||purchaseSearchLine.querySelector('.line-qty')).focus();else purchaseFocusBarcode();
}
function purchaseRenderSearch(){
  pe('purchaseSearchResults').innerHTML=purchaseSearchItems.length?purchaseSearchItems.map((item,index)=>'<button type="button" class="btn '+(index===purchaseSearchIndex?'btn-primary':'btn-ghost')+'" data-result="'+index+'" aria-pressed="'+(index===purchaseSearchIndex)+'">'+purchaseEsc(item.code)+' — '+purchaseEsc(item.name)+'</button>').join(''):'<p>مفيش نتايج</p>';
  pe('purchaseSearchResults').querySelectorAll('[data-result]').forEach(button=>button.addEventListener('click',()=>purchaseChooseSearch(Number(button.dataset.result))));
}
async function purchaseRunSearch(){
  const request=++purchaseSearchRequest,term=pe('purchaseSearchTerm').value.trim();purchaseSearchItems=[];purchaseSearchIndex=0;
  if(!term){pe('purchaseSearchResults').textContent='اكتب اسم الصنف';return}
  pe('purchaseSearchResults').textContent='جاري البحث…';
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=code&q='+encodeURIComponent(term));
    if(request!==purchaseSearchRequest)return;
    purchaseSearchItems=data.items||[];purchaseRenderSearch();
  }catch(error){if(request===purchaseSearchRequest)pe('purchaseSearchResults').textContent=error.message}
}
async function purchaseChooseSearch(index){
  const item=purchaseSearchItems[index];if(!item)return;
  const line=purchaseSearchLine?.isConnected&&!Number(purchaseSearchLine.querySelector('.line-item').value)?purchaseSearchLine:[...pe('purchaseLines').children].find(row=>!Number(row.querySelector('.line-item').value));
  purchaseCloseSearch();if(line)await purchaseLookup(line,item.code);
}
pe('purchaseSearchClose').addEventListener('click',purchaseCloseSearch);
pe('purchaseSearchTerm').addEventListener('input',()=>{++purchaseSearchRequest;purchaseSearchItems=[];clearTimeout(purchaseSearchTimer);purchaseSearchTimer=setTimeout(purchaseRunSearch,250)});
pe('purchaseSearch').addEventListener('keydown',event=>{
  if(event.key==='Escape'){event.preventDefault();event.stopPropagation();purchaseCloseSearch()}
  else if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();purchaseSearchIndex=Math.max(0,Math.min(purchaseSearchItems.length-1,purchaseSearchIndex+(event.key==='ArrowDown'?1:-1)));purchaseRenderSearch();pe('purchaseSearchResults').children[purchaseSearchIndex]?.scrollIntoView({block:'nearest'})}
  else if(isEnterKey(event)&&event.target===pe('purchaseSearchTerm')){event.preventDefault();purchaseChooseSearch(purchaseSearchIndex)}
  else if(event.key==='Tab'){const controls=[...pe('purchaseSearch').querySelectorAll('input,button')],first=controls[0],last=controls.at(-1);if(event.shiftKey&&event.target===first){event.preventDefault();last.focus()}else if(!event.shiftKey&&event.target===last){event.preventDefault();first.focus()}}
});
document.addEventListener('keydown',event=>{
  if(purchaseReadonly||!pe('purchaseSearch').hidden||document.querySelector('.fg-dlg-bg:not([hidden]):not(.purchase-search-bg)'))return;
  const line=document.activeElement?.closest('.purchase-line');
  if(event.key==='Insert'||event.ctrlKey&&event.key.toLowerCase()==='n'){event.preventDefault();purchaseEnsureBlank();purchaseFocusBarcode()}
  else if(event.ctrlKey&&event.key==='Delete'&&line){event.preventDefault();purchaseRemoveLine(line)}
  else if(event.key==='F2'){event.preventDefault();purchaseOpenSearch(line)}
});
function purchaseGridKey(event,input){
  const enter=isEnterKey(event);
  if(!enter&&!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key))return;
  const line=input.closest('.purchase-line');
  if(enter&&input.matches('.line-qty')&&purchaseMergeLine(line)){event.preventDefault();return}
  const rows=[...pe('purchaseLines').querySelectorAll('.purchase-line')];
  const columns=['.line-code','.line-qty','.line-cost','.line-discount'];
  const column=columns.findIndex(selector=>input.matches(selector));
  const row=rows.indexOf(line);
  let target=null;
  if(event.key==='ArrowUp'&&row>0)target=rows[row-1].querySelector(columns[column]);
  else if(event.key==='ArrowDown'&&row<rows.length-1)target=rows[row+1].querySelector(columns[column]);
  else if(event.key==='ArrowLeft'&&column<columns.length-1)target=line.querySelector(columns[column+1]);
  else if(event.key==='ArrowRight'&&column>0)target=line.querySelector(columns[column-1]);
  else if(enter){target=column<columns.length-1?line.querySelector(columns[column+1]):rows[row+1]?.querySelector('.line-code,.line-qty')}
  if(target){event.preventDefault();target.focus();target.select()}
}

function purchaseRows(){return purchaseBoundLines().map(line=>({
  code:purchaseCode(line),
  item_id:Number(line.querySelector('.line-item').value),
  unit_factor:Number(line.querySelector('.line-factor').value),
  qty_units:Number(line.querySelector('.line-qty').value),
  unit_price:Number(line.querySelector('.line-cost').value),
  discount_pct:Number(line.querySelector('.line-discount').value)||0
}))}
function purchaseHasOrigin(){return pe('purchaseType').value==='purchase_return'&&Number(pe('purchaseOrigin').value)>0}
function purchaseDiscount(){return purchaseHasOrigin()||!purchaseDiscountType?null:{type:purchaseDiscountType,value:Number(pe(purchaseDiscountType==='percent'?'purchaseDiscountPct':'purchaseDiscountAmount').value)||0}}
function purchaseRound(value,digits){const scale=10**digits,scaled=value*scale;return Math.round(scaled+Number.EPSILON*Math.max(1,Math.abs(scaled)))/scale}
function purchaseNetText(value){return Number(value).toFixed(Number.isInteger(Number(value))?2:4)}
function purchaseCalc(){
  let grand=0,pieces=0,gross=0,largest=0;
  const bound=purchaseBoundLines(),locked=purchaseHasOrigin();
  const rows=bound.map((line,index)=>{
    const qty=purchaseRound(Number(line.querySelector('.line-qty').value)||0,3);
    const price=line.querySelector('.line-cost'),discountInput=line.querySelector('.line-discount');
    if(locked&&!purchaseReadonly){
      const cost=purchaseOriginCosts[Number(line.querySelector('.line-item').value)];
      if(cost!==undefined)price.value=String(purchaseRound(cost*Number(line.querySelector('.line-factor').value),4));
      discountInput.value='0';
    }
    price.readOnly=locked;discountInput.readOnly=locked;
    const cost=purchaseRound(Number(price.value)||0,4),discount=purchaseRound(Number(discountInput.value)||0,3);
    const after=Math.round(purchaseRound(qty*cost*(1-discount/100),2)*100);
    if(index===0||after>bound[largest]._after)largest=index;
    line._after=after;gross+=after;
    pieces+=purchaseRound(qty*Number(line.querySelector('.line-factor').value||1),3);
    return {line,qty,after,alloc:0};
  });
  pe('purchaseDiscountPct').disabled=purchaseReadonly||locked;pe('purchaseDiscountAmount').disabled=purchaseReadonly||locked;
  const discount=purchaseDiscount(),value=purchaseRound(discount?.value||0,4);
  const target=purchaseRound(discount?.type==='percent'?gross*value/100:value*100,0);
  let allocated=0;
  rows.forEach(row=>{row.alloc=purchaseRound(discount?.type==='percent'?row.after*value/100:(gross>0?value*100*row.after/gross:0),0);allocated+=row.alloc});
  if(rows.length)rows[largest].alloc+=target-allocated;
  rows.forEach(row=>{
    let total=(row.after-row.alloc)/100,net=row.qty>0?total/row.qty:0;
    if(purchaseReadonly&&row.line.dataset.savedTotal!==undefined){total=Number(row.line.dataset.savedTotal);net=Number(row.line.dataset.savedNet)}
    grand+=total;row.line.querySelector('.line-total').textContent=total.toFixed(2);row.line.querySelector('.line-net').textContent=purchaseNetText(net);
  });
  if(purchaseDiscountType!=='percent')pe('purchaseDiscountPct').value=gross?String(purchaseRound(value*10000/gross,4)):'0';
  if(purchaseDiscountType!=='amount')pe('purchaseDiscountAmount').value=(target/100).toFixed(2);
  pe('purchaseItemsCount').textContent=String(purchaseBoundLines().length);
  pe('purchasePieces').textContent=purchaseNumber(pieces);
  pe('purchaseGrand').textContent=grand.toFixed(2);
  pe('purchaseGross').textContent=(gross/100).toFixed(2);
  purchaseRenumber();
}
function purchasePayload(action){return {action,doc_id:Number(pe('purchaseDocId').value)||null,doc_type:pe('purchaseType').value,supplier_id:purchaseResolveSupplier(),branch_id:Number(pe('purchaseBranch').value),doc_date:pe('purchaseDate').value,ref_text:pe('purchaseRef').value.trim(),orig_doc_id:Number(pe('purchaseOrigin').value)||null,notes:pe('purchaseNotes').value.trim(),inv_discount:purchaseDiscount(),lines:purchaseRows(),removed_lines:purchaseRemoved.slice()}}
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
  if(!payload.supplier_id||!payload.branch_id||(!payload.lines.length&&(!payload.doc_id||!payload.removed_lines.length))){if(force)throw new Error('كمّل المورد والفرع والأصناف الأول');return}
  purchaseSaveDirty=false;purchaseSaveState('بيتحفظ…');
  purchaseSaving=purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify(payload)});
  let failed=false;
  try{
    const data=await purchaseSaving;
    purchaseRemoved.splice(0,payload.removed_lines.length);
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
    purchaseCalc();
  }catch(error){purchaseMessage(error.message,true)}
}
async function purchaseFillOrigin(){
  const id=Number(pe('purchaseOrigin').value);if(!id){purchaseOriginCosts={};purchaseCalc();return purchaseQueueSave(true)}
  try{
    const data=await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id='+id);
    purchaseOriginCosts=Object.fromEntries(data.lines.map(line=>[Number(line.item_id),Number(line.unit_cost)]));
    purchaseDiscountType=null;pe('purchaseDiscountPct').value='';pe('purchaseDiscountAmount').value='';
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
  purchaseDiscountType=doc.inv_discount?.type||null;
  if(purchaseDiscountType)pe(purchaseDiscountType==='percent'?'purchaseDiscountPct':'purchaseDiscountAmount').value=String(doc.inv_discount.value);
  pe('purchaseDocId').value=String(doc.id);pe('purchaseType').value=doc.doc_type;purchaseSetSupplier(doc.supplier_id);pe('purchaseBranch').value=String(doc.branch_id);pe('purchaseDate').value=doc.doc_date;pe('purchaseRef').value=doc.ref_text||'';pe('purchaseNotes').value=doc.notes||'';
  pe('purchaseLines').textContent='';data.lines.forEach(line=>purchaseAddLine(line));
  await purchaseLoadOrigins(Number(doc.orig_doc_id)||0);
  if(doc.orig_doc_id&&doc.status==='draft'){
    const origin=await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id='+Number(doc.orig_doc_id));
    purchaseOriginCosts=Object.fromEntries(origin.lines.map(line=>[Number(line.item_id),Number(line.unit_cost)]));
  }
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
[['purchaseDiscountPct','percent'],['purchaseDiscountAmount','amount']].forEach(([id,type])=>{
  pe(id).addEventListener('input',()=>{purchaseDiscountType=type;purchaseCalc();purchaseQueueSave()});
  pe(id).addEventListener('blur',()=>purchaseQueueSave(true));
});
pe('purchaseSupplier').addEventListener('input',()=>{purchaseResolveSupplier();purchaseQueueSave()});
pe('purchaseSupplier').addEventListener('change',()=>{purchaseResolveSupplier();purchaseLoadOrigins();purchaseQueueSave(true)});
pe('purchaseBranch').addEventListener('change',()=>{purchaseLoadOrigins();purchaseQueueBalances();purchaseQueueSave(true)});
pe('purchaseType').addEventListener('change',()=>{purchaseLoadOrigins();purchaseQueueSave(true)});
pe('purchaseOrigin').addEventListener('change',purchaseFillOrigin);
pe('purchasePost')?.addEventListener('click',purchasePostDoc);
pe('purchaseCancel')?.addEventListener('click',purchaseCancelDoc);
pe('purchasePrint').addEventListener('click',()=>window.print());
pe('purchaseNew')?.addEventListener('click',purchaseNew);
purchaseInit();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
