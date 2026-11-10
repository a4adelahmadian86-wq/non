<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('farast_roles')) {
            Schema::create('farast_roles', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('scope_type', 24)->default('global');
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['scope_type', 'scope_id', 'is_active'], 'farast_roles_scope_active_idx');
            });
        }

        if (! Schema::hasTable('farast_role_permissions')) {
            Schema::create('farast_role_permissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('role_id')->constrained('farast_roles')->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->string('scope_type', 24)->default('own');
                $table->json('conditions')->nullable();
                $table->timestamps();
                $table->unique(['role_id', 'permission_id'], 'farast_role_permission_unique');
                $table->index(['permission_id', 'scope_type'], 'farast_role_permission_lookup_idx');
            });
        }

        if (! Schema::hasTable('farast_user_roles')) {
            Schema::create('farast_user_roles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('farast_roles')->cascadeOnDelete();
                $table->string('scope_type', 24)->default('own');
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at'], 'farast_user_roles_active_idx');
                $table->index(['role_id', 'scope_type', 'scope_id'], 'farast_user_roles_scope_idx');
            });
        }

        if (! Schema::hasTable('farast_user_permission_overrides')) {
            Schema::create('farast_user_permission_overrides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->string('effect', 8);
                $table->string('scope_type', 24)->default('own');
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->json('conditions')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'permission_id', 'effect'], 'farast_user_permission_override_idx');
                $table->index(['expires_at', 'starts_at'], 'farast_user_permission_expiry_idx');
            });
        }

        if (! Schema::hasTable('farast_access_audit_logs')) {
            Schema::create('farast_access_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->unsignedBigInteger('subject_user_id')->nullable();
                $table->unsignedBigInteger('role_id')->nullable();
                $table->unsignedBigInteger('permission_id')->nullable();
                $table->string('action', 100);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['subject_user_id', 'created_at'], 'farast_access_audit_subject_idx');
                $table->index(['actor_id', 'created_at'], 'farast_access_audit_actor_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: these migrations may adopt pre-existing
        // tables/columns and contain live access, automation, or audit data.
        // Schema retirement must be an explicit, separately reviewed operation.
    }
};
