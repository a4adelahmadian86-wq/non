<?php

namespace App\Http\Middleware;

use App\Services\CapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCapability
{
    public function __construct(private readonly CapabilityService $capabilities)
    {
    }

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        abort_unless(
            $request->user() && $this->capabilities->allowed($request->user(), $capability),
            403,
            'این قابلیت برای حساب شما فعال نیست.'
        );

        return $next($request);
    }
}
