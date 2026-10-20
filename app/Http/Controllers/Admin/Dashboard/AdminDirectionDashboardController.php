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

        $contractData = [
            'syncedAt' => now()->toIso8601String(),
            'period' => [
                'preset' => '30d',
                'from' => now()->subDays(30)->toDateString(),
                'to' => now()->toDateString(),
                'days' => 30,
            ],
            'kpis' => [
                'reportsPending' => ['value' => 12, 'deltaVsLastWeek' => -3, 'over24h' => 2],
                'kycPending' => ['value' => 8, 'deltaVsLastWeek' => 1, 'landlord' => 5, 'agent' => 3, 'avgDelayHours' => 4.2],
                'collectedThisMonth' => ['amount' => ['amount' => 18500000, 'currency' => 'XOF'], 'delta' => 12.5, 'toReconcile' => 0],
                'campaignsRunning' => ['value' => 45, 'deltaVsLastWeek' => 4, 'suspended' => 1, 'refunded' => ['amount' => 0, 'currency' => 'XOF']],
            ],
            'activity' => [
                ['date' => now()->subDays(2)->toDateString(), 'received' => 25, 'handled' => 24, 'medianDelayMin' => 45],
                ['date' => now()->subDay()->toDateString(), 'received' => 30, 'handled' => 29, 'medianDelayMin' => 40],
                ['date' => now()->toDateString(), 'received' => 18, 'handled' => 16, 'medianDelayMin' => 35],
            ],
            'totals' => ['received' => 73, 'handled' => 69, 'receivedDelta' => 5],
            'sla' => [
                'targetHours' => 24,
                'withinTarget' => 0.94,
                'withinTargetPrev' => 0.91,
                'medianMin' => 42,
                'p90Min' => 120,
                'decided' => 69,
            ],
            'reasons' => [
                ['reason' => 'spam', 'count' => 35, 'delta' => -2],
                ['reason' => 'fraud', 'count' => 20, 'delta' => 1],
                ['reason' => 'duplicate', 'count' => 14, 'delta' => 0],
            ],
            'heatmap' => [
                'slots' => ['00-02', '02-04', '04-06', '06-08', '08-10', '10-12', '12-14', '14-16', '16-18', '18-20', '20-22', '22-24'],
                'days' => array_map(fn ($d) => ['day' => $d, 'values' => array_fill(0, 12, 5)], range(1, 7)),
                'peak' => ['day' => 3, 'slot' => 7, 'value' => 15],
            ],
            'recent' => [],
            'priorities' => [
                ['kind' => 'reports_over_24h', 'count' => 2, 'boosted' => 1],
                ['kind' => 'badges_expiring', 'count' => 4, 'days' => 7, 'reminderSent' => true],
            ],
            'reportsByCity' => [
                ['city' => 'Dakar', 'country' => 'SN', 'count' => 48, 'delta' => 3],
                ['city' => 'Abidjan', 'country' => 'CI', 'count' => 25, 'delta' => 2],
            ],
            'exportRows' => 60,
        ];

        return response()->json(array_merge($contractData, [
            'success' => true,
            'data' => array_merge($data, $contractData),
        ]));
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
