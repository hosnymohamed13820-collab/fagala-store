<?php
/** تحقق محلي لبريف ٨٤٢؛ كل بيانات التجربة جوه transaction بتترجع في الآخر. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds4';
require_once "$root/config.php";
session_save_path(sys_get_temp_dir());
session_id('brief842test');
session_start();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/purchase_docs.php';
$_SERVER['REQUEST_URI'] = '/purchase_docs.php';
$_SESSION['app'] = 'fagala';
$_SESSION['user'] = ['id'=>1,'username'=>'owner','full_name'=>'المالك','is_owner'=>1,'branch_id'=>null,'perms_version'=>0];
$_SESSION['permissions'] = [];
$_SESSION['sens_until'] = time()+600;
define('PURCHASE_VIEW_ONLY', true);
require_once __DIR__.'/../../site/purchase_docs_api.php';
require_once __DIR__.'/../../site/reports_lib.php';
$pdo = db();
$checks = 0;
function b842(bool $ok, string $text): void {
    global $checks;
    if (!$ok) throw new RuntimeException($text);
    $checks++; echo "نجح: $text\n";
}
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO suppliers(name,is_active) VALUES(?,1)')->execute(['مورد تجربة بريف ٨٤٢']);
    $supplier = (int) $pdo->lastInsertId();
    $code = $pdo->query('SELECT code,item_id,unit_factor FROM hq_codes WHERE unit_factor=1 ORDER BY code LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$code) throw new RuntimeException('مفيش صنف مناسب للتجربة');
    $head = ['branch_id'=>6,'supplier_id'=>$supplier,'doc_date'=>date('Y-m-d'),'notes'=>'test_b842'];
    $lines = [array_merge($code,['qty_units'=>2,'unit_price'=>12,'discount_pct'=>0])];
    $purchase = purchase_save_draft($pdo,'purchase',$head,$lines,1);
    $return = purchase_save_draft($pdo,'purchase_return',$head,$lines,1);
    $other = purchase_save_draft($pdo,'purchase',array_merge($head,['branch_id'=>1]),$lines,1);
    $before = purchase_view_doc($pdo,$purchase);
    foreach ([[$purchase,'purchase_return','شراء ومينفعش يتحوّل مرتجع'],[$return,'purchase','مرتجع ومينفعش يتحوّل شراء']] as [$id,$type,$message]) {
        $error = '';
        try { purchase_save_draft($pdo,$type,$head,$lines,1,$id); } catch (RuntimeException $e) { $error=$e->getMessage(); }
        b842(str_contains($error,$message),'رفض تغيير النوع بالرسالة الواضحة: '.$type);
    }
    b842(purchase_view_doc($pdo,$purchase)===$before,'مسودة الشراء فضلت بنفس النوع والصنف بعد محاولة التحويل');
    $filter = ['supplier_id'=>$supplier,'branch_id'=>6,'status'=>'draft'];
    foreach (['purchase'=>$purchase,'purchase_return'=>$return] as $type=>$id) {
        $list = purchase_view_docs($pdo,array_merge($filter,['type'=>$type]),0);
        b842($list['total']===1 && (int)$list['docs'][0]['id']===$id && (int)$list['docs'][0]['lines_count']===1 && !empty($list['docs'][0]['last_modified']),'المعلّق بيفصل '.$type.' ومعاه العدد وآخر تعديل');
    }
    $_SESSION['user']['is_owner']=0; $_SESSION['user']['branch_id']=6;
    $list = purchase_view_docs($pdo,['supplier_id'=>$supplier,'type'=>'purchase','status'=>'draft','branch_id'=>1],6);
    b842($list['total']===1 && (int)$list['docs'][0]['id']===$purchase,'الموظف مابيقدرش يطلب مسودات فرع تاني');
    $_SESSION['user']['is_owner']=1;
    $emptyPage=purchase_view_docs($pdo,array_merge($filter,['type'=>'purchase','page'=>2]),0);
    b842($emptyPage['total']===1 && !$emptyPage['docs'],'صفحات المعلّق بتحافظ على العدد الكلي');
    // بنعلّم مستندات التجربة داخل المعاملة بس علشان نختبر حساب التقرير من غير ترحيل مخزن أو قيود.
    $pdo->prepare("UPDATE stock_docs SET status='posted' WHERE id IN (?,?)")->execute([$purchase,$return]);
    $_GET=['branch'=>6,'supplier_id'=>$supplier];
    $all=build_report($pdo,'purchase_docs',date('Y-m-d'),date('Y-m-d'));
    $_GET['doc_type']='purchase_return';
    $ret=build_report($pdo,'purchase_docs',date('Y-m-d'),date('Y-m-d'));
    $_GET['doc_type']='purchase';
    $buy=build_report($pdo,'purchase_docs',date('Y-m-d'),date('Y-m-d'));
    b842(count($all['rows'])===2 && count($ret['rows'])===1 && $ret['rows'][0][2]==='مرتجع' && count($buy['rows'])===1 && $buy['rows'][0][2]==='شراء','التقرير بالنوع ومن غيره بيفلتر صح');
    b842((float)$all['totals'][7]===0.0 && (float)$ret['totals'][7]===-24.0 && (float)$buy['totals'][7]===24.0,'إجماليات التقرير مطابقة للمرحّل من كل نوع');
    $pdo->prepare("UPDATE stock_docs SET status='draft' WHERE id IN (?,?)")->execute([$purchase,$return]);
    $_GET=['id'=>$purchase];
    ob_start(); require __DIR__.'/../../site/purchase_docs.php'; $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xp=new DOMXPath($dom);
    $nodes=$xp->query('//*[@id="pdBoot"]');
    $boot=json_decode($nodes->item(0)->textContent,true,512,JSON_THROW_ON_ERROR);
    b842((int)$boot['document']['doc']['id']===$purchase && count($boot['document']['lines'])===1,'pdBoot بيرندر مستند حقيقي من Laragon من غير API في المتصفح');
    $ids=[]; foreach ($xp->query('//*[@id]') as $node) { $id=$node->getAttribute('id'); if(isset($ids[$id])) throw new RuntimeException('id متكرر: '.$id); $ids[$id]=true; }
    b842(true,'رندر HTML من غير IDs متكررة');
    b842($xp->query('//a[@target="_blank" and @data-type]')->length===2 && $xp->query('//datalist')->length===0,'لينكات النوع بتفتح تاب جديد والمورد من غير datalist');
    if (getenv('BRIEF842_HTML')) file_put_contents(getenv('BRIEF842_HTML'),$html);
    echo "النتيجة: $checks فحص نجحوا؛ بيانات التجربة هتترجع بالكامل.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
