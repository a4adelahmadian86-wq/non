<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->boolean('antivirus_requested')->default(false)->after('is_primary');
            $table->string('antivirus_status', 24)->nullable()->after('antivirus_requested');
            $table->timestamp('antivirus_scanned_at')->nullable()->after('antivirus_status');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropColumn(['antivirus_requested', 'antivirus_status', 'antivirus_scanned_at']);
        });
    }
};
