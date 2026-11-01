$ErrorActionPreference = 'Stop'

$voiceDir = $PSScriptRoot
$projectRoot = Split-Path -Parent $voiceDir
$laravelEnv = Join-Path $projectRoot '.env'
if (-not (Test-Path $laravelEnv)) {
    throw 'فایل .env لاراول در پوشه اصلی پروژه پیدا نشد.'
}

$sourceLines = Get-Content -LiteralPath $laravelEnv
$values = @{}
foreach ($line in $sourceLines) {
    if ($line -match '^\s*([A-Za-z0-9_]+)\s*=(.*)$') {
        $key = $matches[1]
        $value = $matches[2].Trim().Trim('"').Trim("'")
        $values[$key] = $value
    }
}

foreach ($required in @('APP_KEY', 'APP_URL')) {
    if ([string]::IsNullOrWhiteSpace([string]$values[$required])) {
        throw "متغیر $required در فایل .env لاراول تنظیم نشده است؛ مقدار آن را بررسی کن."
    }
}

$appUri = [System.Uri]::new([string]$values['APP_URL'])
$origin = $appUri.GetLeftPart([System.UriPartial]::Authority)
$callbackBuilder = [System.UriBuilder]::new($appUri)
$origins = [System.Collections.Generic.List[string]]::new()
$origins.Add($origin)

if ($appUri.Host -in @('localhost', '127.0.0.1', '::1')) {
    $callbackBuilder.Host = 'host.docker.internal'
    $alternateBuilder = [System.UriBuilder]::new($appUri)
    $alternateBuilder.Host = if ($appUri.Host -eq 'localhost') { '127.0.0.1' } else { 'localhost' }
    $alternateOrigin = $alternateBuilder.Uri.GetLeftPart([System.UriPartial]::Authority)
    if (-not $origins.Contains($alternateOrigin)) { $origins.Add($alternateOrigin) }
}
$callbackUrl = $callbackBuilder.Uri.GetLeftPart([System.UriPartial]::Authority)

