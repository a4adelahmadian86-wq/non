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
