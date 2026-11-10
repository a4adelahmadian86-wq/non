$ErrorActionPreference = 'Stop'

# Run from the FARAST Laravel project root (the folder containing artisan and .env).
$projectRoot = $PSScriptRoot
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan')) -or
    -not (Test-Path -LiteralPath $envPath)) {
    throw 'این اسکریپت باید داخل پوشه اصلی پروژه Laravel باشد؛ فایل artisan و .env پیدا نشد. هیچ فایلی تغییر نکرد.'
}

$baseUrl = 'https://voice-typing-r5r7.onrender.com'
Write-Host 'Checking the existing Render Voice-typing service...'

foreach ($endpoint in @('/health', '/ready')) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ($baseUrl + $endpoint) -Method Get -TimeoutSec 60
        if ([int]$response.StatusCode -ne 200) {
            throw "Render $endpoint returned HTTP $([int]$response.StatusCode)."
        }
        Write-Host "$endpoint : HTTP $([int]$response.StatusCode)"
    } catch {
        throw "Render $endpoint check failed. Local .env was not changed. $($_.Exception.Message)"
    }
}

function Normalize-VoiceToken([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    $normalized = $Value.Trim()
    $normalized = [regex]::Replace($normalized, '^(?i:Bearer)\s+', '').Trim()
    if ($normalized -match '\s') {
        throw 'The token contains unexpected whitespace. No local files were changed.'
    }
    return $normalized
}

function Test-RenderVoiceToken([string]$Value) {
    $headers = @{ Authorization = "Bearer $Value"; Accept = 'application/json' }
    $result = Invoke-RestMethod -Uri ($baseUrl + '/v1/status') -Method Get -Headers $headers -TimeoutSec 30
    if (-not $result.asr_ready -or -not $result.tts_ready -or -not $result.chat_ready) {
        throw 'Render accepted the token, but ASR, TTS, or chat is not ready.'
    }
    return $result
}

# Reuse the current local token if Render still accepts it. If Render returns 401,
# ask for the current token securely; never display it or write it to console output.
$existingToken = ''
foreach ($line in Get-Content -LiteralPath $envPath) {
    if ($line -match '^\s*FARAST_VOICE_TOKEN\s*=(.*)$') {
        $existingToken = Normalize-VoiceToken($matches[1].Trim().Trim('"').Trim("'"))
    }
}

$token = $null
$authenticated = $false
if (-not [string]::IsNullOrWhiteSpace($existingToken)) {
    try {
        $null = Test-RenderVoiceToken $existingToken
        $token = $existingToken
        $authenticated = $true
        Write-Host 'The existing local token is accepted by Render.'
    } catch {
        $statusCode = $null
        try {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
        } catch {}
        if ($statusCode -ne 401) {
            throw "Render token verification did not complete (HTTP $statusCode or a readiness error). Local .env was not changed. $($_.Exception.Message)"
        }
        Write-Host 'Render rejected the existing local token. Enter the current token for this exact Voice-typing service.'
    }
}

if (-not $authenticated) {
    $secureToken = Read-Host 'Enter the current Render FARAST_VOICE_TOKEN (hidden input; Bearer prefix is optional)' -AsSecureString
    $tokenPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    try {
        $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPtr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPtr)
        $secureToken.Dispose()
    }

    $token = Normalize-VoiceToken $token
    if ([string]::IsNullOrWhiteSpace($token)) {
        throw 'No token was entered. Local .env was not changed.'
    }

    try {
        $null = Test-RenderVoiceToken $token
        Write-Host 'The supplied token is accepted by Render; voice, TTS, and chat are ready.'
    } catch {
        $token = $null
        throw "Render rejected the supplied token or its voice services are not ready. Local .env was not changed. Check FARAST_VOICE_TOKEN in the Environment settings of the existing Voice-typing service. Do not use GROQ_API_KEY. $($_.Exception.Message)"
    }
}

# Back up .env only after the remote token and all required voice capabilities are verified.
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupPath = "$envPath.backup-$timestamp"
Copy-Item -LiteralPath $envPath -Destination $backupPath -ErrorAction Stop

$lines = [System.Collections.Generic.List[string]]::new()
foreach ($line in Get-Content -LiteralPath $envPath) { $lines.Add($line) }

# Replace every duplicate key, not just the first occurrence. This prevents an
# older duplicate later in .env from overriding the newly verified credential.
function Set-EnvValue([string]$Key, [string]$Value) {
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    $matches = [System.Collections.Generic.List[int]]::new()
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match $pattern) { $matches.Add($index) }
    }

    if ($matches.Count -eq 0) {
        $lines.Add("$Key=$Value")
        return
    }

    $first = $matches[0]
    $lines[$first] = "$Key=$Value"
    for ($position = $matches.Count - 1; $position -ge 1; $position--) {
        $lines.RemoveAt($matches[$position])
    }
}

# Laravel stays on this computer; only assistant AI and voice traffic use Render.
Set-EnvValue 'FARAST_VOICE_LOCAL_SPEECH_ENABLED' 'false'
Set-EnvValue 'FARAST_VOICE_REMOTE_BATCH_ENABLED' 'true'
Set-EnvValue 'FARAST_AI_GATEWAY_URL' $baseUrl
Set-EnvValue 'FARAST_AI_GATEWAY_MODEL' 'farast-general'
Set-EnvValue 'FARAST_VOICE_ASR_URL' "$baseUrl/v1/transcribe"
Set-EnvValue 'FARAST_VOICE_TTS_URL' "$baseUrl/v1/synthesize"
Set-EnvValue 'FARAST_VOICE_TOKEN' $token
Set-EnvValue 'FARAST_VOICE_ASR_TOKEN' $token
Set-EnvValue 'FARAST_VOICE_TTS_TOKEN' $token
Set-EnvValue 'FARAST_VOICE_TTS_VOICE_ID' 'farast-fa-male-v1'
Set-EnvValue 'VOICE_STREAM_URL' 'wss://voice-typing-r5r7.onrender.com'

[System.IO.File]::WriteAllLines($envPath, $lines, [System.Text.UTF8Encoding]::new($false))
$token = $null
$existingToken = $null

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Warning 'PHP CLI was not found. From the FARAST project root, run: php artisan config:clear'
} else {
    Push-Location $projectRoot
    try {
        & $php.Source artisan config:clear
        if ($LASTEXITCODE -ne 0) {
            throw "Laravel config:clear failed. The .env backup is at: $backupPath"
        }
    } finally {
        Pop-Location
    }
}

Write-Host ''
Write-Host 'Render voice configuration was updated and verified.'
Write-Host 'The live WebSocket token, ASR, TTS, and AI gateway now use the same verified credential.'
Write-Host 'No token value was printed.'
Write-Host "Backup: $backupPath"
