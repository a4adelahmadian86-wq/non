<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Permission::firstOrCreate(
            ['key' => 'store.security_scan'],
            ['label' => 'درخواست اسکن آنتی‌ویروس فایل محصول', 'group' => 'store']
        );

        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->boolean('antivirus_requested')->default(false);
            $table->string('antivirus_status', 24)->nullable();
            $table->string('antivirus_engine', 40)->nullable();
            $table->string('antivirus_version', 120)->nullable();
            $table->timestamp('antivirus_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Permission::query()->where('key', 'store.security_scan')->delete();

        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropColumn([
                'antivirus_requested',
                'antivirus_status',
                'antivirus_engine',
                'antivirus_version',
                'antivirus_scanned_at',
            ]);
        });
    }
};
