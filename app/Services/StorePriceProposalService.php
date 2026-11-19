<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use App\Models\StorePriceProposal;
use App\Models\StoreProduct;
use App\Models\StoreProductIngestion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StorePriceProposalService
{
    public function __construct(
        private readonly StoreCouponMarginPolicy $marginPolicy,
        private readonly AuditEventService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    public function preview(StoreProduct $product): array
    {
        $currentPrice = max(0, (int) $product->price_rials);
        $costPrice = $product->cost_price_rials === null ? null : max(0, (int) $product->cost_price_rials);
        $uploaderId = $this->uploaderId($product);
        $minimumSafeBase = $this->marginPolicy->minimumSafeSellingPrice($product);
        $basePrice = max($currentPrice, $minimumSafeBase ?? $currentPrice);
        $minimumMargin = $this->marginPolicy->minimumMargin($product, $basePrice);
        $maximumDiscount = $costPrice === null ? 0 : $this->marginPolicy->maximumDiscount($product, $basePrice, 1);
        $campaignPrice = $maximumDiscount > 0 ? max(0, $basePrice - $maximumDiscount) : null;

        return [
            'uploader_id' => $uploaderId,
            'current_price_rials' => $currentPrice,
            'minimum_safe_base_price_rials' => $minimumSafeBase,
            'base_price_rials' => $basePrice,
            'campaign_price_rials' => $campaignPrice !== null && $campaignPrice < $basePrice ? $campaignPrice : null,
            'cost_price_rials' => $costPrice,
            'minimum_margin_rials' => $minimumMargin,
            'maximum_discount_rials' => $maximumDiscount,
            'can_propose' => $basePrice > 0 && $uploaderId !== null,
        ];
    }

    public function propose(StoreProduct $product, User $actor): StorePriceProposal
    {
        $proposal = DB::transaction(function () use ($product, $actor) {
            $lockedProduct = StoreProduct::query()->lockForUpdate()->findOrFail($product->id);
            $preview = $this->preview($lockedProduct);
            $uploaderId = $preview['uploader_id'];

            if (! $uploaderId) {
                throw new RuntimeException('store_product_uploader_not_identified');
            }
            if ($preview['base_price_rials'] <= 0) {
                throw new RuntimeException('store_product_requires_positive_base_price');
            }

            $snapshot = $this->snapshot($lockedProduct, $uploaderId);
            $sourceHash = $this->hashSnapshot($snapshot);
            $pending = StorePriceProposal::query()
                ->where('product_id', $lockedProduct->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($pending && hash_equals($pending->source_hash, $sourceHash)) {
                return $pending;
            }

            if ($pending) {
                $pending->status = 'superseded';
                $pending->decided_by = $actor->id;
                $pending->decided_at = now();
                $pending->decision_note = 'superseded_by_new_price_snapshot';
                $pending->save();
            }

            $proposal = StorePriceProposal::create([
                'product_id' => $lockedProduct->id,
                'uploader_id' => $uploaderId,
                'proposed_by' => $actor->id,
                'base_price_rials' => $preview['base_price_rials'],
                'campaign_price_rials' => $preview['campaign_price_rials'],
                'cost_price_rials' => $preview['cost_price_rials'],
                'minimum_margin_rials' => $preview['minimum_margin_rials'],
                'source_hash' => $sourceHash,
                'snapshot_before' => $snapshot,
                'status' => 'pending',
            ]);

            $this->audit->record(
                'store.product.price_proposal_created',
                $actor->id,
                null,
                'store_product',
                $lockedProduct->id,
                [
                    'proposal_id' => $proposal->id,
                    'uploader_id' => $uploaderId,
                    'base_price_rials' => $preview['base_price_rials'],
                    'campaign_price_rials' => $preview['campaign_price_rials'],
                    'minimum_margin_rials' => $preview['minimum_margin_rials'],
                    'source_hash' => $sourceHash,
                ],
                'application',
            );

            return $proposal;
        });

        $proposal->loadMissing('product');
        $this->queueUploaderNotification((int) $proposal->uploader_id, $proposal, $proposal->product);

        return $proposal;
    }

    public function decide(StorePriceProposal $proposal, User $actor, string $decision, ?string $note = null): bool
    {
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw new RuntimeException('store_price_proposal_decision_invalid');
        }

        return DB::transaction(function () use ($proposal, $actor, $decision, $note) {
            $lockedProposal = StorePriceProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ((int) $lockedProposal->uploader_id !== (int) $actor->id) {
                throw new AuthorizationException('store_price_proposal_not_owned_by_uploader');
            }
            if ($lockedProposal->status !== 'pending') {
                throw new RuntimeException('store_price_proposal_already_decided');
            }

            $product = StoreProduct::query()->lockForUpdate()->findOrFail($lockedProposal->product_id);
            $currentSnapshot = $this->snapshot($product, (int) $lockedProposal->uploader_id);
            if (! hash_equals($lockedProposal->source_hash, $this->hashSnapshot($currentSnapshot))) {
                $lockedProposal->status = 'stale';
                $lockedProposal->decided_by = $actor->id;
                $lockedProposal->decided_at = now();
                $lockedProposal->decision_note = 'pricing_snapshot_changed';
                $lockedProposal->snapshot_after = $currentSnapshot;
                $lockedProposal->save();

                $this->audit->record(
                    'store.product.price_proposal_stale',
                    $actor->id,
                    null,
                    'store_product',
                    $product->id,
                    ['proposal_id' => $lockedProposal->id, 'source_hash' => $lockedProposal->source_hash],
                    'application',
                );

                return false;
            }

            $preview = $this->preview($product);
            if (
                (int) $lockedProposal->base_price_rials !== (int) $preview['base_price_rials']
                || $lockedProposal->campaign_price_rials !== $preview['campaign_price_rials']
                || (int) $lockedProposal->minimum_margin_rials !== (int) $preview['minimum_margin_rials']
            ) {
                $lockedProposal->status = 'stale';
                $lockedProposal->decided_by = $actor->id;
                $lockedProposal->decided_at = now();
                $lockedProposal->decision_note = 'suggested_price_or_margin_floor_changed';
                $lockedProposal->snapshot_after = $currentSnapshot;
                $lockedProposal->save();

                $this->audit->record(
                    'store.product.price_proposal_stale',
                    $actor->id,
                    null,
                    'store_product',
                    $product->id,
                    ['proposal_id' => $lockedProposal->id, 'reason' => 'suggested_price_changed'],
                    'application',
                );

                return false;
            }

            if ($decision === 'accept') {
                $currentPrice = (int) $product->price_rials;
                $basePrice = (int) $lockedProposal->base_price_rials;
                $campaignPrice = $lockedProposal->campaign_price_rials === null
                    ? null
                    : (int) $lockedProposal->campaign_price_rials;
                $priceChanged = false;

                if ($campaignPrice !== null) {
                    // The compare-at price is the real base price accepted here.
                    $product->compare_at_price_rials = $basePrice;
                    $product->price_rials = $campaignPrice;
                    $priceChanged = $campaignPrice !== $currentPrice || $basePrice !== $currentPrice;
                } elseif ($basePrice > $currentPrice) {
                    $product->price_rials = $basePrice;
                    $product->compare_at_price_rials = null;
                    $priceChanged = true;
                }

                if ($priceChanged) {
                    $this->invalidateSupervisorReview($product);
                    $product->save();
                }
            }

            $lockedProposal->status = $decision === 'accept' ? 'accepted' : 'rejected';
            $lockedProposal->decided_by = $actor->id;
            $lockedProposal->decided_at = now();
            $lockedProposal->decision_note = $note === null ? null : mb_substr(trim($note), 0, 500);
            $afterProduct = $product->fresh();
            $lockedProposal->snapshot_after = [
                'price_rials' => (int) $afterProduct->price_rials,
                'compare_at_price_rials' => $afterProduct->compare_at_price_rials,
                'cost_price_rials' => $afterProduct->cost_price_rials,
                'marketing_min_margin_rials' => (int) $afterProduct->marketing_min_margin_rials,
            ];
            $lockedProposal->save();

            $this->audit->record(
                $decision === 'accept' ? 'store.product.price_proposal_accepted' : 'store.product.price_proposal_rejected',
                $actor->id,
                null,
                'store_product',
                $product->id,
                [
                    'proposal_id' => $lockedProposal->id,
                    'base_price_rials' => $lockedProposal->base_price_rials,
                    'campaign_price_rials' => $lockedProposal->campaign_price_rials,
                    'resulting_price_rials' => $lockedProposal->snapshot_after['price_rials'],
                ],
                'application',
            );

            return true;
        });
    }

    public function forUploader(int $uploaderId, int $perPage = 20): LengthAwarePaginator
    {
        return StorePriceProposal::query()
            ->with(['product:id,title,slug,type,status,price_rials,compare_at_price_rials'])
            ->where('uploader_id', $uploaderId)
            ->latest('id')
            ->paginate(max(1, min($perPage, 50)));
    }

    public function uploaderId(StoreProduct $product): ?int
    {
        if ($product->type === 'course') {
            $courseUploader = FarastAuditEvent::query()
                ->where('aggregate_type', 'store_product')
                ->where('aggregate_id', $product->id)
                ->where('event_type', 'store.course.resource_uploaded')
                ->whereNotNull('user_id')
                ->latest('id')
                ->value('user_id');
            if ($courseUploader) {
                return (int) $courseUploader;
            }
        } else {
            $ingestionUploader = StoreProductIngestion::query()
                ->where('product_id', $product->id)
                ->where('status', 'confirmed')
                ->whereNotNull('confirmed_at')
                ->whereNotNull('user_id')
                ->latest('confirmed_at')
                ->value('user_id');
            if ($ingestionUploader) {
                return (int) $ingestionUploader;
            }

            $fileUploader = FarastAuditEvent::query()
                ->where('aggregate_type', 'store_product')
                ->where('aggregate_id', $product->id)
                ->where('event_type', 'store.product.file_uploaded')
                ->whereNotNull('user_id')
                ->latest('id')
                ->value('user_id');
            if ($fileUploader) {
                return (int) $fileUploader;
            }
        }

        if ($product->seller_id) {
            return (int) $product->seller_id;
        }

        $creator = FarastAuditEvent::query()
            ->where('aggregate_type', 'store_product')
            ->where('aggregate_id', $product->id)
            ->where('event_type', 'store.product.created')
            ->whereNotNull('user_id')
            ->oldest('id')
            ->value('user_id');

        return $creator ? (int) $creator : null;
    }

    private function snapshot(StoreProduct $product, int $uploaderId): array
    {
        $currentPrice = max(0, (int) $product->price_rials);
        $minimumSafeBase = $this->marginPolicy->minimumSafeSellingPrice($product);
        $suggestedBase = max($currentPrice, $minimumSafeBase ?? $currentPrice);

        return [
            'price_rials' => $currentPrice,
            'suggested_base_price_rials' => $suggestedBase,
            'minimum_safe_base_price_rials' => $minimumSafeBase,
            'compare_at_price_rials' => $product->compare_at_price_rials === null ? null : (int) $product->compare_at_price_rials,
            'cost_price_rials' => $product->cost_price_rials === null ? null : (int) $product->cost_price_rials,
            'marketing_min_margin_rials' => max(0, (int) $product->marketing_min_margin_rials),
            'minimum_margin_rials' => $this->marginPolicy->minimumMargin($product, $suggestedBase),
            'uploader_id' => $uploaderId,
        ];
    }

    private function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function invalidateSupervisorReview(StoreProduct $product): void
    {
        $metadata = (array) ($product->metadata ?? []);
        $supervisor = (array) ($metadata['ai_supervisor'] ?? []);
        if (! $supervisor) {
            return;
        }

        unset($supervisor['last_review'], $supervisor['last_reviewed_at'], $supervisor['asset_reviews']);
        $metadata['ai_supervisor'] = $supervisor;
        $product->metadata = $metadata;

        if (($supervisor['enabled'] ?? false) && ($supervisor['mode'] ?? 'suggest') === 'review_gate') {
            $product->status = 'draft';
            $product->published_at = null;
        }
    }
    private function queueUploaderNotification(int $uploaderId, StorePriceProposal $proposal, StoreProduct $product): void
    {
        $campaign = $proposal->campaign_price_rials === null
            ? 'برای این محصول به دلیل نبود حاشیه سود امن، قیمت کمپین پیشنهاد نشده است.'
            : 'قیمت کمپین پیشنهادی با رعایت حداقل حاشیه سود آماده بررسی است.';

        $this->notifications->push(
            $uploaderId,
            'store_price_proposal_created',
            'پیشنهاد قیمت محصول برای بررسی',
            $product->title.' — '.$campaign,
            [
                'url' => route('store.price-proposals.index'),
                'proposal_id' => $proposal->id,
                'product_id' => $product->id,
            ],
            false,
            'store-price-proposal:'.$proposal->id,
        );
    }

}
