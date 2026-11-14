<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Serializes ticket mutations that affect the auto-assignment workload.
 *
 * MySQL named locks are connection-scoped rather than transaction-scoped, so
 * this coordinator deliberately acquires the lock on the write PDO before the
 * transaction starts and releases it only after commit/rollback has completed.
 * If the connection dies, MySQL releases the lock automatically.
 *
 * SQLite and other non-MySQL drivers keep their existing transactional behavior;
 * production MySQL concurrency guarantees are verified by a dedicated MySQL test.
 */
final class SupportAssignmentMutex
{
    public const LOCK_NAME = 'farast:support:assignment-workload';

    private const LOCK_TIMEOUT_SECONDS = 15;

    public function assertHeld(): void
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('انتخاب کارشناس باید داخل تراکنش انجام شود.');
        }

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $row = $connection->selectOne(
            'SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held',
            [self::LOCK_NAME],
            false
        );

        if ((int) ($row->held ?? 0) !== 1) {
            throw new RuntimeException('انتخاب کارشناس بدون قفل هماهنگی مجاز نیست.');
        }
    }

    public function synchronized(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $callback();
        }

        // Force the write PDO: GET_LOCK and the following transaction must use
        // the same MySQL session even when read/write splitting is configured.
        $lock = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS],
            false
        );

        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('قفل هماهنگی تخصیص تیکت در مهلت مقرر دریافت نشد؛ عملیات بدون تغییر متوقف شد.');
        }

        try {
            return $callback();
        } finally {
            try {
                $released = $connection->selectOne(
                    'SELECT RELEASE_LOCK(?) AS released',
                    [self::LOCK_NAME],
                    false
                );

                if ((int) ($released->released ?? 0) !== 1) {
                    report(new RuntimeException('آزادسازی قفل هماهنگی تخصیص تیکت تأیید نشد.'));
                }
            } catch (Throwable $exception) {
                // A dropped MySQL session releases its named locks server-side.
                report($exception);
            }
        }
    }
}
