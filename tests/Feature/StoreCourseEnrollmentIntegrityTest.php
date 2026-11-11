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
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\StoreCourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StoreCourseEnrollmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $paid = false, bool $revoked = false): array
    {
        $user = User::factory()->create();
        $category = StoreCategory::create([
            'name' => 'دوره‌های یکپارچگی',
            'slug' => 'course-enrollment-integrity',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره آزمون اعطای دسترسی',
            'slug' => 'course-enrollment-integrity-product',
            'sku' => 'COURSE-ENROLL-INTEGRITY',
            'type' => 'course',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $course = StoreCourse::create([
            'product_id' => $product->id,
            'level' => 'beginner',
            'language' => 'fa',
            'drip_enabled' => false,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'status' => $paid ? 'paid' : 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => $paid ? now() : null,
        ]);
        $orderItem = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'product_snapshot' => [],
        ]);
        $libraryItem = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_file_id' => null,
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
        ]);

        if ($paid) {
            Payment::create([
                'order_id' => $order->id,
                'gateway' => 'wallet',
                'transaction_id' => 'COURSE-ENROLL-'.$order->id,
                'amount_rials' => 100000,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        return compact('user', 'product', 'course', 'order', 'orderItem', 'libraryItem');
    }

    public function test_zero_total_checkout_marks_order_paid_before_granting_course_access(): void
    {
        $user = User::factory()->create();
        $category = StoreCategory::create([
            'name' => 'دوره رایگان',
            'slug' => 'free-course-enrollment-integrity',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره رایگان یکپارچه',
            'slug' => 'free-course-enrollment-integrity-product',
            'sku' => 'FREE-COURSE-ENROLL',
            'type' => 'course',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $course = StoreCourse::create([
            'product_id' => $product->id,
            'level' => 'beginner',
            'language' => 'fa',
            'drip_enabled' => false,
        ]);
        $section = StoreCourseSection::create([
            'course_id' => $course->id,
            'title' => 'فصل رایگان',
            'sort_order' => 1,
        ]);
        StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'درس رایگان',
            'slug' => 'free-course-enrollment-integrity-lesson',
            'lesson_type' => 'article',
            'content' => 'محتوای رایگان',
            'is_published' => true,
            'is_preview' => false,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])
            ->assertRedirect(route('library'));

        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->where('pricing_snapshot->kind', 'store')
            ->latest('id')
            ->firstOrFail();

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('free', $payment->gateway);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(0, (int) $payment->amount_rials);

        $libraryItem = StoreLibraryItem::query()
            ->where('order_id', $order->id)
            ->firstOrFail();
        $enrollment = StoreCourseEnrollment::query()
            ->where('library_item_id', $libraryItem->id)
            ->firstOrFail();

        $this->assertSame($user->id, (int) $enrollment->user_id);
        $this->assertSame($product->id, (int) $enrollment->product_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_pending_order_cannot_grant_course_enrollment(): void
    {
        $fixture = $this->fixture();

        try {
            app(StoreCourseEnrollmentService::class)->grant($fixture['libraryItem'], $fixture['product']);
            $this->fail('A pending order must never grant a course enrollment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('پس از تسویه', $exception->getMessage());
        }

        $this->assertDatabaseMissing('store_course_enrollments', [
            'library_item_id' => $fixture['libraryItem']->id,
        ]);
    }

    public function test_paid_order_with_matching_successful_payment_grants_one_idempotent_enrollment(): void
    {
        $fixture = $this->fixture(paid: true);
        $service = app(StoreCourseEnrollmentService::class);

        $first = $service->grant($fixture['libraryItem'], $fixture['product']);
        $second = $service->grant($fixture['libraryItem'], $fixture['product']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($fixture['course']->id, (int) $first->course_id);
        $this->assertSame($fixture['product']->id, (int) $first->product_id);
        $this->assertSame($fixture['user']->id, (int) $first->user_id);
        $this->assertSame(1, StoreCourseEnrollment::query()->where('library_item_id', $fixture['libraryItem']->id)->count());
    }

    public function test_paid_order_with_mismatched_payment_total_cannot_grant_course_enrollment(): void
    {
        $fixture = $this->fixture(paid: true);
        Payment::query()
            ->where('order_id', $fixture['order']->id)
            ->update(['amount_rials' => 99999]);

        try {
            app(StoreCourseEnrollmentService::class)->grant($fixture['libraryItem'], $fixture['product']);
            $this->fail('A payment total that differs from the order must not grant course access.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('تطبیق ندارد', $exception->getMessage());
        }

        $this->assertDatabaseMissing('store_course_enrollments', [
            'library_item_id' => $fixture['libraryItem']->id,
        ]);
    }

    public function test_revoked_library_entitlement_cannot_create_or_recreate_course_enrollment(): void
    {
        $fixture = $this->fixture(paid: true, revoked: true);

        try {
            app(StoreCourseEnrollmentService::class)->grant($fixture['libraryItem'], $fixture['product']);
            $this->fail('A revoked library entitlement must not grant course access.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('فعال نیست', $exception->getMessage());
        }

        $this->assertDatabaseMissing('store_course_enrollments', [
            'library_item_id' => $fixture['libraryItem']->id,
        ]);
    }
}
