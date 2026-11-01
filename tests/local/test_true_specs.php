<?php
/** بريف ٨٣٨: قاعدة معزولة بتتمسح في الآخر، من غير كتابة في المرآة. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once (getenv('LARAGON_WWW') ?: 'D:/laragon/www') . '/config.php';
require_once __DIR__ . '/../../site/item_master_lib.php';

function specs_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$pdo = db();
$original = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$sandbox = 'brief838_' . bin2hex(random_bytes(5));
$quote = fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
$pdo->exec('CREATE DATABASE ' . $quote($sandbox) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo->exec('USE ' . $quote($sandbox));
    foreach (['hq_item_cats','item_classes','hq_codes','hq_cat_names','item_prices','item_price_history',
              'users','hq_invoice_lines','hq_invoices','stock_doc_lines','stock_docs','suppliers','item_master_codes'] as $table) {
        $pdo->exec('CREATE TABLE ' . $quote($table) . ' LIKE ' . $quote($original) . '.' . $quote($table));
    }
    $pdo->exec('CREATE TABLE schema_migrations (name VARCHAR(255) PRIMARY KEY)');
    $migration = file_get_contents(__DIR__ . '/../../local/migrations/20261101_036_true_specs.sql');
    $pdo->exec($migration);
    $pdo->exec($migration);
    specs_assert((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 1, 'migration runs twice');

    $rows = [
        ['spec'=>2,'spec_id'=>8381,'name'=>'وصف أول'],
        ['spec'=>2,'spec_id'=>8382,'name'=>'وصف تاني'],
        ['spec'=>2,'spec_id'=>8383,'name'=>'وصف تالت'],
    ];
    $pdo->exec("INSERT INTO item_classes (kind,name,true_id) VALUES ('description','وصف موقع',NULL)");
    ic_sync_true_specs($pdo, $rows);
    ic_sync_true_specs($pdo, $rows);
    specs_assert((int)$pdo->query("SELECT COUNT(*) FROM item_classes WHERE kind='description' AND true_id IS NOT NULL")->fetchColumn() === 3, 'three descriptions, replay without duplicates');
    $rows[0]['name'] = 'وصف اتعدل';
    ic_sync_true_specs($pdo, $rows);
    specs_assert($pdo->query('SELECT name FROM item_classes WHERE true_id=8381')->fetchColumn() === 'وصف اتعدل', 'True name updated');
    specs_assert((int)$pdo->query("SELECT COUNT(*) FROM item_classes WHERE true_id IS NULL AND name='وصف موقع'")->fetchColumn() === 1, 'site classification preserved');
    ic_sync_true_specs($pdo, [
        ['spec'=>3,'spec_id'=>8381,'name'=>'نوع'],
        ['spec'=>4,'spec_id'=>8381,'name'=>'مرحلة'],
        ['spec'=>5,'spec_id'=>8381,'name'=>'شركة'],
    ]);
    specs_assert((int)$pdo->query('SELECT COUNT(DISTINCT kind) FROM item_classes WHERE true_id=8381')->fetchColumn() === 4, 'specs map to four separate kinds');

    // تشغيل نفس فرع الاستقبال الفعلي، من غير auth أو HTTP أو جداول إنتاج.
    $receiver = file_get_contents(__DIR__ . '/../../site/sync_hq.php');
    $start = strpos($receiver, "} elseif (\$kind === 'item_cats') {") + strlen("} elseif (\$kind === 'item_cats') {");
    $end = strpos($receiver, "} elseif (\$kind === 'spec_names')", $start);
    $branch = substr($receiver, $start, $end - $start);
    $stored = $skipped = 0;
    $rows = [['item_id'=>838,'is1_id'=>1,'name'=>'صنف','is2_id'=>8381,'is3_id'=>8381,'is4_id'=>8381,'is5_id'=>8381]];
    eval($branch);
    $rows = [['item_id'=>838,'is1_id'=>2,'name'=>'صنف اتعدل']];
    eval($branch);
    $cat = $pdo->query('SELECT * FROM hq_item_cats WHERE item_id=838')->fetch(PDO::FETCH_ASSOC);
    specs_assert((int)$cat['is1_id'] === 2 && array_map('intval', [$cat['is2_id'],$cat['is3_id'],$cat['is4_id'],$cat['is5_id']]) === [8381,8381,8381,8381], 'agent 2.9 preserves all four spec IDs');
    $rows = [['item_id'=>839,'is1_id'=>1,'name'=>'قديم جديد']];
    eval($branch);
    specs_assert((int)$pdo->query('SELECT is2_id FROM hq_item_cats WHERE item_id=839')->fetchColumn() === 0, 'old agent inserts default zero');
    $pdo->exec("INSERT INTO hq_codes (code,item_id,name,code_type,unit_factor) VALUES ('B838',838,'صنف',0,1)");
    $detail = im_detail('true',838);
    specs_assert(($detail['main']['class_names']['description'] ?? '') === 'وصف اتعدل', 'True item card displays description');
    specs_assert(($detail['main']['class_names']['company'] ?? '') === 'شركة', 'True item card displays company');
    $rows = [['item_id'=>838,'is1_id'=>2,'is2_id'=>0]];
    eval($branch);
    specs_assert((int)$pdo->query('SELECT is2_id FROM hq_item_cats WHERE item_id=838')->fetchColumn() === 0, 'explicit zero from new agent clears spec');
} finally {
    $pdo->exec('USE ' . $quote($original));
    $pdo->exec('DROP DATABASE ' . $quote($sandbox));
}
