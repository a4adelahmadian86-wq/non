<?php

namespace Tests\Unit;

use App\Services\ProductDocumentProcessingState;
use Tests\TestCase;

class ProductDocumentProcessingStateTest extends TestCase
{
    public function test_complete_extraction_enables_reader_without_external_semantic_review(): void
    {
        $state = app(ProductDocumentProcessingState::class)->derive(
            ['extraction_truncated' => false],
            ['groq_text' => 'not_configured'],
            0,
            0,
            true,
            4,
        );

        $this->assertSame('extracted', $state['status']);
        $this->assertTrue($state['reader_ready']);
        $this->assertTrue($state['completeness']['extraction_complete']);
        $this->assertFalse($state['completeness']['semantic_review_complete']);
    }

    public function test_truncated_extraction_never_enables_full_reader_or_audio(): void
    {
        $state = app(ProductDocumentProcessingState::class)->derive(
            ['extraction_truncated' => true],
            ['groq_text' => 'completed'],
            3,
            3,
            true,
            4,
        );

        $this->assertSame('partial', $state['status']);
        $this->assertFalse($state['reader_ready']);
        $this->assertFalse($state['completeness']['extraction_complete']);
    }

    public function test_complete_semantic_review_is_recorded_separately_from_extraction(): void
    {
        $state = app(ProductDocumentProcessingState::class)->derive(
            ['extraction_truncated' => false],
            ['groq_text' => 'completed'],
            3,
            3,
            true,
            4,
        );

        $this->assertSame('completed', $state['status']);
        $this->assertTrue($state['reader_ready']);
        $this->assertTrue($state['completeness']['semantic_review_complete']);
    }

    public function test_empty_or_page_less_extraction_does_not_enable_reader(): void
    {
        $empty = app(ProductDocumentProcessingState::class)->derive([], [], 0, 0, false, 4);
        $pageLess = app(ProductDocumentProcessingState::class)->derive([], [], 0, 0, true, 0);

        $this->assertSame('partial', $empty['status']);
        $this->assertFalse($empty['reader_ready']);
        $this->assertSame('extracted', $pageLess['status']);
        $this->assertFalse($pageLess['reader_ready']);
    }

    public function test_complete_image_only_visual_review_can_finish_knowledge_without_reader_pages(): void
    {
        $state = app(ProductDocumentProcessingState::class)->derive(
            ['extraction_truncated' => false],
            ['groq_vision' => 'completed', 'groq_text' => 'not_requested'],
            0,
            0,
            true,
            0,
        );

        $this->assertSame('completed', $state['status']);
        $this->assertFalse($state['reader_ready']);
        $this->assertTrue($state['completeness']['semantic_review_complete']);
        $this->assertTrue($state['completeness']['analysis_batches_complete']);
    }


    public function test_visual_review_alone_does_not_complete_a_document_with_unreviewed_text(): void
    {
        $state = app(ProductDocumentProcessingState::class)->derive(
            ['extraction_truncated' => false, 'extracted_characters' => 2400],
            ['groq_vision' => 'completed', 'groq_text' => 'not_configured'],
            0,
            0,
            true,
            0,
        );

        $this->assertSame('extracted', $state['status']);
        $this->assertFalse($state['completeness']['semantic_review_complete']);
    }

}
