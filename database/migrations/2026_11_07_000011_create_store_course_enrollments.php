<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('store_courses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('library_item_id')->unique()->constrained('store_library_items')->cascadeOnDelete();
            $table->foreignId('last_lesson_id')->nullable()->constrained('store_course_lessons')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'course_id', 'expires_at']);
        });

        Schema::create('store_course_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('store_course_enrollments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('store_course_lessons')->cascadeOnDelete();
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_course_lesson_progress');
        Schema::dropIfExists('store_course_enrollments');
    }
};
