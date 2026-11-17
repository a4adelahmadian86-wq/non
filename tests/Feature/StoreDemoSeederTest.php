<?php

namespace Tests\\Feature;

use App\\Models\\StoreProduct;
use App\\Models\\StoreProductPreview;
use Database\\Seeders\\StoreDemoSeeder;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Storage;
use Tests\\TestCase;

class StoreDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_paid_reader_previews_are_persisted_on_the_private_storage_disk(): void
    {
        Storage::fake('private');

        $this->seed(StoreDemoSeeder::class);

        $product = StoreProduct::query()->where('slug', 'demo-paid')->firstOrFail();
        $previews = StoreProductPreview::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $previews);
        Storage::disk('private')->assertExists('store/demo/demo-paid.txt');

        foreach ($previews as $preview) {
            $this->assertSame('private', $preview->disk);
            $this->assertSame('sample', $preview->access_level);
            Storage::disk('private')->assertExists($preview->path);

            $contents = Storage::disk('private')->get($preview->path);
            $this->assertStringStartsWith('<svg ', ltrim($contents));
        }

        $file = $product->files()->where('purpose', 'product_download')->where('is_primary', true)->firstOrFail();
        $source = Storage::disk('private')->get('store/demo/demo-paid.txt');
        $this->assertSame(strlen($source), $file->size_bytes);
        $this->assertSame(hash('sha256', $source), $file->sha256);
    }

    public function test_demo_paid_reader_route_serves_seeded_sample_previews(): void
    {
        Storage::fake('private');
        $this->seed(StoreDemoSeeder::class);

        $product = StoreProduct::query()->where('slug', 'demo-paid')->firstOrFail();
        $preview = StoreProductPreview::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->firstOrFail();

        $this->get(route('store.reader', $product->slug))
            ->assertOk()
            ->assertSee('data-reader-root', false)
            ->assertSee(route('store.preview', $preview), false);

        $this->get(route('store.preview', $preview))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertSee('نمونه پولی');
    }
}
