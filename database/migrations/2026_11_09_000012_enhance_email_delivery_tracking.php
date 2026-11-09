<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->timestamp('queued_at')->nullable()->after('sent_at');
            $table->timestamp('last_attempt_at')->nullable()->after('queued_at');
            $table->timestamp('accepted_at')->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'queued_at', 'last_attempt_at', 'accepted_at']);
        });
    }
};
