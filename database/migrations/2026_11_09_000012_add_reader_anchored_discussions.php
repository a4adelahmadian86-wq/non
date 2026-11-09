<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('content_discussions')) return;
        Schema::table('content_discussions', function (Blueprint $table) {
            if (! Schema::hasColumn('content_discussions', 'anchor_page')) $table->unsignedInteger('anchor_page')->nullable()->after('rating');
            if (! Schema::hasColumn('content_discussions', 'anchor_quote')) $table->string('anchor_quote', 500)->nullable()->after('anchor_page');
            if (! Schema::hasColumn('content_discussions', 'anchor_start')) $table->unsignedInteger('anchor_start')->nullable()->after('anchor_quote');
            if (! Schema::hasColumn('content_discussions', 'anchor_end')) $table->unsignedInteger('anchor_end')->nullable()->after('anchor_start');
            if (! Schema::hasColumn('content_discussions', 'anchor_context')) $table->string('anchor_context', 500)->nullable()->after('anchor_end');
        });
        Schema::table('content_discussions', function (Blueprint $table) {
            $table->index(['target_type', 'target_key', 'anchor_page', 'status'], 'content_discussions_reader_anchor_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_discussions')) return;
        Schema::table('content_discussions', function (Blueprint $table) {
            $table->dropIndex('content_discussions_reader_anchor_idx');
            foreach (['anchor_page', 'anchor_quote', 'anchor_start', 'anchor_end', 'anchor_context'] as $column) {
                if (Schema::hasColumn('content_discussions', $column)) $table->dropColumn($column);
            }
        });
    }
};
