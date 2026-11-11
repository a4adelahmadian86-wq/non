<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = 'farast_storage_objects';
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('farast_storage_objects must exist before its path identity can be reconciled.');
        }

        if (! Schema::hasColumn($table, 'path_hash')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->char('path_hash', 64)->nullable());
        }

        DB::table($table)
            ->whereNull('path_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['path_hash' => hash('sha256', (string) $row->path)]);
                }
            });

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->char('path_hash', 64)->nullable(false)->change());

        $indexes = Schema::getIndexes($table);
        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['disk', 'path'] && ! ($index['primary'] ?? false)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($index['name']));
            }
        }

        $indexes = Schema::getIndexes($table);
        $hasPathHashUnique = collect($indexes)->contains(
            fn (array $index): bool => ($index['name'] ?? null) === 'farast_storage_objects_disk_path_hash_unique'
                || (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['disk', 'path_hash'])
        );

        if (! $hasPathHashUnique) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(
                ['disk', 'path_hash'],
                'farast_storage_objects_disk_path_hash_unique'
            ));
        }
    }

    public function down(): void
    {
        // Keep path hashes and their uniqueness constraint: they protect the
        // identity of durable storage objects and may already be in use.
    }
};
