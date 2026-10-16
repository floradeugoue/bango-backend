<?php

namespace App\Http\Controllers\Boost;

use App\Http\Controllers\Controller;
use App\Models\Page\Page;
use App\Services\Boost\BoostableContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoostableContentController extends Controller
{
    public function __construct(
        protected BoostableContentService $boostableService
    ) {}

    /**
     * Liste des contenus (Publications Square et Reels) éligibles au boost
     * avec calcul statistique de surperformance relative (BO).
     *
     * GET /api/boost/boostable
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = ! empty($validated['page_id']) ? Page::find($validated['page_id']) : null;
        $pageNumber = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 15);

        $paginator = $this->boostableService->getBoostableContents(
            user: $request->user(),
            page: $page,
            pageNumber: $pageNumber,
            perPage: $perPage
        );

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
