<?php
/** purchase_docs.php — شاشة فواتير الشراء ومرتجعات المورد على الموقع. */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/purchase_doc_lib.php';

require_login();
require_module_access('purchase_docs');
sens_page();

$pdo = db();
$owner = is_real_owner();
$userBranch = (int) ($_SESSION['user']['branch_id'] ?? 0);
$tab = in_array(($_GET['tab'] ?? ''), ['purchase','purchase_return','draft'], true) ? (string)$_GET['tab'] : 'purchase';
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$filterBranch = $owner ? (int)($_GET['branch_id'] ?? 0) : $userBranch;
$filterSupplier = (int) ($_GET['supplier_id'] ?? 0);

$branches = $pdo->query('SELECT id,name,sto_id FROM branches WHERE is_active=1 AND id IN (1,2,3,4,6) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$marks = implode(',', array_fill(0, count(PURCHASE_INTERNAL_ERP_SUPPLIERS), '?'));
$ss = $pdo->prepare("SELECT id,name FROM suppliers WHERE is_active=1 AND (erp_supplier_id IS NULL OR erp_supplier_id NOT IN ($marks)) ORDER BY name");
$ss->execute(PURCHASE_INTERNAL_ERP_SUPPLIERS);
$suppliers = $ss->fetchAll(PDO::FETCH_ASSOC);

$where = ["d.doc_type IN ('purchase','purchase_return')",'d.doc_date BETWEEN ? AND ?'];
$params = [$from,$to];
if ($filterBranch > 0) { $where[]='d.branch_id=?'; $params[]=$filterBranch; }
if ($filterSupplier > 0) { $where[]='d.supplier_id=?'; $params[]=$filterSupplier; }
if ($tab === 'draft') $where[]="d.status='draft'";
else { $where[]='d.doc_type=?'; $params[]=$tab; $where[]="d.status<>'draft'"; }
$st = $pdo->prepare('SELECT d.*,s.name supplier_name,b.name branch_name,SUM(CASE WHEN l.qty_pieces<>0 THEN 1 ELSE 0 END) lines_count FROM stock_docs d JOIN suppliers s ON s.id=d.supplier_id JOIN branches b ON b.id=d.branch_id LEFT JOIN stock_doc_lines l ON l.doc_id=d.id WHERE '.implode(' AND ',$where).' GROUP BY d.id ORDER BY d.doc_date DESC,d.id DESC LIMIT 200');
$st->execute($params);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);
$matches = purchase_match_hq($pdo,array_column($docs,'id'));

$pageTitle = 'فواتير الشراء';
$active_module = 'purchase_docs';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.purchase-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.purchase-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0}.purchase-tabs a{text-decoration:none}
.purchase-form-grid{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px}.purchase-form-grid label,.purchase-line label{display:flex;flex-direction:column;gap:5px;font-size:13px}
.purchase-lines{display:grid;gap:8px}.purchase-line{display:grid;grid-template-columns:1.3fr 1.8fr .75fr .75fr .75fr .8fr auto;gap:7px;align-items:end;border-bottom:1px solid var(--line-soft);padding-bottom:8px}.purchase-line .line-total{padding:9px 4px;font-variant-numeric:tabular-nums}
.purchase-total{text-align:left;font-size:18px;margin:14px 0;font-variant-numeric:tabular-nums}.purchase-events{font-size:13px;line-height:1.9}
@media(max-width:700px){.purchase-form-grid{grid-template-columns:1fr 1fr}.purchase-line{grid-template-columns:1fr 1fr}.purchase-line .item-name{grid-column:1/-1}.purchase-table-wrap{overflow:visible}.purchase-table-wrap table,.purchase-table-wrap tbody,.purchase-table-wrap tr,.purchase-table-wrap td{display:block;width:100%}.purchase-table-wrap thead{display:none}.purchase-table-wrap tr{border:1px solid var(--line-soft);border-radius:10px;margin-bottom:10px;padding:8px}.purchase-table-wrap td{border:0;padding:4px}.purchase-table-wrap td:before{content:attr(data-label);font-weight:700;margin-left:7px}.purchase-form-grid{grid-template-columns:1fr}.purchase-line{grid-template-columns:1fr}.purchase-line .item-name{grid-column:auto}}
@media print{header,.sidebar,.purchase-no-print,.btn{display:none!important}.card{box-shadow:none;border:0}.purchase-form{display:block!important}}
</style>

