<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminAuditLog;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @authenticated
 *
 * @group Admin - Roles
 *
 * API pour la gestion des rôles système.
 */
class RoleController extends Controller
{
    /**
     * Rôles système protégés dont le slug est strictement immuable.
     */
    public const SYSTEM_ROLES = [
        'admin',
        'direction',
        'moderation',
        'support',
        'finance',
        'verification',
        'user',
    ];

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
            'is_active' => 'boolean',
        ]);

        if (in_array($validated['slug'], self::SYSTEM_ROLES, true)) {
            return response()->json([
                'message' => 'Impossible de créer un rôle avec un slug réservé au système.',
                'code' => 'RESERVED_ROLE_SLUG',
            ], 403);
        }

        $role = Role::create($validated);

        $operator = $request->user();
        if ($operator) {
            AdminAuditLog::logChainedEvent(
                event: 'roles.role_created',
                operator: $operator,
                targetType: 'Role',
                targetId: (string) $role->id,
                targetLabel: "Création du rôle {$role->name} ({$role->slug})",
                context: ['role' => $role->toArray()]
            );
        }

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
            'slug' => 'sometimes|required|string|max:255|unique:roles,slug,'.$role->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Protection 1 : Le slug d'un rôle système ne peut JAMAIS être modifié
        if (in_array($role->slug, self::SYSTEM_ROLES, true)) {
            if ($request->has('slug') && $request->input('slug') !== $role->slug) {
                return response()->json([
                    'message' => "Le slug du rôle système [{$role->slug}] est immuable et ne peut pas être modifié.",
                    'code' => 'SYSTEM_ROLE_SLUG_IMMUTABLE',
                ], 403);
            }
        }

        // Protection 2 : Un rôle personnalisé ne peut pas usurper un slug système
        if ($request->filled('slug') && in_array($request->input('slug'), self::SYSTEM_ROLES, true) && $role->slug !== $request->input('slug')) {
            return response()->json([
                'message' => 'Impossible d\'utiliser un slug réservé au système.',
                'code' => 'RESERVED_ROLE_SLUG',
            ], 403);
        }

        $oldAttributes = $role->only(['name', 'slug', 'description', 'is_active']);
        $role->update($validated);

        $operator = $request->user();
        if ($operator) {
            AdminAuditLog::logChainedEvent(
                event: 'roles.role_updated',
                operator: $operator,
                targetType: 'Role',
                targetId: (string) $role->id,
                targetLabel: "Modification du rôle {$role->name} ({$role->slug})",
                context: [
                    'old' => $oldAttributes,
                    'new' => $validated,
                ]
            );
        }

        return response()->json($role);
    }

    /**
     * Supprimer un rôle
     *
     * @urlParam id integer required L'ID du rôle.
     */
    public function destroy(Request $request, Role $role)
    {
        if (in_array($role->slug, self::SYSTEM_ROLES, true)) {
            return response()->json(['message' => 'Impossible de supprimer un rôle système par défaut.'], 403);
        }

        if ($role->users()->exists()) {
            return response()->json(['message' => 'Ce rôle est assigné à des utilisateurs. Impossible de le supprimer.'], 409);
        }

        $operator = $request->user();
        $roleData = $role->toArray();
        $role->delete();

        if ($operator) {
            AdminAuditLog::logChainedEvent(
                event: 'roles.role_deleted',
                operator: $operator,
                targetType: 'Role',
                targetId: (string) $roleData['id'],
                targetLabel: "Suppression du rôle {$roleData['name']} ({$roleData['slug']})",
                context: ['role' => $roleData]
            );
        }

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
     *
     * @bodyParam role_id integer required L'ID du nouveau rôle. Example: 1
     */
    public function updateUserRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $operator = $request->user();
        if (! $operator) {
            return response()->json(['message' => 'Non authentifié. Session administrative requise.'], 401);
        }

        // Protection 1 : Interdiction formelle de modifier son propre rôle
        if ($operator->id === $user->id) {
            return response()->json([
                'message' => 'Impossible de modifier votre propre rôle.',
                'code' => 'SELF_ROLE_CHANGE_FORBIDDEN',
            ], 403);
        }

        $newRole = Role::findOrFail($validated['role_id']);

        // Protection 2 : L'attribution des rôles admin ou direction est strictement réservée à un administrateur
        if (in_array($newRole->slug, ['admin', 'direction'], true) && ! $operator->isAdmin()) {
            return response()->json([
                'message' => 'L\'attribution des rôles admin ou direction est strictement réservée aux administrateurs.',
                'code' => 'ADMIN_PRIVILEGE_REQUIRED',
            ], 403);
        }

        $user->load('role');
        $isCurrentAdmin = $user->role && $user->role->slug === 'admin';

        // Protection 3 : Impossible de rétrograder le dernier administrateur actif du système
        if ($isCurrentAdmin && $newRole->slug !== 'admin') {
            $adminCount = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->count();

            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Impossible de rétrograder le dernier administrateur du système.',
                    'code' => 'LAST_ADMIN_PROTECTED',
                ], 403);
            }
        }

        $previousRoleSlug = $user->role?->slug ?? 'aucun';
        $user->update(['role_id' => $newRole->id]);
        $user->load('role');

        // Protection 4 : Journalisation obligatoire dans le registre d'audit chaîné
        AdminAuditLog::logChainedEvent(
            event: 'roles.user_role_updated',
            operator: $operator,
            targetType: 'User',
            targetId: (string) $user->id,
            targetLabel: "Attribution du rôle {$newRole->name} ({$newRole->slug}) à {$user->name} ({$user->email})",
            context: [
                'previous_role' => $previousRoleSlug,
                'new_role' => $newRole->slug,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour avec succès.',
            'role' => $user->role,
        ]);
    }
}
