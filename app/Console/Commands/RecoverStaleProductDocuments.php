<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductDocument;
use App\Services\AuditEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecoverStaleProductDocuments extends Command
{
    protected $signature = 'farast:recover-product-documents
        {--limit=50 : Maximum documents to inspect in one run}
        {--processing-minutes=25 : Minimum age of a processing lease before recovery}
';

    protected $description = 'Safely redispatch abandoned product-document jobs after verifying their immutable source files.';

    public function handle(AuditEventService $audit): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $processingMinutes = max(15, min(240, (int) $this->option('processing-minutes')));
        $processingCutoff = now()->subMinutes($processingMinutes);

        $ids = StoreProductDocument::query()
            ->where('processing_status', 'processing')
            ->where('updated_at', '<=', $processingCutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        $recovered = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            try {
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

                $disk = Storage::disk($file->disk ?: 'private');
                if (! $disk->exists($file->path)) {
                    $skipped++;
                    continue;
                }

                $stream = $disk->readStream($file->path);
                if (! is_resource($stream)) {
                    $skipped++;
                    continue;
                }

                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    $actualHash = hash_final($hash);
                } finally {
                    fclose($stream);
                }

                if (! hash_equals((string) $document->source_sha256, $actualHash)) {
                    $skipped++;
                    continue;
                }

                $wasRecovered = DB::transaction(function () use ($id, $processingCutoff, $audit): bool {
                    $locked = StoreProductDocument::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $locked) {
                        return false;
                    }

                    $eligible = $locked->processing_status === 'processing'
                        && $locked->updated_at?->lte($processingCutoff);
                    if (! $eligible) {
                        return false;
                    }

                    $previousStatus = $locked->processing_status;
                    $locked->update(['processing_status' => 'pending', 'failure_code' => null]);

                    $audit->record(
                        'store.product_document.recovery_queued',
                        null,
                        null,
                        'store_product_document',
                        (int) $locked->id,
                        [
                            'source_sha256' => $locked->source_sha256,
                            'previous_status' => $previousStatus,
                            'recovery' => 'stale_queue_lease',
                        ],
                        'system_scheduler'
                    );

                    DB::afterCommit(fn () => ProcessProductDocument::dispatch((int) $locked->id));

                    return true;
                }, 3);

                $wasRecovered ? $recovered++ : $skipped++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        $this->info("Recovered {$recovered} product document(s); skipped {$skipped}.");
        return self::SUCCESS;
    }
}
