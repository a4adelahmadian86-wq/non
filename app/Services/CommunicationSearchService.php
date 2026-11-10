<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FarastNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CommunicationSearchService
{
    /**
     * Search only records the current actor can already open. This is a read
     * model over the existing domain stores; it never creates a second index.
     */
    public function search(User $actor, string $term, bool $canSupport, bool $canUseMessenger, int $limit = 30): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $limit = max(1, min($limit, 50));
        $results = collect();
        $isAdmin = $actor->isAdmin();
        $pattern = '%'.$term.'%';

        if ($canSupport) {
            $tickets = Ticket::query()
                ->with(['user:id,name', 'relatedOrder:id,user_id,status,total_rials'])
                ->when(! $isAdmin, fn ($query) => $query->where('user_id', $actor->id))
                ->where(function ($query) use ($pattern, $term) {
                    $query->where('subject', 'like', $pattern)
                        ->orWhere('ticket_code', 'like', $pattern);
                })
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();

            foreach ($tickets as $ticket) {
                $results->push($this->item(
                    'ticket',
                    $ticket->displayCode().' — '.$ticket->subject.($ticket->relatedOrder ? ' · سفارش #'.$ticket->relatedOrder->id : ''),
                    $ticket->displayName().' · '.$ticket->statusLabel(),
                    $isAdmin
                        ? route('admin.support.management', ['ticket' => $ticket->id])
                        : route('support.show', $ticket),
                    $ticket->updated_at,
                    'ticket:'.$ticket->id,
                ));
            }

            $ticketMessages = TicketMessage::query()
                ->with(['ticket.relatedOrder'])
                ->where('body', 'like', $pattern)
                ->whereHas('ticket', function ($query) use ($actor, $isAdmin) {
                    if (! $isAdmin) {
                        $query->where('user_id', $actor->id);
                    }
                })
                ->when(! $isAdmin, fn ($query) => $query->where(function ($visible) {
                    $visible->where('is_internal', false)->orWhereNull('is_internal');
                }))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            foreach ($ticketMessages as $message) {
                $ticket = $message->ticket;
                if (! $ticket) {
                    continue;
                }
                $results->push($this->item(
                    'ticket',
                    'پیام در '.$ticket->displayCode().' — '.$ticket->subject.($ticket->relatedOrder ? ' · سفارش #'.$ticket->relatedOrder->id : ''),
                    Str::limit(trim(strip_tags((string) $message->body)), 180),
                    $isAdmin
                        ? route('admin.support.management', ['ticket' => $ticket->id])
                        : route('support.show', $ticket),
                    $message->created_at,
                    'ticket-message:'.$message->id,
                ));
            }
        }

        if ($canUseMessenger) {
            $visibleConversations = fn ($query) => $query
                ->visibleTo((int) $actor->id)
                ->when(! $isAdmin, function ($scope) use ($actor) {
                    $scope->whereDoesntHave('participants', function ($participants) use ($actor) {
                        $participants->where('users.role', '!=', User::ROLE_ADMIN)
                            ->where(function ($organization) use ($actor) {
                                $organization->whereNull('users.organization_id')
                                    ->orWhere('users.organization_id', '!=', $actor->organization_id);
                            });
                    });
                });

            $conversations = Conversation::query()
                ->where($visibleConversations)
                ->where('title', 'like', $pattern)
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get();

            foreach ($conversations as $conversation) {
                $results->push($this->item(
                    'conversation',
                    $conversation->title ?: 'گفت‌وگوی خصوصی',
                    $conversation->last_message_preview ?: 'گفت‌وگو',
                    route('messenger.show', $conversation),
                    $conversation->last_message_at ?? $conversation->updated_at,
                    'conversation:'.$conversation->id,
                ));
            }

            $messages = ConversationMessage::query()
                ->with('conversation')
                ->whereNull('deleted_at')
                ->where('body', 'like', $pattern)
                ->whereHas('conversation', $visibleConversations)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            foreach ($messages as $message) {
                $conversation = $message->conversation;
                if (! $conversation) {
                    continue;
                }
                $results->push($this->item(
                    'conversation',
                    'پیام در '.($conversation->title ?: 'گفت‌وگوی خصوصی'),
                    Str::limit(trim(strip_tags((string) $message->body)), 180),
                    route('messenger.show', $conversation),
                    $message->created_at,
                    'conversation-message:'.$message->id,
                ));
            }
        }

        $notifications = FarastNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->where(function ($query) use ($pattern) {
                $query->where('title', 'like', $pattern)
                    ->orWhere('body', 'like', $pattern);
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        foreach ($notifications as $notification) {
            $results->push($this->item(
                'notification',
                $notification->title,
                $notification->body ?: $notification->categoryLabel(),
                route('platform.notifications.visit', $notification->id),
                $notification->created_at,
                'notification:'.$notification->id,
            ));
        }

        return $results
            ->sortByDesc(fn (array $item) => $item['sort'])
            ->unique('key')
            ->take($limit)
            ->values();
    }

    private function item(string $kind, string $title, string $body, string $url, $at, string $key): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'at' => $at,
            'sort' => $at?->getTimestamp() ?? 0,
            'key' => $key,
        ];
    }

}
