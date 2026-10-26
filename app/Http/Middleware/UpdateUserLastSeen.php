<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user) {
            $lastSeen = $user->last_seen_at;
            if (! $lastSeen || $lastSeen->diffInSeconds(now()) >= 60) {
                // Update directly in DB without touching updated_at timestamps
                $user->newQuery()
                    ->where('id', $user->id)
                    ->update(['last_seen_at' => now()]);
                $user->last_seen_at = now();
            }
        }

        return $response;
    }
}
