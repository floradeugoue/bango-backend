<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Support\HelpArticleResource;
use App\Services\Support\HelpArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HelpArticleController extends Controller
{
    public function __construct(
        protected HelpArticleService $helpArticleService
    ) {}

    /**
     * Liste et recherche paginée des articles d'aide FAQ.
     *
     * GET /api/help/articles
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = [
            'q' => $request->query('q'),
            'category' => $request->query('category'),
            'locale' => $request->query('locale'),
        ];

        $perPage = (int) $request->query('per_page', 15);
        $articles = $this->helpArticleService->listArticles($filters, $perPage);

        return HelpArticleResource::collection($articles);
    }

    /**
     * Détail d'un article d'aide par son slug.
     *
     * GET /api/help/articles/{slug}
     */
    public function show(Request $request, string $slug): JsonResponse|HelpArticleResource
    {
        $locale = $request->query('locale');
        $article = $this->helpArticleService->findBySlug($slug, $locale);

        if (! $article) {
            return response()->json([
                'message' => 'Article d\'aide introuvable ou non disponible.',
            ], 404);
        }

        return new HelpArticleResource($article);
    }
}
