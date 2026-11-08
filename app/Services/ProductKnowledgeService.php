<?php

namespace App\Services;

use App\Models\StoreProduct;
use App\Models\StoreProductDocument;

class ProductKnowledgeService
{
    public function forAssistant(StoreProduct|int $product, int $maxCharacters = 60000, int $maxBlocks = 120): array
    {
        $productId = $product instanceof StoreProduct ? $product->id : $product;
        $documents = StoreProductDocument::query()
            ->with(['blocks', 'file'])
            ->where('product_id', $productId)
            ->whereIn('processing_state', ['studied', 'extracted_only'])
            ->whereHas('file', function ($query) {
                $query->where('is_active', true)
                    ->whereColumn('store_product_files.sha256', 'store_product_documents.source_sha256');
            })
            ->orderByDesc('content_studied_at')
            ->limit(20)
            ->get();

        $remaining = max(1000, min($maxCharacters, 120000));
        $blockLimit = max(1, min($maxBlocks, 500));
        $result = [];

        foreach ($documents as $document) {
            $blocks = [];
            foreach ($document->blocks as $block) {
                if (count($blocks) >= $blockLimit || $remaining <= 0) {
                    break;
                }
                $text = mb_substr((string) $block->text_content, 0, $remaining);
                if ($text === '') {
                    continue;
                }
                $blocks[] = [
                    'block_id' => $block->id,
                    'sequence' => $block->sequence,
                    'type' => $block->block_type,
                    'page_number' => $block->page_number,
                    'section' => $block->section_path,
                    'text' => $text,
                    'sha256' => $block->content_sha256,
                ];
                $remaining -= mb_strlen($text);
            }

            $manifest = (array) $document->content_manifest;
            $result[] = [
                'document_id' => $document->id,
                'source_sha256' => $document->source_sha256,
                'filename' => $document->original_name,
                'mime' => $document->detected_mime,
                'page_count' => $document->page_count,
                'content_version' => $document->content_version,
                'study_status' => $document->processing_state,
                'summary' => data_get($manifest, 'summary'),
                'outline' => array_slice((array) data_get($manifest, 'outline', []), 0, 80),
                'key_topics' => array_slice((array) data_get($manifest, 'key_topics', []), 0, 40),
                'grounded_facts' => array_slice((array) data_get($manifest, 'grounded_facts', []), 0, 100),
                'blocks' => $blocks,
                'content_truncated_for_context' => $remaining <= 0,
            ];
        }

        return [
            'product_id' => (string) $productId,
            'knowledge_available' => $result !== [],
            'grounding_policy' => [
                'source_of_truth' => 'persisted_document_blocks_and_evidence',
                'do_not_infer_missing_details' => true,
                'cite_document_and_page_when_available' => true,
                'if_information_is_missing_say_so' => true,
                'never_treat_model_summary_as_source_text' => true,
            ],
            'documents' => $result,
        ];
    }
}
