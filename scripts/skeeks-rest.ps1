# Canonical REST client for the skeeks/cms-mcp package.
[CmdletBinding()]
param(
    [ValidateSet('metadata', 'tools', 'tools-index', 'tool-schema', 'context', 'openapi', 'execute')]
    [string]$Action = 'tools',

    [string]$Site = 'skeeks.com',

    [string]$CredentialPath,

    [string]$ToolName,

    [string]$ToolPattern,

    [string]$CachePath,

    [ValidateRange(0, 86400)]
    [int]$CacheMaxAgeSeconds = 300,

    [string]$ArgumentsJson = '{}',

    [string]$ArgumentsBase64,

    [string]$ArgumentsPath,

    [ValidateRange(0, 3600)]
    [int]$RefreshSkewSeconds = 120
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = [Console]::OutputEncoding
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false, $true)

function Read-Utf8File([string]$Path) {
    return [IO.File]::ReadAllText($Path, $Utf8NoBom)
}

function ConvertFrom-JsonDocument([string]$Value) {
    Add-Type -AssemblyName System.Web.Extensions
    $serializer = New-Object System.Web.Script.Serialization.JavaScriptSerializer
    $serializer.MaxJsonLength = [int]::MaxValue
    $serializer.RecursionLimit = 200
    return ,$serializer.DeserializeObject($Value)
}

function Convert-HexToBytes([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value) -or ($Value.Length % 2) -ne 0 -or $Value -notmatch '^[0-9a-fA-F]+$') {
        throw 'The OAuth credential store contains an invalid DPAPI value.'
    }

    $bytes = New-Object byte[] ($Value.Length / 2)
    for ($index = 0; $index -lt $bytes.Length; $index++) {
        $bytes[$index] = [Convert]::ToByte($Value.Substring($index * 2, 2), 16)
    }
    return $bytes
}

function Convert-BytesToHex([byte[]]$Value) {
    return ([BitConverter]::ToString($Value)).Replace('-', '').ToLowerInvariant()
}

function Unprotect-Secret([string]$Value) {
    $protected = Convert-HexToBytes $Value
    $plain = [System.Security.Cryptography.ProtectedData]::Unprotect(
        $protected,
        $null,
        [System.Security.Cryptography.DataProtectionScope]::CurrentUser
    )

    if ($plain.Length -ge 2 -and $plain[0] -eq 0xFF -and $plain[1] -eq 0xFE) {
        return [Text.Encoding]::Unicode.GetString($plain, 2, $plain.Length - 2).TrimEnd([char]0)
    }
    if ($plain.Length -ge 2 -and $plain[0] -eq 0xFE -and $plain[1] -eq 0xFF) {
        return [Text.Encoding]::BigEndianUnicode.GetString($plain, 2, $plain.Length - 2).TrimEnd([char]0)
    }
    if ($plain.Length -ge 3 -and $plain[0] -eq 0xEF -and $plain[1] -eq 0xBB -and $plain[2] -eq 0xBF) {
        return [Text.Encoding]::UTF8.GetString($plain, 3, $plain.Length - 3).TrimEnd([char]0)
    }

    $evenZeros = 0
    $oddZeros = 0
    for ($index = 0; $index -lt $plain.Length; $index++) {
        if ($plain[$index] -eq 0) {
            if (($index % 2) -eq 0) { $evenZeros++ } else { $oddZeros++ }
        }
    }
    $pairs = [Math]::Max(1, [Math]::Floor($plain.Length / 2))
    if (($oddZeros / $pairs) -gt 0.3 -and ($evenZeros / $pairs) -lt 0.1) {
        return [Text.Encoding]::Unicode.GetString($plain).TrimEnd([char]0)
    }
    if (($evenZeros / $pairs) -gt 0.3 -and ($oddZeros / $pairs) -lt 0.1) {
        return [Text.Encoding]::BigEndianUnicode.GetString($plain).TrimEnd([char]0)
    }

    $utf8 = New-Object System.Text.UTF8Encoding($false, $true)
    return $utf8.GetString($plain).TrimStart([char]0xFEFF).TrimEnd([char]0)
}

function Protect-Secret([string]$Value) {
    $plain = [Text.Encoding]::UTF8.GetBytes($Value)
    $protected = [System.Security.Cryptography.ProtectedData]::Protect(
        $plain,
        $null,
        [System.Security.Cryptography.DataProtectionScope]::CurrentUser
    )
    return Convert-BytesToHex $protected
}

