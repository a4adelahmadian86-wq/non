<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_customer_segments')) {
            Schema::create('crm_customer_segments', function (Blueprint $table) {
                $table->id();
                $table->string('name', 140);
                $table->text('description')->nullable();
                $table->json('filters');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('crm_marketing_campaigns')) {
            Schema::create('crm_marketing_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('channel', 30)->default('in_app');
                $table->string('status', 20)->default('draft');
                $table->foreignId('segment_id')->constrained('crm_customer_segments')->restrictOnDelete();
                $table->string('title', 180);
                $table->text('body');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('crm_campaign_recipients')) {
            Schema::create('crm_campaign_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('crm_marketing_campaigns')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('notification_id')->nullable()->constrained('farast_notifications')->nullOnDelete();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'user_id']);
                $table->index(['campaign_id', 'notified_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaign_recipients');
        Schema::dropIfExists('crm_marketing_campaigns');
        Schema::dropIfExists('crm_customer_segments');
    }
};
