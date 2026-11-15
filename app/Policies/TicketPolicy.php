<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Http\Middleware\ResolveAdminScope;

class TicketPolicy
{
    private function canManageSupport(User $user, Ticket $ticket): bool
    {
        // Legacy admin-role fallback in AuthorizationService is global by design.
        // Apply the active admin data scope first so a narrowed admin cannot use a
        // direct ticket URL to bypass the inbox/query scope.
        if ($user->isAdmin()) {
            $requestScope = (int) auth()->id() === (int) $user->id ? request()->attributes->get('farast.scope') : null;
            $scope = is_string($requestScope)
                ? $requestScope
                : ResolveAdminScope::scopeWithConfiguredOverride($user);
            $visibleUserIds = ResolveAdminScope::visibleUserIds($user, $scope);
            if ($visibleUserIds !== null) {
                $visibleUserIds = array_values(array_unique(array_merge(
                    array_map('intval', $visibleUserIds),
                    [(int) $user->id],
                )));

                if ($ticket->user_id === null || ! in_array((int) $ticket->user_id, $visibleUserIds, true)) {
                    return false;
                }
            }
        }

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

    /**
     * Customer satisfaction scores are customer-owned feedback, not a staff action.
     */
    public function rate(User $user, Ticket $ticket): bool
    {
        return (int) $ticket->user_id === (int) $user->id;
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
