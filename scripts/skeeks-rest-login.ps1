# Canonical OAuth authorization client for the SkeekS REST adapter.
[CmdletBinding()]
param(
    [string]$Site = 'skeeks.com',

    [string]$CredentialPath,

    [string]$ClientName,

    [ValidateRange(30, 900)]
    [int]$TimeoutSeconds = 300,

    [switch]$ForceAuthorization
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = [Console]::OutputEncoding
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false, $true)

function Convert-BytesToHex([byte[]]$Value) {
    return ([BitConverter]::ToString($Value)).Replace('-', '').ToLowerInvariant()
}

function Convert-ToBase64Url([byte[]]$Value) {
    return [Convert]::ToBase64String($Value).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function New-RandomBytes([int]$Length) {
    $bytes = New-Object byte[] $Length
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return $bytes
}

function Protect-Secret([string]$Value) {
    $plain = [Text.Encoding]::UTF8.GetBytes($Value)
    $protected = [Security.Cryptography.ProtectedData]::Protect(
        $plain,
        $null,
        [Security.Cryptography.DataProtectionScope]::CurrentUser
    )
    return Convert-BytesToHex $protected
}

function Read-Utf8Json([string]$Path) {
    return [IO.File]::ReadAllText($Path, $Utf8NoBom) | ConvertFrom-Json
}

function Save-CredentialStore($Store, [string]$Path) {
    $directory = Split-Path -Parent $Path
    if (!(Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $temporary = Join-Path $directory ('.' + [IO.Path]::GetFileName($Path) + '.' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $backup = $null
    [IO.File]::WriteAllText($temporary, ($Store | ConvertTo-Json -Depth 10), $Utf8NoBom)
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

function Assert-HttpsEndpoint([string]$Value, [string]$ExpectedHost, [string]$Name) {
    $uri = $null
    if (![Uri]::TryCreate($Value, [UriKind]::Absolute, [ref]$uri)) {
        throw "$Name is not an absolute URI."
    }
    if ($uri.Scheme -ne 'https' -or $uri.Host -ne $ExpectedHost) {
        throw "$Name must use HTTPS on $ExpectedHost."
    }
    return $uri
}

function Convert-ToQueryString([System.Collections.IDictionary]$Values) {
    $pairs = foreach ($entry in $Values.GetEnumerator()) {
        if ($null -ne $entry.Value -and [string]$entry.Value -ne '') {
            [Uri]::EscapeDataString([string]$entry.Key) + '=' + [Uri]::EscapeDataString([string]$entry.Value)
        }
    }
    return $pairs -join '&'
}

function Start-LoopbackListener([string]$PreferredRedirectUri) {
    if ($PreferredRedirectUri) {
        $redirect = [Uri]$PreferredRedirectUri
        if ($redirect.Scheme -ne 'http' -or $redirect.Host -notin @('127.0.0.1', 'localhost') -or $redirect.Port -le 0) {
            throw 'The stored OAuth redirect URI is not an allowed loopback URI.'
        }
        $prefix = $redirect.GetLeftPart([UriPartial]::Path)
        if (!$prefix.EndsWith('/')) { $prefix += '/' }
        $listener = New-Object Net.HttpListener
        $listener.Prefixes.Add($prefix)
        $listener.Start()
        return [pscustomobject]@{ listener = $listener; redirect_uri = $prefix }
    }

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $random = New-RandomBytes 2
        $port = 49152 + (([int]$random[0] * 256 + [int]$random[1]) % 16383)
        $redirectUri = "http://127.0.0.1:$port/oauth/callback/"
        $listener = New-Object Net.HttpListener
        try {
            $listener.Prefixes.Add($redirectUri)
            $listener.Start()
            return [pscustomobject]@{ listener = $listener; redirect_uri = $redirectUri }
        } catch {
            $listener.Close()
        }
    }
    throw 'Could not allocate a loopback port for the OAuth callback.'
}

function Send-CallbackPage($Context, [int]$StatusCode, [string]$Title, [string]$Message) {
    $safeTitle = [Net.WebUtility]::HtmlEncode($Title)
    $safeMessage = [Net.WebUtility]::HtmlEncode($Message)
    $html = "<!doctype html><html lang=`"ru`"><head><meta charset=`"utf-8`"><title>$safeTitle</title></head><body style=`"font-family:system-ui;margin:3rem;max-width:44rem`"><h1>$safeTitle</h1><p>$safeMessage</p></body></html>"
    $bytes = [Text.Encoding]::UTF8.GetBytes($html)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = 'text/html; charset=utf-8'
    $Context.Response.ContentLength64 = $bytes.Length
    try {
        $Context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    } catch {
        # The browser may close the loopback connection after navigation.
    } finally {
        try { $Context.Response.OutputStream.Close() } catch {}
    }
}

function Wait-AuthorizationCode($Listener, [string]$ExpectedState, [int]$Timeout) {
    $deadline = [DateTimeOffset]::UtcNow.AddSeconds($Timeout)
    while ([DateTimeOffset]::UtcNow -lt $deadline) {
        $remaining = [int][Math]::Max(1, ($deadline - [DateTimeOffset]::UtcNow).TotalMilliseconds)
        $contextTask = $Listener.GetContextAsync()
        if (!$contextTask.Wait($remaining)) {
            break
        }

        $context = $contextTask.Result
        $query = $context.Request.QueryString
        $state = [string]$query['state']
        $code = [string]$query['code']
        $errorCode = [string]$query['error']

        if (!$code -and !$errorCode) {
            Send-CallbackPage $context 200 'OAuth is waiting for approval' 'Return to the authorization page and approve access.'
            continue
        }
        if ($state -ne $ExpectedState) {
            Send-CallbackPage $context 400 'OAuth callback rejected' 'The state value did not match. Waiting for the correct callback.'
            continue
        }
        if ($errorCode) {
            Send-CallbackPage $context 400 'Authorization declined' 'The site did not grant access to the application.'
            throw "OAuth authorization failed: $errorCode"
        }

        Send-CallbackPage $context 200 'Authorization complete' 'Access was saved. You can close this tab.'
        return $code
    }
    throw "OAuth authorization timed out after $Timeout seconds."
}

$originText = if ($Site -match '^https?://') { $Site } else { 'https://' + $Site }
$originUri = [Uri]$originText
if ($originUri.Scheme -ne 'https' -and !(($originUri.Scheme -eq 'http') -and $originUri.IsLoopback)) {
    throw 'Site must use HTTPS unless it targets localhost.'
}
$origin = $originUri.GetLeftPart([UriPartial]::Authority).TrimEnd('/')
$siteKey = $originUri.Host.ToLowerInvariant()

if (!$CredentialPath) {
    $CredentialPath = Join-Path $env:USERPROFILE ('.codex\oauth\' + $siteKey + '-rest-api.json')
}
$CredentialPath = [IO.Path]::GetFullPath($CredentialPath)
$powershellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$restScript = Join-Path $PSScriptRoot 'skeeks-rest.ps1'

if ((Test-Path -LiteralPath $CredentialPath) -and !$ForceAuthorization) {
    [pscustomobject]@{
        status = 'already_authorized'
        site = $siteKey
        credential_path = $CredentialPath
        next_command = "& '$powershellExe' -NoProfile -ExecutionPolicy Bypass -File '$restScript' -Site '$siteKey' -Action tools-index"
    } | ConvertTo-Json
    exit 0
}

Add-Type -AssemblyName System.Security

$resourceMetadataUri = $origin + '/.well-known/oauth-protected-resource/cms/rest-api'
$resourceMetadata = Invoke-RestMethod -Method Get -Uri $resourceMetadataUri -Headers @{ Accept = 'application/json' }
$resourceUri = Assert-HttpsEndpoint ([string]$resourceMetadata.resource) $originUri.Host 'OAuth resource'
if ($resourceUri.AbsoluteUri.TrimEnd('/') -ne ($origin + '/cms/rest-api')) {
    throw 'The protected-resource metadata returned an unexpected resource URI.'
}
if (!$resourceMetadata.authorization_servers -or @($resourceMetadata.authorization_servers).Count -ne 1) {
    throw 'The protected-resource metadata must advertise one authorization server.'
}

$issuerUri = Assert-HttpsEndpoint ([string]@($resourceMetadata.authorization_servers)[0]) $originUri.Host 'OAuth issuer'
$authorizationMetadataUri = $origin + '/.well-known/oauth-authorization-server' + $issuerUri.AbsolutePath.TrimEnd('/')
$authorizationMetadata = Invoke-RestMethod -Method Get -Uri $authorizationMetadataUri -Headers @{ Accept = 'application/json' }
$authorizationEndpoint = Assert-HttpsEndpoint ([string]$authorizationMetadata.authorization_endpoint) $originUri.Host 'OAuth authorization endpoint'
$tokenEndpoint = Assert-HttpsEndpoint ([string]$authorizationMetadata.token_endpoint) $originUri.Host 'OAuth token endpoint'
$registrationEndpoint = Assert-HttpsEndpoint ([string]$authorizationMetadata.registration_endpoint) $originUri.Host 'OAuth registration endpoint'
if (@($authorizationMetadata.code_challenge_methods_supported) -notcontains 'S256') {
    throw 'The OAuth server does not advertise PKCE S256.'
}

$existingStore = $null
if (Test-Path -LiteralPath $CredentialPath) {
    try { $existingStore = Read-Utf8Json $CredentialPath } catch { $existingStore = $null }
}
$preferredRedirect = if ($existingStore -and $existingStore.dynamic_scope -eq $true) { [string]$existingStore.redirect_uri } else { $null }
$callback = Start-LoopbackListener $preferredRedirect
$listener = $callback.listener
$redirectUri = [string]$callback.redirect_uri

$clientId = $null
$clientSecret = $null
$registeredNewClient = $true
try {
    if ($preferredRedirect -and $existingStore.client_id -and $existingStore.client_secret_dpapi) {
        $registeredNewClient = $false
        $clientId = [string]$existingStore.client_id
        $protectedSecret = for ($index = 0; $index -lt ([string]$existingStore.client_secret_dpapi).Length; $index += 2) {
            [Convert]::ToByte(([string]$existingStore.client_secret_dpapi).Substring($index, 2), 16)
        }
        $clientSecretBytes = [Security.Cryptography.ProtectedData]::Unprotect(
            [byte[]]$protectedSecret,
            $null,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
        $clientSecret = [Text.Encoding]::UTF8.GetString($clientSecretBytes)
    } else {
        if (!$ClientName) { $ClientName = 'Codex SkeekS REST - ' + $env:COMPUTERNAME }
        $registration = Invoke-RestMethod -Method Post -Uri $registrationEndpoint.AbsoluteUri -ContentType 'application/json; charset=utf-8' -Body (@{
            client_name = $ClientName
            redirect_uris = @($redirectUri)
        } | ConvertTo-Json -Compress)
        $clientId = [string]$registration.client_id
        $clientSecret = [string]$registration.client_secret
        $registration = $null
        if (!$clientId -or !$clientSecret) {
            throw 'Dynamic client registration did not return client credentials.'
        }
    }

    $codeVerifier = Convert-ToBase64Url (New-RandomBytes 64)
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try {
        $codeChallenge = Convert-ToBase64Url ($sha256.ComputeHash([Text.Encoding]::ASCII.GetBytes($codeVerifier)))
    } finally {
        $sha256.Dispose()
    }
    $state = Convert-ToBase64Url (New-RandomBytes 32)
    $authorizeQuery = [ordered]@{
        response_type = 'code'
        client_id = $clientId
        redirect_uri = $redirectUri
        code_challenge = $codeChallenge
        code_challenge_method = 'S256'
        resource = $resourceUri.AbsoluteUri.TrimEnd('/')
        state = $state
    }
    $authorizeUrl = $authorizationEndpoint.AbsoluteUri + '?' + (Convert-ToQueryString $authorizeQuery)

    Start-Process -FilePath $authorizeUrl | Out-Null
    $authorizationCode = Wait-AuthorizationCode $listener $state $TimeoutSeconds

    $tokens = Invoke-RestMethod -Method Post -Uri $tokenEndpoint.AbsoluteUri -ContentType 'application/x-www-form-urlencoded' -Body @{
        grant_type = 'authorization_code'
        client_id = $clientId
        client_secret = $clientSecret
        code = $authorizationCode
        redirect_uri = $redirectUri
        code_verifier = $codeVerifier
        resource = $resourceUri.AbsoluteUri.TrimEnd('/')
    }
    if (!$tokens.access_token -or !$tokens.refresh_token) {
        throw 'The OAuth token response did not contain an access and refresh token pair.'
    }

    $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $store = [ordered]@{
        client_id = $clientId
        client_secret_dpapi = Protect-Secret $clientSecret
        access_token_dpapi = Protect-Secret ([string]$tokens.access_token)
        refresh_token_dpapi = Protect-Secret ([string]$tokens.refresh_token)
        access_token_expires_at = $now + [int64]$tokens.expires_in
        refresh_token_expires_at = if ($tokens.refresh_token_expires_in) { $now + [int64]$tokens.refresh_token_expires_in } else { $null }
        token_endpoint = $tokenEndpoint.AbsoluteUri
        authorization_endpoint = $authorizationEndpoint.AbsoluteUri
        resource = $resourceUri.AbsoluteUri.TrimEnd('/')
        redirect_uri = $redirectUri
        dynamic_scope = $true
        saved_at = $now
    }
    Save-CredentialStore $store $CredentialPath

    [pscustomobject]@{
        status = 'authorized'
        site = $siteKey
        resource = $store.resource
        credential_path = $CredentialPath
        access_token_expires_at = $store.access_token_expires_at
        refresh_token_expires_at = $store.refresh_token_expires_at
        registered_new_client = $registeredNewClient
        next_command = "& '$powershellExe' -NoProfile -ExecutionPolicy Bypass -File '$restScript' -Site '$siteKey' -Action tools-index"
    } | ConvertTo-Json
} finally {
    $clientSecret = $null
    $clientSecretBytes = $null
    $protectedSecret = $null
    $tokens = $null
    $authorizationCode = $null
    $codeVerifier = $null
    if ($listener) {
        try { $listener.Stop() } catch {}
        $listener.Close()
    }
}
