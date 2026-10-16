<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchPostsRequest;
use App\Http\Resources\Square\PostResource;
use App\Services\Search\PostSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostSearchController extends Controller
{
    public function __construct(
        protected PostSearchService $searchService
    ) {}

    /**
     * Recherche plein texte des publications Square (RC-06).
     *
     * GET /api/search/posts?q=...
     */
    public function index(SearchPostsRequest $request): AnonymousResourceCollection
    {
        $posts = $this->searchService->search(
            query: (string) $request->validated('q'),
            viewer: $request->user(),
            perPage: (int) $request->input('per_page', 20)
        );

        return PostResource::collection($posts);
    }
}
