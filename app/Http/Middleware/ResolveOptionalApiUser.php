<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveOptionalApiUser
{
    /**
     * Resolve Passport API user if a Bearer token is provided,
     * without aborting or throwing 401 if absent or invalid.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() && ! $request->user()) {
            try {
                $user = Auth::guard('api')->user();
                if ($user) {
                    $request->setUserResolver(fn () => $user);
                }
            } catch (\Throwable) {
                // Ignore invalid or expired token on public routes
            }
        }

        return $next($request);
    }
}
