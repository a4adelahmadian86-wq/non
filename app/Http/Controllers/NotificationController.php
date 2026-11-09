<?php

namespace App\Http\Controllers;

use App\Models\FarastNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        $filter = $request->string('filter')->toString() ?: 'all';
        $allowed = ['all', 'unread', 'support', 'finance', 'announcement', 'community', 'security', 'marketing', 'system', 'archived'];
        if (! in_array($filter, $allowed, true)) $filter = 'all';

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) ($validated['q'] ?? ''));

        $items = $this->notifications->paginateForUser(
            (int) $request->user()->id,
            $filter === 'all' ? null : $filter,
            20,
            $search,
        );
        $counts = $this->notifications->countsByCategory((int) $request->user()->id);

        return view('platform.notifications', compact('items', 'filter', 'counts', 'search'));
    }

    public function read(Request $request, int $id)
    {
        $this->notifications->markRead((int) $request->user()->id, $id);

        if ($request->wantsJson() || $request->ajax()) return response()->json(['ok' => true]);

        return back();
    }

    public function readAll(Request $request)
    {
        $count = $this->notifications->markAllRead((int) $request->user()->id);

        if ($request->wantsJson() || $request->ajax()) return response()->json(['ok' => true, 'marked' => $count]);

        return back()->with('status', $count ? $count.' اعلان خوانده شد.' : 'اعلان خوانده‌نشده‌ای نبود.');
    }

    public function archive(Request $request, int $id)
    {
        abort_unless($this->notifications->archive((int) $request->user()->id, $id), 404);

        return back()->with('status', 'اعلان به بایگانی شخصی منتقل شد.');
    }

    public function restore(Request $request, int $id)
    {
        abort_unless($this->notifications->restore((int) $request->user()->id, $id), 404);

        return back()->with('status', 'اعلان به فهرست اصلی بازگردانده شد.');
    }

    public function visit(Request $request, int $id)
    {
        $notification = FarastNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
        $target = $notification->url();

        if (! $target) {
            $this->notifications->markRead((int) $request->user()->id, $notification->id);
            return redirect()->route('platform.notifications');
        }

        $parts = parse_url($target);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $targetHost = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $safeRelative = str_starts_with($target, '/') && ! str_starts_with($target, '//');
        $safeAbsolute = in_array($scheme, ['http', 'https'], true)
            && is_string($targetHost) && is_string($appHost)
            && hash_equals(strtolower($appHost), strtolower($targetHost));

        $this->notifications->markRead((int) $request->user()->id, $notification->id);

        if (! $safeRelative && ! $safeAbsolute) return redirect()->route('platform.notifications');

        return redirect()->to($target);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'ok' => true,
            'count' => $this->notifications->unreadCount((int) $request->user()->id),
        ]);
    }

    public function latest(Request $request)
    {
        $items = $this->notifications->latest((int) $request->user()->id, 8)->map(function ($notification) {
            return [
                'id' => $notification->id,
                'title' => $notification->title,
                'body' => $notification->body,
                'type' => $notification->type,
                'category' => $notification->category(),
                'category_label' => $notification->categoryLabel(),
                'icon' => $notification->icon(),
                'url' => $notification->url(),
                'visit_url' => route('platform.notifications.visit', $notification->id),
                'read' => $notification->isRead(),
                'time' => $notification->created_at?->timezone(config('app.timezone'))->diffForHumans(),
            ];
        });

        return response()->json([
            'ok' => true,
            'count' => $this->notifications->unreadCount((int) $request->user()->id),
            'items' => $items,
        ]);
    }
}
