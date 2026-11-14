<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\StoreCategory;
use App\Models\StoreCourse;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ZarinpalCommerceWorkflowOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_gateway_payment_keeps_paid_state_and_reconciles_outbox_failure(): void
    {
        $user = User::factory()->create();
        $category = StoreCategory::create([
            'name' => 'درگاه آزمون',
            'slug' => 'zarinpal-outbox-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره پرداخت درگاه',
            'slug' => 'zarinpal-outbox-course',
            'sku' => 'ZP-OUTBOX-COURSE-001',
            'type' => 'course',
            'price_rials' => 45000,
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
            'title' => 'فصل پرداخت درگاه',
            'sort_order' => 1,
        ]);
        StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'درس تحویل',
            'slug' => 'zarinpal-outbox-delivery',
            'lesson_type' => 'article',
            'content' => 'محتوای آزمون تحویل دوره',
            'is_published' => true,
            'is_preview' => false,
            'sort_order' => 1,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 45000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 45000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);
        $item = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 45000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 45000,
            'product_snapshot' => ['title' => $product->title, 'sku' => $product->sku],
        ]);
        $authority = 'A000000000000000000000000000000099';
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => $authority,
            'transaction_id' => $authority,
            'amount_rials' => 45000,
            'status' => 'pending',
        ]);

        DB::table('farast_workflows')->insert([
            'user_id' => $user->id,
            'name' => 'پیگیری پرداخت درگاه',
            'enabled' => true,
            'trigger_type' => 'order.paid',
            'steps' => json_encode([
                ['action' => 'create_follow_up', 'title' => 'پیگیری پرداخت درگاه', 'priority' => 'high'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SiteSetting::write('payment_gateway', 'zarinpal');
        SiteSetting::write('gateway_merchant_id', 'test-merchant-id');
        SiteSetting::write('zarinpal_sandbox', true);

        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 987654321],
            ], 200),
        ]);

        $workflowEngine = Mockery::mock(WorkflowEngine::class);
        $workflowEngine->shouldReceive('enqueue')
            ->once()
            ->andThrow(new RuntimeException('Workflow outbox unavailable.'));
        $this->app->instance(WorkflowEngine::class, $workflowEngine);

        $this->actingAs($user)
            ->withSession([
                'zarinpal_order_id' => $order->id,
                'zarinpal_authority' => $authority,
            ])
            ->get(route('checkout.zarinpal.callback').'?Authority='.$authority.'&Status=OK')
            ->assertRedirect(route('library'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseHas('store_library_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'gateway_response->workflow_event_reconciliation->status' => 'required',
        ]);
        $this->assertDatabaseMissing('farast_workflow_events', [
            'idempotency_key' => 'order:'.$order->id.':order.paid',
        ]);

        // The external capture is settled; a later scheduler pass restores the
        // missing automation event without changing the financial record.
        $this->app->instance(WorkflowEngine::class, new WorkflowEngine());
        $engine = app(WorkflowEngine::class);
        $this->assertSame(1, $engine->retryFailedEvents());

        $eventKey = 'order:'.$order->id.':order.paid';
        $this->assertDatabaseHas('farast_workflow_events', [
            'event_type' => 'order.paid',
            'idempotency_key' => $eventKey,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);
        $this->assertSame(
            'reconciled',
            data_get(Payment::query()->findOrFail($payment->id)->gateway_response, 'workflow_event_reconciliation.status'),
        );
        $this->assertDatabaseHas('crm_follow_up_tasks', [
            'customer_id' => $user->id,
            'created_by' => $user->id,
            'title' => 'پیگیری پرداخت درگاه',
            'priority' => 'high',
        ]);
        $this->assertSame(0, $engine->retryFailedEvents());
        $this->assertSame(1, DB::table('farast_workflow_events')->where('idempotency_key', $eventKey)->count());
    }
}
