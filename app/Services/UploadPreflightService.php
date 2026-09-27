<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class UploadPreflightService
{
    public function estimatePages(string $path, ?string $mime = null): int
    {
        abort_unless(Storage::disk('private')->exists($path), 404);
        $mime = strtolower((string) $mime);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return $this->pdfPages(Storage::disk('private')->get($path));
        }

        if ($mime === 'application/zip' || $mime === 'application/x-zip-compressed' || $extension === 'zip') {
            return $this->zipImagePages($path);
        }

        return 1;
    }

    public function depositPercent(int $pages): int
    {
        if ($pages <= 7) return 0;
        if ($pages <= 12) return 5;
        if ($pages <= 18) return 10;
        if ($pages <= 28) return 20;
        if ($pages <= 38) return 30;
        if ($pages <= 78) return 45;
        return 55;
    }

    public function fileHash(string $path): string
    {
        abort_unless(Storage::disk('private')->exists($path), 404);
        return hash('sha256', Storage::disk('private')->get($path));
    }

    private function pdfPages(string $bytes): int
    {
        $count = preg_match_all('/\/Type\s*\/Page\b/', $bytes, $m) ?: 0;
        if ($count > 0) return min(10000, $count);

        if (preg_match('/\/Count\s+(\d{1,6})\b/', $bytes, $m) === 1) {
            return max(1, min(10000, (int) $m[1]));
        }

        return 1;
    }

    private function zipImagePages(string $path): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('برای شمارش سریع ZIP افزونه ZipArchive باید فعال باشد.');
        }

        $absolute = Storage::disk('private')->path($path);
        $zip = new ZipArchive();
        if ($zip->open($absolute) !== true) {
            throw new RuntimeException('فایل ZIP قابل خواندن نیست.');
        }

        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.(?:jpe?g|png|webp)$/i', $name)) $count++;
        }
        $zip->close();

        return max(1, min(10000, $count));
    }
}
