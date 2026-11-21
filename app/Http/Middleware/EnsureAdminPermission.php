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

        $isContractClient = $request->header('X-Requested-With') === 'bango-admin'
            || str_starts_with($request->path(), 'admin/v1')
            || str_starts_with($request->path(), 'api/admin/v1');

        foreach ($permissions as $permission) {
            $normalizedPermission = str_replace('.', ':', $permission);
            $dotPermission = str_replace(':', '.', $permission);

            $hasAccess = $user->hasPermission($permission)
                || $user->hasPermission($normalizedPermission)
                || $user->hasPermission($dotPermission);

            // Équivalence souple pour les statistiques : stats.read <-> stats.view
            if (! $hasAccess && in_array($permission, ['stats.read', 'stats:read', 'stats.view', 'stats:view'], true)) {
                $hasAccess = $user->hasPermission('stats:view')
                    || $user->hasPermission('stats.view')
                    || $user->hasPermission('stats:read')
                    || $user->hasPermission('stats.read');
            }

            if (! $hasAccess) {
                return response()->json([
                    'code' => $isContractClient ? 'forbidden' : 'ADMIN_PERMISSION_DENIED',
                    'message' => "Accès refusé. La permission '{$permission}' est requise pour cette opération.",
                    'fields' => (object) [],
                    'meta' => (object) [],
                ], 403);
            }
        }

        return $next($request);
    }
}
