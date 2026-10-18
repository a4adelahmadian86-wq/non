<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'guest_name')) {
                $table->string('guest_name')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('tickets', 'guest_email')) {
                $table->string('guest_email')->nullable()->after('guest_name');
            }
            if (! Schema::hasColumn('tickets', 'department')) {
                $table->string('department')->default('support')->after('priority');
            }
            if (! Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->timestamp('last_reply_at')->nullable()->after('updated_at');
            }
            if (! Schema::hasColumn('tickets', 'source')) {
                $table->string('source')->default('dashboard')->after('department'); // dashboard|widget|email
            }
        });

        // Allow nullable user_id for true guest tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_messages', 'attachments')) {
                $table->json('attachments')->nullable()->after('body');
            }
            if (! Schema::hasColumn('ticket_messages', 'is_staff')) {
                $table->boolean('is_staff')->default(false)->after('is_internal');
            }
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $cols = ['guest_name', 'guest_email', 'department', 'last_reply_at', 'source'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_messages', 'attachments')) {
                $table->dropColumn('attachments');
            }
            if (Schema::hasColumn('ticket_messages', 'is_staff')) {
                $table->dropColumn('is_staff');
            }
        });
    }
};
