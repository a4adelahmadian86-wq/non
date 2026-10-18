<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'topic_area')) {
                $table->string('topic_area')->nullable()->after('department'); // services | store
            }
            if (! Schema::hasColumn('tickets', 'related_service')) {
                $table->string('related_service')->nullable()->after('topic_area');
            }
            if (! Schema::hasColumn('tickets', 'related_product_id')) {
                $table->unsignedBigInteger('related_product_id')->nullable()->after('related_service');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['topic_area', 'related_service', 'related_product_id'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
