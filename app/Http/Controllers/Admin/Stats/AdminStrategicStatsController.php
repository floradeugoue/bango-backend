<?php

namespace App\Http\Controllers\Admin\Stats;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Stats\AdminScheduledReportUpdateRequest;
use App\Http\Requests\Admin\Stats\AdminStatsExportRequest;
use App\Services\Admin\Stats\AdminStrategicStatsService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminStrategicStatsController extends Controller
{
    public function __construct(
        protected AdminStrategicStatsService $statsService
    ) {}

    /**
     * 6.1. Vue d'ensemble stratégique.
     *
     * GET /api/admin/stats/overview
     */
    public function overview(Request $request): JsonResponse
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        $stats = $this->statsService->getOverviewStats($from, $to);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.2. Entonnoir d'acquisition et conversion vers le payant.
     *
     * GET /api/admin/stats/acquisition
     */
    public function acquisition(Request $request): JsonResponse
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        $stats = $this->statsService->getAcquisitionFunnel($from, $to);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.3. Ventilation fine du chiffre d'affaires.
     *
     * GET /api/admin/stats/revenue
     */
    public function revenue(Request $request): JsonResponse
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        $stats = $this->statsService->getRevenueBreakdown($from, $to);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.4. Statistiques de modération (SLA, contestations).
     *
     * GET /api/admin/stats/moderation
     */
    public function moderation(Request $request): JsonResponse
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        $stats = $this->statsService->getModerationStats($from, $to);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.5. Rétention et analyse des cohortes d'utilisateurs.
     *
     * GET /api/admin/stats/retention
     */
    public function retention(Request $request): JsonResponse
    {
        $months = (int) $request->input('months', 6);
        $stats = $this->statsService->getRetentionCohorts(max($months, 1));

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.6. Performance immobilière (biens loués, visites payées, reversements).
     *
     * GET /api/admin/stats/immo
     */
    public function immo(Request $request): JsonResponse
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;

        $stats = $this->statsService->getRealEstateStats($from, $to);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 6.7. Consultation des rapports périodiques programmés.
     *
     * GET /api/admin/stats/reports
     */
    public function reports(): JsonResponse
    {
        $reports = $this->statsService->getScheduledReports();

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * 6.7 bis. Mise à jour de la configuration d'un rapport périodique.
     *
     * PUT /api/admin/stats/reports/{id}
     * (et alias PUT /api/admin/reports/{id})
     */
    public function updateReport(AdminScheduledReportUpdateRequest $request, int|string $id): JsonResponse
    {
        $report = $this->statsService->updateScheduledReport((int) $id, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Configuration du rapport périodique mise à jour avec succès.',
            'data' => $report,
        ]);
    }

    /**
     * 6.8. Prévisualisation d'un export statistique.
     *
     * POST /api/admin/stats/exports/preview
     */
    public function previewExport(AdminStatsExportRequest $request): JsonResponse
    {
        try {
            $preview = $this->statsService->previewStatsExport(
                domain: $request->input('domain'),
                filters: $request->only(['from', 'to'])
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 6.8 bis. Export statistique CSV agrégé et sécurisé.
     *
     * POST /api/admin/stats/exports
     */
    public function exportCsv(AdminStatsExportRequest $request): StreamedResponse
    {
        return $this->statsService->exportStatsCsv(
            domain: $request->input('domain'),
            filters: $request->only(['from', 'to']),
            operator: $request->user()
        );
    }

    public function export(AdminStatsExportRequest $request): StreamedResponse
    {
        return $this->exportCsv($request);
    }
}
