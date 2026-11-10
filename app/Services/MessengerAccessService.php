<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MessengerAccessService
{
    public function canUse(User $user): bool
    {
        if ($user->is_blocked) return false;
        if ($user->isAdmin()) return true;

        return $user->organization_id !== null
            && in_array($user->role, [
                User::ROLE_ORGANIZATION_MANAGER,
                User::ROLE_TEAM_SUPERVISOR,
                User::ROLE_EMPLOYEE,
            ], true);
    }

    public function assertCanUse(User $user): void
    {
        abort_unless($this->canUse($user), 403, 'دسترسی به پیام‌رسان داخلی برای این حساب فعال نیست.');
    }

    public function usersFor(User $actor): Builder
    {
        $this->assertCanUse($actor);
        $query = User::query()->where('id', '!=', $actor->id)->where('is_blocked', false);

        if (! $actor->isAdmin()) {
            $query->where(function (Builder $q) use ($actor) {
                $q->where('organization_id', $actor->organization_id)
                    ->orWhere('role', User::ROLE_ADMIN);
            });
        }

        return $query;
    }

    public function canInvite(User $actor, User $candidate): bool
    {
        if (! $this->canUse($actor) || $candidate->is_blocked) return false;
        if ($actor->isAdmin()) return true;

        return $candidate->isAdmin()
            || ($candidate->organization_id !== null && (int) $candidate->organization_id === (int) $actor->organization_id);
    }

    public function canCreateGroup(User $actor, Collection $participants): bool
    {
        if (! $this->canUse($actor)) return false;
        $members = $participants->reject(fn (User $user) => $user->isAdmin());

        if ($members->contains(fn (User $user) => $user->organization_id === null)) return false;

        return $members->pluck('organization_id')->unique()->count() <= 1;
    }

    public function canAddToGroup(User $actor, Conversation $conversation, Collection $candidates): bool
    {
        $current = $conversation->participants()->get();
        return $this->canCreateGroup($actor, $current->merge($candidates));
    }

    public function assertConversationScope(User $actor, Conversation $conversation): void
    {
        $this->assertCanUse($actor);
        if ($actor->isAdmin()) return;

        $foreignMemberExists = $conversation->participants()
            ->where('users.role', '!=', User::ROLE_ADMIN)
            ->where(function (Builder $query) use ($actor) {
                $query->whereNull('users.organization_id')
                    ->orWhere('users.organization_id', '!=', $actor->organization_id);
            })
            ->exists();

        abort_if($foreignMemberExists, 404);
    }
}
