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
