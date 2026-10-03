<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('farast_commercial_products')) {
            Schema::create('farast_commercial_products', function (Blueprint $table) {
                $table->id();
                $table->string('code', 120)->unique();
                $table->string('name', 180);
                $table->string('capability_code', 120)->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->string('currency', 8)->default('IRR');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('farast_commercial_plans')) {
            Schema::create('farast_commercial_plans', function (Blueprint $table) {
                $table->id();
                $table->string('code', 120)->unique();
                $table->string('name', 180);
                $table->string('product_code', 120)->nullable()->index();
                $table->string('billing_interval', 30)->nullable();
                $table->unsignedBigInteger('price')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->json('quotas')->nullable();
                $table->boolean('allow_payg')->default(true);
                $table->boolean('allow_overage')->default(false);
                $table->boolean('postpaid')->default(false);
                $table->boolean('active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('farast_subscriptions')) {
            Schema::create('farast_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('subscription_id', 80)->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('plan_id')->index();
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('farast_commercial_plan_products')) {
            Schema::create('farast_commercial_plan_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('plan_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedInteger('priority')->default(0);
                $table->unique(['plan_id','product_id']);
            });
        }
        if (!Schema::hasTable('farast_usage_counters')) {
            Schema::create('farast_usage_counters', function (Blueprint $table) {
                $table->id();
                $table->string('scope_key', 220);
                $table->string('capability', 120);
                $table->string('unit', 40);
                $table->decimal('quantity', 20, 6)->default(0);
                $table->timestamp('period_start')->nullable();
                $table->timestamp('period_end')->nullable();
                $table->timestamps();
                $table->unique(['scope_key','capability','unit','period_start']);
                $table->index(['capability','period_start']);
            });
        }
        if (!Schema::hasTable('farast_price_quotes')) {
            Schema::create('farast_price_quotes', function (Blueprint $table) {
                $table->id();
                $table->uuid('quote_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->decimal('quantity', 20, 6);
                $table->string('unit', 40);
                $table->string('currency', 8);
                $table->unsignedBigInteger('pricing_policy_version_id')->nullable()->index();
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('discount')->default(0);
                $table->unsignedBigInteger('fee')->default(0);
                $table->unsignedBigInteger('tax')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->string('status', 30)->default('valid')->index();
                $table->timestamp('expires_at')->index();
                $table->json('snapshot');
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('farast_output_authorizations')) {
            Schema::create('farast_output_authorizations', function (Blueprint $table) {
                $table->id();
                $table->uuid('authorization_id')->unique();
                $table->unsignedBigInteger('actor_id')->index();
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->string('action', 40);
                $table->string('status', 30)->default('allowed')->index();
                $table->unsignedBigInteger('pricing_policy_version_id')->nullable();
                $table->unsignedBigInteger('charge_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'farast_output_authorizations','farast_price_quotes','farast_usage_counters',
            'farast_commercial_plan_products','farast_subscriptions','farast_commercial_plans',
            'farast_commercial_products'
        ] as $table) Schema::dropIfExists($table);
    }
};
