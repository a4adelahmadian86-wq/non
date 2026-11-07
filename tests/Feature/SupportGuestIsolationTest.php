<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
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
            ['attachment' => UploadedFile::fake()->create('document.pdf', 32, 'application/pdf'), 'notify' => false],
        );

        $attachment = $message->fresh()->attachments[0];
        $this->assertSame('private', $attachment['disk']);
        Storage::disk('private')->assertExists($attachment['path']);

        $this->actingAs($user)
            ->get(route('support.attachment', ['ticket' => $ticket->id, 'message' => $message->id, 'index' => 0]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');

        $this->actingAs($other)
            ->get(route('support.attachment', ['ticket' => $ticket->id, 'message' => $message->id, 'index' => 0]))
            ->assertForbidden();
    }
}
