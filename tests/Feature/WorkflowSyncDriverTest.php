<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowSyncDriverTest extends TestCase
{
    use DatabaseMigrations;

    public function test_sync_driver_processes_outbox_event_only_after_source_transaction_commits(): void
    {
        config(['queue.default' => 'sync']);

        $owner = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $workflowId = DB::table('farast_workflows')->insertGetId([
            'user_id' => $owner->id,
            'name' => 'Sync outbox smoke',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([[
                'action' => 'notify',
                'title' => 'سند ذخیره شد',
                'message' => 'گردش‌کار پس از ثبت سند اجرا شد.',
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::transaction(function () use ($owner): int {
            $eventId = app(WorkflowEngine::class)->enqueue(
                'document.saved',
                ['user_id' => $owner->id, 'document_id' => 7801],
                'sync-outbox:document:7801:revision:1',
            );

            $this->assertDatabaseHas('farast_workflow_events', [
                'id' => $eventId,
                'status' => 'pending',
            ]);

            return $eventId;
        });

        $this->assertDatabaseHas('farast_workflow_events', [
            'id' => $eventId,
            'status' => 'completed',
            'matched_workflows' => 1,
        ]);
        $this->assertDatabaseHas('farast_workflow_runs', [
            'workflow_id' => $workflowId,
            'status' => 'completed',
        ]);
        $this->assertSame(1, DB::table('farast_notifications')
            ->where('user_id', $owner->id)
            ->where('type', 'workflow')
            ->count());
    }
    public function test_sync_outbox_callback_is_discarded_when_source_transaction_rolls_back(): void
    {
        config(['queue.default' => 'sync']);

        $owner = User::factory()->create(['role' => User::ROLE_ADMIN]);
        DB::table('farast_workflows')->insert([
            'user_id' => $owner->id,
            'name' => 'Rolled back sync outbox',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'notify', 'title' => 'Must not run']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($owner): void {
                app(WorkflowEngine::class)->enqueue(
                    'document.saved',
                    ['user_id' => $owner->id, 'document_id' => 7802],
                    'sync-outbox:document:7802:revision:1',
                );
                throw new \RuntimeException('rollback source event');
            });
            $this->fail('The source transaction should roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback source event', $exception->getMessage());
        }

        $this->assertDatabaseMissing('farast_workflow_events', [
            'idempotency_key' => 'sync-outbox:document:7802:revision:1',
        ]);
        $this->assertDatabaseCount('farast_workflow_runs', 0);
        $this->assertDatabaseCount('farast_notifications', 0);
    }

}
