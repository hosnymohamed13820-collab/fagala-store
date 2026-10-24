<?php
/**
 * calls_lib.php — مكتبة دوال متابعة مكالمات الفروع (بريف ٢٠٩)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

/**
 * توحيد رقم التليفون
 * - إزالة أي مسافات أو حروف أو رموز
 * - تحويل الأرقام بصيغة 0020... أو 20... (12 رقم) إلى 0...
 * - إذا كان الناتج مش من 7 لـ 15 رقم يرجع null
 *
 * @param string|int|float|null $raw الرقم الخام
 * @return string|null الرقم الموحد أو null إذا كان مخفي أو غير صالح
 */
function calls_norm_number(string|int|float|null $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $rawStr = trim((string)$raw);
    if ($rawStr === '' || $rawStr === '-1' || $rawStr === '-2') {
        return null;
    }
    // إزالة أي حاجة مش رقم
    $digits = (string) preg_replace('/\D+/', '', $rawStr);
    if ($digits === '') {
        return null;
    }

    // 0020 متبوعة بـ 10 أرقام (إجمالي 14 رقم) -> 0 + 10 أرقام
    if (str_starts_with($digits, '0020') && strlen($digits) === 14) {
        $digits = '0' . substr($digits, 4);
    }
    // 20 متبوعة بـ 10 أرقام (إجمالي 12 رقم مثل +20100...) -> 0 + 10 أرقام
    elseif (str_starts_with($digits, '20') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }

    $len = strlen($digits);
    if ($len < 7 || $len > 15) {
        return null;
    }

    return $digits;
}

/**
 * معلومات كود نوع المكالمة (أندرويد)
 *
 * @param int $type
 * @return array{label: string, badge: string}
 */
function calls_type_info(int $type): array
{
    return match ($type) {
        1       => ['label' => 'واردة',            'badge' => 'fchip-pos'],
        2       => ['label' => 'صادرة',            'badge' => 'fchip'],
        3       => ['label' => 'فائتة',            'badge' => 'fchip-neg'],
        4       => ['label' => 'بريد صوتي',        'badge' => 'fchip'],
        5       => ['label' => 'مرفوضة',           'badge' => 'fchip-warn'],
        6       => ['label' => 'محظورة',           'badge' => 'fchip-neg'],
        7       => ['label' => 'رد من جهاز آخر',   'badge' => 'fchip'],
        default => ['label' => 'نوع تاني (' . $type . ')', 'badge' => 'fchip'],
    };
}

/**
 * قراءة مهلة المتابعة بالدقائق من الإعدادات
 */
function calls_get_followup_minutes(PDO $pdo): int
{
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'calls_followup_minutes' LIMIT 1");
        $st->execute();
        $val = $st->fetchColumn();
        $mins = (int) $val;
        return ($mins >= 5 && $mins <= 240) ? $mins : 60;
    } catch (Throwable) {
        return 60;
    }
}

/**
 * حفظ مهلة المتابعة بالدقائق في الإعدادات (للمالك فقط)
 */
function calls_set_followup_minutes(PDO $pdo, int $minutes): bool
{
    if ($minutes < 5 || $minutes > 240) {
        return false;
    }
    $st = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, description)
        VALUES ('calls_followup_minutes', ?, 'مهلة متابعة المكالمات الفائتة بالدقائق')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    return $st->execute([(string) $minutes]);
}

/**
 * جلب قائمة الفروع المعتمدة لمتابعة المكالمات
 *
 * @return array<int, string> [branch_id => branch_name]
 */
function calls_get_branches(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [
        1 => 'بونجور',
        2 => 'اي بي سي',
        3 => 'الفجالة',
        4 => 'دجلة',
        5 => 'اشراقة',
    ];
    try {
        $st = $pdo->query("SELECT id, name FROM branches WHERE id IN (1, 2, 3, 4, 5) ORDER BY id");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $cache[(int)$r['id']] = (string)$r['name'];
        }
    } catch (Throwable) {}
    return $cache;
}

/**
 * تاب ١: المكالمات الفائتة بدون رد والمتابعة
 * استعلامين فقط:
 * ١) الفائتة في الفترة
 * ٢) كافة المكالمات اللاحقة لنفس الفروع حتى نهاية الفترة + المهلة
 */
