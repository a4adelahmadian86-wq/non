<?php

namespace Tests\Unit;

use App\Services\StoreProductSampleBuilder;
use Tests\TestCase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class StoreProductSampleBuilderTest extends TestCase
{
    public function test_sample_contains_only_valid_unique_pages_selected_by_the_model(): void
    {
        $source = new Fpdi();
        foreach ([1, 2, 3, 4] as $page) {
            $source->AddPage();
            $source->SetFont('Arial', '', 12);
            $source->Cell(0, 10, 'Source page '.$page);
        }

        $sourceBytes = $source->Output('S');
        $sample = app(StoreProductSampleBuilder::class)->build($sourceBytes, [3, 3, 0, 99, 1]);

        $this->assertNotNull($sample);
        $this->assertSame([3, 1], $sample['selected_pages']);
        $this->assertSame(4, $sample['source_page_count']);
        $this->assertSame(2, $sample['included_page_count']);
        $this->assertStringStartsWith('%PDF-', $sample['bytes']);

        $reader = new Fpdi();
        $this->assertSame(2, $reader->setSourceFile(StreamReader::createByString($sample['bytes'])));
    }

    public function test_sample_generation_rejects_non_pdf_input(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('product_sample_source_invalid');

        app(StoreProductSampleBuilder::class)->build('not a PDF', [1]);
    }

    public function test_empty_or_invalid_page_selection_does_not_create_an_artifact(): void
    {
        $source = new Fpdi();
        $source->AddPage();

        $this->assertNull(app(StoreProductSampleBuilder::class)->build($source->Output('S'), [0, 2, 'bad']));
    }
}
