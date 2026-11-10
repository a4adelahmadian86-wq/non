<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_marketing_campaigns')) {
            Schema::table('crm_marketing_campaigns', function (Blueprint $table) {
                if (! Schema::hasColumn('crm_marketing_campaigns', 'incentive_product_id')) {
                    $table->foreignId('incentive_product_id')->nullable()->after('segment_id')->constrained('store_products')->nullOnDelete();
                }
                if (! Schema::hasColumn('crm_marketing_campaigns', 'incentive_discount_percent')) {
                    $table->unsignedTinyInteger('incentive_discount_percent')->nullable()->after('incentive_product_id');
                }
                if (! Schema::hasColumn('crm_marketing_campaigns', 'incentive_valid_hours')) {
                    $table->unsignedSmallInteger('incentive_valid_hours')->default(48)->after('incentive_discount_percent');
                }
                if (! Schema::hasColumn('crm_marketing_campaigns', 'budget_cap_rials')) {
                    $table->unsignedBigInteger('budget_cap_rials')->default(0)->after('incentive_valid_hours');
                }
            });
        }

        if (Schema::hasTable('store_coupon_assignments') && ! Schema::hasColumn('store_coupon_assignments', 'campaign_id')) {
            Schema::table('store_coupon_assignments', function (Blueprint $table) {
                $table->foreignId('campaign_id')->nullable()->after('coupon_id')
                    ->constrained('crm_marketing_campaigns')->nullOnDelete();
                $table->index(['campaign_id', 'status', 'expires_at'], 'store_coupon_campaign_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_coupon_assignments') && Schema::hasColumn('store_coupon_assignments', 'campaign_id')) {
            Schema::table('store_coupon_assignments', function (Blueprint $table) {
                $table->dropIndex('store_coupon_campaign_status_idx');
                $table->dropConstrainedForeignId('campaign_id');
            });
        }

        if (Schema::hasTable('crm_marketing_campaigns')) {
            Schema::table('crm_marketing_campaigns', function (Blueprint $table) {
                foreach (['incentive_product_id', 'incentive_discount_percent', 'incentive_valid_hours', 'budget_cap_rials'] as $column) {
                    if (Schema::hasColumn('crm_marketing_campaigns', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
