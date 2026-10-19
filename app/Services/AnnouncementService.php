<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * مدیریت انتشار اعلان‌های عمومی و هماهنگی با اعلان درون‌برنامه / ایمیل.
 */
class AnnouncementService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly EmailService $email,
    ) {}

    public function create(array $data, ?int $createdBy = null): Announcement
    {
        $data['created_by'] = $createdBy ?? auth()->id();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['is_pinned'] = (bool) ($data['is_pinned'] ?? false);
        $data['notify_in_app'] = (bool) ($data['notify_in_app'] ?? true);
        $data['notify_email'] = (bool) ($data['notify_email'] ?? false);
        $data['audience'] = $data['audience'] ?? 'all';

        $announcement = Announcement::create($data);

        if ($announcement->is_active && $this->shouldBroadcastNow($announcement)) {
            $this->broadcast($announcement);
        }

        return $announcement;
    }

    public function update(Announcement $announcement, array $data): Announcement
    {
        $wasActive = $announcement->is_active;
        $data['is_active'] = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : $announcement->is_active;
        $data['is_pinned'] = array_key_exists('is_pinned', $data)
            ? (bool) $data['is_pinned']
            : ($announcement->is_pinned ?? false);
        $data['notify_in_app'] = array_key_exists('notify_in_app', $data)
            ? (bool) $data['notify_in_app']
            : ($announcement->notify_in_app ?? true);
        $data['notify_email'] = array_key_exists('notify_email', $data)
            ? (bool) $data['notify_email']
            : ($announcement->notify_email ?? false);

        $announcement->update($data);
        $announcement->refresh();

        if ($announcement->is_active && ! $wasActive && $this->shouldBroadcastNow($announcement) && ! $announcement->notified_at) {
            $this->broadcast($announcement);
        }

        return $announcement;
    }

    public function toggle(Announcement $announcement): Announcement
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);
        $announcement->refresh();

        if ($announcement->is_active && ! $announcement->notified_at && $this->shouldBroadcastNow($announcement)) {
            $this->broadcast($announcement);
        }

        return $announcement;
    }

    public function broadcast(Announcement $announcement): int
    {
        if (! Schema::hasTable('farast_notifications')) {
            return 0;
        }

        $sent = 0;
        $url = $announcement->link_url ?: route('announcements');
        $type = 'announcement_'.$announcement->type;

        $query = User::query()->where('is_blocked', false);

        match ($announcement->audience ?? 'all') {
            'admins' => $query->where('role', User::ROLE_ADMIN),
            'users' => $query->where('role', '!=', User::ROLE_ADMIN),
            default => null,
        };

        $query->orderBy('id')->chunkById(200, function ($users) use ($announcement, $type, $url, &$sent) {
            foreach ($users as $user) {
                if ($announcement->notify_in_app ?? true) {
                    $this->notifications->push(
                        $user->id,
                        $type,
                        $announcement->title,
                        Str::limit(strip_tags($announcement->body), 280),
                        [
                            'url' => $url,
                            'announcement_id' => $announcement->id,
                            'announcement_type' => $announcement->type,
                        ],
                    );
                    $sent++;
                }

                if (($announcement->notify_email ?? false) && $user->email) {
                    try {
                        $this->email->sendSystemAnnouncement($user, $announcement);
                    } catch (\Throwable $e) {
                        Log::warning('farast.announcement.email_failed', [
                            'user_id' => $user->id,
                            'announcement_id' => $announcement->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        if (Schema::hasColumn('announcements', 'notified_at')) {
            $announcement->update(['notified_at' => now()]);
        }

        Log::info('farast.announcement.broadcast', [
            'announcement_id' => $announcement->id,
            'audience' => $announcement->audience ?? 'all',
            'sent' => $sent,
        ]);

        return $sent;
    }

    protected function shouldBroadcastNow(Announcement $announcement): bool
    {
        if (! $announcement->is_active) {
            return false;
        }
        if ($announcement->starts_at && $announcement->starts_at->isFuture()) {
            return false;
        }
        if ($announcement->ends_at && $announcement->ends_at->isPast()) {
            return false;
        }

        return ($announcement->notify_in_app ?? true) || ($announcement->notify_email ?? false);
    }
}
