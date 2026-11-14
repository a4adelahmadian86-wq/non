<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\SupportSlaService;
use Tests\TestCase;

class SupportPendingStatusSemanticsTest extends TestCase
{
    public function test_pending_status_is_consistently_described_as_waiting_for_customer(): void
    {
        $ticket = new Ticket(['status' => 'pending']);

        $this->assertSame('منتظر پاسخ کاربر', $ticket->statusLabel());
        $this->assertSame('paused', app(SupportSlaService::class)->resolutionStatus($ticket)['state']);
    }
}
