<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->string('type', 16)->default('direct');
                $table->string('title', 160)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('direct_key', 64)->nullable()->unique();
                $table->text('last_message_preview')->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->timestamps();
                $table->index(['type', 'updated_at']);
            });
        }

        if (! Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 16)->default('member');
                $table->unsignedBigInteger('last_read_message_id')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('muted_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id', 'user_id']);
                $table->index(['user_id', 'archived_at', 'read_at']);
            });
        }

        if (! Schema::hasTable('conversation_messages')) {
            Schema::create('conversation_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('reply_to_id')->nullable();
                $table->longText('body');
                $table->json('attachments')->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
                $table->index(['conversation_id', 'id']);
                $table->index(['user_id', 'created_at']);
                $table->foreign('reply_to_id')->references('id')->on('conversation_messages')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
