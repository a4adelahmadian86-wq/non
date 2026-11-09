<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\MailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverFarastEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    public function __construct(
        public int $emailLogId,
        public string $recipient,
        public Mailable $mailable,
    ) {
        $this->onQueue('default');
    }

    public function handle(MailConfigService $mailConfig): void
    {
        $log = EmailLog::query()->find($this->emailLogId);
        if (! $log || in_array($log->status, ['accepted', 'logged'], true)) {
            return;
        }

        $mailConfig->apply();
        $provider = $mailConfig->currentProvider();
        $mailConfig->assertAvailable($provider);

        $log->increment('attempts');
        $log->forceFill([
            'last_attempt_at' => now(),
            'error' => null,
            'meta' => array_merge($log->meta ?? [], ['provider' => $provider]),
        ])->save();

        Mail::to($this->recipient)->send($this->mailable);

        if ($provider === 'log') {
            $log->update([
                'status' => 'logged',
                'sent_at' => null,
                'accepted_at' => null,
                'error' => null,
            ]);

            return;
        }

        $log->update([
            'status' => 'accepted',
            'sent_at' => now(),
            'accepted_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        EmailLog::query()->whereKey($this->emailLogId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception->getMessage(), 0, 1000),
            'last_attempt_at' => now(),
        ]);
    }
}
