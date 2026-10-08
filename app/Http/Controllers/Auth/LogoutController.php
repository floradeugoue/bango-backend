<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @group Auth
 *
 * API d'authentification des utilisateurs.
 */
class LogoutController extends Controller
{
    /**
     * Déconnexion (Logout)
     *
     * Déconnecte l'utilisateur courant en invalidant son token Passport actuel.
     * Cette action ne révoque que le token utilisé pour la requête.
     *
     * @authenticated
     * 
     * @response 200 {
     *   "success": true,
     *   "message": "Déconnexion réussie."
     * }
     * 
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()->token();
        $token->revoke();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie.'
        ], 200);
    }
}
