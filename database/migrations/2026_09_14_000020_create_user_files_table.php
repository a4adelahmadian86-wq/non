<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('disk', 40)->default('private');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('page_count')->default(1);
            $table->unsignedBigInteger('estimated_price_rials')->default(0);
            $table->string('status', 30)->default('local');
            $table->string('remote_path')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('local_expires_at')->nullable();
            $table->timestamp('remote_expires_at')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('local_expires_at');
            $table->index('remote_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_files');
    }
};
