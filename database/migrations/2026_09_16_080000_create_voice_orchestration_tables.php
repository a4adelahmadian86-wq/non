<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('name', 120);
            $table->string('model', 120)->nullable();
            $table->text('credentials')->nullable();
            $table->json('capabilities')->nullable();
            $table->enum('billing_mode', ['trial', 'monthly_free', 'paid', 'emergency'])->default('monthly_free');
            $table->unsignedBigInteger('quota_limit_seconds')->nullable();
            $table->unsignedBigInteger('quota_used_seconds')->default(0);
            $table->timestamp('quota_period_started_at')->nullable();
            $table->timestamp('quota_period_ends_at')->nullable();
            $table->decimal('credit_remaining', 14, 4)->nullable();
            $table->timestamp('credit_expires_at')->nullable();
            $table->unsignedInteger('quality_score')->default(50);
            $table->unsignedInteger('reliability_score')->default(50);
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('healthy')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['provider', 'enabled', 'healthy']);
            $table->index(['billing_mode', 'credit_expires_at']);
            $table->index(['quota_period_ends_at']);
        });

        Schema::create('voice_provider_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_provider_account_id')->constrained('voice_provider_accounts')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('audio_seconds')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('successful_requests')->default(0);
            $table->unsignedInteger('failed_requests')->default(0);
            $table->unsignedBigInteger('input_bytes')->default(0);
            $table->unsignedBigInteger('output_bytes')->default(0);
            $table->timestamps();
            $table->unique(['voice_provider_account_id', 'usage_date']);
        });

        Schema::create('voice_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 20)->default('fa-IR');
            $table->string('provider', 60)->nullable();
            $table->text('wrong_text');
            $table->text('correct_text');
            $table->string('context_hash', 64)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('confirmations')->default(0);
            $table->decimal('confidence', 5, 4)->default(0.1000);
            $table->boolean('verified')->default(false);
            $table->boolean('global')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['locale', 'provider', 'verified']);
            $table->index(['global', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_corrections');
        Schema::dropIfExists('voice_provider_usage');
        Schema::dropIfExists('voice_provider_accounts');
    }
};
