<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained('typing_documents')->nullOnDelete();
            $t->unsignedBigInteger('subtotal_rials'); $t->unsignedBigInteger('discount_rials')->default(0);
            $t->unsignedBigInteger('tax_rials')->default(0); $t->unsignedBigInteger('total_rials');
            $t->string('status', 20)->default('pending'); $t->json('pricing_snapshot')->nullable();
            $t->unsignedInteger('free_pages_applied')->default(0); $t->timestamp('terms_accepted_at')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamps();
            $t->index(['user_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('orders'); }
};
