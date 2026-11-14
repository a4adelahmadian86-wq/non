<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportTicketService;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_ticket_creation_rollback_removes_managed_attachment_after_initial_message_succeeds(): void
    {
        Storage::fake('private');
        $customer = User::factory()->create();

        $sla = Mockery::mock(SupportSlaService::class);
        $sla->shouldReceive('applyTargets')
            ->once()
            ->andThrow(new \RuntimeException('SLA target calculation failed'));

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            $sla,
        );

        try {
            $service->createTicket($customer, [
                'subject' => 'Rollback with attachment',
                'body' => 'Please inspect this document',
                'priority' => 'normal',
                'department' => 'support',
            ], UploadedFile::fake()->createWithContent('note.txt', 'private payload'));

            $this->fail('Ticket creation should roll back when SLA target calculation fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SLA target calculation failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_ticket_creation_enqueues_its_workflow_event_before_commit(): void
    {
        $customer = User::factory()->create();

        $ticket = $this->service()->createTicket($customer, [
            'subject' => 'Transactional ticket event',
            'body' => 'Please follow up',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->assertDatabaseHas('farast_workflow_events', [
            'idempotency_key' => 'ticket:'.$ticket->id.':created',
            'event_type' => 'ticket.created',
            'user_id' => $customer->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('farast_workflow_runs', 0);
    }

    public function test_ticket_creation_rolls_back_when_outbox_persistence_fails(): void
    {
        $customer = User::factory()->create();
        $engine = Mockery::mock(WorkflowEngine::class);
        $engine->shouldReceive('enqueueTicketCreated')
            ->once()
            ->andThrow(new \\RuntimeException('workflow outbox unavailable'));
        $this->app->instance(WorkflowEngine::class, $engine);

        try {
            $this->service()->createTicket($customer, [
                'subject' => 'Outbox failure rollback',
                'body' => 'This ticket must not commit without its event',
                'priority' => 'normal',
                'department' => 'support',
            ]);
            $this->fail('Ticket creation must roll back when the outbox cannot persist its event.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('workflow outbox unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertDatabaseCount('farast_workflow_events', 0);
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
