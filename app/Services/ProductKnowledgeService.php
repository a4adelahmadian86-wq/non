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
        $product->loadMissing(['files.document.chunks', 'files.document.artifacts', 'files.document.facts']);

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

            $facts = [];
            foreach ($document->facts as $fact) {
                if (! hash_equals((string) $file->sha256, (string) $fact->source_sha256)
                    || $fact->verification_status !== 'evidence_backed') {
                    continue;
                }
                $factLocator = (array) ($fact->locator_json ?? []);
                if ($sampleOnly) {
                    $factPages = array_filter([
                        isset($factLocator['page']) ? (int) $factLocator['page'] : null,
                        ...(is_array($factLocator['pages'] ?? null) ? array_map('intval', $factLocator['pages']) : []),
                    ], static fn ($page) => is_int($page) && $page > 0);
                    if (array_intersect($samplePages, $factPages) === []) {
                        continue;
                    }
                }
                $factPayload = [
                    'type' => (string) $fact->fact_type,
                    'key' => (string) $fact->fact_key,
                    'value' => (string) $fact->fact_value,
                    'evidence' => (string) $fact->evidence_text,
                    'locator' => $factLocator,
                    'verification_status' => 'evidence_backed',
                ];
                $cost = mb_strlen($factPayload['key']) + mb_strlen($factPayload['value']) + mb_strlen($factPayload['evidence']);
                if ($cost > $remaining) break;
                $facts[] = $factPayload;
                $remaining -= $cost;
                if (count($facts) >= 60) break;
            }

            $review = (array) data_get($document->structure_json, 'ai_review', []);
            $completeness = (array) data_get($document->structure_json, 'completeness', []);
            $sources[] = [
                'source_file_id' => (int) $file->id,
                'source_sha256' => (string) $document->source_sha256,
                'original_name' => (string) $file->original_name,
                'source_type' => (string) ($document->detected_type ?: $file->mime),
                'processing_status' => (string) $document->processing_status,
                'semantic_review_complete' => (data_get($review, 'provider_status.groq_text') === 'completed')
                    && (bool) ($completeness['analysis_batches_complete'] ?? false),
                'extraction_complete' => (bool) ($completeness['extraction_complete'] ?? false),
                'analysis_batch_count' => (int) ($completeness['analysis_batch_count'] ?? data_get($review, 'analysis_batch_count', 0)),
                'successful_analysis_batches' => (int) ($completeness['successful_analysis_batches'] ?? data_get($review, 'successful_analysis_batches', 0)),
                'page_count' => $document->page_count,
                'word_count' => $document->word_count,
                'character_count' => $document->character_count,
                'summary' => $sampleOnly ? null : data_get($review, 'summary'),
                'findings' => $sampleOnly ? [] : data_get($review, 'findings', []),
                'facts' => $facts,
                'fact_count' => count($facts),
                'chunks' => $chunks,
                'audio' => $sampleOnly ? null : data_get($document->structure_json, 'audio.status'),
                'sample_pages' => $samplePages,
            ];

            if ($remaining <= 0) break;
        }

        $hasIncompleteSource = collect($sources)->contains(function (array $source): bool {
            $expected = (int) ($source['analysis_batch_count'] ?? 0);
            $successful = (int) ($source['successful_analysis_batches'] ?? 0);

            return ($source['processing_status'] ?? null) !== 'completed'
                || ! ($source['semantic_review_complete'] ?? false)
                || ! ($source['extraction_complete'] ?? false)
                || ($expected > 0 && $successful < $expected);
        });
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
            'grounding_instruction' => 'Use only the supplied product record, evidence-backed facts, and source-located chunks. Every factual claim must be supported by its exact evidence and locator. Do not infer missing pages, features, lessons, specifications, or benefits. If evidence is absent or processing is incomplete, state that it is unknown.',
        ];
    }
}
