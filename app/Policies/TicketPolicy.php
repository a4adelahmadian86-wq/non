<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthorizationService;

class TicketPolicy
{
    private function canManageSupport(User $user, Ticket $ticket): bool
    {
        return app(AuthorizationService::class)->allowsRecord($user, 'support.manage', $ticket);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return (int) $ticket->user_id === (int) $user->id
            || $this->canManageSupport($user, $ticket);
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

        return (int) $ticket->user_id === (int) $user->id
            || $this->canManageSupport($user, $ticket);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return (int) $ticket->user_id === (int) $user->id
            || $this->canManageSupport($user, $ticket);
    }

    public function manage(User $user, Ticket $ticket): bool
    {
        return $this->canManageSupport($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->canManageSupport($user, $ticket);
    }
}
