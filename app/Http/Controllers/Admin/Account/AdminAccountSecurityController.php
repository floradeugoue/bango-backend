<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\Account\AdminAccountOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccountSecurityController extends Controller
{
    public function __construct(
        protected AdminAccountOperationsService $accountService
    ) {}

    /**
     * 5.12. Opérations de sécurité du compte (déconnexion forcée, réinitialisation 2FA).
     *
     * POST /api/admin/accounts/{id}/security/{kind}
     */
    public function securityAction(Request $request, int|string $id, string $kind): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $result = $this->accountService->executeSecurityAction($user, $operator, $kind);

        return response()->json([
            'success' => true,
            'message' => 'Opération de sécurité exécutée avec succès.',
            'data' => $result,
        ]);
    }
}
