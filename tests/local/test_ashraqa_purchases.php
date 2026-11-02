<?php
/** تطابق قراية Redux مع الشاشة القديمة، بالمفتاح الرباعي والفلترة والصفحات. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$repo = dirname(__DIR__, 2);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ashraqa;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
require $repo . '/site/ashraqa_purchases_lib.php';
function brief848_old_purchases(PDO $pdo, array $filters): array {
    $_GET = $filters;
    $source = file_get_contents(dirname(__DIR__,2) . '/ashraqa/purchases.php');
    $start = strpos($source, '$from =');
    $end = strpos($source, '$page_title');
    eval(substr($source, $start, $end-$start));
    return ['rows'=>$ashInvoices ?? [],'doc'=>$ashDoc ?: null,'lines'=>$ashLines,'buy'=>$totAshBuy ?? 0,'returned'=>$totAshRet ?? 0];
}
$filters = [[],['sstt'=>113],['sstt'=>115],['show_cancelled'=>1],['q'=>'1'],['supplier'=>1]];
foreach ($filters as $filter) {
    $old = brief848_old_purchases($pdo, $filter);
    $new = ashraqa_purchases_read($pdo, $filter);
    if ($new['rows'] !== array_slice($old['rows'],0,100) || count($old['rows']) !== (int)$new['totals']['count'] || abs($old['buy']-(float)$new['totals']['buy'])>=0.005 || abs($old['returned']-(float)$new['totals']['returned'])>=0.005) throw new RuntimeException('القائمة أو الإجماليات اختلفت');
    $second = ashraqa_purchases_read($pdo, $filter+['page'=>2]);
    if ($second['rows'] !== array_slice($old['rows'],100,100)) throw new RuntimeException('الصفحات اختلفت');
}
$doc = $pdo->query('SELECT sstt_id,brch_id,fy_no,add_doc FROM ashraqa_purchases ORDER BY doc_date DESC LIMIT 1')->fetch(PDO::FETCH_NUM);
if (!$doc) throw new RuntimeException('مفيش فاتورة حقيقية للاختبار');
$filter = ['doc'=>implode('-', $doc)];
$old = brief848_old_purchases($pdo, $filter);
$new = ashraqa_purchases_read($pdo, $filter);
if ($new['doc'] !== $old['doc'] || $new['lines'] !== $old['lines']) throw new RuntimeException('تفاصيل الفاتورة اختلفت');
$invalid = ashraqa_purchases_read($pdo, ['doc'=>'113-999999-999999-999999']);
if (!$invalid['not_found'] || $invalid['doc'] !== null || $invalid['lines'] !== []) throw new RuntimeException('المفتاح الرباعي اختلف');
echo 'PASS: 6 filters, both pages, totals, real invoice details, missing quadruple key; real rows=' . count(brief848_old_purchases($pdo, [])['rows']) . "\n";
