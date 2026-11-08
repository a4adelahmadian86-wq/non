<?php

namespace Tests\Unit;

use App\Services\StoreProductDocumentPageBuilder;
use Tests\TestCase;

class StoreProductDocumentPageBuilderTest extends TestCase
{
    public function test_pdf_reader_pages_are_prebuilt_and_keep_original_page_locators(): void
    {
        $result = app(StoreProductDocumentPageBuilder::class)->build([
            [
                'chunk_type' => 'pdf_page',
                'locator' => ['page' => 2],
                'content_text' => 'متن صفحه دوم',
            ],
            [
                'chunk_type' => 'pdf_page',
                'locator' => ['page' => 1],
                'content_text' => 'متن صفحه اول <script>alert(1)</script>',
            ],
        ], [
            'detected_type' => 'pdf',
            'page_count' => 3,
        ]);

        $this->assertSame(3, $result['page_count']);
        $this->assertSame([1, 2, 3], array_column($result['pages'], 'page_number'));
        $this->assertSame('page:1', $result['pages'][0]['locator_key']);
        $this->assertStringContainsString('متن صفحه اول', $result['pages'][0]['content_text']);
        $this->assertStringContainsString('&lt;script&gt;', $result['pages'][0]['content_html']);
        $this->assertStringNotContainsString('<script>', $result['pages'][0]['content_html']);
        $this->assertSame(2, $result['chunk_locators'][0]['reader_page']);
        $this->assertSame(1, $result['chunk_locators'][1]['reader_page']);
        $this->assertFalse($result['pages'][2]['has_extracted_text']);
    }

    public function test_spreadsheet_sheets_become_separate_reader_sections(): void
    {
        $result = app(StoreProductDocumentPageBuilder::class)->build([
            [
                'chunk_type' => 'xlsx_rows',
                'locator' => ['sheet' => 'Sales', 'row_start' => 1, 'row_end' => 10],
                'content_text' => 'فروش | ماه | مبلغ',
            ],
            [
                'chunk_type' => 'xlsx_rows',
                'locator' => ['sheet' => 'Inventory', 'row_start' => 1, 'row_end' => 8],
                'content_text' => 'کالا | موجودی',
            ],
        ], [
            'detected_type' => 'office',
            'sheet_count' => 2,
        ]);

        $this->assertSame(2, $result['page_count']);
        $this->assertSame('sheet:Sales', $result['pages'][0]['locator_key']);
        $this->assertSame('sheet:Inventory', $result['pages'][1]['locator_key']);
        $this->assertSame(1, $result['chunk_locators'][0]['reader_page']);
        $this->assertSame(2, $result['chunk_locators'][1]['reader_page']);
    }
    public function test_reader_html_exposes_sentence_and_paragraph_playback_targets(): void
    {
        $result = app(StoreProductDocumentPageBuilder::class)->build([
            ['chunk_type' => 'text', 'locator' => ['page' => 1], 'content_text' => "سلام. حال شما چطور است؟\n\nاین پاراگراف دوم است."],
        ], ['detected_type' => 'pdf', 'page_count' => 1]);

        $html = $result['pages'][0]['content_html'];
        $this->assertStringContainsString('reader-document-paragraph', $html);
        $this->assertStringContainsString('reader-document-sentence', $html);
        $this->assertStringContainsString('سلام.', $html);
        $this->assertStringContainsString('این پاراگراف دوم است.', $html);
        $this->assertSame("سلام. حال شما چطور است؟\n\nاین پاراگراف دوم است.", $result['pages'][0]['content_text']);
    }
}
