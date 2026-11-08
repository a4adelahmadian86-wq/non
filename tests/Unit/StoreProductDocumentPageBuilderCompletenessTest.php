<?php

namespace Tests\Unit;

use App\Services\StoreProductDocumentPageBuilder;
use InvalidArgumentException;
use Tests\TestCase;

class StoreProductDocumentPageBuilderCompletenessTest extends TestCase
{
    public function test_reader_builder_keeps_all_pages_beyond_the_old_three_hundred_page_limit(): void
    {
        $chunks = [];

        for ($page = 1; $page <= 301; $page++) {
            $chunks[] = [
                'chunk_type' => 'pdf_page',
                'locator' => ['page' => $page],
                'content_text' => 'متن صفحه '.$page,
            ];
        }

        $result = app(StoreProductDocumentPageBuilder::class)->build($chunks, [
            'detected_type' => 'pdf',
            'page_count' => 301,
        ]);

        $this->assertSame(301, $result['page_count']);
        $this->assertSame(301, $result['pages'][300]['page_number']);
        $this->assertSame('متن صفحه 301', $result['pages'][300]['content_text']);
        $this->assertSame(301, $result['chunk_locators'][300]['reader_page']);
    }

    public function test_reader_builder_does_not_silently_truncate_long_text_chunks(): void
    {
        $longText = str_repeat('متن کامل سند ', 300);

        $result = app(StoreProductDocumentPageBuilder::class)->build([
            [
                'chunk_type' => 'pdf_page',
                'locator' => ['page' => 1],
                'content_text' => $longText,
            ],
        ], [
            'detected_type' => 'pdf',
            'page_count' => 1,
        ]);

        $this->assertSame($longText, $result['pages'][0]['content_text']);
        $this->assertGreaterThan(1800, mb_strlen($result['pages'][0]['content_text']));
        $this->assertSame(hash('sha256', $longText), $result['pages'][0]['content_sha256']);
    }

    public function test_reader_builder_fails_explicitly_when_page_count_exceeds_configured_safety_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(StoreProductDocumentPageBuilder::class)->build([], [
            'detected_type' => 'pdf',
            'page_count' => 11,
            'max_reader_pages' => 10,
        ]);
    }
}
