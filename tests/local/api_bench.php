<?php
/**
 * api_bench.php — قياس كل أجزاء GET في واجهات *_api.php على Laragon.
 *
 * CLI فقط. الأداة لا تُنسخ إلى www، ولا تشغّل POST، وتتخطى أي GET معروف
 * إنه بيكتب. عدّ الاستعلامات من general_log وبيترجع لإعداداته الأصلية.
 *
 * الاستخدام:
 *   D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe tests/local/api_bench.php
 *   ... api_bench.php --list
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repo = dirname(__DIR__, 2);
$www = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
$runner = __DIR__ . '/run_page.php';
$php = PHP_BINARY;
$listOnly = in_array('--list', $argv, true);
$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}
$runs = 3;

/** أجزاء كتابة. وجود الاسم هنا يمنع تشغيله حتى لو تحليل الملف أخطأ. */
$postParts = [
    'analysis_api.php' => ['new_batch', 'del_batch', 'build_erp'],
    'ashraqa_invoices_api.php' => ['set_status', 'save_price', 'save_prices_bulk'],
    'attendance_api.php' => ['checkin', 'checkout', 'request'],
    'book_reserve_api.php' => ['save', 'set_status', 'reprint'],
    'branch_invoices_api.php' => ['set_status', 'save_price', 'save_prices_bulk'],
    'calls_api.php' => ['log'],
    'cashier_api.php' => ['save', 'approve', 'reject'],
    'customers_api.php' => ['create', 'update_field'],
    'dashboard_api.php' => ['save'],
    'invoices_api.php' => [
        'autosave', 'group_autosave', 'set_status', 'reservation_preview', 'reservation_apply', 'save_notes',
        'group_save_notes', 'item_flags_save', 'item_flags_clear', 'add_to_problems',
        'group_create', 'group_create_items', 'group_add_invoices', 'group_add_items',
        'group_delete', 'group_remove_invoice', 'group_remove_item',
    ],
    'invoice_problems_api.php' => ['save_field', 'delete_problem', 'delete_invoice', 'recalc_all'],
    'item_classes_api.php' => ['create', 'rename', 'toggle'],
    'item_master_api.php' => ['create', 'update', 'code_save', 'price'],
    'item_prices_api.php' => ['apply'],
    'market_prices_api.php' => ['fetch', 'link', 'unlink', 'ignore', 'unignore', 'bulk_link', 'bulk_undo', 'renorm'],
    'notifications_api.php' => ['mark_read'],
    'ops_api.php' => ['revoke_token'],
    'pos_api.php' => [
        'session_open', 'session_close', 'finalize', 'hold', 'held_resume', 'held_cancel',
        'approve', 'drawer_open', 'cart_event', 'return_ref', 'return_general', 'exchange', 'print',
    ],
    'pos_devices_api.php' => ['add', 'update'],
    'purchase_docs_api.php' => ['save', 'post', 'cancel'],
    'purchase_groups_api.php' => ['create', 'toggle', 'toggle_source', 'remove', 'uncheck_all', 'delete_checked', 'rename', 'sort'],
    'rabt_guard_api.php' => ['close'],
    'receiving_api.php' => ['start', 'scan', 'update_qty', 'review', 'reject_review', 'approve', 'delete_receipt', 'delete_invoice_receipts'],
    'remote_scan_api.php' => ['wake', 'push'],
    'reorder_api.php' => ['update_limits', 'reset_manual', 'recalc', 'create_shortage'],
    'settings_api.php' => ['invoice_guard', 'daily_summary_save'],
    'sms_inbox_api.php' => ['webhook'],
    'stock_api.php' => ['post', 'cancel', 'opening', 'adjust_create', 'import_run'],
    'stocktake_api.php' => [
        'add_sheet', 'add_line', 'delete_line', 'start_review', 'set_review', 'set_review_qty',
        'set_final', 'set_final_bulk', 'finalize', 'end_session', 'reopen_session', 'delete_sheet',
        'reassign', 'change_place', 'reopen_review',
    ],
    'stocktake_places_api.php' => ['add', 'delete', 'delete_all', 'toggle'],
    'tasks_api.php' => ['mark_done', 'add_comment', 'upload_attachment'],
    'tasks_issue_api.php' => ['create', 'issue', 'save'],
    'transfers_api.php' => ['create', 'prepare', 'send', 'count', 'receive', 'resolve_excess', 'cancel'],
    'undistributed_api.php' => ['set_period', 'save_note'],
    'users_api.php' => ['discount_cap'],
];

