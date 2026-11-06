<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_customer_notes')) {
            Schema::create('crm_customer_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('note');
                $table->string('visibility', 20)->default('internal');
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('crm_follow_up_tasks')) {
            Schema::create('crm_follow_up_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('open');
                $table->string('priority', 20)->default('normal');
                $table->timestamp('due_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['customer_id', 'status', 'due_at']);
                $table->index(['assigned_to', 'status', 'due_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_follow_up_tasks');
        Schema::dropIfExists('crm_customer_notes');
    }
};
