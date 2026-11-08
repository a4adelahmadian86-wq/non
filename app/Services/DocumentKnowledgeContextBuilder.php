<?php

namespace App\\Services;

use App\\Models\\StoreDocumentRecord;
use Illuminate\\Support\\Collection;

class DocumentKnowledgeContextBuilder
{
    public function forProduct(int $productId, int $maxCharacters = 12000): array
    {
        $limit = max(1000, min(30000, $maxCharacters));
        $remaining = $limit;
        $documents = StoreDocumentRecord::query()
            ->with(['file:id,product_id,original_name,sha256,is_active', 'chunks:id,document_id,chunk_index,content_type,page_number,heading,content,source_anchor,content_hash', 'facts:id,document_id,fact_key,fact_value,confidence,evidence,model'])
            ->where('product_id', $productId)
            ->whereIn('status', ['ready', 'extracted', 'partial'])
            ->whereHas('file', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        $sources = [];
        $chunks = [];
        $facts = [];
        foreach ($documents as $document) {
            $file = $document->file;
            if (! $file || ! hash_equals(strtolower((string) $document->source_sha256), strtolower((string) $file->sha256))) {
                continue;
            }

            $sources[] = [
                'document_id' => $document->id,
                'file_id' => $file->id,
                'filename' => $file->original_name,
                'sha256' => $document->source_sha256,
                'status' => $document->status,
                'page_count' => $document->page_count,
            ];

            foreach ($document->chunks as $chunk) {
                if ($remaining <= 0) break;
                $content = mb_substr((string) $chunk->content, 0, $remaining);
                if ($content === '') continue;
                $chunks[] = [
                    'document_id' => $document->id,
                    'file_id' => $file->id,
                    'filename' => $file->original_name,
                    'chunk_index' => $chunk->chunk_index,
                    'content_type' => $chunk->content_type,
                    'page_number' => $chunk->page_number,
                    'heading' => $chunk->heading,
                    'text' => $content,
                    'source_anchor' => $chunk->source_anchor ?? [],
                    'content_hash' => $chunk->content_hash,
                ];
                $remaining -= mb_strlen($content);
            }

            foreach ($document->facts as $fact) {
                $facts[] = [
                    'document_id' => $document->id,
                    'file_id' => $file->id,
                    'key' => $fact->fact_key,
                    'value' => $fact->fact_value,
                    'confidence' => (float) $fact->confidence,
                    'evidence' => $fact->evidence ?? [],
                    'model' => $fact->model,
                ];
            }
        }

        return [
            'schema_version' => 1,
            'product_id' => $productId,
            'sources' => $sources,
            'chunks' => $chunks,
            'facts' => $facts,
            'truncated' => $remaining <= 0,
            'content_characters' => $limit - $remaining,
            'instruction' => 'Use only supplied source facts. Every factual claim must retain its file and page/source reference. If a fact is absent, say it is not present in the indexed source.',
        ];
    }

    public function searchProduct(int $productId, string $query, int $maxCharacters = 12000): array
    {
        $term = trim(mb_substr($query, 0, 160));
        if ($term === '') {
            return $this->forProduct($productId, $maxCharacters);
        }

        $documents = StoreDocumentRecord::query()
            ->with(['file:id,product_id,original_name,sha256,is_active'])
            ->where('product_id', $productId)
            ->whereIn('status', ['ready', 'extracted', 'partial'])
            ->whereHas('file', fn ($builder) => $builder->where('is_active', true))
            ->get()
            ->filter(fn ($document) => $document->file && hash_equals(strtolower((string) $document->source_sha256), strtolower((string) $document->file->sha256)));

        $documentIds = $documents->pluck('id')->all();
        if ($documentIds === []) {
            return ['schema_version' => 1, 'product_id' => $productId, 'query' => $term, 'sources' => [], 'chunks' => [], 'facts' => [], 'truncated' => false];
        }

        $limit = max(1000, min(30000, $maxCharacters));
        $remaining = $limit;
        $matchedChunks = \\App\\Models\\StoreDocumentChunk::query()
            ->whereIn('document_id', $documentIds)
            ->where('content', 'like', '%'.$term.'%')
            ->orderBy('document_id')
            ->orderBy('chunk_index')
            ->limit(30)
            ->get();

        $chunks = [];
        $matchedDocumentIds = [];
        foreach ($matchedChunks as $chunk) {
            if ($remaining <= 0) break;
            $document = $documents->firstWhere('id', $chunk->document_id);
            if (! $document || ! $document->file) continue;
            $content = mb_substr((string) $chunk->content, 0, $remaining);
            $chunks[] = [
                'document_id' => $document->id,
                'file_id' => $document->file->id,
                'filename' => $document->file->original_name,
                'chunk_index' => $chunk->chunk_index,
                'content_type' => $chunk->content_type,
                'page_number' => $chunk->page_number,
                'heading' => $chunk->heading,
                'text' => $content,
                'source_anchor' => $chunk->source_anchor ?? [],
                'content_hash' => $chunk->content_hash,
            ];
            $matchedDocumentIds[] = $document->id;
            $remaining -= mb_strlen($content);
        }

        $facts = \\App\\Models\\StoreDocumentFact::query()
            ->whereIn('document_id', array_values(array_unique($matchedDocumentIds)))
            ->where(function ($builder) use ($term) {
                $builder->where('fact_key', 'like', '%'.$term.'%')->orWhere('fact_value', 'like', '%'.$term.'%');
            })
            ->limit(50)
            ->get()
            ->map(fn ($fact) => [
                'document_id' => $fact->document_id,
                'key' => $fact->fact_key,
                'value' => $fact->fact_value,
                'confidence' => (float) $fact->confidence,
                'evidence' => $fact->evidence ?? [],
                'model' => $fact->model,
            ])->all();

        $sources = $documents->whereIn('id', array_values(array_unique($matchedDocumentIds)))->map(fn ($document) => [
            'document_id' => $document->id,
            'file_id' => $document->file->id,
            'filename' => $document->file->original_name,
            'sha256' => $document->source_sha256,
            'status' => $document->status,
            'page_count' => $document->page_count,
        ])->values()->all();

        return [
            'schema_version' => 1,
            'product_id' => $productId,
            'query' => $term,
            'sources' => $sources,
            'chunks' => $chunks,
            'facts' => $facts,
            'truncated' => $remaining <= 0,
            'content_characters' => $limit - $remaining,
            'instruction' => 'Use only returned source evidence. Preserve filename, page and source anchors. If no matching source is returned, state that the indexed files do not contain a verified answer.',
        ];
    }

    public function forFile(int $fileId, int $maxCharacters = 12000): array
    {
        $document = StoreDocumentRecord::query()
            ->with(['file:id,product_id,original_name,sha256,is_active', 'chunks:id,document_id,chunk_index,content_type,page_number,heading,content,source_anchor,content_hash', 'facts:id,document_id,fact_key,fact_value,confidence,evidence,model'])
            ->where('file_id', $fileId)
            ->first();

        if (! $document || ! $document->file || ! $document->file->is_active
            || ! hash_equals(strtolower((string) $document->source_sha256), strtolower((string) $document->file->sha256))) {
            return ['schema_version' => 1, 'file_id' => $fileId, 'sources' => [], 'chunks' => [], 'facts' => [], 'truncated' => false];
        }

        $limit = max(1000, min(30000, $maxCharacters));
        $remaining = $limit;
        $chunks = [];
        foreach ($document->chunks as $chunk) {
            if ($remaining <= 0) break;
            $content = mb_substr((string) $chunk->content, 0, $remaining);
            if ($content === '') continue;
            $chunks[] = [
                'document_id' => $document->id,
                'file_id' => $document->file->id,
                'filename' => $document->file->original_name,
                'chunk_index' => $chunk->chunk_index,
                'content_type' => $chunk->content_type,
                'page_number' => $chunk->page_number,
                'heading' => $chunk->heading,
                'text' => $content,
                'source_anchor' => $chunk->source_anchor ?? [],
                'content_hash' => $chunk->content_hash,
            ];
            $remaining -= mb_strlen($content);
        }

        return [
            'schema_version' => 1,
            'file_id' => $fileId,
            'product_id' => (int) $document->product_id,
            'sources' => [['document_id' => $document->id, 'file_id' => $document->file->id, 'filename' => $document->file->original_name, 'sha256' => $document->source_sha256, 'status' => $document->status, 'page_count' => $document->page_count]],
            'chunks' => $chunks,
            'facts' => $document->facts->map(fn ($fact) => ['document_id' => $document->id, 'key' => $fact->fact_key, 'value' => $fact->fact_value, 'confidence' => (float) $fact->confidence, 'evidence' => $fact->evidence ?? [], 'model' => $fact->model])->all(),
            'truncated' => $remaining <= 0,
            'content_characters' => $limit - $remaining,
        ];
    }
}
