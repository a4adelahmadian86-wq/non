<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('farast_workflow_events')) {
            Schema::create('farast_workflow_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 60);
                $table->string('idempotency_key', 190)->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->json('context')->nullable();
                $table->unsignedInteger('matched_workflows')->default(0);
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['event_type', 'created_at']);
                $table->index(['user_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_workflow_events');
    }
};