function calls_get_missed_data(PDO $pdo, ?int $branchId, string $dateFrom, string $dateTo, int $followupMins): array
{
    $branches = calls_get_branches($pdo);
    $params1 = [];
    $where1 = "c.call_at >= ? AND c.call_at < DATE_ADD(?, INTERVAL 1 DAY) AND c.call_type IN (3, 5)";
    $params1[] = $dateFrom;
    $params1[] = $dateTo;

    if ($branchId !== null && $branchId > 0) {
        $where1 .= " AND c.branch_id = ?";
        $params1[] = $branchId;
    }

    // استعلام ١: المكالمات الفائتة
    $sql1 = "
        SELECT c.id, c.branch_id, c.number, c.number_norm, c.call_type, c.call_at, c.duration_sec,
               DATE(c.call_at) AS call_date
        FROM branch_calls c
        WHERE $where1
        ORDER BY c.call_at DESC
    ";
    $st1 = $pdo->prepare($sql1);
    $st1->execute($params1);
    $allMissed = $st1->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hiddenCount = 0;
    $validMissed = [];
    $numbersNormSet = [];
    $branchIdsSet = [];

    foreach ($allMissed as $m) {
        if ($m['number_norm'] === null || $m['number_norm'] === '') {
            $hiddenCount++;
        } else {
            $validMissed[] = $m;
            $numbersNormSet[$m['number_norm']] = true;
            $branchIdsSet[(int)$m['branch_id']] = true;
        }
    }

    if (empty($validMissed)) {
        return [
            'rows'         => [],
            'hidden_count' => $hiddenCount,
            'stats'        => [
                'total_missed'      => 0,
                'resolved'          => 0,
                'resolved_late'     => 0,
                'attempted'         => 0,
                'unresolved'        => 0,
                'in_grace'          => 0,
            ],
            'followup_minutes' => $followupMins,
        ];
    }

    // استعلام ٢: كافة المكالمات لنفس الأرقام والفروع من بداية الفترة حتى نهاية الفترة + المهلة
    // لحساب المتابعة وعدد مرات الاتصال في اليوم
    $normList = array_keys($numbersNormSet);
    $bList    = array_keys($branchIdsSet);

    // chunk numbers if huge, but typically in date range it's fine
    $normPlaceholders = implode(',', array_fill(0, count($normList), '?'));
    $bPlaceholders    = implode(',', array_fill(0, count($bList), '?'));

    $sql2 = "
        SELECT branch_id, number_norm, call_type, call_at, duration_sec, DATE(call_at) AS call_date
        FROM branch_calls
        WHERE number_norm IN ($normPlaceholders)
          AND branch_id IN ($bPlaceholders)
          AND call_at >= ?
          AND call_at <= DATE_ADD(DATE_ADD(?, INTERVAL 1 DAY), INTERVAL ? MINUTE)
        ORDER BY call_at ASC
    ";
    $params2 = array_merge($normList, $bList, [$dateFrom, $dateTo, $followupMins]);
    $st2 = $pdo->prepare($sql2);
    $st2->execute($params2);
    $followupCalls = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // فهرسة المكالمات في PHP حسب branch_id و number_norm
    $callsByBranchNum = [];
    $dailyCountByBranchNum = []; // [branch_id|number_norm|call_date] => count
    foreach ($followupCalls as $fc) {
        $key = $fc['branch_id'] . '|' . $fc['number_norm'];
        $callsByBranchNum[$key][] = $fc;

        $dateKey = $key . '|' . $fc['call_date'];
        $dailyCountByBranchNum[$dateKey] = ($dailyCountByBranchNum[$dateKey] ?? 0) + 1;
    }

    $nowTs = time();
    $rows = [];
    $stats = [
        'total_missed'  => count($validMissed),
        'resolved'      => 0,
        'resolved_late' => 0,
        'attempted'     => 0,
        'unresolved'    => 0,
        'in_grace'      => 0,
    ];

    foreach ($validMissed as $vm) {
        $bid = (int) $vm['branch_id'];
        $numNorm = (string) $vm['number_norm'];
        $missedTs = strtotime($vm['call_at']);
        $key = $bid . '|' . $numNorm;
        $branchNumCalls = $callsByBranchNum[$key] ?? [];

        // البحث عن أول مكالمة ناجحة (صادرة > 0 أو واردة > 0) بعد وقت الفائتة
        $firstTalk = null;
        $hasZeroAttempt = false;

        foreach ($branchNumCalls as $c) {
            $cTs = strtotime($c['call_at']);
            if ($cTs <= $missedTs) {
                continue; // المكالمة لازم تكون بعدها
            }
            $type = (int) $c['call_type'];
            $dur  = (int) $c['duration_sec'];

            // صادرة مدتها 0
            if ($type === 2 && $dur === 0) {
                $hasZeroAttempt = true;
            }

            // محادثة ناجحة: صادرة > 0 أو واردة > 0
            if (($type === 2 && $dur > 0) || ($type === 1 && $dur > 0)) {
                $firstTalk = $c;
                break; // أول مكالمة بعد الفائتة
            }
        }

        $status = '';
        $chipClass = '';
        $afterMin = null;

        if ($firstTalk !== null) {
            $talkTs = strtotime($firstTalk['call_at']);
            $diffMins = max(0, (int) round(($talkTs - $missedTs) / 60));
            $afterMin = $diffMins;

            if ($diffMins <= $followupMins) {
                $status = 'اتكلّم';
                $chipClass = 'fchip-pos';
                $stats['resolved']++;
            } else {
                $status = 'اتكلّم متأخر';
                $chipClass = 'fchip-warn';
                $stats['resolved_late']++;
            }
        } elseif ($hasZeroAttempt) {
            $status = 'اتصل ومحدش رد';
            $chipClass = 'fchip-warn';
            $stats['attempted']++;
        } else {
            // التحقق من المهلة
            $timeSinceMissedMin = ($nowTs - $missedTs) / 60;
            if ($timeSinceMissedMin > $followupMins) {
                $status = 'محدش كلّمه';
                $chipClass = 'fchip-neg';
                $stats['unresolved']++;
            } else {
                $status = 'لسه في المهلة';
                $chipClass = 'fchip';
                $stats['in_grace']++;
            }
        }

        $dateKey = $key . '|' . $vm['call_date'];
        $dailyCount = $dailyCountByBranchNum[$dateKey] ?? 1;

        $tInfo = calls_type_info((int)$vm['call_type']);

        $rows[] = [
            'id'           => (int)$vm['id'],
            'branch_id'    => $bid,
            'branch_name'  => $branches[$bid] ?? ('فرع ' . $bid),
            'call_at'      => $vm['call_at'],
            'number'       => $vm['number'],
            'number_norm'  => $numNorm,
            'type_label'   => $tInfo['label'],
            'type_badge'   => $tInfo['badge'],
            'daily_count'  => $dailyCount,
            'status'       => $status,
            'chip_class'   => $chipClass,
            'after_min'    => $afterMin,
        ];
    }

    // الترتيب: «محدش كلّمه» الأول، ثم الأحدث
    usort($rows, function ($a, $b) {
        $aUnres = ($a['status'] === 'محدش كلّمه') ? 1 : 0;
        $bUnres = ($b['status'] === 'محدش كلّمه') ? 1 : 0;
        if ($aUnres !== $bUnres) {
            return $bUnres <=> $aUnres;
        }
        return strcmp($b['call_at'], $a['call_at']);
    });

    return [
        'rows'             => $rows,
        'hidden_count'     => $hiddenCount,
        'stats'            => $stats,
        'followup_minutes' => $followupMins,
    ];
}

