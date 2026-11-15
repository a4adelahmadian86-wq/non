<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_sla_alert_outbox')
            || Schema::hasColumn('support_sla_alert_outbox', 'lease_token')) {
            return;
        }

        Schema::table('support_sla_alert_outbox', function (Blueprint $table): void {
            $table->string('lease_token', 36)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('support_sla_alert_outbox')
            && Schema::hasColumn('support_sla_alert_outbox', 'lease_token')) {
            Schema::table('support_sla_alert_outbox', function (Blueprint $table): void {
                $table->dropColumn('lease_token');
            });
        }
    }
};
