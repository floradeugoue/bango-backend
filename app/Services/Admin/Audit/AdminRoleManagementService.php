<?php

namespace App\Services\Admin\Audit;

use App\Enums\Admin\AdminPermission;
use App\Models\Admin\AdminApproval;
use App\Models\Admin\AdminAuditLog;
use App\Models\Auth\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminRoleManagementService
{
    /**
     * Consultation de la matrice complète des rôles et des permissions.
     *
     * @return array<string, mixed>
     */
    public function getRoleMatrix(): array
    {
        $roles = Role::withCount('users')->get()->map(function (Role $role) {
            return [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions ?? [],
                'users_count' => $role->users_count,
                'is_active' => (bool) $role->is_active,
            ];
        });

        return [
            'roles' => $roles,
            'all_permissions' => AdminPermission::all(),
            'total_roles' => $roles->count(),
        ];
    }

    /**
     * Simulation en lecture seule des conséquences de l'attribution ou du retrait d'un rôle.
     * GARANTIE ABSOLUE : Aucune mutation, aucune révocation de session, aucun effet de bord.
     *
     * @return array<string, mixed>
     */
    public function previewRoleChange(User $targetUser, string $roleSlug, string $action): array
    {
        $targetRole = Role::where('slug', $roleSlug)->firstOrFail();
        $currentRole = $targetUser->role;

        $currentPermissions = $currentRole ? ($currentRole->permissions ?? []) : [];
        $newPermissions = $action === 'assign'
            ? ($targetRole->permissions ?? [])
            : []; // Si révocation vers utilisateur standard

        $addedPermissions = array_values(array_diff($newPermissions, $currentPermissions));
        $removedPermissions = array_values(array_diff($currentPermissions, $newPermissions));
        $activeSessionsCount = $targetUser->tokens()->count();

        return [
            'target_user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'current_role' => $currentRole ? [
                    'id' => $currentRole->id,
                    'slug' => $currentRole->slug,
                    'name' => $currentRole->name,
                ] : null,
            ],
            'action' => $action,
            'proposed_role' => [
                'id' => $targetRole->id,
                'slug' => $targetRole->slug,
                'name' => $targetRole->name,
            ],
            'added_permissions' => $addedPermissions,
            'removed_permissions' => $removedPermissions,
            'impacted_active_sessions' => $activeSessionsCount,
            'requires_double_approval' => true,
            'simulated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Création d'une demande de changement de rôle soumise au principe des 4 yeux.
     */
    public function createRoleChangeRequest(
        User $targetUser,
        string $roleSlug,
        string $action,
        string $reason,
        User $requester
    ): AdminApproval {
        return DB::transaction(function () use ($targetUser, $roleSlug, $action, $reason, $requester) {
            $role = Role::where('slug', $roleSlug)->firstOrFail();

            // Vérifier qu'il n'y a pas déjà une demande en attente pour cet utilisateur
            $existingPending = AdminApproval::where('approvable_type', User::class)
                ->where('approvable_id', (string) $targetUser->id)
                ->where('status', 'pending')
                ->where('action', "role.{$action}")
                ->exists();

            if ($existingPending) {
                throw new DomainException("Une demande de modification de rôle est déjà en attente d'approbation pour l'utilisateur {$targetUser->name}.");
            }

            $approval = AdminApproval::create([
                'requester_id' => $requester->id,
                'action' => "role.{$action}",
                'approvable_type' => User::class,
                'approvable_id' => (string) $targetUser->id,
                'payload' => [
                    'target_user_id' => $targetUser->id,
                    'target_user_name' => $targetUser->name,
                    'target_user_email' => $targetUser->email,
                    'role_id' => $role->id,
                    'role_slug' => $role->slug,
                    'role_name' => $role->name,
                    'action' => $action,
                    'reason' => $reason,
                ],
                'status' => 'pending',
            ]);

            // Tracer dans l'audit chaîné
            AdminAuditLog::logChainedEvent(
                event: 'role.change_requested',
                operator: $requester,
                targetType: 'User',
                targetId: (string) $targetUser->id,
                targetLabel: "Demande {$action} rôle {$role->name} pour {$targetUser->name}",
                context: [
                    'approval_id' => $approval->id,
                    'role_slug' => $role->slug,
                    'reason' => $reason,
                ]
            );

            return $approval->load(['requester', 'approvable']);
        });
    }

    /**
     * Confirmation ou rejet de la demande par un second opérateur distinct (Double Approbation).
     */
    public function confirmRoleChangeRequest(
        int|string $requestId,
        User $confirmer,
        string $decision,
        ?string $decisionReason = null
    ): AdminApproval {
        return DB::transaction(function () use ($requestId, $confirmer, $decision, $decisionReason) {
            $approval = AdminApproval::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($approval->status !== 'pending') {
                throw new DomainException("Cette demande n'est plus en attente (statut actuel : {$approval->status}).");
            }

            // RÈGLE IMPÉRATIVE DE DOUBLE VALIDATION : L'opérateur qui valide ne peut pas être le demandeur
            if ((int) $approval->requester_id === (int) $confirmer->id) {
                throw new DomainException("SELF_APPROVAL_FORBIDDEN: L'opérateur ayant initié la demande ({$confirmer->name}) ne peut pas la confirmer lui-même.");
            }

            $payload = $approval->payload;
            $targetUserId = $payload['target_user_id'] ?? $approval->approvable_id;
            $targetUser = User::where('id', $targetUserId)->lockForUpdate()->firstOrFail();

            if ($decision === 'approve') {
                $role = Role::where('slug', $payload['role_slug'])->firstOrFail();
                $action = $payload['action'] ?? 'assign';

                if ($action === 'assign') {
                    $targetUser->role_id = $role->id;
                } else {
                    // Révocation : retour au rôle utilisateur standard si disponible
                    $userRole = Role::where('slug', 'user')->first();
                    $targetUser->role_id = $userRole?->id;
                }
                $targetUser->save();

                // Révocation de toutes les sessions actives de l'utilisateur pour forcer la mise à jour des tokens
                $targetUser->tokens()->delete();

                $approval->status = 'approved';
                $approval->validator_id = $confirmer->id;
                $approval->decided_at = Carbon::now();
                $approval->decision_reason = $decisionReason;
                $approval->save();

                // Journalisation d'audit chaînée
                AdminAuditLog::logChainedEvent(
                    event: 'role.change_confirmed',
                    operator: $confirmer,
                    targetType: 'User',
                    targetId: (string) $targetUser->id,
                    targetLabel: "Confirmation changement de rôle pour {$targetUser->name}",
                    context: [
                        'approval_id' => $approval->id,
                        'new_role' => $targetUser->fresh()->role?->slug,
                        'decision_reason' => $decisionReason,
                    ]
                );
            } else {
                $approval->status = 'rejected';
                $approval->validator_id = $confirmer->id;
                $approval->decided_at = Carbon::now();
                $approval->decision_reason = $decisionReason;
                $approval->save();

                // Journalisation du rejet
                AdminAuditLog::logChainedEvent(
                    event: 'role.change_rejected',
                    operator: $confirmer,
                    targetType: 'User',
                    targetId: (string) $targetUser->id,
                    targetLabel: "Rejet de la demande de rôle pour {$targetUser->name}",
                    context: [
                        'approval_id' => $approval->id,
                        'decision_reason' => $decisionReason,
                    ]
                );
            }

            return $approval->fresh(['requester', 'validator', 'approvable']);
        });
    }
}
