<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(
                ['assigned_to', 'user_id', 'status'],
                'tickets_assignment_load_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_assignment_load_idx');
        });
    }
};
