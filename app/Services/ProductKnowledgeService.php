<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductKnowledgeService
{
    /**
     * Build a source-grounded context for any AI feature. Never infer facts from missing,
     * stale, or only partially extracted documents.
     */
    public function contextForProduct(StoreProduct|int $product, int $maxCharacters = 12000): array
    {
        $product = $product instanceof StoreProduct
            ? $product
            : StoreProduct::query()->findOrFail($product);

        $maxCharacters = max(1000, min(120000, $maxCharacters));
        $product->loadMissing(['files.document.chunks', 'files.document.artifacts']);

        $sources = [];
        $remaining = $maxCharacters;

        foreach ($product->files->where('is_active', true)->sortByDesc('is_primary') as $file) {
            $document = $file->document;
            if (! $document || ! in_array($document->processing_status, ['completed', 'extracted', 'partial'], true)) {
                continue;
            }
            if (! hash_equals((string) $file->sha256, (string) $document->source_sha256)) {
                continue;
            }

            $chunks = [];
            foreach ($document->chunks as $chunk) {
                if ($remaining <= 0) break;
                $text = mb_substr((string) $chunk->content_text, 0, $remaining);
                if ($text === '') continue;
                $chunks[] = [
                    'chunk_type' => $chunk->chunk_type,
                    'locator' => $chunk->locator_json ?? [],
                    'content' => $text,
                    'content_sha256' => $chunk->content_sha256,
                ];
                $remaining -= mb_strlen($text);
            }

            $review = (array) data_get($document->structure_json, 'ai_review', []);
            $sources[] = [
                'source_file_id' => (int) $file->id,
                'source_sha256' => (string) $document->source_sha256,
                'original_name' => (string) $file->original_name,
                'source_type' => (string) ($document->detected_type ?: $file->mime),
                'processing_status' => (string) $document->processing_status,
                'semantic_review_complete' => (data_get($review, 'provider_status.gemini') === 'completed'),
                'page_count' => $document->page_count,
                'word_count' => $document->word_count,
                'character_count' => $document->character_count,
                'summary' => data_get($review, 'summary'),
                'findings' => data_get($review, 'findings', []),
                'chunks' => $chunks,
                'audio' => data_get($document->structure_json, 'audio.status'),
                'sample_pages' => data_get($document->structure_json, 'sample.selected_pages', []),
            ];

            if ($remaining <= 0) break;
        }

        return [
            'product_id' => (int) $product->id,
            'product_title' => (string) $product->title,
            'product_type' => (string) $product->type,
            'download_policy' => (string) $product->download_policy,
            'knowledge_status' => $sources === [] ? 'not_available' : ($remaining > 0 ? 'available' : 'context_truncated'),
            'source_count' => count($sources),
            'remaining_character_budget' => $remaining,
            'sources' => $sources,
            'grounding_instruction' => 'Use only the supplied product record and source-located chunks. Do not infer missing pages, features, lessons, specifications, or benefits. If evidence is absent or processing is incomplete, state that it is unknown.',
        ];
    }
}
