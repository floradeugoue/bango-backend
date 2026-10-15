<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCatalogSoundRequest;
use App\Http\Requests\Admin\UpdateCatalogSoundRequest;
use App\Http\Resources\Reel\SoundResource;
use App\Services\Admin\AdminSoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSoundCatalogController extends Controller
{
    public function __construct(
        protected AdminSoundService $soundService
    ) {}

    /**
     * Liste des sons du catalogue pour l'administration (avec filtres avancés).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = [
            'search' => $request->query('search') ?? $request->query('q'),
            'genre' => $request->query('genre'),
            'rights_status' => $request->query('rights_status'),
            'is_published' => $request->has('is_published') ? $request->boolean('is_published') : null,
            'is_removed' => $request->has('is_removed') ? $request->boolean('is_removed') : null,
            'is_original' => $request->has('is_original') ? $request->boolean('is_original') : null,
            'sort_by' => $request->query('sort_by', 'latest'),
        ];

        $perPage = (int) $request->query('per_page', 25);
        $sounds = $this->soundService->listSounds($filters, $perPage);

        return SoundResource::collection($sounds);
    }

    /**
     * Importe un nouveau fichier audio et crée une entrée dans le catalogue audio (sounds/catalogue).
     */
    public function store(StoreCatalogSoundRequest $request): JsonResponse
    {
        $sound = $this->soundService->createSound(
            $request->validated(),
            $request->getAudioFile()
        );

        return response()->json([
            'success' => true,
            'message' => 'Son importé et enregistré dans le catalogue avec succès.',
            'data' => new SoundResource($sound),
        ], 201);
    }

    /**
     * Consultation détaillée d'un son avec toutes ses métadonnées administratives.
     */
    public function show(string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);

        return response()->json([
            'success' => true,
            'data' => new SoundResource($sound),
        ]);
    }

    /**
     * Met à jour les métadonnées d'un son et optionnellement remplace son fichier audio.
     */
    public function update(UpdateCatalogSoundRequest $request, string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);

        $updatedSound = $this->soundService->updateSound(
            $sound,
            $request->validated(),
            $request->getAudioFile()
        );

        return response()->json([
            'success' => true,
            'message' => 'Métadonnées du son mises à jour avec succès.',
            'data' => new SoundResource($updatedSound),
        ]);
    }

    /**
     * Publie ou retire un son de la sélection publique.
     */
    public function togglePublication(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $sound = $this->soundService->getSound($id);
        $published = $request->boolean('is_published');

        $updatedSound = $this->soundService->setPublication($sound, $published);

        return response()->json([
            'success' => true,
            'message' => $published ? 'Son publié dans le catalogue des Reels.' : 'Son désactivé du catalogue public.',
            'data' => new SoundResource($updatedSound),
        ]);
    }

    /**
     * Met à jour le statut de vérification des droits d'un son.
     */
    public function updateRights(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'rights_status' => ['required', 'string', 'in:pending,verified,rejected'],
        ]);

        $sound = $this->soundService->getSound($id);
        $updatedSound = $this->soundService->setRightsStatus($sound, $request->input('rights_status'));

        return response()->json([
            'success' => true,
            'message' => 'Statut des droits mis à jour avec succès.',
            'data' => new SoundResource($updatedSound),
        ]);
    }

    /**
     * Retire un son du catalogue public (suppression logique sans perte des Reels existants).
     */
    public function removeFromCatalog(string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);
        $updatedSound = $this->soundService->removeFromCatalog($sound);

        return response()->json([
            'success' => true,
            'message' => 'Son retiré du catalogue avec succès. Les Reels existants conservent l\'accès au fichier.',
            'data' => new SoundResource($updatedSound),
        ]);
    }

    /**
     * Restaure un son précédemment retiré du catalogue.
     */
    public function restore(string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);
        $updatedSound = $this->soundService->restoreToCatalog($sound);

        return response()->json([
            'success' => true,
            'message' => 'Son restauré dans le catalogue.',
            'data' => new SoundResource($updatedSound),
        ]);
    }

    /**
     * Supprime définitivement un son (uniquement si aucun Reel ne l'utilise).
     */
    public function destroy(string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);
        $this->soundService->deletePermanently($sound);

        return response()->json([
            'success' => true,
            'message' => 'Son supprimé définitivement.',
        ]);
    }
}
