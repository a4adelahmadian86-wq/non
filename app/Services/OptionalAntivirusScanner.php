<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class OptionalAntivirusScanner
{
    public function configured(): bool
    {
        $binary = trim((string) config('services.clamav.binary', ''));
        return $binary !== '' && $this->binaryAvailable($binary);
    }

    public function scan(string $path): array
    {
        $binary = trim((string) config('services.clamav.binary', ''));
        if ($binary === '' || ! $this->binaryAvailable($binary)) {
            throw new RuntimeException('antivirus_scanner_unavailable');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('antivirus_scan_failed');
        }

        $process = new Process([$binary, '--no-summary', '--infected', '--', $path]);
        $process->setTimeout(max(10, min(300, (int) config('services.clamav.timeout', 120))));
        $process->run();

        if ($process->isSuccessful()) {
            return [
                'status' => 'clean',
                'engine' => 'clamav',
                'version' => $this->version($binary),
                'scanned_at' => now()->toISOString(),
            ];
        }
        if ($process->getExitCode() === 1) {
            throw new RuntimeException('antivirus_malware_detected');
        }
        throw new RuntimeException('antivirus_scan_failed');
    }

    private function binaryAvailable(string $binary): bool
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }
        $process = new Process(['sh', '-lc', 'command -v "$1" >/dev/null 2>&1', 'sh', $binary]);
        $process->setTimeout(3);
        $process->run();
        return $process->isSuccessful();
    }

    private function version(string $binary): ?string
    {
        $process = new Process([$binary, '--version']);
        $process->setTimeout(5);
        $process->run();
        return $process->isSuccessful()
            ? mb_substr(trim(strtok($process->getOutput(), "\n") ?: ''), 0, 120)
            : null;
    }
}
