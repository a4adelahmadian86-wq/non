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

foreach ($required in @('APP_KEY', 'VOICE_GATEWAY_SECRET', 'APP_URL')) {
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

# Keep only the secrets the voice containers actually need; never copy the whole Laravel .env.
$ttsToken = [string]$values['FARAST_VOICE_TTS_TOKEN']
if ([string]::IsNullOrWhiteSpace($ttsToken)) { $ttsToken = [string]$values['FARAST_VOICE_TOKEN'] }
$runtimeLines = @(
    "APP_KEY=$($values['APP_KEY'])",
    "VOICE_GATEWAY_SECRET=$($values['VOICE_GATEWAY_SECRET'])",
    "FARAST_VOICE_TTS_TOKEN=$ttsToken"
)
[System.IO.File]::WriteAllLines((Join-Path $voiceDir '.env.runtime'), $runtimeLines, [System.Text.UTF8Encoding]::new($false))

$composeLines = @(
    "VOICE_STREAM_APP_URL=$callbackUrl",
    "VOICE_STREAM_ORIGINS=$($origins -join ',')",
    'VOICE_STREAM_BIND_ADDRESS=127.0.0.1',
    'VOICE_TTS_BIND_ADDRESS=127.0.0.1',
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
Set-LaravelValue 'FARAST_VOICE_LOCAL_SPEECH_ENABLED' 'true'
Set-LaravelValue 'VOICE_STREAM_URL' 'ws://127.0.0.1:6002'
Set-LaravelValue 'FARAST_VOICE_TTS_URL' 'http://127.0.0.1:7007/v1/synthesize'
[System.IO.File]::WriteAllLines($laravelEnv, $laravelLines, [System.Text.UTF8Encoding]::new($false))

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Push-Location $projectRoot
    try {
        & $php.Source artisan db:seed --class='Database\Seeders\SherpaOnnxVoiceProviderSeeder' --force | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'ثبت موتور محلی Sherpa در پایگاه داده انجام نشد.' }
        & $php.Source artisan config:clear | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'پاک‌سازی تنظیمات Laravel انجام نشد.' }
    } finally {
        Pop-Location
    }
} else {
    Write-Warning 'PHP در PATH پیدا نشد؛ برای فعال‌شدن موتور محلی باید seeder موتور Sherpa اجرا شود.'
}

Write-Host 'تنظیمات محلی گفتار آماده شد. کلیدها و توکن‌ها نمایش داده یا تغییر داده نشدند.'
