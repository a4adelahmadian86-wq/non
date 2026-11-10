<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->string('file_key_hash', 64)->nullable()->after('file_key');
        });

        DB::table('storage_managed_files')
            ->select(['id', 'file_key'])
            ->orderBy('id')
            ->chunkById(500, function ($files): void {
                foreach ($files as $file) {
                    DB::table('storage_managed_files')
                        ->where('id', $file->id)
                        ->update(['file_key_hash' => hash('sha256', (string) $file->file_key)]);
                }
            });

        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->unique('file_key_hash', 'storage_managed_files_file_key_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->dropUnique('storage_managed_files_file_key_hash_unique');
            $table->dropColumn('file_key_hash');
        });
    }
};
