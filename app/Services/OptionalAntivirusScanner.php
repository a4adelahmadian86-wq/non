<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class OptionalAntivirusScanner
{
    public function configured(): bool
    {
        $binary = trim((string) config('services.clamav.binary', ''));

        return $binary !== '' && $this->resolveBinary($binary) !== null;
    }

    public function scanStream($stream): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('antivirus_source_unavailable');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'farast-antivirus-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('antivirus_temporary_file_unavailable');
        }

        $target = fopen($temporaryPath, 'wb');
        if (! is_resource($target)) {
            @unlink($temporaryPath);
            throw new RuntimeException('antivirus_temporary_file_unavailable');
        }

        try {
            $bytes = stream_copy_to_stream($stream, $target);
            fflush($target);
            if (! is_int($bytes) || $bytes < 1) {
                throw new RuntimeException('antivirus_source_unavailable');
            }
            fclose($target);
            $target = null;
            @chmod($temporaryPath, 0600);

            return $this->scan($temporaryPath);
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($temporaryPath);
        }
    }

    public function scan(string $path): array
    {
        $configured = trim((string) config('services.clamav.binary', ''));
        $binary = $configured !== '' ? $this->resolveBinary($configured) : null;

        if ($binary === null) {
            throw new RuntimeException('antivirus_scanner_unavailable');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('antivirus_source_unavailable');
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

    private function resolveBinary(string $binary): ?string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        $process = new Process(['sh', '-lc', 'command -v "$1"', 'sh', $binary]);
        $process->setTimeout(3);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $resolved = trim($process->getOutput());

        return $resolved !== '' && is_executable($resolved) ? $resolved : null;
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
