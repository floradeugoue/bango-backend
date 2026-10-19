<?php

namespace App\Http\Controllers\Admin\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Audit\AdminRoleChangeRequestRequest;
use App\Http\Requests\Admin\Audit\AdminRoleConfirmRequest;
use App\Models\User;
use App\Services\Admin\Audit\AdminRoleManagementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRoleGovernanceController extends Controller
{
    public function __construct(
        protected AdminRoleManagementService $roleService
    ) {}

    /**
     * 4.4. Consultation de la matrice des rôles et permissions.
     *
     * GET /api/admin/audit/roles
     */
    public function matrix(): JsonResponse
    {
        $matrix = $this->roleService->getRoleMatrix();

        return response()->json([
            'success' => true,
            'data' => $matrix,
        ]);
    }

    /**
     * 4.4 bis. Simulation en lecture seule de l'attribution d'un rôle.
     *
     * GET /api/admin/audit/roles/assign-preview
     * (et alias GET /api/admin/assign-preview)
     */
    public function assignPreview(Request $request): JsonResponse
    {
        $targetUserId = (int) $request->input('user_id');
        $roleSlug = (string) $request->input('role_slug');

        if (! $targetUserId || empty($roleSlug)) {
            return response()->json([
                'success' => false,
                'message' => 'Les paramètres user_id et role_slug sont requis.',
            ], 422);
        }

        $user = User::findOrFail($targetUserId);
        $preview = $this->roleService->previewRoleChange($user, $roleSlug, 'assign');

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 4.4 ter. Simulation en lecture seule du retrait d'un rôle.
     *
     * GET /api/admin/audit/roles/revoke-preview
     * (et alias GET /api/admin/revoke-preview)
     */
    public function revokePreview(Request $request): JsonResponse
    {
        $targetUserId = (int) $request->input('user_id');
        $roleSlug = (string) $request->input('role_slug');

        if (! $targetUserId || empty($roleSlug)) {
            return response()->json([
                'success' => false,
                'message' => 'Les paramètres user_id et role_slug sont requis.',
            ], 422);
        }

        $user = User::findOrFail($targetUserId);
        $preview = $this->roleService->previewRoleChange($user, $roleSlug, 'revoke');

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 4.5. Soumission d'une demande de changement de rôle (1er opérateur).
     *
     * POST /api/admin/audit/roles/requests
     */
    public function storeRequest(AdminRoleChangeRequestRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->input('user_id'));

        try {
            $approval = $this->roleService->createRoleChangeRequest(
                targetUser: $user,
                roleSlug: $request->input('role_slug'),
                action: $request->input('action'),
                reason: $request->input('reason'),
                requester: $request->user()
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Demande de modification de rôle soumise avec succès au principe des 4 yeux.',
            'data' => $approval,
        ], 201);
    }

    /**
     * 4.5 bis. Confirmation ou rejet par un 2nd opérateur distinct (Double Approbation).
     *
     * POST /api/admin/audit/roles/confirm
     * (et alias POST /api/admin/confirm)
     */
    public function confirm(AdminRoleConfirmRequest $request): JsonResponse
    {
        $requestId = $request->input('request_id');

        try {
            $approval = $this->roleService->confirmRoleChangeRequest(
                requestId: $requestId,
                confirmer: $request->user(),
                decision: $request->input('decision'),
                decisionReason: $request->input('decision_reason')
            );
        } catch (DomainException $e) {
            // Auto-approbation interdite -> 403 Forbidden
            $status = str_contains($e->getMessage(), 'SELF_APPROVAL_FORBIDDEN') ? 403 : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Décision sur la demande de rôle enregistrée avec succès.',
            'data' => $approval,
        ]);
    }
}
