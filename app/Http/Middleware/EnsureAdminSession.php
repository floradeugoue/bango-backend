<?php

namespace App\Http\Middleware;

use App\Models\Admin\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawCookie = $request->cookie('bango_admin_session');

        if (empty($rawCookie) && ($cookieHeader = $request->header('Cookie'))) {
            if (preg_match('/bango_admin_session=([^;]+)/', $cookieHeader, $m)) {
                $rawCookie = urldecode(trim($m[1]));
            }
        }

        if (empty($rawCookie)) {
            $rawCookie = $request->header('X-Bango-Admin-Session') ?? $request->bearerToken();
        }

        if (empty($rawCookie) || ! is_string($rawCookie)) {
            return response()->json([
                'message' => 'Non authentifié. Session administrative requise.',
            ], 401);
        }

        // Supporte à la fois les cookies bruts et les cookies chiffrés par Laravel
        try {
            $sessionId = decrypt($rawCookie, unserialize: false);
        } catch (\Throwable) {
            $sessionId = $rawCookie;
        }

        // 2. Recherche et vérification de la session en base de données
        /** @var AdminSession|null $adminSession */
        $adminSession = AdminSession::with('user.role')->find($sessionId);

        if (! $adminSession) {
            return response()->json([
                'message' => 'Session administrative invalide ou introuvable.',
            ], 401);
        }

        // 3. Vérification de la révocation
        if ($adminSession->isRevoked()) {
            return response()->json([
                'message' => 'Session administrative révoquée.',
            ], 401);
        }

        // 4. Vérification de l'expiration glissante (30 min) et de l'expiration absolue
        if ($adminSession->isExpired(30)) {
            return response()->json([
                'message' => 'Session administrative expirée par inactivité.',
            ], 401);
        }

        // 5. Vérification du compte opérateur
        $user = $adminSession->user;

        if (! $user || $user->trashed()) {
            return response()->json([
                'message' => 'Compte opérateur introuvable ou supprimé.',
            ], 401);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Compte opérateur actuellement suspendu.',
            ], 403);
        }

        if (! $user->isInternalOperator()) {
            return response()->json([
                'message' => 'Accès refusé. Privilèges opérateur requis.',
            ], 403);
        }

        // 6. Vérification du second facteur (2FA)
        if (! $adminSession->two_factor_verified_at) {
            return response()->json([
                'message' => 'Validation du second facteur (2FA) obligatoire.',
            ], 403);
        }

        // 7. Validation CSRF sur les requêtes modifiant l'état (POST, PUT, PATCH, DELETE)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $csrfHeader = $request->header('X-CSRF-Token')
                ?? $request->header('X-XSRF-Token')
                ?? $request->input('_token');

            $rawCsrfCookie = $request->cookie('bango_admin_csrf');
            if (empty($rawCsrfCookie) && ($cookieHeader = $request->header('Cookie'))) {
                if (preg_match('/bango_admin_csrf=([^;]+)/', $cookieHeader, $m)) {
                    $rawCsrfCookie = urldecode(trim($m[1]));
                }
            }
            $csrfCookie = $rawCsrfCookie;
            if ($rawCsrfCookie && is_string($rawCsrfCookie)) {
                try {
                    $csrfCookie = decrypt($rawCsrfCookie, unserialize: false);
                } catch (\Throwable) {
                    $csrfCookie = $rawCsrfCookie;
                }
            }

            // Le jeton fourni doit correspondre au jeton CSRF de la session
            $expectedToken = $adminSession->csrf_token;

            $headerValid = ! empty($csrfHeader) && hash_equals($expectedToken, (string) $csrfHeader);
            $cookieValid = empty($csrfCookie) || hash_equals($expectedToken, (string) $csrfCookie);

            if (! $headerValid || ! $cookieValid) {
                return response()->json([
                    'message' => 'Jeton de protection CSRF invalide ou manquant.',
                    'code' => 'CSRF_MISMATCH',
                ], 419);
            }
        }

        // 8. Injection du contexte opérateur et de la session sur la requête
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('admin_session', $adminSession);

        // 9. Mise à jour de l'activité glissante
        $adminSession->touchActivity();

        return $next($request);
    }
}
