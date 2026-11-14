<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCategory;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\User;
use App\Services\StoreOrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class StoreOrderFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_digital_entitlement_is_pinned_to_the_order_file_and_verified_through_storage_core(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $category = StoreCategory::create([
            'name' => 'فایل‌های آزمون تحویل',
            'slug' => 'fulfillment-files-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'فایل نسخه‌بندی‌شده',
            'slug' => 'version-pinned-download',
            'sku' => 'FULFILL-FILE-001',
            'type' => 'digital_file',
            'price_rials' => 25000,
            'status' => 'archived',
            'published_at' => now()->subDay(),
        ]);
        $path = 'products/fulfillment/version-1.pdf';
        $contents = "%PDF-1.7 exact purchased version";
        Storage::disk('private')->put($path, $contents);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'version-1.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'is_primary' => false,
            'is_active' => true,
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 25000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 25000,
            'status' => 'paid',
            'paid_at' => now(),
            'pricing_snapshot' => ['kind' => 'store'],
        ]);
        $item = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 25000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 25000,
            'product_snapshot' => [
                'file_id' => $file->id,
                'file_sha256' => $file->sha256,
            ],
        ]);
        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount_rials' => 25000,
            'status' => 'paid',
            'transaction_id' => 'FULFILL-FILE-PAYMENT-001',
            'paid_at' => now(),
        ]);

        $service = app(StoreOrderFulfillmentService::class);
        $this->assertSame(1, $service->fulfill($order));

        $libraryItem = StoreLibraryItem::query()
            ->where('user_id', $user->id)
            ->where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $this->assertSame($item->id, (int) $libraryItem->order_item_id);
        $this->assertSame($file->id, (int) $libraryItem->product_file_id);

        // A later primary-file replacement must not change the purchased file,
        // and corrupted storage bytes must block any silent re-fulfillment.
        Storage::disk('private')->put($path, 'tampered bytes');
        try {
            $service->fulfill($order->fresh());
            $this->fail('A file whose bytes no longer match the pinned checksum must not be granted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('checksum', $exception->getMessage());
        }

        $this->assertSame($file->id, (int) $libraryItem->fresh()->product_file_id);
        $this->assertSame(1, StoreLibraryItem::query()->where('order_id', $order->id)->count());
    }
}
