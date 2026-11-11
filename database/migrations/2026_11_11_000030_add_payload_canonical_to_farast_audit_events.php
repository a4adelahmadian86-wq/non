<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_audit_events') && ! Schema::hasColumn('farast_audit_events', 'payload_canonical')) {
            Schema::table('farast_audit_events', function (Blueprint $table): void {
                $table->longText('payload_canonical')->nullable()->after('payload_meta');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('farast_audit_events') && Schema::hasColumn('farast_audit_events', 'payload_canonical')) {
            Schema::table('farast_audit_events', function (Blueprint $table): void {
                $table->dropColumn('payload_canonical');
            });
        }
    }
};
