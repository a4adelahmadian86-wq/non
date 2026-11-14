<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Models\User;
use App\Services\SupportAssignmentMutex;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use App\Services\WorkflowEngine;
use Tests\Concerns\RefreshDatabaseWithoutOuterTransactionForMySql;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SupportAssignmentMutexTest extends TestCase
{
    use RefreshDatabaseWithoutOuterTransactionForMySql;

    public function test_ticket_creation_holds_mysql_workload_lock_and_rebalances_after_each_ticket(): void
    {
        $this->requireMySql();

        $probe = $this->probeConnection();
        $agents = User::factory()->count(2)->create(['role' => User::ROLE_ADMIN])->sortBy('id')->values();
        $customer = User::factory()->create();
        $lockObservations = [];

        $workflow = Mockery::mock(WorkflowEngine::class);
        $workflow->shouldReceive('enqueueTicketCreated')->twice()->andReturnUsing(
            function () use ($probe, &$lockObservations): int {
                $row = $probe->selectOne(
                    'SELECT GET_LOCK(?, 0) AS acquired',
                    [SupportAssignmentMutex::LOCK_NAME],
                    false
                );
                $acquired = (int) ($row->acquired ?? -1);
                $lockObservations[] = $acquired;

                if ($acquired === 1) {
                    $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [SupportAssignmentMutex::LOCK_NAME], false);
                }

                return 1;
            }
        );
        $this->app->instance(WorkflowEngine::class, $workflow);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('ticketCreated')->twice();
        $notifier->shouldReceive('assigned')->twice();
        $this->app->instance(SupportNotificationService::class, $notifier);

        $service = app(SupportTicketService::class);
        $first = $service->createTicket($customer, [
            'subject' => 'Concurrent allocation first ticket',
            'body' => 'First message',
            'department' => 'support',
            'priority' => 'normal',
        ]);
        $second = $service->createTicket($customer, [
            'subject' => 'Concurrent allocation second ticket',
            'body' => 'Second message',
            'department' => 'support',
            'priority' => 'normal',
        ]);

        $this->assertSame($agents[0]->id, (int) $first->assigned_to);
        $this->assertSame($agents[1]->id, (int) $second->assigned_to);
        $this->assertSame([0, 0], $lockObservations, 'A second MySQL connection must not enter the workload-critical section.');

        $autoAssignmentEvent = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $first->id)
            ->where('event_type', 'support.ticket.assignment_changed')
            ->where('source', 'support')
            ->first();

        $this->assertNotNull($autoAssignmentEvent);
        $this->assertNull($autoAssignmentEvent->user_id);
        $this->assertNull(data_get($autoAssignmentEvent->payload_meta, 'from_assigned_to'));
        $this->assertSame($agents[0]->id, (int) data_get($autoAssignmentEvent->payload_meta, 'to_assigned_to'));

        $afterCommit = $probe->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [SupportAssignmentMutex::LOCK_NAME],
            false
        );
        $this->assertSame(1, (int) $afterCommit->acquired);
        $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [SupportAssignmentMutex::LOCK_NAME], false);
    }

    public function test_mysql_workload_lock_is_released_after_transaction_rollback(): void
    {
        $this->requireMySql();
        $probe = $this->probeConnection();

        try {
            app(SupportAssignmentMutex::class)->synchronized(function () use ($probe): void {
                DB::transaction(function () use ($probe): void {
                    $blocked = $probe->selectOne(
                        'SELECT GET_LOCK(?, 0) AS acquired',
                        [SupportAssignmentMutex::LOCK_NAME],
                        false
                    );
                    $this->assertSame(0, (int) $blocked->acquired);

                    throw new RuntimeException('expected transaction rollback');
                });
            });
            $this->fail('The transactional callback exception should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected transaction rollback', $exception->getMessage());
        }

        $released = $probe->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [SupportAssignmentMutex::LOCK_NAME],
            false
        );
        $this->assertSame(1, (int) $released->acquired);
        $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [SupportAssignmentMutex::LOCK_NAME], false);
    }

    public function test_mysql_workload_lock_timeout_fails_closed_without_running_callback(): void
    {
        $this->requireMySql();

        $probe = $this->probeConnection();
        config(['support.assignment_lock_timeout_seconds' => 0]);

        $held = $probe->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [SupportAssignmentMutex::LOCK_NAME],
            false
        );
        $this->assertSame(1, (int) $held->acquired);

        $callbackRan = false;

        try {
            try {
                app(SupportAssignmentMutex::class)->synchronized(function () use (&$callbackRan): void {
                    $callbackRan = true;
                });
                $this->fail('A lock timeout must stop the workload mutation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('در مهلت مقرر دریافت نشد', $exception->getMessage());
            }

            $this->assertFalse($callbackRan, 'The protected callback must never run without the workload lock.');

            // The failed contender must not release a lock owned by another session.
            $stillHeld = $probe->selectOne(
                'SELECT IS_USED_LOCK(?) IS NOT NULL AS held',
                [SupportAssignmentMutex::LOCK_NAME],
                false
            );
            $this->assertSame(1, (int) $stillHeld->held);
        } finally {
            $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [SupportAssignmentMutex::LOCK_NAME], false);
        }
    }

    public function test_mysql_workload_lock_rejects_calls_inside_an_existing_transaction(): void
    {
        $this->requireMySql();
        $probe = $this->probeConnection();

        DB::beginTransaction();

        try {
            try {
                app(SupportAssignmentMutex::class)->synchronized(
                    fn () => $this->fail('A nested workload lock must not execute its callback.')
                );
                $this->fail('A nested workload lock must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('تراکنش بیرونی', $exception->getMessage());
            }

            $available = $probe->selectOne(
                'SELECT GET_LOCK(?, 0) AS acquired',
                [SupportAssignmentMutex::LOCK_NAME],
                false
            );
            $this->assertSame(1, (int) $available->acquired);
            $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [SupportAssignmentMutex::LOCK_NAME], false);
        } finally {
            DB::rollBack();
        }
    }

    private function requireMySql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL named-lock behavior is verified in the dedicated MySQL CI job.');
        }
    }

    private function probeConnection()
    {
        $name = 'mysql_assignment_lock_probe';
        $default = config('database.default');
        config(['database.connections.'.$name => config('database.connections.'.$default)]);
        DB::purge($name);

        return DB::connection($name);
    }
}
