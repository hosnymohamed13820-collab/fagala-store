<?php
/**
 * تنزيل نسخة الوكيل المتوقعة. التوكن بيحدد صاحب الملف ومينفعش يسحب غيره.
 */
declare(strict_types=1);

require_once __DIR__ . '/sync_tokens.php';

header('Cache-Control: no-store');

function update_die(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    update_die('GET only.', 405);
}

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if ($token === '') {
    update_die('Unauthorized.', 401);
}

$requested = trim((string) ($_GET['agent'] ?? ''));
$allowed = ['hq', 'zahraa', 'bonjour', 'degla', 'abc'];
if (!in_array($requested, $allowed, true)) {
    update_die('Bad agent key.', 400);
}

$caller = null;
foreach ($allowed as $agent) {
    $expected = (string) (SYNC_AGENT_TOKENS[$agent] ?? '');
    if ($expected !== '' && hash_equals($expected, $token)) {
        $caller = $agent;
        break;
    }
}
if ($caller === null) {
    update_die('Unauthorized.', 401);
}
if ($caller !== $requested) {
    update_die('Forbidden.', 403);
}

$path = __DIR__ . '/agent_dist/' . $requested . '.ps1';
if (!is_file($path) || !is_readable($path)) {
    update_die('Update not found.', 404);
}

$size = filesize($path);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $requested . '.ps1"');
if ($size !== false) {
    header('Content-Length: ' . $size);
}
readfile($path);
