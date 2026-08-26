<?php
/** stub_auth.php — بديل auth.php للاختبار المحلي على SQLite */

$GLOBALS['__pdo'] = null;
$GLOBALS['__settings'] = [
    'shortage_dosta'      => '12',
    'shortage_hq_store'   => '1',
    'shortage_branch_ids' => '2,3,5,6',
];

function db(): PDO
{
    if ($GLOBALS['__pdo'] === null) {
        $p = new PDO('sqlite::memory:');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $GLOBALS['__pdo'] = $p;
    }
    return $GLOBALS['__pdo'];
}

function setting(string $k, $def = null)
{
    return $GLOBALS['__settings'][$k] ?? $def;
}

function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function current_user(): array { return ['id' => 1, 'full_name' => 'اختبار']; }
function csrf_token(): string { return 'test'; }
function csrf_verify(?string $t): bool { return $t === 'test'; }
function require_permission(string $m): void {}
function is_logged_in(): bool { return true; }
function can(string $m): bool { return true; }
