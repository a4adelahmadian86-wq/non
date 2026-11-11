<?php

namespace Tests\Feature;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsServiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kavenegar.key' => 'test-key',
            'services.kavenegar.sender' => '1000',
            'support.sms_templates.ticket_reply' => null,
            'services.kavenegar.templates.ticket_reply' => null,
        ]);
    }

    public function test_same_sms_event_is_sent_once_and_a_new_event_is_sent_separately(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200]], 200),
        ]);

        $sms = app(SmsService::class);
        $sms->sendEvent('09123456789', 'ticket_reply', 'پاسخ جدید', ['token' => 'T-1'], 'ticket:1:message:10');
        $sms->sendEvent('09123456789', 'ticket_reply', 'پاسخ جدید', ['token' => 'T-1'], 'ticket:1:message:10');

        $this->assertDatabaseCount('sms_logs', 1);
        $this->assertDatabaseHas('sms_logs', [
            'event' => 'ticket_reply',
            'status' => 'accepted',
            'attempts' => 1,
            'idempotency_key' => 'ticket:1:message:10:mobile:'.hash('sha256', '09123456789'),
        ]);
        Http::assertSentCount(1);

        $sms->sendEvent('09123456789', 'ticket_reply', 'پاسخ دوم', ['token' => 'T-1'], 'ticket:1:message:11');

        $this->assertDatabaseCount('sms_logs', 2);
        Http::assertSentCount(2);
    }

    public function test_failed_sms_event_can_be_retried_without_creating_a_second_log(): void
    {
        Http::fakeSequence()
            ->push(['return' => ['status' => 500]], 500)
            ->push(['return' => ['status' => 200]], 200);

        $sms = app(SmsService::class);
        $key = 'ticket:2:message:20';

        $this->assertFalse($sms->sendEvent('09123456789', 'ticket_reply', 'پاسخ', [], $key));
        $this->assertTrue($sms->sendEvent('09123456789', 'ticket_reply', 'پاسخ', [], $key));

        $this->assertDatabaseCount('sms_logs', 1);
        $this->assertDatabaseHas('sms_logs', [
            'idempotency_key' => $key.':mobile:'.hash('sha256', '09123456789'),
            'status' => 'accepted',
            'attempts' => 2,
        ]);
        Http::assertSentCount(2);
    }
}
