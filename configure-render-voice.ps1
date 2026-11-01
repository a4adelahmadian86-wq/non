$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan')) -or -not (Test-Path -LiteralPath $envPath)) {
    throw 'این اسکریپت باید از پوشه اصلی پروژه Laravel اجرا شود؛ فایل artisan و .env پیدا نشد.'
}

$values = @{}
$lines = Get-Content -LiteralPath $envPath
foreach ($line in $lines) {
    if ($line -match '^\s*([A-Za-z0-9_]+)\s*=(.*)$') {
        $values[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
    }
}

$voiceToken = [string]$values['FARAST_VOICE_TOKEN']
if ([string]::IsNullOrWhiteSpace($voiceToken)) {
    throw 'FARAST_VOICE_TOKEN در .env وجود ندارد. ابتدا پیکربندی Render را با اسکریپت احراز‌شده قبلی کامل کن؛ توکن را در چت ارسال نکن.'
}

$baseUrl = 'https://voice-typing-r5r7.onrender.com'
Write-Host 'Checking Render health and authenticated gateway access...'
foreach ($endpoint in @('/health', '/ready')) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri ($baseUrl + $endpoint) -TimeoutSec 60
    if ($response.StatusCode -ne 200) { throw "Render $endpoint returned HTTP $($response.StatusCode)." }
    Write-Host "$endpoint : HTTP $($response.StatusCode)"
}
$status = Invoke-RestMethod -Method Get -Uri ($baseUrl + '/v1/status') -Headers @{ Authorization = "Bearer $voiceToken"; Accept = 'application/json' } -TimeoutSec 30
if (-not $status.asr_ready -or -not $status.tts_ready) {
    throw 'Render ASR/TTS is not ready. No local .env changes were made.'
}
Write-Host 'Authenticated Render voice status: OK'

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = "$envPath.backup-$timestamp"
Copy-Item -LiteralPath $envPath -Destination $backup -ErrorAction Stop

$updated = [System.Collections.Generic.List[string]]::new()
foreach ($line in $lines) { $updated.Add($line) }

function Set-EnvValue([string]$Key, [string]$Value) {
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    $found = $false
    for ($index = 0; $index -lt $updated.Count; $index++) {
        if ($updated[$index] -match $pattern) {
            $updated[$index] = "$Key=$Value"
            $found = $true
            break
        }
    }
    if (-not $found) { $updated.Add("$Key=$Value") }
}

# Laravel remains local. All AI, live ASR, chat and TTS requests go to Render.
Set-EnvValue 'FARAST_VOICE_LOCAL_SPEECH_ENABLED' 'false'
Set-EnvValue 'FARAST_VOICE_REMOTE_BATCH_ENABLED' 'true'
Set-EnvValue 'FARAST_VOICE_ASR_URL' "$baseUrl/v1/transcribe"
Set-EnvValue 'FARAST_VOICE_ASR_TOKEN' $voiceToken
Set-EnvValue 'FARAST_VOICE_TTS_URL' "$baseUrl/v1/synthesize"
Set-EnvValue 'FARAST_VOICE_TTS_TOKEN' $voiceToken
Set-EnvValue 'FARAST_VOICE_TTS_VOICE_ID' 'farast-fa-male-v1'
Set-EnvValue 'FARAST_AI_GATEWAY_URL' $baseUrl
Set-EnvValue 'FARAST_AI_GATEWAY_TOKEN' $voiceToken
Set-EnvValue 'FARAST_AI_GATEWAY_MODEL' 'farast-general'
Set-EnvValue 'VOICE_STREAM_URL' 'wss://voice-typing-r5r7.onrender.com'

[System.IO.File]::WriteAllLines($envPath, $updated, [System.Text.UTF8Encoding]::new($false))

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Push-Location $projectRoot
    try {
        & $php.Source artisan config:clear
        if ($LASTEXITCODE -ne 0) { throw 'Laravel config:clear failed.' }
    } finally {
        Pop-Location
    }
} else {
    Write-Warning 'PHP CLI was not found in PATH. Run php artisan config:clear from the Laravel project folder.'
}

Write-Host ''
Write-Host 'Render conversational AI configuration has been applied.'
Write-Host 'Laravel remains on this computer; the browser and Laravel send AI/voice processing to Render.'
Write-Host 'Live microphone audio is streamed to Render; server-side VAD decides when each utterance ends.'
Write-Host 'No Docker, Qwen, Sherpa, Piper, or model download is required on the user device.'
Write-Host "Backup: $backup"
