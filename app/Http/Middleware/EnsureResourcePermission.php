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
            'owner_id' => $attributes['owner_id'] ?? $attributes['user_id'] ?? $attributes['created_by'] ?? null,
            'organization_id' => $attributes['organization_id'] ?? null,
            'team_id' => $attributes['team_id'] ?? null,
            'status' => $attributes['status'] ?? null,
        ];

        if ($resource instanceof User) {
            $context['owner_id'] = $resource->id;
            $context['organization_id'] = $resource->organization_id;
            $context['team_ids'] = $resource->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
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
