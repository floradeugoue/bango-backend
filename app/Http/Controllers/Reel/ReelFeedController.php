<?php

namespace App\Http\Controllers\Reel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reel\ReelResource;
use App\Models\Reel\Reel;
use App\Services\Reel\ReelFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReelFeedController extends Controller
{
    public function __construct(protected ReelFeedService $feedService) {}

    /**
     * Fil vertical des Reels (RL-01, RL-08 Découvrir, etc.).
     */
    public function feed(Request $request): AnonymousResourceCollection
    {
        $scope = $request->query('scope', 'for_you');
        $topic = $request->query('topic');
        $perPage = (int) $request->query('per_page', 10);

        $reels = $this->feedService->getFeed($request->user(), $scope, $topic, $perPage);

        return ReelResource::collection($reels);
    }

    /**
     * Détails d'un Reel individuel.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $reel = $this->feedService->getReel($id, $request->user());

        if (! $reel) {
            return response()->json([
                'success' => false,
                'message' => 'Reel introuvable ou indisponible.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ReelResource($reel),
        ]);
    }

    /**
     * Supprimer un Reel (RL-06 / auteur uniquement).
     */
    public function destroy(Reel $reel, Request $request): JsonResponse
    {
        $this->feedService->deleteReel($reel, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Reel supprimé avec succès.',
        ]);
    }
}