<div class="card">
  <div class="purchase-head">
    <div><h2 style="margin:0"><i class="ti ti-package-import"></i> فواتير الشراء</h2><small>حركة مخزن على الموقع من غير حساب مورد أو قيود مالية</small></div>
    <?php if (can('purchase_docs:create')): ?><button class="btn btn-primary purchase-no-print" type="button" onclick="purchaseNew('purchase')">فاتورة جديدة</button><?php endif; ?>
    <?php if (can('purchase_docs:reports')): ?><a class="btn btn-outline purchase-no-print" href="reports.php?type=purchase_docs">التقارير</a><?php endif; ?>
  </div>
  <div class="purchase-tabs purchase-no-print">
    <a class="btn <?= $tab==='purchase'?'btn-primary':'btn-outline' ?>" href="?tab=purchase">شراء</a>
    <a class="btn <?= $tab==='purchase_return'?'btn-primary':'btn-outline' ?>" href="?tab=purchase_return">مرتجع</a>
    <a class="btn <?= $tab==='draft'?'btn-primary':'btn-outline' ?>" href="?tab=draft">مسودات</a>
    <?php if (can('purchase_docs:create')): ?><button class="btn btn-outline" type="button" onclick="purchaseNew('purchase_return')">مرتجع جديد</button><?php endif; ?>
  </div>
  <form method="get" class="purchase-form-grid purchase-no-print">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label>من<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>لحد<input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php if ($owner): ?><label>المخزن<select name="branch_id"><option value="0">كل المخازن</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $filterBranch===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach ?></select></label><?php endif ?>
    <label>المورد<select name="supplier_id"><option value="0">كل الموردين</option><?php foreach($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $filterSupplier===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach ?></select></label>
    <div><button class="btn btn-secondary" type="submit">عرض</button></div>
  </form>
</div>

<div class="card purchase-table-wrap" style="margin-top:14px">
<table class="ftable"><thead><tr><th>الرقم</th><th>التاريخ</th><th>المورد</th><th>المخزن</th><th>رقم فاتورة المورد</th><th class="num">الأصناف</th><th class="num">الإجمالي</th><th>الحالة</th><th>في True؟</th><th></th></tr></thead><tbody>
<?php foreach($docs as $d): $match=$matches[(int)$d['id']]??['state'=>'missing','doc_no'=>null]; ?>
<tr>
 <td data-label="الرقم"><?= $d['doc_no']!==null ? e($d['doc_year'].'/'.$d['doc_no']) : 'مسودة-'.$d['id'] ?></td>
 <td data-label="التاريخ"><?= e($d['doc_date']) ?></td><td data-label="المورد"><?= e($d['supplier_name']) ?></td><td data-label="المخزن"><?= e($d['branch_name']) ?></td><td data-label="فاتورة المورد"><?= e((string)$d['ref_text']) ?></td>
 <td data-label="الأصناف" class="num"><?= (int)$d['lines_count'] ?></td><td data-label="الإجمالي" class="num"><?= sens_mask(number_format((float)$d['total_amount'],2)) ?></td>
 <td data-label="الحالة"><span class="fchip <?= $d['status']==='posted'?'fchip-pos':($d['status']==='cancelled'?'fchip-neg':'fchip-warn') ?>"><?= e($d['status']==='posted'?'مترحلة':($d['status']==='cancelled'?'ملغية':'مسودة')) ?></span></td>
 <td data-label="في True؟"><?php if($match['state']==='matched'): ?><span class="fchip fchip-pos">متطابقة <?= e((string)$match['doc_no']) ?></span><?php elseif($match['state']==='ambiguous'): ?><span class="fchip fchip-warn">مطابقة مش أكيدة</span><?php else: ?><span class="fchip fchip-warn">مش لاقي</span><?php endif ?></td>
 <td><button class="btn btn-sm btn-outline" type="button" onclick="purchaseOpen(<?= (int)$d['id'] ?>)">فتح</button></td>
</tr><?php endforeach ?>
<?php if(!$docs): ?><tr><td colspan="10">مفيش مستندات بالفلاتر دي.</td></tr><?php endif ?>
</tbody></table></div>

<div id="purchaseEditor" class="card purchase-form" style="display:none;margin-top:14px">
 <div class="purchase-head"><h3 id="purchaseTitle">فاتورة شراء</h3><button class="btn btn-ghost purchase-no-print" type="button" onclick="purchaseClose()">إغلاق</button></div>
 <input id="purchaseDocId" type="hidden"><input id="purchaseType" type="hidden" value="purchase">
 <div class="purchase-form-grid">
  <label>المورد<select id="purchaseSupplier"><option value="">اختار المورد</option><?php foreach($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach ?></select></label>
  <label>المخزن<select id="purchaseBranch" <?= !$owner?'disabled':'' ?>><?php foreach($branches as $b): if(!$owner && (int)$b['id']!==$userBranch)continue; ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach ?></select></label>
  <label>التاريخ<input id="purchaseDate" type="date" value="<?= date('Y-m-d') ?>"></label>
  <label>رقم فاتورة المورد<input id="purchaseRef" maxlength="100"></label>
  <label id="purchaseOriginWrap" style="display:none">مرتجع على فاتورة<select id="purchaseOrigin"><option value="">مرتجع من غير أصل</option></select></label>
  <label>ملاحظات<input id="purchaseNotes" maxlength="1000"></label>
 </div>
 <h4>الأصناف</h4><div id="purchaseLines" class="purchase-lines"></div>
 <button class="btn btn-outline purchase-no-print" type="button" onclick="purchaseAddLine()">إضافة صنف</button>
 <div class="purchase-total">الإجمالي: <span id="purchaseGrand">0.00</span></div>
 <div id="purchaseMessage"></div>
 <div class="purchase-no-print" style="display:flex;gap:8px;flex-wrap:wrap">
  <?php if(can('purchase_docs:post')): ?><button id="purchasePost" class="btn btn-success" type="button" onclick="purchasePostDoc()">ترحيل</button><?php endif ?>
  <?php if(can('purchase_docs:cancel')): ?><button id="purchaseCancel" class="btn btn-danger" type="button" onclick="purchaseCancelDoc()" style="display:none">إلغاء</button><?php endif ?>
  <button class="btn btn-outline" type="button" onclick="window.print()">طباعة</button>
 </div>
 <div id="purchaseEvents" class="purchase-events"></div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
const PURCHASE_CSRF=<?= json_encode(csrf_token()) ?>;
const PURCHASE_OWNER=<?= $owner ? 'true' : 'false' ?>;
let purchaseTimer=null,purchaseLoading=false,purchaseLineSeq=0;
const pe=id=>document.getElementById(id);
function purchaseMessage(text,bad=false){pe('purchaseMessage').innerHTML=text?'<div class="alert '+(bad?'alert-danger':'alert-success')+'">'+purchaseEsc(text)+'</div>':''}
function purchaseEsc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML}
async function purchaseApi(url,options={}){const r=await fetch(url,options);const j=await r.json();if(!j.ok)throw new Error(j.error||'حصل خطأ');return j.data}
function purchaseNew(type){purchaseLoading=true;pe('purchaseEditor').style.display='block';pe('purchaseEditor').querySelectorAll('input,select').forEach(x=>x.disabled=false);if(!PURCHASE_OWNER)pe('purchaseBranch').disabled=true;pe('purchaseDocId').value='';pe('purchaseType').value=type;pe('purchaseTitle').textContent=type==='purchase'?'فاتورة شراء جديدة':'مرتجع مورد جديد';pe('purchaseOriginWrap').style.display=type==='purchase_return'?'flex':'none';pe('purchaseRef').value='';pe('purchaseNotes').value='';pe('purchaseDate').value=new Date().toISOString().slice(0,10);pe('purchaseLines').innerHTML='';pe('purchaseEvents').innerHTML='';pe('purchaseCancel')?.style.setProperty('display','none');pe('purchasePost')?.style.removeProperty('display');purchaseAddLine();purchaseLoading=false;if(type==='purchase_return')purchaseLoadOrigins();pe('purchaseEditor').scrollIntoView({behavior:'smooth'})}
function purchaseAddLine(row={}){const div=document.createElement('div');div.className='purchase-line';const codeId='purchaseCode'+(++purchaseLineSeq);div.innerHTML='<label>الكود<span style="display:flex;gap:5px"><input id="'+codeId+'" class="line-code" value="'+purchaseEsc(row.code||'')+'"><button class="btn btn-sm btn-outline purchase-no-print line-scan" type="button" aria-label="فتح السكانر"><i class="ti ti-camera"></i></button></span></label><label class="item-name">الصنف<input class="line-name" value="'+purchaseEsc(row.item_name||'')+'" readonly><input class="line-item" type="hidden" value="'+purchaseEsc(row.item_id||'')+'"><input class="line-factor" type="hidden" value="'+purchaseEsc(row.unit_factor||1)+'"></label><label>الكمية<input class="line-qty" type="number" min="0.001" step="0.001" value="'+purchaseEsc(row.qty_units||1)+'"></label><label>السعر<input class="line-price" type="number" min="0" step="0.0001" value="'+purchaseEsc(row.unit_price||0)+'"></label><label>الخصم %<input class="line-discount" type="number" min="0" max="100" step="0.001" value="'+purchaseEsc(row.discount_pct||0)+'"></label><span class="line-total">0.00</span><button class="btn btn-sm btn-danger purchase-no-print line-remove" type="button">حذف</button>';
 const code=div.querySelector('.line-code');code.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();purchaseLookup(div)}});code.addEventListener('blur',()=>purchaseLookup(div));div.querySelectorAll('input').forEach(x=>{x.addEventListener('input',purchaseCalc);x.addEventListener('blur',purchaseQueueSave)});div.querySelector('.line-scan').onclick=()=>openBarcodeScanner(codeId);div.querySelector('.line-remove').onclick=()=>{div.remove();purchaseCalc();purchaseQueueSave()};pe('purchaseLines').appendChild(div);purchaseCalc()}
