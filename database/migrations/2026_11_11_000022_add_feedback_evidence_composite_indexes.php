<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'farast_feedback_evidence';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $requiredIndexes = [
            'farast_feedback_evidence_project_status_index' => ['project_id', 'status'],
            'farast_feedback_evidence_tool_version_status_index' => ['tool_id', 'tool_version_id', 'status'],
        ];

        $existing = Schema::getIndexes(self::TABLE);
        $existingColumns = array_map(
            static fn (array $index): array => $index['columns'] ?? [],
            $existing
        );

        $missing = [];
        foreach ($requiredIndexes as $name => $columns) {
            $allColumnsExist = collect($columns)->every(
                static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
            );

            if ($allColumnsExist && ! in_array($columns, $existingColumns, true)) {
                $missing[$name] = $columns;
            }
        }

        if ($missing === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
            foreach ($missing as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $names = [
            'farast_feedback_evidence_project_status_index',
            'farast_feedback_evidence_tool_version_status_index',
        ];
        $existingNames = array_column(Schema::getIndexes(self::TABLE), 'name');

        foreach ($names as $name) {
            if (in_array($name, $existingNames, true)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }
};
