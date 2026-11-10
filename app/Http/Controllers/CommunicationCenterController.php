<?php

namespace App\\Http\\Controllers;

use App\\Models\\Conversation;
use App\\Models\\FarastNotification;
use App\\Models\\Ticket;
use App\\Services\\NotificationService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Collection;

class CommunicationCenterController extends Controller
{
    public function __invoke(Request $request, NotificationService $notifications)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $tickets = Ticket::query()
            ->with(['user:id,name,email', 'assignee:id,name'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        $conversations = Conversation::query()
            ->visibleTo((int) $request->user()->id)
            ->with('participants:id,name')
            ->withCount(['messages as unread_messages_count' => function ($query) use ($request) {
                $userId = (int) $request->user()->id;
                $query->where('user_id', '!=', $userId)
                    ->whereRaw('conversation_messages.id > COALESCE((SELECT last_read_message_id FROM conversation_participants WHERE conversation_participants.conversation_id = conversation_messages.conversation_id AND conversation_participants.user_id = ? LIMIT 1), 0)', [$userId]);
            }])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $recentNotifications = $notifications->latest((int) $request->user()->id, 10);
        $unreadNotifications = $notifications->unreadCount((int) $request->user()->id);

        $feed = collect()
            ->merge($tickets->map(fn (Ticket $ticket) => [
                'kind' => 'ticket',
                'title' => $ticket->displayCode().' — '.$ticket->subject,
                'body' => $ticket->displayName().' · '.$ticket->statusLabel().' · '.$ticket->priorityLabel(),
                'url' => route('admin.support.management', ['ticket' => $ticket->id]),
                'at' => $ticket->updated_at,
                'sort' => $ticket->updated_at?->getTimestamp() ?? 0,
            ]))
            ->merge($conversations->map(fn (Conversation $conversation) => [
                'kind' => 'conversation',
                'title' => $conversation->title ?: ($conversation->participants->firstWhere('id', '!=', $request->user()->id)?->name ?? 'گفت‌وگوی خصوصی'),
                'body' => $conversation->last_message_preview ?: 'گفت‌وگو ایجاد شده است',
                'url' => route('messenger.show', $conversation),
                'at' => $conversation->last_message_at ?? $conversation->updated_at,
                'sort' => ($conversation->last_message_at ?? $conversation->updated_at)?->getTimestamp() ?? 0,
            ]))
            ->merge($recentNotifications->map(fn (FarastNotification $notification) => [
                'kind' => 'notification',
                'title' => $notification->title,
                'body' => $notification->body ?: $notification->categoryLabel(),
                'url' => route('platform.notifications.visit', $notification->id),
                'at' => $notification->created_at,
                'sort' => $notification->created_at?->getTimestamp() ?? 0,
            ]))
            ->sortByDesc('sort')
            ->take(24)
            ->values();

        $stats = [
            'tickets_needing_reply' => Ticket::query()->needsReply()->count(),
            'tickets_open' => Ticket::query()->openQueue()->count(),
            'conversations_unread' => (int) $conversations->sum('unread_messages_count'),
            'notifications_unread' => $unreadNotifications,
        ];

        return view('admin.communications.index', compact(
            'tickets',
            'conversations',
            'recentNotifications',
            'feed',
            'stats',
        ));
    }
}