function Assert-HttpsUri([string]$Value, [string]$Name) {
    $uri = $null
    if (![Uri]::TryCreate($Value, [UriKind]::Absolute, [ref]$uri)) {
        throw "$Name must be an absolute URI."
    }
    if ($uri.Scheme -ne 'https' -and !(($uri.Scheme -eq 'http') -and $uri.IsLoopback)) {
        throw "$Name must use HTTPS unless it targets localhost."
    }
    return $uri
}

function Save-CredentialStore($Store, [string]$Path) {
    $directory = Split-Path -Parent $Path
    $temporary = Join-Path $directory ('.' + [IO.Path]::GetFileName($Path) + '.' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $backup = $null
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($temporary, ($Store | ConvertTo-Json -Depth 10), $encoding)
    try {
        if (Test-Path -LiteralPath $Path) {
            $backup = Join-Path $directory ('.' + [IO.Path]::GetFileName($Path) + '.' + [Guid]::NewGuid().ToString('N') + '.bak')
            [IO.File]::Replace($temporary, $Path, $backup)
        } else {
            [IO.File]::Move($temporary, $Path)
        }
    } finally {
        if (Test-Path -LiteralPath $temporary) {
            Remove-Item -LiteralPath $temporary -Force
        }
        if ($backup -and (Test-Path -LiteralPath $backup)) {
            Remove-Item -LiteralPath $backup -Force
        }
    }
}

function Save-JsonCache($Value, [string]$Path) {
    $directory = Split-Path -Parent $Path
    if (!(Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
    $temporary = Join-Path $directory ('.' + [IO.Path]::GetFileName($Path) + '.' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $backup = $null
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($temporary, ($Value | ConvertTo-Json -Depth 100), $encoding)
    try {
        if (Test-Path -LiteralPath $Path) {
            $backup = Join-Path $directory ('.' + [IO.Path]::GetFileName($Path) + '.' + [Guid]::NewGuid().ToString('N') + '.bak')
            [IO.File]::Replace($temporary, $Path, $backup)
        } else {
            [IO.File]::Move($temporary, $Path)
        }
    } finally {
        if (Test-Path -LiteralPath $temporary) {
            Remove-Item -LiteralPath $temporary -Force
        }
        if ($backup -and (Test-Path -LiteralPath $backup)) {
            Remove-Item -LiteralPath $backup -Force
        }
    }
}

if (!$CredentialPath) {
    $CredentialPath = Join-Path $env:USERPROFILE ('.codex\oauth\' + $Site + '-rest-api.json')
}
$CredentialPath = [IO.Path]::GetFullPath($CredentialPath)

if (!(Test-Path -LiteralPath $CredentialPath)) {
    $loginScript = Join-Path $PSScriptRoot 'skeeks-rest-login.ps1'
    $powershellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    throw "OAuth credential store not found for $Site. Run: & '$powershellExe' -NoProfile -ExecutionPolicy Bypass -File '$loginScript' -Site '$Site'"
}

Add-Type -AssemblyName System.Security

$hash = [Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($CredentialPath.ToLowerInvariant()))
$credentialHash = Convert-BytesToHex $hash
$mutexName = 'Local\SkeeksRestOAuth-' + $credentialHash.Substring(0, 24)
$mutex = New-Object Threading.Mutex($false, $mutexName)
$lockTaken = $false
$accessToken = $null
$store = $null

try {
    try {
        $lockTaken = $mutex.WaitOne(30000)
    } catch [Threading.AbandonedMutexException] {
        $lockTaken = $true
    }
    if (!$lockTaken) {
        throw 'Timed out waiting for another OAuth refresh to finish.'
    }

    $store = Read-Utf8File $CredentialPath | ConvertFrom-Json
    foreach ($property in @('client_id', 'client_secret_dpapi', 'access_token_dpapi', 'refresh_token_dpapi', 'access_token_expires_at', 'token_endpoint', 'resource')) {
        if ($null -eq $store.$property -or [string]::IsNullOrWhiteSpace([string]$store.$property)) {
            throw "OAuth credential store is missing $property."
        }
    }

    $resourceUri = Assert-HttpsUri ([string]$store.resource) 'OAuth resource'
    $tokenUri = Assert-HttpsUri ([string]$store.token_endpoint) 'OAuth token endpoint'
    if ($resourceUri.AbsolutePath.TrimEnd('/') -notlike '*/cms/rest-api') {
        throw 'OAuth resource is not a SkeekS REST API endpoint.'
    }
    if ($resourceUri.Host -ne $tokenUri.Host) {
        throw 'OAuth resource and token endpoint must use the same host.'
    }

    $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    if (($now + $RefreshSkewSeconds) -ge [int64]$store.access_token_expires_at) {
        $refreshToken = Unprotect-Secret ([string]$store.refresh_token_dpapi)
        $clientSecret = Unprotect-Secret ([string]$store.client_secret_dpapi)
        try {
            $rotated = Invoke-RestMethod -Method Post -Uri $tokenUri.AbsoluteUri -ContentType 'application/x-www-form-urlencoded' -Body @{
                grant_type = 'refresh_token'
                client_id = [string]$store.client_id
                client_secret = $clientSecret
                refresh_token = $refreshToken
            }
        } finally {
            $refreshToken = $null
            $clientSecret = $null
        }

        if ([string]::IsNullOrWhiteSpace([string]$rotated.access_token) -or [string]::IsNullOrWhiteSpace([string]$rotated.refresh_token)) {
            throw 'OAuth refresh response did not contain the rotated token pair.'
        }

        $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        $store.access_token_dpapi = Protect-Secret ([string]$rotated.access_token)
        $store.refresh_token_dpapi = Protect-Secret ([string]$rotated.refresh_token)
        $store.access_token_expires_at = $now + [int64]$rotated.expires_in
        if ($null -ne $rotated.refresh_token_expires_in) {
            $store.refresh_token_expires_at = $now + [int64]$rotated.refresh_token_expires_in
        }
        $store.saved_at = $now
        Save-CredentialStore $store $CredentialPath
    }

    $accessToken = Unprotect-Secret ([string]$store.access_token_dpapi)
} finally {
    if ($lockTaken) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}

$resourceRoot = ([string]$store.resource).TrimEnd('/')
$siteKey = ([string]$resourceUri.Host).ToLowerInvariant() -replace '[^a-z0-9.-]', '_'
if (!$CachePath) {
    $CachePath = Join-Path $env:USERPROFILE ('.codex\cache\skeeks-cms\' + $siteKey + '\tools-' + $credentialHash.Substring(0, 16) + '.json')
}
$CachePath = [IO.Path]::GetFullPath($CachePath)
$method = 'GET'
$requestUri = $resourceRoot
$body = $null

switch ($Action) {
    'metadata' { $requestUri = $resourceRoot }
    'tools' { $requestUri = $resourceRoot + '/tools' }
    'tools-index' { $requestUri = $resourceRoot + '/tools/index' }
    'tool-schema' {
        if ([string]::IsNullOrWhiteSpace($ToolName) -or $ToolName -notmatch '^[a-z0-9_-]+$') {
            throw 'ToolName is required for tool-schema and must contain only lowercase ASCII letters, digits, underscores or hyphens.'
        }
        $requestUri = $resourceRoot + '/tools/' + [Uri]::EscapeDataString($ToolName)
    }
    'context' { $requestUri = $resourceRoot + '/context' }
    'openapi' { $requestUri = $resourceRoot + '/openapi' }
    'execute' {
        if ([string]::IsNullOrWhiteSpace($ToolName) -or $ToolName -notmatch '^[a-z0-9_-]+$') {
            throw 'ToolName is required for execute and must contain only lowercase ASCII letters, digits, underscores or hyphens.'
        }
        $argumentSources = @($ArgumentsPath, $ArgumentsBase64, $(if ($ArgumentsJson -ne '{}') { $ArgumentsJson } else { $null })) | Where-Object { $_ }
        if ($argumentSources.Count -gt 1) {
            throw 'Use only one of ArgumentsPath, ArgumentsBase64 or ArgumentsJson.'
        }
        if ($ArgumentsPath) {
            $rawArguments = Read-Utf8File $ArgumentsPath
        } elseif ($ArgumentsBase64) {
            try {
                $rawArguments = (New-Object System.Text.UTF8Encoding($false, $true)).GetString([Convert]::FromBase64String($ArgumentsBase64))
            } catch {
                throw 'ArgumentsBase64 must contain base64-encoded UTF-8 JSON.'
            }
        } else {
            $rawArguments = $ArgumentsJson
        }
        $arguments = $rawArguments | ConvertFrom-Json
        if ($null -eq $arguments -or $arguments -isnot [psobject]) {
            throw 'Tool arguments must be a JSON object.'
        }
        $body = $arguments | ConvertTo-Json -Depth 100 -Compress
        $method = 'POST'
        $requestUri = $resourceRoot + '/tools/' + [Uri]::EscapeDataString($ToolName)
    }
}

$headers = @{
    Authorization = 'Bearer ' + $accessToken
    Accept = 'application/json'
}
$cache = $null
$cacheStatus = if ($Action -eq 'tools') { 'miss' } else { 'not_used' }
$skipRequest = $false
$responseHeaders = $null
if ($Action -eq 'tools' -and (Test-Path -LiteralPath $CachePath)) {
    try {
        $cache = Read-Utf8File $CachePath | ConvertFrom-Json
        if ($null -eq $cache.response -or $null -eq $cache.response.tools -or [string]$cache.resource -ne $resourceRoot) {
            $cache = $null
        }
    } catch {
        $cache = $null
    }
}
if ($Action -eq 'tools' -and $cache -and $CacheMaxAgeSeconds -gt 0) {
    $cacheAge = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - [int64]$cache.fetched_at
    if ($cacheAge -ge 0 -and $cacheAge -le $CacheMaxAgeSeconds) {
        $response = $cache.response
        $cacheStatus = 'hit'
        $skipRequest = $true
    }
}
if ($Action -eq 'tools' -and !$skipRequest -and $cache -and ![string]::IsNullOrWhiteSpace([string]$cache.etag)) {
    $headers['If-None-Match'] = [string]$cache.etag
}

$stopwatch = [Diagnostics.Stopwatch]::StartNew()
try {
    if ($skipRequest) {
        # The authorized catalog is fresh in the local, credential-specific cache.
    } elseif ($method -eq 'POST') {
        $webResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri $requestUri -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $body
        $responseHeaders = $webResponse.Headers
        $response = ConvertFrom-JsonDocument $webResponse.Content
    } elseif ($Action -eq 'tools') {
        try {
            $webResponse = Invoke-WebRequest -UseBasicParsing -Method Get -Uri $requestUri -Headers $headers
            $responseHeaders = $webResponse.Headers
            $response = ConvertFrom-JsonDocument $webResponse.Content
            $cacheStatus = 'updated'
            $etag = [string]$webResponse.Headers['ETag']
            $cache = [pscustomobject]@{
                resource = $resourceRoot
                etag = $etag
                api_version = [string]$response.api_version
                server_version = [string]$response.server_version
                tools_revision = [string]$response.tools_revision
                fetched_at = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
                response = $response
            }
            Save-JsonCache $cache $CachePath
        } catch {
            $statusCode = 0
            if ($null -ne $_.Exception.Response -and $null -ne $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
            if ($statusCode -ne 304 -or !$cache) {
                throw
            }
            $validatedAt = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
            if ($null -ne $cache.PSObject.Properties['fetched_at']) {
                $cache.fetched_at = $validatedAt
            } else {
                $cache | Add-Member -NotePropertyName fetched_at -NotePropertyValue $validatedAt
            }
            Save-JsonCache $cache $CachePath
            $response = $cache.response
            $cacheStatus = 'validated'
        }
    } else {
        $response = Invoke-RestMethod -Method Get -Uri $requestUri -Headers $headers
    }
} finally {
    $stopwatch.Stop()
    $accessToken = $null
    $headers.Authorization = $null
    $headers['If-None-Match'] = $null
}

if ($response -is [string] -and $response.TrimStart().StartsWith('{')) {
    $response = $response | ConvertFrom-Json
}

if ($Action -eq 'tools' -and $ToolPattern) {
    $response.tools = @($response.tools | Where-Object {
        ([string]$_.name -match $ToolPattern) -or ([string]$_.description -match $ToolPattern)
    })
    $response.returned_count = @($response.tools).Count
}

$apiVersion = if ($responseHeaders -and $responseHeaders['X-Skeeks-Api-Version']) {
    [string]$responseHeaders['X-Skeeks-Api-Version']
} elseif ($response.api_version) {
    [string]$response.api_version
} elseif ($cache) {
    [string]$cache.api_version
} else { $null }
$serverVersion = if ($responseHeaders -and $responseHeaders['X-Skeeks-Server-Version']) {
    [string]$responseHeaders['X-Skeeks-Server-Version']
} elseif ($response.server_version) {
    [string]$response.server_version
} elseif ($cache) {
    [string]$cache.server_version
} else { $null }
$toolsRevision = if ($responseHeaders -and $responseHeaders['X-Skeeks-Tools-Revision']) {
    [string]$responseHeaders['X-Skeeks-Tools-Revision']
} elseif ($response.tools_revision) {
    [string]$response.tools_revision
} elseif ($cache) {
    [string]$cache.tools_revision
} else { $null }

[pscustomobject]@{
    action = $Action
    tool = if ($Action -eq 'execute' -or $Action -eq 'tool-schema') { $ToolName } else { $null }
    duration_ms = $stopwatch.ElapsedMilliseconds
    cache_status = $cacheStatus
    api_version = $apiVersion
    server_version = $serverVersion
    tools_revision = $toolsRevision
    response = $response
} | ConvertTo-Json -Depth 100
