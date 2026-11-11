<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_volume_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_volume_id')->constrained('storage_volumes')->restrictOnDelete();
            $table->uuid('reservation_key')->unique();
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedInteger('reserved_objects')->default(1);
            $table->string('purpose', 40);
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['storage_volume_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_volume_reservations');
    }
};
