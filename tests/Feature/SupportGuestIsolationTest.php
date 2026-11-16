<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class SupportGuestIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function guestTicket(string $email): Ticket
    {
        return Ticket::create([
            'guest_name' => 'Guest',
            'guest_email' => $email,
            'subject' => 'Guest request',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'source' => 'widget',
        ]);
    }

    public function test_guest_cannot_reply_to_another_ticket_by_email_and_guessable_id(): void
    {
        $ticket = $this->guestTicket('victim@example.test');
        $ticket->messages()->create(['body' => 'Private conversation', 'is_internal' => false, 'is_staff' => false]);

        $response = $this->postJson('/api/support/guest-message', [
            'body' => 'Unauthorized message',
            'email' => 'victim@example.test',
            'submission_key' => 'a3d9e110-8b1a-4f80-8db7-7f9c6d3a2211',
            'ticket_id' => $ticket->id,
        ])->assertOk();

        $this->assertNotSame($ticket->id, (int) $response->json('ticket_id'));
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertDatabaseHas('tickets', [
            'id' => $response->json('ticket_id'),
            'guest_email' => 'victim@example.test',
        ]);
    }

    public function test_guest_can_continue_only_a_ticket_bound_to_the_current_session(): void
    {
        $ticket = $this->guestTicket('owner@example.test');
        $ticket->messages()->create(['body' => 'First message', 'is_internal' => false, 'is_staff' => false]);
        session(['support_guest_ticket_ids' => [$ticket->id]]);

        $this->postJson('/api/support/guest-message', [
            'body' => 'Follow-up from same browser session',
            'email' => 'owner@example.test',
            'submission_key' => 'b5e8c120-7a2b-4c91-9ec8-8a0d7e4b3322',
            'ticket_id' => $ticket->id,
        ])->assertOk()->assertJsonPath('ticket_id', $ticket->id);

        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame(1, Ticket::where('guest_email', 'owner@example.test')->count());
    }

    public function test_login_claims_only_guest_tickets_created_in_the_current_session(): void
    {
        $user = User::factory()->create(['email' => 'claim@example.test']);
        $ownedSessionTicket = $this->guestTicket('claim@example.test');
        $unrelatedTicket = $this->guestTicket('claim@example.test');
        session([
            'support_guest_email' => 'claim@example.test',
            'support_guest_ticket_ids' => [$ownedSessionTicket->id],
        ]);

        $claimed = app(SupportTicketService::class)->claimGuestTickets($user);

        $this->assertSame(1, $claimed);
        $this->assertSame($user->id, (int) $ownedSessionTicket->fresh()->user_id);
        $this->assertNull($unrelatedTicket->fresh()->user_id);

        $event = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ownedSessionTicket->id)
            ->where('event_type', 'support.ticket.guest_claimed')
            ->firstOrFail();

        $this->assertSame($user->id, $event->user_id);
        $this->assertNull($event->payload_meta['from_user_id']);
        $this->assertSame($user->id, $event->payload_meta['to_user_id']);
        $this->assertSame('support', $event->source);
        $encoded = json_encode($event->payload_meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('claim@example.test', $encoded);
    }

    public function test_guest_ticket_claim_rolls_back_when_audit_append_fails(): void
    {
        $user = User::factory()->create(['email' => 'rollback-claim@example.test']);
        $ticket = $this->guestTicket('rollback-claim@example.test');
        session([
            'support_guest_email' => 'rollback-claim@example.test',
            'support_guest_ticket_ids' => [$ticket->id],
        ]);

        $audit = Mockery::mock(\App\Services\AuditEventService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit ledger unavailable'));
        $this->app->instance(\App\Services\AuditEventService::class, $audit);

        try {
            app(SupportTicketService::class)->claimGuestTickets($user);
            $this->fail('A failed audit append must roll back guest ticket ownership.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit ledger unavailable', $exception->getMessage());
        }

        $this->assertNull($ticket->fresh()->user_id);
        $this->assertDatabaseMissing('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticket->id,
            'event_type' => 'support.ticket.guest_claimed',
        ]);
        $this->assertSame([$ticket->id], session('support_guest_ticket_ids'));
    }

    public function test_legacy_public_ticket_attachments_are_migrated_to_private_storage(): void
    {
        Storage::fake('public');
        Storage::fake('private');
        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Legacy attachment',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $legacyPath = 'ticket-attachments/'.$ticket->id.'/legacy.pdf';
        Storage::disk('public')->put($legacyPath, 'legacy private content');
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'Old attachment',
            'is_internal' => false,
            'is_staff' => false,
            'attachments' => [[
                'path' => $legacyPath,
                'name' => 'legacy.pdf',
                'size' => 22,
                'mime' => 'application/pdf',
            ]],
        ]);

        Artisan::call('farast:secure-ticket-attachments', ['--limit' => 100]);

        $attachment = $message->fresh()->attachments[0];
        $this->assertSame('private', $attachment['disk']);
        Storage::disk('private')->assertExists($attachment['path']);
        Storage::disk('public')->assertMissing($legacyPath);
    }

    public function test_ticket_attachments_are_stored_on_private_disk_and_downloaded_through_authorization(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Private attachment',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $message = app(SupportTicketService::class)->addMessage(
            $ticket,
            $user,
            'Attached document',
            ['attachment' => UploadedFile::fake()->createWithContent('document.txt', 'private attachment content'), 'notify' => false],
        );

        $attachment = $message->fresh()->attachments[0];
        $this->assertSame('private', $attachment['disk']);
        Storage::disk('private')->assertExists($attachment['path']);

        $response = $this->actingAs($user)
            ->get(route('support.attachment', ['ticket' => $ticket->id, 'message' => $message->id, 'index' => 0]))
            ->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($other)
            ->get(route('support.attachment', ['ticket' => $ticket->id, 'message' => $message->id, 'index' => 0]))
            ->assertForbidden();
    }

    public function test_guest_first_message_retry_returns_the_same_ticket(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'body' => 'Initial guest support request',
            'name' => 'Guest Owner',
            'email' => 'retry-owner@example.test',
            'submission_key' => $key,
        ];

        $first = $this->postJson('/api/support/guest-message', $payload)->assertOk();
        $retry = $this->postJson('/api/support/guest-message', $payload)->assertOk();

        $this->assertSame($first->json('ticket_id'), $retry->json('ticket_id'));
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_messages', 1);
    }

    public function test_guest_follow_up_retry_does_not_append_a_second_message(): void
    {
        $initial = $this->postJson('/api/support/guest-message', [
            'body' => 'Initial conversation',
            'name' => 'Guest Owner',
            'email' => 'followup-owner@example.test',
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk();
        $ticketId = (int) $initial->json('ticket_id');
        $payload = [
            'body' => 'One follow-up message',
            'email' => 'followup-owner@example.test',
            'ticket_id' => $ticketId,
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
        ];

        $first = $this->postJson('/api/support/guest-message', $payload)->assertOk();
        $retry = $this->postJson('/api/support/guest-message', $payload)->assertOk();

        $this->assertSame($ticketId, (int) $first->json('ticket_id'));
        $this->assertSame($ticketId, (int) $retry->json('ticket_id'));
        $this->assertSame(2, Ticket::findOrFail($ticketId)->messages()->count());
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_messages', 2);
    }

}
