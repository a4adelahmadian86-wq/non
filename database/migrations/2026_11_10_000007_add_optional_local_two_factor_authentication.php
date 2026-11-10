<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->text('two_factor_secret')->nullable();
                });
            }

            if (! Schema::hasColumn('users', 'two_factor_enabled_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('two_factor_enabled_at')->nullable()->index();
                });
            }
        }

        if (! Schema::hasTable('farast_two_factor_recovery_codes')) {
            Schema::create('farast_two_factor_recovery_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->char('code_hash', 64);
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'used_at'], 'farast_2fa_recovery_user_used_idx');
                $table->unique(['user_id', 'code_hash'], 'farast_2fa_recovery_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_two_factor_recovery_codes');

        if (Schema::hasColumn('users', 'two_factor_enabled_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['two_factor_enabled_at']);
                $table->dropColumn('two_factor_enabled_at');
            });
        }

        if (Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_factor_secret');
            });
        }
    }
};
