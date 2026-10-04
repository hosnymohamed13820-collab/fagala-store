<?php
/**
 * daily_summary_lib.php — الملخص اليومي للإدارة (بريف ١١١)
 * نظام الفجالة ستور المالي
 *
 * كل ليلة إشعار للي محمد يختارهم: كل فرع باع كام النهاردة مقارنة بنفس
 * اليوم الأسبوع اللي فات (السبت بالسبت)، وعدد الفواتير، والأكتر بيعاً،
 * والأصناف اللي خلصت. قراية بس — مفيش فلوس بتتحرّك.
 *
 * ⚠️ الملف ده بيتنده من push_worker.php (اللي مابيعملش require لـauth.php).
 *    item_sales_lib.php بيعمل require لـauth.php (جلسة + هيدرز) — فبنحمّله
 *    **جوه ds_build() بس**، يعني مرة واحدة في اليوم وقت الإرسال، مش كل دقيقة.
 *    auth.php وهو بيتحمّل مابيعملش redirect (اتأكدنا) — بيبدأ جلسة وخلاص.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** أسماء الأيام بالعربي — date('w'): 0 = الأحد */
const DS_DAY_NAMES = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];

/** الفرع يتعلّم «داتا ناقصة» لو الوكيل ماكلّمناش من أكتر من كده */
const DS_STALE_SECONDS = 2 * 3600;

/**
 * الملخص ليوم واحد (توقيت القاهرة).
 *
 * @return array{date:string,prev_date:string,branches:array<int,array>,total:array,top:array,generated_at:string}
 */
