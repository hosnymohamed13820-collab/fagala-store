<?php
/** دوال قراءة وصف نسخة الوكيل من غير اعتماد على قاعدة البيانات. */
declare(strict_types=1);

/** قراءة وصف نسخة توزيع موثوق في شكله؛ التوقيع نفسه بيتراجع جوه الوكيل. */
function agent_dist_manifest(string $key): ?array
{
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $key)) return null;

    $path = __DIR__ . '/agent_dist/' . $key . '.json';
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) > 32768) return null;

    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['agent'] ?? '') !== $key) return null;

    $version = trim((string) ($data['version'] ?? ''));
    $sha256 = strtolower(trim((string) ($data['sha256'] ?? '')));
    $sig = trim((string) ($data['sig'] ?? ''));
    if (!preg_match('/^\d+(?:\.\d+){1,3}$/', $version)) return null;
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) return null;
    if ($sig === '' || base64_decode($sig, true) === false) return null;

    return ['version' => $version, 'sha256' => $sha256, 'sig' => $sig];
}

/** النسخة المتاحة لو أحدث فعلاً من نسخة الوكيل الحالية. */
function agent_dist_update(string $key, string $currentVersion): ?array
{
    $manifest = agent_dist_manifest($key);
    if ($manifest === null || $currentVersion === '') return null;
    return version_compare($manifest['version'], $currentVersion, '>') ? $manifest : null;
}
