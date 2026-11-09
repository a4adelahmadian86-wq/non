<?php

namespace App\Services;

class ProductDocumentProcessingState
{
    /**
     * Keep deterministic extraction/reader readiness separate from optional semantic AI review.
     *
     * @return array{status:string,reader_ready:bool,completeness:array<string,mixed>}
     */
    public function derive(
        array $inspection,
        array $providers,
        int $expectedBatches,
        int $successfulBatches,
        bool $hasChunks,
        int $readerPageCount,
    ): array {
        $extractionComplete = ! (bool) ($inspection['extraction_truncated'] ?? false);
        $visualOnlyReviewComplete = $expectedBatches === 0 && ($providers['groq_vision'] ?? null) === 'completed';
        $analysisBatchesComplete = $expectedBatches > 0
            ? $successfulBatches >= $expectedBatches
            : $visualOnlyReviewComplete;
        $semanticReviewComplete = (
            ($providers['groq_text'] ?? null) === 'completed' && $analysisBatchesComplete
        ) || $visualOnlyReviewComplete;

        $status = ! $hasChunks || ! $extractionComplete
            ? 'partial'
            : ($semanticReviewComplete ? 'completed' : 'extracted');

        return [
            'status' => $status,
            'reader_ready' => $hasChunks && $extractionComplete && $readerPageCount > 0,
            'completeness' => [
                'extraction_complete' => $extractionComplete,
                'analysis_batches_complete' => $analysisBatchesComplete,
                'analysis_batch_count' => max(0, $expectedBatches),
                'successful_analysis_batches' => max(0, $successfulBatches),
                'semantic_review_complete' => $semanticReviewComplete,
                'source_hash_verified' => true,
                'status' => $status,
            ],
        ];
    }
}
