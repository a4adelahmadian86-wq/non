<?php

namespace Tests\Feature;

use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSecurityServiceTest extends TestCase
{
    use RefreshDatabase;


    public function test_internal_archive_inspection_exposes_member_pages_and_author_metadata(): void
    {
        if (! class_exists(\ZipArchive::class) || ! class_exists(\FPDF::class)) {
            $this->markTestSkipped('ZIP and FPDF extensions are required for this archive metadata fixture.');
        }

        Storage::fake('private');
        $pdfPath = tempnam(sys_get_temp_dir(), 'farast-pdf-');
        $archivePath = tempnam(sys_get_temp_dir(), 'farast-zip-');
        try {
            $pdf = new \FPDF();
            $pdf->SetAuthor('FARAST Metadata Test');
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(40, 10, 'Metadata test');
            $pdf->Output('F', $pdfPath);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($archivePath, \ZipArchive::CREATE | \\ZipArchive::OVERWRITE) === true);
            $zip->addFile($pdfPath, 'documents/manual.pdf');
            $zip->close();

            Storage::disk('private')->put('security-tests/manuals.zip', file_get_contents($archivePath));
            $result = app(UploadSecurityService::class)->inspect('security-tests/manuals.zip', null, 1024 * 1024);

            $this->assertTrue($result['safe']);
            $entry = collect($result['archive_entries'])->firstWhere('name', 'documents/manual.pdf');
            $this->assertNotNull($entry);
            $this->assertSame(1, $entry['metadata']['page_count'] ?? null);
            $this->assertSame('FARAST Metadata Test', $entry['metadata']['author'] ?? null);
            $this->assertSame('checked', $entry['content_scan']);
        } finally {
            @unlink($pdfPath);
            @unlink($archivePath);
        }
    }

    public function test_internal_archive_inspection_rejects_path_traversal_without_extraction(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for this archive fixture.');
        }

        Storage::fake('private');
        $archivePath = tempnam(sys_get_temp_dir(), 'farast-zip-');
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($archivePath, \ZipArchive::CREATE | \\ZipArchive::OVERWRITE) === true);
            $zip->addFromString('../outside.txt', 'must never be extracted');
            $zip->close();
            Storage::disk('private')->put('security-tests/traversal.zip', file_get_contents($archivePath));

            try {
                app(UploadSecurityService::class)->inspect('security-tests/traversal.zip', null, 1024 * 1024);
                $this->fail('Archive path traversal must be rejected.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('upload_archive_path_or_link_rejected', $exception->getMessage());
            }
        } finally {
            @unlink($archivePath);
        }
    }

    public function test_internal_static_inspection_can_pass_without_a_third_party_scanner(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('unscanned.png', 2, 2);
        $path = $file->store('security-tests', 'private');

        $previousEnv = getenv('FARAST_CLAMSCAN_BINARY');
        $previousServer = $_SERVER['FARAST_CLAMSCAN_BINARY'] ?? null;
        $previousSuperglobal = $_ENV['FARAST_CLAMSCAN_BINARY'] ?? null;

        try {
            putenv('FARAST_CLAMSCAN_BINARY=');
            $_SERVER['FARAST_CLAMSCAN_BINARY'] = '';
            $_ENV['FARAST_CLAMSCAN_BINARY'] = '';

            $result = app(UploadSecurityService::class)->inspect($path, 'image/png', 1024);

            $this->assertTrue($result['safe']);
            $this->assertSame('farast_static_v1', $result['scan']);
            $this->assertSame('image', $result['detected_type']);
            $this->assertSame(2, $result['dimensions']['width']);
            $this->assertSame(2, $result['dimensions']['height']);
            $this->assertTrue($result['sandbox_required']);
            $this->assertSame('بررسی ایستای داخلی فراست', $result['scan_label']);
            $this->assertStringContainsString('تضمین', $result['security_notice']);
        } finally {
            if ($previousEnv === false) {
                putenv('FARAST_CLAMSCAN_BINARY');
            } else {
                putenv('FARAST_CLAMSCAN_BINARY='.$previousEnv);
            }
            if ($previousServer === null) unset($_SERVER['FARAST_CLAMSCAN_BINARY']);
            else $_SERVER['FARAST_CLAMSCAN_BINARY'] = $previousServer;
            if ($previousSuperglobal === null) unset($_ENV['FARAST_CLAMSCAN_BINARY']);
            else $_ENV['FARAST_CLAMSCAN_BINARY'] = $previousSuperglobal;
        }
    }
}
