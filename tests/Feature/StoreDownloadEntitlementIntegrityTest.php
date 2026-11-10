<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCategory;
use App\Models\StoreDownload;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\User;
use App\Models\Wallet;
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
            'purpose' => 'product_download',
            'scan_status' => 'clean',
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

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => 'AUTH-SNAPSHOT',
            'transaction_id' => 'AUTH-SNAPSHOT',
            'amount_rials' => $order->total_rials,
            'status' => 'pending',
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

        // A repeated gateway callback must be idempotent and must not send a second receipt.
        $this->withSession([
            'zarinpal_order_id' => $order->id,
            'zarinpal_authority' => 'AUTH-SNAPSHOT',
        ])->get('/checkout/zarinpal/callback?Authority=AUTH-SNAPSHOT&Status=OK')
            ->assertRedirect(route('library'));
    }


    public function test_wallet_payment_uses_the_exact_checkout_price_and_file_snapshot(): void
    {
        Storage::fake('private');
        $user = $this->makeUser('09123456788');
        [$product, $purchasedFile] = $this->makeProduct('wallet-price-snapshot');

        $this->actingAs($user)
            ->postJson('/cart/products/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $this->post('/cart/checkout', ['accept_terms' => '1'])->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $originalTotal = (int) $order->total_rials;
        $item = StoreOrderItem::query()->where('order_id', $order->id)->firstOrFail();

        // Catalog changes after checkout must not silently change the confirmed order price.
        $product->update(['price_rials' => 250000]);
        $purchasedFile->update(['is_primary' => false]);
        Storage::disk('private')->put('products/wallet-price-snapshot-v2.pdf', 'version-two');
        StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'scan_status' => 'clean',
            'version' => '2.0',
            'disk' => 'private',
            'path' => 'products/wallet-price-snapshot-v2.pdf',
            'original_name' => 'product-v2.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'version-two'),
            'is_primary' => true,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id, 'balance_rials' => $originalTotal]);

        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendOrderPaid')->once()->andReturnNull();
        app()->instance(EmailService::class, $email);

        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        // Replaying the same wallet request must not debit or dispatch a second receipt.
        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertRedirect(route('store'));

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('gateway', 'wallet')->count());
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'balance_rials' => 0,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount_rials' => $originalTotal,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_type' => 'store_order',
            'reference_id' => $order->id,
            'amount_rials' => $originalTotal,
        ]);

        $paidOrder = $order->fresh();
        $this->assertSame($originalTotal, (int) $paidOrder->total_rials);
        $this->assertSame((int) $item->unit_price_rials, (int) $item->fresh()->unit_price_rials);
        $libraryItem = StoreLibraryItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($purchasedFile->id, (int) $libraryItem->product_file_id);
    }

    public function test_zarinpal_callback_rejects_an_authority_not_bound_to_the_order(): void
    {
        $user = $this->makeUser();
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);

        $gateway = Mockery::mock(ZarinpalService::class);
        $gateway->shouldNotReceive('verify');
        app()->instance(ZarinpalService::class, $gateway);

        $this->actingAs($user)->withSession([
            'zarinpal_order_id' => $order->id,
            'zarinpal_authority' => 'AUTH-FROM-ANOTHER-ORDER',
        ])->get('/checkout/zarinpal/callback?Authority=AUTH-FROM-ANOTHER-ORDER&Status=OK')
            ->assertRedirect(route('checkout', $order))
            ->assertSessionHasErrors('payment');
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

    public function test_quarantined_files_cannot_be_issued_or_streamed_from_old_links(): void
    {
        Storage::fake('private');
        $user = $this->makeUser('09123456784');
        [$product, $file] = $this->makeProduct('quarantined-product');
        [$libraryItem, $download, $token] = $this->makeDownload($user, $product, $file);

        $file->update(['scan_status' => 'infected']);

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('فایل این محصول فعلاً در دسترس نیست.')
            ->assertDontSee('data-library-download="'.$libraryItem->id.'"', false);

        $this->post(route('library.download.issue', $libraryItem))
            ->assertNotFound();

        $url = URL::temporarySignedRoute(
            'store.download.stream',
            now()->addMinutes(10),
            ['download' => $download->id, 'token' => $token]
        );

        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('store_downloads', [
            'id' => $download->id,
            'download_count' => 0,
        ]);
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

    public function test_library_page_shows_download_details_and_accessible_feedback(): void
    {
        $user = $this->makeUser('09123456780');
        [$product, $file] = $this->makeProduct('library-ui-product');
        [$libraryItem] = $this->makeDownload($user, $product, $file);

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('libraryDownloadFeedback', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('product-v1.pdf')
            ->assertSee('خریداری‌شده')
            ->assertSee('دریافت امن فایل')
            ->assertDontSee('alert(');
    }

    public function test_library_keeps_course_access_separate_from_file_downloads(): void
    {
        $user = $this->makeUser('09123456781');
        [$product, $file] = $this->makeProduct('library-course');
        $product->update(['type' => 'course']);
        $this->makeDownload($user, $product, $file);

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('دوره آموزشی')
            ->assertSee('ورود به دوره')
            ->assertSee(route('store.course.learn', $product), false)
            ->assertDontSee('class="store-primary-btn library-download"', false);
    }


    public function test_empty_library_explains_how_to_get_started(): void
    {
        $user = $this->makeUser('09123456782');

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('کتابخانه شما هنوز خالی است')
            ->assertSee('کشف محصولات فروشگاه')
            ->assertDontSee('data-library-download=', false);
    }


    public function test_reader_only_purchase_opens_reader_instead_of_download(): void
    {
        $user = $this->makeUser('09123456783');
        [$product, $file] = $this->makeProduct('reader-only-library');
        $product->update(['download_policy' => 'reader_only']);
        $this->makeDownload($user, $product, $file);

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('مطالعه در خوانشگر')
            ->assertSee(route('store.reader', $product->slug), false)
            ->assertDontSee('data-library-download=', false);
    }

    public function test_zarinpal_initialization_reserves_order_against_wallet_double_payment(): void
    {
        $user = $this->makeUser('09123456786');
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);

        $gateway = Mockery::mock(ZarinpalService::class);
        $gateway->shouldReceive('enabled')->once()->andReturn(true);
        $gateway->shouldReceive('request')->once()->andReturn([
            'authority' => 'AUTH-RESERVED-ORDER',
            'url' => 'https://gateway.example.test/pay/AUTH-RESERVED-ORDER',
        ]);
        app()->instance(ZarinpalService::class, $gateway);

        $this->actingAs($user)
            ->post(route('checkout.zarinpal', $order), ['accept_terms' => '1'])
            ->assertRedirect('https://gateway.example.test/pay/AUTH-RESERVED-ORDER');

        $this->assertSame('gateway_pending', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => 'AUTH-RESERVED-ORDER',
            'status' => 'pending',
            'amount_rials' => 50000,
        ]);

        // The wallet path must reject this order while the gateway attempt is active.
        $this->post(route('checkout.wallet', $order), ['accept_terms' => '1'])
            ->assertSessionHasErrors('payment');

        $this->assertSame('gateway_pending', $order->fresh()->status);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->where('gateway', 'wallet')->count());
    }

    public function test_failed_zarinpal_initialization_releases_order_reservation(): void
    {
        $user = $this->makeUser('09123456785');
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);

        $gateway = Mockery::mock(ZarinpalService::class);
        $gateway->shouldReceive('enabled')->once()->andReturn(true);
        $gateway->shouldReceive('request')->once()->andThrow(new \\RuntimeException('gateway unavailable'));
        app()->instance(ZarinpalService::class, $gateway);

        $this->actingAs($user)
            ->post(route('checkout.zarinpal', $order), ['accept_terms' => '1'])
            ->assertSessionHasErrors('payment');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'status' => 'failed',
        ]);
    }

}
