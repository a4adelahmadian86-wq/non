<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductImage;
use App\Models\StoreTag;
use App\Models\User;
use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminStoreProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Store Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function category(): StoreCategory
    {
        return StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => 'test-products',
            'is_active' => true,
        ]);
    }

    private function payload(StoreCategory $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'sku' => 'TEST-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 125000,
            'status' => 'draft',
            'short_description' => 'توضیح کوتاه',
        ], $overrides);
    }

    public function test_new_products_are_always_created_as_private_drafts_and_cover_path_is_not_mass_assignable(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'status' => 'published',
                'cover_path' => 'https://attacker.invalid/fake-cover.jpg',
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-PRODUCT-001')->firstOrFail();

        $response->assertRedirect(route('admin.store.edit', $product));
        $this->assertSame('draft', $product->status);
        $this->assertNull($product->published_at);
        $this->assertNull($product->cover_path);
    }

    public function test_creating_a_course_product_also_creates_its_course_catalog_record(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'title' => 'دوره آزمایشی',
                'sku' => 'TEST-COURSE-001',
                'type' => 'course',
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-COURSE-001')->firstOrFail();

        $response->assertRedirect(route('admin.store.course.edit', $product));
        $this->assertDatabaseHas('store_courses', ['product_id' => $product->id]);
        $this->assertSame('draft', $product->status);
    }

    public function test_product_creation_synchronizes_tags_and_related_products(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $tag = StoreTag::create(['name' => 'طراحی', 'slug' => 'design']);
        $related = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول مکمل',
            'slug' => 'related-product',
            'sku' => 'RELATED-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 50000,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'tag_ids' => [$tag->id],
                'related_product_ids' => [$related->id],
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-PRODUCT-001')->firstOrFail();
        $response->assertRedirect(route('admin.store.edit', $product));
        $this->assertDatabaseHas('store_product_tag', [
            'product_id' => $product->id,
            'tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('store_product_related', [
            'product_id' => $product->id,
            'related_product_id' => $related->id,
        ]);
    }

    public function test_admin_can_create_a_tag_from_the_product_editor(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.store.create'))
            ->post(route('admin.store.tags.store'), [
                'name' => 'بازاریابی',
                'slug' => 'marketing',
            ])
            ->assertRedirect(route('admin.store.create'));

        $this->assertDatabaseHas('store_tags', [
            'name' => 'بازاریابی',
            'slug' => 'marketing',
        ]);
    }

    public function test_product_gallery_upload_is_scanned_and_saved_to_public_storage(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول گالری',
            'slug' => 'gallery-product',
            'sku' => 'GALLERY-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $this->mock(UploadSecurityService::class, function ($mock) {
            $mock->shouldReceive('inspect')->once()->andReturnUsing(function ($path) {
                $checksum = hash_file('sha256', Storage::disk('private')->path($path));

                return [
                    'safe' => true,
                    'mime' => 'image/jpeg',
                    'size' => Storage::disk('private')->size($path),
                    'checksum' => $checksum,
                    'scan' => 'test-clean',
                ];
            });
        });

        $this->actingAs($admin)
            ->post(route('admin.store.images.upload', $product), [
                'image' => UploadedFile::fake()->image('gallery.jpg', 800, 600),
                'alt' => 'نمای محصول',
            ])
            ->assertRedirect(route('admin.store.edit', $product));

        $image = StoreProductImage::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('نمای محصول', $image->alt);
        $this->assertTrue($image->is_active);
        $this->assertStringStartsWith('storage/store/product-images/'.$product->id.'/', $image->path);
        Storage::disk('public')->assertExists(str_replace('storage/', '', $image->path));
    }

    public function test_compare_at_price_must_be_greater_than_the_sale_price(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)
            ->from(route('admin.store.create'))
            ->post(route('admin.store.store'), $this->payload($category, [
                'compare_at_price_rials' => 125000,
            ]))
            ->assertRedirect(route('admin.store.create'))
            ->assertSessionHasErrors('compare_at_price_rials');

        $this->assertDatabaseMissing('store_products', ['sku' => 'TEST-PRODUCT-001']);
    }

    public function test_last_primary_file_cannot_be_deleted_while_product_is_published(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول منتشرشده',
            'slug' => 'published-product',
            'sku' => 'PUBLISHED-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $path = 'store/products/'.$product->id.'/primary.pdf';
        Storage::disk('private')->put($path, 'safe-test-content');
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'primary.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 17,
            'sha256' => hash('sha256', 'safe-test-content'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'test',
            'scanned_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->delete(route('admin.store.files.delete', [$product, $file]))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('store_product_files', ['id' => $file->id]);
        Storage::disk('private')->assertExists($path);
    }

    public function test_published_file_product_requires_an_active_clean_primary_file_on_every_update(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول پیش‌نویس',
            'slug' => 'draft-product',
            'sku' => 'DRAFT-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->payload($category, [
                'title' => $product->title,
                'sku' => $product->sku,
                'status' => 'published',
            ]))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
    }
}
