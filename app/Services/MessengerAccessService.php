<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MessengerAccessService
{
    private const STAFF_ROLES = [
        User::ROLE_ORGANIZATION_MANAGER,
        User::ROLE_TEAM_SUPERVISOR,
        User::ROLE_EMPLOYEE,
    ];

    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function canUse(User $user): bool
    {
        if ($user->is_blocked) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->organization_id !== null
            && in_array($user->role, self::STAFF_ROLES, true)
            && $this->authorization->allows($user, 'messenger.use');
    }

    public function assertCanUse(User $user): void
    {
        abort_unless($this->canUse($user), 403, 'دسترسی به پیام‌رسان داخلی برای این حساب فعال نیست.');
    }

    public function usersFor(User $actor): Builder
    {
        $this->assertCanUse($actor);
        $query = User::query()->where('id', '!=', $actor->id)->where('is_blocked', false);

        if ($actor->isAdmin()) {
            return $query->where(function (Builder $q) {
                $q->where('role', User::ROLE_ADMIN)
                    ->orWhere(function (Builder $staff) {
                        $staff->whereIn('role', self::STAFF_ROLES)->whereNotNull('organization_id');
                    });
            });
        }

        if (! $this->authorization->allows($actor, 'messenger.invite')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($actor) {
            $q->where('role', User::ROLE_ADMIN)
                ->orWhere(function (Builder $staff) use ($actor) {
                    $staff->whereIn('role', self::STAFF_ROLES)
                        ->where('organization_id', $actor->organization_id);
                });
        });
    }

    public function canInvite(User $actor, User $candidate): bool
    {
        if (! $this->canUse($actor)
            || ! $this->authorization->allows($actor, 'messenger.invite')) {
            return false;
        }

        return $this->canShareConversationWith($actor, $candidate);
    }

    public function canCreateGroup(User $actor, Collection $participants): bool
    {
        if (! $this->canUse($actor)
            || ! $this->authorization->allows($actor, 'messenger.group.create')) {
            return false;
        }

        return $this->participantsShareAllowedOrganization($actor, $participants);
    }

    public function canAddToGroup(User $actor, Conversation $conversation, Collection $candidates): bool
    {
        if (! $this->canUse($actor)
            || ! $this->authorization->allows($actor, 'messenger.group.manage')) {
            return false;
        }

        return $this->participantsShareAllowedOrganization(
            $actor,
            $conversation->participants()->get()->merge($candidates)
        );
    }

    public function assertConversationScope(User $actor, Conversation $conversation): void
    {
        $this->assertCanUse($actor);
        if ($actor->isAdmin()) {
            return;
        }

        $foreignMemberExists = $conversation->participants()
            ->where(function (Builder $query) use ($actor) {
                $query->where('users.is_blocked', true)
                    ->orWhereNotIn('users.role', self::STAFF_ROLES)
                    ->orWhereNull('users.organization_id')
                    ->orWhere('users.organization_id', '!=', $actor->organization_id);
            })
            ->exists();

        abort_if($foreignMemberExists, 404);
    }

    private function canShareConversationWith(User $actor, User $candidate): bool
    {
        if ($candidate->is_blocked) {
            return false;
        }
        if ($candidate->isAdmin()) {
            return true;
        }
        if (! in_array($candidate->role, self::STAFF_ROLES, true) || $candidate->organization_id === null) {
            return false;
        }

        return $actor->isAdmin()
            || ($actor->organization_id !== null
                && (int) $candidate->organization_id === (int) $actor->organization_id);
    }

    private function participantsShareAllowedOrganization(User $actor, Collection $participants): bool
    {
        $members = $participants->reject(fn (User $user) => $user->isAdmin());

        if ($members->contains(fn (User $user) => $user->is_blocked
            || ! in_array($user->role, self::STAFF_ROLES, true)
            || $user->organization_id === null)) {
            return false;
        }

        if (! $actor->isAdmin()
            && ($actor->organization_id === null
                || $members->contains(fn (User $user) => (int) $user->organization_id !== (int) $actor->organization_id))) {
            return false;
        }

        if ($members->pluck('organization_id')->unique()->count() > 1) {
            return false;
        }

        return $participants->every(fn (User $user) => $this->canShareConversationWith($actor, $user));
    }
}
