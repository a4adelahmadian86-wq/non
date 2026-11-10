<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\FarastNotification;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\AdminConsoleService;
use App\Services\CapabilityService;
use App\Services\MessengerAccessService;
use App\Services\NotificationService;
use App\Services\CommunicationSearchService;
use Illuminate\Http\Request;

class CommunicationCenterController extends Controller
{
    public function __invoke(
        Request $request,
        NotificationService $notifications,
        CapabilityService $capabilities,
        MessengerAccessService $messengerAccess,
        CommunicationSearchService $communicationSearch,
        AdminConsoleService $adminConsole,
    ) {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        // An admin role may be deliberately narrowed by the console scope override.
        // Only the global support section may aggregate all customers' records.
        $canManageAllTickets = $isAdmin && $adminConsole->sectionAllowed($user, 'support_admin');
        $canSupport = $capabilities->allowed($user, 'can_support');
        $canUseMessenger = $messengerAccess->canUse($user);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $searchTerm = trim((string) ($validated['q'] ?? ''));
        $searchResults = $searchTerm !== ''
            ? $communicationSearch->search($user, $searchTerm, $canSupport, $canUseMessenger, 30, $canManageAllTickets)
            : collect();

        $ticketQuery = Ticket::query()->with(['user:id,name,email', 'assignee:id,name']);
        if (! $canManageAllTickets) {
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

        $unreadConversationsCount = 0;
        if ($canUseMessenger) {
            $unreadQuery = DB::table('conversation_messages')
                ->join('conversation_participants', 'conversation_participants.conversation_id', '=', 'conversation_messages.conversation_id')
                ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
                ->where('conversation_participants.user_id', $user->id)
                ->whereNull('conversation_participants.archived_at')
                ->whereNull('conversation_messages.deleted_at')
                ->whereColumn('conversation_messages.user_id', '!=', 'conversation_participants.user_id')
                ->whereRaw('conversation_messages.id > COALESCE(conversation_participants.last_read_message_id, 0)');

            if (! $isAdmin) {
                $unreadQuery->whereNotExists(function ($subquery) use ($user) {
                    $subquery->selectRaw('1')
                        ->from('conversation_participants as other_participants')
                        ->join('users as other_users', 'other_users.id', '=', 'other_participants.user_id')
                        ->whereColumn('other_participants.conversation_id', 'conversations.id')
                        ->where('other_users.role', '!=', User::ROLE_ADMIN)
                        ->where(function ($scope) use ($user) {
                            $scope->whereNull('other_users.organization_id')
                                ->orWhere('other_users.organization_id', '!=', $user->organization_id);
                        });
                });
            }

            $unreadConversationsCount = (int) $unreadQuery->count();
        }

        $recentNotifications = $notifications->latest((int) $user->id, 10);
        $unreadNotifications = $notifications->unreadCount((int) $user->id);

        $feed = collect()
            ->merge($tickets->map(fn (Ticket $ticket) => [
                'kind' => 'ticket',
                'title' => $ticket->displayCode().' — '.$ticket->subject,
                'body' => $ticket->displayName().' · '.$ticket->statusLabel().' · '.$ticket->priorityLabel(),
                'url' => $canManageAllTickets
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

        $customerUnreadTickets = 0;
        if ((! $isAdmin || ! $canManageAllTickets) && $canSupport) {
            $unreadTicketsQuery = Ticket::query()->where('user_id', $user->id)->where('status', 'answered');
            if (Schema::hasColumn('tickets', 'customer_last_seen_at') && Schema::hasColumn('tickets', 'last_reply_at')) {
                $unreadTicketsQuery->where(function ($query) {
                    $query->whereNull('customer_last_seen_at')
                        ->orWhereColumn('last_reply_at', '>', 'customer_last_seen_at');
                });
            }
            $customerUnreadTickets = $unreadTicketsQuery->count();
        }

        $scopedTicketStats = Ticket::query();
        if (! $canManageAllTickets) {
            $scopedTicketStats->where('user_id', $user->id);
        }
        if (! $canSupport) {
            $scopedTicketStats->whereRaw('1 = 0');
        }

        $stats = [
            'tickets_needing_reply' => $canManageAllTickets
                ? Ticket::query()->needsReply()->count()
                : ($isAdmin ? (clone $scopedTicketStats)->needsReply()->count() : $customerUnreadTickets),
            'tickets_open' => $canManageAllTickets
                ? Ticket::query()->openQueue()->count()
                : (clone $scopedTicketStats)->where('status', '!=', 'closed')->count(),
            'conversations_unread' => $unreadConversationsCount,
            'notifications_unread' => $unreadNotifications,
        ];

        return view('admin.communications.index', compact(
            'tickets',
            'conversations',
            'recentNotifications',
            'feed',
            'stats',
            'isAdmin',
            'canManageAllTickets',
            'canSupport',
            'canUseMessenger',
            'searchTerm',
            'searchResults',
        ));
    }
}
