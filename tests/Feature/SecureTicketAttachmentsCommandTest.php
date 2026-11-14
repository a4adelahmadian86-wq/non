<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecureTicketAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_attachment_is_scanned_and_bound_to_managed_private_storage(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Legacy attachment migration',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        Storage::disk('public')->put('legacy/ticket-proof.txt', 'safe attachment');
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'Attachment is here',
            'is_staff' => false,
            'is_internal' => false,
            'attachments' => [[
                'name' => 'ticket-proof.txt',
                'path' => 'legacy/ticket-proof.txt',
                'disk' => 'public',
                'mime' => 'text/plain',
                'size' => 15,
            ]],
        ]);

        Artisan::call('farast:secure-ticket-attachments', ['--limit' => 500]);

        $attachment = $message->fresh()->attachments[0];
        $this->assertNotEmpty($attachment['managed_file_id']);
        $this->assertSame('private', $attachment['disk']);
        $this->assertFalse(Storage::disk('public')->exists('legacy/ticket-proof.txt'));
        $this->assertTrue(Storage::disk('private')->exists($attachment['path']));

        $managed = StorageManagedFile::findOrFail($attachment['managed_file_id']);
        $this->assertSame('support', $managed->source_module);
        $this->assertSame(TicketMessage::class, $managed->source_type);
        $this->assertSame($message->id, (int) $managed->source_id);
        $this->assertSame('private', $managed->visibility);
        $this->assertContains($managed->status, ['active', 'pending_transfer']);
        $this->assertFalse((bool) data_get($managed->metadata, 'reference_pending', true));

        $this->actingAs($customer)
            ->get(route('support.attachment', [
                'ticket' => $ticket->id,
                'message' => $message->id,
                'index' => 0,
            ]))
            ->assertOk()
            ->assertSee('safe attachment');
    }

    public function test_unmanaged_public_attachment_fails_closed_before_migration(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Unmanaged attachment must not be served',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        Storage::disk('public')->put('legacy/unmanaged.txt', 'not served');
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'Legacy public file',
            'attachments' => [[
                'name' => 'unmanaged.txt',
                'path' => 'legacy/unmanaged.txt',
                'disk' => 'public',
                'mime' => 'text/plain',
            ]],
        ]);

        $this->actingAs($customer)
            ->get(route('support.attachment', [
                'ticket' => $ticket->id,
                'message' => $message->id,
                'index' => 0,
            ]))
            ->assertNotFound();
    }

    public function test_unsupported_legacy_attachment_is_quarantined_and_cannot_be_downloaded(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Unsafe legacy attachment',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        Storage::disk('public')->put('legacy/unsafe.php', '<?php echo "unsafe";');
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'Unsafe attachment',
            'is_staff' => false,
            'is_internal' => false,
            'attachments' => [[
                'name' => 'unsafe.php',
                'path' => 'legacy/unsafe.php',
                'disk' => 'public',
                'mime' => 'text/plain',
                'size' => 20,
            ]],
        ]);

        Artisan::call('farast:secure-ticket-attachments', ['--limit' => 500]);

        $attachment = $message->fresh()->attachments[0];
        $this->assertTrue((bool) ($attachment['quarantined'] ?? false));
        $this->assertSame('private', $attachment['disk']);
        $this->assertFalse(Storage::disk('public')->exists('legacy/unsafe.php'));
        $this->assertTrue(Storage::disk('private')->exists($attachment['path']));

        $this->actingAs($customer)
            ->get(route('support.attachment', [
                'ticket' => $ticket->id,
                'message' => $message->id,
                'index' => 0,
            ]))
            ->assertNotFound();
    }
}
