<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Repair partially migrated databases without dropping existing security data.
        if (! Schema::hasTable('farast_account_sessions')) {
            Schema::create('farast_account_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->char('session_key', 64)->unique();
                $table->char('device_key_hash', 64)->nullable()->index();
                $table->text('session_id_encrypted');
                $table->string('device_label', 120)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->dateTime('last_activity')->index();
                $table->dateTime('revoked_at')->nullable()->index();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at', 'last_activity'], 'farast_account_sessions_user_active_idx');
            });
        } elseif (! Schema::hasColumn('farast_account_sessions', 'device_key_hash')) {
            Schema::table('farast_account_sessions', function (Blueprint $table) {
                $table->char('device_key_hash', 64)->nullable()->index()->after('session_key');
            });
        }

        if (! Schema::hasTable('farast_security_events')) {
            Schema::create('farast_security_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event', 100);
                $table->string('ip', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at'], 'farast_security_events_user_time_idx');
            });
        }

        if (! Schema::hasTable('farast_blocked_devices')) {
            Schema::create('farast_blocked_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->char('device_key_hash', 64);
                $table->string('device_label', 120)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->dateTime('blocked_at');
                $table->dateTime('unblocked_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'device_key_hash'], 'farast_blocked_device_unique');
                $table->index(['user_id', 'unblocked_at']);
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: repaired security tables may contain account history.
    }
};
