<?php
namespace App\Http\Middleware;
use Closure;
use App\Models\User;
use Illuminate\Http\Request;
class AdminOnly {
 public function handle(Request $request, Closure $next) {
  // در معماری «کنسول واحد چندنقشی»، نقش‌های مدیریتی پایین‌تر نیز با رعایت
  // RBAC و دامنه (Scope) می‌توانند وارد /admin شوند. با FARAST_ADMIN_MULTI_ROLE=false
  // رفتار کاملاً قدیمی (فقط سوپرادمین) برگردانده می‌شود.
  if (! config('farast_admin.multi_role_console', false)) {
   abort_unless($request->user()?->isAdmin(),403);
   return $next($request);
  }

  $user = $request->user();
  abort_unless($user,403);
  abort_if($user->is_blocked,403,'حساب شما مسدود شده است.');
  abort_unless(in_array($user->role, User::DASHBOARD_ROLES, true),403);

  return $next($request);
 }
}
