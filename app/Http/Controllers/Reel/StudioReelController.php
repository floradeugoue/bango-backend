<?php

namespace App\Http\Controllers\Reel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reel\PublishReelRequest;
use App\Http\Requests\Reel\StoreDraftRequest;
use App\Http\Requests\Reel\UploadReelMediaRequest;
use App\Http\Resources\Reel\ReelResource;
use App\Services\Media\MediaStorageService;
use App\Services\Reel\ReelDraftService;
use App\Services\Reel\ReelPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudioReelController extends Controller
{
    public function __construct(
        protected ReelDraftService $draftService,
        protected ReelPublishService $publishService,
        protected MediaStorageService $storageService
    ) {}

    /**
     * Sauvegarder ou mettre à jour un brouillon dans Studio Reel (CR-14, CR-17 à CR-29).
     */
    public function saveDraft(StoreDraftRequest $request): JsonResponse
    {
        $reel = $this->draftService->saveDraft($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Brouillon sauvegardé avec succès.',
            'data' => new ReelResource($reel),
        ], 200);
    }

    /**
     * Liste des brouillons de Reel de l'utilisateur connecté.
     */
    public function listDrafts(Request $request): AnonymousResourceCollection
    {
        $drafts = $this->draftService->getUserDrafts($request->user(), (int) $request->query('per_page', 20));

        return ReelResource::collection($drafts);
    }

    /**
     * Obtenir les détails d'un brouillon pour reprise dans Studio Reel.
     */
    public function getDraft(string $id, Request $request): JsonResponse
    {
        $draft = $this->draftService->getDraft($id, $request->user());

        if (! $draft) {
            return response()->json([
                'success' => false,
                'message' => 'Brouillon introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ReelResource($draft),
        ]);
    }

    /**
     * Supprimer un brouillon.
     */
    public function deleteDraft(string $id, Request $request): JsonResponse
    {
        $deleted = $this->draftService->deleteDraft($id, $request->user());

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Brouillon introuvable ou déjà supprimé.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Brouillon supprimé avec succès.',
        ]);
    }

    /**
     * Publier un Reel (création asynchrone avec job de transcodage et publication).
     */
    public function publish(PublishReelRequest $request): JsonResponse
    {
        $reel = $this->publishService->publish($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Publication du Reel lancée avec succès.',
            'data' => new ReelResource($reel),
        ], 202);
    }

    /**
     * Téléversement de clip vidéo ou d'image de couverture pour Studio Reel.
     */
    public function uploadMedia(UploadReelMediaRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $kind = $request->input('kind');
        $isDraft = (bool) $request->input('is_draft', false) || $kind === 'draft' || $kind === 'clip';
        $mime = $file->getMimeType() ?? $file->getClientMimeType() ?? '';

        if ($kind === 'cover' || (empty($kind) && str_starts_with($mime, 'image/'))) {
            $result = $this->storageService->storeReelCover($file, $request->user());
        } elseif ($kind === 'audio' || (empty($kind) && str_starts_with($mime, 'audio/'))) {
            $result = $this->storageService->storeAudio($file, $request->user());
        } else {
            $result = $this->storageService->storeReelClip($file, $request->user(), null, $isDraft);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 201);
    }
}
