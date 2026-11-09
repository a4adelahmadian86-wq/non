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
        $detectedType = (string) ($inspection['detected_type'] ?? '');
        $transcriptionKnown = array_key_exists('transcription_truncated', $inspection);
        $transcriptionComplete = $detectedType !== 'audio'
            || ($transcriptionKnown && ! (bool) $inspection['transcription_truncated']);
        $extractionComplete = ! (bool) ($inspection['extraction_truncated'] ?? false)
            && $transcriptionComplete;

        $imageCapableTypes = ['pdf', 'docx', 'pptx', 'xlsx'];
        $inventoryKnown = array_key_exists('visual_inventory_complete', $inspection);
        $visualInventoryComplete = $inventoryKnown
            ? (bool) $inspection['visual_inventory_complete']
            : (! in_array($detectedType, $imageCapableTypes, true)
                && ! ($detectedType === 'audio' && ! $transcriptionKnown));
        if ($detectedType === 'image' && ($providers['groq_vision'] ?? null) === 'completed') {
            $visualInventoryComplete = true;
        }

        $visualReviewRequired = (int) ($inspection['embedded_image_count'] ?? 0) > 0
            || $detectedType === 'image';
        $visualReviewComplete = ! $visualReviewRequired
            || (
                ($providers['groq_vision'] ?? null) === 'completed'
                && $visualInventoryComplete
                && ! (bool) ($inspection['visual_review_truncated'] ?? false)
            );
        $textReviewComplete = $expectedBatches > 0
            && $successfulBatches >= $expectedBatches
            && ($providers['groq_text'] ?? null) === 'completed';
        $visualOnlyReviewComplete = $expectedBatches === 0
            && $visualReviewRequired
            && $visualReviewComplete
            && (int) ($inspection['extracted_characters'] ?? 0) === 0;
        $analysisBatchesComplete = $expectedBatches > 0
            ? $successfulBatches >= $expectedBatches
            : $visualOnlyReviewComplete;
        $semanticReviewComplete = $visualInventoryComplete
            && (
                ($textReviewComplete && $visualReviewComplete)
                || $visualOnlyReviewComplete
            );

        $hasUsableKnowledge = $hasChunks || $visualOnlyReviewComplete;
        $status = ! $hasUsableKnowledge || ! $extractionComplete
            ? 'partial'
            : ($semanticReviewComplete ? 'completed' : 'extracted');

        return [
            'status' => $status,
            'reader_ready' => $hasChunks && $extractionComplete && $readerPageCount > 0,
            'completeness' => [
                'extraction_complete' => $extractionComplete,
                'transcription_complete' => $transcriptionComplete,
                'visual_inventory_complete' => $visualInventoryComplete,
                'visual_review_required' => $visualReviewRequired,
                'visual_review_complete' => $visualReviewComplete,
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
