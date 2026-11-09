<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('content_discussions')) {
            return;
        }
        if (! Schema::hasColumn('content_discussions', 'anchor_section')) {
            Schema::table('content_discussions', function (Blueprint $table) {
                $table->unsignedInteger('anchor_section')->nullable()->after('anchor_page');
            });
        }
        Schema::table('content_discussions', function (Blueprint $table) {
            $table->index(['target_type', 'target_key', 'anchor_section', 'status'], 'content_discussions_reader_section_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_discussions')) {
            return;
        }
        Schema::table('content_discussions', function (Blueprint $table) {
            $table->dropIndex('content_discussions_reader_section_idx');
            if (Schema::hasColumn('content_discussions', 'anchor_section')) {
                $table->dropColumn('anchor_section');
            }
        });
    }
};
