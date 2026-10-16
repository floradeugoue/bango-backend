<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ArticleFeedbackRequest;
use App\Models\Support\HelpArticle;
use App\Services\Support\HelpArticleService;
use Illuminate\Http\JsonResponse;

class HelpArticleFeedbackController extends Controller
{
    public function __construct(
        protected HelpArticleService $helpArticleService
    ) {}

    /**
     * Enregistre un vote d'utilité sur un article d'aide (SUP-03).
     *
     * POST /api/help/articles/{id}/feedback
     */
    public function feedback(ArticleFeedbackRequest $request, int|string $id): JsonResponse
    {
        $article = HelpArticle::query()
            ->published()
            ->where('id', $id)
            ->first();

        if (! $article) {
            return response()->json([
                'message' => 'Article d\'aide introuvable ou non publié.',
            ], 404);
        }

        $user = $request->user('api') ?? $request->user();
        $ip = $request->ip();
        $sessionId = $request->header('X-Session-ID')
            ?: $request->input('session_id')
            ?: (session()->isStarted() ? session()->getId() : null);

        $result = $this->helpArticleService->recordFeedback(
            $article,
            (bool) $request->input('helpful'),
            $user,
            $ip,
            $sessionId
        );

        return response()->json([
            'message' => 'Merci pour votre retour.',
            'data' => $result,
        ]);
    }
}
