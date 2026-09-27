<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('gateway', 40); $t->string('authority', 160)->nullable(); $t->string('transaction_id', 160)->nullable();
            $t->unsignedBigInteger('amount_rials'); $t->string('status', 20)->default('pending');
            $t->json('gateway_response')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamps();
            $t->index(['gateway','authority']); $t->index('transaction_id');
        });
    }
    public function down(): void { Schema::dropIfExists('payments'); }
};
