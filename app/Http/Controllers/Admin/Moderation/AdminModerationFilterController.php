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
            'step' => $request->query('step'),
            'appealed' => $request->query('appealed'),
            'action_taken' => $request->query('action_taken'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'q' => $request->query('q'),
        ];

        $perPage = (int) $request->query('per_page', $request->query('perPage', 20));
        $logs = $this->filterService->getFilterLogs($filters, $perPage);
        $meta = $this->filterService->getFilterMeta(30);

        return response()->json([
            'items' => $logs->items(),
            'page' => $logs->currentPage(),
            'perPage' => $logs->perPage(),
            'total' => $logs->total(),
            'pageCount' => $logs->lastPage(),
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
            'meta' => $meta,
        ]);
    }
}
