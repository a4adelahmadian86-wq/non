<?php

namespace App\Jobs;

use App\Models\StoreProduct;
use App\Models\StoreProductPublicationReview;
use App\Services\AuthorizationService;
use App\Services\ProductKnowledgeService;
use App\Services\ProductPublicationReadiness;
use App\Services\ProductSupervisorClient;
use App\Services\ProductSupervisorSnapshotBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReviewProductPublicationRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 30;
    public int $timeout = 180;

    public function __construct(public readonly int $publicationReviewId)
    {
        $this->onQueue('documents');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        ProductSupervisorClient $supervisor,
        ProductSupervisorSnapshotBuilder $snapshotBuilder,
        ProductPublicationReadiness $readiness,
        AuthorizationService $authorization,
        ProductKnowledgeService $knowledge
    ): void {
        $queueItem = StoreProductPublicationReview::query()
            ->with(['product', 'submitter'])
            ->find($this->publicationReviewId);

        if (! $queueItem || $queueItem->status !== StoreProductPublicationReview::STATUS_PENDING_AI) {
            return;
        }
        if (! $supervisor->configured()) {
            throw new RuntimeException('product_supervisor_not_configured');
        }

        $product = StoreProduct::query()->with(['category', 'tags', 'files.document', 'course.sections.lessons.resources'])
            ->findOrFail($queueItem->product_id);
        $pendingDocuments = DB::table('store_product_documents')
            ->where('product_id', $product->id)
            ->whereIn('processing_status', ['pending', 'processing'])
            ->exists();
        if ($pendingDocuments) {
            $this->release(60);
            return;
        }

        $settings = [
            'enabled' => true,
            'mode' => 'review_gate',
            'checks' => [
                'content' => true,
                'pricing' => true,
                'seo' => true,
                'media' => true,
                'policy' => true,
                'course' => true,
            ],
            'instructions' => 'ادعاهای اغراق‌آمیز، نادرست یا فاقد پشتوانه را مشخص کن. اصلاح پیشنهادی باید حداقلی، دقیق و مبتنی بر فایل‌های منبع باشد. هیچ محتوای اثبات‌نشده‌ای را تأیید نکن.',
        ];
        $snapshot = $snapshotBuilder->build($product, $settings);
        $snapshot['document_knowledge'] = $knowledge->contextForProduct($product, 12000);
        if ($snapshot['checks'] === []) {
            throw new RuntimeException('product_supervisor_checks_unavailable');
        }

        $snapshotHash = $snapshotBuilder->fingerprint($snapshot);
        $review = $supervisor->review($snapshot);
        $review['snapshot_hash'] = $snapshotHash;

        $documentAnalysisIncomplete = DB::table('store_product_documents')
            ->where('product_id', $product->id)
            ->whereIn('processing_status', ['failed', 'awaiting_provider', 'stale'])
            ->exists();
        $manualSecurityReview = data_get($product->metadata, 'intake_security_review.status') === 'manual_review_required'
            || $documentAnalysisIncomplete;
        $submitterCanPublish = $queueItem->submitter
            && $authorization->allows($queueItem->submitter, 'store.products.publish');
        $productReady = (bool) $product->category?->is_active;

        if ($productReady && $product->type === 'course') {
            $productReady = $product->course()
                ->whereHas('sections.lessons', fn ($query) => $query->publishable())
                ->exists();
        } elseif ($productReady) {
            $productReady = $product->files()
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->where('scan_status', 'clean')
                ->exists();

            if ($productReady && ($product->download_policy ?? 'signed') === 'reader_only') {
                $productReady = $readiness->hasReadyPrimaryReader($product);
            }
        }

        $autoPublish = ($review['verdict'] ?? null) === 'approved'
            && $submitterCanPublish
            && $productReady
            && ! $manualSecurityReview;

        DB::transaction(function () use ($queueItem, $product, $review, $settings, $autoPublish) {
            $lockedQueue = StoreProductPublicationReview::query()
                ->whereKey($queueItem->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedQueue->status !== StoreProductPublicationReview::STATUS_PENDING_AI) {
                return;
            }

            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($lockedProduct->metadata ?? []);
            $supervisorMeta = (array) data_get($metadata, 'ai_supervisor', []);
            $supervisorMeta = array_replace_recursive($supervisorMeta, $settings);
            $supervisorMeta['last_review'] = $review;
            $supervisorMeta['last_reviewed_at'] = now()->toISOString();
            $metadata['ai_supervisor'] = $supervisorMeta;
            $metadata['intake_publication'] = [
                'ai_verdict' => $review['verdict'] ?? 'unknown',
                'ai_score' => $review['score'] ?? null,
                'review_id' => $review['review_id'] ?? null,
                'reviewed_at' => now()->toISOString(),
                'state' => $autoPublish ? 'published' : 'pending_manager',
            ];

            if ($autoPublish) {
                $lockedProduct->update([
                    'metadata' => $metadata,
                    'status' => 'published',
                    'published_at' => now(),
                ]);
                $lockedQueue->update([
                    'status' => StoreProductPublicationReview::STATUS_PUBLISHED,
                    'ai_verdict' => $review['verdict'] ?? null,
                    'ai_score' => $review['score'] ?? null,
                    'ai_review_json' => $review,
                    'published_at' => now(),
                ]);
            } else {
                $lockedProduct->update(['metadata' => $metadata]);
                $lockedQueue->update([
                    'status' => StoreProductPublicationReview::STATUS_PENDING_MANAGER,
                    'ai_verdict' => $review['verdict'] ?? null,
                    'ai_score' => $review['score'] ?? null,
                    'ai_review_json' => $review,
                ]);
            }
        }, 3);
    }

    public function failed(\Throwable $exception): void
    {
        StoreProductPublicationReview::query()
            ->whereKey($this->publicationReviewId)
            ->where('status', StoreProductPublicationReview::STATUS_PENDING_AI)
            ->update([
                'status' => StoreProductPublicationReview::STATUS_PENDING_MANAGER,
                'ai_verdict' => 'unavailable',
                'ai_review_json' => [
                    'error' => class_basename($exception),
                    'reviewed_at' => now()->toISOString(),
                    'automated_publication' => false,
                ],
            ]);
        report($exception);
    }
}
