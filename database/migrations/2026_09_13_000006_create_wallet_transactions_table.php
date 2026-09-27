<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id(); $t->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20); $t->unsignedBigInteger('amount_rials');
            $t->unsignedBigInteger('balance_before'); $t->unsignedBigInteger('balance_after');
            $t->string('reference_type')->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('description', 500)->nullable(); $t->string('idempotency_key', 120)->nullable()->unique(); $t->timestamps();
            $t->index(['reference_type','reference_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('wallet_transactions'); }
};
