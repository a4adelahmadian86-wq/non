<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_workflow_audit_logs')) {
            return;
        }

        Schema::create('farast_workflow_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['owner_id', 'created_at'], 'farast_workflow_audit_owner_idx');
            $table->index(['workflow_id', 'created_at'], 'farast_workflow_audit_workflow_idx');
            $table->index(['actor_id', 'created_at'], 'farast_workflow_audit_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_workflow_audit_logs');
    }
};
