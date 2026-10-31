$ErrorActionPreference = 'Stop'

$checks = @(
    @{ Name = 'Sherpa Persian ASR and live gateway'; Url = 'http://127.0.0.1:6002/health'; Field = 'asr_ready'; ExtraField = 'laravel_ready' },
    @{ Name = 'Piper Persian TTS'; Url = 'http://127.0.0.1:7007/health'; Field = 'ok' },
    @{ Name = 'Qwen3 local AI'; Url = 'http://127.0.0.1:7008/ready'; Field = 'ok' }
)

$deadline = (Get-Date).AddMinutes(20)
$lastNotice = Get-Date
while ((Get-Date) -lt $deadline) {
    $allReady = $true
    foreach ($check in $checks) {
        $ready = $false
        try {
            $result = Invoke-RestMethod -Uri $check.Url -TimeoutSec 4
            $property = $result.PSObject.Properties[$check.Field]
            $ready = ($null -ne $property) -and [bool]$property.Value
            if ($ready -and $check.ExtraField) {
                $extraProperty = $result.PSObject.Properties[$check.ExtraField]
                $ready = ($null -ne $extraProperty) -and [bool]$extraProperty.Value
            }
        } catch {
            $ready = $false
        }
        if (-not $ready) { $allReady = $false }
    }

    if ($allReady) {
        Write-Host 'تمام موتورهای گفتار و هوش مصنوعی محلی آماده هستند.'
        exit 0
    }

    if (((Get-Date) - $lastNotice).TotalSeconds -ge 30) {
        Write-Host 'در انتظار دانلود اولیه مدل‌ها و آماده‌شدن سرویس‌ها...'
        $lastNotice = Get-Date
    }
    Start-Sleep -Seconds 5
}

Write-Error 'موتورهای گفتار یا هوش مصنوعی طی ۲۰ دقیقه آماده نشدند. لاگ Docker را بررسی کن.'
exit 1
