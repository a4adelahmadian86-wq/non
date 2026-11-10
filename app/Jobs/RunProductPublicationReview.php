<?php

namespace App\Jobs;

use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductPublicationReview;
use App\Services\AuditEventService;
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
use Throwable;

class RunProductPublicationReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 4;

    public function __construct(public int $reviewId)
    {
        $this->onQueue('documents');
    }

    public function backoff(): array
    {
        return [60, 180, 300, 600];
    }

    public function handle(
        ProductSupervisorClient $supervisor,
        ProductSupervisorSnapshotBuilder $snapshotBuilder,
        ProductKnowledgeService $knowledge,
        ProductPublicationReadiness $readiness
    ): void {
        $review = StoreProductPublicationReview::query()->with('product')->find($this->reviewId);
        if (! $review || ! $review->product) return;
        if (! in_array($review->status, ['queued', 'reviewing', 'failed'], true)) return;

        $product = $review->product;
        if ($product->status !== 'pending_review') {
            $review->update(['status' => 'stale', 'failure_code' => 'product_not_pending_review']);
            return;
        }
        if (! $supervisor->configured()) {
            $review->update(['status' => 'failed', 'failure_code' => 'supervisor_not_configured']);
            return;
        }

        $activeFiles = $product->files()->where('purpose', 'product_download')->where('is_active', true)->get();
        if ($product->type === 'digital_file' && ! $activeFiles->contains(fn ($file) => $file->is_primary && $file->scan_status === 'clean')) {
            $this->markNeedsCorrection($review, 'primary_file_not_ready', [[
                'area' => 'media', 'severity' => 'critical',
                'title' => 'فایل اصلی سالم و فعال وجود ندارد',
                'detail' => 'برای محصول فایل‌محور، یک فایل اصلی باید در فضای خصوصی ثبت و بررسی ساختاری آن کامل شده باشد.',
                'recommendation' => 'فایل اصلی را بارگذاری و وضعیت بررسی آن را تکمیل کنید.',
            ]]);
            return;
        }

        if ($activeFiles->isNotEmpty()) {
            $documents = StoreProductDocument::query()->whereIn('product_file_id', $activeFiles->pluck('id'))->get();
            $states = $documents->pluck('processing_status')->all();
            if ($documents->count() < $activeFiles->count() || array_intersect($states, ['pending', 'processing'])) {
                $review->update(['status' => 'queued', 'failure_code' => null]);
                $this->release(60);
                return;
            }
            if ($documents->contains(fn ($document) => ! in_array($document->processing_status, ['completed', 'extracted'], true))) {
                $this->markNeedsCorrection($review, 'document_review_incomplete', [[
                    'area' => 'content', 'severity' => 'warning',
                    'title' => 'بررسی محتوای یکی از فایل‌ها کامل نیست',
                    'detail' => 'ناظر محصول نمی‌تواند محتوای فایل ناقص، قدیمی یا فاقد نتیجه پردازش را به‌عنوان دانش کامل تأیید کند.',
                    'recommendation' => 'پردازش فایل را تکمیل یا نسخه سالم و قابل بررسی آن را دوباره بارگذاری کنید.',
                ]]);
                return;
            }
        }

        $settings = (array) data_get($product->metadata, 'ai_supervisor', []);
        $settings['enabled'] = true;
        $settings['checks'] = array_fill_keys(['content', 'pricing', 'seo', 'media', 'policy', 'course'], true);
        $snapshot = $snapshotBuilder->build($product, $settings);
        $snapshot['document_knowledge'] = $knowledge->contextForProduct($product, 12000);
        $snapshotHash = $snapshotBuilder->fingerprint($snapshot);
        $review->update([
            'status' => 'reviewing',
            'snapshot_hash' => $snapshotHash,
            'reviewed_snapshot_hash' => $snapshotHash,
            'snapshot_json' => $snapshot,
            'attempts' => (int) $review->attempts + 1,
            'failure_code' => null,
        ]);

        try {
            $result = $supervisor->review($snapshot);
        } catch (Throwable $exception) {
            $review->update(['status' => 'failed', 'failure_code' => 'supervisor_request_failed']);
            report($exception);
            return;
        }

        $freshProduct = StoreProduct::query()->with(['category', 'tags'])->findOrFail($product->id);
        $freshSnapshot = $snapshotBuilder->build($freshProduct, $settings);
        $freshSnapshot['document_knowledge'] = $knowledge->contextForProduct($freshProduct, 12000);
        if (! hash_equals($snapshotHash, $snapshotBuilder->fingerprint($freshSnapshot))) {
            $review->update(['status' => 'stale', 'failure_code' => 'product_changed_during_review']);
            return;
        }

        $verdict = (string) $result['verdict'];
        $corrections = $this->normalizeCorrections((array) ($result['corrections'] ?? []));
        $findings = array_slice((array) ($result['findings'] ?? []), 0, 12);
        $critical = collect($findings)->contains(fn ($finding) => ($finding['severity'] ?? null) === 'critical');

        if ($verdict !== 'approved') {
            if ($corrections === []) {
                $this->markNeedsCorrection($review, 'no_safe_ai_correction_available', $findings, $result);
                return;
            }

            DB::transaction(function () use ($review, $freshProduct, $corrections, $result, $findings, $snapshotHash, $snapshotBuilder, $settings, $knowledge) {
                $lockedProduct = StoreProduct::query()->whereKey($freshProduct->id)->lockForUpdate()->firstOrFail();
                $lockedReview = StoreProductPublicationReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
                if ($lockedReview->status !== 'reviewing' || $lockedProduct->status !== 'pending_review') return;

                $before = [];
                foreach ($corrections as $field => $value) {
                    $before[$field] = $lockedProduct->getAttribute($field);
                    $lockedProduct->setAttribute($field, $value);
                }
                $metadata = (array) ($lockedProduct->metadata ?? []);
                $metadata['ai_supervisor']['last_auto_correction'] = [
                    'review_id' => $result['review_id'] ?? null,
                    'applied_at' => now()->toISOString(),
                    'before' => $before,
                    'after' => $corrections,
                    'requires_manager_approval' => true,
                ];
                $lockedProduct->forceFill(['metadata' => $metadata, 'status' => 'pending_review', 'published_at' => null])->save();

                $correctedProduct = $lockedProduct->fresh();
                $postCorrection = $snapshotBuilder->build($correctedProduct, $settings);
                $postCorrection['document_knowledge'] = $knowledge->contextForProduct($correctedProduct, 12000);
                $postCorrectionHash = $snapshotBuilder->fingerprint($postCorrection);
                $lockedReview->update([
                    'status' => 'awaiting_manager',
                    'verdict' => $result['verdict'],
                    'score' => (int) ($result['score'] ?? 0),
                    'review_id' => $result['review_id'] ?? null,
                    'model' => $result['model'] ?? null,
                    'summary' => (string) ($result['summary'] ?? ''),
                    'findings_json' => $findings,
                    'corrections_json' => ['before' => $before, 'after' => $corrections],
                    'snapshot_hash' => $postCorrectionHash,
                    'reviewed_snapshot_hash' => $snapshotHash,
                    'reviewed_at' => now(),
                    'failure_code' => null,
                ]);
                app(AuditEventService::class)->record(
                    'store.product.ai_auto_corrected',
                    $review->requested_by,
                    null,
                    'store_product',
                    $lockedProduct->id,
                    ['publication_review_id' => $lockedReview->id, 'fields' => array_keys($corrections), 'manager_approval_required' => true],
                    'product_supervisor'
                );
            });
            return;
        }

        $review->loadMissing('requester');
        $canAutoPublish = $review->auto_publish
            && $review->requester
            && app(AuthorizationService::class)->allows($review->requester, 'store.manage')
            && ! $critical
            && $this->canPublish($freshProduct, $readiness);

        DB::transaction(function () use ($review, $freshProduct, $result, $findings, $snapshotHash, $canAutoPublish) {
            $lockedProduct = StoreProduct::query()->whereKey($freshProduct->id)->lockForUpdate()->firstOrFail();
            $lockedReview = StoreProductPublicationReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($lockedReview->status !== 'reviewing' || $lockedProduct->status !== 'pending_review') return;

            $lockedProduct->update($canAutoPublish
                ? ['status' => 'published', 'published_at' => now()]
                : ['status' => 'pending_review', 'published_at' => null]);
            $lockedReview->update([
                'status' => $canAutoPublish ? 'published' : 'awaiting_manager',
                'verdict' => 'approved',
                'score' => (int) ($result['score'] ?? 0),
                'review_id' => $result['review_id'] ?? null,
                'model' => $result['model'] ?? null,
                'summary' => (string) ($result['summary'] ?? ''),
                'findings_json' => $findings,
                'snapshot_hash' => $snapshotHash,
                'reviewed_snapshot_hash' => $snapshotHash,
                'reviewed_at' => now(),
                'published_at' => $canAutoPublish ? now() : null,
            ]);
            app(AuditEventService::class)->record(
                $canAutoPublish ? 'store.product.auto_published_after_ai_approval' : 'store.product.ai_approved_waiting_manager',
                $review->requested_by,
                null,
                'store_product',
                $lockedProduct->id,
                ['publication_review_id' => $lockedReview->id, 'verdict' => 'approved', 'automated_publication' => $canAutoPublish],
                'product_supervisor'
            );
        });
    }

    private function canPublish(StoreProduct $product, ProductPublicationReadiness $readiness): bool
    {
        if (! $product->category()->where('is_active', true)->exists()) return false;
        if ($product->type === 'course') {
            return $product->course()->whereHas('sections.lessons', fn ($query) => $query->publishable())->exists();
        }
        $hasPrimary = $product->files()->where('purpose', 'product_download')->where('is_active', true)
            ->where('is_primary', true)->where('scan_status', 'clean')->exists();
        return $hasPrimary && (($product->download_policy ?? 'signed') !== 'reader_only' || $readiness->hasReadyPrimaryReader($product));
    }

    private function normalizeCorrections(array $corrections): array
    {
        $limits = ['title' => 220, 'short_description' => 2000, 'description' => 8000, 'seo_title' => 220, 'seo_description' => 320];
        $safe = [];
        foreach ($limits as $field => $limit) {
            if (! isset($corrections[$field]) || ! is_string($corrections[$field])) continue;
            $value = trim(mb_substr($corrections[$field], 0, $limit));
            if ($value !== '') $safe[$field] = $value;
        }
        return $safe;
    }

    private function markNeedsCorrection(StoreProductPublicationReview $review, string $code, array $findings, array $result = []): void
    {
        $review->update([
            'status' => 'needs_correction',
            'verdict' => $result['verdict'] ?? 'blocked',
            'score' => isset($result['score']) ? (int) $result['score'] : null,
            'review_id' => $result['review_id'] ?? null,
            'model' => $result['model'] ?? null,
            'summary' => (string) ($result['summary'] ?? 'برای ادامه انتشار، اصلاح یا تکمیل اطلاعات لازم است.'),
            'findings_json' => $findings,
            'corrections_json' => [],
            'reviewed_at' => now(),
            'failure_code' => $code,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        StoreProductPublicationReview::query()->whereKey($this->reviewId)->update([
            'status' => 'failed',
            'failure_code' => 'publication_review_job_failed',
            'updated_at' => now(),
        ]);
    }
}
