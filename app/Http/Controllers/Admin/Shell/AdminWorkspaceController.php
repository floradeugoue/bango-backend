<?php

namespace App\Http\Controllers\Admin\Shell;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWorkspaceController extends Controller
{
    public function __construct(
        protected AdminWorkspaceService $workspaceService,
    ) {}

    /**
     * GET /me/workspace
     * Données du tableau de bord personnel de l'opérateur.
     */
    public function workspace(Request $request): JsonResponse
    {
        $operator = $request->user();

        $data = $this->workspaceService->getWorkspaceData($operator);

        return response()->json($data, 200);
    }

    /**
     * GET /me/approvals
     * File des validations au principe des quatre yeux.
     */
    public function approvals(Request $request): JsonResponse
    {
        $operator = $request->user();

        $data = $this->workspaceService->getApprovalsQueue($operator);

        return response()->json($data, 200);
    }
}
