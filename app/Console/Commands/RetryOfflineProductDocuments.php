<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductDocument;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RetryOfflineProductDocuments extends Command
{
    protected $signature = 'farast:retry-offline-product-documents {--limit=25}';
    protected $description = 'Retry locally extracted product documents when the primary AI supervisor is ready again.';

    public function handle(
        ProductSupervisorClient $supervisor,
        StorageManager $storage,
        AuditEventService $audit
    ): int {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $now = now()->timestamp;

        $documents = StoreProductDocument::query()
            ->with('file')
            ->whereIn('processing_status', ['extracted', 'partial'])
            ->where('structure_json->ai_review->processing_mode', 'offline_structural_only')
            ->where('structure_json->ai_review->offline_retry_after', '<=', $now)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No offline product documents are due for retry.');
            return self::SUCCESS;
        }

        if (! $supervisor->primaryReadyForAi()) {
            $this->info('Primary AI supervisor is not ready; offline documents remain queued for a later check.');
            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($documents as $document) {
            try {
                $file = $document->file;
                if (! $file
                    || ! $file->is_active
                    || $file->scan_status !== 'clean'
                    || ! in_array($file->purpose, ['product_download', 'lesson_resource'], true)
                    || ! filled($file->sha256)
                    || ! hash_equals((string) $document->source_sha256, (string) $file->sha256)
                    || ! $storage->exists($file->disk ?: 'private', (string) $file->path)) {
                    $skipped++;
                    continue;
                }

                $wasQueued = DB::transaction(function () use ($document, $audit, $now): bool {
                    $locked = StoreProductDocument::query()
                        ->whereKey($document->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $locked || ! in_array($locked->processing_status, ['extracted', 'partial'], true)) {
                        return false;
                    }

                    $structure = (array) ($locked->structure_json ?? []);
                    if (data_get($structure, 'ai_review.processing_mode') !== 'offline_structural_only'
                        || (int) data_get($structure, 'ai_review.offline_retry_after', PHP_INT_MAX) > $now) {
                        return false;
                    }

                    $structure['ai_review']['offline_retry_after'] = now()->addHours(6)->timestamp;
                    $locked->update([
                        'processing_status' => 'pending',
                        'failure_code' => null,
                        'structure_json' => $structure,
                    ]);

                    $audit->record(
                        'store.product_document.offline_retry_queued',
                        null,
                        null,
                        'store_product_document',
                        (int) $locked->id,
                        [
                            'source_sha256' => $locked->source_sha256,
                            'previous_status' => $document->processing_status,
                            'recovery' => 'offline_ai_review_retry',
                        ],
                        'system_scheduler'
                    );

                    DB::afterCommit(fn () => ProcessProductDocument::dispatch((int) $locked->id));

                    return true;
                }, 3);

                $wasQueued ? $queued++ : $skipped++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        $this->info("Queued {$queued} offline document(s) for AI retry; skipped {$skipped}.");
        return self::SUCCESS;
    }
}
