<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_sla_alert_outbox')) {
            return;
        }

        Schema::create('support_sla_alert_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('kind', 32);
            $table->string('event', 24);
            $table->integer('minutes_left')->nullable();
            $table->string('event_identity', 191);
            $table->string('idempotency_key', 190)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['ticket_id', 'kind', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sla_alert_outbox');
    }
};
