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

    private function ownerIdFromAttributes(array $attributes): mixed
    {
        foreach (['owner_id', 'resource_user_id', 'user_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                return $attributes[$field];
            }
        }

        return null;
    }

    public function handle(Request $request, Closure $next, string $permission, string $parameter = 'resource')
    {
        $resource = $request->route($parameter);
        abort_unless($resource instanceof Model, 404, 'منبع موردنظر پیدا نشد.');

        $attributes = $resource->getAttributes();
        $context = [
            'resource_id' => $resource->getKey(),
            'resource_type' => $resource::class,
            // Use the first declared ownership attribute, even when its value is null.
            // A nullable owner_id must never fall through to user_id/created_by,
            // which may identify the actor or uploader rather than the resource owner.
            'owner_id' => $this->ownerIdFromAttributes($attributes),
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
