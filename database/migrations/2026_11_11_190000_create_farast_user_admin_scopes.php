<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_user_admin_scopes')) {
            return;
        }

        Schema::create('farast_user_admin_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('reason', 500);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id'], 'farast_admin_scope_type_id_index');
            $table->index('expires_at', 'farast_admin_scope_expires_at_index');
        });
    }

    public function down(): void
    {
        // Keep administrator scope history and restrictions on rollback.
    }
};
