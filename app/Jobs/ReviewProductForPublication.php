<?php

namespace App\Jobs;

use App\Models\StoreProduct;
use App\Models\User;
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
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewProductForPublication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;
    public array $backoff = [45, 120];

    public function __construct(public int $productId)
    {
        $this->onQueue('product-supervisor');
    }

    public function handle(
        ProductSupervisorClient $supervisor,
        ProductSupervisorSnapshotBuilder $snapshotBuilder,
        ProductKnowledgeService $knowledge,
        ProductPublicationReadiness $readiness,
        AuthorizationService $authorization
    ): void {
        $product = StoreProduct::query()->find($this->productId);
        if (! $product) {
            return;
        }

        $workflow = (array) data_get($product->metadata, 'publication_workflow', []);
        if (! in_array($workflow['status'] ?? null, ['queued', 'awaiting_supervisor', 'retry_requested'], true)) {
            return;
        }

        $this->storeWorkflow($product, [
            'status' => 'reviewing',
            'last_attempt_at' => now()->toISOString(),
            'attempts' => ((int) ($workflow['attempts'] ?? 0)) + 1,
            'automated_publication' => false,
        ]);
        $product->refresh();

        if (! $supervisor->configured()) {
            $this->storeWorkflow($product, [
                'status' => 'awaiting_supervisor',
                'last_error_code' => 'supervisor_not_configured',
            ]);
            return;
        }

        $settings = (array) data_get($product->metadata, 'ai_supervisor', []);
        $settings['enabled'] = true;
        $settings['mode'] = 'review_gate';
        $settings['checks'] = array_replace([
            'content' => true,
            'pricing' => true,
            'seo' => true,
            'media' => true,
            'policy' => true,
            'course' => true,
        ], (array) ($settings['checks'] ?? []));

        $snapshot = $snapshotBuilder->build($product, $settings);
        if (($snapshot['checks'] ?? []) === []) {
            $settings['checks'] = array_fill_keys(['content', 'pricing', 'seo', 'media', 'policy', 'course'], true);
            $snapshot = $snapshotBuilder->build($product, $settings);
        }
        $snapshot['document_knowledge'] = $knowledge->contextForProduct($product, 12000);
        $snapshotHash = $snapshotBuilder->fingerprint($snapshot);

        $review = $supervisor->review($snapshot);
        $review['snapshot_hash'] = $snapshotHash;
        $review['automated_publication'] = false;
        $review['publication_authorized'] = false;

        $product = StoreProduct::query()->findOrFail($this->productId);
        $freshSettings = (array) data_get($product->metadata, 'ai_supervisor', []);
        $freshSettings['enabled'] = true;
        $freshSettings['mode'] = 'review_gate';
        $freshSettings['checks'] = $settings['checks'];
        $freshSnapshot = $snapshotBuilder->build($product, $freshSettings);
        $freshSnapshot['document_knowledge'] = $knowledge->contextForProduct($product, 12000);
        if (! hash_equals($snapshotHash, $snapshotBuilder->fingerprint($freshSnapshot))) {
            $this->storeWorkflow($product, [
                'status' => 'stale',
                'last_error_code' => 'product_changed_during_supervisor_review',
                'review_id' => $review['review_id'] ?? null,
            ]);
            return;
        }

        $blockers = $readiness->publicationBlockers($product);
        $verdict = (string) ($review['verdict'] ?? 'blocked');
        $submitterId = (int) data_get($product->metadata, 'publication_workflow.submitted_by', 0);
        $submitter = $submitterId > 0 ? User::query()->find($submitterId) : null;

        if ($blockers !== []) {
            $status = 'blocked';
        } elseif ($verdict === 'approved') {
            $status = $submitter && $authorization->allows($submitter, 'store.publish_products')
                ? 'published'
                : 'awaiting_admin_approval';
        } else {
            // Any rejection or requested change requires an explicit human decision,
            // including when the submitter has publication permission.
            $status = 'awaiting_manual_approval';
        }

        DB::transaction(function () use ($product, $review, $status, $verdict, $blockers, $submitterId) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($locked->metadata ?? []);
            $supervisorMeta = (array) data_get($metadata, 'ai_supervisor', []);
            $supervisorMeta['last_review'] = $review;
            $supervisorMeta['last_reviewed_at'] = now()->toISOString();
            $metadata['ai_supervisor'] = $supervisorMeta;

            $workflow = (array) data_get($metadata, 'publication_workflow', []);
            $workflow['status'] = $status;
            $workflow['ai_verdict'] = $verdict;
            $workflow['review_id'] = (string) ($review['review_id'] ?? '');
            $workflow['review_score'] = (int) ($review['score'] ?? 0);
            $workflow['review_summary'] = mb_substr((string) ($review['summary'] ?? ''), 0, 700);
            $workflow['review_findings'] = array_slice((array) ($review['findings'] ?? []), 0, 12);
            $workflow['reviewed_at'] = now()->toISOString();
            $workflow['publication_blockers'] = $blockers;
            $workflow['publication_authorized'] = false;
            $workflow['automated_publication'] = false;
            $workflow['last_error_code'] = null;

            if ($status === 'published') {
                $locked->status = 'published';
                $locked->published_at = now();
                $workflow['published_by'] = $submitterId;
                $workflow['published_at'] = now()->toISOString();
                $workflow['publication_authorized'] = true;
            } else {
                $locked->status = 'draft';
                $locked->published_at = null;
            }

            $metadata['publication_workflow'] = $workflow;
            $locked->metadata = $metadata;
            $locked->save();

            app(AuditEventService::class)->record(
                'store.product.publication_supervisor_completed',
                null,
                null,
                'store_product',
                $locked->id,
                [
                    'review_id' => $workflow['review_id'],
                    'verdict' => $verdict,
                    'workflow_status' => $status,
                    'publication_authorized' => $workflow['publication_authorized'],
                    'publication_blockers' => $blockers,
                ],
                'system'
            );
        }, 3);
    }

    public function failed(Throwable $exception): void
    {
        $product = StoreProduct::query()->find($this->productId);
        if (! $product) {
            return;
        }

        Log::warning('Product publication supervisor job failed', [
            'product_id' => $this->productId,
            'exception' => class_basename($exception),
        ]);
        $this->storeWorkflow($product, [
            'status' => 'awaiting_supervisor',
            'last_error_code' => 'supervisor_review_failed',
            'last_attempt_at' => now()->toISOString(),
            'automated_publication' => false,
        ]);
    }

    private function storeWorkflow(StoreProduct $product, array $updates): void
    {
        DB::transaction(function () use ($product, $updates) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($locked->metadata ?? []);
            $workflow = (array) data_get($metadata, 'publication_workflow', []);
            $metadata['publication_workflow'] = array_replace($workflow, $updates);
            $locked->update(['metadata' => $metadata]);
        }, 3);
    }
}
