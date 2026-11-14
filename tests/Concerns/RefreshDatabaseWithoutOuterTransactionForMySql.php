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

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;

            return;
        }

        // Reuse the schema but clear data between tests. Re-running hundreds of
        // migrations for every MySQL test made the dedicated lock suite needlessly slow.
        $connection = DB::connection();
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($connection->select('SHOW TABLES', [], false) as $row) {
                $table = array_values((array) $row)[0] ?? null;

                if (! is_string($table) || $table === 'migrations') {
                    continue;
                }

                $quote = chr(96);
                $quoted = $quote.str_replace($quote, $quote.$quote, $table).$quote;
                $connection->statement('TRUNCATE TABLE '.$quoted);
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
