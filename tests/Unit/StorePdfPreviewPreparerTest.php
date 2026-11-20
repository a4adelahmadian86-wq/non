<?php

namespace Tests\Unit;

use App\Services\StorePdfPreviewPreparer;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class StorePdfPreviewPreparerTest extends TestCase
{
    public function test_generated_sample_contains_exactly_the_approved_number_of_pages(): void
    {
        $source = new Fpdi();
        $source->AddPage();
        $source->SetFont('Helvetica', '', 12);
        $source->Cell(40, 10, 'Page one');
        $source->AddPage('L');
        $source->Cell(40, 10, 'Page two');

        $result = app(StorePdfPreviewPreparer::class)->prepareSample($source->Output('S'), 1);

        $this->assertSame(2, $result['source_page_count']);
        $this->assertSame(1, $result['included_page_count']);
        $this->assertStringStartsWith('%PDF-', $result['bytes']);

        $verificationPath = tempnam(sys_get_temp_dir(), 'farast-pdf-test-');
        $this->assertIsString($verificationPath);

        try {
            $this->assertSame(strlen($result['bytes']), file_put_contents($verificationPath, $result['bytes'], LOCK_EX));
            $verification = new Fpdi();
            $this->assertSame(1, $verification->setSourceFile($verificationPath));
        } finally {
            @unlink($verificationPath);
        }
    }

    public function test_malformed_source_pdf_is_rejected_without_returning_original_bytes(): void
    {
        $this->expectException(RuntimeException::class);

        app(StorePdfPreviewPreparer::class)->prepareSample('not-a-pdf', 1);
    }
}