/** GETs فيها side effect معروف؛ ممنوع تشغيلها في أداة القياس. */
$mutatingGets = [
    'review_api.php' => [
        'shifts', 'custody', 'permits', 'seq_issues', 'counts', 'meta', 'all',
    ],
];

/** أجزاء متعرفة بـallowedParts أو if بدل switch. */
$declaredParts = [
    'branch_recon_api.php' => ['pairs', 'detail', 'alerts', 'ashraqa', 'parties', 'meta', 'all'],
    'custody_api.php' => ['branch', 'employee', 'meta', 'all'],
    'employee_statement_api.php' => ['default'],
    'hr_api.php' => ['employees', 'attendance', 'requests', 'bonuses', 'penalties', 'deficits', 'advances', 'salaries', 'meta', 'all'],
    'item_card_api.php' => ['search', 'card', 'xlsx'],
    'item_sales_api.php' => ['stock', 'movement', 'profit', 'monthly'],
    'my_salary_api.php' => ['default'],
    'machines_api.php' => ['readings', 'daily', 'monthly', 'machines'],
    'notifications_api.php' => ['summary', 'list', 'mark_read'],
    'pos_reports_api.php' => ['meta', 'report'],
    'purchase_docs_api.php' => ['docs', 'options', 'suppliers', 'code', 'doc', 'origins', 'return_available', 'save', 'post', 'cancel'],
    'reorder_api.php' => ['items', 'update_limits', 'reset_manual', 'recalc', 'create_shortage'],
    'review_api.php' => ['shifts', 'custody', 'permits', 'seq_issues', 'counts', 'meta', 'all'],
    'stock_api.php' => ['balance', 'moves', 'doc', 'docs', 'recon', 'post', 'cancel', 'opening', 'adjust_create', 'import_run'],
    'transfers_api.php' => ['list', 'doc', 'lookup_code', 'open_diffs', 'create', 'prepare', 'send', 'count', 'receive', 'resolve_excess', 'cancel'],
];

$actionFiles = ['purchase_docs_api.php', 'reorder_api.php', 'stock_api.php', 'transfers_api.php'];

/** باراميترات آمنة عامة؛ الملف يتجاهل الزيادة. */
$common = [
    'id' => '1', 'item_id' => '1', 'invoice_id' => '1', 'doc_id' => '1',
    'batch_id' => '1', 'group_id' => '1', 'employee_id' => '1', 'emp' => '1',
    'branch_id' => '1', 'branch' => '1', 'sto' => '1', 'page' => '1', 'limit' => '100',
    'q' => 'ا', 'code' => '1', 'from' => date('Y-m-01'), 'to' => date('Y-m-d'),
    'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'),
    'device_uuid' => 'bench-read-only', 'report' => 'removed', 'group' => 'system',
    'a' => 'fagala', 'b' => 'hq', 'tab' => '',
];

function discoverParts(string $site, array $declared): array
{
    $out = [];
    foreach (glob($site . '/*_api.php') ?: [] as $path) {
        $file = basename($path);
        $src = (string) file_get_contents($path);
        $parts = $declared[$file] ?? [];

        $tokens = token_get_all($src);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_SWITCH) {
                continue;
            }
            $head = '';
            while (++$i < $count && $tokens[$i] !== '{') {
                $head .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }
            if (!str_contains($head, '$part') || $i >= $count) {
                continue;
            }
            $depth = 1;
            while (++$i < $count && $depth > 0) {
                $tok = $tokens[$i];
                if ($tok === '{') {
                    $depth++;
                } elseif ($tok === '}') {
                    $depth--;
                } elseif ($depth === 1 && is_array($tok) && $tok[0] === T_CASE) {
                    while (++$i < $count) {
                        $candidate = $tokens[$i];
                        if (is_array($candidate) && $candidate[0] === T_CONSTANT_ENCAPSED_STRING) {
                            $parts[] = stripcslashes(substr($candidate[1], 1, -1));
                            break;
                        }
                        if ($candidate === ':') {
                            break;
                        }
                    }
                }
            }
        }

        preg_match_all('/\$part\s*={2,3}\s*([\'\"])([^\'\"]+)\1/', $src, $m2);
        $parts = array_merge($parts, $m2[2] ?? []);

        preg_match_all('/([\'\"])([^\'\"]+)\1\s*=>\s*\[\s*([\'\"])(GET|POST)\3/i', $src, $m3);
        $parts = array_merge($parts, $m3[2] ?? []);

        $parts = array_values(array_unique(array_filter($parts, static fn($v) => $v !== '')));
        sort($parts, SORT_STRING);
        if (!$parts) {
            $parts = ['default'];
        }
        $out[$file] = $parts;
    }
    ksort($out, SORT_STRING);
    return $out;
}

