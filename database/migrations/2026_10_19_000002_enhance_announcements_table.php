<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'audience')) {
                $table->string('audience', 30)->default('all')->after('type');
            }
            if (! Schema::hasColumn('announcements', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('announcements', 'notify_in_app')) {
                $table->boolean('notify_in_app')->default(true)->after('is_pinned');
            }
            if (! Schema::hasColumn('announcements', 'notify_email')) {
                $table->boolean('notify_email')->default(false)->after('notify_in_app');
            }
            if (! Schema::hasColumn('announcements', 'link_url')) {
                $table->string('link_url', 500)->nullable()->after('body');
            }
            if (! Schema::hasColumn('announcements', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('ends_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            foreach (['audience', 'is_pinned', 'notify_in_app', 'notify_email', 'link_url', 'notified_at'] as $col) {
                if (Schema::hasColumn('announcements', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