function ds_build(string $date): array
{
    require_once __DIR__ . '/item_sales_lib.php';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('تاريخ غلط');
    }
    $pdo  = db();
    $prev = date('Y-m-d', strtotime($date . ' -7 days'));
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $prevNext = date('Y-m-d', strtotime($prev . ' +1 day'));

    // أنواع المرتجع — زي isl_sales() بالظبط
    $returnTypes = array_values(array_filter(array_map(
        'intval',
        explode(',', (string) setting('analysis_sales_return_types', '7,17'))
    ), static fn($x) => $x > 0));
    if (empty($returnTypes)) {
        $returnTypes = [7, 17];
    }
    $inReturns = implode(',', $returnTypes);

    // ١) استعلام واحد للتاريخين مع بعض
    $st = $pdo->prepare(
        "SELECT agent_key, DATE(invoice_date) AS d,
                SUM(CASE WHEN doc_type IN ($inReturns) THEN -net_amount ELSE net_amount END) AS net,
                SUM(CASE WHEN doc_type IN ($inReturns) THEN 0 ELSE 1 END) AS cnt
         FROM br_invoices
         WHERE (invoice_date >= ? AND invoice_date < ?) OR (invoice_date >= ? AND invoice_date < ?)
         GROUP BY agent_key, DATE(invoice_date)"
    );
    $st->execute([$date, $next, $prev, $prevNext]);
    $sums = [];   // [agent_key][date] => [net, cnt]
    foreach ($st as $r) {
        $sums[(string) $r['agent_key']][(string) $r['d']] = [(float) $r['net'], (int) $r['cnt']];
    }

    // ٢) آخر مرة الوكيل كلّمنا — agent_status.last_beat_at
    //    (مش MAX(synced_at): الصف بيتحدّث لما فاتورة تتغيّر بس، ففرع قفل بدري
    //     كان هيبان «داتا ناقصة» وهو كامل.)
    $beats = [];
    try {
        foreach ($pdo->query('SELECT agent_key, last_beat_at FROM agent_status') as $r) {
            $beats[(string) $r['agent_key']] = $r['last_beat_at'] !== null ? (string) $r['last_beat_at'] : null;
        }
    } catch (Throwable $e) {
        $beats = [];
    }
    // الداتا كاملة لو الوكيل كلّمنا بعد آخر اليوم، أو في آخر ساعتين (لو اليوم لسه شغال)
    $needBeat = min(time() - DS_STALE_SECONDS, strtotime($next . ' 00:00:00'));

    // ٣) أسماء الفروع وخريطة الوكيل — نفس طريقة item_sales_lib (مفيش خريطة جديدة)
    $agentMap = isl_agent_store_map();             // agent_key => sto_id
    $stores   = an_stores();                       // sto_id => [name, is_hq]
    $agentByStore = [];
    foreach ($agentMap as $ak => $sid) {
        $agentByStore[$sid] = $ak;
    }

    // ٤) خلص النهاردة + الأكتر بيعاً
    $sales    = isl_sales($date, $date);
    $balances = isl_balances_map();
    $items    = isl_items_map();

    $branches = [];
    foreach (an_branch_stores() as $sid) {
        $ak = $agentByStore[$sid] ?? null;
        $cur = $ak !== null ? ($sums[$ak][$date] ?? [0.0, 0]) : [0.0, 0];
        $old = $ak !== null ? ($sums[$ak][$prev] ?? [0.0, 0]) : [0.0, 0];

        $ranOut = 0;
        foreach (($sales['by_store'][$sid] ?? []) as $iid => $row) {
            if ((float) $row['sold_qty'] > 0 && (float) ($balances[$sid][$iid] ?? 0) <= 0) {
                $ranOut++;
            }
        }

        $beat  = $ak !== null ? ($beats[$ak] ?? null) : null;
        $stale = $beat === null || strtotime($beat) < $needBeat;

        $branches[] = [
            'sto_id'    => $sid,
            'agent_key' => $ak,
            'name'      => (string) ($stores[$sid]['name'] ?? ('مخزن ' . $sid)),
            'net'       => $cur[0],
            'count'     => $cur[1],
            'prev_net'  => $old[0],
            'prev_count'=> $old[1],
            'change'    => ds_change($cur[0], $old[0]),
            'ran_out'   => $ranOut,
            'last_beat' => $beat,
            'stale'     => $stale,
        ];
    }
    usort($branches, static fn($a, $b) => $b['net'] <=> $a['net']);

    $tNet = array_sum(array_column($branches, 'net'));
    $tOld = array_sum(array_column($branches, 'prev_net'));
    $total = [
        'net'        => $tNet,
        'count'      => array_sum(array_column($branches, 'count')),
        'prev_net'   => $tOld,
        'prev_count' => array_sum(array_column($branches, 'prev_count')),
        'change'     => ds_change($tNet, $tOld),
        'ran_out'    => array_sum(array_column($branches, 'ran_out')),
    ];

    // الأكتر بيعاً بالكمية — كل الفروع
    $byItem = $sales['by_item'];
    uasort($byItem, static fn($a, $b) => $b['qty'] <=> $a['qty']);
    $top = [];
    foreach ($byItem as $iid => $row) {
        if ((float) $row['qty'] <= 0 || count($top) >= 10) {
            break;
        }
        $top[] = [
            'item_id' => (int) $iid,
            'name'    => (string) ($items[$iid]['name'] ?? $row['fallback_name']),
            'barcode' => (string) ($items[$iid]['barcode'] ?? $row['fallback_code']),
            'qty'     => (float) $row['qty'],
            'revenue' => (float) $row['revenue'],
        ];
    }

    return [
        'date'         => $date,
        'prev_date'    => $prev,
        'branches'     => $branches,
        'total'        => $total,
        'top'          => $top,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/** نسبة التغيير — null لو اليوم القديم صفر أو سالب (مفيش قسمة على صفر). */
function ds_change(float $now, float $old): ?float
{
    if ($old <= 0) {
        return null;
    }
    return ($now - $old) / $old * 100;
}

/** «+18٪» / «−22٪» / «0٪» — من غير أسهم ولا إيموجي. */
function ds_pct(?float $p): string
{
    if ($p === null) {
        return '';
    }
    $n = (int) round($p);
    if ($n > 0) return '+' . $n . '٪';
    if ($n < 0) return '−' . abs($n) . '٪';
    return '0٪';
}

/** رقم صحيح بفواصل آلاف، والسالب بعلامة − */
function ds_money(float $v): string
{
    $n = (int) round($v);
    return ($n < 0 ? '−' : '') . number_format(abs($n));
}

/**
 * نص الإشعار. notify() بيقص body عند ٢٥٥ حرف — فبنقص احنا بالفروع.
 *
 * @return array{title:string, body:string}
 */
function ds_text(array $s): array
{
    $ts    = strtotime($s['date']);
    $day   = DS_DAY_NAMES[(int) date('w', $ts)];
    $pct   = ds_pct($s['total']['change']);
    $title = 'ملخص ' . $day . ' ' . date('j/n', $ts) . ': ' . ds_money($s['total']['net']) . ' ج'
           . ($pct !== '' ? ' (' . $pct . ')' : '');

    $parts = [];
    foreach ($s['branches'] as $b) {
        $p = $b['name'] . ' ' . ds_money($b['net']);
        $bp = ds_pct($b['change']);
        if ($bp !== '') $p .= ' (' . $bp . ')';
        if ($b['stale']) $p .= ' (داتا ناقصة)';
        $parts[] = $p;
    }
    if (!empty($s['top'])) {
        $parts[] = 'الأكتر: ' . $s['top'][0]['name'];
    }

    $body = implode(' · ', $parts);
    while (mb_strlen($body) > 255 && count($parts) > 1) {
        array_pop($parts);
        $body = implode(' · ', $parts) . ' · …';
    }
    if (mb_strlen($body) > 255) {
        $body = mb_substr($body, 0, 254) . '…';
    }

    return ['title' => $title, 'body' => $body];
}

/**
 * بتتنده من الوركر كل دقيقة. بتبعت مرة واحدة في اليوم بعد الساعة المحددة.
 * عمرها ما ترمي — أي خطأ بيتسجّل وخلاص.
 */
function ds_maybe_send(PDO $pdo): void
{
    try {
        if ((string) setting('daily_summary_on', '0') !== '1') {
            return;
        }
        if ((int) date('G') < (int) setting('daily_summary_hour', '23')) {
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) setting('daily_summary_users', ''))),
            static fn($x) => $x > 0
        )));
        if (empty($ids)) {
            return;
        }

        // 🔴 مرة واحدة في اليوم حتى لو وركرين اشتغلوا في نفس الثانية:
        //    اللي يكسب الـUPDATE هو اللي يبعت، والتاني rowCount = 0 فيرجع.
        $today = date('Y-m-d');
        $pdo->prepare(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('daily_summary_last', '')"
        )->execute();
        $st = $pdo->prepare(
            "UPDATE settings SET setting_value = ?
             WHERE setting_key = 'daily_summary_last' AND (setting_value IS NULL OR setting_value <> ?)"
        );
        $st->execute([$today, $today]);
        if ($st->rowCount() === 0) {
            return;
        }

        // اليوزرز الشغالين بس — استعلام واحد
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND id IN ($in)");
        $st->execute($ids);
        $active = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (empty($active)) {
            return;
        }

        $s = ds_build($today);
        $t = ds_text($s);
        require_once __DIR__ . '/push_lib.php';
        notify($pdo, $active, 'daily_summary', $t['title'], $t['body'], 'daily_summary.php?date=' . $today);
    } catch (Throwable $ex) {
        if (function_exists('errlog_record')) {
            errlog_record('php', 'الملخص اليومي: ' . $ex->getMessage(), $ex->getFile(), $ex->getLine());
        }
    }
}
