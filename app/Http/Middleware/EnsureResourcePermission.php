<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class EnsureResourcePermission
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function handle(Request $request, Closure $next, string $permission, string $parameter = 'resource')
    {
        $resource = $request->route($parameter);
        abort_unless($resource instanceof Model, 404, 'منبع موردنظر پیدا نشد.');

        $attributes = $resource->getAttributes();
        $context = [
            'resource_id' => $resource->getKey(),
            'resource_type' => $resource::class,
            // Preserve an explicitly-null owner_id: falling through to user_id
            // could grant access to a record whose authoritative owner is absent.
            'owner_id' => array_key_exists('owner_id', $attributes)
                ? $attributes['owner_id']
                : (array_key_exists('resource_user_id', $attributes)
                    ? $attributes['resource_user_id']
                    : ($attributes['user_id'] ?? null)),
            'organization_id' => $attributes['organization_id'] ?? null,
            'team_id' => $attributes['team_id'] ?? null,
            'status' => $attributes['status'] ?? null,
        ];

        if ($resource instanceof User) {
            $context['owner_id'] = $resource->id;
            $context['organization_id'] = $resource->organization_id;
            $memberTeamIds = $resource->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
            $supervisedTeamIds = Team::query()->where('supervisor_id', $resource->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $context['team_ids'] = array_values(array_unique(array_merge($memberTeamIds, $supervisedTeamIds)));
        } elseif ($resource instanceof Organization) {
            $context['organization_id'] = $resource->id;
        } elseif ($resource instanceof Team) {
            // A supervisor is not the owner of the team record. Team access must
            // be granted by the team/organization scope, never by own-scope fallback.
            $context['owner_id'] = null;
            $context['team_id'] = $resource->id;
            $context['organization_id'] = $resource->organization_id;
        }

        abort_unless(
            $this->authorization->allowsFor($request->user(), $permission, $context),
            403,
            'دسترسی به این منبع در محدوده مجاز حساب شما نیست.'
        );

        return $next($request);
    }
}
