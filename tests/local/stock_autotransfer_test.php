<?php
/** اختبار محلي بجداول مؤقتة على اتصال واحد؛ مابيلمسش إعدادات أو أرصدة أو إشعارات حقيقية. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$pdo = new PDO('mysql:host=localhost;dbname=fagala;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function db(): PDO { return $GLOBALS['pdo']; }
function can(string $perm): bool { return in_array($perm, $GLOBALS['perms'], true); }
function is_owner(): bool { return $GLOBALS['owner']; }
function is_real_owner(): bool { return $GLOBALS['owner']; }
function current_user(): array { return ['branch_id' => $GLOBALS['branch']]; }
$GLOBALS['perms']=[]; $GLOBALS['owner']=true; $GLOBALS['branch']=null;
require_once dirname(__DIR__, 2) . '/site/stock_autotransfer_lib.php';
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: $label\n";
}
$originalFlag = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='erp_stock_enabled'")->fetchColumn();
$realDocs = (int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn();
$tables = ['settings', 'users', 'hq_codes', 'invoice_distribution', 'invoice_distribution_lines',
    'stock_doc_dists', 'stock_docs', 'stock_doc_lines', 'stock_moves', 'stock_balances', 'doc_events', 'doc_seq'];
foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/^\s*CONSTRAINT[^\n]+\n/m', '', $create);
    $create = preg_replace('/,\n\)/', "\n)", $create);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $create));
}
// الجداول اللي عليها عدّاد تبدأ من نطاق بيانات الاختبار؛ كلها بتختفي بقفل الاتصال.
foreach (['invoice_distribution', 'invoice_distribution_lines', 'stock_doc_dists', 'stock_docs',
    'stock_doc_lines', 'stock_moves', 'doc_events'] as $table) $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT=990000");
$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES
    ('erp_stock_enabled','0'),('stock_allow_negative','1'),
    ('stock_mode_1','live'),('stock_mode_2','live'),('stock_mode_3','live'),('stock_mode_4','live'),('stock_mode_6','live')");
$pdo->exec("INSERT INTO hq_codes (code,item_id,name,unit_factor) VALUES
    ('BRIEF851A',990851,'صنف تجربة أ',1),('BRIEF851B',990852,'صنف تجربة ب',1),
    ('BRIEF851PACK',990853,'باكو تجربة',12),('BRIEF851NOFACTOR',990854,'معامل ناقص',0),
    ('BRIEF851ALT',990851,'كود تاني للصنف أ',12)");
$pdo->exec("INSERT INTO invoice_distribution (id,invoice_no,dist_date,status) VALUES
    (990851,'851-A',CURDATE(),'done'),(990852,'851-B',CURDATE(),'done'),
    (990853,'851-SPLIT',CURDATE(),'split'),(990854,'851-SKIP',CURDATE(),'done'),
    (990855,'851-PACK',CURDATE(),'done'),(990856,'851-ROLLBACK',CURDATE(),'done')");
$pdo->exec('UPDATE invoice_distribution SET company_branch=1');
$pdo->exec("INSERT INTO invoice_distribution_lines (distribution_id,branch_id,amount,notes) VALUES
    (990851,3,10,'BRIEF851A'),(990851,4,5,'BRIEF851A'),
    (990852,3,2,'BRIEF851A'),(990852,1,7,'BRIEF851B'),
    (990853,1,9,'BRIEF851A'),(990854,5,9,'BRIEF851A'),(990854,1,4,'BRIEF851A'),
    (990854,2,3,'BRIEF851UNKNOWN'),(990854,2,3,'BRIEF851NOFACTOR'),(990854,2,0,'BRIEF851B'),
    (990854,4,0,'BRIEF851B'),
    (990855,1,3,'BRIEF851PACK'),(990855,3,1,'BRIEF851ALT'),(990855,3,2,'BRIEF851A'),
    (990856,1,1,'BRIEF851A'),(990856,4,1,'BRIEF851A')");
try {
    autotransfer_run($pdo, [990851,990852], 1);
    check(false, 'flag gate');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'لسه مقفول'), 'flag=0 rejects run');
}
check((int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn() === 0, 'disabled run writes no docs');
$pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='erp_stock_enabled'");
$preview = autotransfer_preview($pdo, [990852,990851,990851]);
$totals = array_column($preview['branches'], 'total_pieces', 'branch_id');
check($totals === [1=>7.0,3=>12.0,4=>5.0], 'preview: branch 1=7, 3=12, 4=5');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn() === 0, 'preview writes no docs');
$run = autotransfer_run($pdo, [990851,990852], 1);
check(count($run['documents']) === 3, 'run creates 3 docs');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_doc_dists')->fetchColumn() === 4, '4 distribution/branch links');
try {
    $pdo->exec('INSERT INTO stock_doc_dists (doc_id,distribution_id,branch_id) VALUES (990000,990851,3)');
    check(false, 'unique distribution/branch');
} catch (PDOException $e) {
    check($e->getCode()==='23000', 'unique distribution/branch rejects duplicate');
}
check((int) $pdo->query("SELECT COUNT(*) FROM stock_docs WHERE status='sent' AND branch_id=6")->fetchColumn() === 3, 'all sent from 6');
$rows = $pdo->query('SELECT d.counter_branch_id AS branch,SUM(l.qty_pieces) AS qty FROM stock_docs d JOIN stock_doc_lines l ON l.doc_id=d.id GROUP BY d.counter_branch_id ORDER BY branch')->fetchAll();
echo 'DOC TOTALS: ' . json_encode($rows) . "\n";
check(array_map('floatval',array_column($rows,'qty')) === [7.0,12.0,5.0], 'document quantities match preview');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_moves')->fetchColumn() === 6, '6 real stock moves');
check((float) $pdo->query('SELECT SUM(qty) FROM stock_moves WHERE branch_id=6 AND is_transit=0')->fetchColumn() === -24.0, 'source moves -24 pieces');
check((float) $pdo->query('SELECT SUM(qty) FROM stock_moves WHERE is_transit=1')->fetchColumn() === 24.0, 'destination transit +24 pieces');
$again = autotransfer_run($pdo, [990851,990852], 1);
$single = autotransfer_run($pdo, [990851], 1);
check(!$again['documents'] && count($again['skipped']) === 4 && !$single['documents'], 'repeat and group/single overlap create no docs');
check(count(array_filter($again['skipped'], fn($s) => $s['reason']==='اتحوّل قبل كده')) === 4, 'repeat returns already transferred reasons');
$skip = autotransfer_run($pdo,[990853,990854],1);
check(count($skip['documents'])===1 && count($skip['skipped'])===4, 'split / branch5 / missing code / factor / zero skipped; valid branch sent');
$pack = autotransfer_run($pdo,[990855],1);
check(count($pack['documents'])===2, 'pack and alternate code documents');
check((float) $pdo->query('SELECT qty_pieces FROM stock_doc_lines WHERE item_id=990853')->fetchColumn()===36.0, 'pack: 3 units x12 =36 pieces');
check((float) $pdo->query('SELECT l.qty_pieces FROM stock_doc_lines l JOIN stock_doc_dists x ON x.doc_id=l.doc_id WHERE x.distribution_id=990855 AND x.branch_id=3')->fetchColumn()===14.0, 'same item different barcodes: 1x12 +2x1 =14 in one line');
$before = (int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn();
$pdo->exec("UPDATE settings SET setting_value='mirror' WHERE setting_key='stock_mode_4'");
try {
    autotransfer_run($pdo,[990856],1);
    check(false,'rollback on send failure');
} catch (RuntimeException $e) {
    check((int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn()===$before, 'later branch failure rolls back all docs');
    check((int) $pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990856')->fetchColumn()===0,'failure leaves no links');
}
$GLOBALS['perms']=[]; $GLOBALS['owner']=false; $GLOBALS['branch']=6;
check(!autotransfer_can_create(),'employee without permission rejected');
$GLOBALS['perms']=['transfers','transfers:create']; $GLOBALS['branch']=1;
check(autotransfer_can_create(),'branch employee with create permission can open preview');
$otherBranch = autotransfer_run($pdo,[990856],1);
check(!$otherBranch['documents'] && count($otherBranch['skipped'])===1,'employee cannot send from another branch');
$GLOBALS['branch']=6;
check(autotransfer_can_create(),'administration employee with create permission accepted');
$GLOBALS['perms']=[]; $GLOBALS['owner']=true; $GLOBALS['branch']=null;
check(autotransfer_can_create(),'owner accepted');

// ٨٥١ب: مصادر مختلفة، الزوج الناقص بيرجع بعد التصحيح، وصلاحية الراسل لكل توزيع.
$pdo->exec("UPDATE settings SET setting_value='live' WHERE setting_key='stock_mode_4'");
$pdo->exec("INSERT INTO invoice_distribution (id,invoice_no,dist_date,status,company_branch) VALUES
    (990861,'851B-ADMIN',CURDATE(),'done',1),(990862,'851B-FAGALA',CURDATE(),'done',2),
    (990863,'851B-SENDER6',CURDATE(),'done',1),(990864,'851B-SENDER3',CURDATE(),'done',2),
    (990865,'851B-REPAIR',CURDATE(),'done',1),(990866,'851B-EMP6',CURDATE(),'done',1),
    (990867,'851B-EMP3',CURDATE(),'done',2),(990868,'851B-UNKNOWN',CURDATE(),'done',NULL),
    (990869,'851B-NOMAP',CURDATE(),'done',990869),(990870,'851B-FACTOR',CURDATE(),'done',1)");
$pdo->exec("INSERT INTO invoice_distribution_lines (distribution_id,branch_id,amount,notes) VALUES
    (990861,4,2,'BRIEF851A'),(990862,3,5,'BRIEF851A'),(990862,4,7,'BRIEF851A'),
    (990863,1,2,'BRIEF851A'),(990864,1,3,'BRIEF851A'),
    (990865,3,4,'BRIEF851A'),(990865,3,2,'BRIEF851REPAIR'),
    (990866,4,6,'BRIEF851A'),(990867,4,8,'BRIEF851A'),
    (990868,3,1,'BRIEF851A'),(990869,3,1,'BRIEF851A'),
    (990870,3,1,'BRIEF851A'),(990870,3,2,'BRIEF851NOFACTOR')");
$admin = autotransfer_run($pdo,[990861],1);
check(count($admin['documents'])===1 && $admin['documents'][0]['from_branch_id']===6,'851B-1: True store 1 maps to sender 6');
$local = autotransfer_run($pdo,[990862],1);
check(count($local['documents'])===1 && $local['documents'][0]['from_branch_id']===3 &&
    $local['documents'][0]['branch_id']===4 && count($local['skipped'])===1 && $local['skipped'][0]['reason']==='نفس المخزن',
    '851B-2: True store 2 sends 3 to 4; same warehouse skipped');
check((int)$pdo->query('SELECT COUNT(*) FROM stock_docs d JOIN stock_doc_dists x ON x.doc_id=d.id
    JOIN stock_doc_lines l ON l.doc_id=d.id WHERE x.distribution_id=990862 AND d.branch_id=3
    AND d.counter_branch_id=4 AND l.qty_pieces=7')->fetchColumn()===1,
    '851B-2: actual document is 3 to 4 with 7 pieces');
check((int)$pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990862 AND branch_id=3')->fetchColumn()===0,
    '851B-2: same warehouse has no link');
$routesPreview = autotransfer_preview($pdo,[990863,990864]);
check(count($routesPreview['branches'])===2 && array_column($routesPreview['branches'],'from_branch_id')===[6,3],
    '851B-3: preview separates sender/receiver pairs');
$routes = autotransfer_run($pdo,[990863,990864],1);
check(count($routes['documents'])===2 && array_column($routes['documents'],'from_branch_id')===[6,3] &&
    array_column($routes['documents'],'branch_id')===[1,1], '851B-3: two senders to one receiver create two docs');
$actualRoutes=$pdo->query('SELECT d.branch_id,l.qty_pieces FROM stock_docs d JOIN stock_doc_dists x ON x.doc_id=d.id
    JOIN stock_doc_lines l ON l.doc_id=d.id WHERE x.distribution_id IN (990863,990864) ORDER BY d.branch_id')->fetchAll();
check(array_map('intval',array_column($actualRoutes,'branch_id'))===[3,6] &&
    array_map('floatval',array_column($actualRoutes,'qty_pieces'))===[3.0,2.0], '851B-3: actual docs keep each sender quantity');
$partialPreview = autotransfer_preview($pdo,[990865]);
check(!$partialPreview['branches'] && count($partialPreview['skipped'])===1 &&
    $partialPreview['skipped'][0]['reason']==='فيه صنف مش متعرّف: BRIEF851REPAIR','851B-4: incomplete pair excluded from preview');
$partial = autotransfer_run($pdo,[990865],1);
check(!$partial['documents'] && (int)$pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990865')->fetchColumn()===0,
    '851B-4: missing code blocks whole pair without link');
$pdo->exec("INSERT INTO hq_codes (code,item_id,name,unit_factor) VALUES ('BRIEF851REPAIR',990865,'صنف اتصلح',1)");
check(in_array(990865,autotransfer_default_ids($pdo),true),'851B-4: repaired pair remains selectable');
$repairedPreview = autotransfer_preview($pdo,[990865]);
check(count($repairedPreview['branches'])===1 && $repairedPreview['branches'][0]['items_count']===2 &&
    $repairedPreview['branches'][0]['total_pieces']===6.0,'851B-4: repaired preview includes both items');
$repaired = autotransfer_run($pdo,[990865],1);
check(count($repaired['documents'])===1 && $repaired['documents'][0]['items_count']===2,
    '851B-4: corrected code transfers both items');
check((int)$pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990865')->fetchColumn()===1,
    '851B-4: link added only after full pair sent');
$factorMissing = autotransfer_run($pdo,[990870],1);
check(!$factorMissing['documents'] && (int)$pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990870')->fetchColumn()===0,
    '851B-extra: missing factor blocks valid sibling too');
$pdo->exec("UPDATE hq_codes SET unit_factor=12 WHERE code='BRIEF851NOFACTOR'");
$factorFixed = autotransfer_run($pdo,[990870],1);
check(count($factorFixed['documents'])===1 && $factorFixed['documents'][0]['items_count']===2,
    '851B-extra: corrected factor retries whole pair');
$GLOBALS['perms']=['transfers','transfers:create']; $GLOBALS['owner']=false; $GLOBALS['branch']=6;
$employeePreview = autotransfer_preview($pdo,[990866,990867]);
check(count($employeePreview['branches'])===1 && $employeePreview['branches'][0]['from_branch_id']===6 &&
    count($employeePreview['skipped'])===1,'851B-5: employee preview only contains own sender');
$employeeRun = autotransfer_run($pdo,[990866,990867],1);
check(count($employeeRun['documents'])===1 && $employeeRun['documents'][0]['from_branch_id']===6 &&
    count($employeeRun['skipped'])===1 && $employeeRun['skipped'][0]['distribution_id']===990867,
    '851B-5: employee sends own warehouse and skips other sender');
check((int)$pdo->query('SELECT COUNT(*) FROM stock_doc_dists WHERE distribution_id=990867')->fetchColumn()===0,
    '851B-5: unauthorized sender has no link');
$GLOBALS['perms']=[]; $GLOBALS['owner']=true; $GLOBALS['branch']=null;
$unknown = autotransfer_run($pdo,[990868,990869],1);
check(!$unknown['documents'] && count($unknown['skipped'])===2 &&
    array_column($unknown['skipped'],'reason')===['مخزن الفاتورة مش معروف','مخزن الفاتورة مش معروف'],
    '851B-extra: NULL and unmapped True stores never default to 6');
check((int)$pdo->query("SELECT COUNT(*) FROM stock_docs WHERE status<>'sent'")->fetchColumn()===0,
    '851B-extra: all successful docs are sent');
foreach (array_reverse($tables) as $table) $pdo->exec("DROP TEMPORARY TABLE `$table`");
check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='erp_stock_enabled'")->fetchColumn()===$originalFlag,'real flag unchanged');
check((int) $pdo->query('SELECT COUNT(*) FROM stock_docs')->fetchColumn()===$realDocs,'real docs unchanged');
echo "DONE: temporary tables removed; no real users or push queue used\n";