/**
 * تاب ٢: سجل المكالمات بالكامل
 */
function calls_get_log_data(PDO $pdo, ?int $branchId, string $dateFrom, string $dateTo, int $limit = 500): array
{
    $branches = calls_get_branches($pdo);
    $params = [$dateFrom, $dateTo];
    $where = "c.call_at >= ? AND c.call_at < DATE_ADD(?, INTERVAL 1 DAY)";

    if ($branchId !== null && $branchId > 0) {
        $where .= " AND c.branch_id = ?";
        $params[] = $branchId;
    }

    // إجمالي العدد
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM branch_calls c WHERE $where");
    $stCnt->execute($params);
    $totalCount = (int) $stCnt->fetchColumn();

    // أول LIMIT مكالمة
    $sql = "
        SELECT c.id, c.branch_id, c.number, c.number_norm, c.call_type, c.call_at, c.duration_sec
        FROM branch_calls c
        WHERE $where
        ORDER BY c.call_at DESC
        LIMIT $limit
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($rawRows as $r) {
        $bid = (int) $r['branch_id'];
        $dur = (int) $r['duration_sec'];
        $durStr = sprintf('%02d:%02d', (int)floor($dur / 60), $dur % 60);
        $tInfo = calls_type_info((int)$r['call_type']);

        $rows[] = [
            'id'          => (int)$r['id'],
            'branch_id'   => $bid,
            'branch_name' => $branches[$bid] ?? ('فرع ' . $bid),
            'call_at'     => $r['call_at'],
            'number'      => $r['number'] ?: '—',
            'number_norm' => $r['number_norm'],
            'type_label'  => $tInfo['label'],
            'type_badge'  => $tInfo['badge'],
            'duration_sec'=> $dur,
            'duration_str'=> $durStr,
        ];
    }

    return [
        'rows'        => $rows,
        'total_count' => $totalCount,
        'limit'       => $limit,
    ];
}

/**
 * تاب ٣: ملخص الفروع
 * استعلام مجمع واحد GROUP BY branch_id + نتائج تاب ١ المحسوبة مسبقاً
 */
