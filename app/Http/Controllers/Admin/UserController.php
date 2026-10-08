<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @group Admin - Users & Sessions
 * 
 * Endpoints réservés aux administrateurs pour la gestion des utilisateurs et de leurs accès.
 */
class UserController extends Controller
{
    /**
     * Lister les utilisateurs
     * 
     * Affiche la liste des utilisateurs avec leurs relations principales.
     * 
     * @queryParam display_name string Filtrer par nom.
     * @queryParam email string Filtrer par email.
     * @queryParam phone string Filtrer par téléphone.
     * @queryParam handle string Filtrer par pseudo.
     * @queryParam status string Filtrer par statut (active, suspended, blocked, paused).
     * @queryParam is_verified boolean Filtrer par comptes vérifiés ou non.
     * @queryParam sort_by string Champ de tri (created_at, display_name, email, status). Example: created_at
     * @queryParam sort_order string Ordre de tri (asc, desc). Example: desc
     * @queryParam per_page int Éléments par page. Example: 15
     */
    public function index(Request $request)
    {
        $query = User::with(['role', 'country', 'city', 'neighbourhood']);
        
        $likeOperator = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        // Filtres basiques
        if ($request->has('display_name')) {
            $query->where('display_name', $likeOperator, '%' . $request->display_name . '%');
        }
        if ($request->has('email')) {
            $query->where('email', $likeOperator, '%' . $request->email . '%');
        }
        if ($request->has('phone')) {
            $query->where('phone', 'like', '%' . $request->phone . '%');
        }
        if ($request->has('handle')) {
            $query->where('handle', $likeOperator, '%' . $request->handle . '%');
        }

        // Filtre de statut
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtre de compte vérifié
        if ($request->has('is_verified')) {
            $query->where('is_verified', filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN));
        }

        // Tri
        $sortField = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['created_at', 'updated_at', 'display_name', 'email', 'status'];
        
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    /**
     * Afficher un utilisateur
     * 
     * Affiche les détails d'un utilisateur spécifique.
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function show(User $user)
    {
        $user->load([
            'role', 
            'country', 
            'city', 
            'neighbourhood',
            'interests',
            'housingSearch'
        ]);

        return response()->json($user);
    }

    /**
     * Modifier un utilisateur
     * 
     * Met à jour les informations de base de l'utilisateur.
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     * @bodyParam display_name string Le nouveau nom.
     * @bodyParam handle string Le nouveau pseudo.
     * @bodyParam status string Le nouveau statut.
     * @bodyParam is_verified boolean Activer/Désactiver la vérification.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'handle' => 'sometimes|string|max:255|unique:users,handle,' . $user->id,
            'birth_date' => 'sometimes|date',
            'gender' => 'sometimes|string',
            'locale' => 'sometimes|string',
            'account_type' => 'sometimes|string',
            'status' => 'sometimes|string',
            'is_verified' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json(['success' => true, 'user' => $user]);
    }

    /**
     * Suspendre un compte
     * 
     * Change le statut en "suspended" et déconnecte immédiatement l'utilisateur.
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function suspend(User $user)
    {
        $user->update(['status' => 'suspended']);
        $user->tokens()->delete(); // Kick the user out
        return response()->json(['success' => true, 'status' => 'suspended']);
    }

    /**
     * Lever la suspension d'un compte
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function unsuspend(User $user)
    {
        $user->update(['status' => 'active']);
        return response()->json(['success' => true, 'status' => 'active']);
    }

    /**
     * Bloquer un compte
     * 
     * Change le statut en "blocked" et déconnecte immédiatement l'utilisateur.
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function block(User $user)
    {
        $user->update(['status' => 'blocked']);
        $user->tokens()->delete(); // Kick the user out
        return response()->json(['success' => true, 'status' => 'blocked']);
    }

    /**
     * Débloquer un compte
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function unblock(User $user)
    {
        $user->update(['status' => 'active']);
        return response()->json(['success' => true, 'status' => 'active']);
    }

    /**
     * Supprimer un compte
     * 
     * Suppression logicielle (Soft Delete) du compte.
     * 
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function destroy(User $user)
    {
        $user->delete(); // Soft delete
        $user->tokens()->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Lister les sessions d'un utilisateur
     * 
     * @subgroup Gestion des Sessions
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function sessions(User $user)
    {
        $sessions = $user->tokens->map(function($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'scopes' => $token->scopes,
                'revoked' => $token->revoked,
                'created_at' => $token->created_at,
                'updated_at' => $token->updated_at,
                'expires_at' => $token->expires_at,
            ];
        });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Révoquer une session spécifique
     * 
     * @subgroup Gestion des Sessions
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     * @urlParam session string required L'ID du token de session. Example: 9b2d...
     */
    public function revokeSession(User $user, $sessionId)
    {
        $user->tokens()->where('id', $sessionId)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Révoquer toutes les sessions
     * 
     * Déconnecte l'utilisateur de tous ses appareils.
     * 
     * @subgroup Gestion des Sessions
     * @urlParam user integer required L'ID de l'utilisateur. Example: 1
     */
    public function revokeAllSessions(User $user)
    {
        $user->tokens()->delete();
        return response()->json(['success' => true]);
    }
}
