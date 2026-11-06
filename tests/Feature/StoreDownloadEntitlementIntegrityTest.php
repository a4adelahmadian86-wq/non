<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreDownload;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\User;
use App\Services\EmailService;
use App\Services\ZarinpalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class StoreDownloadEntitlementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $mobile = '09123456789'): User
    {
        return User::create([
            'name' => 'Store QA',
            'mobile' => $mobile,
            'password' => 'secret',
        ]);
    }

    /**
     * @return array{0: StoreProduct, 1: StoreProductFile}
     */
    private function makeProduct(string $slug = 'snapshot-product'): array
    {
        $category = StoreCategory::create([
            'name' => 'آزمایشی',
            'slug' => $slug.'-category',
            'is_active' => true,
        ]);

        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'type' => 'digital_file',
            'price_rials' => 50000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'version' => '1.0',
        ]);

        Storage::disk('private')->put('products/'.$slug.'-v1.pdf', 'version-one');

        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'products/'.$slug.'-v1.pdf',
            'original_name' => 'product-v1.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'version-one'),
            'is_primary' => true,
            'is_active' => true,
        ]);

        return [$product, $file];
    }

    /**
     * @return array{0: StoreLibraryItem, 1: StoreDownload, 2: string}
     */
    private function makeDownload(User $user, StoreProduct $product, StoreProductFile $file, int $count = 0): array
    {
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now(),
        ]);

        $libraryItem = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'product_file_id' => $file->id,
            'granted_at' => now(),
        ]);

        $token = str_repeat('a', 64);
        $download = StoreDownload::create([
            'library_item_id' => $libraryItem->id,
            'user_id' => $user->id,
            'product_file_id' => $file->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(10),
            'download_count' => $count,
        ]);

        return [$libraryItem, $download, $token];
    }

    public function test_zarinpal_callback_grants_the_exact_file_selected_at_checkout(): void
    {
        Storage::fake('private');
        $user = $this->makeUser();
        [$product, $purchasedFile] = $this->makeProduct();

        $this->actingAs($user)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $item = StoreOrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame($purchasedFile->id, (int) ($item->product_snapshot['file_id'] ?? 0));
        $this->assertSame($purchasedFile->sha256, $item->product_snapshot['file_sha256'] ?? null);

        $purchasedFile->update(['is_primary' => false]);
        Storage::disk('private')->put('products/'.$product->slug.'-v2.pdf', 'version-two');
        StoreProductFile::create([
            'product_id' => $product->id,
            'version' => '2.0',
            'disk' => 'private',
            'path' => 'products/'.$product->slug.'-v2.pdf',
            'original_name' => 'product-v2.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'version-two'),
            'is_primary' => true,
            'is_active' => true,
        ]);

        $gateway = Mockery::mock(ZarinpalService::class);
        $gateway->shouldReceive('verify')
            ->once()
            ->with('AUTH-SNAPSHOT', (int) $order->total_rials)
            ->andReturn(['code' => 100, 'ref_id' => '123456']);
        app()->instance(ZarinpalService::class, $gateway);

        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendOrderPaid')->once()->andReturnNull();
        app()->instance(EmailService::class, $email);

        $this->withSession([
            'zarinpal_order_id' => $order->id,
            'zarinpal_authority' => 'AUTH-SNAPSHOT',
        ])->get('/checkout/zarinpal/callback?Authority=AUTH-SNAPSHOT&Status=OK')
            ->assertRedirect(route('library'));

        $libraryItem = StoreLibraryItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($purchasedFile->id, $libraryItem->product_file_id);
    }

    public function test_revoking_a_library_item_invalidates_previously_issued_download_links(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('products/revoked.pdf', 'content');

        $user = $this->makeUser();
        [$product, $file] = $this->makeProduct('revoked-product');
        [$libraryItem, $download, $token] = $this->makeDownload($user, $product, $file);
        $libraryItem->update(['revoked_at' => now()]);

        $url = URL::temporarySignedRoute(
            'store.download.stream',
            now()->addMinutes(10),
            ['download' => $download->id, 'token' => $token]
        );

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertDatabaseHas('store_downloads', [
            'id' => $download->id,
            'download_count' => 0,
        ]);
    }

    public function test_download_link_cannot_stream_a_file_other_than_its_library_entitlement(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('products/alternate.pdf', 'alternate');

        $user = $this->makeUser();
        [$product, $entitledFile] = $this->makeProduct('mismatch-product');
        $otherFile = StoreProductFile::create([
            'product_id' => $product->id,
            'version' => '2.0',
            'disk' => 'private',
            'path' => 'products/alternate.pdf',
            'original_name' => 'alternate.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 9,
            'sha256' => hash('sha256', 'alternate'),
            'is_primary' => false,
            'is_active' => true,
        ]);

        [$libraryItem, $download, $token] = $this->makeDownload($user, $product, $entitledFile);
        $download->update(['product_file_id' => $otherFile->id]);

        $url = URL::temporarySignedRoute(
            'store.download.stream',
            now()->addMinutes(10),
            ['download' => $download->id, 'token' => $token]
        );

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_download_limit_stops_at_ten_successful_streams(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('products/limited.pdf', 'content');

        $user = $this->makeUser();
        [$product, $file] = $this->makeProduct('limited-product');
        [, $download, $token] = $this->makeDownload($user, $product, $file, 9);

        $url = URL::temporarySignedRoute(
            'store.download.stream',
            now()->addMinutes(10),
            ['download' => $download->id, 'token' => $token]
        );

        $this->actingAs($user)->get($url)->assertOk();
        $this->get($url)->assertTooManyRequests();

        $this->assertDatabaseHas('store_downloads', [
            'id' => $download->id,
            'download_count' => 10,
        ]);
    }
}
