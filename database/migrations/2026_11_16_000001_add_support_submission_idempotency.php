<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('tickets', 'submission_scope')) {
                $table->char('submission_scope', 64)->nullable();
            }
            if (! Schema::hasColumn('tickets', 'submission_key')) {
                $table->uuid('submission_key')->nullable();
            }
            if (! Schema::hasColumn('tickets', 'submission_fingerprint')) {
                $table->char('submission_fingerprint', 64)->nullable();
            }
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('ticket_messages', 'submission_scope')) {
                $table->char('submission_scope', 64)->nullable();
            }
            if (! Schema::hasColumn('ticket_messages', 'submission_key')) {
                $table->uuid('submission_key')->nullable();
            }
            if (! Schema::hasColumn('ticket_messages', 'submission_fingerprint')) {
                $table->char('submission_fingerprint', 64)->nullable();
            }
        });

        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasIndex('tickets', 'tickets_submission_scope_key_unique')) {
                $table->unique(['submission_scope', 'submission_key'], 'tickets_submission_scope_key_unique');
            }
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            if (! Schema::hasIndex('ticket_messages', 'ticket_messages_submission_unique')) {
                $table->unique(['submission_scope', 'submission_key'], 'ticket_messages_submission_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table): void {
            if (Schema::hasIndex('ticket_messages', 'ticket_messages_submission_unique')) {
                $table->dropUnique('ticket_messages_submission_unique');
            }
            foreach (['submission_fingerprint', 'submission_key', 'submission_scope'] as $column) {
                if (Schema::hasColumn('ticket_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tickets', function (Blueprint $table): void {
            if (Schema::hasIndex('tickets', 'tickets_submission_scope_key_unique')) {
                $table->dropUnique('tickets_submission_scope_key_unique');
            }
            foreach (['submission_fingerprint', 'submission_key', 'submission_scope'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
