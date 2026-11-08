<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductKnowledgeService
{
    /**
     * Build a source-grounded context for any AI feature. Never infer facts from missing,
     * stale, or only partially extracted documents.
     */
    public function contextForProduct(StoreProduct|int $product, int $maxCharacters = 12000, bool $sampleOnly = false): array
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

            $samplePages = array_values(array_filter(
                (array) data_get($document->structure_json, 'sample.selected_pages', []),
                static fn ($page) => is_int($page) && $page > 0
            ));
            if ($sampleOnly && $samplePages === []) {
                continue;
            }

            $chunks = [];
            foreach ($document->chunks as $chunk) {
                if ($remaining <= 0) break;
                $locator = (array) ($chunk->locator_json ?? []);
                if ($sampleOnly) {
                    $chunkPages = array_filter(array_merge(
                        isset($locator['page']) ? [(int) $locator['page']] : [],
                        is_array($locator['pages'] ?? null) ? $locator['pages'] : [],
                        isset($locator['page_start'], $locator['page_end']) ? range((int) $locator['page_start'], (int) $locator['page_end']) : []
                    ), static fn ($page) => is_int($page) || ctype_digit((string) $page));
                    if (array_intersect($samplePages, array_map('intval', $chunkPages)) === []) {
                        continue;
                    }
                }
                $text = mb_substr((string) $chunk->content_text, 0, $remaining);
                if ($text === '') continue;
                $chunks[] = [
                    'chunk_type' => $chunk->chunk_type,
                    'locator' => $locator,
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
                'summary' => $sampleOnly ? null : data_get($review, 'summary'),
                'findings' => $sampleOnly ? [] : data_get($review, 'findings', []),
                'chunks' => $chunks,
                'audio' => $sampleOnly ? null : data_get($document->structure_json, 'audio.status'),
                'sample_pages' => $samplePages,
            ];

            if ($remaining <= 0) break;
        }

        $hasIncompleteSource = collect($sources)->contains(fn (array $source) => ! ($source['semantic_review_complete'] ?? false));
        $knowledgeStatus = $sources === []
            ? 'not_available'
            : ($remaining <= 0 ? 'context_truncated' : ($hasIncompleteSource ? 'partial' : 'available'));

        return [
            'product_id' => (int) $product->id,
            'product_title' => (string) $product->title,
            'product_type' => (string) $product->type,
            'download_policy' => (string) $product->download_policy,
            'knowledge_status' => $knowledgeStatus,
            'source_count' => count($sources),
            'remaining_character_budget' => $remaining,
            'access_scope' => $sampleOnly ? 'public_sample_only' : 'purchased_full_document',
            'sources' => $sources,
            'grounding_instruction' => 'Use only the supplied product record and source-located chunks. Do not infer missing pages, features, lessons, specifications, or benefits. If evidence is absent or processing is incomplete, state that it is unknown.',
        ];
    }
}
