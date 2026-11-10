<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\FarastNotification;
use App\Models\Ticket;
use App\Services\CapabilityService;
use App\Services\MessengerAccessService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class CommunicationCenterController extends Controller
{
    public function __invoke(
        Request $request,
        NotificationService $notifications,
        CapabilityService $capabilities,
        MessengerAccessService $messengerAccess,
    ) {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $canSupport = $isAdmin || $capabilities->allowed($user, 'can_support');
        $canUseMessenger = $messengerAccess->canUse($user);

        $ticketQuery = Ticket::query()->with(['user:id,name,email', 'assignee:id,name']);
        if (! $isAdmin) {
            $ticketQuery->where('user_id', $user->id);
        }
        if (! $canSupport) {
            $ticketQuery->whereRaw('1 = 0');
        }
        $tickets = $ticketQuery->orderByDesc('updated_at')->limit(10)->get();

        $conversations = collect();
        if ($canUseMessenger) {
            $conversations = Conversation::query()
                ->visibleTo((int) $user->id)
                ->when(! $isAdmin, function ($query) use ($user) {
                    $query->whereDoesntHave('participants', function ($participants) use ($user) {
                        $participants->where('users.role', '!=', \App\Models\User::ROLE_ADMIN)
                            ->where(function ($scope) use ($user) {
                                $scope->whereNull('users.organization_id')
                                    ->orWhere('users.organization_id', '!=', $user->organization_id);
                            });
                    });
                })
                ->with('participants:id,name')
                ->withCount(['messages as unread_messages_count' => function ($query) use ($user) {
                    $userId = (int) $user->id;
                    $query->where('user_id', '!=', $userId)
                        ->whereRaw('conversation_messages.id > COALESCE((SELECT last_read_message_id FROM conversation_participants WHERE conversation_participants.conversation_id = conversation_messages.conversation_id AND conversation_participants.user_id = ? LIMIT 1), 0)', [$userId]);
                }])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        }

        $recentNotifications = $notifications->latest((int) $user->id, 10);
        $unreadNotifications = $notifications->unreadCount((int) $user->id);

        $feed = collect()
            ->merge($tickets->map(fn (Ticket $ticket) => [
                'kind' => 'ticket',
                'title' => $ticket->displayCode().' — '.$ticket->subject,
                'body' => $ticket->displayName().' · '.$ticket->statusLabel().' · '.$ticket->priorityLabel(),
                'url' => $isAdmin
                    ? route('admin.support.management', ['ticket' => $ticket->id])
                    : route('support.show', $ticket),
                'at' => $ticket->updated_at,
                'sort' => $ticket->updated_at?->getTimestamp() ?? 0,
            ]))
            ->merge($conversations->map(fn (Conversation $conversation) => [
                'kind' => 'conversation',
                'title' => $conversation->title ?: ($conversation->participants->firstWhere('id', '!=', $user->id)?->name ?? 'گفت‌وگوی خصوصی'),
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
            'tickets_needing_reply' => $isAdmin ? Ticket::query()->needsReply()->count() : $tickets->whereIn('status', ['answered', 'user_replied'])->count(),
            'tickets_open' => $isAdmin ? Ticket::query()->openQueue()->count() : $tickets->where('status', '!=', 'closed')->count(),
            'conversations_unread' => (int) $conversations->sum('unread_messages_count'),
            'notifications_unread' => $unreadNotifications,
        ];

        return view('admin.communications.index', compact(
            'tickets',
            'conversations',
            'recentNotifications',
            'feed',
            'stats',
            'isAdmin',
            'canSupport',
            'canUseMessenger',
        ));
    }
}
