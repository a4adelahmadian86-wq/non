<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'marketing_consent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('marketing_consent')->default(false)->after('is_blocked');
                $table->timestamp('marketing_consent_updated_at')->nullable()->after('marketing_consent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'marketing_consent_updated_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_consent_updated_at'));
        }
        if (Schema::hasColumn('users', 'marketing_consent')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_consent'));
        }
    }
};
