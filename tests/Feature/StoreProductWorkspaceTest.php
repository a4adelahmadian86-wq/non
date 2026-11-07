<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Services\StoreProductWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreProductWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $type, string $slug): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => $slug.'-category',
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'type' => $type,
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }

    public function test_digital_product_readiness_requires_a_clean_active_primary_file(): void
    {
        $product = $this->makeProduct('digital_file', 'workspace-file');
        $service = app(StoreProductWorkspaceService::class);

        $this->assertFalse($service->inspect($product)['can_publish']);

        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'disk' => 'private',
            'path' => 'products/workspace-file.pdf',
            'original_name' => 'workspace-file.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'workspace'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);

        $this->assertTrue($service->inspect($product->fresh())['can_publish']);

        $file->update(['scan_status' => 'pending']);
        $this->assertFalse($service->inspect($product->fresh())['can_publish']);
        $this->assertTrue($service->synchronizePublishedState($product));
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
    }

    public function test_course_readiness_tracks_published_curriculum_and_unpublishes_when_last_lesson_is_removed(): void
    {
        $product = $this->makeProduct('course', 'workspace-course');
        $course = StoreCourse::create([
            'product_id' => $product->id,
            'level' => 'beginner',
            'language' => 'fa',
        ]);
        $section = StoreCourseSection::create([
            'course_id' => $course->id,
            'title' => 'فصل اول',
            'sort_order' => 1,
        ]);
        $lesson = StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'جلسه اول',
            'slug' => 'workspace-course-lesson',
            'lesson_type' => 'article',
            'is_published' => true,
            'sort_order' => 1,
        ]);
        $service = app(StoreProductWorkspaceService::class);

        $this->assertTrue($service->inspect($product->fresh())['can_publish']);

        $lesson->update(['is_published' => false]);
        $this->assertFalse($service->inspect($product->fresh())['can_publish']);
        $this->assertTrue($service->synchronizePublishedState($product));
        $this->assertSame('draft', $product->fresh()->status);
    }
}
