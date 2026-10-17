<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Non authentifié. Opérateur requis.',
            ], 401);
        }

        // Si l'utilisateur est admin ou direction, il a tous les droits
        if ($user->isAdmin() || $user->hasRole('direction')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            $normalizedPermission = str_replace('.', ':', $permission);
            $dotPermission = str_replace(':', '.', $permission);

            if (! $user->hasPermission($permission) && ! $user->hasPermission($normalizedPermission) && ! $user->hasPermission($dotPermission)) {
                return response()->json([
                    'message' => "Accès refusé. La permission '{$permission}' est requise pour cette opération.",
                    'code' => 'ADMIN_PERMISSION_DENIED',
                ], 403);
            }
        }

        return $next($request);
    }
}
