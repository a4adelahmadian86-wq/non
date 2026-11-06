<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCategory;
use App\Models\StoreDownload;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmCustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function makeUser(string $mobile, string $role = User::ROLE_EMPLOYEE): User
    {
        return User::create([
            'name' => 'CRM '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_admin_can_view_customer_360_and_create_internal_note_and_follow_up(): void
    {
        $admin = $this->makeUser('09120000001', User::ROLE_ADMIN);
        $customer = $this->makeUser('09120000002');

        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 250000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 250000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'test'],
            'paid_at' => now(),
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount_rials' => 250000,
            'status' => 'paid',
            'transaction_id' => 'CRM-PAYMENT-1',
            'paid_at' => now(),
        ]);
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance_rials' => 750000]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount_rials' => 250000,
            'balance_before' => 1000000,
            'balance_after' => 750000,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'description' => 'پرداخت سفارش',
            'idempotency_key' => 'crm-test-wallet-1',
        ]);

        $category = StoreCategory::create(['name' => 'فایل‌ها', 'slug' => 'crm-files']);
        $product = StoreProduct::create([
            'category_id' => $category->id, 'seller_id' => $admin->id,
            'title' => 'قالب آزمایشی', 'slug' => 'crm-template', 'sku' => 'CRM-TPL-1',
            'type' => 'digital_file', 'price_rials' => 100000, 'status' => 'published',
            'published_at' => now(),
        ]);
        $file = StoreProductFile::create([
            'product_id' => $product->id, 'version' => '1.0', 'disk' => 'private',
            'path' => 'crm/template.zip', 'original_name' => 'template.zip',
            'mime' => 'application/zip', 'size_bytes' => 1200,
            'sha256' => str_repeat('a', 64), 'is_primary' => true, 'is_active' => true,
        ]);
        $libraryItem = StoreLibraryItem::create([
            'user_id' => $customer->id, 'product_id' => $product->id,
            'order_id' => $order->id, 'product_file_id' => $file->id,
            'granted_at' => now(),
        ]);
        StoreDownload::create([
            'library_item_id' => $libraryItem->id, 'user_id' => $customer->id,
            'product_file_id' => $file->id, 'token_hash' => hash('sha256', 'crm-download-token'),
            'expires_at' => now()->addDay(), 'download_count' => 2,
        ]);

        $this->actingAs($admin)->getJson('/admin/crm/customers')
            ->assertOk()->assertJsonFragment(['id' => $customer->id]);

        $this->getJson('/admin/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('metrics.orders_count', 1)
            ->assertJsonPath('metrics.paid_revenue_rials', 250000)
            ->assertJsonPath('timeline.0.type', 'order')
            ->assertJsonFragment(['type' => 'payment', 'gateway' => 'wallet', 'amount_rials' => 250000])
            ->assertJsonFragment(['type' => 'wallet_transaction', 'balance_after_rials' => 750000])
            ->assertJsonPath('metrics.payments_count', 1)
            ->assertJsonPath('metrics.wallet_transactions_count', 1);
        $this->getJson('/admin/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('metrics.store_library_items_count', 1)
            ->assertJsonPath('metrics.store_downloads_count', 1)
            ->assertJsonFragment(['type' => 'store_download', 'download_count' => 2])
            ->assertJsonFragment(['type' => 'store_entitlement', 'status' => 'active']);

        $this->postJson('/admin/crm/customers/'.$customer->id.'/notes', [
            'note' => 'مشتری برای سفارش بعدی تماس می‌خواهد.',
        ])->assertCreated();

        $this->assertDatabaseHas('crm_customer_notes', [
            'user_id' => $customer->id,
            'author_id' => $admin->id,
            'visibility' => 'internal',
        ]);

        $this->postJson('/admin/crm/customers/'.$customer->id.'/follow-ups', [
            'title' => 'تماس پیگیری',
            'priority' => 'high',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated();

        $taskId = DB::table('crm_follow_up_tasks')->where('customer_id', $customer->id)->value('id');
        $this->patchJson('/admin/crm/customers/'.$customer->id.'/follow-ups/'.$taskId, [
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('crm_follow_up_tasks', [
            'id' => $taskId,
            'status' => 'completed',
        ]);
    }

    public function test_employee_without_users_permission_cannot_access_crm(): void
    {
        $employee = $this->makeUser('09120000003');
        $customer = $this->makeUser('09120000004');

        $this->actingAs($employee)->getJson('/admin/crm/customers')->assertForbidden();
        $this->postJson('/admin/crm/customers/'.$customer->id.'/notes', ['note' => 'نباید ثبت شود'])
            ->assertForbidden();
        $this->assertDatabaseCount('crm_customer_notes', 0);
    }

    public function test_customer_detail_and_writes_respect_admin_scope(): void
    {
        $organizationA = \App\Models\Organization::create(['name' => 'CRM A', 'slug' => 'crm-a', 'status' => 'active']);
        $organizationB = \App\Models\Organization::create(['name' => 'CRM B', 'slug' => 'crm-b', 'status' => 'active']);
        $admin = $this->makeUser('09120000005', User::ROLE_ADMIN);
        $admin->organization_id = $organizationA->id;
        $admin->save();
        $inside = $this->makeUser('09120000006');
        $outside = $this->makeUser('09120000007');

        $inside->organization_id = $organizationA->id;
        $inside->save();
        $outside->organization_id = $organizationB->id;
        $outside->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->getJson('/admin/crm/customers/'.$outside->id)->assertForbidden();
        $this->postJson('/admin/crm/customers/'.$outside->id.'/notes', ['note' => 'خارج از دامنه'])
            ->assertForbidden();
        $this->assertDatabaseCount('crm_customer_notes', 0);
    }
}
