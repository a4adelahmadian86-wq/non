<?php

namespace Tests\Feature;

use App\Services\StorePdfPreviewPreparer;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class StorePdfPreviewPreparerTest extends TestCase
{
    private function pdfWithPages(int $count): string
    {
        $pdf = new \FPDF();
        $pdf->SetFont('Arial', '', 14);
        for ($page = 1; $page <= $count; $page++) {
            $pdf->AddPage();
            $pdf->Cell(0, 12, 'Sample source page '.$page);
        }

        return $pdf->Output('S');
    }

    private function countPages(string $bytes): int
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-pdf-count-');
        file_put_contents($path, $bytes);
        try {
            return (new Fpdi())->setSourceFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_sample_pdf_contains_only_the_configured_leading_pages(): void
    {
        $source = $this->pdfWithPages(5);
        $result = app(StorePdfPreviewPreparer::class)->prepareSample($source, 2);

        $this->assertSame(5, $result['source_page_count']);
        $this->assertSame(2, $result['included_page_count']);
        $this->assertSame(2, $this->countPages($result['bytes']));
        $this->assertNotSame($source, $result['bytes']);
        $this->assertStringStartsWith('%PDF-', $result['bytes']);
    }

    public function test_sample_page_limit_is_clamped_to_supported_bounds(): void
    {
        $source = $this->pdfWithPages(3);

        $onePage = app(StorePdfPreviewPreparer::class)->prepareSample($source, 0);
        $allPages = app(StorePdfPreviewPreparer::class)->prepareSample($source, 500);

        $this->assertSame(1, $onePage['included_page_count']);
        $this->assertSame(3, $allPages['included_page_count']);
        $this->assertSame(3, $this->countPages($allPages['bytes']));
    }

    public function test_invalid_pdf_is_rejected_instead_of_falling_back_to_the_original(): void
    {
        $this->expectException(RuntimeException::class);
        app(StorePdfPreviewPreparer::class)->prepareSample('%PDF-1.4 not a valid PDF', 2);
    }
}
