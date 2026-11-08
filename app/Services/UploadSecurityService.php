<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class UploadSecurityService
{
    private array $allowed = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf', 'application/zip',
    ];

    public function inspect(string $path, ?string $expectedMime = null, ?int $maxBytes = null, ?array $allowedMimes = null): array
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            throw new RuntimeException('upload_not_found');
        }

        $size = (int) $disk->size($path);
        $limit = $maxBytes ?? (int) config('farast.security.max_upload_bytes', 2 * 1024 * 1024 * 1024);
        if ($size > $limit) {
            throw new RuntimeException('upload_too_large');
        }

        $mime = strtolower((string) $disk->mimeType($path));
        $allowed = $allowedMimes ?? $this->allowed;
        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException('upload_mime_rejected');
        }
        if ($expectedMime && strtolower($expectedMime) !== $mime) {
            throw new RuntimeException('upload_mime_mismatch');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['php', 'phar', 'phtml', 'exe', 'js', 'html', 'htm', 'svg'], true)) {
            throw new RuntimeException('upload_extension_rejected');
        }

        $absolutePath = $disk->path($path);
        $hash = hash_file('sha256', $absolutePath);
        if (! is_string($hash)) {
            throw new RuntimeException('upload_checksum_failed');
        }

        $scanner = trim((string) env('FARAST_CLAMSCAN_BINARY', ''));
        $scan = 'not_configured';
        if ($scanner !== '') {
            $timeout = max(0.1, min(600, (float) config('farast.security.clamscan_timeout', 120)));
            $process = new Process([$scanner, '--no-summary', $absolutePath]);
            $process->setTimeout($timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                throw new RuntimeException('upload_malware_scan_timeout', 0, $exception);
            } catch (\\Throwable $exception) {
                throw new RuntimeException('upload_malware_scanner_unavailable', 0, $exception);
            }

            if ($process->getExitCode() !== 0) {
                throw new RuntimeException('upload_malware_scan_failed');
            }
            $scan = 'clean';
        }

        return [
            'safe' => $scan === 'clean',
            'mime' => $mime,
            'size' => $size,
            'checksum' => $hash,
            'scan' => $scan,
            'sandbox_required' => true,
            'storage' => 'private',
        ];
    }
}
