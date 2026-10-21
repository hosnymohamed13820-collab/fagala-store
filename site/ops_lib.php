<?php
/**
 * ops_lib.php — مكتبة دوال مراقبة وتشغيل النظام
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

/**
 * حساب بيانات تبويب السرعة والأداء (perf) لليوم المحدد (أو الافتراضي).
 */
function ops_perf_data(?string $selectedDay = null): array
{
    $logsDir = __DIR__ . '/logs';
    $availableDays = [];
    if (is_dir($logsDir)) {
        $files = @glob($logsDir . '/perf-*.log.php');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (preg_match('/perf-(\d{4}-\d{2}-\d{2})\.log\.php$/', $f, $m)) {
                    $availableDays[] = $m[1];
                }
            }
        }
    }
    rsort($availableDays);

    $todayCairo = (new \DateTime('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');

    if ($selectedDay === null || $selectedDay === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
        $selectedDay = in_array($todayCairo, $availableDays, true) ? $todayCairo : ($availableDays[0] ?? $todayCairo);
    }

    $logFilePath = $logsDir . '/perf-' . $selectedDay . '.log.php';

    $totalRequests = 0;
    $totalAllMs = 0.0;
    $over1SecCount = 0;

    $pagesMap = [];
    $slowestRequests = [];

    if (is_file($logFilePath)) {
        $fh = @fopen($logFilePath, 'r');
        if ($fh) {
            while (($raw = fgets($fh)) !== false) {
                $line = trim($raw);
                if ($line === '' || str_starts_with($line, '<?php')) {
                    continue;
                }

                $parts = explode("\t", $line);
                if (count($parts) < 6) {
                    continue;
                }

                $time   = $parts[0];
                $page   = $parts[1];
                $method = $parts[2];
                $ms     = (float) $parts[3];
                $mb     = (float) $parts[4];
                $status = (int) $parts[5];
                $uid    = (isset($parts[6]) && $parts[6] !== '') ? (int) $parts[6] : null;

                $totalRequests++;
                $totalAllMs += $ms;
                if ($ms >= 1000) {
                    $over1SecCount++;
                }

                if (!isset($pagesMap[$page])) {
                    $pagesMap[$page] = [
                        'page'     => $page,
                        'count'    => 0,
                        'total_ms' => 0.0,
                        'times'    => [],
                        'total_mb' => 0.0,
                        'errors'   => 0,
                    ];
                }
                $pagesMap[$page]['count']++;
                $pagesMap[$page]['total_ms'] += $ms;
                $pagesMap[$page]['times'][] = $ms;
                $pagesMap[$page]['total_mb'] += $mb;
                if ($status >= 500) {
                    $pagesMap[$page]['errors']++;
                }

                $slowestRequests[] = [
                    'time'   => $time,
                    'page'   => $page,
                    'method' => $method,
                    'ms'     => $ms,
                    'mb'     => $mb,
                    'status' => $status,
                    'uid'    => $uid,
                ];
            }
            fclose($fh);
        }
    }

    $pagesStats = [];
    foreach ($pagesMap as $page => $d) {
        $count = $d['count'];
        $times = $d['times'];
        sort($times);
        $n = count($times);

        $p95Index = min($n - 1, max(0, (int) ceil(0.95 * $n) - 1));
        $p95 = $times[$p95Index];

        $avgMs = round($d['total_ms'] / $count, 1);
        $maxMs = round(max($times), 1);
        $avgMb = round($d['total_mb'] / $count, 2);

        $pagesStats[] = [
            'page'    => $page,
            'count'   => $count,
            'avg_ms'  => $avgMs,
            'p95_ms'  => $p95,
            'max_ms'  => $maxMs,
            'avg_mb'  => $avgMb,
            'errors'  => $d['errors'],
        ];
    }

    usort($pagesStats, fn($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);

    usort($slowestRequests, fn($a, $b) => $b['ms'] <=> $a['ms']);
    $slowest20 = array_slice($slowestRequests, 0, 20);

    $uids = [];
    foreach ($slowest20 as $sr) {
        if ($sr['uid'] !== null && $sr['uid'] > 0) {
            $uids[] = (int) $sr['uid'];
        }
    }
    $uids = array_values(array_unique($uids));
    $userNames = [];
    if (!empty($uids)) {
        try {
            $inPh = implode(',', array_fill(0, count($uids), '?'));
            $st = db()->prepare("SELECT id, full_name FROM users WHERE id IN ($inPh)");
            $st->execute($uids);
            $userNames = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            // صامت
        }
    }

    $overallAvg = $totalRequests > 0 ? round($totalAllMs / $totalRequests, 1) : 0.0;

    return [
        'available_days'   => $availableDays,
        'selected_day'     => $selectedDay,
        'total_requests'   => $totalRequests,
        'overall_avg'      => $overallAvg,
        'over_1sec_count'  => $over1SecCount,
        'pages_stats'      => $pagesStats,
        'slowest_20'       => $slowest20,
        'user_names'       => $userNames,
    ];
}

/**
 * جلب سجل العمليات (audit) مع الفلترة والتقسيم لصفحات.
 */
function ops_audit_data(
    string $from,
    string $to,
    ?int $userId = null,
    string $page = '',
    string $actionSearch = '',
    int $pageNum = 1,
    int $limit = 200
): array {
    $todayCairo = (new \DateTime('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');
    $from = trim($from);
    if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = $todayCairo;
    }
    $to = trim($to);
    if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = $todayCairo;
    }

    $pageNum = max(1, $pageNum);
    $limit = max(1, min(1000, $limit));
    $offset = ($pageNum - 1) * $limit;

    $usersList = [];
    $pagesList = [];
    $rows = [];
    $total = 0;

    try {
        $pdo = db();
        $uSt = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC");
        if ($uSt) {
            $usersList = $uSt->fetchAll(\PDO::FETCH_ASSOC);
        }
        $pSt = $pdo->query("SELECT DISTINCT page FROM audit_requests ORDER BY page ASC");
        if ($pSt) {
            $pagesList = $pSt->fetchAll(\PDO::FETCH_COLUMN);
        }

        $where = ["created_at >= ? AND created_at <= ?"];
        $bindings = [$from . ' 00:00:00', $to . ' 23:59:59'];

        if ($userId !== null && $userId > 0) {
            $where[] = "user_id = ?";
            $bindings[] = $userId;
        }
        if ($page !== '') {
            $where[] = "page = ?";
            $bindings[] = $page;
        }
        if ($actionSearch !== '') {
            $where[] = "action LIKE ?";
            $bindings[] = '%' . $actionSearch . '%';
        }

        $whereSql = implode(' AND ', $where);

        $cntSt = $pdo->prepare("SELECT COUNT(*) FROM audit_requests WHERE $whereSql");
        $cntSt->execute($bindings);
        $total = (int) $cntSt->fetchColumn();

        $sql = "SELECT id, created_at, user_id, username, ip, method, page, action, params, status, ms " .
               "FROM audit_requests WHERE $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
        $dataSt = $pdo->prepare($sql);
        $dataSt->execute($bindings);
        $rows = $dataSt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = [];
        $total = 0;
    }

    return [
        'from'        => $from,
        'to'          => $to,
        'user_id'     => $userId,
        'page'        => $page,
        'action'      => $actionSearch,
        'page_num'    => $pageNum,
        'limit'       => $limit,
        'total'       => $total,
        'rows'        => $rows,
        'users_list'  => $usersList,
        'pages_list'  => $pagesList,
    ];
}

