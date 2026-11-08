<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\User;
use App\Services\StoreCourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreCourseLearningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Course QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);
    }

    private function makeCourse(string $slug = 'course-test', int $price = 0): array
    {
        $category = StoreCategory::create([
            'name' => 'دوره آزمایشی',
            'slug' => $slug.'-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره آزمایشی',
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'type' => 'course',
            'price_rials' => $price,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $course = StoreCourse::create([
            'product_id' => $product->id,
            'level' => 'beginner',
            'language' => 'fa',
            'drip_enabled' => false,
        ]);
        $section = StoreCourseSection::create([
            'course_id' => $course->id,
            'title' => 'فصل اول',
            'sort_order' => 1,
        ]);
        $lesson = StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'جلسه اول',
            'slug' => $slug.'-lesson',
            'lesson_type' => 'article',
            'content' => 'محتوای آموزشی آزمایشی',
            'is_published' => true,
            'is_preview' => true,
            'sort_order' => 1,
        ]);

        return [$product, $course, $section, $lesson];
    }

    private function grantCourse(User $user, StoreProduct $product): StoreLibraryItem
    {
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => (int) $product->price_rials,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => (int) $product->price_rials,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now(),
        ]);
        $item = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'product_file_id' => null,
            'granted_at' => now(),
        ]);
        app(StoreCourseEnrollmentService::class)->grant($item, $product);

        return $item;
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Course Admin',
            'mobile' => '09123456788',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_admin_cannot_publish_an_article_lesson_without_body_content(): void
    {
        $admin = $this->makeAdmin();
        [$product, , , $lesson] = $this->makeCourse('empty-article', 10000);
        $product->update(['status' => 'draft', 'published_at' => null]);
        $lesson->update(['content' => null, 'is_published' => false]);

        $this->actingAs($admin)
            ->from(route('admin.store.course.edit', $product))
            ->put(route('admin.store.course.lessons.update', [$product, $lesson]), [
                'title' => $lesson->title,
                'lesson_type' => 'article',
                'summary' => '',
                'content' => '',
                'video_url' => '',
                'duration_seconds' => 0,
                'sort_order' => 1,
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.store.course.edit', $product))
            ->assertSessionHasErrors('is_published');

        $this->assertFalse($lesson->fresh()->is_published);
    }

    public function test_course_cannot_be_published_without_a_valid_published_lesson(): void
    {
        $admin = $this->makeAdmin();
        [$product, , , $lesson] = $this->makeCourse('invalid-course-content', 10000);
        $product->update(['status' => 'draft', 'published_at' => null]);
        $lesson->update(['content' => '   ', 'is_published' => true]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), [
                'category_id' => $product->category_id,
                'title' => $product->title,
                'sku' => $product->sku,
                'type' => 'course',
                'price_rials' => $product->price_rials,
                'status' => 'published',
            ])
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_last_valid_lesson_cannot_be_unpublished_while_course_is_live(): void
    {
        $admin = $this->makeAdmin();
        [$product, , , $lesson] = $this->makeCourse('protected-course-unpublish', 10000);

        $this->actingAs($admin)
            ->from(route('admin.store.course.edit', $product))
            ->put(route('admin.store.course.lessons.update', [$product, $lesson]), [
                'title' => $lesson->title,
                'lesson_type' => 'article',
                'summary' => $lesson->summary,
                'content' => $lesson->content,
                'video_url' => '',
                'duration_seconds' => $lesson->duration_seconds,
                'sort_order' => $lesson->sort_order,
                'is_published' => '0',
            ])
            ->assertRedirect(route('admin.store.course.edit', $product))
            ->assertSessionHasErrors('is_published');

        $this->assertTrue($lesson->fresh()->is_published);
        $this->assertSame('published', $product->fresh()->status);
    }

    public function test_last_valid_lesson_cannot_be_deleted_from_a_published_course(): void
    {
        $admin = $this->makeAdmin();
        [$product, , , $lesson] = $this->makeCourse('protected-course-lesson', 10000);

        $this->actingAs($admin)
            ->from(route('admin.store.course.edit', $product))
            ->delete(route('admin.store.course.lessons.delete', [$product, $lesson]))
            ->assertRedirect(route('admin.store.course.edit', $product))
            ->assertSessionHasErrors('lesson');

        $this->assertDatabaseHas('store_course_lessons', ['id' => $lesson->id, 'is_published' => true]);
        $this->assertSame('published', $product->fresh()->status);
    }

    public function test_enrolled_student_cannot_open_a_published_lesson_without_valid_content(): void
    {
        $user = $this->makeUser();
        [$product, , , $lesson] = $this->makeCourse('empty-published-lesson', 10000);
        $lesson->update(['content' => null, 'is_published' => true]);
        $this->grantCourse($user, $product);

        $this->actingAs($user)
            ->get(route('store.course.lesson', [$product, $lesson]))
            ->assertNotFound();
    }

    public function test_last_clean_file_resource_cannot_be_removed_from_a_published_file_lesson(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $admin = $this->makeAdmin();
        [$product, , , $lesson] = $this->makeCourse('file-resource-course', 10000);
        $lesson->update(['lesson_type' => 'file', 'content' => null, 'video_url' => null]);
        $path = 'store/products/'.$product->id.'/lessons/'.$lesson->id.'/handout.pdf';
        \Illuminate\Support\Facades\Storage::disk('private')->put($path, 'course-handout');
        $resource = StoreProductFile::create([
            'product_id' => $product->id,
            'course_lesson_id' => $lesson->id,
            'purpose' => 'lesson_resource',
            'version' => '1.0',
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'handout.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 14,
            'sha256' => hash('sha256', 'course-handout'),
            'is_primary' => false,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'test',
            'scanned_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.course.edit', $product))
            ->delete(route('admin.store.course.resources.delete', [$product, $lesson, $resource]))
            ->assertRedirect(route('admin.store.course.edit', $product))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('store_product_files', ['id' => $resource->id]);
        \Illuminate\Support\Facades\Storage::disk('private')->assertExists($path);
    }

    public function test_free_course_checkout_creates_library_entitlement_and_enrollment(): void
    {
        $user = $this->makeUser();
        [$product] = $this->makeCourse();

        $this->actingAs($user)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])
            ->assertRedirect(route('library'));

        $libraryItem = StoreLibraryItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertNull($libraryItem->product_file_id);
        $this->assertDatabaseHas('store_course_enrollments', [
            'library_item_id' => $libraryItem->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_public_course_page_hides_invalid_published_lessons_and_preview_route_rejects_them(): void
    {
        [$product, , , $lesson] = $this->makeCourse('public-invalid-lesson', 10000);
        $lesson->update(['content' => null, 'is_published' => true, 'is_preview' => true]);

        $this->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertDontSee('جلسه اول');

        $this->get(route('store.course.preview', [$product, $lesson]))
            ->assertNotFound();
    }

    public function test_enrolled_student_can_open_lesson_and_save_completion(): void
    {
        $user = $this->makeUser();
        [$product, , , $lesson] = $this->makeCourse('student-course', 10000);
        $this->grantCourse($user, $product);

        $this->actingAs($user)
            ->get(route('store.course.learn', $product))
            ->assertOk()
            ->assertSee('فصل اول')
            ->assertSee('جلسه اول');

        $this->actingAs($user)
            ->get(route('store.course.lesson', [$product, $lesson]))
            ->assertOk()
            ->assertSee('محتوای آموزشی آزمایشی');

        $this->postJson(route('store.course.progress', [$product, $lesson]), ['completed' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'completed' => true]);

        $this->assertNotNull(DB::table('store_course_lesson_progress')
            ->where('lesson_id', $lesson->id)
            ->value('completed_at'));
    }

    public function test_older_active_entitlement_survives_a_newer_expired_purchase(): void
    {
        $user = $this->makeUser();
        [$product, $course] = $this->makeCourse('multiple-entitlements', 10000);

        $originalEntitlement = $this->grantCourse($user, $product); // Original enrollment has no expiry.
        $originalEntitlement->update(['granted_at' => now()->subDays(10)]);
        $course->update(['access_duration_days' => 1]);

        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 10000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 10000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now()->subDays(3),
        ]);
        $newerPurchase = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'product_file_id' => null,
            'granted_at' => now()->subDays(3),
        ]);
        app(StoreCourseEnrollmentService::class)->grant($newerPurchase, $product);

        $this->actingAs($user)
            ->get(route('store.course.learn', $product))
            ->assertOk()
            ->assertSee('فصل اول');
    }

    public function test_drip_released_lesson_cannot_be_opened_before_its_release_day(): void
    {
        $user = $this->makeUser();
        [$product, $course, , $lesson] = $this->makeCourse('drip-course', 10000);
        $course->update(['drip_enabled' => true]);
        $lesson->update(['drip_after_days' => 3]);
        $this->grantCourse($user, $product);

        $this->actingAs($user)
            ->get(route('store.course.lesson', [$product, $lesson]))
            ->assertForbidden();
    }
    public function test_progress_updates_preserve_position_when_completion_changes(): void
    {
        $user = $this->makeUser();
        [$product, , , $lesson] = $this->makeCourse('progress-preservation', 10000);
        $this->grantCourse($user, $product);

        $this->actingAs($user)
            ->postJson(route('store.course.progress', [$product, $lesson]), ['last_position_seconds' => 125])
            ->assertOk()
            ->assertJson(['ok' => true, 'completed' => false]);

        $this->postJson(route('store.course.progress', [$product, $lesson]), ['completed' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'completed' => true]);

        $progress = DB::table('store_course_lesson_progress')
            ->where('lesson_id', $lesson->id)
            ->first();

        $this->assertSame(125, (int) $progress->last_position_seconds);
        $this->assertNotNull($progress->completed_at);

        $this->postJson(route('store.course.progress', [$product, $lesson]), ['completed' => false])
            ->assertOk()
            ->assertJson(['ok' => true, 'completed' => false]);

        $progress = DB::table('store_course_lesson_progress')
            ->where('lesson_id', $lesson->id)
            ->first();

        $this->assertSame(125, (int) $progress->last_position_seconds);
        $this->assertNull($progress->completed_at);
    }

}
