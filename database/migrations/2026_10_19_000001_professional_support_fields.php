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

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'ticket_code')) {
                $table->string('ticket_code', 32)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('tickets', 'assigned_to')) {
                $table->unsignedBigInteger('assigned_to')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('tickets', 'first_response_at')) {
                $table->timestamp('first_response_at')->nullable()->after('last_reply_at');
            }
            if (! Schema::hasColumn('tickets', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('first_response_at');
            }
            if (! Schema::hasColumn('tickets', 'customer_last_seen_at')) {
                $table->timestamp('customer_last_seen_at')->nullable()->after('closed_at');
            }
        });

        if (Schema::hasTable('ticket_messages') && ! Schema::hasColumn('ticket_messages', 'is_staff')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->boolean('is_staff')->default(false)->after('is_internal');
            });
        }

        if (Schema::hasTable('ticket_messages') && ! Schema::hasColumn('ticket_messages', 'attachments')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->json('attachments')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['ticket_code', 'assigned_to', 'first_response_at', 'closed_at', 'customer_last_seen_at'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
