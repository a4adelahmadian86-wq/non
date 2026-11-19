<?php

namespace Tests\\Feature;

use App\\Models\\FarastAuditEvent;
use App\\Models\\FarastNotification;
use App\\Models\\StoreCategory;
use App\\Models\\StorePriceProposal;
use App\\Models\\StoreProduct;
use App\\Models\\StoreProductIngestion;
use App\\Models\\User;
use App\\Services\\AuditEventService;
use App\\Services\\StorePriceProposalService;
use Illuminate\\Auth\\Access\\AuthorizationException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Str;
use RuntimeException;
use Tests\\TestCase;

class StorePriceProposalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function user(string $name): User
    {
        $mobile = '09152222'.str_pad((string) ++$this->sequence, 3, '0', STR_PAD_LEFT);

        return User::create([
            'name' => $name,
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
    }

    private function product(?int $cost = 50000, int $margin = 0, string $type = 'digital_file'): StoreProduct
    {
        $slug = 'price-proposal-'.$this->sequence.'-'.Str::lower(Str::random(6));
        $category = StoreCategory::create([
            'name' => 'دسته پیشنهاد قیمت '.$slug,
            'slug' => $slug,
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی پیشنهاد قیمت',
            'slug' => $slug,
            'sku' => strtoupper('PP-'.$this->sequence.'-'.Str::random(6)),
            'type' => $type,
            'price_rials' => 100000,
            'cost_price_rials' => $cost,
            'marketing_min_margin_rials' => $margin,
            'status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);
    }

    private function confirmUploader(StoreProduct $product, User $uploader): void
    {
        StoreProductIngestion::create([
            'id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'user_id' => $uploader->id,
            'status' => 'confirmed',
            'staging_disk' => 'private',
            'staging_path' => 'store-ingestion/'.$product->id.'/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', 'source-'.$product->id),
            'inspection_json' => ['pages' => 4],
            'is_primary' => true,
            'expires_at' => now()->addDay(),
            'confirmed_at' => now(),
        ]);
    }

    public function test_campaign_floor_is_proposed_from_real_cost_and_margin_and_not_applied_before_acceptance(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product();
        $this->confirmUploader($product, $uploader);

        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->assertSame(100000, (int) $proposal->base_price_rials);
        $this->assertSame(55000, (int) $proposal->campaign_price_rials);
        $this->assertSame(5000, (int) $proposal->minimum_margin_rials);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame(100000, (int) $product->fresh()->price_rials);
        $this->assertNull($product->fresh()->compare_at_price_rials);

        $notification = FarastNotification::where('user_id', $uploader->id)
            ->where('type', 'store_price_proposal_created')
            ->first();
        $this->assertNotNull($notification);
        $this->assertSame(route('store.price-proposals.index'), $notification->url());
        $this->assertDatabaseHas('farast_audit_events', [
            'event_type' => 'store.product.price_proposal_created',
            'aggregate_type' => 'store_product',
            'aggregate_id' => $product->id,
        ]);
    }

    public function test_uploader_acceptance_applies_campaign_price_and_invalidates_review_gate(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product();
        $product->update([
            'metadata' => [
                'ai_supervisor' => [
                    'enabled' => true,
                    'mode' => 'review_gate',
                    'last_review' => ['status' => 'approved'],
                    'last_reviewed_at' => now()->toIso8601String(),
                    'asset_reviews' => ['cover' => ['status' => 'approved']],
                ],
            ],
        ]);
        $this->confirmUploader($product, $uploader);
        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->assertTrue(app(StorePriceProposalService::class)->decide($proposal, $uploader, 'accept'));

        $updated = $product->fresh();
        $this->assertSame(55000, (int) $updated->price_rials);
        $this->assertSame(100000, (int) $updated->compare_at_price_rials);
        $this->assertSame('draft', $updated->status);
        $this->assertNull($updated->published_at);
        $this->assertArrayNotHasKey('last_review', $updated->metadata['ai_supervisor']);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertDatabaseHas('farast_audit_events', [
            'event_type' => 'store.product.price_proposal_accepted',
            'aggregate_type' => 'store_product',
            'aggregate_id' => $product->id,
        ]);
    }

    public function test_uploader_rejection_does_not_change_any_product_price(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product();
        $this->confirmUploader($product, $uploader);
        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->assertTrue(app(StorePriceProposalService::class)->decide($proposal, $uploader, 'reject', 'فعلاً منتشر نشود'));

        $this->assertSame(100000, (int) $product->fresh()->price_rials);
        $this->assertNull($product->fresh()->compare_at_price_rials);
        $this->assertSame('rejected', $proposal->fresh()->status);
    }

    public function test_changed_price_snapshot_expires_proposal_without_overwriting_new_price(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product();
        $this->confirmUploader($product, $uploader);
        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $product->update(['price_rials' => 120000]);

        $this->assertFalse(app(StorePriceProposalService::class)->decide($proposal, $uploader, 'accept'));
        $this->assertSame(120000, (int) $product->fresh()->price_rials);
        $this->assertSame('stale', $proposal->fresh()->status);
    }

    public function test_course_uploader_is_resolved_from_resource_upload_audit_event(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Course Uploader');
        $product = $this->product(50000, 0, 'course');

        app(AuditEventService::class)->record(
            'store.course.resource_uploaded',
            $uploader->id,
            null,
            'store_product',
            $product->id,
            ['lesson_id' => 9],
        );

        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->assertSame($uploader->id, (int) $proposal->uploader_id);
    }

    public function test_campaign_price_is_not_suggested_without_cost_or_margin_room(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product(null);
        $this->confirmUploader($product, $uploader);

        $preview = app(StorePriceProposalService::class)->preview($product);

        $this->assertNull($preview['campaign_price_rials']);
        $this->assertSame(0, $preview['maximum_discount_rials']);
    }

    public function test_only_the_resolved_uploader_can_decide_a_proposal(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $other = $this->user('Other User');
        $product = $this->product();
        $this->confirmUploader($product, $uploader);
        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->expectException(AuthorizationException::class);
        app(StorePriceProposalService::class)->decide($proposal, $other, 'accept');
    }

    public function test_product_price_proposal_snapshot_cannot_be_rewritten_after_creation(): void
    {
        $pricingTeam = $this->user('Pricing Team');
        $uploader = $this->user('Uploader');
        $product = $this->product();
        $this->confirmUploader($product, $uploader);
        $proposal = app(StorePriceProposalService::class)->propose($product, $pricingTeam);

        $this->expectException(\\LogicException::class);
        $proposal->update(['base_price_rials' => 1]);
    }
}
