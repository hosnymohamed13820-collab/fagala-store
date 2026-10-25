param(
    [switch]$NewKey,
    [string]$KeyPath = (Join-Path $env:USERPROFILE '.fagala\agent_sign_key.xml'),
    [string]$OutputDir = ''
)

$ErrorActionPreference = 'Stop'
$RepoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
if (-not $OutputDir) { $OutputDir = Join-Path $RepoRoot 'site\agent_dist' }
$KeyPath = [IO.Path]::GetFullPath($KeyPath)
$OutputDir = [IO.Path]::GetFullPath($OutputDir)

function New-SigningRsa {
    $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider 3072
    $rsa.PersistKeyInCsp = $false
    return $rsa
}

if ($NewKey) {
    if (Test-Path -LiteralPath $KeyPath) {
        throw "Signing key already exists: $KeyPath"
    }
    $keyDir = Split-Path -Parent $KeyPath
    if (-not (Test-Path -LiteralPath $keyDir)) {
        [void](New-Item -ItemType Directory -Path $keyDir)
    }
    $rsa = New-SigningRsa
    try {
        [IO.File]::WriteAllText($KeyPath, $rsa.ToXmlString($true), (New-Object Text.UTF8Encoding($false)))
        Write-Host 'Public key (put this in the agents):'
        Write-Output $rsa.ToXmlString($false)
    } finally {
        $rsa.Dispose()
    }
    exit 0
}

if (-not (Test-Path -LiteralPath $KeyPath)) {
    throw "Signing key not found: $KeyPath (run with -NewKey once)"
}

$privateXml = [IO.File]::ReadAllText($KeyPath, [Text.Encoding]::UTF8)
$rsa = New-SigningRsa
try {
    $rsa.FromXmlString($privateXml)
    $publicXml = $rsa.ToXmlString($false)
    $utf8Strict = New-Object Text.UTF8Encoding($false, $true)
    $agents = @(
        'local\agents\agent_hq.ps1',
        'local\agents\agent_FAGALA.ps1',
        'local\agents\agent_BONJOUR.ps1',
        'local\agents\agent_DEGLA.ps1',
        'local\agents\agent_ABC.ps1'
    )

    if (-not (Test-Path -LiteralPath $OutputDir)) {
        [void](New-Item -ItemType Directory -Path $OutputDir)
    }

    foreach ($relative in $agents) {
        $source = Join-Path $RepoRoot $relative
        $bytes = [IO.File]::ReadAllBytes($source)
        $text = $utf8Strict.GetString($bytes)

        $keyMatch = [regex]::Match($text, "(?m)^\s*\`$AgentKey\s*=\s*'([^']+)'\s*(?:#.*)?$")
        $verMatch = [regex]::Match($text, "(?m)^\s*\`$AgentVer\s*=\s*'([^']+)'\s*(?:#.*)?$")
        if (-not $keyMatch.Success -or -not $verMatch.Success) {
            throw "Could not read AgentKey/AgentVer from $relative"
        }
        $agentKey = $keyMatch.Groups[1].Value
        $version = $verMatch.Groups[1].Value

        if ($text.Contains('__PUBKEY__')) {
            Write-Warning "$relative still has __PUBKEY__; replacing it in the signed distribution copy."
            $text = $text.Replace('__PUBKEY__', $publicXml)
            $bytes = $utf8Strict.GetBytes($text)
        } else {
            $pubMatch = [regex]::Match($text, "(?m)^\s*\`$UpdatePubKey\s*=\s*'([^']+)'\s*(?:#.*)?$")
            if (-not $pubMatch.Success -or $pubMatch.Groups[1].Value -ne $publicXml) {
                throw "Public key in $relative does not match $KeyPath"
            }
        }

        $sha = ([Security.Cryptography.SHA256]::Create().ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join ''
        $signedText = "$agentKey|$version|$sha"
        $signature = $rsa.SignData([Text.Encoding]::UTF8.GetBytes($signedText), [Security.Cryptography.CryptoConfig]::MapNameToOID('SHA256'))

        [IO.File]::WriteAllBytes((Join-Path $OutputDir "$agentKey.ps1"), $bytes)
        $manifest = [ordered]@{
            agent   = $agentKey
            version = $version
            sha256  = $sha
            sig     = [Convert]::ToBase64String($signature)
        } | ConvertTo-Json -Compress
        [IO.File]::WriteAllText((Join-Path $OutputDir "$agentKey.json"), $manifest, (New-Object Text.UTF8Encoding($false)))
        Write-Host "Signed $agentKey v$version ($sha)"
    }
} finally {
    $rsa.Dispose()
}
