<?php
use App\Services\AuthorizationService;
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
 public function up(): void {app(AuthorizationService::class)->syncDefaults();}
 public function down(): void {}
};