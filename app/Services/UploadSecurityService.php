<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        $scanResult = app(StaticFileSafetyScanner::class)->scan($absolutePath, $mime, $extension);
        $hash = (string) ($scanResult['sha256'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('upload_checksum_failed');
        }

        return [
            'safe' => (bool) ($scanResult['safe'] ?? false),
            'mime' => $mime,
            'size' => $size,
            'checksum' => $hash,
            'scan' => (string) ($scanResult['status'] ?? 'review_required'),
            'scan_engine' => (string) ($scanResult['engine'] ?? 'farast-static-policy-v1'),
            'scan_findings' => (array) ($scanResult['findings'] ?? []),
            'scan_disclaimer' => (string) ($scanResult['disclaimer'] ?? ''),
            'sandbox_required' => true,
            'storage' => 'private',
        ];
    }
}
