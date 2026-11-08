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

    public function test_pdf_is_checked_locally_and_page_count_is_parsed_from_the_document_tree(): void
    {
        $pdf = new \FPDF();
        $pdf->SetFont('Arial', '', 12);
        $pdf->AddPage();
        $pdf->Cell(0, 10, 'First page');
        $pdf->AddPage();
        $pdf->Cell(0, 10, 'Second page');
        $bytes = $pdf->Output('S');
        Storage::disk('private')->put('quarantine/sample.pdf', $bytes);

        $result = app(UploadSecurityService::class)->inspect('quarantine/sample.pdf', 'application/pdf');

        $this->assertTrue($result['safe']);
        $this->assertSame('pdf', $result['detected_type']);
        $this->assertSame(2, $result['page_count']);
        $this->assertSame('farast_static_v1', $result['scan']);
        $this->assertSame(hash('sha256', $bytes), $result['checksum']);
        $this->assertStringContainsString('تضمین', $result['security_notice']);
    }

    public function test_disallowed_executable_extension_is_rejected_before_storage_approval(): void
    {
        Storage::disk('private')->put('quarantine/payload.exe', "MZ\x00\x00");

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/payload.exe');
    }

    public function test_pdf_with_obvious_active_content_actions_is_rejected(): void
    {
        Storage::disk('private')->put('quarantine/active.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript >> >> endobj");

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/active.pdf', 'application/pdf');
    }

    public function test_pdf_extension_with_non_pdf_signature_is_rejected(): void
    {
        Storage::disk('private')->put('quarantine/fake.pdf', 'plain text pretending to be a PDF');

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/fake.pdf');
    }

    public function test_tar_manifest_is_listed_without_extracting_archive_members(): void
    {
        if (! class_exists(\PharData::class)) {
            $this->markTestSkipped('PHP PharData is unavailable.');
        }

        $base = tempnam(sys_get_temp_dir(), 'farast-tar-');
        @unlink($base);
        $tarPath = $base.'.tar';
        $archive = new \PharData($tarPath);
        $archive->addFromString('chapters/intro.txt', 'مقدمه کتاب');
        unset($archive);

        $bytes = file_get_contents($tarPath);
        @unlink($tarPath);
        Storage::disk('private')->put('quarantine/book.tar', $bytes);

        $result = app(UploadSecurityService::class)->inspect('quarantine/book.tar', 'application/x-tar');

        $this->assertTrue($result['safe']);
        $this->assertSame('archive', $result['detected_type']);
        $this->assertSame(1, $result['archive_entry_count']);
        $this->assertSame('chapters/intro.txt', $result['archive_entries'][0]['name']);
        $this->assertSame('checked', $result['archive_entries'][0]['content_scan']);
    }

    public function test_zip_manifest_is_listed_without_extracting_archive_members(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is unavailable.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'farast-zip-');
        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($temporary, \ZipArchive::CREATE));
        $archive->addFromString('chapters/intro.txt', 'مقدمه کتاب');
        $archive->close();

        Storage::disk('private')->put('quarantine/book.zip', file_get_contents($temporary));
        @unlink($temporary);

        $result = app(UploadSecurityService::class)->inspect('quarantine/book.zip', 'application/zip');

        $this->assertTrue($result['safe']);
        $this->assertSame('archive', $result['detected_type']);
        $this->assertSame(1, $result['archive_entry_count']);
        $this->assertSame('chapters/intro.txt', $result['archive_entries'][0]['name']);
        $this->assertSame('checked', $result['archive_entries'][0]['content_scan']);
    }

    public function test_zip_member_with_known_malware_test_signature_is_rejected_without_extraction(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is unavailable.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'farast-zip-eicar-');
        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($temporary, \ZipArchive::CREATE));
        $archive->addFromString('notes.txt', 'X5O!P%@AP[4\\\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        $archive->close();

        Storage::disk('private')->put('quarantine/eicar.zip', file_get_contents($temporary));
        @unlink($temporary);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upload_archive_known_test_signature_rejected');
        app(UploadSecurityService::class)->inspect('quarantine/eicar.zip', 'application/zip');
    }

    public function test_zip_path_traversal_is_rejected_without_extraction(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is unavailable.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'farast-zip-');
        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($temporary, \ZipArchive::CREATE));
        $archive->addFromString('../outside.txt', 'unsafe');
        $archive->close();

        Storage::disk('private')->put('quarantine/traversal.zip', file_get_contents($temporary));
        @unlink($temporary);

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/traversal.zip', 'application/zip');
    }

    public function test_empty_files_are_rejected(): void
    {
        Storage::disk('private')->put('quarantine/empty.txt', '');

        $this->expectException(\RuntimeException::class);
        app(UploadSecurityService::class)->inspect('quarantine/empty.txt');
    }
}
