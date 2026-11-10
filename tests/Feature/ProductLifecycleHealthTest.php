<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreProduct;
use App\Models\StoreProductIngestion;
use App\Models\User;
use App\Services\ProductLifecycleHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductLifecycleHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_detects_published_products_without_required_payloads_and_expired_preflights(): void
    {
        $category = StoreCategory::create([
            'name' => 'دسته چرخه عمر',
            'slug' => 'lifecycle-category',
            'is_active' => true,
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'فایل منتشرشده بدون فایل اصلی',
            'slug' => 'published-file-without-payload',
            'sku' => 'LIFE-FILE-01',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'download_policy' => 'signed',
        ]);

        $course = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره منتشرشده بدون جلسه',
            'slug' => 'published-course-without-lessons',
            'sku' => 'LIFE-COURSE-01',
            'type' => 'course',
            'price_rials' => 200000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        StoreCourse::create(['product_id' => $course->id]);

        StoreProductIngestion::create([
            'id' => (string) Str::uuid(),
            'product_id' => $course->id,
            'user_id' => null,
            'status' => 'inspected',
            'staging_disk' => 'private',
            'staging_path' => 'staging/expired-source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'source'),
            'inspection_json' => ['safe' => true],
            'is_primary' => false,
            'expires_at' => now()->subMinute(),
        ]);

        $snapshot = app(ProductLifecycleHealthService::class)->snapshot();

        $this->assertSame(1, $snapshot['published_missing_safe_primary_file']);
        $this->assertSame(1, $snapshot['published_courses_without_publishable_lessons']);
        $this->assertSame(1, $snapshot['expired_inspections']);
        $this->assertSame(2, $snapshot['product_counts']['digital_file:published'] + $snapshot['product_counts']['course:published']);
        $this->assertSame(1, $snapshot['ingestion_stats']['inspected']);
    }

    public function test_operations_center_exposes_cross_layer_lifecycle_snapshot_to_admins(): void
    {
        $admin = User::create([
            'name' => 'Lifecycle Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.store.operations'))
            ->assertOk()
            ->assertViewHas('lifecycleHealth')
            ->assertSee('یکپارچگی چرخه عمر محصول');
    }

    public function test_reader_only_health_accepts_only_a_hash_matched_ready_primary_document_with_pages(): void
    {
        $category = StoreCategory::create([
            'name' => 'خوانشگر',
            'slug' => 'reader-health-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول خوانشگر آماده',
            'slug' => 'reader-health-ready',
            'sku' => 'READER-HEALTH-READY',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'download_policy' => 'reader_only',
        ]);
        $hash = hash('sha256', 'reader-source');
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/reader-health/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 13,
            'sha256' => $hash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => 'completed',
            'detected_type' => 'pdf',
            'page_count' => 1,
            'structure_json' => ['reader' => ['ready' => true]],
        ]);
        $document->pages()->create([
            'page_number' => 1,
            'page_kind' => 'pdf_page',
            'locator_key' => 'page:1',
            'locator_json' => ['page' => 1],
            'content_text' => 'متن صفحه',
            'content_html' => '<p>متن صفحه</p>',
            'content_sha256' => hash('sha256', 'متن صفحه'),
            'has_extracted_text' => true,
        ]);

        $snapshot = app(ProductLifecycleHealthService::class)->snapshot();
        $this->assertSame(0, $snapshot['reader_only_without_ready_document']);

        $file->update(['is_active' => false]);
        $snapshot = app(ProductLifecycleHealthService::class)->snapshot();
        $this->assertSame(1, $snapshot['reader_only_without_ready_document']);
    }

}
