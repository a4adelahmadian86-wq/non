<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Symfony\\Component\\HttpFoundation\\Response;

/**
 * Revoke access immediately when an authenticated account has been blocked.
 *
 * Login-time checks alone are insufficient: an already authenticated browser
 * session would otherwise remain usable until it expires or is manually logged
 * out. This middleware runs inside the web stack after the session is started.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_blocked) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'حساب کاربری شما مسدود شده است.',
            ], 403);
        }

        return redirect()
            ->route('login')
            ->with('error', 'حساب کاربری شما مسدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.');
    }
}
