<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Services\Admin\AutomatedTextFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModerationFilterController extends Controller
{
    public function __construct(
        protected AutomatedTextFilterService $filterService
    ) {}

    /**
     * Consultation des déclenchements du filtre textuel automatique (GET /moderation/filter).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'category' => $request->query('category'),
            'action_taken' => $request->query('action_taken'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 20);
        $logs = $this->filterService->getFilterLogs($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
