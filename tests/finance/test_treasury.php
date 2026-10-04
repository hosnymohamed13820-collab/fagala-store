<?php
/**
 * test_treasury.php — اختبارات توصيف للخزينة واليومية والورديات (بريف ١٠٩)
 *
 * «توصيف» = بتسجّل السلوك الحالي زي ما هو، مش بتحكم عليه. الهدف إن أي
 * تغيير بعدين في مسار الفلوس (وإحنا بنفصل الصفحات للـAPI) يبان هنا.
 *
 * بيشتغل على قاعدة Laragon المحلية. الدوال اللي بتتختبر:
 *   post_journal · treasury_movement · reverse_treasury_movement ·
 *   update_journal_entry · transfer_between_treasuries · change_movement_date ·
 *   treasury_balance · save_shift_review
 *
 * 🔴 ليه مفيش transaction كبير زي test_custody.php؟
 *    كل الدوال دي (ما عدا post_journal لما يتبعتلها $pdo) بتفتح transaction
 *    بنفسها بـ$pdo->beginTransaction() على نفس الاتصال — جوه transaction تاني
 *    بترمي «There is already an active transaction». فالداتا بتتكتب فعلاً
 *    وبتتمسح في finally **بأرقامها بالظبط** (مش «كل اللي فوق 990000» —
 *    Laragon فيه صفوف حقيقية فوق 990000 من أيام الاختبارات القديمة).
 *
 * 🔴 save_shift_review بتختار الخزينة المركزية لكل وسيلة لوحدها (أقل id
 *    من النوع ده) — يعني بتلمس خزاين Laragon الحقيقية. أرصدتها بتتاخد
 *    قبل الاختبار وبترجع زي ما كانت، وقيودها بتتمسح بالـref بتاعها.
 *    ده على Laragon بس (نسخة محلية) — الاختبار عمره ما يشتغل على السيرفر.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$laragonWww = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
if (!file_exists($laragonWww . '/config.php')) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/config.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "⏭️ اتخطّى — Laragon مش شغّال\n";
    exit(0);
}

require_once $laragonWww . '/finance.php';

$passCount = 0;
$failCount = 0;

function num_eq(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

function assert_case(string $name, bool $cond, string $details = ''): void
{
    global $passCount, $failCount;
    if ($cond) {
        echo "✅ {$name}\n";
        $passCount++;
    } else {
        echo "❌ {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
        $failCount++;
    }
}

/** بيرجّع true لو الدالة رمت exception من النوع ده */
function throws(callable $fn, string $class = Throwable::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

function t_bal(PDO $pdo, int $tId): float
{
    $st = $pdo->prepare('SELECT balance FROM treasuries WHERE id = ?');
    $st->execute([$tId]);
    return (float) $st->fetchColumn();
}

function je_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function tm_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM treasury_movements WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ===== الداتا الوهمية — أرقام ثابتة، ومفيش حاجة بتتعمل لو أي رقم منهم موجود =====
const T_MAIN  = 9990001;   // خزينة نوع main
const T_OTHER = 9990002;   // خزينة نوع main تانية (للتحويل)
const T_CASH  = 9990003;   // خزينة نوع cash (الصرف منها ممنوع)
const SHIFT_ID = 9990001;
const USER_ID  = 1;

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$exists = $pdo->query(
    'SELECT (SELECT COUNT(*) FROM treasuries WHERE id IN (' . T_MAIN . ',' . T_OTHER . ',' . T_CASH . '))
          + (SELECT COUNT(*) FROM shifts WHERE id = ' . SHIFT_ID . ')'
)->fetchColumn();
if ((int) $exists > 0) {
    echo "❌ الأرقام الوهمية موجودة في القاعدة من رن قديم وقع — امسحها الأول (treasuries/shifts بالأرقام 999000x)\n";
    exit(1);
}

$branchId = (int) $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
$sumRealBefore = (float) $pdo->query('SELECT SUM(balance) FROM treasuries WHERE id < ' . T_MAIN)->fetchColumn();

// الخزاين المركزية اللي save_shift_review هتلمسها — رصيدها بيرجع في الآخر
$central = [];
foreach (['cash', 'visa', 'instapay'] as $m) {
    $tid = central_treasury_by_type($m);
    if ($tid !== null) {
        $central[$m] = ['id' => $tid, 'bal' => t_bal($pdo, $tid)];
    }
}

$movementIds = [];

try {
    $ins = $pdo->prepare('INSERT INTO treasuries (id, name, type, branch_id, balance, is_active, show_in_receipts) VALUES (?, ?, ?, NULL, 0, 1, 0)');
    $ins->execute([T_MAIN, 'اختبار ١٠٩ — رئيسية', 'main']);
    $ins->execute([T_OTHER, 'اختبار ١٠٩ — تانية', 'main']);
    $ins->execute([T_CASH, 'اختبار ١٠٩ — كاش', 'cash']);

    // =================================================================
    //  post_journal — الرصيد = الحالي + الدائن − المدين
    // =================================================================
    $e1 = post_journal(T_MAIN, 0, 500, 'اختبار دائن', $today, null, null, USER_ID);
    $r1 = je_row($pdo, $e1);
    assert_case('post_journal: دائن 500 على رصيد 0 ← الخزينة 500', num_eq(t_bal($pdo, T_MAIN), 500));
    assert_case('post_journal: الرصيد التراكمي في القيد = 500', num_eq((float) $r1['running_balance'], 500));

    $e2 = post_journal(T_MAIN, 200, 0, 'اختبار مدين', $today, null, null, USER_ID);
    assert_case('post_journal: مدين 200 على 500 ← 300', num_eq(t_bal($pdo, T_MAIN), 300));
    assert_case('post_journal: الرصيد التراكمي للقيد التاني = 300', num_eq((float) je_row($pdo, $e2)['running_balance'], 300));

    // قيد بتاريخ إمبارح بعد قيود النهاردة: الرصيد التراكمي بياخد رصيد اللحظة
    // (مش ترتيب التاريخ)، والقيود اللي قبله مابتتعدّلش — السلوك الحالي.
    $e3 = post_journal(T_MAIN, 0, 100, 'اختبار بتاريخ قديم', $yesterday, null, null, USER_ID);
    assert_case('post_journal: قيد بتاريخ إمبارح دائن 100 ← رصيده التراكمي 400 (رصيد اللحظة مش ترتيب التاريخ)',
        num_eq((float) je_row($pdo, $e3)['running_balance'], 400));
    assert_case('post_journal: القيد اللي قبله مااتعدّلش (لسه 300)',
        num_eq((float) je_row($pdo, $e2)['running_balance'], 300));

    assert_case('post_journal: خزينة مش موجودة ← exception',
        throws(fn() => post_journal(9999999, 0, 1, 'x', $today)));

    // لما يتبعتلها $pdo جوه transaction بره: مابتعملش commit لوحدها
    $pdo->beginTransaction();
    post_journal(T_MAIN, 0, 1000, 'اختبار جوه transaction بره', $today, null, null, USER_ID, $pdo);
    $pdo->rollBack();
    assert_case('post_journal بـ$pdo بره + rollBack ← الرصيد لسه 400', num_eq(t_bal($pdo, T_MAIN), 400));

    // =================================================================
    //  treasury_movement
    // =================================================================
    $m1 = treasury_movement(T_MAIN, 'receipt', 100, $today, 'استلام اختبار', USER_ID);
    $movementIds[] = $m1;
    assert_case('treasury_movement: استلام 100 على 400 ← 500', num_eq(t_bal($pdo, T_MAIN), 500));
    $je1 = $pdo->prepare("SELECT * FROM journal_entries WHERE ref_type = 'receipt' AND ref_id = ?");
    $je1->execute([$m1]);
    $je1 = $je1->fetch(PDO::FETCH_ASSOC) ?: [];
    assert_case('treasury_movement: استلام ← قيد دائن 100 مربوط بالحركة', !empty($je1) && num_eq((float) $je1['credit'], 100) && num_eq((float) $je1['debit'], 0));

    $m2 = treasury_movement(T_MAIN, 'disbursement', 50, $today, 'صرف اختبار', USER_ID);
    $movementIds[] = $m2;
    assert_case('treasury_movement: صرف 50 على 500 ← 450', num_eq(t_bal($pdo, T_MAIN), 450));

    assert_case('treasury_movement: مبلغ صفر ← InvalidArgumentException',
        throws(fn() => treasury_movement(T_MAIN, 'receipt', 0, $today), InvalidArgumentException::class));
    assert_case('treasury_movement: مبلغ سالب ← InvalidArgumentException',
        throws(fn() => treasury_movement(T_MAIN, 'receipt', -10, $today), InvalidArgumentException::class));
    assert_case('treasury_movement: نوع غلط ← InvalidArgumentException',
        throws(fn() => treasury_movement(T_MAIN, 'gift', 10, $today), InvalidArgumentException::class));
    assert_case('treasury_movement: تاريخ بكرة ← InvalidArgumentException',
        throws(fn() => treasury_movement(T_MAIN, 'receipt', 10, date('Y-m-d', strtotime('+1 day'))), InvalidArgumentException::class));

    $cntBefore = (int) $pdo->query('SELECT COUNT(*) FROM treasury_movements WHERE treasury_id = ' . T_CASH)->fetchColumn();
    assert_case('treasury_movement: صرف من خزينة نوع cash ← RuntimeException',
        throws(fn() => treasury_movement(T_CASH, 'disbursement', 10, $today), RuntimeException::class));
    $cntAfter = (int) $pdo->query('SELECT COUNT(*) FROM treasury_movements WHERE treasury_id = ' . T_CASH)->fetchColumn();
    assert_case('treasury_movement: الصرف المرفوض من الكاش ماكتبش ولا صف', $cntBefore === $cntAfter && num_eq(t_bal($pdo, T_CASH), 0));

    // =================================================================
    //  update_journal_entry — مابتلمسش فلوس
    // =================================================================
    $before = $je1;
    $balBefore = t_bal($pdo, T_MAIN);
    assert_case('update_journal_entry: بيان فاضي ← exception',
        throws(fn() => update_journal_entry((int) $je1['id'], null, '   ', null, USER_ID)));
    update_journal_entry((int) $je1['id'], null, str_repeat('ب', 300), 'ملاحظة', USER_ID);
    $after = je_row($pdo, (int) $je1['id']);
    assert_case('update_journal_entry: بيان 300 حرف ← اتخزّن 255', mb_strlen((string) $after['description']) === 255);
    assert_case('update_journal_entry: المدين والدائن والرصيد التراكمي ورصيد الخزينة زي ما هم',
        num_eq((float) $after['debit'], (float) $before['debit'])
        && num_eq((float) $after['credit'], (float) $before['credit'])
        && num_eq((float) $after['running_balance'], (float) $before['running_balance'])
        && num_eq(t_bal($pdo, T_MAIN), $balBefore));
    assert_case('update_journal_entry: edited_by اتكتب', (int) $after['edited_by'] === USER_ID && !empty($after['edited_at']));
    assert_case('update_journal_entry: بيان الحركة (treasury_movements) اتحدّث معاه',
        (string) tm_row($pdo, $m1)['description'] === (string) $after['description']);

    // =================================================================
    //  reverse_treasury_movement
    // =================================================================
    $rev = reverse_treasury_movement((int) $je1['id'], USER_ID);
    $revRow = je_row($pdo, $rev);
    assert_case('reverse_treasury_movement: عكس استلام 100 ← الخزينة من 450 لـ350', num_eq(t_bal($pdo, T_MAIN), 350));
    assert_case("reverse_treasury_movement: القيد العكسي ref_type = 'reversal' ومدين 100",
        ($revRow['ref_type'] ?? '') === 'reversal' && num_eq((float) $revRow['debit'], 100));
    assert_case('reverse_treasury_movement: الحركة اتعلّم عليها reversed_at', !empty(tm_row($pdo, $m1)['reversed_at']));
    assert_case('reverse_treasury_movement: عكس نفس القيد تاني ← exception',
        throws(fn() => reverse_treasury_movement((int) $je1['id'], USER_ID)));
    assert_case('reverse_treasury_movement: قيد مش صرف/استلام ← exception',
        throws(fn() => reverse_treasury_movement($e1, USER_ID)));

    // =================================================================
    //  change_movement_date — قيد عكسي بالتاريخ القديم + قيد جديد
    // =================================================================
    $jeM2 = $pdo->prepare("SELECT id FROM journal_entries WHERE ref_type = 'disbursement' AND ref_id = ?");
    $jeM2->execute([$m2]);
    $jeM2Id = (int) $jeM2->fetchColumn();
    $balBefore = t_bal($pdo, T_MAIN);
    $cd = change_movement_date($jeM2Id, $yesterday, USER_ID);
    assert_case('change_movement_date: الرصيد مااتغيّرش (عكس + جديد)', num_eq(t_bal($pdo, T_MAIN), $balBefore));
    assert_case('change_movement_date: اتعمل قيدين (عكسي بالتاريخ القديم + جديد بإمبارح)',
        !empty($cd['changed'])
        && substr((string) je_row($pdo, (int) $cd['rev_entry_id'])['entry_date'], 0, 10) === $today
        && substr((string) je_row($pdo, (int) $cd['new_entry_id'])['entry_date'], 0, 10) === $yesterday);
    assert_case('change_movement_date: تاريخ الحركة اتحدّث لإمبارح', substr((string) tm_row($pdo, $m2)['movement_date'], 0, 10) === $yesterday);

    // =================================================================
    //  transfer_between_treasuries + treasury_balance
    // =================================================================
    $balMain = t_bal($pdo, T_MAIN);
    $trId = transfer_between_treasuries(T_MAIN, T_OTHER, 70, $today, 'تحويل اختبار', USER_ID);
    assert_case('transfer_between_treasuries: 70 من الرئيسية ← نقصت 70', num_eq(t_bal($pdo, T_MAIN), $balMain - 70));
    assert_case('transfer_between_treasuries: 70 للتانية ← بقت 70', num_eq(t_bal($pdo, T_OTHER), 70));
    $trCnt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE ref_type = 'transfer' AND ref_id = ?");
    $trCnt->execute([$trId]);
    assert_case('transfer_between_treasuries: قيدين (صادر ووارد)', (int) $trCnt->fetchColumn() === 2);
    assert_case('transfer_between_treasuries: لنفس الخزينة ← InvalidArgumentException',
        throws(fn() => transfer_between_treasuries(T_MAIN, T_MAIN, 10, $today), InvalidArgumentException::class));
    assert_case('treasury_balance = treasuries.balance', num_eq(treasury_balance(T_OTHER), 70));

    // =================================================================
    //  save_shift_review — بتلمس الخزاين المركزية (رصيدها بيرجع في finally)
    // =================================================================
    if (count($central) === 3) {
        $pdo->prepare(
            "INSERT INTO shifts (id, user_id, branch_id, business_date, shift_name, cash_total, visa_total,
                                 wallet_total, instapay_total, system_total, difference, status)
             VALUES (?, ?, ?, ?, 'اختبار ١٠٩', 1000, 200, 0, 0, 1250, -50, 'submitted')"
        )->execute([SHIFT_ID, USER_ID, $branchId, $today]);

        assert_case('save_shift_review: قرار غلط ← InvalidArgumentException',
            throws(fn() => save_shift_review(SHIFT_ID, [], 'maybe', null, USER_ID), InvalidArgumentException::class));
        assert_case('save_shift_review: رفض من غير سبب ← InvalidArgumentException',
            throws(fn() => save_shift_review(SHIFT_ID, ['cash' => 1000], 'rejected', '  ', USER_ID), InvalidArgumentException::class));

        $shiftJe = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE ref_type = 'shift' AND ref_id = ?");
        save_shift_review(SHIFT_ID, ['cash' => 1000, 'visa' => 200, 'instapay' => 0, 'system' => 1250], 'rejected', 'اختبار', USER_ID);
        $shiftJe->execute([SHIFT_ID]);
        assert_case('save_shift_review: الرفض مابيدخلش الخزينة (صفر قيود)', (int) $shiftJe->fetchColumn() === 0);

        save_shift_review(SHIFT_ID, ['cash' => 1000, 'visa' => 200, 'instapay' => 0, 'system' => 1250], 'approved', null, USER_ID);
        assert_case('save_shift_review: اعتماد كاش 1000 ← الكاش المركزية +1000',
            num_eq(t_bal($pdo, $central['cash']['id']), $central['cash']['bal'] + 1000));
        assert_case('save_shift_review: اعتماد فيزا 200 ← الفيزا المركزية +200',
            num_eq(t_bal($pdo, $central['visa']['id']), $central['visa']['bal'] + 200));
        $shiftJe->execute([SHIFT_ID]);
        assert_case('save_shift_review: إنستا صفر ← مفيش قيد ليها (قيدين بس)', (int) $shiftJe->fetchColumn() === 2);
        $sh = $pdo->query('SELECT status, difference FROM shifts WHERE id = ' . SHIFT_ID)->fetch(PDO::FETCH_ASSOC);
        assert_case('save_shift_review: الفرق = (1000 + 200) − 1250 = −50 والحالة approved',
            ($sh['status'] ?? '') === 'approved' && num_eq((float) $sh['difference'], -50));

        // إعادة مراجعة: بيتحرّك الفرق بس
        save_shift_review(SHIFT_ID, ['cash' => 900, 'visa' => 200, 'instapay' => 0, 'system' => 1250], 'approved', null, USER_ID);
        assert_case('save_shift_review: إعادة مراجعة كاش 900 ← الكاش المركزية +900 بس (اتعمل تصحيح −100)',
            num_eq(t_bal($pdo, $central['cash']['id']), $central['cash']['bal'] + 900));
        assert_case('save_shift_review: إعادة المراجعة مالمستش الفيزا (لسه +200)',
            num_eq(t_bal($pdo, $central['visa']['id']), $central['visa']['bal'] + 200));
        $shiftJe->execute([SHIFT_ID]);
        assert_case('save_shift_review: قيد تصحيح واحد زيادة (٣ قيود)', (int) $shiftJe->fetchColumn() === 3);
    } else {
        echo "⏭️ save_shift_review — مفيش ٣ خزاين مركزية (كاش/فيزا/إنستا) على Laragon\n";
    }

} catch (Throwable $e) {
    assert_case('الاختبار كمّل للآخر', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $dummy = T_MAIN . ',' . T_OTHER . ',' . T_CASH;
    $pdo->exec("DELETE FROM journal_entries WHERE treasury_id IN ($dummy)");
    $pdo->exec("DELETE FROM journal_entries WHERE ref_type = 'shift' AND ref_id = " . SHIFT_ID);
    $pdo->exec("DELETE FROM treasury_transfers WHERE from_treasury_id IN ($dummy) OR to_treasury_id IN ($dummy)");
    $pdo->exec("DELETE FROM treasury_movements WHERE treasury_id IN ($dummy)");
    $pdo->exec('DELETE FROM shift_reviews WHERE shift_id = ' . SHIFT_ID);
    $pdo->exec('DELETE FROM shifts WHERE id = ' . SHIFT_ID);
    $pdo->exec("DELETE FROM treasuries WHERE id IN ($dummy)");
    $restore = $pdo->prepare('UPDATE treasuries SET balance = ? WHERE id = ?');
    foreach ($central as $c) {
        $restore->execute([$c['bal'], $c['id']]);
    }
}

$sumRealAfter = (float) $pdo->query('SELECT SUM(balance) FROM treasuries WHERE id < ' . T_MAIN)->fetchColumn();
$left = (int) $pdo->query(
    'SELECT (SELECT COUNT(*) FROM treasuries WHERE id IN (' . T_MAIN . ',' . T_OTHER . ',' . T_CASH . '))
          + (SELECT COUNT(*) FROM journal_entries WHERE treasury_id IN (' . T_MAIN . ',' . T_OTHER . ',' . T_CASH . '))
          + (SELECT COUNT(*) FROM shifts WHERE id = ' . SHIFT_ID . ')'
)->fetchColumn();
assert_case('التنضيف: مفيش صف وهمي فاضل', $left === 0);
assert_case('التنضيف: مجموع أرصدة الخزاين الحقيقية زي ما كان', num_eq($sumRealAfter, $sumRealBefore),
    "قبل {$sumRealBefore} · بعد {$sumRealAfter}");

echo "\n";
if ($failCount === 0) {
    echo "✅ كل اختبارات الخزينة واليومية والورديات نجحت ({$passCount})\n";
    exit(0);
} else {
    echo "❌ فشل {$failCount} من أصل " . ($passCount + $failCount) . " اختبار\n";
    exit(1);
}
