<?php

namespace App\Http\Controllers\Admin\Stats;

use App\Http\Controllers\Controller;
use App\Services\Admin\Stats\AdminActivityStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminActivityStatsController extends Controller
{
    public function __construct(
        protected AdminActivityStatsService $statsService
    ) {}

    /**
     * Métriques globales de l'écran Statistiques -> Activité.
     *
     * GET /admin/stats/activity
     */
    public function index(Request $request): JsonResponse
    {
        $toInput = $request->query('to');
        $fromInput = $request->query('from');
        $country = $request->query('country');

        $to = $toInput ? Carbon::parse($toInput, 'UTC')->endOfDay() : Carbon::now('UTC')->endOfDay();
        $from = $fromInput ? Carbon::parse($fromInput, 'UTC')->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $data = $this->statsService->getActivityStats($from, $to, $country);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
