<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
     * @authenticated
 * @group Admin - Roles
 *
 * API pour la gestion des rôles système.
 */
class RoleController extends Controller
{
    /**
     * Liste des rôles
     *
     * @response 200 [{"id":1,"name":"Administrateur","slug":"admin"}]
     */
    public function index()
    {
        // Seuls les admins peuvent normalement accéder ici (à protéger par middleware via routes)
        return response()->json(Role::all());
    }

    /**
     * Créer un rôle
     *
     * @bodyParam name string required Le nom du rôle. Example: Modérateur
     * @bodyParam slug string required L'identifiant unique. Example: moderator
     * @bodyParam description string Description du rôle.
     * @bodyParam is_active boolean Statut. Example: true
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:roles,slug',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $role = Role::create($validated);
        return response()->json($role, 201);
    }

    /**
     * Détail d'un rôle
     *
     * @urlParam id integer required L'ID du rôle.
     */
    public function show(Role $role)
    {
        return response()->json($role);
    }

    /**
     * Mettre à jour un rôle
     *
     * @urlParam id integer required L'ID du rôle.
     */
    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:roles,slug,' . $role->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $role->update($validated);
        return response()->json($role);
    }

    /**
     * Supprimer un rôle
     *
     * @urlParam id integer required L'ID du rôle.
     */
    public function destroy(Role $role)
    {
        if (in_array($role->slug, ['admin', 'user'])) {
            return response()->json(['message' => 'Impossible de supprimer un rôle système par défaut.'], 403);
        }

        if ($role->users()->exists()) {
            return response()->json(['message' => 'Ce rôle est assigné à des utilisateurs. Impossible de le supprimer.'], 409);
        }

        $role->delete();
        return response()->json(['message' => 'Rôle supprimé avec succès.']);
    }

    /**
     * Utilisateurs avec ce rôle
     *
     * @urlParam id integer required L'ID du rôle.
     */
    public function users(Role $role)
    {
        return response()->json($role->users()->paginate(20));
    }

    /**
     * Voir le rôle d'un utilisateur (Admin)
     *
     * @urlParam user integer required L'ID de l'utilisateur.
     */
    public function getUserRole(User $user)
    {
        $user->load('role');
        return response()->json(['role' => $user->role]);
    }

    /**
     * Modifier le rôle d'un utilisateur (Admin)
     *
     * @urlParam user integer required L'ID de l'utilisateur.
     * @bodyParam role_id integer required L'ID du nouveau rôle. Example: 1
     */
    public function updateUserRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id'
        ]);

        $user->update(['role_id' => $validated['role_id']]);
        
        $user->load('role');
        return response()->json(['message' => 'Rôle mis à jour', 'role' => $user->role]);
    }
}
