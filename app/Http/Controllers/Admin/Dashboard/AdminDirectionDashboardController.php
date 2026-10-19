<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Admin\Dashboard\AdminDirectionDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDirectionDashboardController extends Controller
{
    public function __construct(
        protected AdminDirectionDashboardService $dashboardService
    ) {}

    /**
     * 7.1. Synthèse exécutive globale pour la Direction.
     *
     * GET /api/admin/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $data = $this->dashboardService->getExecutiveDashboard($request->user());

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * 7.2. Export du tableau de bord exécutif en CSV sécurisé.
     *
     * POST /api/admin/dashboard/exports
     */
    public function exportDashboard(Request $request): StreamedResponse
    {
        return $this->dashboardService->exportDashboardCsv($request->user());
    }

    /**
     * 7.3. Consultation des métadonnées et statut d'un export généré.
     *
     * GET /api/admin/exports/{id}
     */
    public function showExport(Request $request, int|string $id): JsonResponse
    {
        $export = $this->dashboardService->getExportDetails((int) $id, $request->user());

        return response()->json([
            'success' => true,
            'data' => $export,
        ]);
    }

    /**
     * 7.4. Statut d'onboarding de l'opérateur connecté.
     *
     * GET /api/admin/me/onboarding
     */
    public function onboarding(Request $request): JsonResponse
    {
        $status = $this->dashboardService->getOnboardingStatus($request->user());

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }
}
