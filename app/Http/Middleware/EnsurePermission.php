<?php
namespace App\Http\Middleware;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
class EnsurePermission {
 public function __construct(private AuthorizationService $authorization){}
 public function handle(Request $request,Closure $next,string $permission){abort_unless($this->authorization->allows($request->user(),$permission),403,'دسترسی به این عملیات برای حساب شما مجاز نیست.');return $next($request);}
}