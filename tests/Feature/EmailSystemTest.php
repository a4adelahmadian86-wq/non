<?php

namespace Tests\\Feature;

use App\\Jobs\\DeliverFarastEmail;
use App\\Mail\\TestMail;
use App\\Models\\EmailLog;
use App\\Models\\SiteSetting;
use App\\Models\\User;
use App\\Services\\EmailService;
use App\\Services\\MailConfigService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Mail;
use Illuminate\\Support\\Facades\\Queue;
use Tests\\TestCase;

class EmailSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_transport_is_never_reported_as_a_real_delivery(): void
    {
        SiteSetting::write('mail_provider', 'log');
        Mail::fake();

        app(EmailService::class)->sendTest('qa@example.test');

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'qa@example.test',
            'type' => 'test',
            'status' => 'logged',
            'attempts' => 1,
            'sent_at' => null,
            'accepted_at' => null,
        ]);

        Mail::assertSent(TestMail::class);
    }

    public function test_transactional_messages_use_the_existing_database_queue_by_default(): void
    {
        config(['queue.default' => 'database']);
        SiteSetting::write('mail_provider', 'log');
        SiteSetting::write('email_sync', '0');
        Queue::fake();

        $user = User::create([
            'name' => 'Email Queue QA',
            'mobile' => '09150000991',
            'email' => 'queue@example.test',
            'password' => 'secret',
        ]);

        app(EmailService::class)->sendWelcome($user);

        Queue::assertPushed(DeliverFarastEmail::class);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'queue@example.test',
            'type' => 'welcome',
            'status' => 'queued',
            'attempts' => 0,
        ]);
    }

    public function test_legacy_third_party_provider_settings_fall_back_to_internal_log_mode(): void
    {
        SiteSetting::write('mail_provider', 'resend');

        $mailConfig = app(MailConfigService::class);

        $this->assertSame('log', $mailConfig->currentProvider());
        $this->assertSame(['local', 'sendmail', 'log'], array_keys(MailConfigService::PROVIDERS));
    }
}
