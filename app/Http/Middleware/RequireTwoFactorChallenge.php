<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class RequireTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if (! $user || ! $user->two_factor_enabled_at || $request->session()->boolean('auth.two_factor_passed')) {
            return $response;
        }

        if ($request->routeIs(
            'two-factor.challenge',
            'two-factor.challenge.verify',
            'logout'
        )) {
            return $response;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'برای ادامه، تأیید دومرحله‌ای حساب خود را تکمیل کنید.',
                'two_factor_required' => true,
            ], 423);
        }

        return redirect()->route('two-factor.challenge');
    }
}
