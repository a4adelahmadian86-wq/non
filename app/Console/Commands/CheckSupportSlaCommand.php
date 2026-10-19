<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckSupportSlaCommand extends Command
{
    protected $signature = 'support:check-sla {--dry-run : فقط گزارش بدون ارسال اعلان}';

    protected $description = 'پایش SLA تیکت‌ها و ارسال هشدار نزدیک‌به‌نقض / نقض';

    public function handle(SupportSlaService $sla, SupportNotificationService $notifier): int
    {
        if (! config('support.monitor_enabled', true)) {
            $this->info('پایش SLA غیرفعال است.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'sla_first_due_at')) {
            $this->warn('ستون‌های SLA موجود نیست. migrate را اجرا کنید.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $warnBefore = (int) config('support.warn_before_minutes', 30);

        $open = Ticket::query()
            ->openQueue()
            ->with(['assignee', 'user'])
            ->orderBy('id')
            ->limit(500)
            ->get();

        $stats = ['warned_first' => 0, 'breached_first' => 0, 'warned_resolve' => 0, 'breached_resolve' => 0];

        foreach ($open as $ticket) {
            // —— اولین پاسخ ——
            if (! $ticket->first_response_at && $ticket->sla_first_due_at) {
                $mins = (int) now()->diffInMinutes($ticket->sla_first_due_at, false);

                if ($mins < 0 && empty($ticket->sla_first_breached_at)) {
                    $stats['breached_first']++;
                    $this->line("BREACH first #{$ticket->id} {$ticket->displayCode()}");
                    if (! $dry) {
                        $ticket->forceFill(['sla_first_breached_at' => now()])->saveQuietly();
                        $notifier->slaBreached($ticket, 'first_response');
                    }
                } elseif ($mins >= 0 && $mins <= $warnBefore && empty($ticket->sla_first_warned_at)) {
                    $stats['warned_first']++;
                    $this->line("WARN first #{$ticket->id} ({$mins}m left)");
                    if (! $dry) {
                        $ticket->forceFill(['sla_first_warned_at' => now()])->saveQuietly();
                        $notifier->slaWarning($ticket, 'first_response', $mins);
                    }
                }
            }

            // —— حل کامل ——
            if ($ticket->status !== 'closed' && $ticket->sla_resolve_due_at) {
                $mins = (int) now()->diffInMinutes($ticket->sla_resolve_due_at, false);

                if ($mins < 0 && empty($ticket->sla_resolve_breached_at)) {
                    $stats['breached_resolve']++;
                    $this->line("BREACH resolve #{$ticket->id} {$ticket->displayCode()}");
                    if (! $dry) {
                        $ticket->forceFill(['sla_resolve_breached_at' => now()])->saveQuietly();
                        $notifier->slaBreached($ticket, 'resolution');
                    }
                } elseif ($mins >= 0 && $mins <= $warnBefore && empty($ticket->sla_resolve_warned_at)) {
                    $stats['warned_resolve']++;
                    $this->line("WARN resolve #{$ticket->id} ({$mins}m left)");
                    if (! $dry) {
                        $ticket->forceFill(['sla_resolve_warned_at' => now()])->saveQuietly();
                        $notifier->slaWarning($ticket, 'resolution', $mins);
                    }
                }
            }
        }

        $this->info(sprintf(
            'SLA check done. first(warn=%d,breach=%d) resolve(warn=%d,breach=%d)%s',
            $stats['warned_first'],
            $stats['breached_first'],
            $stats['warned_resolve'],
            $stats['breached_resolve'],
            $dry ? ' [dry-run]' : ''
        ));

        return self::SUCCESS;
    }
}
