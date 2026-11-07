<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BackendHealthCheck extends Command
{
    protected $signature = 'farast:health-check';

    protected $description = 'Check local backend database and storage readiness without contacting external services';

    public function handle(): int
    {
        $checks = [
            'database' => fn (): mixed => DB::connection()->select('SELECT 1'),
            'private_storage' => fn (): bool => $this->checkPrivateStorage(),
            'queue' => fn (): bool => $this->checkQueueStorage(),
            'cache' => fn (): bool => $this->checkCacheStorage(),
            'sessions' => fn (): bool => $this->checkSessionStorage(),
            'production_config' => fn (): bool => $this->checkProductionConfig(),
        ];

        $failed = 0;

        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->components->info($name.': ready');
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->components->error($name.': failed (details were written to the application log)');
            }
        }

        $this->newLine();
        $this->line('Backend checks: '.(count($checks) - $failed).' passed, '.$failed.' failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkPrivateStorage(): bool
    {
        $disk = Storage::disk('private');
        $path = '.health-checks/'.Str::uuid().'.probe';
        $contents = Str::random(32);

        try {
            if (! $disk->put($path, $contents)) {
                throw new \RuntimeException('Private storage write returned false.');
            }

            if ($disk->get($path) !== $contents) {
                throw new \RuntimeException('Private storage read-back did not match.');
            }
        } finally {
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new \RuntimeException('Private storage probe could not be removed.');
            }
        }

        return true;
    }

    private function checkQueueStorage(): bool
    {
        $driver = (string) config('queue.default');
        if ($driver !== 'database') {
            return true;
        }

        $connection = config('queue.connections.database.connection');
        $failedConnection = config('queue.failed.database');
        $jobsTable = (string) config('queue.connections.database.table', 'jobs');
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::connection($connection)->hasTable($jobsTable)
            || ! Schema::connection($failedConnection)->hasTable($failedTable)) {
            throw new \RuntimeException('Database queue tables are missing.');
        }

        return true;
    }

    private function checkCacheStorage(): bool
    {
        if (config('cache.default') !== 'database') {
            return true;
        }

        $store = (array) config('cache.stores.database', []);
        $connection = $store['connection'] ?? null;
        $table = (string) ($store['table'] ?? 'cache');

        if (! Schema::connection($connection)->hasTable($table)) {
            throw new \RuntimeException('Database cache table is missing.');
        }

        return true;
    }

    private function checkSessionStorage(): bool
    {
        if (config('session.driver') !== 'database') {
            return true;
        }

        if (! Schema::connection(config('session.connection'))->hasTable(config('session.table', 'sessions'))) {
            throw new \RuntimeException('Database session table is missing.');
        }

        return true;
    }

    private function checkProductionConfig(): bool
    {
        if (app()->environment('production')) {
            if ((bool) config('app.debug')) {
                throw new \RuntimeException('APP_DEBUG must be disabled in production.');
            }

            if (trim((string) config('app.key')) === '') {
                throw new \RuntimeException('APP_KEY is not configured.');
            }
        }

        return true;
    }
}
