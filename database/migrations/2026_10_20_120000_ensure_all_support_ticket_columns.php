<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * یکجا همه ستون‌های پشتیبانی/SLA را اضافه می‌کند (idempotent).
 * برای دیتابیس‌هایی که migrationهای قبلی ناقص اجرا شده‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $cols = [
                'ticket_code' => fn (Blueprint $t) => $t->string('ticket_code', 32)->nullable(),
                'assigned_to' => fn (Blueprint $t) => $t->unsignedBigInteger('assigned_to')->nullable(),
                'department' => fn (Blueprint $t) => $t->string('department', 40)->nullable(),
                'guest_name' => fn (Blueprint $t) => $t->string('guest_name', 80)->nullable(),
                'guest_email' => fn (Blueprint $t) => $t->string('guest_email', 160)->nullable(),
                'last_reply_at' => fn (Blueprint $t) => $t->timestamp('last_reply_at')->nullable(),
                'first_response_at' => fn (Blueprint $t) => $t->timestamp('first_response_at')->nullable(),
                'closed_at' => fn (Blueprint $t) => $t->timestamp('closed_at')->nullable(),
                'customer_last_seen_at' => fn (Blueprint $t) => $t->timestamp('customer_last_seen_at')->nullable(),
                'sla_first_due_at' => fn (Blueprint $t) => $t->timestamp('sla_first_due_at')->nullable(),
                'sla_resolve_due_at' => fn (Blueprint $t) => $t->timestamp('sla_resolve_due_at')->nullable(),
                'sla_first_warned_at' => fn (Blueprint $t) => $t->timestamp('sla_first_warned_at')->nullable(),
                'sla_resolve_warned_at' => fn (Blueprint $t) => $t->timestamp('sla_resolve_warned_at')->nullable(),
                'csat_score' => fn (Blueprint $t) => $t->unsignedTinyInteger('csat_score')->nullable(),
                'csat_comment' => fn (Blueprint $t) => $t->string('csat_comment', 500)->nullable(),
                'csat_at' => fn (Blueprint $t) => $t->timestamp('csat_at')->nullable(),
                'related_service' => fn (Blueprint $t) => $t->string('related_service', 80)->nullable(),
                'related_product_id' => fn (Blueprint $t) => $t->unsignedBigInteger('related_product_id')->nullable(),
                'topic' => fn (Blueprint $t) => $t->string('topic', 80)->nullable(),
            ];

            foreach ($cols as $name => $definition) {
                if (! Schema::hasColumn('tickets', $name)) {
                    $definition($table);
                }
            }
        });

        if (Schema::hasTable('ticket_messages')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('ticket_messages', 'is_staff')) {
                    $table->boolean('is_staff')->default(false);
                }
                if (! Schema::hasColumn('ticket_messages', 'attachments')) {
                    $table->json('attachments')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // non-destructive
    }
};