# Keep only the secrets each private container needs; never copy the whole Laravel .env.
function New-RandomToken {
    $randomBytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($randomBytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($randomBytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

$gatewaySecret = [string]$values['VOICE_GATEWAY_SECRET']
if ([string]::IsNullOrWhiteSpace($gatewaySecret)) { $gatewaySecret = New-RandomToken }
$aiToken = [string]$values['FARAST_AI_GATEWAY_TOKEN']
if ([string]::IsNullOrWhiteSpace($aiToken)) { $aiToken = New-RandomToken }

$ttsToken = [string]$values['FARAST_VOICE_TTS_TOKEN']
if ([string]::IsNullOrWhiteSpace($ttsToken)) { $ttsToken = [string]$values['FARAST_VOICE_TOKEN'] }
$runtimeLines = @(
    "APP_KEY=$($values['APP_KEY'])",
    "VOICE_GATEWAY_SECRET=$gatewaySecret",
    "FARAST_VOICE_TTS_TOKEN=$ttsToken",
    "FARAST_AI_GATEWAY_TOKEN=$aiToken"
)
[System.IO.File]::WriteAllLines((Join-Path $voiceDir '.env.runtime'), $runtimeLines[0..2], [System.Text.UTF8Encoding]::new($false))
[System.IO.File]::WriteAllLines((Join-Path $voiceDir '.env.ai-runtime'), @("FARAST_AI_GATEWAY_TOKEN=$aiToken"), [System.Text.UTF8Encoding]::new($false))

$composeLines = @(
    "VOICE_STREAM_APP_URL=$callbackUrl",
    "VOICE_STREAM_ORIGINS=$($origins -join ',')",
    'VOICE_STREAM_BIND_ADDRESS=127.0.0.1',
    'VOICE_TTS_BIND_ADDRESS=127.0.0.1',
    'AI_GATEWAY_BIND_ADDRESS=127.0.0.1',
    'OLLAMA_MODEL=qwen3:1.7b',
    'VOICE_STREAM_PORT=6002',
    'VOICE_TTS_PORT=7007',
    'SHERPA_NUM_THREADS=2',
    'SHERPA_MAX_CONCURRENT_SESSIONS=2',
    'PIPER_NUM_THREADS=2',
    'VOICE_STREAM_RESTART_MS=270000'
)
[System.IO.File]::WriteAllLines((Join-Path $voiceDir '.env'), $composeLines, [System.Text.UTF8Encoding]::new($false))

$laravelLines = [System.Collections.Generic.List[string]]::new()
foreach ($line in $sourceLines) { $laravelLines.Add($line) }
function Set-LaravelValue([string]$Key, [string]$Value) {
    $pattern = '^' + [regex]::Escape($Key) + '\s*='
    $found = $false
    for ($i = 0; $i -lt $laravelLines.Count; $i++) {
        if ($laravelLines[$i] -match $pattern) {
            $laravelLines[$i] = "$Key=$Value"
            $found = $true
            break
        }
    }
    if (-not $found) { $laravelLines.Add("$Key=$Value") }
}
Set-LaravelValue 'VOICE_GATEWAY_SECRET' $gatewaySecret
if (-not [string]::IsNullOrWhiteSpace($ttsToken)) { Set-LaravelValue 'FARAST_VOICE_TTS_TOKEN' $ttsToken }
Set-LaravelValue 'FARAST_VOICE_LOCAL_SPEECH_ENABLED' 'true'
Set-LaravelValue 'VOICE_STREAM_HEALTH_URL' 'http://127.0.0.1:6002/health'
Set-LaravelValue 'FARAST_AI_GATEWAY_URL' 'http://127.0.0.1:7008'
Set-LaravelValue 'FARAST_AI_GATEWAY_HEALTH_URL' 'http://127.0.0.1:7008/ready'
Set-LaravelValue 'FARAST_AI_GATEWAY_TOKEN' $aiToken
Set-LaravelValue 'FARAST_AI_GATEWAY_MODEL' 'qwen3:1.7b'
Set-LaravelValue 'VOICE_STREAM_URL' 'ws://127.0.0.1:6002'
$remoteBatchEnabled = ([string]$values['FARAST_VOICE_REMOTE_BATCH_ENABLED']) -match '^(?i:true|1|yes|on)$'
if ($remoteBatchEnabled) {
    # Keep the already verified Render ASR/TTS URLs; local Sherpa remains the live stream.
    if ([string]::IsNullOrWhiteSpace([string]$values['FARAST_VOICE_ASR_URL'])) {
        Set-LaravelValue 'FARAST_VOICE_ASR_URL' 'https://voice-typing-r5r7.onrender.com/v1/transcribe'
    }
    if ([string]::IsNullOrWhiteSpace([string]$values['FARAST_VOICE_TTS_URL'])) {
        Set-LaravelValue 'FARAST_VOICE_TTS_URL' 'https://voice-typing-r5r7.onrender.com/v1/synthesize'
    }
} else {
    Set-LaravelValue 'FARAST_VOICE_TTS_URL' 'http://127.0.0.1:7007/v1/synthesize'
}
[System.IO.File]::WriteAllLines($laravelEnv, $laravelLines, [System.Text.UTF8Encoding]::new($false))

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpExe = if ($phpCommand) { $phpCommand.Source } else { $null }
if ([string]::IsNullOrWhiteSpace($phpExe)) {
    $phpCandidates = @('C:\xampp\php\php.exe', 'C:\wamp64\bin\php\php.exe', (Join-Path $projectRoot 'php\php.exe'))
    foreach ($candidate in $phpCandidates) {
        if (Test-Path -LiteralPath $candidate) { $phpExe = $candidate; break }
    }
}
if ([string]::IsNullOrWhiteSpace($phpExe)) {
    foreach ($phpRoot in @('C:\laragon\bin\php', 'C:\wamp64\bin\php')) {
        if (Test-Path -LiteralPath $phpRoot) {
            $found = Get-ChildItem -LiteralPath $phpRoot -Filter php.exe -File -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($found) { $phpExe = $found.FullName; break }
        }
    }
}
if ([string]::IsNullOrWhiteSpace($phpExe)) {
    throw 'PHP CLI پیدا نشد. PHP پروژه باید نصب باشد و php.exe در PATH، XAMPP، WAMP یا Laragon در دسترس باشد تا حساب موتور گفتار ثبت شود.'
}

Push-Location $projectRoot
try {
    & $phpExe artisan db:seed --class='Database\Seeders\SherpaOnnxVoiceProviderSeeder' --force | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'ثبت موتور محلی Sherpa در پایگاه داده انجام نشد.' }
    & $phpExe artisan config:clear | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'پاک‌سازی تنظیمات Laravel انجام نشد.' }
} finally {
    Pop-Location
}

Write-Host 'تنظیمات محلی گفتار و مدل زبانی آماده شد. کلیدها و توکن‌ها نمایش داده یا تغییر داده نشدند.'
