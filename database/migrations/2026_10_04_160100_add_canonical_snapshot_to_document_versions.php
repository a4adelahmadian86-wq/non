<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_document_versions') && !Schema::hasColumn('farast_document_versions', 'content_json')) {
            Schema::table('farast_document_versions', function (Blueprint $table) {
                $table->json('content_json')->nullable()->after('content');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('farast_document_versions') && Schema::hasColumn('farast_document_versions', 'content_json')) {
            Schema::table('farast_document_versions', fn (Blueprint $table) => $table->dropColumn('content_json'));
        }
    }
};
