<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class LocalClamAvScanner
{
    public function scan(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('antivirus_file_unavailable');
        }

        $configuredBinary = trim((string) config('farast.security.clamav_binary', 'clamscan'));
        $binary = (new ExecutableFinder())->find($configuredBinary);
        if (! $binary) {
            throw new RuntimeException('antivirus_engine_unavailable');
        }

        try {
            $process = new Process([$binary, '--no-summary', '--infected', $absolutePath]);
            $process->setTimeout(180);
            $process->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException('antivirus_engine_unavailable', 0, $exception);
        }

        if ($process->getExitCode() === 0) {
            return [
                'status' => 'clean',
                'engine' => 'clamav',
                'version' => $this->version($binary),
                'scanned_at' => now()->toISOString(),
            ];
        }

        if ($process->getExitCode() === 1) {
            return [
                'status' => 'infected',
                'engine' => 'clamav',
                'version' => $this->version($binary),
                'scanned_at' => now()->toISOString(),
                'finding' => mb_substr(trim($process->getOutput()), 0, 500),
            ];
        }

        throw new RuntimeException('antivirus_scan_failed');
    }

    private function version(string $binary): ?string
    {
        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();
            return $process->isSuccessful() ? mb_substr(trim($process->getOutput()), 0, 120) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
