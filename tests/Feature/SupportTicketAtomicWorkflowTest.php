<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class SupportTicketAtomicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SupportTicketService
    {
        return new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );
    }

    public function test_repeating_same_status_does_not_create_duplicate_system_notes(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Idempotent status transition',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $service = $this->service();
        $first = $service->changeStatus($ticket, 'pending', $actor);
        $second = $service->changeStatus($first, 'pending', $actor);

        $this->assertSame('pending', $second->status);
        $this->assertSame(1, $ticket->messages()->where('is_internal', true)->count());
    }

    public function test_customer_reply_cannot_race_past_a_closed_ticket(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Closed ticket reply race',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        try {
            $this->service()->addMessage($ticket, $customer, 'late reply', [
                'is_staff' => false,
                'is_internal' => false,
            ]);
            $this->fail('A customer reply to a closed ticket must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('این تیکت بسته شده و امکان ارسال پاسخ ندارد.', $exception->getMessage());
        }

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_csat_can_only_be_submitted_once_even_from_a_stale_ticket_instance(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Single CSAT submission',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $staleTicket = $ticket->fresh();

        $service = $this->service();
        $saved = $service->submitCsat($ticket, 5, 'عالی بود');

        $this->assertSame(5, (int) $saved->csat_score);

        $this->expectException(InvalidArgumentException::class);
        $service->submitCsat($staleTicket, 3, 'دوباره');
    }
}
