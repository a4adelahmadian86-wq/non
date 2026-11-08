<?php

namespace App\\Services;

use App\\Models\\StoreDocumentRecord;
use Illuminate\\Support\\Collection;

class DocumentKnowledgeContextBuilder
{
    public function forProduct(int $productId, int $maxCharacters = 12000): array
    {
        $remaining = max(1000, min(30000, $maxCharacters));
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
                if ($remaining <= 0) break 2;
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
            'content_characters' => max(0, $maxCharacters - $remaining),
            'instruction' => 'Use only supplied source facts. Every factual claim must retain its file and page/source reference. If a fact is absent, say it is not present in the indexed source.',
        ];
    }

    public function forFile(int $fileId, int $maxCharacters = 12000): array
    {
        $document = StoreDocumentRecord::query()->where('file_id', $fileId)->first();
        if (! $document) {
            return ['schema_version' => 1, 'file_id' => $fileId, 'sources' => [], 'chunks' => [], 'facts' => [], 'truncated' => false];
        }

        return $this->forProduct((int) $document->product_id, $maxCharacters);
    }
}
