<?php

namespace App\Jobs;

use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReviewProductFileAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [45, 120];

    public function __construct(public int $productId, public int $fileId)
    {
        $this->onQueue('product-supervisor');
    }

    public function handle(ProductSupervisorClient $supervisor): void
    {
        $product = StoreProduct::query()->find($this->productId);
        $file = StoreProductFile::query()
            ->whereKey($this->fileId)
            ->where('product_id', $this->productId)
            ->where('purpose', 'product_download')
            ->where('is_active', true)
            ->first();

        if (! $product || ! $file) {
            return;
        }

        if (! $supervisor->configured()) {
            $this->storeReview($product, [
                'status' => 'awaiting_supervisor',
                'sha256' => (string) $file->sha256,
                'last_error_code' => 'supervisor_not_configured',
                'reviewed_at' => now()->toISOString(),
            ]);
            return;
        }

        if ((int) $file->size_bytes > 25 * 1024 * 1024) {
            $this->storeReview($product, [
                'status' => 'skipped_size_limit',
                'sha256' => (string) $file->sha256,
                'summary' => 'فایل از سقف مستقیم ناظر بیشتر است؛ بررسی محصول از طریق دانش استخراج‌شده انجام می‌شود.',
                'reviewed_at' => now()->toISOString(),
            ]);
            $this->dispatchPublicationIfWaiting($product);
            return;
        }

        $disk = Storage::disk($file->disk ?: 'private');
        if (! $disk->exists($file->path)) {
            throw new \RuntimeException('product_asset_missing');
        }

        $this->storeReview($product, [
            'status' => 'reviewing',
            'sha256' => (string) $file->sha256,
            'started_at' => now()->toISOString(),
        ]);

        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('product_asset_stream_unavailable');
        }

        try {
            $hashContext = hash_init('sha256');
            hash_update_stream($hashContext, $stream);
            $sha256 = hash_final($hashContext);
            if (! filled($file->sha256) || ! hash_equals(strtolower((string) $file->sha256), strtolower($sha256))) {
                throw new \RuntimeException('product_asset_checksum_mismatch');
            }
            rewind($stream);

            $product->loadMissing(['category', 'tags']);
            $review = $supervisor->reviewFile($stream, $file->original_name ?: basename($file->path), [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'primary_scan_status' => 'structural_checks_passed',
                'antivirus_status' => str_contains((string) $file->scan_engine, 'clamav') ? 'clean' : 'not_requested',
                'category_name' => $product->category?->name,
                'tags_json' => json_encode($product->tags->pluck('name')->take(30)->values()->all(), JSON_UNESCAPED_UNICODE),
                'description' => mb_substr((string) $product->description, 0, 8000),
            ]);
        } finally {
            fclose($stream);
        }

        $inspection = (array) ($review['inspection'] ?? []);
        $this->storeReview($product, [
            'status' => 'completed',
            'review_id' => (string) ($review['review_id'] ?? ''),
            'summary' => mb_substr((string) ($review['summary'] ?? ''), 0, 700),
            'findings' => array_slice(is_array($review['findings'] ?? null) ? $review['findings'] : [], 0, 4),
            'warnings' => array_map(
                static fn ($warning) => mb_substr((string) $warning, 0, 400),
                array_slice(is_array($review['warnings'] ?? null) ? $review['warnings'] : [], 0, 6)
            ),
            'provider_status' => (array) ($review['provider_status'] ?? []),
            'inspection' => [
                'detected_type' => (string) ($inspection['detected_type'] ?? 'unknown'),
                'extracted_characters' => (int) ($inspection['extracted_characters'] ?? 0),
                'antivirus_status' => (string) ($inspection['antivirus_status'] ?? 'not_configured'),
                'upstream_scan_status' => (string) ($inspection['upstream_scan_status'] ?? 'not_provided'),
                'archive_entries' => array_slice(is_array($inspection['archive_entries'] ?? null) ? $inspection['archive_entries'] : [], 0, 10),
            ],
            'security_gate' => (string) ($review['security_gate'] ?? 'manual_scan_required'),
            'publication_authorized' => false,
            'automated_publication' => false,
            'sha256' => $sha256,
            'reviewed_at' => now()->toISOString(),
        ]);

        app(AuditEventService::class)->record(
            'store.product.ai_supervisor_asset_reviewed',
            null,
            null,
            'store_product',
            $product->id,
            [
                'review_id' => $review['review_id'] ?? null,
                'file_id' => $file->id,
                'security_gate' => $review['security_gate'] ?? null,
            ],
            'system'
        );

        $this->dispatchPublicationIfWaiting($product);
    }

    public function failed(Throwable $exception): void
    {
        $product = StoreProduct::query()->find($this->productId);
        if (! $product) {
            return;
        }

        Log::warning('Product supervisor asset review job failed', [
            'product_id' => $this->productId,
            'file_id' => $this->fileId,
            'exception' => class_basename($exception),
        ]);
        $file = StoreProductFile::query()->whereKey($this->fileId)->first();
        $this->storeReview($product, [
            'status' => 'failed',
            'sha256' => (string) ($file?->sha256 ?? ''),
            'last_error_code' => 'asset_supervisor_review_failed',
            'reviewed_at' => now()->toISOString(),
        ]);
    }

    private function storeReview(StoreProduct $product, array $review): void
    {
        DB::transaction(function () use ($product, $review) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($locked->metadata ?? []);
            $supervisor = (array) data_get($metadata, 'ai_supervisor', []);
            $assetReviews = (array) ($supervisor['asset_reviews'] ?? []);
            $assetReviews['file:'.$this->fileId] = array_replace(
                (array) ($assetReviews['file:'.$this->fileId] ?? []),
                $review
            );
            if (count($assetReviews) > 100) {
                $assetReviews = array_slice($assetReviews, -100, null, true);
            }
            $supervisor['asset_reviews'] = $assetReviews;
            $metadata['ai_supervisor'] = $supervisor;
            $locked->update(['metadata' => $metadata]);
        }, 3);
    }

    private function dispatchPublicationIfWaiting(StoreProduct $product): void
    {
        $product->refresh();
        if (data_get($product->metadata, 'publication_workflow.status') === 'awaiting_assets') {
            ReviewProductForPublication::dispatch((int) $product->id);
        }
    }
}
