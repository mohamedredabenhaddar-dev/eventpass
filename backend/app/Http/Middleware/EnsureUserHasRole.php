<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless($roles !== [] && count(array_diff($roles, UserRole::values())) === 0, 403);
        abort_unless(
            $request->user()
            && in_array($request->user()->role->value, $roles, true),
            403,
        );

        return $next($request);
    }
}
