<?php

namespace App\Http\Controllers;

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
        if (! in_array($filter, ['all', 'unread', 'support', 'finance', 'system'], true)) {
            $filter = 'all';
        }

        $items = $this->notifications->paginateForUser(auth()->id(), $filter === 'all' ? null : $filter, 20);
        $counts = $this->notifications->countsByCategory(auth()->id());

        return view('platform.notifications', compact('items', 'filter', 'counts'));
    }

    public function read(Request $request, int $id)
    {
        $this->notifications->markRead(auth()->id(), $id);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function readAll(Request $request)
    {
        $n = $this->notifications->markAllRead(auth()->id());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'marked' => $n]);
        }

        return back()->with('status', $n ? $n.' اعلان خوانده شد.' : 'اعلان خوانده‌نشده‌ای نبود.');
    }

    public function unreadCount()
    {
        return response()->json([
            'ok' => true,
            'count' => $this->notifications->unreadCount(auth()->id()),
        ]);
    }

    public function latest()
    {
        $items = $this->notifications->latest(auth()->id(), 8)->map(function ($n) {
            return [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type,
                'category' => $n->category(),
                'icon' => $n->icon(),
                'url' => $n->url(),
                'read' => $n->isRead(),
                'time' => $n->created_at?->timezone(config('app.timezone'))->diffForHumans(),
            ];
        });

        return response()->json([
            'ok' => true,
            'count' => $this->notifications->unreadCount(auth()->id()),
            'items' => $items,
        ]);
    }
}
