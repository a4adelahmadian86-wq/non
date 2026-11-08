<?php

namespace Tests\Unit;

use App\Services\LocalFileInspector;
use App\Services\StaticFileSafetyScanner;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class LocalFileInspectorTest extends TestCase
{
    public function test_zip_preflight_lists_archive_entries_without_extracting_them(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-intake-');
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manual/readme.txt', 'Sample content');
        $zip->addFromString('images/cover.png', 'not-a-real-image');
        $zip->close();

        try {
            $upload = new UploadedFile($path, 'bundle.zip', 'application/zip', null, true);
            $details = app(LocalFileInspector::class)->inspect($upload);

            self::assertSame('zip', $details['detected_type']);
            self::assertSame(2, $details['archive']['entry_count']);
            self::assertSame('manual/readme.txt', $details['archive']['entries'][0]['name']);
            self::assertSame('images/cover.png', $details['archive']['entries'][1]['name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_local_inspector_rejects_archive_path_traversal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-intake-');
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('../outside.txt', 'unsafe');
        $zip->close();

        try {
            $upload = new UploadedFile($path, 'unsafe.zip', 'application/zip', null, true);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('intake_archive_unsafe_path');
            app(LocalFileInspector::class)->inspect($upload);
        } finally {
            @unlink($path);
        }
    }

    public function test_static_scanner_rejects_executable_signatures_even_with_a_safe_extension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-scan-');
        file_put_contents($path, "MZ".str_repeat("\\0", 128));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('static_scan_windows_executable');
            app(StaticFileSafetyScanner::class)->scan($path, 'text/plain', 'txt');
        } finally {
            @unlink($path);
        }
    }
}
