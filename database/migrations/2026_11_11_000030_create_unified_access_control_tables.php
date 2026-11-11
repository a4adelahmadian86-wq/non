<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'access_control_managed')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('access_control_managed')->default(false);
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('name', 160);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_assignments')) {
            Schema::create('role_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->restrictOnDelete();
                $table->string('scope', 24)->default('own');
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('assigned_by')->nullable()->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
                $table->index(['role_id', 'scope']);
                $table->index(['organization_id', 'team_id']);
            });
        }

        if (! Schema::hasTable('user_permission_grants')) {
            Schema::create('user_permission_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->string('effect', 8);
                $table->string('scope', 24)->default('own');
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('granted_by')->nullable()->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'permission_id', 'effect']);
                $table->index(['organization_id', 'team_id']);
            });
        }

        if (! Schema::hasTable('access_audit_logs')) {
            Schema::create('access_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('subject_id')->nullable()->index();
                $table->string('event', 100);
                $table->string('subject_type', 120)->nullable();
                $table->unsignedBigInteger('subject_record_id')->nullable();
                $table->json('context')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['subject_type', 'subject_record_id']);
                $table->index(['event', 'created_at']);
            });
        }

        DB::table('roles')->insertOrIgnore([
            ['key' => 'admin', 'name' => 'مدیر کل', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'user', 'name' => 'کاربر', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'employee', 'name' => 'کارمند', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'organization_manager', 'name' => 'مدیر سازمان', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'team_supervisor', 'name' => 'سرپرست تیم', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'observer', 'name' => 'ناظر', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'guest', 'name' => 'مهمان', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'access_control_managed')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('access_control_managed');
            });
        }

        Schema::dropIfExists('access_audit_logs');
        Schema::dropIfExists('user_permission_grants');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('roles');
    }
};
