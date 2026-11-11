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
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessCommerceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_course_purchase_uses_one_catalog_product_order_payment_and_entitlement_chain(): void
    {
        $customer = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $customer->id,
            'balance_rials' => 500000,
        ]);

        $category = StoreCategory::create([
            'name' => 'دوره‌های آزمون',
            'slug' => 'phase7-course-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره یکپارچه‌سازی مرحله هفتم',
            'slug' => 'phase7-commerce-course',
            'sku' => 'PHASE7-COURSE-001',
            'type' => 'course',
            'price_rials' => 120000,
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
            'title' => 'فصل پذیرش سرتاسری',
            'sort_order' => 1,
        ]);
        $lesson = StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'درس چرخه سفارش',
            'slug' => 'phase7-order-lifecycle-lesson',
            'lesson_type' => 'article',
            'content' => 'محتوای درس برای آزمون یکپارچگی',
            'is_published' => true,
            'is_preview' => false,
            'sort_order' => 1,
        ]);

        $this->actingAs($customer)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])
            ->assertRedirect();

        $order = Order::query()
            ->where('user_id', $customer->id)
            ->where('status', 'pending')
            ->where('pricing_snapshot->kind', 'store')
            ->latest('id')
            ->firstOrFail();

        $orderItem = StoreOrderItem::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->assertSame($product->id, (int) $orderItem->product_id);
        $this->assertSame($product->title, $orderItem->title_snapshot);
        $this->assertSame(1, (int) $orderItem->quantity);
        $this->assertSame('store', data_get($order->pricing_snapshot, 'kind'));
        $this->assertSame($order->total_rials, (int) $orderItem->total_rials + (int) $orderItem->tax_rials);

        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        $order->refresh();
        $wallet->refresh();

        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(500000 - (int) $order->total_rials, (int) $wallet->balance_rials);

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('wallet', $payment->gateway);
        $this->assertSame('paid', $payment->status);
        $this->assertSame((int) $order->total_rials, (int) $payment->amount_rials);

        $debit = WalletTransaction::query()
            ->where('reference_type', 'store_order')
            ->where('reference_id', $order->id)
            ->firstOrFail();
        $this->assertSame('debit', $debit->type);
        $this->assertSame((int) $order->total_rials, (int) $debit->amount_rials);
        $this->assertSame('store-order-'.$order->id, $debit->idempotency_key);

        $libraryItem = StoreLibraryItem::query()
            ->where('user_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('order_id', $order->id)
            ->firstOrFail();
        $this->assertSame($orderItem->id, (int) $libraryItem->order_item_id);
        $this->assertNull($libraryItem->product_file_id);
        $this->assertNull($libraryItem->revoked_at);

        $enrollment = StoreCourseEnrollment::query()
            ->where('library_item_id', $libraryItem->id)
            ->firstOrFail();
        $this->assertSame($course->id, (int) $enrollment->course_id);
        $this->assertSame($product->id, (int) $enrollment->product_id);
        $this->assertSame($customer->id, (int) $enrollment->user_id);

        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $customer->id,
            'type' => 'order_paid',
        ]);

        // Replaying the same wallet checkout must not create a second financial
        // entry, payment, entitlement, enrollment, or order-paid notification.
        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, WalletTransaction::query()
            ->where('reference_type', 'store_order')
            ->where('reference_id', $order->id)
            ->count());
        $this->assertSame(1, StoreLibraryItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, StoreCourseEnrollment::query()->where('library_item_id', $libraryItem->id)->count());
        $this->assertSame(1, DB::table('farast_notifications')
            ->where('user_id', $customer->id)
            ->where('type', 'order_paid')
            ->count());

        $this->get(route('store.course.learn', $product))
            ->assertOk()
            ->assertSee($section->title)
            ->assertSee($lesson->title);
    }
}
