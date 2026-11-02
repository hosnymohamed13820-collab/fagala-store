<?php
/** تشغيل توافق 041 على القواعد المحلية بس، مع إثبات إن إعادة التشغيل ما بتغيرش الهيكل. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$schema = $argv[1] ?? 'ashraqa';
if (!in_array($schema, ['ashraqa', 'fagala'], true)) throw new RuntimeException('قاعدة اختبار غير معروفة');
$pdo = new PDO("mysql:host=127.0.0.1;dbname=$schema;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function compat_shape(PDO $pdo): string {
    $columns = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_NUM);
    $indexes = $pdo->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_NUM);
    return hash('sha256', json_encode([$columns, $indexes]));
}
$before = compat_shape($pdo);
$sql = file_get_contents(dirname(__DIR__, 2) . '/local/migrations/20261102_041_ashraqa_compat.sql');
// لازم يفضل DDL، باستثناء تسجيل اسم التوافق في الآخر.
$withoutMarker = preg_replace("/INSERT IGNORE INTO schema_migrations \(name\) VALUES \('20261102_041_ashraqa_compat'\);/", '', $sql);
$withoutMarker = preg_replace('/ON (?:DELETE|UPDATE) (?:CASCADE|SET NULL|RESTRICT|CURRENT_TIMESTAMP(?:\(\))?)/i', '', $withoutMarker);
if (preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE\s+TABLE)\b/i', $withoutMarker)) throw new RuntimeException('الملف فيه داتا');
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$pdo->exec($sql);
$after = compat_shape($pdo);
echo json_encode(['schema' => $schema, 'before' => $before, 'after' => $after, 'ddl_noop' => $before === $after], JSON_PRETTY_PRINT) . "\n";
if ($schema === 'fagala' && $before !== $after) exit(1);
