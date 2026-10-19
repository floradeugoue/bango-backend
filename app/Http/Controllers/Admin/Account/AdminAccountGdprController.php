<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Account\AdminAccountAnonymizeApproveRequest;
use App\Http\Requests\Admin\Account\AdminAccountAnonymizeRequest;
use App\Models\Support\UserAnonymizationRequest;
use App\Models\User;
use App\Services\Admin\Account\AdminAccountOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccountGdprController extends Controller
{
    public function __construct(
        protected AdminAccountOperationsService $accountService
    ) {}

    /**
     * 5.11. Création d'une demande d'anonymisation RGPD (1er opérateur).
     *
     * POST /api/admin/accounts/{id}/anonymize
     */
    public function requestAnonymization(AdminAccountAnonymizeRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $anonymizeRequest = $this->accountService->requestAnonymization(
            user: $user,
            operator: $operator,
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'anonymisation créée et placée en attente de seconde approbation.',
            'data' => $anonymizeRequest,
        ], 201);
    }

    /**
     * 5.11 bis. Approbation et exécution de l'anonymisation RGPD (2e opérateur distinct).
     *
     * POST /api/admin/accounts/{id}/anonymize/approve
     */
    public function approveAnonymization(AdminAccountAnonymizeApproveRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $approver = $request->user();

        // Récupérer la demande en attente pour cet utilisateur
        $pendingRequest = UserAnonymizationRequest::where('user_id', $user->id)
            ->where('status', 'pending_approval')
            ->firstOrFail();

        try {
            $processed = $this->accountService->approveAnonymization(
                requestId: $pendingRequest->id,
                approver: $approver,
                decisionNote: $request->input('decision_note')
            );
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Anonymisation RGPD approuvée et exécutée avec succès.',
            'data' => $processed,
        ]);
    }

    /**
     * 5.13. Export de portabilité RGPD d'un compte individuel.
     *
     * POST /api/admin/accounts/{id}/exports
     */
    public function portabilityExport(Request $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $data = $this->accountService->exportUserPortability($user, $operator);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * 5.13 bis. Export administratif CSV de la liste des comptes.
     *
     * POST /api/admin/accounts/exports
     */
    public function accountsExport(Request $request): StreamedResponse
    {
        $filters = $request->only(['status', 'badge']);
        $operator = $request->user();

        return $this->accountService->exportAccountsCsv($filters, $operator);
    }
}
