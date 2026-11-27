<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductDocument;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryIncompleteProductDocuments extends Command
{
    protected $signature = 'farast:retry-incomplete-product-documents
        {--limit=25 : Maximum extracted documents to requeue in one run}
        {--age-hours=6 : Minimum age of an incomplete review before retry}
';

    protected $description = 'Requeue locally extracted product documents whose external AI review was unavailable or not configured.';

    private const RETRYABLE_PROVIDER_STATUSES = ['unavailable', 'not_configured', 'privacy_not_confirmed'];

    private const PROVIDER_TASK_KEYS = [
        'cloudflare_text',
        'cloudflare_vision',
        'cloudflare_transcription',
        'groq_text',
        'groq_vision',
        'groq_transcription',
    ];

    public function handle(ProductSupervisorClient $supervisor, AuditEventService $audit): int
    {
        if (! $supervisor->configured()) {
            $this->info('Product Supervisor is not configured; incomplete documents remain queued for a later check.');
            return self::SUCCESS;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $ageHours = max(1, min(72, (int) $this->option('age-hours')));
        $cutoff = now()->subHours($ageHours);

        $ids = StoreProductDocument::query()
            ->whereIn('processing_status', ['extracted', 'partial'])
            ->whereNull('failure_code')
            ->whereNotNull('processed_at')
            ->where('processed_at', '<=', $cutoff)
            ->orderBy('processed_at')
            ->limit($limit)
            ->pluck('id');

        $queued = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $document = StoreProductDocument::query()->with('file')->find($id);
            $file = $document?->file;

            if (! $document || ! $file
                || ! $file->is_active
                || $file->scan_status !== 'clean'
                || ! in_array($file->purpose, ['product_download', 'lesson_resource'], true)
                || ! filled($file->sha256)
                || ! hash_equals((string) $document->source_sha256, (string) $file->sha256)) {
                $skipped++;
                continue;
            }

            if (! $this->hasRetryableProviderStatus((array) data_get($document->structure_json, 'ai_review.provider_status', []))) {
                $skipped++;
                continue;
            }

            $wasQueued = DB::transaction(function () use ($id, $cutoff, $audit): bool {
                $locked = StoreProductDocument::query()->whereKey($id)->lockForUpdate()->first();
                if (! $locked
                    || ! in_array($locked->processing_status, ['extracted', 'partial'], true)
                    || $locked->failure_code !== null
                    || ! $locked->processed_at
                    || $locked->processed_at->gt($cutoff)) {
                    return false;
                }

                $structure = (array) ($locked->structure_json ?? []);
                $providerStatuses = (array) data_get($structure, 'ai_review.provider_status', []);
                if (! $this->hasRetryableProviderStatus($providerStatuses)) {
                    return false;
                }

                $previousStatus = $locked->processing_status;
                $locked->update([
                    'processing_status' => 'processing',
                    'failure_code' => null,
                ]);

                $audit->record(
                    'store.product_document.incomplete_review_retry_queued',
                    null,
                    null,
                    'store_product_document',
                    (int) $locked->id,
                    [
                        'source_sha256' => $locked->source_sha256,
                        'previous_status' => $previousStatus,
                        'provider_status' => $providerStatuses,
                        'retry' => 'external_ai_review_unavailable',
                    ],
                    'system_scheduler'
                );

                DB::afterCommit(fn () => ProcessProductDocument::dispatch((int) $locked->id));

                return true;
            }, 3);

            $wasQueued ? $queued++ : $skipped++;
        }

        $this->info("Requeued {$queued} incomplete product document(s); skipped {$skipped}.");
        return self::SUCCESS;
    }

    private function hasRetryableProviderStatus(array $statuses): bool
    {
        foreach (self::PROVIDER_TASK_KEYS as $key) {
            if (in_array($statuses[$key] ?? null, self::RETRYABLE_PROVIDER_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }
}
