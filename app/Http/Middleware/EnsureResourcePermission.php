<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce a permission against the route-bound record, not merely the menu item.
 * Usage: ->middleware('resource.permission:documents.edit,document')
 */
class EnsureResourcePermission
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function handle(Request $request, Closure $next, string $permission, string $parameter, ?string $modelClass = null): Response
    {
        $user = $request->user();
        $record = $request->route($parameter);

        abort_unless($user, 401);
        if (! $record instanceof Model && is_string($modelClass) && class_exists($modelClass)) {
            $candidate = app($modelClass);
            if ($candidate instanceof Model) {
                $record = $candidate->resolveRouteBinding($record);
            }
        }
        abort_unless($record instanceof Model, 404);

        if (! $this->authorization->allowsRecord($user, $permission, $record)) {
            try {
                $this->authorization->audit(
                    'authorization.denied',
                    $user,
                    $user,
                    $record,
                    ['permission' => $permission, 'scope' => $request->attributes->get('farast.scope')],
                    $request,
                );
            } catch (\Throwable $exception) {
                report($exception);
            }

            abort(403, 'دسترسی به این رکورد برای حساب شما مجاز نیست.');
        }

        return $next($request);
    }
}
