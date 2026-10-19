<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\AdminHelpArticleUpdateRequest;
use App\Models\Support\HelpArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportHelpCenterController extends Controller
{
    /**
     * 4.11. Liste des articles du centre d'aide (y compris brouillons/non publiés).
     *
     * GET /api/admin/support/help
     */
    public function index(Request $request): JsonResponse
    {
        $query = HelpArticle::withCount('feedbacks')
            ->orderBy('category')
            ->orderBy('order');

        if ($request->has('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->has('is_published')) {
            $query->where('is_published', filter_var($request->query('is_published'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('locale')) {
            $query->where('locale', $request->query('locale'));
        }

        if ($request->has('search')) {
            $query->search($request->query('search'));
        }

        $perPage = (int) $request->query('per_page', 20);

        return response()->json($query->paginate($perPage));
    }

    /**
     * 4.11 bis. Détail d'un article pour consultation ou édition.
     *
     * GET /api/admin/support/help/{id}
     */
    public function show(int|string $id): JsonResponse
    {
        $article = HelpArticle::where('id', $id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'article' => $article,
        ]);
    }

    /**
     * 4.11 ter. Mise à jour d'un article du centre d'aide.
     *
     * PUT /api/admin/support/help/{id}
     */
    public function update(AdminHelpArticleUpdateRequest $request, int|string $id): JsonResponse
    {
        $article = HelpArticle::where('id', $id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        $article->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Article du centre d\'aide mis à jour avec succès.',
            'article' => $article->fresh(),
        ]);
    }
}
