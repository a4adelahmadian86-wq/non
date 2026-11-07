<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('store_products')->cascadeOnDelete();
            $table->string('subtitle', 220)->nullable();
            $table->string('instructor_name', 160)->nullable();
            $table->string('level', 30)->default('beginner');
            $table->string('language', 12)->default('fa');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('access_duration_days')->nullable();
            $table->unsignedTinyInteger('certificate_min_completion_percent')->default(100);
            $table->boolean('certificate_enabled')->default(false);
            $table->boolean('drip_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('store_course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('store_courses')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('store_course_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('store_course_sections')->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('slug', 220)->unique();
            $table->string('lesson_type', 24)->default('video');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('video_url', 2048)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('drip_after_days')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['section_id', 'is_published', 'sort_order']);
        });

        Schema::table('store_product_files', function (Blueprint $table) {
            $table->foreignId('course_lesson_id')->nullable()->after('product_id')
                ->constrained('store_course_lessons')->nullOnDelete();
            $table->string('purpose', 32)->default('product_download')->after('course_lesson_id');
            $table->string('scan_status', 24)->default('legacy')->after('is_active');
            $table->string('scan_engine', 80)->nullable()->after('scan_status');
            $table->timestamp('scanned_at')->nullable()->after('scan_engine');
            $table->index(['course_lesson_id', 'purpose', 'is_active'], 'store_product_files_lesson_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_files', function (Blueprint $table) {
            $table->dropIndex('store_product_files_lesson_purpose_idx');
            $table->dropConstrainedForeignId('course_lesson_id');
            $table->dropColumn(['purpose', 'scan_status', 'scan_engine', 'scanned_at']);
        });
        Schema::dropIfExists('store_course_lessons');
        Schema::dropIfExists('store_course_sections');
        Schema::dropIfExists('store_courses');
    }
};
