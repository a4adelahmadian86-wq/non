<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FarastObservability
{
    public function handle(Request $request,Closure $next):Response
    {
        $requestId=(string)($request->header('X-Request-Id')?:Str::uuid());
        $correlationId=(string)($request->header('X-Correlation-Id')?:$requestId);
        Log::withContext(['request_id'=>$requestId,'correlation_id'=>$correlationId]);$started=hrtime(true);
        $response=null;
        try{$response=$next($request);return $response;}
        finally{
            Log::info('farast.request.completed',['method'=>$request->method(),'path'=>$request->path(),'status'=>$response?->getStatusCode(),'duration_ms'=>(int)((hrtime(true)-$started)/1_000_000)]);
            if($response){$response->headers->set('X-Request-Id',$requestId);$response->headers->set('X-Correlation-Id',$correlationId);}
        }
    }
}