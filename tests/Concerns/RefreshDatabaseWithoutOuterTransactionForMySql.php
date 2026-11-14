<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Keep normal RefreshDatabase transactions on SQLite, but avoid an outer
 * transaction on MySQL tests that must acquire a session-level advisory lock
 * before their business transaction starts.
 */
trait RefreshDatabaseWithoutOuterTransactionForMySql
{
    use RefreshDatabase {
        refreshTestDatabase as protected refreshTestDatabaseWithTransactions;
    }

    protected function refreshTestDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->refreshTestDatabaseWithTransactions();

            return;
        }

        // MySQL advisory-lock integration tests need a real commit boundary.
        // Rebuild per test so data isolation is retained without an outer tx.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        RefreshDatabaseState::$migrated = true;
    }
}
