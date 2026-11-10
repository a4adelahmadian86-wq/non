<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_account_sessions') && !Schema::hasColumn('farast_account_sessions', 'device_key_hash')) {
            Schema::table('farast_account_sessions', function (Blueprint $table) {
                $table->char('device_key_hash', 64)->nullable()->index()->after('session_key');
            });
        }

        if (!Schema::hasTable('farast_blocked_devices')) {
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
        Schema::dropIfExists('farast_blocked_devices');

        if (Schema::hasTable('farast_account_sessions') && Schema::hasColumn('farast_account_sessions', 'device_key_hash')) {
            Schema::table('farast_account_sessions', function (Blueprint $table) {
                $table->dropColumn('device_key_hash');
            });
        }
    }
};
