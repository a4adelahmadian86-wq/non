<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommercialSubscriptionMigrationSafetyTest extends TestCase
{
    private string $previousDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefault = (string) config('database.default');
        config([
            'database.connections.subscription_migration_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('subscription_migration_test');
        DB::setDefaultConnection('subscription_migration_test');
        Schema::create('farast_commercial_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
        });
        Schema::create('farast_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('subscription_id', 80)->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('plan_code')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('legacy_reference')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousDefault);
        DB::purge('subscription_migration_test');

        parent::tearDown();
    }

    private function runCanonicalization(): void
    {
        $migration = require base_path('database/migrations/2026_10_03_000004_canonicalize_farast_subscriptions.php');
        $migration->up();
    }

    public function test_canonicalization_preserves_every_row_and_legacy_identifier(): void
    {
        DB::table('farast_commercial_plans')->insert(['id' => 7, 'code' => 'pro']);
        DB::table('farast_subscriptions')->insert([
            'id' => 41,
            'subscription_id' => 'legacy-subscription-41',
            'user_id' => 12,
            'plan_id' => 999,
            'plan_code' => 'pro',
            'status' => 'active',
            'starts_at' => now(),
            'metadata' => json_encode(['origin' => 'legacy']),
            'legacy_reference' => 'external-system-41',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCanonicalization();

        $this->assertSame(1, DB::table('farast_subscriptions')->count());
        $row = DB::table('farast_subscriptions')->first();
        $this->assertSame('legacy-subscription-41', $row->subscription_id);
        $this->assertSame(7, (int) $row->plan_id);
        $metadata = json_decode($row->metadata, true);
        $this->assertSame('legacy', $metadata['origin']);
        $this->assertSame('external-system-41', $metadata['legacy_fields']['legacy_reference']);
        $this->assertSame(41, (int) $metadata['legacy_id']);
        $this->assertFalse(Schema::hasTable('farast_subscriptions_phase_20261003_staging'));
    }

    public function test_unresolvable_plan_aborts_without_dropping_or_rewriting_source_rows(): void
    {
        DB::table('farast_subscriptions')->insert([
            'id' => 42,
            'subscription_id' => 'legacy-subscription-42',
            'user_id' => 12,
            'plan_id' => 999,
            'plan_code' => 'missing-plan',
            'status' => 'active',
            'starts_at' => now(),
            'legacy_reference' => 'must-survive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->runCanonicalization();
            $this->fail('Canonicalization must stop when a subscription plan cannot be resolved.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('legacy subscription row 42', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('farast_subscriptions'));
        $this->assertDatabaseHas('farast_subscriptions', [
            'id' => 42,
            'subscription_id' => 'legacy-subscription-42',
            'legacy_reference' => 'must-survive',
        ]);
        $this->assertFalse(Schema::hasTable('farast_subscriptions_phase_20261003_staging'));
    }
}