function isListArray(array $value): bool
{
    return array_keys($value) === range(0, count($value) - 1);
}

function maxRows(mixed $value): int
{
    if (!is_array($value)) {
        return 0;
    }
    $max = isListArray($value) ? count($value) : 0;
    foreach ($value as $child) {
        $max = max($max, maxRows($child));
    }
    return $max;
}

function tableCounts(PDO $pdo, array $tables): array
{
    $out = [];
    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach ($tables as $table) {
        $exists->execute([$table]);
        $out[$table] = (int) $exists->fetchColumn() > 0
            ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()
            : null;
    }
    return $out;
}

function runEndpoint(string $php, string $runner, string $www, string $file, array $query): array
{
    $sessionDir = rtrim(sys_get_temp_dir(), '/\\') . '/fagala_api_bench_sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('تعذر إنشاء session مؤقتة');
    }
    $cmd = [
        $php, '-d', 'session.save_path=' . $sessionDir,
        $runner, $file, 'owner', http_build_query($query, '', '&', PHP_QUERY_RFC3986),
    ];
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($runner));
    if (!is_resource($proc)) {
        throw new RuntimeException('تعذر تشغيل ' . $file);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $started > 90) {
            proc_terminate($proc);
            $stderr .= ' TIMEOUT';
            break;
        }
        usleep(10000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['body' => $stdout, 'stderr' => trim($stderr), 'exit' => $exit, 'ms' => (microtime(true) - $started) * 1000];
}

$all = discoverParts($repo . '/site', $declaredParts);
$jobs = [];
foreach ($all as $file => $parts) {
    foreach ($parts as $part) {
        $method = in_array($part, $postParts[$file] ?? [], true) ? 'POST' : 'GET';
        $reason = null;
        if ($method === 'POST') {
            $reason = 'اتخطى — POST';
        } elseif (in_array($part, $mutatingGets[$file] ?? [], true)) {
            $reason = 'اتخطى — GET بيكتب (review_expire_shift_returns)';
        }
        $param = in_array($file, $actionFiles, true) ? 'action' : 'part';
        $jobs[] = compact('file', 'part', 'param', 'method', 'reason');
    }
}
if ($only !== null && $only !== '') {
    $jobs = array_values(array_filter(
        $jobs,
        static fn(array $job): bool => str_contains(
            $job['file'] . '?' . $job['param'] . '=' . $job['part'],
            $only
        )
    ));
    if (!$jobs) {
        fwrite(STDERR, "مفيش endpoint مطابق لـ --only=$only\n");
        exit(2);
    }
}

if ($listOnly) {
    foreach ($jobs as $job) {
        echo implode("\t", [$job['file'], $job['part'], $job['method'], $job['reason'] ?? '']) . PHP_EOL;
    }
    fwrite(STDERR, 'parts=' . count($jobs) . ' get=' . count(array_filter($jobs, fn($j) => $j['method'] === 'GET')) . PHP_EOL);
    exit(0);
}

if (!is_file($www . '/config.php')) {
    fwrite(STDERR, "Laragon config.php مش موجود في $www\n");
    exit(2);
}
require_once $www . '/config.php';
$admin = db();
$admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$adminThread = (int) $admin->query('SELECT CONNECTION_ID()')->fetchColumn();
$vars = [];
foreach (['general_log', 'log_output'] as $name) {
    $st = $admin->query("SHOW GLOBAL VARIABLES LIKE '" . $name . "'");
    $row = $st->fetch(PDO::FETCH_NUM);
    $vars[$name] = (string) ($row[1] ?? '');
}

$sensitiveTables = ['journal_entries', 'push_queue', 'notifications'];
$before = tableCounts($admin, $sensitiveTables);
$results = [];

