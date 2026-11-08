<?php

namespace Tests\Feature;

use App\Models\StoreProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReaderDemoProductCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_command_creates_one_published_product_with_private_prepared_reader_samples(): void
    {
        Storage::fake('private');

        $this->assertSame(0, Artisan::call('farast:seed-reader-demo'));

        $product = StoreProduct::query()->where('slug', 'farast-reader-demo')->firstOrFail();

        $this->assertSame('published', $product->status);
        $this->assertSame(3, $product->previews()->count());
        $this->assertDatabaseHas('store_product_previews', ['product_id' => $product->id, 'kind' => 'pdf', 'disk' => 'private', 'access_level' => 'sample']);
        $this->assertDatabaseHas('store_product_previews', ['product_id' => $product->id, 'path' => 'store/reader-demo/FARAST-Reader-Demo-Word.pdf', 'kind' => 'pdf']);
        $this->assertDatabaseHas('store_product_previews', ['product_id' => $product->id, 'path' => 'store/reader-demo/FARAST-Reader-Demo-Excel.html', 'kind' => 'html']);
        $this->assertDatabaseHas('store_product_files', ['product_id' => $product->id, 'purpose' => 'product_download', 'scan_status' => 'clean']);
        Storage::disk('private')->assertExists('store/reader-demo/FARAST-Reader-Demo.pdf');
        Storage::disk('private')->assertExists('store/reader-demo/FARAST-Reader-Demo-Word.pdf');
        Storage::disk('private')->assertExists('store/reader-demo/FARAST-Reader-Demo-Excel.html');

        $this->assertSame(0, Artisan::call('farast:seed-reader-demo'));
        $this->assertSame(1, StoreProduct::query()->where('slug', 'farast-reader-demo')->count());
    }
}
