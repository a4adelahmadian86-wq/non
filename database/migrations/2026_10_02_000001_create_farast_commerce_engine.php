<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_entitlements')) {
            Schema::table('farast_entitlements', function (Blueprint $table) {
                if (!Schema::hasColumn('farast_entitlements', 'organization_id')) $table->unsignedBigInteger('organization_id')->nullable()->index();
                if (!Schema::hasColumn('farast_entitlements', 'unit')) $table->string('unit', 40)->nullable();
                if (!Schema::hasColumn('farast_entitlements', 'priority')) $table->unsignedInteger('priority')->default(0);
                if (!Schema::hasColumn('farast_entitlements', 'source_type')) $table->string('source_type', 80)->nullable();
                if (!Schema::hasColumn('farast_entitlements', 'source_id')) $table->string('source_id', 120)->nullable();
            });
        }

        if (!Schema::hasTable('farast_pricing_policy_versions')) {
            Schema::create('farast_pricing_policy_versions', function (Blueprint $table) {
                $table->id();
                $table->string('policy_code', 120);
                $table->unsignedInteger('version');
                $table->string('capability_code', 120)->nullable()->index();
                $table->string('unit', 40);
                $table->string('currency', 8)->default('IRR');
                $table->unsignedBigInteger('unit_price')->default(0);
                $table->unsignedBigInteger('additional_unit_price')->nullable();
                $table->unsignedBigInteger('fee_amount')->default(0);
                $table->unsignedInteger('discount_basis_points')->default(0);
                $table->unsignedInteger('tax_basis_points')->default(0);
                $table->unsignedInteger('payg_multiplier_basis_points')->default(10000);
                $table->decimal('included_quantity', 20, 6)->default(0);
                $table->string('region_code', 32)->nullable();
                $table->timestamp('effective_from')->nullable();
                $table->timestamp('effective_until')->nullable();
                $table->json('metadata')->nullable();
                $table->string('checksum', 64);
                $table->timestamps();
                $table->unique(['policy_code', 'version']);
                $table->index(['capability_code', 'active' => false]);
            });
        }

        if (Schema::hasTable('farast_usage_events') === false) {
            Schema::create('farast_usage_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->unsignedBigInteger('tool_id')->nullable()->index();
                $table->unsignedBigInteger('tool_version_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->decimal('quantity', 20, 6);
                $table->string('unit', 40);
                $table->timestamp('occurred_at')->index();
                $table->unsignedBigInteger('entitlement_id')->nullable()->index();
                $table->unsignedBigInteger('pricing_policy_version_id')->nullable()->index();
                $table->json('cost_metadata')->nullable();
                $table->json('metadata')->nullable();
                $table->string('checksum', 64);
                $table->timestamps();
                $table->index(['actor_id', 'capability', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('farast_usage_reservations')) {
            Schema::create('farast_usage_reservations', function (Blueprint $table) {
                $table->id();
                $table->uuid('reservation_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->unsignedBigInteger('entitlement_id')->nullable()->index();
                $table->unsignedBigInteger('pricing_policy_version_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->decimal('quantity', 20, 6);
                $table->string('unit', 40);
                $table->string('mode', 50);
                $table->string('status', 30)->default('reserved')->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('committed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->unsignedBigInteger('usage_event_id')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('farast_charges')) {
            Schema::create('farast_charges', function (Blueprint $table) {
                $table->id();
                $table->uuid('charge_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('usage_event_id')->nullable()->index();
                $table->unsignedBigInteger('reservation_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->decimal('quantity', 20, 6);
                $table->string('unit', 40);
                $table->unsignedBigInteger('unit_price')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('discount')->default(0);
                $table->unsignedBigInteger('fee')->default(0);
                $table->unsignedBigInteger('tax')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->unsignedBigInteger('pricing_policy_version_id')->nullable()->index();
                $table->json('snapshot')->nullable();
                $table->timestamps();
                $table->index(['actor_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('farast_invoices')) {
            Schema::create('farast_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_number', 80)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('currency', 8)->default('IRR');
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('discount')->default(0);
                $table->unsignedBigInteger('fee')->default(0);
                $table->unsignedBigInteger('tax')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->string('status', 30)->default('issued')->index();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('farast_invoice_items')) {
            Schema::create('farast_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('charge_id')->nullable()->index();
                $table->string('description', 255);
                $table->decimal('quantity', 20, 6);
                $table->string('unit', 40);
                $table->unsignedBigInteger('unit_price')->default(0);
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('discount')->default(0);
                $table->unsignedBigInteger('fee')->default(0);
                $table->unsignedBigInteger('tax')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->json('snapshot')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('farast_refunds')) {
            Schema::create('farast_refunds', function (Blueprint $table) {
                $table->id();
                $table->uuid('refund_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('charge_id')->index();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->string('status', 30)->default('pending')->index();
                $table->string('reason', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('farast_cost_events')) {
            Schema::create('farast_cost_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('tool_id')->nullable()->index();
                $table->unsignedBigInteger('tool_version_id')->nullable()->index();
                $table->string('capability', 120)->index();
                $table->string('provider', 120)->nullable();
                $table->string('unit', 40);
                $table->decimal('quantity', 20, 6);
                $table->unsignedBigInteger('cost_amount')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('input_bytes')->nullable();
                $table->unsignedBigInteger('output_bytes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('farast_wallet_reservations')) {
            Schema::create('farast_wallet_reservations', function (Blueprint $table) {
                $table->id();
                $table->uuid('reservation_id')->unique();
                $table->string('idempotency_key', 180)->unique();
                $table->unsignedBigInteger('wallet_id')->index();
                $table->unsignedBigInteger('charge_id')->nullable()->index();
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('currency', 8)->default('IRR');
                $table->string('status', 30)->default('reserved')->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('committed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('farast_pricing_policies')) {
            $rows = DB::table('farast_pricing_policies')->where('active', true)->get();
            foreach ($rows as $row) {
                $exists = DB::table('farast_pricing_policy_versions')
                    ->where('policy_code', $row->code)->where('version', 1)->exists();
                if (!$exists) {
                    $payload = [
                        'policy_code' => $row->code,
                        'version' => 1,
                        'capability_code' => $row->capability_code,
                        'unit' => $row->unit ?: 'unit',
                        'currency' => 'IRR',
                        'unit_price' => max(0, (int) $row->base_price_rials),
                        'additional_unit_price' => $row->additional_price_rials === null ? null : max(0, (int) $row->additional_price_rials),
                        'fee_amount' => max(0, (int) $row->fee_rials),
                        'discount_basis_points' => max(0, min(10000, (int) round(((float) $row->discount_percent) * 100))),
                        'tax_basis_points' => 0,
                        'payg_multiplier_basis_points' => max(10000, (int) round(((float) ($row->payg_multiplier_percent ?: 100)) * 100)),
                        'included_quantity' => max(0, (float) $row->subscription_allowance),
                        'effective_from' => now(),
                        'metadata' => json_encode((array) $row->metadata, JSON_UNESCAPED_UNICODE),
                        'checksum' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    DB::table('farast_pricing_policy_versions')->insert($payload);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'farast_wallet_reservations','farast_cost_events','farast_refunds','farast_invoice_items',
            'farast_invoices','farast_charges','farast_usage_reservations','farast_usage_events',
            'farast_pricing_policy_versions'
        ] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('farast_entitlements')) {
            Schema::table('farast_entitlements', function (Blueprint $table) {
                foreach (['organization_id','unit','priority','source_type','source_id'] as $column) {
                    if (Schema::hasColumn('farast_entitlements', $column)) $table->dropColumn($column);
                }
            });
        }
    }
};