<?php

namespace Tests\Unit;

use App\Services\UploadSecurityService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSecurityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_pdf_is_checked_locally_and_page_count_is_reported(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n2 0 obj << /Type /Page >> endobj\n%%EOF";
        Storage::disk('private')->put('quarantine/sample.pdf', $pdf);

        $result = app(UploadSecurityService::class)->inspect('quarantine/sample.pdf', 'application/pdf');

        $this->assertTrue($result['safe']);
        $this->assertSame('pdf', $result['detected_type']);
        $this->assertSame(2, $result['page_count']);
        $this->assertSame('farast_static_v1', $result['scan']);
        $this->assertSame(hash('sha256', $pdf), $result['checksum']);
        $this->assertStringContainsString('تضمین', $result['security_notice']);
    }

    public function test_disallowed_executable_extension_is_rejected_before_storage_approval(): void
    {
        Storage::disk('private')->put('quarantine/payload.exe', "MZ\x00\x00");

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/payload.exe');
    }

    public function test_pdf_extension_with_non_pdf_signature_is_rejected(): void
    {
        Storage::disk('private')->put('quarantine/fake.pdf', 'plain text pretending to be a PDF');

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/fake.pdf');
    }

    public function test_empty_files_are_rejected(): void
    {
        Storage::disk('private')->put('quarantine/empty.txt', '');

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/empty.txt');
    }
}