try {
    $admin->exec("SET GLOBAL log_output = 'TABLE'");
    $admin->exec('SET GLOBAL general_log = ON');

    foreach ($jobs as $index => $job) {
        $label = $job['file'] . '?' . ($job['part'] === 'default' ? '' : $job['param'] . '=' . $job['part']);
        fwrite(STDERR, sprintf("[%d/%d] %s\n", $index + 1, count($jobs), $label));
        if ($job['reason'] !== null) {
            $results[] = $job + ['endpoint' => $label, 'status' => $job['reason']];
            continue;
        }

        $times = [];
        $queries = [];
        $last = null;
        for ($i = 0; $i < $runs; $i++) {
            $query = $common;
            if ($job['part'] !== 'default') {
                $query[$job['param']] = $job['part'];
            }
            if ($job['file'] === 'item_card_api.php') {
                unset($query['part']);
                if ($job['part'] === 'search') {
                    unset($query['item_id']);
                } elseif ($job['part'] === 'xlsx') {
                    $query['part'] = 'xlsx';
                } else {
                    unset($query['q']);
                }
            }

            $start = (string) $admin->query('SELECT NOW(6)')->fetchColumn();
            $last = runEndpoint($php, $runner, $www, $job['file'], $query);
            $end = (string) $admin->query('SELECT NOW(6)')->fetchColumn();
            $times[] = $last['ms'];

            $count = $admin->prepare(
                "SELECT COUNT(*) FROM mysql.general_log
                  WHERE event_time >= ? AND event_time <= ?
                    AND command_type IN ('Query', 'Execute') AND thread_id <> ?
                    AND argument NOT LIKE '%mysql.general_log%'"
            );
            $count->execute([$start, $end, $adminThread]);
            $queries[] = (int) $count->fetchColumn();
        }

        $body = (string) ($last['body'] ?? '');
        $decoded = json_decode($body, true);
        $apiStatus = is_array($decoded) ? ($decoded['ok'] ?? null) : null;
        $status = ($last['stderr'] ?? '') !== '' ? 'خطأ تشغيل: ' . $last['stderr'] : 'تم';
        if ($apiStatus === false) {
            $status = 'رد API فشل: ' . (string) ($decoded['error'] ?? 'غير معروف');
        } elseif (!is_array($decoded)) {
            $status = 'رد غير JSON';
        }
        $results[] = $job + [
            'endpoint' => $label,
            'status' => $status,
            'ms' => round(array_sum($times) / count($times), 1),
            'kb' => round(strlen($body) / 1024, 1),
            'rows' => is_array($decoded) ? maxRows($decoded) : null,
            'queries' => round(array_sum($queries) / count($queries), 1),
            'http_ok' => $apiStatus,
            'sample_error' => is_array($decoded) ? ($decoded['error'] ?? null) : null,
        ];
    }
} finally {
    try {
        $admin->exec('SET GLOBAL general_log = ' . (strtoupper($vars['general_log']) === 'ON' ? 'ON' : 'OFF'));
        $safeOutput = preg_replace('/[^A-Z,]/i', '', $vars['log_output']) ?: 'FILE';
        $admin->exec("SET GLOBAL log_output = '" . $safeOutput . "'");
    } catch (Throwable $e) {
        fwrite(STDERR, 'تحذير: تعذر إرجاع general_log: ' . $e->getMessage() . PHP_EOL);
    }
}

$after = tableCounts($admin, $sensitiveTables);
usort($results, static function (array $a, array $b): int {
    return ($b['ms'] ?? -1) <=> ($a['ms'] ?? -1);
});

$payload = [
    'generated_at' => date(DATE_ATOM),
    'command' => $php . ' ' . __FILE__,
    'runs' => $runs,
    'laragon_www' => $www,
    'general_log_before' => $vars,
    'sensitive_counts_before' => $before,
    'sensitive_counts_after' => $after,
    'counts_match' => $before === $after,
    'results' => $results,
];
$suffix = $only !== null && $only !== '' ? '_' . preg_replace('/[^a-z0-9]+/i', '_', $only) : '';
$jsonFile = rtrim(sys_get_temp_dir(), '/\\') . '/fagala_api_bench_results' . $suffix . '.json';
file_put_contents($jsonFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

echo "endpoint\tmethod\tms\tkb\trows\tqueries\tstatus\n";
foreach ($results as $row) {
    echo implode("\t", [
        $row['endpoint'], $row['method'], $row['ms'] ?? '', $row['kb'] ?? '',
        $row['rows'] ?? '', $row['queries'] ?? '', $row['status'],
    ]) . PHP_EOL;
}
fwrite(STDERR, 'counts_before=' . json_encode($before) . PHP_EOL);
fwrite(STDERR, 'counts_after=' . json_encode($after) . PHP_EOL);
fwrite(STDERR, 'results=' . $jsonFile . PHP_EOL);
exit($before === $after ? 0 : 3);
