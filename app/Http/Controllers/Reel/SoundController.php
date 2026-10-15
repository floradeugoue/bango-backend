<?php

namespace App\Http\Controllers\Reel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reel\ReelResource;
use App\Http\Resources\Reel\SoundResource;
use App\Services\Reel\SoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SoundController extends Controller
{
    public function __construct(protected SoundService $soundService) {}

    /**
     * Catalogue des sons disponibles (CR-21, RL-05).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $genre = $request->query('genre');
        $search = $request->query('search') ?? $request->query('q');
        $perPage = (int) $request->query('per_page', 20);

        $sounds = $this->soundService->listSounds($genre, $search, $perPage);

        return SoundResource::collection($sounds);
    }

    /**
     * Détails d'un son (catalogue ou original).
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $sound = $this->soundService->getSound($id, $request->user());

        if (! $sound) {
            return response()->json([
                'success' => false,
                'message' => 'Son introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new SoundResource($sound),
        ]);
    }

    /**
     * Liste des Reels utilisant ce son (RL-05).
     */
    public function reels(string $id, Request $request): AnonymousResourceCollection
    {
        $order = $request->query('order', 'popular');
        $perPage = (int) $request->query('per_page', 20);

        $reels = $this->soundService->getReelsForSound($id, $order, $request->user(), $perPage);

        return ReelResource::collection($reels);
    }

    /**
     * Ajouter ou retirer un son des favoris.
     */
    public function toggleFavourite(string $id, Request $request): JsonResponse
    {
        $result = $this->soundService->toggleFavourite($id, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Obtenir la liste des identifiants des sons favoris de l'utilisateur.
     */
    public function favourites(Request $request): JsonResponse
    {
        $ids = $this->soundService->getUserFavouriteSoundIds($request->user());

        return response()->json([
            'success' => true,
            'data' => $ids,
        ]);
    }

    /**
     * Verrouiller ou déverrouiller la réutilisation de son son original.
     */
    public function lockOriginal(string $id, Request $request): JsonResponse
    {
        $locked = $request->boolean('locked', true);
        $sound = $this->soundService->setOriginalSoundLocked($id, $locked, $request->user());

        return response()->json([
            'success' => true,
            'message' => $locked ? 'Son original verrouillé.' : 'Son original déverrouillé.',
            'data' => new SoundResource($sound),
        ]);
    }
}
