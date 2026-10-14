<?php

namespace Tests\Feature;

use App\Models\FarastUsageReservation;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\CommerceAuthorizationService;
use App\Services\EditorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class EditorExportSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeExportableDocument(): TypingDocument
    {
        $user = User::create([
            'name' => 'Export Test',
            'mobile' => '09'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'role' => 'admin',
            'is_verified' => true,
            'is_blocked' => false,
        ]);

        $document = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'آزمون خروجی فارسی',
            'content' => '<h1>عنوان فارسی</h1><p dir="rtl">سلام FARAST 2026</p><table><tr><td>سلول</td></tr></table>',
            'status' => 'draft',
        ]);

        app(EditorDocumentService::class)->save(
            $document,
            $document->content,
            $document->title,
            null,
            'manual',
            ['paper' => 'A4', 'orientation' => 'portrait', 'direction' => 'rtl', 'font_family' => 'B Nazanin', 'font_size' => 16]
        );

        return $document->fresh();
    }

    private function allowExportReservation(): void
    {
        $reservation = new FarastUsageReservation();
        $commerce = Mockery::mock(CommerceAuthorizationService::class);
        $commerce->shouldReceive('reserve')->once()->andReturn($reservation);
        $commerce->shouldReceive('commit')->once()->andReturnNull();
        $this->app->instance(CommerceAuthorizationService::class, $commerce);
    }

    public function test_pdf_export_returns_a_real_pdf_document(): void
    {
        $document = $this->makeExportableDocument();
        $this->allowExportReservation();

        $response = $this->actingAs($document->user)
            ->post('/editor/export/pdf', ['document_id' => $document->id]);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_docx_export_returns_a_valid_word_open_xml_package(): void
    {
        $document = $this->makeExportableDocument();
        $this->allowExportReservation();

        $response = $this->actingAs($document->user)
            ->post('/editor/export/docx', ['document_id' => $document->id]);

        $response->assertDownload('farast-'.$document->id.'.docx');
        $base = $response->baseResponse;
        $this->assertTrue(method_exists($base, 'getFile'));
        $path = $base->getFile()->getPathname();
        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($path));
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $this->assertNotFalse($zip->getFromName('word/document.xml'));
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('سلام FARAST 2026', $xml);
        $zip->close();
    }
}
