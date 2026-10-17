<?php

namespace App\Http\Controllers\Admin\Shell;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shell\AdminSearchRequest;
use App\Services\Admin\AdminSearchService;
use Illuminate\Http\JsonResponse;

class AdminSearchController extends Controller
{
    public function __construct(
        protected AdminSearchService $searchService,
    ) {}

    /**
     * GET /search
     * Recherche transversale pour le raccourci ⌘K du frontend.
     */
    public function search(AdminSearchRequest $request): JsonResponse
    {
        $operator = $request->user();
        $query = (string) $request->input('q');
        $category = $request->input('category', 'all');
        $limit = (int) $request->input('limit', 20);

        $results = $this->searchService->search($operator, $query, $category, $limit);

        return response()->json($results, 200);
    }
}
