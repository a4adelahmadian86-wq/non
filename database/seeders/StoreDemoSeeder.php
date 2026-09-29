<?php

namespace Database\Seeders;

use App\Models\StoreCategory;
use App\Models\StoreCoupon;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
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
                'preview_pages' => 1,
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

        $paidPath = 'store/demo/demo-paid.txt';
        $paidFull = storage_path('app/private/'.$paidPath);
        if (! is_file($paidFull)) {
            file_put_contents($paidFull, "فایل آزمایشی پولی فروشگاه فراست\n");
        }
        $paid = StoreProduct::updateOrCreate(
            ['slug' => 'demo-paid'],
            [
                'category_id' => $category->id,
                'title' => 'فایل آزمایشی پولی (۳۵ هزار تومان)',
                'sku' => 'DEMO-PAID-001',
                'type' => 'digital_file',
                'short_description' => 'برای تست سبد، تخفیف و پرداخت کیف‌پول',
                'description' => 'قیمت نمایشی ۳۵۰٬۰۰۰ ریال (۳۵ هزار تومان).',
                'price_rials' => 350000,
                'compare_at_price_rials' => 450000,
                'status' => 'published',
                'published_at' => now(),
                'featured' => true,
                'sort_order' => 2,
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
}