function calls_get_summary_data(PDO $pdo, ?int $branchId, string $dateFrom, string $dateTo, int $followupMins): array
{
    $branches = calls_get_branches($pdo);

    // 1. حساب تفاصيل المتابعة من تاب ١
    $missedData = calls_get_missed_data($pdo, $branchId, $dateFrom, $dateTo, $followupMins);
    $missedByBranch = [];
    $delaysByBranch = [];

    foreach ($missedData['rows'] as $mr) {
        $bid = $mr['branch_id'];
        $missedByBranch[$bid]['total'] = ($missedByBranch[$bid]['total'] ?? 0) + 1;
        if ($mr['status'] === 'اتكلّم') {
            $missedByBranch[$bid]['in_time'] = ($missedByBranch[$bid]['in_time'] ?? 0) + 1;
        }
        if ($mr['after_min'] !== null) {
            $delaysByBranch[$bid][] = $mr['after_min'];
        }
    }

    // 2. استعلام تجميعي واحد لكل الفروع
    $params = [$dateFrom, $dateTo];
    $where = "c.call_at >= ? AND c.call_at < DATE_ADD(?, INTERVAL 1 DAY)";

    if ($branchId !== null && $branchId > 0) {
        $where .= " AND c.branch_id = ?";
        $params[] = $branchId;
    }

    $sql = "
        SELECT
            c.branch_id,
            COUNT(CASE WHEN c.call_type = 1 THEN 1 END) AS incoming_count,
            COUNT(CASE WHEN c.call_type = 1 AND c.duration_sec > 0 THEN 1 END) AS answered_count,
            COUNT(CASE WHEN c.call_type IN (3, 5) THEN 1 END) AS missed_count,
            COUNT(CASE WHEN c.call_type = 2 THEN 1 END) AS outgoing_count,
            MAX(c.received_at) AS last_sync
        FROM branch_calls c
        WHERE $where
        GROUP BY c.branch_id
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $aggRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $aggByBranch = [];
    foreach ($aggRows as $ar) {
        $aggByBranch[(int)$ar['branch_id']] = $ar;
    }

    // إذا كان المالك يرى الكل، نعرض كل الفروع الخمسة حتى لو صفر مكالمات
    $targetBranchIds = ($branchId !== null && $branchId > 0) ? [$branchId] : array_keys($branches);

    $nowTs = time();
    $rows = [];

    foreach ($targetBranchIds as $bid) {
        $ar = $aggByBranch[$bid] ?? null;
        $inCount   = (int)($ar['incoming_count'] ?? 0);
        $ansCount  = (int)($ar['answered_count'] ?? 0);
        $missCount = (int)($ar['missed_count'] ?? 0);
        $outCount  = (int)($ar['outgoing_count'] ?? 0);
        $lastSync  = $ar['last_sync'] ?? null;

        // من تاب ١
        $inTimeCount = (int)($missedByBranch[$bid]['in_time'] ?? 0);
        $rateStr = $missCount > 0 ? (round(($inTimeCount / $missCount) * 100) . '%') : '—';

        // حساب الوسيط لوقت المعاودة
        $delays = $delaysByBranch[$bid] ?? [];
        $medianStr = '—';
        if (!empty($delays)) {
            sort($delays, SORT_NUMERIC);
            $cnt = count($delays);
            $mid = (int) floor($cnt / 2);
            if ($cnt % 2 !== 0) {
                $median = $delays[$mid];
            } else {
                $median = round(($delays[$mid - 1] + $delays[$mid]) / 2);
            }
            $medianStr = $median . ' د';
        }

        // حالة آخر مزامنة: إذا أقدم من ساعتين (7200 ثانية)
        $syncStatus = 'normal';
        $syncLabel = 'لا توجد مزامنة';
        if ($lastSync !== null) {
            $syncTs = strtotime($lastSync);
            $diffSec = $nowTs - $syncTs;
            if ($diffSec > 7200) {
                $syncStatus = 'stale'; // أقدم من ساعتين
                $syncLabel = 'الموبايل مش بيبعت (' . date('H:i', $syncTs) . ')';
            } else {
                $syncStatus = 'ok';
                $diffMin = max(0, (int) round($diffSec / 60));
                $syncLabel = $diffMin <= 1 ? 'الآن' : ('من ' . $diffMin . ' د');
            }
        }

        $rows[] = [
            'branch_id'       => $bid,
            'branch_name'     => $branches[$bid] ?? ('فرع ' . $bid),
            'incoming_count'  => $inCount,
            'answered_count'  => $ansCount,
            'missed_count'    => $missCount,
            'in_time_count'   => $inTimeCount,
            'followup_rate'   => $rateStr,
            'median_delay'    => $medianStr,
            'outgoing_count'  => $outCount,
            'last_sync'       => $lastSync,
            'sync_status'     => $syncStatus,
            'sync_label'      => $syncLabel,
        ];
    }

    return $rows;
}
