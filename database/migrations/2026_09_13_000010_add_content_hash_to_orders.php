<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('orders', function (Blueprint $t) { $t->string('content_hash', 64)->nullable()->after('pricing_snapshot')->index(); }); }
    public function down(): void { Schema::table('orders', function (Blueprint $t) { $t->dropIndex(['content_hash']); $t->dropColumn('content_hash'); }); }
};
