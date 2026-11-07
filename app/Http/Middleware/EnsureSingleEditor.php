<?php

namespace App\Http\Middleware;

use App\Models\EditorSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureSingleEditor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        abort_if($user->is_blocked, 403);

        $currentToken = $request->session()->get('editor_session_token');

        // Serialize session acquisition on the owning user row. Without this lock,
        // two first-time editor requests can both observe no active session and
        // create two valid editor tokens for the same account.
        $sessionToken = DB::transaction(function () use ($user, $currentToken): ?string {
            $lockedUser = $user->newQuery()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($lockedUser->is_blocked, 403);

            $activeSession = EditorSession::query()
                ->where('user_id', $lockedUser->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($activeSession) {
                if (! is_string($currentToken) || $currentToken === ''
                    || ! hash_equals((string) $activeSession->token, $currentToken)) {
                    return null;
                }

                return (string) $activeSession->token;
            }

            $newToken = Str::random(64);

            EditorSession::create([
                'user_id' => $lockedUser->id,
                'token' => $newToken,
                'last_seen_at' => now(),
                'expires_at' => now()->addMinutes(20),
            ]);

            return $newToken;
        }, 3);

        if ($sessionToken === null) {
            return response()->view('errors.single-session', [], 409);
        }

        $request->session()->put('editor_session_token', $sessionToken);

        return $next($request);
    }
}
