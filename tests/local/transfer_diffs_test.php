<?php
/**
 * tests/local/transfer_diffs_test.php — اختبارات شاملة لفروق التحويلات والمقاصة الذكية (بريف ٨٥٣)
 * يعمل بجداول مؤقتة على اتصال واحد؛ لا يلمس بيانات أو أرصدة حقيقية.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pdo = new PDO('mysql:host=localhost;dbname=fagala;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function db(): PDO { return $GLOBALS['pdo']; }

$GLOBALS['perms'] = [];
$GLOBALS['is_real_owner'] = true;
$GLOBALS['is_owner'] = true;
$GLOBALS['user_id'] = 1;
$GLOBALS['user_branch_id'] = null;

function is_real_owner(): bool { return (bool) ($GLOBALS['is_real_owner'] ?? false); }
function is_owner(): bool { return (bool) ($GLOBALS['is_owner'] ?? false); }
function can(string $perm): bool {
    if (is_real_owner()) return true;
    return in_array($perm, $GLOBALS['perms'] ?? [], true);
}
function current_user(): array {
    return [
        'id'        => $GLOBALS['user_id'] ?? 1,
        'branch_id' => $GLOBALS['user_branch_id'] ?? null,
    ];
}
function user_effective_permissions(int $userId): array {
    return $GLOBALS['perms'] ?? [];
}

$_SESSION = [
    'user' => ['id' => 1, 'is_owner' => 1],
    'permissions' => ['transfers', 'transfers:create', 'transfers:receive', 'transfers:resolve'],
];

require_once dirname(__DIR__, 2) . '/site/stock_transfer_lib.php';

function check(bool $ok, string $label, string $details = ''): void {
    if (!$ok) {
        $msg = "FAIL: $label" . ($details ? " ($details)" : '');
        echo "❌ $msg\n";
        throw new RuntimeException($msg);
    }
    echo "✅ PASS: $label\n";
}

// -------------------------------------------------------------
// إنشاء الجداول المؤقتة
// -------------------------------------------------------------
$tables = [
    'settings', 'users', 'roles', 'role_permissions', 'user_permissions',
    'branches', 'hq_codes', 'stock_docs', 'stock_doc_lines', 'stock_moves',
    'stock_balances', 'stock_transfer_rx', 'stock_transfer_diffs',
    'stock_doc_dists', 'doc_events', 'doc_seq', 'notifications', 'push_queue'
];

foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/^\s*CONSTRAINT[^\n]+\n/m', '', $create);
    $create = preg_replace('/,\n\)/', "\n)", $create);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $create));
}

foreach ([
    'stock_docs', 'stock_doc_lines', 'stock_moves', 'stock_transfer_diffs',
    'doc_events', 'notifications', 'push_queue', 'stock_doc_dists'
] as $t) {
    $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT=991000");
}

// إعدادات البيئة
$pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
    ('erp_stock_enabled', '1'),
    ('stock_allow_negative', '1'),
    ('stock_mode_1', 'live'),
    ('stock_mode_2', 'live'),
    ('stock_mode_3', 'live'),
    ('stock_mode_4', 'live'),
    ('stock_mode_6', 'live')");

// الفروع
$pdo->exec("INSERT INTO branches (id, name, is_active) VALUES
    (1, 'بونجور', 1),
    (2, 'اي بي سي', 1),
    (3, 'الفجالة', 1),
    (4, 'دجلة', 1),
    (6, 'الإدارة', 1)");

// الأدوار والصلاحيات
$pdo->exec("INSERT INTO roles (id, name, name_ar, is_owner) VALUES
    (1, 'owner', 'أونر', 1),
    (2, 'receiver', 'استلامات', 0),
    (3, 'accountant', 'محاسب', 0),
    (4, 'cashier', 'كاشير', 0)");

$pdo->exec("INSERT INTO role_permissions (role_id, module_key) VALUES
    (2, 'transfers:resolve')");

// المستخدمين
$pdo->exec("INSERT INTO users (id, username, full_name, role_id, branch_id, is_active, password_hash) VALUES
    (1, 'owner', 'المالك الحقيقي', 1, NULL, 1, 'hash1'),
    (2, 'receiver_emp', 'مسؤول الاستلامات', 2, 6, 1, 'hash2'),
    (3, 'normal_emp', 'موظف عادي', 4, 3, 1, 'hash3'),
    (4, 'view_all_user', 'مستخدم استعراض', 3, NULL, 1, 'hash4')");

// الأصناف للتجربة
$pdo->exec("INSERT INTO hq_codes (code, item_id, name, unit_factor) VALUES
    ('CODE-853-1', 991001, 'صنف عادي مطابق', 1),
    ('CODE-853-2A', 991002, 'صنف مربوط كود أ', 1),
    ('CODE-853-2B', 991002, 'صنف مربوط كود ب', 1),
    ('CODE-853-3', 991003, 'صنف عجز لوحده', 1),
    ('CODE-853-4', 991004, 'صنف زيادة لوحدها', 1),
    ('CODE-853-5', 991005, 'صنف مقاصة ذكية', 1),
    ('CODE-853-6', 991006, 'صنف مقاصة جزئية', 1),
    ('CODE-853-7', 991007, 'صنف تغير اقتراح', 1),
    ('CODE-853-8', 991008, 'صنف رفض', 1),
    ('CODE-853-10', 991010, 'صنف إشعارات', 1)");

echo "\n--- بدء اختبارات Completeness Contract (بريف ٨٥٣) ---\n\n";

// =============================================================
// الحالة 1: زي النهاردة — تحويل من غير فروق -> closed ومفيش صف في stock_transfer_diffs
// =============================================================
$lines1 = [
    ['item_id' => 991001, 'code' => 'CODE-853-1', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
];
$doc1 = transfer_create($pdo, 6, 3, $lines1, 1);
transfer_send($pdo, $doc1, 1);

$stLines1 = $pdo->prepare('SELECT id FROM stock_doc_lines WHERE doc_id = ?');
$stLines1->execute([$doc1]);
$line1Id = (int) $stLines1->fetchColumn();

transfer_count($pdo, $doc1, $line1Id, 10.0, 1);
$rx1 = transfer_receive($pdo, $doc1, 1);

check($rx1['status'] === 'closed', 'Case 1: status is closed');
check($rx1['short_diffs'] === 0 && $rx1['excess_diffs'] === 0, 'Case 1: 0 diffs');
$diffs1Count = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = $doc1")->fetchColumn();
check($diffs1Count === 0, 'Case 1: no rows in stock_transfer_diffs');
$bal3 = stock_balance($pdo, 991001, 3, 0)['qty'];
$bal6 = stock_balance($pdo, 991001, 6, 0)['qty'];
check($bal3 === 10.0 && $bal6 === -10.0, 'Case 1: recipient +10, sender -10');

// =============================================================
// الحالة 2: الأكواد المربوطة — نفس item_id بكودين مبعوت 10+10 ومعدود 12+8 -> مفيش فرق و closed
// =============================================================
$lines2 = [
    ['item_id' => 991002, 'code' => 'CODE-853-2A', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10],
    ['item_id' => 991002, 'code' => 'CODE-853-2B', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
];
$doc2 = transfer_create($pdo, 6, 3, $lines2, 1);
transfer_send($pdo, $doc2, 1);

$stLines2 = $pdo->prepare('SELECT id, code FROM stock_doc_lines WHERE doc_id = ? ORDER BY id ASC');
$stLines2->execute([$doc2]);
$doc2Lines = $stLines2->fetchAll(PDO::FETCH_KEY_PAIR);
$l2A = array_search('CODE-853-2A', $doc2Lines);
$l2B = array_search('CODE-853-2B', $doc2Lines);

transfer_count($pdo, $doc2, $l2A, 12.0, 1);
transfer_count($pdo, $doc2, $l2B, 8.0, 1);
$rx2 = transfer_receive($pdo, $doc2, 1);

check($rx2['status'] === 'closed', 'Case 2: linked codes total 20=20 is closed');
check($rx2['short_diffs'] === 0 && $rx2['excess_diffs'] === 0, 'Case 2: 0 diffs created');
$diffs2Count = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfer_diffs WHERE doc_id = $doc2")->fetchColumn();
check($diffs2Count === 0, 'Case 2: no rows in stock_transfer_diffs');

// فحص مسار العد بالمسح المباشر لكود 2B على تحويل فيه سطر 2A فقط
$doc2Alt = transfer_create($pdo, 6, 3, [
    ['item_id' => 991002, 'code' => 'CODE-853-2A', 'qty_units' => 5, 'unit_factor' => 1, 'qty_pieces' => 5]
], 1);
transfer_send($pdo, $doc2Alt, 1);
// مسح كود 2B بالباركود من غير تحديد lineId
$scanRes = transfer_count($pdo, $doc2Alt, 0, 0, 1, 'CODE-853-2B', 5.0, 'set');
check($scanRes['line_id'] > 0 && $scanRes['received_pieces'] === 5.0, 'Case 2 Alt: scanning alternate linked code resolves to doc line');
$rx2Alt = transfer_receive($pdo, $doc2Alt, 1);
check($rx2Alt['status'] === 'closed', 'Case 2 Alt: transfer closed without diff');

// =============================================================
// الحالة 3: عجز لوحده — مبعوت 10 ومعدود 8 -> pending بـ to_sender
// رصيد المستلم +10 والراسل -10. بعد موافقة الاستلامات: لا حركة. بعد موافقة المالك: المستلم -2 والراسل +2.
// =============================================================
$doc3 = transfer_create($pdo, 6, 3, [
    ['item_id' => 991003, 'code' => 'CODE-853-3', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc3, 1);
$line3Id = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc3")->fetchColumn();
transfer_count($pdo, $doc3, $line3Id, 8.0, 1);
$rx3 = transfer_receive($pdo, $doc3, 1);

check($rx3['status'] === 'diff_open', 'Case 3: status is diff_open');
$stDiff3 = $pdo->prepare("SELECT * FROM stock_transfer_diffs WHERE doc_id = ?");
$stDiff3->execute([$doc3]);
$df3 = $stDiff3->fetch();
check($df3 && $df3['kind'] === 'short' && (float)$df3['qty'] === 2.0, 'Case 3: short diff of 2 pieces');
check($df3['status'] === 'pending' && $df3['resolution'] === 'to_sender', 'Case 3: status pending, resolution to_sender');

$bal3Before = stock_balance($pdo, 991003, 3, 0)['qty'];
$bal6Before = stock_balance($pdo, 991003, 6, 0)['qty'];
check($bal3Before === 10.0 && $bal6Before === -10.0, 'Case 3: before approval recipient +10, sender -10 (no return yet)');

// موافقة الاستلامات فقط
$GLOBALS['is_real_owner'] = false;
$GLOBALS['is_owner'] = false;
$GLOBALS['perms'] = ['transfers', 'transfers:resolve'];
$apprRecv = transfer_diff_approve($pdo, (int)$df3['id'], 2);
check($apprRecv['recv_approved'] === true && $apprRecv['owner_approved'] === false, 'Case 3: recv approved only');
check($apprRecv['is_fully_approved'] === false, 'Case 3: not fully approved yet');
$bal3Mid = stock_balance($pdo, 991003, 3, 0)['qty'];
check($bal3Mid === 10.0, 'Case 3: still no stock move after recv approval only');

// موافقة المالك
$GLOBALS['is_real_owner'] = true;
$GLOBALS['is_owner'] = true;
$GLOBALS['perms'] = [];
$apprOwner = transfer_diff_approve($pdo, (int)$df3['id'], 1);
check($apprOwner['is_fully_approved'] === true && $apprOwner['status'] === 'resolved', 'Case 3: fully approved and resolved');
check($apprOwner['resolve_doc_id'] > 0, 'Case 3: adjust doc created');

$bal3After = stock_balance($pdo, 991003, 3, 0)['qty'];
$bal6After = stock_balance($pdo, 991003, 6, 0)['qty'];
check($bal3After === 8.0 && $bal6After === -8.0, 'Case 3: after execution recipient net +8, sender net -8');
$doc3Status = $pdo->query("SELECT status FROM stock_docs WHERE id = $doc3")->fetchColumn();
check($doc3Status === 'closed', 'Case 3: doc closed after resolve');

// =============================================================
// الحالة 4: زيادة لوحدها — مبعوت 10 ومعدود 11 -> from_sender. بعد الاعتمادين: الراسل -1 والمستلم +1
// =============================================================
$doc4 = transfer_create($pdo, 6, 3, [
    ['item_id' => 991004, 'code' => 'CODE-853-4', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc4, 1);
$line4Id = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc4")->fetchColumn();
transfer_count($pdo, $doc4, $line4Id, 11.0, 1);
$rx4 = transfer_receive($pdo, $doc4, 1);

check($rx4['status'] === 'diff_open', 'Case 4: status diff_open');
$df4 = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc4")->fetch();
check($df4 && $df4['kind'] === 'excess' && (float)$df4['qty'] === 1.0, 'Case 4: excess diff of 1 piece');
check($df4['resolution'] === 'from_sender', 'Case 4: resolution from_sender');

// اعتماد الاستلامات والمالك معاً
$GLOBALS['is_real_owner'] = true;
$GLOBALS['perms'] = ['transfers', 'transfers:resolve'];
$appr4 = transfer_diff_approve($pdo, (int)$df4['id'], 1);
check($appr4['is_fully_approved'] === true && $appr4['status'] === 'resolved', 'Case 4: resolved after both approvals');
$bal3_4 = stock_balance($pdo, 991004, 3, 0)['qty'];
$bal6_4 = stock_balance($pdo, 991004, 6, 0)['qty'];
check($bal3_4 === 11.0 && $bal6_4 === -11.0, 'Case 4: recipient total +11, sender -11');

// =============================================================
// الحالة 5: المقاصة الذكية — تحويلين من الإدارة لدجلة (عدت 8) والفجالة (عدت 12)
// الاتنين swap ومربوطين ببعض. بعد الاعتمادين: مستند واحد: دجلة -2 والفجالة +2 والإدارة لم تتحرك.
// =============================================================
$doc5Degla = transfer_create($pdo, 6, 4, [
    ['item_id' => 991005, 'code' => 'CODE-853-5', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc5Degla, 1);
$l5D = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc5Degla")->fetchColumn();
transfer_count($pdo, $doc5Degla, $l5D, 8.0, 1);
transfer_receive($pdo, $doc5Degla, 1);

$doc5Fagala = transfer_create($pdo, 6, 3, [
    ['item_id' => 991005, 'code' => 'CODE-853-5', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc5Fagala, 1);
$l5F = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc5Fagala")->fetchColumn();
transfer_count($pdo, $doc5Fagala, $l5F, 12.0, 1);
transfer_receive($pdo, $doc5Fagala, 1);

// استعلام الفروق
$df5D = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc5Degla")->fetch();
$df5F = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc5Fagala")->fetch();

check($df5D['resolution'] === 'swap' && $df5F['resolution'] === 'swap', 'Case 5: both resolutions are swap');
check((int)$df5D['pair_diff_id'] === (int)$df5F['id'], 'Case 5: Degla diff pairs to Fagala diff');
check((int)$df5F['pair_diff_id'] === (int)$df5D['id'], 'Case 5: Fagala diff pairs to Degla diff');
check((int)$df5D['counter_branch_id'] === 3, 'Case 5: Degla counter branch is Fagala (3)');
check((int)$df5F['counter_branch_id'] === 4, 'Case 5: Fagala counter branch is Degla (4)');

// رصيد الإدارة الحالي قبل المقاصة
$balAdminBefore = stock_balance($pdo, 991005, 6, 0)['qty'];
check($balAdminBefore === -20.0, 'Case 5: Admin sent 10+10 = -20');

// اعتماد المقاصة (الموافقة على فرق واحد تطبق على الزوج)
$appr5 = transfer_diff_approve($pdo, (int)$df5D['id'], 1);
check($appr5['is_fully_approved'] === true, 'Case 5: swap fully approved');

$df5DAfter = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df5D['id']}")->fetch();
$df5FAfter = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df5F['id']}")->fetch();
check($df5DAfter['status'] === 'resolved' && $df5FAfter['status'] === 'resolved', 'Case 5: both diffs resolved');
check($df5DAfter['resolve_doc_id'] === $df5FAfter['resolve_doc_id'], 'Case 5: same resolve_doc_id for both diffs');

// فحص الأرصدة بعد المقاصة
$balDegla = stock_balance($pdo, 991005, 4, 0)['qty'];
$balFagala = stock_balance($pdo, 991005, 3, 0)['qty'];
$balAdminAfter = stock_balance($pdo, 991005, 6, 0)['qty'];

check($balDegla === 8.0, 'Case 5: Degla balance = 8.0 (10 - 2)');
check($balFagala === 12.0, 'Case 5: Fagala balance = 12.0 (10 + 2)');
check($balAdminAfter === -20.0, 'Case 5: Admin balance did NOT move (-20 unchanged)');

// فحص عدد الحركات في مستند التسوية: حركة واحدة للزوج (خصم من دجلة وإضافة للفجالة)
$adjDocId = (int) $df5DAfter['resolve_doc_id'];
$adjMoves = $pdo->query("SELECT * FROM stock_moves WHERE doc_id = $adjDocId")->fetchAll();
check(count($adjMoves) === 2, 'Case 5: single adjustment doc with exactly 2 moves (pair)');

// =============================================================
// الحالة 6: المقاصة الجزئية — دجلة ناقص 5 والفجالة زيادة 3 -> to_sender و from_sender (مفيش swap)
// =============================================================
$doc6D = transfer_create($pdo, 6, 4, [
    ['item_id' => 991006, 'code' => 'CODE-853-6', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc6D, 1);
$l6D = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc6D")->fetchColumn();
transfer_count($pdo, $doc6D, $l6D, 5.0, 1); // ناقص 5
transfer_receive($pdo, $doc6D, 1);

$doc6F = transfer_create($pdo, 6, 3, [
    ['item_id' => 991006, 'code' => 'CODE-853-6', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc6F, 1);
$l6F = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc6F")->fetchColumn();
transfer_count($pdo, $doc6F, $l6F, 13.0, 1); // زيادة 3
transfer_receive($pdo, $doc6F, 1);

$df6D = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc6D")->fetch();
$df6F = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc6F")->fetch();

check($df6D['resolution'] === 'to_sender', 'Case 6: short 5 is to_sender (no partial swap)');
check($df6F['resolution'] === 'from_sender', 'Case 6: excess 3 is from_sender (no partial swap)');
check(empty($df6D['pair_diff_id']) && empty($df6F['pair_diff_id']), 'Case 6: no pairing on partial');

// =============================================================
// الحالة 7: اقتراح يتغير — ناقص في دجلة to_sender ثم زيادة مماثلة في فرع آخر -> يتحول لـ swap
// وإذا كان معتمداً من أحد الطرفين لا يتغير
// =============================================================
// أ) لم يُعتمد بعد -> يتحول لـ swap
$doc7A = transfer_create($pdo, 6, 4, [
    ['item_id' => 991007, 'code' => 'CODE-853-7', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc7A, 1);
$l7A = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc7A")->fetchColumn();
transfer_count($pdo, $doc7A, $l7A, 6.0, 1); // ناقص 4
transfer_receive($pdo, $doc7A, 1);

$df7A = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc7A")->fetch();
check($df7A['resolution'] === 'to_sender', 'Case 7A: initial resolution is to_sender');

// وصول زيادة مماثلة في فرع 1 (بونجور) بـ 4 قطع
$doc7B = transfer_create($pdo, 6, 1, [
    ['item_id' => 991007, 'code' => 'CODE-853-7', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc7B, 1);
$l7B = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc7B")->fetchColumn();
transfer_count($pdo, $doc7B, $l7B, 14.0, 1); // زيادة 4
transfer_receive($pdo, $doc7B, 1);

$df7AAfter = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df7A['id']}")->fetch();
$df7B = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc7B")->fetch();
check($df7AAfter['resolution'] === 'swap' && $df7B['resolution'] === 'swap', 'Case 7A: resolution changed to swap when pair arrived');
check((int)$df7AAfter['pair_diff_id'] === (int)$df7B['id'], 'Case 7A: paired together');

// ب) إذا كان معتمداً من أحد الطرفين -> لا يتغير
// لنقم باعتماد diff 7A من الاستلامات فقط
$GLOBALS['is_real_owner'] = false;
$GLOBALS['perms'] = ['transfers', 'transfers:resolve'];
transfer_diff_approve($pdo, (int)$df7AAfter['id'], 2);
// الآن 7A لديه recv_approved_at مش null
// تشغيل transfer_diffs_match مجدداً لن يغير اقتراحه
$matchRes = transfer_diffs_match($pdo);
$df7AStill = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df7AAfter['id']}")->fetch();
check($df7AStill['resolution'] === 'swap', 'Case 7B: approved diff resolution remains intact');

// =============================================================
// الحالة 8: رفض — الفرق المقابل في swap يرفض أيضاً من غير أي حركة
// =============================================================
$doc8A = transfer_create($pdo, 6, 4, [
    ['item_id' => 991008, 'code' => 'CODE-853-8', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc8A, 1);
$l8A = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc8A")->fetchColumn();
transfer_count($pdo, $doc8A, $l8A, 7.0, 1); // ناقص 3
transfer_receive($pdo, $doc8A, 1);

$doc8B = transfer_create($pdo, 6, 3, [
    ['item_id' => 991008, 'code' => 'CODE-853-8', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
], 1);
transfer_send($pdo, $doc8B, 1);
$l8B = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $doc8B")->fetchColumn();
transfer_count($pdo, $doc8B, $l8B, 13.0, 1); // زيادة 3
transfer_receive($pdo, $doc8B, 1);

$df8A = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc8A")->fetch();
$df8B = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE doc_id = $doc8B")->fetch();

$movesBeforeReject = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves")->fetchColumn();
$rejectRes = transfer_diff_reject($pdo, (int)$df8A['id'], 1, 'العد غير دقيق وبانتظار إعادة الجرد');
check($rejectRes['status'] === 'rejected', 'Case 8: rejection returned status rejected');

$df8AAfter = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df8A['id']}")->fetch();
$df8BAfter = $pdo->query("SELECT * FROM stock_transfer_diffs WHERE id = {$df8B['id']}")->fetch();
check($df8AAfter['status'] === 'rejected' && $df8BAfter['status'] === 'rejected', 'Case 8: both swap diffs marked rejected');
check($df8AAfter['reject_reason'] === 'العد غير دقيق وبانتظار إعادة الجرد', 'Case 8: reject reason saved');

$movesAfterReject = (int) $pdo->query("SELECT COUNT(*) FROM stock_moves")->fetchColumn();
check($movesAfterReject === $movesBeforeReject, 'Case 8: no stock moves on rejection');

// =============================================================
// الحالة 9: الصلاحيات — موظف من غير transfers:resolve ومش مالك -> يرفض.
// يوزر view_all -> لا يسجل موافقة المالك
// =============================================================
$GLOBALS['is_real_owner'] = false;
$GLOBALS['is_owner'] = false;
$GLOBALS['perms'] = ['transfers', 'transfers:create']; // ليس لديه resolve
$_SESSION['user']['is_owner'] = 0;
$_SESSION['permissions'] = ['transfers', 'transfers:create'];
try {
    transfer_diff_approve($pdo, (int)$df6D['id'], 3);
    check(false, 'Case 9: non-permitted user should throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'غير مصرح'), 'Case 9: non-permitted user throws 403 / forbidden message');
}

// يوزر view_all فقط
$GLOBALS['is_real_owner'] = false;
$GLOBALS['is_owner'] = true; // view_all يعطي is_owner لكن ليس is_real_owner
$GLOBALS['perms'] = ['view_all'];
$_SESSION['user']['is_owner'] = 0;
$_SESSION['permissions'] = ['view_all'];
try {
    transfer_diff_approve($pdo, (int)$df6D['id'], 4);
    check(false, 'Case 9: view_all user should not approve');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'غير مصرح'), 'Case 9: view_all rejected for owner approval');
}

// =============================================================
// الحالة 10: الإشعارات — دفعة فيها 3 فروق -> إشعار واحد مجمّع لكل مستلم
// =============================================================
$pdo->exec("DELETE FROM notifications");
$pdo->exec("DELETE FROM push_queue");

// إنشاء 3 تحويلات بفروق
for ($i = 1; $i <= 3; $i++) {
    $dNotif = transfer_create($pdo, 6, 3, [
        ['item_id' => 991010, 'code' => 'CODE-853-10', 'qty_units' => 10, 'unit_factor' => 1, 'qty_pieces' => 10]
    ], 1);
    transfer_send($pdo, $dNotif, 1);
    $lId = (int) $pdo->query("SELECT id FROM stock_doc_lines WHERE doc_id = $dNotif")->fetchColumn();
    transfer_count($pdo, $dNotif, $lId, 7.0 + $i, 1);
    // إدخال في stock_transfer_diffs كـ pending من غير تشغيل match فوراً لمحاكاة دفعة
    $pdo->prepare("INSERT INTO stock_transfer_diffs (doc_id, doc_line_id, item_id, kind, qty, status) VALUES (?, ?, ?, 'short', ?, 'pending')")
        ->execute([$dNotif, $lId, 991010, 3.0 - $i + 1]);
}

$pdo->exec("DELETE FROM notifications");
$pdo->exec("DELETE FROM push_queue");

// تشغيل المطابقة على الدفعة المجمعة
$matchBatch = transfer_diffs_match($pdo);
check($matchBatch['updated_count'] >= 3, 'Case 10: batch matched 3 diffs');

$notifRows = $pdo->query("SELECT * FROM notifications WHERE type = 'transfer_diff'")->fetchAll();
print_r($notifRows);
// إشعار واحد فقط لكل مستلم (المستخدم رقم 1 المالك)
$uidsNotified = array_column($notifRows, 'user_id');
check(count($notifRows) === count(array_unique($uidsNotified)), 'Case 10: exactly ONE notification per recipient, not 3');
echo "Notification Body: " . ($notifRows[0]['body'] ?? '') . "\n";
check(str_contains($notifRows[0]['body'] ?? '', 'فرق تحويل') && str_contains($notifRows[0]['body'] ?? '', 'فروق أخرى'), 'Case 10: notification body contains batch summary');

echo "\n--- اكتملت جميع اختبارات transfer_diffs_test.php بنجاح 100% ---\n\n";

