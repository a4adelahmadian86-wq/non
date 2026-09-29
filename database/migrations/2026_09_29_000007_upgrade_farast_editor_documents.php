<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_documents')) {
            Schema::table('farast_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('farast_documents', 'content_json')) {
                    $table->json('content_json')->nullable()->after('content');
                }
                if (! Schema::hasColumn('farast_documents', 'document_format')) {
                    $table->string('document_format', 30)->default('farast-v1')->after('content_json');
                }
                if (! Schema::hasColumn('farast_documents', 'page_settings')) {
                    $table->json('page_settings')->nullable()->after('document_format');
                }
                if (! Schema::hasColumn('farast_documents', 'revision')) {
                    $table->unsignedBigInteger('revision')->default(0)->after('page_settings');
                }
                if (! Schema::hasColumn('farast_documents', 'last_saved_at')) {
                    $table->timestamp('last_saved_at')->nullable()->after('revision');
                }
            });
        }

        if (Schema::hasTable('typing_documents') && ! Schema::hasColumn('typing_documents', 'farast_document_id')) {
            Schema::table('typing_documents', function (Blueprint $table) {
                $table->foreignId('farast_document_id')->nullable()->after('id')->constrained('farast_documents')->nullOnDelete();
                $table->index(['user_id', 'farast_document_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('typing_documents') && Schema::hasColumn('typing_documents', 'farast_document_id')) {
            Schema::table('typing_documents', function (Blueprint $table) {
                $table->dropForeign(['farast_document_id']);
                $table->dropIndex(['user_id', 'farast_document_id']);
                $table->dropColumn('farast_document_id');
            });
        }

        if (Schema::hasTable('farast_documents')) {
            Schema::table('farast_documents', function (Blueprint $table) {
                foreach (['content_json', 'document_format', 'page_settings', 'revision', 'last_saved_at'] as $column) {
                    if (Schema::hasColumn('farast_documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
