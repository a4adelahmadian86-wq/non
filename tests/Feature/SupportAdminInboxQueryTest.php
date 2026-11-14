<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAdminInboxQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_inbox_loads_the_latest_public_preview_and_active_thread(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Inbox preview query',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Earlier public reply',
            'is_internal' => false,
            'is_staff' => true,
        ]);
        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Internal note must not become the list preview',
            'is_internal' => true,
            'is_staff' => true,
        ]);
        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Latest public preview sentinel',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('Latest public preview sentinel')
            ->assertSee('Internal note must not become the list preview');

        $this->assertStringContainsString('Latest public preview sentinel', $response->getContent());
    }
}
