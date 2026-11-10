<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminConsoleService;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->ticketIsInScope($user, $ticket);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === 'closed') {
            return false;
        }

        return $this->ticketIsInScope($user, $ticket);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $this->ticketIsInScope($user, $ticket);
    }

    public function manage(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            && app(AdminConsoleService::class)->sectionAllowed($user, 'support_admin')
            && $this->ticketIsInScope($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->manage($user, $ticket);
    }

    /**
     * Admin roles may be narrowed to organization, team, or own scope.
     * Ticket access must follow that scope even on customer-facing routes.
     */
    private function ticketIsInScope(User $user, Ticket $ticket): bool
    {
        if (! $user->isAdmin()) {
            return (int) $ticket->user_id === (int) $user->id;
        }

        $visibleUserIds = app(AdminConsoleService::class)->visibleUserIds($user);

        return $visibleUserIds === null
            || ($ticket->user_id !== null && in_array((int) $ticket->user_id, array_map('intval', $visibleUserIds), true));
    }
}
