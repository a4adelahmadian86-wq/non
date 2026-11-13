<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for discovering support agents and checking their
 * record-level eligibility. Candidate discovery is narrowed in SQL; the final
 * decision always goes through AuthorizationService.
 */
class SupportAgentDirectory
{
    public function eligibleFor(Ticket $ticket): Collection
    {
        $query = User::query()
            ->where(function ($blocked): void {
                $blocked->where('is_blocked', false)->orWhereNull('is_blocked');
            })
            ->where(function ($candidates): void {
                // Legacy administrators remain compatible, subject to managed-access rules.
                $candidates->where('role', User::ROLE_ADMIN);

                if (Schema::hasTable('role_assignments')
                    && Schema::hasTable('roles')
                    && Schema::hasTable('role_permissions')
                    && Schema::hasTable('permissions')) {
                    $candidates->orWhereIn('id', DB::table('role_assignments')
                        ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                        ->join('role_permissions', 'role_permissions.role', '=', 'roles.key')
                        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                        ->where('permissions.key', 'support.manage')
                        ->where('roles.is_active', true)
                        ->where(fn ($q) => $q->whereNull('role_assignments.starts_at')->orWhere('role_assignments.starts_at', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('role_assignments.expires_at')->orWhere('role_assignments.expires_at', '>', now()))
                        ->select('role_assignments.user_id'));
                }

                if (Schema::hasTable('user_permission_grants') && Schema::hasTable('permissions')) {
                    $candidates->orWhereIn('id', DB::table('user_permission_grants')
                        ->join('permissions', 'permissions.id', '=', 'user_permission_grants.permission_id')
                        ->where('permissions.key', 'support.manage')
                        ->where('user_permission_grants.effect', 'allow')
                        ->where(fn ($q) => $q->whereNull('user_permission_grants.starts_at')->orWhere('user_permission_grants.starts_at', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('user_permission_grants.expires_at')->orWhere('user_permission_grants.expires_at', '>', now()))
                        ->select('user_permission_grants.user_id'));
                }

                if (Schema::hasTable('role_permissions') && Schema::hasTable('permissions')) {
                    $candidates->orWhereExists(function ($q): void {
                        $q->selectRaw('1')
                            ->from('role_permissions')
                            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                            ->whereColumn('role_permissions.role', 'users.role')
                            ->where('permissions.key', 'support.manage');
                    });
                }
            })
            ->orderBy('id');

        $authorization = app(AuthorizationService::class);

        return $query->get()
            ->filter(fn (User $user): bool => $authorization->allowsRecord($user, 'support.manage', $ticket))
            ->values();
    }

    public function allows(User $agent, Ticket $ticket): bool
    {
        return ! (bool) $agent->is_blocked
            && app(AuthorizationService::class)->allowsRecord($agent, 'support.manage', $ticket);
    }
}
