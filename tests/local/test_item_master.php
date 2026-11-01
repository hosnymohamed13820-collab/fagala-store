<?php
/** بريف ٨٣٥: قراءة المرآة المحلية، وبعدها fixtures في جداول مؤقتة للجلسة بس. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once (getenv('LARAGON_WWW') ?: 'D:/laragon/www') . '/config.php';
require_once __DIR__ . '/../../site/item_master_lib.php';

$pdo = db();
echo 'DB version: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
foreach (['1','0055','%','_'] as $q) {
    $r = im_search($q, 1);
    $keys = array_map(fn($row) => $row['source'].':'.$row['id'], $r['rows']);
    if (count($keys) !== count(array_unique($keys))) throw new RuntimeException('الصنف اتكرر');
    if ($q === '1' && ($r['rows'][0]['code'] ?? '') !== '1') throw new RuntimeException('الكود المطابق مش الأول');
    if ($q === '0055' && array_column(array_slice($r['rows'],0,2),'code') !== ['0055','005501']) {
        throw new RuntimeException('بداية الكود مش قبل الاسم');
    }
    if ($q === '%' || $q === '_') {
        foreach ($r['rows'] as $row) {
            if (!str_contains($row['name1'],$q) && !str_starts_with($row['code'],$q)) {
                throw new RuntimeException('LIKE مااتعملوش escape');
            }
        }
    }
    echo json_encode(['q'=>$q,'total'=>$r['total'],'first5'=>array_slice($r['rows'],0,5)], JSON_UNESCAPED_UNICODE) . "\n";
}
$r = im_search('1', 1);
if ($r['rows']) {
    $row = $r['rows'][0];
    if ($row['source'] === 'true') {
        $st = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM (
            SELECT balance FROM hq_branch_balances WHERE item_id=? AND
            ((agent_key='zahraa' AND sto_id=2) OR (agent_key='bonjour' AND sto_id=3)
             OR (agent_key='degla' AND sto_id=5) OR (agent_key='abc' AND sto_id=6))
            UNION ALL SELECT balance FROM hq_balances WHERE item_id=? AND sto_id=1
        ) balances");
        $st->execute([$row['id'],$row['id']]);
        $expected = (float) $st->fetchColumn();
        if ((float) $row['balance'] !== $expected) throw new RuntimeException('الرصيد مختلف');
        echo "balance item {$row['id']}: {$row['balance']} = $expected\n";
        $detail = im_detail('true', (int)$row['id']);
        if (!$detail || !$detail['readonly']) throw new RuntimeException('كارت True مش سليم');
    }
}
$id = (int)$pdo->query('SELECT l.item_id FROM hq_invoice_lines l JOIN hq_invoices h
    ON h.sth_id=l.sth_id AND h.suffix=l.suffix
    WHERE h.doc_type=2 AND h.supplier_id IN (4,1000180,1000181,3000091,1000182) LIMIT 1')->fetchColumn();
if ($id) {
    $rows = im_suppliers('true', $id);
    foreach ($rows as $row) {
        if (in_array($row['supplier_name'], ['فرع دجلة','الرئيسية (الادارة)'], true)) {
            throw new RuntimeException('مورد داخلي ظهر');
        }
    }
    echo json_encode(['transferred_item'=>$id,'suppliers'=>$rows],JSON_UNESCAPED_UNICODE)."\n";
} else echo "ماتجرّبتش: مفيش صنف محوّل في الداتا المحلية\n";
echo "قراءة الداتا المحلية عدّت\n";

$pdo->exec(file_get_contents(__DIR__ . '/item_master_fixture.sql'));
unset($_SESSION['sens_until']);
// دي أول حالة كتابة: صنف الموقع بيتعدل ويتقري تاني زي الأول.
im_update_field(99999835, 'name1', 'اسم اتعدل في الاختبار', 1);
$savedName = $pdo->query('SELECT name1 FROM item_master WHERE id=99999835')->fetchColumn();
if ($savedName !== 'اسم اتعدل في الاختبار') {
    throw new RuntimeException('حفظ صنف الموقع فشل');
}
echo "site update/save: PASS\n";
$suppliers = im_suppliers('true', 99999835);
if (array_column($suppliers,'supplier_type') !== ['إشراقة','رصيد افتتاحي','مورد']) {
    throw new RuntimeException('استبعاد أو تصنيف الموردين فشل');
}
if (array_filter(array_column($suppliers,'unit_price'), fn($v)=>$v!==null)) {
    throw new RuntimeException('سعر الشراء ظهر والقفل مقفول');
}
echo json_encode(['fixture_suppliers'=>$suppliers],JSON_UNESCAPED_UNICODE)."\n";
$_SESSION['sens_until'] = time()+60;
if ((float)im_suppliers('true',99999835)[0]['unit_price'] !== 15.0) {
    throw new RuntimeException('فتح التكلفة فشل');
}
unset($_SESSION['sens_until']);
echo "supplier exclusion/types + sensitive lock: PASS\n";
