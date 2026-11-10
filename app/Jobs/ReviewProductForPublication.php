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
        if (($workflow['status'] ?? null) !== 'queued') {
            return;
        }

        $this->storeWorkflow($product, [
            'status' => 'reviewing',
            'last_attempt_at' => now()->toISOString(),
            'attempts' => ((int) ($workflow['attempts'] ?? 0)) + 1,
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

        $corrections = [];
        $correctionApplied = false;
        $finalReview = $review;

        if (($review['verdict'] ?? null) === 'needs_changes') {
            $edits = $this->safeEdits((array) ($review['suggested_edits'] ?? []));
            if ($edits !== []) {
                $applied = DB::transaction(function () use ($product, $edits, $settings, $snapshotHash, $snapshotBuilder, $knowledge, &$corrections) {
                    $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                    $lockedSettings = (array) data_get($locked->metadata, 'ai_supervisor', []);
                    $lockedSettings['enabled'] = true;
                    $lockedSettings['mode'] = 'review_gate';
                    $lockedSettings['checks'] = $settings['checks'];
                    $freshSnapshot = $snapshotBuilder->build($locked, $lockedSettings);
                    $freshSnapshot['document_knowledge'] = $knowledge->contextForProduct($locked, 12000);
                    if (! hash_equals($snapshotHash, $snapshotBuilder->fingerprint($freshSnapshot))) {
                        return 'stale';
                    }

                    foreach ($edits as $field => $value) {
                        $old = (string) ($locked->{$field} ?? '');
                        if ($old === $value) {
                            continue;
                        }
                        $corrections[$field] = [
                            'before' => mb_substr($old, 0, 1200),
                            'after' => mb_substr($value, 0, 1200),
                        ];
                        $locked->{$field} = $value;
                    }

                    if ($corrections !== []) {
                        $locked->save();
                    }

                    return $corrections !== [] ? 'applied' : 'unchanged';
                }, 3);

                if ($applied === 'stale') {
                    $this->storeWorkflow($product, [
                        'status' => 'stale',
                        'last_error_code' => 'product_changed_during_supervisor_review',
                        'review_id' => $review['review_id'] ?? null,
                    ]);
                    return;
                }

                if ($applied === 'applied') {
                    $correctionApplied = true;
                    $product = StoreProduct::query()->findOrFail($this->productId);
                    $correctedSettings = (array) data_get($product->metadata, 'ai_supervisor', []);
                    $correctedSettings['enabled'] = true;
                    $correctedSettings['mode'] = 'review_gate';
                    $correctedSettings['checks'] = $settings['checks'];
                    $correctedSnapshot = $snapshotBuilder->build($product, $correctedSettings);
                    $correctedSnapshot['document_knowledge'] = $knowledge->contextForProduct($product, 12000);
                    $finalReview = $supervisor->review($correctedSnapshot);
                    $finalReview['snapshot_hash'] = $snapshotBuilder->fingerprint($correctedSnapshot);
                    $finalReview['correction_round'] = 1;
                    $finalReview['automated_publication'] = false;
                    $finalReview['publication_authorized'] = false;
                }
            }
        }

        $product = StoreProduct::query()->findOrFail($this->productId);
        $blockers = $readiness->publicationBlockers($product);
        $verdict = (string) ($finalReview['verdict'] ?? 'blocked');
        $submitterId = (int) data_get($product->metadata, 'publication_workflow.submitted_by', 0);
        $submitter = $submitterId > 0 ? User::query()->find($submitterId) : null;

        if ($blockers !== []) {
            $status = 'blocked';
        } elseif ($correctionApplied) {
            // Any AI-authored correction requires a human approval, even when the submitter is privileged.
            $status = 'awaiting_manual_approval';
        } elseif ($verdict === 'approved') {
            $status = $submitter && $authorization->allows($submitter, 'store.publish_products')
                ? 'published'
                : 'awaiting_admin_approval';
        } elseif ($verdict === 'needs_changes') {
            $status = 'needs_manual_changes';
        } else {
            $status = 'blocked';
        }

        DB::transaction(function () use ($product, $finalReview, $corrections, $correctionApplied, $status, $verdict, $blockers, $submitterId) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($locked->metadata ?? []);
            $supervisorMeta = (array) data_get($metadata, 'ai_supervisor', []);
            $supervisorMeta['last_review'] = $finalReview;
            $supervisorMeta['last_reviewed_at'] = now()->toISOString();
            $metadata['ai_supervisor'] = $supervisorMeta;

            $workflow = (array) data_get($metadata, 'publication_workflow', []);
            $workflow['status'] = $status;
            $workflow['ai_verdict'] = $verdict;
            $workflow['review_id'] = (string) ($finalReview['review_id'] ?? '');
            $workflow['review_score'] = (int) ($finalReview['score'] ?? 0);
            $workflow['review_summary'] = mb_substr((string) ($finalReview['summary'] ?? ''), 0, 700);
            $workflow['review_findings'] = array_slice((array) ($finalReview['findings'] ?? []), 0, 12);
            $workflow['reviewed_at'] = now()->toISOString();
            $workflow['correction_applied'] = $correctionApplied;
            $workflow['corrections'] = $corrections;
            $workflow['publication_blockers'] = $blockers;
            $workflow['last_error_code'] = null;

            if ($status === 'published') {
                $locked->status = 'published';
                $locked->published_at = now();
                $workflow['published_by'] = $submitterId;
                $workflow['published_at'] = now()->toISOString();
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
                    'correction_applied' => $correctionApplied,
                    'correction_fields' => array_keys($corrections),
                ],
                'system'
            );
        });
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
        ]);
    }

    private function safeEdits(array $edits): array
    {
        $limits = [
            'title' => 220,
            'short_description' => 2000,
            'description' => 8000,
            'seo_title' => 220,
            'seo_description' => 320,
        ];
        $safe = [];
        foreach ($limits as $field => $limit) {
            if (! isset($edits[$field]) || ! is_string($edits[$field])) {
                continue;
            }
            $value = trim(strip_tags($edits[$field]));
            $value = mb_substr($value, 0, $limit);
            if (mb_strlen($value) >= 2) {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }

    private function storeWorkflow(StoreProduct $product, array $updates): void
    {
        DB::transaction(function () use ($product, $updates) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($locked->metadata ?? []);
            $workflow = (array) data_get($metadata, 'publication_workflow', []);
            $metadata['publication_workflow'] = array_replace($workflow, $updates);
            $locked->update(['metadata' => $metadata]);
        });
    }
}
