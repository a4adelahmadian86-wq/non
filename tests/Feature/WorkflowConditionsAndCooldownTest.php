<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowConditionsAndCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $mobile): User
    {
        return User::create([
            'name' => 'Automation owner',
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function workflow(User $owner, array $options = []): int
    {
        return DB::table('farast_workflows')->insertGetId(array_merge([
            'user_id' => $owner->id,
            'name' => 'Conditional workflow',
            'enabled' => true,
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'notify', 'title' => 'شرط برقرار شد']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ], $options));
    }

    public function test_dispatch_rejects_unapproved_context_fields_and_non_integer_user_ids(): void
    {
        $owner = $this->owner('09140000209');
        $engine = app(WorkflowEngine::class);

        try {
            $engine->dispatch('document.saved', [
                'user_id' => $owner->id,
                'document_id' => 9,
                'password' => 'must-not-be-stored',
            ], 'document:unsafe-context:1');
            $this->fail('Unsupported event context fields must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('unsupported fields', $exception->getMessage());
        }

        $this->assertSame(0, $engine->dispatch('document.saved', [
            'user_id' => (string) ($owner->id + 0.5),
            'document_id' => 9,
        ], 'document:invalid-user:1'));
        $this->assertDatabaseMissing('farast_workflow_events', ['idempotency_key' => 'document:unsafe-context:1']);
        $this->assertDatabaseMissing('farast_workflow_events', ['idempotency_key' => 'document:invalid-user:1']);
    }

    public function test_event_only_runs_workflow_when_all_conditions_match(): void
    {
        $owner = $this->owner('09140000201');
        $workflow = $this->workflow($owner, [
            'conditions' => json_encode([
                ['field' => 'document_id', 'operator' => 'greater_than', 'value' => '10'],
                ['field' => 'status', 'operator' => 'equals', 'value' => 'ready'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $engine = app(WorkflowEngine::class);

        $skipped = $engine->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 9, 'status' => 'ready'],
            'document:9:revision:1',
        );
        $this->assertSame(0, $skipped);
        $this->assertDatabaseMissing('farast_workflow_event_workflows', ['workflow_id' => $workflow]);

        $completed = $engine->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 11, 'status' => 'ready'],
            'document:11:revision:1',
        );
        $this->assertSame(1, $completed);
        $this->assertDatabaseHas('farast_workflow_runs', ['workflow_id' => $workflow, 'status' => 'completed']);
        $this->assertSame(1, DB::table('farast_notifications')->where('user_id', $owner->id)->where('type', 'workflow')->count());
    }

    public function test_workflow_cooldown_prevents_overlapping_event_triggers(): void
    {
        $owner = $this->owner('09140000202');
        $workflow = $this->workflow($owner, ['cooldown_seconds' => 3600]);
        $engine = app(WorkflowEngine::class);

        $first = $engine->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 31],
            'document:31:revision:1',
        );
        $second = $engine->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 32],
            'document:32:revision:1',
        );

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, DB::table('farast_workflow_runs')->where('workflow_id', $workflow)->count());
        $this->assertDatabaseHas('farast_workflow_events', [
            'idempotency_key' => 'document:32:revision:1',
            'status' => 'completed',
            'matched_workflows' => 0,
        ]);
    }

    public function test_manual_run_cannot_forge_business_context_to_bypass_workflow_conditions(): void
    {
        $owner = $this->owner('09140000204');
        $workflow = $this->workflow($owner, [
            'conditions' => json_encode([
                ['field' => 'amount_rials', 'operator' => 'greater_than', 'value' => '100000'],
                ['field' => 'status', 'operator' => 'equals', 'value' => 'paid'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->actingAs($owner)->postJson('/workspace/workflows/'.$workflow.'/run', [
            'context' => [
                'amount_rials' => 9000000,
                'status' => 'paid',
                'customer_id' => $owner->id,
            ],
        ])->assertStatus(409);

        $this->assertDatabaseCount('farast_workflow_runs', 0);
        $this->assertDatabaseCount('crm_follow_up_tasks', 0);
    }

    public function test_workflow_create_and_edit_are_recorded_in_the_audit_ledger(): void
    {
        $owner = $this->owner('09140000205');
        $payload = [
            'name' => 'Audited workflow',
            'trigger_type' => 'document.saved',
            'steps' => [['action' => 'notify', 'title' => 'Initial title', 'message' => 'Initial message']],
            'enabled' => '1',
            'cooldown_seconds' => 0,
        ];

        $this->actingAs($owner)->post('/workspace/workflows', $payload)->assertRedirect();
        $workflow = DB::table('farast_workflows')->where('user_id', $owner->id)->where('name', 'Audited workflow')->first();
        $this->assertNotNull($workflow);
        $this->assertDatabaseHas('farast_workflow_audit_logs', [
            'workflow_id' => $workflow->id,
            'owner_id' => $owner->id,
            'action' => 'workflow.created',
        ]);

        $payload['id'] = $workflow->id;
        $payload['name'] = 'Audited workflow updated';
        $payload['steps'][0]['title'] = 'Updated title';
        $this->post('/workspace/workflows', $payload)->assertRedirect();

        $this->assertDatabaseHas('farast_workflow_audit_logs', [
            'workflow_id' => $workflow->id,
            'owner_id' => $owner->id,
            'action' => 'workflow.updated',
        ]);
        $latest = DB::table('farast_workflow_audit_logs')->where('workflow_id', $workflow->id)->where('action', 'workflow.updated')->first();
        $this->assertSame('Audited workflow', data_get(json_decode($latest->before_state, true), 'name'));
        $this->assertSame('Audited workflow updated', data_get(json_decode($latest->after_state, true), 'name'));
    }

    public function test_workflow_configuration_and_audit_write_roll_back_together(): void
    {
        $owner = $this->owner('09140000208');
        DB::unprepared("CREATE TRIGGER fail_workflow_audit BEFORE INSERT ON farast_workflow_audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");

        $this->actingAs($owner)->post('/workspace/workflows', [
            'name' => 'Workflow without audit',
            'trigger_type' => 'document.saved',
            'steps' => [['action' => 'notify', 'title' => 'Should roll back']],
            'enabled' => '1',
        ])->assertServerError();

        $this->assertDatabaseMissing('farast_workflows', [
            'user_id' => $owner->id,
            'name' => 'Workflow without audit',
        ]);
    }

    public function test_workflow_with_execution_history_cannot_be_deleted(): void
    {
        $owner = $this->owner('09140000206');
        $workflow = $this->workflow($owner);
        app(WorkflowEngine::class)->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 206],
            'document:206:revision:1',
        );

        $this->actingAs($owner)->delete('/workspace/workflows/'.$workflow)->assertStatus(409);
        $this->assertDatabaseHas('farast_workflows', ['id' => $workflow]);
        $this->assertDatabaseMissing('farast_workflow_audit_logs', [
            'workflow_id' => $workflow,
            'action' => 'workflow.deleted',
        ]);
    }

    public function test_unused_workflow_can_be_deleted_without_losing_its_audit_record(): void
    {
        $owner = $this->owner('09140000207');
        $workflow = $this->workflow($owner);

        $this->actingAs($owner)->delete('/workspace/workflows/'.$workflow)->assertRedirect();
        $this->assertDatabaseMissing('farast_workflows', ['id' => $workflow]);
        $this->assertDatabaseHas('farast_workflow_audit_logs', [
            'workflow_id' => $workflow,
            'owner_id' => $owner->id,
            'action' => 'workflow.deleted',
        ]);
    }

    public function test_event_matching_is_frozen_even_when_a_new_workflow_is_created_later(): void
    {
        $owner = $this->owner('09140000203');
        $engine = app(WorkflowEngine::class);

        $first = $engine->dispatch(
            'document.saved',
            ['user_id' => $owner->id, 'document_id' => 41],
            'document:41:revision:1',
        );
        $this->assertSame(0, $first);

        $workflow = $this->workflow($owner);
        $retried = $engine->retryFailedEvents();

        $this->assertSame(0, $retried);
        $this->assertDatabaseMissing('farast_workflow_event_workflows', [
            'workflow_id' => $workflow,
            'event_id' => DB::table('farast_workflow_events')->where('idempotency_key', 'document:41:revision:1')->value('id'),
        ]);
        $this->assertSame(0, DB::table('farast_workflow_runs')->where('workflow_id', $workflow)->count());
    }
}
