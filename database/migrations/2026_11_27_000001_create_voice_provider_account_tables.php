<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_provider_accounts')) {
            Schema::create('voice_provider_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 64)->unique();
                $table->string('name', 120)->nullable();
                $table->string('model', 180)->nullable();
                $table->longText('credentials')->nullable();
                $table->json('capabilities')->nullable();
                $table->string('billing_mode', 32)->default('monthly_free');
                $table->decimal('quota_limit_seconds', 14, 3)->nullable();
                $table->decimal('quota_used_seconds', 14, 3)->default(0);
                $table->timestamp('quota_period_started_at')->nullable();
                $table->timestamp('quota_period_ends_at')->nullable();
                $table->decimal('credit_remaining', 14, 4)->nullable();
                $table->timestamp('credit_expires_at')->nullable();
                $table->unsignedTinyInteger('quality_score')->default(50);
                $table->unsignedTinyInteger('reliability_score')->default(50);
                $table->unsignedInteger('priority')->default(100);
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->boolean('enabled')->default(false);
                $table->boolean('healthy')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['enabled', 'healthy', 'priority'], 'voice_provider_accounts_routing_idx');
            });
        }

        if (! Schema::hasTable('voice_provider_usages')) {
            Schema::create('voice_provider_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('voice_provider_account_id')
                    ->constrained('voice_provider_accounts')
                    ->cascadeOnDelete();
                $table->date('usage_date');
                $table->decimal('audio_seconds', 14, 3)->default(0);
                $table->unsignedBigInteger('requests')->default(0);
                $table->unsignedBigInteger('successful_requests')->default(0);
                $table->unsignedBigInteger('failed_requests')->default(0);
                $table->unsignedBigInteger('input_bytes')->default(0);
                $table->unsignedBigInteger('output_bytes')->default(0);
                $table->timestamps();
                $table->unique(
                    ['voice_provider_account_id', 'usage_date'],
                    'voice_provider_usages_account_date_unique'
                );
                $table->index(['usage_date', 'voice_provider_account_id'], 'voice_provider_usages_date_account_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_provider_usages');
        Schema::dropIfExists('voice_provider_accounts');
    }
};
