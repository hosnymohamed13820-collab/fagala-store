param(
    [string]$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$TempRoot = (Join-Path $RepoRoot '.tmp_b222')
)

$ErrorActionPreference = 'Stop'
$DistDir = Join-Path $TempRoot 'dist'
$KeyPath = Join-Path $TempRoot 'test_key.xml'

function Assert-True {
    param([bool]$Value, [string]$Message)
    if (-not $Value) { throw $Message }
}

function Get-Sha256 {
    param([byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') }) -join '' }
    finally { $sha.Dispose() }
}

if (-not (Test-Path -LiteralPath $KeyPath) -or -not (Test-Path -LiteralPath $DistDir)) {
    throw 'Run sign_agents.ps1 -NewKey and sign into .tmp_b222/dist first.'
}

$rsa = New-Object Security.Cryptography.RSACryptoServiceProvider
try {
    $rsa.PersistKeyInCsp = $false
    $rsa.FromXmlString([IO.File]::ReadAllText($KeyPath, [Text.Encoding]::UTF8))
    $publicXml = $rsa.ToXmlString($false)

    $expected = @('hq', 'zahraa', 'bonjour', 'degla', 'abc')
    foreach ($key in $expected) {
        $ps1 = Join-Path $DistDir "$key.ps1"
        $json = Join-Path $DistDir "$key.json"
        Assert-True ((Test-Path -LiteralPath $ps1) -and (Test-Path -LiteralPath $json)) "Missing distribution files for $key"
        $bytes = [IO.File]::ReadAllBytes($ps1)
        $manifest = Get-Content -Raw -LiteralPath $json | ConvertFrom-Json
        $sha = Get-Sha256 $bytes
        Assert-True ($manifest.sha256 -eq $sha) "SHA mismatch for $key"
        $sig = [Convert]::FromBase64String([string]$manifest.sig)
        $payload = [Text.Encoding]::UTF8.GetBytes("$key|$($manifest.version)|$sha")
        Assert-True ($rsa.VerifyData($payload, [Security.Cryptography.CryptoConfig]::MapNameToOID('SHA256'), $sig)) "Signature mismatch for $key"
    }
    Write-Host 'CASE 1 OK: 5 ps1 + 5 json; hashes and signatures match'

    $source = Join-Path $RepoRoot 'local\agents\agent_FAGALA.ps1'
    $tokens = $null
    $errors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($source, [ref]$tokens, [ref]$errors)
    Assert-True ($errors.Count -eq 0) 'Source agent does not parse'
    $wanted = @('Get-UpdateState', 'Save-UpdateState', 'Write-BadUpdateSignature', 'Invoke-SelfUpdate')
    foreach ($node in $ast.FindAll({ param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst] -and $wanted -contains $n.Name }, $true)) {
        Invoke-Expression $node.Extent.Text
    }
    foreach ($name in $wanted) { Assert-True ($null -ne (Get-Command $name -ErrorAction SilentlyContinue)) "Missing function $name" }

    $script:AgentKey = 'zahraa'
    $script:AgentVer = '1.5'
    $script:UpdatePubKey = $publicXml
    $script:UpdateUrl = 'file://unused'
    $script:SyncToken = 'test'
    function Write-Log { param([string]$Message) Write-Host $Message }

    $manifest = Get-Content -Raw -LiteralPath (Join-Path $DistDir 'zahraa.json') | ConvertFrom-Json
    $goodBytes = [IO.File]::ReadAllBytes((Join-Path $DistDir 'zahraa.ps1'))
    $work = Join-Path $TempRoot ('selfupdate_' + [guid]::NewGuid().ToString('N'))
    [void](New-Item -ItemType Directory -Path $work)

    $target = Join-Path $work 'valid.ps1'
    $oldBytes = [Text.Encoding]::ASCII.GetBytes('old agent bytes')
    [IO.File]::WriteAllBytes($target, $oldBytes)
    $script:UpdateStateFile = Join-Path $work 'valid-state.json'
    $ok = Invoke-SelfUpdate -Update $manifest -TargetPath $target -ContentBytes $goodBytes
    Assert-True ($ok -and ((Get-Sha256 ([IO.File]::ReadAllBytes($target))) -eq $manifest.sha256)) 'Valid update was not installed byte-for-byte'
    Assert-True ((Get-Sha256 ([IO.File]::ReadAllBytes("$target.bak"))) -eq (Get-Sha256 $oldBytes)) 'Valid update backup is wrong'
    Write-Host 'CASE 2 OK: valid signed update replaced bytes and made .bak'

    $target = Join-Path $work 'tampered.ps1'
    [IO.File]::WriteAllBytes($target, $oldBytes)
    $tampered = [byte[]]$goodBytes.Clone()
    $tampered[$tampered.Length - 1] = $tampered[$tampered.Length - 1] -bxor 1
    $script:UpdateStateFile = Join-Path $work 'tampered-state.json'
    $ok = Invoke-SelfUpdate -Update $manifest -TargetPath $target -ContentBytes $tampered
    Assert-True ((-not $ok) -and ((Get-Sha256 ([IO.File]::ReadAllBytes($target))) -eq (Get-Sha256 $oldBytes))) 'Tampered update changed the target'
    Write-Host 'CASE 3 OK: one-byte tamper rejected; target unchanged'

    $target = Join-Path $work 'wrong-key.ps1'
    [IO.File]::WriteAllBytes($target, $oldBytes)
    $other = New-Object Security.Cryptography.RSACryptoServiceProvider 3072
    try {
        $other.PersistKeyInCsp = $false
        $payload = [Text.Encoding]::UTF8.GetBytes("zahraa|$($manifest.version)|$($manifest.sha256)")
        $badSig = $other.SignData($payload, [Security.Cryptography.CryptoConfig]::MapNameToOID('SHA256'))
    } finally { $other.Dispose() }
    $badManifest = [pscustomobject]@{ version = $manifest.version; sha256 = $manifest.sha256; sig = [Convert]::ToBase64String($badSig) }
    $script:UpdateStateFile = Join-Path $work 'wrong-key-state.json'
    $ok = Invoke-SelfUpdate -Update $badManifest -TargetPath $target -ContentBytes $goodBytes
    Assert-True ((-not $ok) -and ((Get-Sha256 ([IO.File]::ReadAllBytes($target))) -eq (Get-Sha256 $oldBytes))) 'Wrong-key update changed the target'
    Write-Host 'CASE 4 OK: signature from another key rejected'

    $target = Join-Path $work 'parse-error.ps1'
    [IO.File]::WriteAllBytes($target, $oldBytes)
    $badScript = [Text.Encoding]::UTF8.GetBytes("`$AgentKey = 'zahraa'`n`$AgentVer = '1.6'`nfunction Broken {`n")
    $badSha = Get-Sha256 $badScript
    $badPayload = [Text.Encoding]::UTF8.GetBytes("zahraa|1.6|$badSha")
    $badSigned = $rsa.SignData($badPayload, [Security.Cryptography.CryptoConfig]::MapNameToOID('SHA256'))
    $parseManifest = [pscustomobject]@{ version = '1.6'; sha256 = $badSha; sig = [Convert]::ToBase64String($badSigned) }
    $script:UpdateStateFile = Join-Path $work 'parse-state.json'
    $ok = Invoke-SelfUpdate -Update $parseManifest -TargetPath $target -ContentBytes $badScript
    Assert-True ((-not $ok) -and ((Get-Sha256 ([IO.File]::ReadAllBytes($target))) -eq (Get-Sha256 $oldBytes))) 'Parse-error update changed the target'
    Write-Host 'CASE 5 OK: correctly signed parse error rejected'

    $sourceMap = @{
        hq = 'agent_hq.ps1'; zahraa = 'agent_FAGALA.ps1'; bonjour = 'agent_BONJOUR.ps1';
        degla = 'agent_DEGLA.ps1'; abc = 'agent_ABC.ps1'
    }
    foreach ($key in $expected) {
        $sourceBytes = [IO.File]::ReadAllBytes((Join-Path $RepoRoot ('local\agents\' + $sourceMap[$key])))
        $distBytes = [IO.File]::ReadAllBytes((Join-Path $DistDir "$key.ps1"))
        [void][ScriptBlock]::Create((New-Object Text.UTF8Encoding($false, $true)).GetString($distBytes))
        Assert-True (($sourceBytes[0..2] -join ',') -eq ($distBytes[0..2] -join ',')) "Encoding prefix changed for $key"
    }
    Write-Host 'CASE 8 OK: all 5 distributed agents parse; first 3 bytes unchanged'
} finally {
    $rsa.Dispose()
}
