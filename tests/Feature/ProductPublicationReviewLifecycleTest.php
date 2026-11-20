<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductPublicationReviewLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Publication QA',
            'mobile' => '09120000071',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function product(): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته انتشار',
            'slug' => 'publication-review-category',
            'is_active' => true,
        ]);

        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول در انتظار انتشار',
            'slug' => 'publication-review-product',
            'sku' => 'PUB-REVIEW-001',
            'type' => 'digital_file',
            'short_description' => 'معرفی محصول',
            'description' => 'شرح محصول برای بررسی ناظر',
            'price_rials' => 125000,
            'status' => 'draft',
            'published_at' => null,
            'metadata' => [
                'ai_supervisor' => [
                    'enabled' => true,
                    'mode' => 'review_gate',
                    'checks' => ['content' => true, 'pricing' => true],
                    'instructions' => 'بررسی دقیق محتوای محصول',
                ],
            ],
        ]);

        StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'products/publication-review.pdf',
            'original_name' => 'publication-review.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'publication-review-file'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);

        return $product;
    }

    private function configureSupervisor(StoreProduct $product, string $verdict = 'approved'): void
    {
        config([
            'services.product_supervisor.url' => 'https://supervisor.example.test',
            'services.product_supervisor.token' => 'test-supervisor-token',
        ]);

        Http::fake([
            'https://supervisor.example.test/v1/reviews/products' => Http::response([
                'review_id' => 'review-'.$product->id,
                'product_id' => (string) $product->id,
                'verdict' => $verdict,
                'score' => 96,
                'findings' => [],
                'model' => 'test-model',
            ], 200),
        ]);
    }

    private function review(StoreProduct $product): void
    {
        $this->from(route('admin.store.edit', $product))
            ->post(route('admin.store.ai-supervisor.review', $product))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHas('status');
    }

    private function publicationPayload(StoreProduct $product, int $price): array
    {
        return [
            'category_id' => $product->category_id,
            'title' => $product->title,
            'sku' => $product->sku,
            'type' => $product->type,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'price_rials' => $price,
            'status' => 'published',
        ];
    }

    public function test_approved_supervisor_review_can_authorize_publication_of_the_unchanged_snapshot(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->configureSupervisor($product);

        $this->actingAs($admin);
        $this->review($product);

        $review = data_get($product->fresh()->metadata, 'ai_supervisor.last_review');
        $this->assertSame('approved', data_get($review, 'verdict'));
        $this->assertNotEmpty(data_get($review, 'snapshot_hash'));

        $this->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->publicationPayload($product, 125000))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHas('status');

        $this->assertSame('published', $product->fresh()->status);
        $this->assertNotNull($product->fresh()->published_at);
    }

    public function test_changing_a_reviewed_price_invalidates_the_approval_before_publication(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->configureSupervisor($product);

        $this->actingAs($admin);
        $this->review($product);

        $this->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->publicationPayload($product, 150000))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
        $this->assertSame(125000, $product->fresh()->price_rials);
    }

    public function test_non_approved_supervisor_verdict_cannot_authorize_publication(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->configureSupervisor($product, 'needs_changes');

        $this->actingAs($admin);
        $this->review($product);

        $this->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->publicationPayload($product, 125000))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
    }
}
