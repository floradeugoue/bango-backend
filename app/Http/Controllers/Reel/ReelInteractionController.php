<?php

namespace App\Http\Controllers\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reel\ReelReactionRequest;
use App\Http\Requests\Reel\ReelShareRequest;
use App\Http\Resources\Reel\ReelResource;
use App\Models\Reel\Reel;
use App\Services\Reel\ReelInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReelInteractionController extends Controller
{
    public function __construct(protected ReelInteractionService $interactionService) {}

    /**
     * Appliquer ou basculer une réaction (Like / Dislike avec exclusivité).
     */
    public function react(Reel $reel, ReelReactionRequest $request): JsonResponse
    {
        $type = ReelReactionType::from($request->validated('type'));
        $result = $this->interactionService->react($reel, $request->user(), $type);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Supprimer toute réaction sur un Reel.
     */
    public function removeReaction(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->removeReaction($reel, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Aimer un Reel (helper raccourci pour double-tap ou tap like).
     */
    public function like(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->react($reel, $request->user(), ReelReactionType::Like);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Retirer un like sur un Reel.
     */
    public function unlike(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->removeReaction($reel, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Disliker un Reel.
     */
    public function dislike(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->react($reel, $request->user(), ReelReactionType::Dislike);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Retirer un dislike sur un Reel.
     */
    public function undislike(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->removeReaction($reel, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Enregistrer un partage de Reel (incrément atomique et protection idempotente).
     */
    public function share(Reel $reel, ReelShareRequest $request): JsonResponse
    {
        $result = $this->interactionService->recordShare(
            $reel,
            $request->user(),
            $request->validated('platform'),
            $request->validated('client_mutation_id')
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Enregistrer une vue sur un Reel (avec protection anti-abus 30 minutes).
     */
    public function view(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->recordView(
            $reel,
            $request->user(),
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Enregistrer ou retirer des favoris/signets (RL-06).
     */
    public function toggleSave(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->toggleSave($reel, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Lister les Reels sauvegardés par l'utilisateur connecté.
     */
    public function listSaved(Request $request): AnonymousResourceCollection
    {
        $reels = $this->interactionService->listSavedReels($request->user(), (int) $request->query('per_page', 20));

        return ReelResource::collection($reels);
    }

    /**
     * Marquer un Reel comme "Ça ne m'intéresse pas" (RL-06).
     */
    public function markNotInterested(Reel $reel, Request $request): JsonResponse
    {
        $result = $this->interactionService->markNotInterested($reel, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
