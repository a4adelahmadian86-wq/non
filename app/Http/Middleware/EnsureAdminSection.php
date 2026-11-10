<?php

namespace App\Http\Middleware;

use App\Services\AdminConsoleService;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminSection
{
    public function __construct(private readonly AdminConsoleService $console)
    {
    }

    public function handle(Request $request, Closure $next, string $section)
    {
        abort_unless(
            $this->console->sectionAllowed($request->user(), $section),
            403,
            'دسترسی به این بخش از کنسول برای نقش یا دامنهٔ حساب شما مجاز نیست.'
        );

        $definition = config("farast_admin.sections.$section");
        if (is_array($definition) && is_string($definition['permission'] ?? null)) {
            $request->attributes->set('farast.section_permission', $definition['permission']);
        }

        return $next($request);
    }
}