/**
 * جلب بيانات تبويب الأجهزة (devices).
 */
function ops_devices_data(): array
{
    $deviceRows = [];
    try {
        $dSql = "SELECT t.id, t.user_id, t.device_name, t.pw_version AS token_pw_ver,
                        t.created_at, t.last_used_at, t.last_ip, t.revoked_at,
                        u.username, u.full_name, u.pw_version AS user_pw_ver, u.is_active
                 FROM api_tokens t
                 LEFT JOIN users u ON u.id = t.user_id
                 ORDER BY t.id DESC";
        $dSt = db()->query($dSql);
        if ($dSt) {
            $deviceRows = $dSt->fetchAll(\PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        $deviceRows = [];
    }

    return ['rows' => $deviceRows];
}

/**
 * جلب بيانات تبويب القاعدة والـ migrations (db).
 */
function ops_db_data(): array
{
    $manifestFile = __DIR__ . '/migrations_manifest.php';
    $manifest = is_file($manifestFile) ? (array) (require $manifestFile) : [];
    $applied = [];
    $dbReady = true;
    try {
        foreach (db()->query('SELECT name, applied_at FROM schema_migrations') as $r) {
            $applied[(string) $r['name']] = (string) $r['applied_at'];
        }
    } catch (\Throwable $e) {
        $dbReady = false;
    }

    $dbPending = 0;
    $dbRows = [];
    foreach ($manifest as $name) {
        $at = $applied[$name] ?? null;
        if ($at === null) {
            $dbPending++;
        }
        $dbRows[] = ['name' => (string) $name, 'applied_at' => $at];
    }

    return [
        'ready'         => $dbReady,
        'pending_count' => $dbPending,
        'rows'          => $dbRows,
    ];
}

/**
 * إلغاء توكن جهاز (revoke token). للأونر فقط وبصلاحية ops:devices.
 */
function ops_revoke_token(int $tokenId): bool
{
    if ($tokenId <= 0) {
        return false;
    }
    $st = db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $st->execute([$tokenId]);
    return $st->rowCount() > 0;
}
