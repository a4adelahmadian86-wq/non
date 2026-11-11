<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadinessController extends Controller
{
    /**
     * Report whether the application can safely receive database-backed traffic.
     * Never expose connection details, credentials, or exception messages.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable) {
            return response()->json(['status' => 'not_ready'], 503)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        return response()->json(['status' => 'ready'], 200)
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