async function purchaseLookup(div){const code=div.querySelector('.line-code').value.trim();if(!code)return;try{const d=await purchaseApi('purchase_docs_api.php?action=code&code='+encodeURIComponent(code)+'&supplier_id='+encodeURIComponent(pe('purchaseSupplier').value));div.querySelector('.line-item').value=d.item.item_id;div.querySelector('.line-name').value=d.item.name;div.querySelector('.line-factor').value=d.item.unit_factor||1;if(Number(div.querySelector('.line-price').value)===0&&d.item.last_purchase_price!==null)div.querySelector('.line-price').value=d.item.last_purchase_price;purchaseCalc();purchaseQueueSave()}catch(e){purchaseMessage(e.message,true)}}
async function purchaseLoadOrigins(){if(pe('purchaseType').value!=='purchase_return'||!pe('purchaseSupplier').value)return;try{const d=await purchaseApi('purchase_docs_api.php?action=origins&branch_id='+encodeURIComponent(pe('purchaseBranch').value)+'&supplier_id='+encodeURIComponent(pe('purchaseSupplier').value));pe('purchaseOrigin').innerHTML='<option value="">مرتجع من غير أصل</option>'+d.origins.map(o=>'<option value="'+o.id+'">'+purchaseEsc(o.doc_year+'/'+o.doc_no+' — '+(o.ref_text||'من غير رقم'))+'</option>').join('')}catch(e){purchaseMessage(e.message,true)}}
async function purchaseFillOrigin(){const id=Number(pe('purchaseOrigin').value);if(!id)return;try{const d=await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id='+id);purchaseLoading=true;pe('purchaseLines').innerHTML='';d.lines.forEach(l=>purchaseAddLine({code:l.code,item_id:l.item_id,item_name:l.item_name,unit_factor:l.unit_factor,qty_units:Number(l.available_pieces)/Number(l.unit_factor||1),unit_price:l.unit_price,discount_pct:0}));purchaseLoading=false;purchaseCalc();purchaseQueueSave()}catch(e){purchaseLoading=false;purchaseMessage(e.message,true)}}
function purchaseRows(){return [...pe('purchaseLines').children].filter(r=>r.querySelector('.line-item').value).map(r=>({code:r.querySelector('.line-code').value,item_id:Number(r.querySelector('.line-item').value),unit_factor:Number(r.querySelector('.line-factor').value),qty_units:Number(r.querySelector('.line-qty').value),unit_price:Number(r.querySelector('.line-price').value),discount_pct:Number(r.querySelector('.line-discount').value)}))}
function purchaseCalc(){let total=0;[...pe('purchaseLines').children].forEach(r=>{const v=Math.round((Number(r.querySelector('.line-qty').value)||0)*(Number(r.querySelector('.line-price').value)||0)*(1-(Number(r.querySelector('.line-discount').value)||0)/100)*100)/100;r.querySelector('.line-total').textContent=v.toFixed(2);total+=v});pe('purchaseGrand').textContent=total.toFixed(2)}
function purchasePayload(action){return {action,doc_id:pe('purchaseDocId').value||null,doc_type:pe('purchaseType').value,supplier_id:Number(pe('purchaseSupplier').value),branch_id:Number(pe('purchaseBranch').value),doc_date:pe('purchaseDate').value,ref_text:pe('purchaseRef').value,orig_doc_id:pe('purchaseOrigin').value||null,notes:pe('purchaseNotes').value,lines:purchaseRows()}}
function purchaseQueueSave(){if(purchaseLoading)return;clearTimeout(purchaseTimer);purchaseTimer=setTimeout(purchaseSave,450)}
async function purchaseSave(){const p=purchasePayload('save');if(!p.supplier_id||!p.branch_id||!p.lines.length)return;try{const d=await purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify(p)});pe('purchaseDocId').value=d.doc_id;purchaseMessage('المسودة اتحفظت')}catch(e){purchaseMessage(e.message,true)}}
async function purchasePostDoc(){await purchaseSave();const id=Number(pe('purchaseDocId').value);if(!id)return purchaseMessage('كمّل بيانات الفاتورة الأول',true);if(!confirm('متأكد إنك عايز ترحّل المستند؟'))return;try{await purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify({action:'post',doc_id:id})});location.reload()}catch(e){purchaseMessage(e.message,true)}}
async function purchaseCancelDoc(){const reason=prompt('اكتب سبب الإلغاء');if(!reason)return;try{await purchaseApi('purchase_docs_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':PURCHASE_CSRF},body:JSON.stringify({action:'cancel',doc_id:Number(pe('purchaseDocId').value),reason})});location.reload()}catch(e){purchaseMessage(e.message,true)}}
async function purchaseOpen(id){try{purchaseLoading=true;const d=await purchaseApi('purchase_docs_api.php?action=doc&id='+id);pe('purchaseEditor').style.display='block';pe('purchaseDocId').value=d.doc.id;pe('purchaseType').value=d.doc.doc_type;pe('purchaseSupplier').value=d.doc.supplier_id;pe('purchaseBranch').value=d.doc.branch_id;pe('purchaseDate').value=d.doc.doc_date;pe('purchaseRef').value=d.doc.ref_text||'';pe('purchaseNotes').value=d.doc.notes||'';pe('purchaseOriginWrap').style.display=d.doc.doc_type==='purchase_return'?'flex':'none';pe('purchaseLines').innerHTML='';d.lines.forEach(purchaseAddLine);pe('purchaseTitle').textContent=(d.doc.doc_type==='purchase'?'فاتورة شراء ':'مرتجع مورد ')+(d.doc.doc_no?'#'+d.doc.doc_no:'#مسودة-'+d.doc.id);pe('purchaseEvents').innerHTML='<h4>سجل المستند</h4>'+d.events.map(e=>'<div>'+purchaseEsc(e.at)+' — '+purchaseEsc(e.event)+' — '+purchaseEsc(e.user_name||'النظام')+'</div>').join('');const draft=d.doc.status==='draft';pe('purchasePost')?.style.setProperty('display',draft?'':'none');pe('purchaseCancel')?.style.setProperty('display',d.doc.status==='posted'?'':'none');pe('purchaseEditor').querySelectorAll('input,select').forEach(x=>x.disabled=!draft);pe('purchaseEditor').scrollIntoView({behavior:'smooth'});purchaseLoading=false}catch(e){purchaseLoading=false;purchaseMessage(e.message,true)}}
function purchaseClose(){pe('purchaseEditor').style.display='none'}
['purchaseSupplier','purchaseBranch','purchaseDate','purchaseRef','purchaseNotes','purchaseOrigin'].forEach(id=>pe(id)?.addEventListener('blur',purchaseQueueSave));
pe('purchaseSupplier').addEventListener('change',purchaseLoadOrigins);pe('purchaseBranch').addEventListener('change',purchaseLoadOrigins);pe('purchaseOrigin').addEventListener('change',purchaseFillOrigin);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
