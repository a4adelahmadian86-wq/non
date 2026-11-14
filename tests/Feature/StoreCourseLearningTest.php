<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\Wallet;
use App\Models\StoreOrderItem;
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

    private function attachPaidStoreOrderRecords(Order $order, StoreProduct $product): StoreOrderItem
    {
        $orderItem = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => (int) $order->total_rials,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => (int) $order->total_rials,
            'product_snapshot' => ['title' => $product->title, 'sku' => $product->sku],
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'test',
            'amount_rials' => (int) $order->total_rials,
            'status' => 'paid',
            'transaction_id' => 'TEST-COURSE-ORDER-'.$order->id,
            'idempotency_key' => 'test-course-order-'.$order->id,
            'paid_at' => now(),
        ]);

        return $orderItem;
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
        $this->attachPaidStoreOrderRecords($order, $product);
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

    public function test_paid_course_wallet_checkout_grants_one_verified_entitlement(): void
    {
        $user = $this->makeUser();
        [$product] = $this->makeCourse('wallet-paid-course', 12000);
        Wallet::create(['user_id' => $user->id, 'balance_rials' => 100000]);

        $this->actingAs($user)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])->assertRedirect();
        $order = Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status);

        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        $order->refresh();
        $this->assertTrue($order->isPaid());
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertSame((int) $order->total_rials, (int) $payment->amount_rials);

        $libraryItem = StoreLibraryItem::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('order_id', $order->id)
            ->firstOrFail();
        $this->assertNotNull($libraryItem->order_item_id);

        $enrollment = StoreCourseEnrollment::query()
            ->where('library_item_id', $libraryItem->id)
            ->firstOrFail();
        $this->assertSame($user->id, (int) $enrollment->user_id);
        $this->assertSame($product->id, (int) $enrollment->product_id);

        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, StoreLibraryItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, StoreCourseEnrollment::query()->where('library_item_id', $libraryItem->id)->count());
    }

    public function test_course_enrollment_rejects_paid_order_without_matching_payment_ledger(): void
    {
        $user = $this->makeUser();
        [$product] = $this->makeCourse('missing-payment-course', 15000);
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 15000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 15000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now(),
        ]);
        $orderItem = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 15000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 15000,
            'product_snapshot' => ['title' => $product->title],
        ]);
        $libraryItem = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'granted_at' => now(),
        ]);

        $this->expectException(\\RuntimeException::class);
        app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
    }

    public function test_course_enrollment_rejects_revoked_library_entitlement(): void
    {
        $user = $this->makeUser();
        [$product] = $this->makeCourse('revoked-course-regrant', 10000);
        $libraryItem = $this->grantCourse($user, $product);
        $libraryItem->update(['revoked_at' => now()]);

        $this->expectException(\\RuntimeException::class);
        app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
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
        $this->attachPaidStoreOrderRecords($order, $product);
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

    public function test_course_completion_counts_only_lessons_released_by_the_drip_schedule(): void
    {
        $user = $this->makeUser();
        [$product, $course, $section, $firstLesson] = $this->makeCourse('drip-completion-course', 10000);
        $course->update(['drip_enabled' => true]);
        StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'جلسه آینده',
            'slug' => 'drip-completion-future-lesson',
            'lesson_type' => 'article',
            'content' => 'این جلسه هنوز آزاد نشده است.',
            'is_published' => true,
            'drip_after_days' => 3,
            'sort_order' => 2,
        ]);
        $this->grantCourse($user, $product);

        $this->actingAs($user)
            ->postJson(route('store.course.progress', [$product, $firstLesson]), ['completed' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'completed' => true]);

        $enrollment = StoreCourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertNotNull($enrollment->fresh()->completed_at);
        $this->actingAs($user)
            ->get(route('store.course.learn', $product))
            ->assertOk()
            ->assertSee('100٪');
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

    public function test_revoked_course_entitlement_cannot_open_course_or_lessons(): void
    {
        $user = $this->makeUser();
        [$product, , , $lesson] = $this->makeCourse('revoked-course-entitlement', 10000);
        $libraryItem = $this->grantCourse($user, $product);
        $libraryItem->update(['revoked_at' => now()]);

        $this->actingAs($user)
            ->get(route('store.course.learn', $product))
            ->assertNotFound();

        $this->get(route('store.course.lesson', [$product, $lesson]))
            ->assertNotFound();
    }


}
