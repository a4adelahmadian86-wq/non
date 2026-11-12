<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductIngestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCourseResourceIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Course Resource Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function courseLesson(string $suffix = 'one'): array
    {
        $category = StoreCategory::create([
            'name' => 'دسته دوره',
            'slug' => 'course-resource-'.$suffix,
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره منابع',
            'slug' => 'course-resources-'.$suffix,
            'sku' => 'COURSE-RES-'.$suffix,
            'type' => 'course',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);
        $course = StoreCourse::create(['product_id' => $product->id, 'level' => 'beginner', 'language' => 'fa']);
        $section = StoreCourseSection::create(['course_id' => $course->id, 'title' => 'فصل اول', 'sort_order' => 1]);
        $lesson = StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'جلسه اول',
            'slug' => 'course-resource-lesson-'.$suffix,
            'lesson_type' => 'article',
            'content' => 'متن درس',
            'is_published' => false,
            'sort_order' => 1,
        ]);

        return [$product, $lesson];
    }

    public function test_course_resource_is_quarantined_until_explicit_confirmation_then_queued(): void
    {
        Storage::fake('private');
        Queue::fake();
        [$product, $lesson] = $this->courseLesson();
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.store.course.resources.preflight', [$product, $lesson]),
            ['file' => UploadedFile::fake()->createWithContent('handout.txt', 'A safe course handout with learning notes.')],
            ['Accept' => 'application/json']
        )->assertCreated()->assertJsonPath('success', true);

        $ingestion = StoreProductIngestion::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame($lesson->id, (int) $ingestion->course_lesson_id);
        $this->assertSame('inspected', $ingestion->status);
        $this->assertTrue(Storage::disk('private')->exists($ingestion->staging_path));
        $stagingPath = $ingestion->staging_path;
        $this->assertSame(0, StoreProductFile::query()->where('product_id', $product->id)->count());
        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->postJson(route('admin.store.course.resources.confirm', [$product, $lesson, $ingestion->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('processing_status', 'pending');

        $this->assertSame('uploaded', $ingestion->fresh()->status);
        $this->assertFalse(Storage::disk('private')->exists($stagingPath));
        $file = StoreProductFile::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('lesson_resource', $file->purpose);
        $this->assertSame($lesson->id, (int) $file->course_lesson_id);
        $this->assertSame('clean', $file->scan_status);
        Queue::assertPushed(ProcessProductDocument::class);
    }

    public function test_course_resource_confirmation_rejects_changed_quarantine_bytes(): void
    {
        Storage::fake('private');
        Queue::fake();
        [$product, $lesson] = $this->courseLesson('tamper');
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.store.course.resources.preflight', [$product, $lesson]),
            ['file' => UploadedFile::fake()->createWithContent('handout.txt', 'original course handout')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $ingestion = StoreProductIngestion::query()->firstOrFail();
        Storage::disk('private')->put($ingestion->staging_path, 'tampered course handout');

        $this->actingAs($admin)->postJson(
            route('admin.store.course.resources.confirm', [$product, $lesson, $ingestion->id])
        )->assertStatus(409)->assertJsonPath('code', 'staged_source_changed');

        $this->assertSame(0, StoreProductFile::query()->where('product_id', $product->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_course_resource_quarantine_can_be_cancelled_and_removed(): void
    {
        Storage::fake('private');
        [$product, $lesson] = $this->courseLesson('cancel');
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.store.course.resources.preflight', [$product, $lesson]),
            ['file' => UploadedFile::fake()->createWithContent('handout.txt', 'temporary resource')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $ingestion = StoreProductIngestion::query()->firstOrFail();
        $this->assertTrue(Storage::disk('private')->exists($ingestion->staging_path));

        $this->actingAs($admin)->deleteJson(
            route('admin.store.course.resources.cancel', [$product, $lesson, $ingestion->id])
        )->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('store_product_ingestions', ['id' => $ingestion->id]);
        $this->assertFalse(Storage::disk('private')->exists($ingestion->staging_path));
    }

    public function test_course_resource_preflight_rejects_unsupported_extensions(): void
    {
        Storage::fake('private');
        [$product, $lesson] = $this->courseLesson('reject');
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.store.course.resources.preflight', [$product, $lesson]),
            ['file' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;')],
            ['Accept' => 'application/json']
        )->assertStatus(422);

        $this->assertSame(0, StoreProductIngestion::query()->count());
        $this->assertSame(0, StoreProductFile::query()->count());
    }

    public function test_course_resource_ingestion_requires_admin_authentication(): void
    {
        [$product, $lesson] = $this->courseLesson('auth');

        $this->post(route('admin.store.course.resources.preflight', [$product, $lesson]))
            ->assertRedirect(route('login'));
    }
}
