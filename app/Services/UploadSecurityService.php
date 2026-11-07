<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class UploadSecurityService
{
    private array $allowed = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function inspect(string $path, ?string $expectedMime = null, ?int $maxBytes = null): array
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

        if (! in_array($mime, $this->allowed, true)) {
            throw new RuntimeException('upload_mime_rejected');
        }

        if ($expectedMime && $expectedMime !== $mime) {
            throw new RuntimeException('upload_mime_mismatch');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['php', 'phar', 'phtml', 'exe', 'js', 'html', 'htm', 'svg'], true)) {
            throw new RuntimeException('upload_extension_rejected');
        }

        $checksum = hash('sha256', $disk->get($path));
        $scanner = trim((string) config('farast.security.clamscan_binary', ''));

        if ($scanner === '') {
            return [
                'safe' => false,
                'mime' => $mime,
                'size' => $size,
                'checksum' => $checksum,
                'scan' => 'not_configured',
                'sandbox_required' => true,
                'storage' => 'private',
            ];
        }

        // Pass arguments directly to the process; never build a shell command from
        // environment configuration or a user-controlled storage path.
        $process = new Process([$scanner, '--no-summary', $disk->path($path)]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('upload_malware_scan_failed');
        }

        return [
            'safe' => true,
            'mime' => $mime,
            'size' => $size,
            'checksum' => $checksum,
            'scan' => 'clean',
            'sandbox_required' => true,
            'storage' => 'private',
        ];
    }
}
