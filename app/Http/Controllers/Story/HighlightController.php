<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\StoreHighlightRequest;
use App\Http\Resources\Story\StoryHighlightResource;
use App\Services\Story\HighlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HighlightController extends Controller
{
    public function __construct(protected HighlightService $highlightService) {}

    /**
     * Récupérer les collections À la une visibles selon les règles d'accès.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $authorId = $request->query('user_id') ? (int) $request->query('user_id') : null;
        $highlights = $this->highlightService->getHighlights($request->user(), $authorId);

        return StoryHighlightResource::collection($highlights);
    }

    /**
     * Créer une collection À la une (titre max 20 caractères).
     */
    public function store(StoreHighlightRequest $request): JsonResponse
    {
        $highlight = $this->highlightService->createHighlight(
            $request->user(),
            $request->validated()
        );

        return (new StoryHighlightResource($highlight))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Consulter les détails d'une collection spécifique.
     */
    public function show(string $id, Request $request): StoryHighlightResource
    {
        $highlight = $this->highlightService->getHighlight($id, $request->user());

        if (! $highlight) {
            abort(404, 'Collection À la une introuvable ou restreinte.');
        }

        return new StoryHighlightResource($highlight);
    }

    /**
     * Supprimer une collection À la une sans supprimer ses Stories.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $this->highlightService->deleteHighlight($id, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Collection À la une supprimée avec succès.',
        ]);
    }
}
