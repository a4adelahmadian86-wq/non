<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('free_credits', function (Blueprint $t) {
            $t->id(); $t->string('mobile_hash', 64); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('week_start'); $t->unsignedInteger('granted_pages')->default(1); $t->unsignedInteger('consumed_pages')->default(0); $t->timestamps();
            $t->unique(['mobile_hash','week_start']); $t->index(['user_id','week_start']);
        });
    }
    public function down(): void { Schema::dropIfExists('free_credits'); }
};
