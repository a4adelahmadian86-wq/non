<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('key', 100)->unique();
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('department_user')) {
            Schema::create('department_user', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('joined_at')->useCurrent();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();
                $table->unique(['department_id', 'user_id']);
                $table->index(['user_id', 'left_at']);
            });
        }

        if (Schema::hasTable('role_assignments')) {
            Schema::table('role_assignments', function (Blueprint $table): void {
                if (! Schema::hasColumn('role_assignments', 'is_primary')) {
                    $table->boolean('is_primary')->default(false)->index();
                }
                if (! Schema::hasColumn('role_assignments', 'title')) {
                    $table->string('title', 160)->nullable();
                }
                if (! Schema::hasColumn('role_assignments', 'ended_at')) {
                    $table->timestamp('ended_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally preserve department membership and responsibility history on rollback.
        // Schema rollback must never erase operational or personnel records.
    }
};
