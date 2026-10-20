<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Account\AdminAccountBadgeExtendRequest;
use App\Http\Requests\Admin\Account\AdminAccountBadgeRemoveRequest;
use App\Http\Requests\Admin\Account\AdminAccountCertificationRequest;
use App\Http\Requests\Admin\Account\AdminAccountLiftSuspensionRequest;
use App\Http\Requests\Admin\Account\AdminAccountSuspendRequest;
use App\Http\Requests\Admin\Account\AdminAccountWarningRequest;
use App\Models\User;
use App\Services\Admin\Account\AdminAccountOperationsService;
use Illuminate\Http\JsonResponse;

class AdminAccountSanctionController extends Controller
{
    public function __construct(
        protected AdminAccountOperationsService $accountService
    ) {}

    /**
     * 5.6. Suspension d'un compte utilisateur (7 jours, 30 jours, indéfinie).
     *
     * POST /api/admin/accounts/{id}/suspend
     */
    public function suspend(AdminAccountSuspendRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $suspendedUser = $this->accountService->suspendAccount(
            user: $user,
            operator: $operator,
            duration: $request->input('duration'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Compte suspendu avec succès.',
            'data' => [
                'id' => $suspendedUser->id,
                'status' => $suspendedUser->status,
                'suspended_from' => $suspendedUser->suspended_from?->toIso8601String(),
                'suspended_until' => $suspendedUser->suspended_until?->toIso8601String(),
                'suspension_reason' => $suspendedUser->suspension_reason,
            ],
        ]);
    }

    /**
     * 5.6 bis. Levée de la suspension d'un compte.
     *
     * POST /api/admin/accounts/{id}/suspend/lift
     */
    public function liftSuspension(AdminAccountLiftSuspensionRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $activeUser = $this->accountService->liftSuspension(
            user: $user,
            operator: $operator,
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Suspension levée avec succès.',
            'data' => [
                'id' => $activeUser->id,
                'status' => $activeUser->status,
            ],
        ]);
    }

    /**
     * 5.7. Avertissement formel (niveaux 1, 2, 3).
     *
     * POST /api/admin/accounts/{id}/warnings
     */
    public function warning(AdminAccountWarningRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $sanction = $this->accountService->applyWarning(
            user: $user,
            operator: $operator,
            level: (int) $request->input('level'),
            reason: $request->input('reason'),
            applyRestriction: (bool) $request->input('apply_restriction', false)
        );

        return response()->json([
            'success' => true,
            'message' => 'Avertissement formel enregistré.',
            'data' => $sanction,
        ], 201);
    }

    /**
     * 5.8. Prolongation commerciale de badge.
     *
     * POST /api/admin/accounts/{id}/badge/extend
     */
    public function extendBadge(AdminAccountBadgeExtendRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $updatedUser = $this->accountService->extendBadge(
            user: $user,
            operator: $operator,
            badge: $request->input('badge'),
            durationDays: (int) $request->input('duration_days'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Validité du badge prolongée avec succès.',
            'data' => [
                'user_id' => $updatedUser->id,
                'badge' => $updatedUser->badge,
            ],
        ]);
    }

    /**
     * 5.8 bis. Retrait d'un badge.
     *
     * POST /api/admin/accounts/{id}/badge/remove
     */
    public function removeBadge(AdminAccountBadgeRemoveRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $updatedUser = $this->accountService->removeBadge(
            user: $user,
            operator: $operator,
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Badge retiré avec succès.',
            'data' => [
                'user_id' => $updatedUser->id,
                'badge' => $updatedUser->badge,
            ],
        ]);
    }

    /**
     * 5.9. Attribution de certification (compte ou page).
     *
     * POST /api/admin/accounts/{id}/certification
     */
    public function certify(AdminAccountCertificationRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $result = $this->accountService->grantCertification(
            user: $user,
            operator: $operator,
            type: $request->input('type', 'account') ?? 'account',
            pageId: $request->input('page_id'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Certification accordée avec succès.',
            'data' => $result,
        ]);
    }

    /**
     * 5.9 bis. Retrait de certification (compte ou page).
     *
     * POST /api/admin/accounts/{id}/certification/remove
     */
    public function removeCertification(AdminAccountCertificationRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $result = $this->accountService->removeCertification(
            user: $user,
            operator: $operator,
            type: $request->input('type', 'account') ?? 'account',
            pageId: $request->input('page_id'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Certification retirée avec succès.',
            'data' => $result,
        ]);
    }
}
