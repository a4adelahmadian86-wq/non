<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BroadcastDueAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:broadcast-due';

    protected $description = 'Broadcast active announcements whose start window has begun and not yet notified';

    public function handle(AnnouncementService $service): int
    {
        if (! Schema::hasTable('announcements')) {
            return self::SUCCESS;
        }

        $q = Announcement::query()
            ->where('is_active', true)
            ->whereNull('notified_at')
            ->where(function ($w) {
                $w->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($w) {
                $w->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('id')
            ->limit(50);

        $count = 0;
        foreach ($q->get() as $announcement) {
            $sent = $service->broadcast($announcement);
            $this->line("Broadcast #{$announcement->id} sent={$sent}");
            $count++;
        }

        $this->info("Done. announcements={$count}");

        return self::SUCCESS;
    }
}
