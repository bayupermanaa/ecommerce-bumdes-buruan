<?php

namespace App\Http\Middleware;

use App\Support\UserRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $roles = array_map(static fn (string $role): string => trim($role), $roles);

        if (! Auth::check()) {
            abort(403);
        }

        if ($roles === []) {
            abort(403);
        }

        $userRole = $request->user()?->role;

        if ($userRole === null || ! in_array($userRole, $roles, true)
            && ! (in_array(UserRoles::SELLER, $roles, true) && UserRoles::isSeller($userRole))) {
            abort(403);
        }

        return $next($request);
    }
}
