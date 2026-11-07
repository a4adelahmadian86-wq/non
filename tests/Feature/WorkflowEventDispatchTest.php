<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile): User
    {
        return User::create([
            'name' => 'Workflow owner', 'email' => $mobile.'@example.test',
            'mobile' => $mobile, 'password' => 'secret', 'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_document_saved_event_runs_matching_workflow_once(): void
    {
        $user = $this->user('09152223344');
        $workflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id, 'name' => 'Saved document alert', 'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'notify', 'title' => 'سند ذخیره شد', 'message' => 'سند شما ذخیره شد.']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $engine = app(WorkflowEngine::class);
        $first = $engine->dispatch('document.saved', ['user_id' => $user->id, 'document_id' => 17], 'document:17:revision:1');
        $second = $engine->dispatch('document.saved', ['user_id' => $user->id, 'document_id' => 17], 'document:17:revision:1');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseHas('farast_workflow_runs', ['workflow_id' => $workflow, 'status' => 'completed']);
        $this->assertSame(1, DB::table('farast_notifications')->where('user_id', $user->id)->where('type', 'workflow')->count());
        $this->assertSame(1, DB::table('farast_workflow_events')->count());
    }



    public function test_verified_order_paid_event_runs_customer_workflow_once(): void
    {
        $user = $this->user('09152223348');
        $workflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id, 'name' => 'Paid order receipt', 'enabled' => true,
            'trigger_type' => 'order.paid',
            'steps' => json_encode([['action' => 'notify', 'title' => 'پرداخت موفق', 'message' => 'سفارش شما پرداخت شد.']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = app(WorkflowEngine::class)->dispatch(
            'order.paid',
            ['user_id' => $user->id, 'order_id' => 125, 'amount_rials' => 450000],
            'order:125:paid',
        );
        $second = app(WorkflowEngine::class)->dispatch(
            'order.paid',
            ['user_id' => $user->id, 'order_id' => 125, 'amount_rials' => 450000],
            'order:125:paid',
        );

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseHas('farast_workflow_runs', ['workflow_id' => $workflow, 'status' => 'completed']);
        $this->assertDatabaseHas('farast_notifications', ['user_id' => $user->id, 'title' => 'پرداخت موفق']);
    }


    public function test_typing_deposit_paid_event_runs_matching_workflow_once(): void
    {
        $user = $this->user('09152223349');
        $workflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id, 'name' => 'Deposit receipt', 'enabled' => true,
            'trigger_type' => 'order.deposit_paid',
            'steps' => json_encode([['action' => 'notify', 'title' => 'پیش‌پرداخت ثبت شد']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $engine = app(WorkflowEngine::class);
        $context = ['user_id' => $user->id, 'order_id' => 502, 'order_kind' => 'typing_deposit'];
        $first = $engine->dispatch('order.deposit_paid', $context, 'order:502:order.deposit_paid');
        $second = $engine->dispatch('order.deposit_paid', $context, 'order:502:order.deposit_paid');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseHas('farast_workflow_runs', ['workflow_id' => $workflow, 'status' => 'completed']);
        $this->assertDatabaseHas('farast_notifications', ['user_id' => $user->id, 'title' => 'پیش‌پرداخت ثبت شد']);
    }

    public function test_workflow_can_create_a_crm_follow_up_for_its_owner(): void
    {
        $user = $this->user('09152223347');
        DB::table('farast_workflows')->insert([
            'user_id' => $user->id, 'name' => 'Ticket follow-up', 'enabled' => true,
            'trigger_type' => 'ticket.created',
            'steps' => json_encode([['action' => 'create_follow_up', 'title' => 'بررسی تیکت جدید', 'priority' => 'high']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = app(WorkflowEngine::class)->dispatch(
            'ticket.created',
            ['user_id' => $user->id, 'customer_id' => $user->id, 'ticket_id' => 91],
            'ticket:91:created',
        );

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('crm_follow_up_tasks', [
            'customer_id' => $user->id, 'created_by' => $user->id,
            'title' => 'بررسی تیکت جدید', 'priority' => 'high',
        ]);
    }

    public function test_workflow_steps_roll_back_together_when_a_later_step_fails(): void
    {
        $user = $this->user('09152223350');
        $workflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Atomic workflow',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([
                ['action' => 'notify', 'title' => 'این اعلان نباید باقی بماند'],
                ['action' => 'unsupported_action'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(WorkflowEngine::class)->run($workflow, ['user_id' => $user->id]);
            $this->fail('The unsupported second step should fail the workflow.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unsupported workflow action', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('farast_notifications')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('farast_workflow_runs', [
            'workflow_id' => $workflow,
            'status' => 'failed',
        ]);
    }

    public function test_failed_workflow_event_retries_only_failed_steps_and_completes(): void
    {
        $user = $this->user('09152223351');
        $successfulWorkflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Successful first workflow',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'notify', 'title' => 'اعلان اول']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $failingWorkflow = DB::table('farast_workflows')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Temporarily invalid workflow',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'unsupported_action']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $engine = app(WorkflowEngine::class);
        $context = ['user_id' => $user->id, 'document_id' => 991];
        $completed = $engine->dispatch('document.saved', $context, 'document:991:revision:1');

        $this->assertSame(1, $completed);
        $eventId = DB::table('farast_workflow_events')->where('idempotency_key', 'document:991:revision:1')->value('id');
        $this->assertDatabaseHas('farast_workflow_events', [
            'id' => $eventId,
            'status' => 'failed',
            'matched_workflows' => 2,
            'failed_workflows' => 1,
        ]);
        $this->assertDatabaseHas('farast_workflow_event_workflows', [
            'event_id' => $eventId,
            'workflow_id' => $successfulWorkflow,
            'status' => 'completed',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('farast_workflow_event_workflows', [
            'event_id' => $eventId,
            'workflow_id' => $failingWorkflow,
            'status' => 'failed',
            'attempts' => 1,
        ]);

        DB::table('farast_workflows')->where('id', $failingWorkflow)->update([
            'steps' => json_encode([['action' => 'notify', 'title' => 'اعلان دوم']], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $retried = $engine->retryFailedEvents();
        $this->assertSame(1, $retried);

        $this->assertDatabaseHas('farast_workflow_events', [
            'id' => $eventId,
            'status' => 'completed',
            'failed_workflows' => 0,
        ]);
        $this->assertNotNull(DB::table('farast_workflow_events')->where('id', $eventId)->value('processed_at'));
        $this->assertSame(1, DB::table('farast_workflow_runs')->where('workflow_id', $successfulWorkflow)->count());
        $this->assertSame(2, DB::table('farast_workflow_runs')->where('workflow_id', $failingWorkflow)->count());
        $this->assertSame(2, DB::table('farast_notifications')->where('user_id', $user->id)->where('type', 'workflow')->count());
        $this->assertSame(0, $engine->dispatch('document.saved', $context, 'document:991:revision:1'));

        // Recover the rare crash window where all step transactions committed but
        // the parent event's final status update did not.
        DB::table('farast_workflow_events')->where('id', $eventId)->update([
            'status' => 'processing',
            'processed_at' => null,
        ]);
        $this->assertSame(0, $engine->retryFailedEvents());
        $this->assertDatabaseHas('farast_workflow_events', [
            'id' => $eventId,
            'status' => 'completed',
        ]);
    }

    public function test_event_idempotency_key_cannot_be_reused_for_another_owner(): void
    {
        $owner = $this->user('09152223352');
        $other = $this->user('09152223353');
        $engine = app(WorkflowEngine::class);

        $engine->dispatch('document.saved', ['user_id' => $owner->id], 'shared-event-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already bound to another event');
        $engine->dispatch('document.saved', ['user_id' => $other->id], 'shared-event-key');
    }

    public function test_event_dispatch_never_runs_another_users_workflow(): void
    {
        $owner = $this->user('09152223345');
        $other = $this->user('09152223346');
        DB::table('farast_workflows')->insert([
            'user_id' => $other->id, 'name' => 'Private', 'enabled' => true,
            'trigger_type' => 'ticket.created', 'steps' => json_encode([['action' => 'notify']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = app(WorkflowEngine::class)->dispatch('ticket.created', ['user_id' => $owner->id], 'ticket:unique-1');
        $this->assertSame(0, $count);
        $this->assertSame(0, DB::table('farast_workflow_runs')->count());
    }
}
