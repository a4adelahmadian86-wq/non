<?php

namespace Database\Seeders;

use App\Models\StoreCategory;
use App\Models\StoreCoupon;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class StoreDemoSeeder extends Seeder
{
    public function run(): void
    {
        $dir = storage_path('app/private/store/demo');
        File::ensureDirectoryExists($dir);
        $path = 'store/demo/demo-free.txt';
        $full = storage_path('app/private/'.$path);
        if (! is_file($full)) {
            file_put_contents($full, "فایل آزمایشی رایگان فروشگاه فراست\nنسخه دمو برای تست خرید و کتابخانه.\n");
        }

        $category = StoreCategory::firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'آزمایشی', 'description' => 'دسته محصولات آزمایشی', 'is_active' => true, 'sort_order' => 1]
        );

        $books = StoreCategory::firstOrCreate(
            ['slug' => 'books'],
            ['name' => 'کتاب و جزوه', 'description' => 'نمونه دسته کتابخانه', 'is_active' => true, 'sort_order' => 2]
        );

        $product = StoreProduct::updateOrCreate(
            ['slug' => 'demo-free'],
            [
                'category_id' => $category->id,
                'title' => 'فایل آزمایشی رایگان',
                'sku' => 'DEMO-FREE-001',
                'type' => 'digital_file',
                'short_description' => 'محصول رایگان برای تست مسیر خرید و دانلود',
                'description' => "این محصول فقط برای آزمایش فروشگاه است.\nپس از دریافت رایگان، فایل در کتابخانه ظاهر می‌شود.",
                'price_rials' => 0,
                'status' => 'published',
                'published_at' => now(),
                'featured' => true,
                'sort_order' => 1,
                'preview_pages' => 3,
                'license_type' => 'شخصی',
                'version' => '1.0',
            ]
        );

        StoreProductFile::updateOrCreate(
            ['product_id' => $product->id, 'path' => $path],
            [
                'version' => '1.0',
                'disk' => 'private',
                'original_name' => 'demo-free.txt',
                'mime' => 'text/plain',
                'size_bytes' => filesize($full),
                'sha256' => hash_file('sha256', $full),
                'is_primary' => true,
                'is_active' => true,
            ]
        );

        $this->seedPreviews($product, 3, 'نمونه رایگان');

        $paidPath = 'store/demo/demo-paid.txt';
        $paidFull = storage_path('app/private/'.$paidPath);
        if (! is_file($paidFull)) {
            file_put_contents($paidFull, "فایل آزمایشی پولی فروشگاه فراست\n");
        }
        $paid = StoreProduct::updateOrCreate(
            ['slug' => 'demo-paid'],
            [
                'category_id' => $books->id,
                'title' => 'فایل آزمایشی پولی (۳۵ هزار تومان)',
                'sku' => 'DEMO-PAID-001',
                'type' => 'digital_file',
                'short_description' => 'برای تست سبد، تخفیف و پرداخت کیف‌پول',
                'description' => 'قیمت نمایشی ۳۵۰٬۰۰۰ ریال (۳۵ هزار تومان). پیش‌نمایش چند صفحه دارد.',
                'price_rials' => 350000,
                'compare_at_price_rials' => 450000,
                'status' => 'published',
                'published_at' => now(),
                'featured' => true,
                'sort_order' => 2,
                'preview_pages' => 3,
                'license_type' => 'شخصی',
                'version' => '1.0',
            ]
        );
        StoreProductFile::updateOrCreate(
            ['product_id' => $paid->id, 'path' => $paidPath],
            [
                'version' => '1.0',
                'disk' => 'private',
                'original_name' => 'demo-paid.txt',
                'mime' => 'text/plain',
                'size_bytes' => filesize($paidFull),
                'sha256' => hash_file('sha256', $paidFull),
                'is_primary' => true,
                'is_active' => true,
            ]
        );

        $this->seedPreviews($paid, 3, 'نمونه پولی');

        StoreCoupon::updateOrCreate(
            ['code' => 'FARAST10'],
            [
                'type' => 'percent',
                'value' => 10,
                'min_subtotal_rials' => 0,
                'max_uses' => 1000,
                'is_active' => true,
                'description' => '۱۰٪ تخفیف آزمایشی',
            ]
        );
    }

    private function seedPreviews(StoreProduct $product, int $pages, string $label): void
    {
        $previewDir = storage_path('app/private/store/previews/'.$product->id);
        File::ensureDirectoryExists($previewDir);

        for ($i = 1; $i <= $pages; $i++) {
            $rel = 'store/previews/'.$product->id.'/page-'.$i.'.svg';
            $full = storage_path('app/private/'.$rel);
            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800" viewBox="0 0 600 800">
  <rect width="600" height="800" fill="#f7fafc"/>
  <rect x="40" y="40" width="520" height="720" rx="12" fill="#fff" stroke="#dbe4f0"/>
  <text x="300" y="120" text-anchor="middle" font-family="Tahoma,Arial" font-size="28" fill="#1a2b45">{$label}</text>
  <text x="300" y="170" text-anchor="middle" font-family="Tahoma,Arial" font-size="18" fill="#6b7c93">صفحه پیش‌نمایش {$i}</text>
  <text x="80" y="260" font-family="Tahoma,Arial" font-size="16" fill="#52667e">این یک صفحه نمونه است تا خوانشگر</text>
  <text x="80" y="290" font-family="Tahoma,Arial" font-size="16" fill="#52667e">فروشگاه فراست را قبل از خرید ببینید.</text>
  <text x="80" y="340" font-family="Tahoma,Arial" font-size="15" fill="#8a9bb0">محصول: {$product->title}</text>
</svg>
SVG;
            file_put_contents($full, $svg);

            StoreProductPreview::updateOrCreate(
                ['product_id' => $product->id, 'path' => $rel],
                [
                    'disk' => 'private',
                    'mime' => 'image/svg+xml',
                    'page_number' => $i,
                    'kind' => 'image',
                    'watermarked' => true,
                    'is_active' => true,
                    'sort_order' => $i,
                ]
            );
        }
    }
}
