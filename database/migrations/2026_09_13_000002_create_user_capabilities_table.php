<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_capabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('active')->nullable();
            $t->boolean('can_type')->nullable();
            $t->boolean('can_ai')->nullable();
            $t->boolean('can_voice')->nullable();
            $t->boolean('can_export_docx')->nullable();
            $t->boolean('can_export_pdf')->nullable();
            $t->boolean('can_feedback')->nullable();
            $t->boolean('can_support')->nullable();
            $t->unsignedInteger('weekly_free_pages')->nullable();
            $t->unsignedInteger('max_file_mb')->nullable();
            $t->unsignedInteger('daily_ai_requests')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
