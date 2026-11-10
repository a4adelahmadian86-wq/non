<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class RequireTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Existing authenticated sessions are stopped before protected route
        // actions execute. The post-response check below handles the initial
        // password/OTP login request, where authentication happens in-route.
        if ($this->requiresChallenge($request, $user)) {
            return $this->challengeResponse($request);
        }

        $response = $next($request);
        $user = $request->user();

        if ($this->requiresChallenge($request, $user)) {
            return $this->challengeResponse($request);
        }

        return $response;
    }

    private function requiresChallenge(Request $request, $user): bool
    {
        if (! $user || ! $user->two_factor_enabled_at || $request->session()->boolean('auth.two_factor_passed')) {
            return false;
        }

        return ! $request->routeIs(
            'two-factor.challenge',
            'two-factor.challenge.verify',
            'logout'
        );
    }

    private function challengeResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'برای ادامه، تأیید دومرحله‌ای حساب خود را تکمیل کنید.',
                'two_factor_required' => true,
            ], 423);
        }

        return redirect()->route('two-factor.challenge');
    }
}
